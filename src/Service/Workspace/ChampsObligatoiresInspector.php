<?php

namespace App\Service\Workspace;

use App\Entity\ConditionPartage;
use App\Entity\ReversementRetroAgent;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Symfony\Component\Form\Extension\Core\Type\PercentType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\ResolvedFormTypeInterface;

/**
 * Source UNIQUE de la notion « champ obligatoire » d'une entité, dérivée des
 * métadonnées Doctrine (nullabilité de colonne, défaut BDD/PHP) plutôt que de
 * contraintes #[Assert] écrites entité par entité (couverture aujourd'hui trop
 * clairsemée). Les prédicats sont partagés :
 *  - par l'assistant IA (Ket) via {@see WorkspaceMutationService} — invariant
 *    « annoncé = exigé » de l'inventaire des champs ;
 *  - par le CRUD HTTP interactif via ControllerUtilsTrait::handleFormSubmission,
 *    pour transformer un champ obligatoire vide en erreur 422 propre (au lieu
 *    d'un 500 au flush Doctrine).
 *
 * Fournit aussi le libellé LISIBLE d'un champ (lu depuis le FormType) pour que
 * les messages d'erreur nomment le champ tel que l'utilisateur le voit.
 */
class ChampsObligatoiresInspector
{
    /** Champs jamais exigés à l'utilisateur (système + scoping auto). */
    public const CHAMPS_SYSTEME = ['id', 'createdAt', 'updatedAt'];

    /**
     * Relations MÉTIER requises à la création même si leur colonne est NULLABLE en
     * base : la nullabilité tolère les données héritées, mais une création sans elles
     * produit une fiche incohérente (ex. un RevenuPourCourtier sans typeRevenu casse
     * le calcul de commission — cf. IndicatorCalculationHelper). Exigées à l'identique
     * côté formulaire HTTP et côté assistant (Ket), via relationRequise(). Clé = nom
     * court d'entité, valeur = champs de relation. Fail-open : entité absente = aucune
     * exigence métier supplémentaire (comportement Doctrine inchangé).
     */
    private const RELATIONS_METIER_REQUISES = [
        'RevenuPourCourtier' => ['typeRevenu'],
        // Un chargement de prime SANS type ne peut pas servir de base au calcul de
        // commission (getCotationMontantChargementPrime matche le TYPE, pas le nom)
        // → prime affichée mais commission à 0. On l'exige donc à la création.
        'ChargementPourPrime' => ['type'],
    ];

    /**
     * Champs de CHOIX indispensables à la création, même si leur colonne tolère NULL :
     * ce sont des DISCRIMINANTS métier, pour lesquels une valeur par défaut serait un
     * mensonge silencieux (une note de crédit émise là où il fallait un débit, une
     * souscription là où il fallait un renouvellement). On les EXIGE au lieu de les
     * deviner — à l'identique côté écran (422 nommant le champ) et côté assistant
     * (« manquants » de l'inventaire), via {@see scalaireRequis()}.
     *
     * Jumeau de RELATIONS_METIER_REQUISES ci-dessus : même patron, même fail-open
     * (entité absente = aucune exigence supplémentaire).
     *
     * Les champs de choix qui admettent, eux, un défaut NON AMBIGU ne figurent pas ici :
     * leur défaut est posé sur la PROPRIÉTÉ de l'entité (`= self::X`), ce qui sert d'un
     * seul geste l'écran, l'assistant, l'import de fichier et l'API.
     */
    private const CHOIX_METIER_REQUIS = [
        // Le type d'avenant scelle le sort d'une police : il fonde le pipeline
        // d'échéance et les mouvements (renouvellement/prorogation/résiliation).
        'Piste' => ['typeAvenant'],
        // Débit ou crédit = facture émise ou avoir ; et le destinataire détermine qui
        // doit l'argent. Aucun des deux ne se devine.
        'Note' => ['type', 'addressedTo'],
        // « Prime nette » est la BASE du calcul de commission : un défaut ferait d'un
        // chargement quelconque l'assiette de la rémunération du courtier.
        'Chargement' => ['fonction'],
        // Deux mondes distincts : taxe SUR LA PRIME (chargement) ≠ taxe SUR LA
        // COMMISSION. Le redevable dit lequel — jamais un défaut.
        'Taxe' => ['redevable'],
        'TypeRevenu' => ['redevable'],
    ];

    /**
     * @var array<string, array<string, array{libelle: string, choix: array<int|string, string>,
     *      defautFormulaire: mixed, aFormulaireData: bool, aide: string|null,
     *      requisFormulaire: bool, multiple: bool, pourcentage: bool}>>
     */
    private array $descripteurs = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FormFactoryInterface $formFactory,
    ) {
    }

    /**
     * Champs obligatoires laissés VIDES sur une entité déjà hydratée (typiquement
     * après {@see \Symfony\Component\Form\FormInterface::submit()}). Renvoie une
     * carte `champ => [message]` directement fusionnable avec les erreurs Symfony.
     *
     * @param string[]|null $champsPilotables Si fourni, restreint le contrôle aux
     *        seuls champs exposés par le formulaire : un champ obligatoire renseigné
     *        AILLEURS (ex. par un beforePersist, un listener ou l'auto-scoping) n'est
     *        alors jamais signalé à tort. Passer `array_keys($form->all())`.
     *
     * @return array<string, string[]>
     */
    public function champsManquants(object $entity, ?array $champsPilotables = null): array
    {
        try {
            $meta = $this->em->getClassMetadata($entity::class);
        } catch (\Throwable) {
            return [];
        }

        $limiter = $champsPilotables !== null;
        $shortName = $this->shortName($meta->getName());
        $manquants = [];

        // Colonnes scalaires non-nullables sans défaut BDD/PHP, et discriminants métier
        // ({@see CHOIX_METIER_REQUIS}), laissés vides.
        foreach ($meta->getFieldNames() as $field) {
            if (in_array($field, self::CHAMPS_SYSTEME, true)) {
                continue;
            }
            $discriminant = $this->choixRequis($shortName, $field);
            if (!$discriminant && ($meta->isNullable($field) || $this->aUnDefaut($meta, $field))) {
                continue;
            }
            if ($limiter && !in_array($field, $champsPilotables, true)) {
                continue;
            }
            if ($this->estVide($entity, $meta, $field)) {
                $manquants[$field] = ['Ce champ est obligatoire.'];
            }
        }

        // Relations ManyToOne obligatoires (hors entreprise/invite auto-scopés).
        foreach ($meta->getAssociationMappings() as $field => $mapping) {
            if (!$this->relationRequise($field, $mapping)) {
                continue;
            }
            if ($limiter && !in_array($field, $champsPilotables, true)) {
                continue;
            }
            if (!$this->valeurNonNulle($entity, $meta, $field)) {
                $manquants[$field] = ['Ce champ est obligatoire.'];
            }
        }

        return $manquants + $this->incoherencesMetier($entity, $shortName, $champsPilotables);
    }

    /**
     * INCOHÉRENCES entre champs — l'autre famille de refus, à côté des champs manquants.
     *
     * Un champ peut être individuellement valide et rendre la fiche insensée en
     * combinaison avec un autre. Ces règles ne se déduisent d'aucune métadonnée Doctrine :
     * on les nomme ici, une par une, plutôt que de semer des #[Assert] par entité (le
     * projet n'en pose aucune, cf. le refus 422 générique du trait CRUD).
     *
     * @param string[]|null $champsPilotables cf. champsManquants()
     *
     * @return array<string, string[]>
     */
    private function incoherencesMetier(object $entity, string $shortName, ?array $champsPilotables): array
    {
        // Une condition de partage rétrocède à UN bénéficiaire : un partenaire EXTERNE ou
        // un agent INTERNE, jamais les deux, jamais aucun. Avec les deux, l'assiette à
        // retenir serait ambiguë — celle du partenaire ne porte que les revenus
        // partageables, celle de l'agent porte ce qui reste au cabinet APRÈS les
        // partenaires — et trancher en silence ferait perdre de l'argent à quelqu'un.
        if ($shortName === 'ConditionPartage' && $entity instanceof ConditionPartage && !$entity->estValide()) {
            // MÊME DISCIPLINE QUE LES CHAMPS MANQUANTS : on ne signale que ce que le
            // formulaire courant peut corriger. Un appel restreint à d'autres champs — ou
            // un écran qui ne montre aucun des deux bénéficiaires — n'a pas à recevoir un
            // refus qu'il ne saurait pas lever.
            $pilotable = static fn (string $champ): bool
                => $champsPilotables === null || in_array($champ, $champsPilotables, true);
            if (!$pilotable('agent') && !$pilotable('partenaire')) {
                return [];
            }

            // Le champ visé est celui que l'écran expose : sur la fiche d'un partenaire,
            // `agent` n'existe pas — y signaler « agent » serait incompréhensible.
            $champ = $pilotable('agent') ? 'agent' : 'partenaire';

            return [$champ => [$entity->getBeneficiaire() === null
                ? 'Désignez un bénéficiaire : un partenaire externe, ou un agent interne.'
                : 'Une condition rétrocède à un partenaire externe OU à un agent interne, pas aux deux.',
            ]];
        }

        // Un reversement VERSE à un bénéficiaire, et à un seul. Sans agent ni partenaire il
        // ne verse à personne ; avec les deux, on ne saurait ni quelle dette il éteint ni
        // quelle écriture il produit — 6611 pour un salarié, 632 pour un intermédiaire
        // externe. Même règle, même forme et même chemin de refus que ci-dessus.
        if ($shortName === 'ReversementRetroAgent' && $entity instanceof ReversementRetroAgent) {
            $pilotable = static fn (string $champ): bool
                => $champsPilotables === null || in_array($champ, $champsPilotables, true);

            if (!$entity->estValide() && ($pilotable('agent') || $pilotable('partenaire'))) {
                $champ = $pilotable('agent') ? 'agent' : 'partenaire';

                return [$champ => [$entity->getBeneficiaire() === null
                    ? 'Désignez le bénéficiaire du versement : un agent interne, ou un partenaire externe.'
                    : 'Un versement va à un agent interne OU à un partenaire externe, pas aux deux.',
                ]];
            }

            // L'ÉCHÉANCE ET L'AFFAIRE DOIVENT RELEVER DE LA MÊME COTATION. Rien dans le
            // schéma ne l'impose — les deux sont enfants de Cotation — et le versement
            // porterait alors sur une affaire tout en s'imputant à l'échéance d'une autre :
            // le solde des deux serait faux, sans qu'aucune erreur ne le dise.
            if (!$entity->mailleCoherente() && ($pilotable('tranche') || $pilotable('avenant'))) {
                $champ = $pilotable('tranche') ? 'tranche' : 'avenant';

                return [$champ => [
                    'L’échéance et l’affaire réglées doivent appartenir à la même proposition.',
                ]];
            }
        }

        return [];
    }

    /**
     * Un champ scalaire est-il OBLIGATOIRE (non-nullable, sans défaut BDD/PHP, hors
     * système) — ou bien un DISCRIMINANT métier exigé même sur colonne nullable
     * ({@see CHOIX_METIER_REQUIS}) ?
     */
    public function scalaireRequis(ClassMetadata $meta, object $entity, string $field): bool
    {
        if (in_array($field, self::CHAMPS_SYSTEME, true)) {
            return false;
        }
        // Discriminant métier : la nullabilité de la colonne tolère les données
        // héritées, elle ne dispense pas d'un choix explicite à la création.
        if ($this->choixRequis($this->shortName($meta->getName()), $field)) {
            return $this->estVide($entity, $meta, $field);
        }
        if ($meta->isNullable($field)) {
            return false;
        }

        return !$this->aUnDefaut($meta, $field) && !$this->valeurNonNulle($entity, $meta, $field);
    }

    /** Une relation ManyToOne est-elle OBLIGATOIRE (colonne non-null OU requise métier, hors entreprise/invite auto) ? */
    public function relationRequise(string $field, object $mapping): bool
    {
        if (!$mapping->isManyToOne() || !$mapping->isOwningSide() || in_array($field, ['entreprise', 'invite'], true)) {
            return false;
        }
        foreach (($mapping->joinColumns ?? []) as $jc) {
            if (($jc->nullable ?? true) === false) {
                return true;
            }
        }

        // Exigence MÉTIER (colonne nullable mais indispensable à la cohérence).
        $source = $this->shortName((string) ($mapping->sourceEntity ?? ''));

        return in_array($field, self::RELATIONS_METIER_REQUISES[$source] ?? [], true);
    }

    /** Nom court d'une classe (dernier segment du FQCN). */
    private function shortName(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');

        return $pos === false ? $fqcn : substr($fqcn, $pos + 1);
    }

    /** Libellé LISIBLE d'un champ (lu depuis le FormType), repli sur l'humanisation. */
    public function libelleChamp(string $fqcn, string $field): string
    {
        $pos = strrpos($fqcn, '\\');
        $shortName = $pos !== false ? substr($fqcn, $pos + 1) : $fqcn;

        return $this->libellesFormulaire($shortName, $fqcn)[$field] ?? $this->humaniser($field);
    }

    /**
     * DESCRIPTEUR des champs d'une entité, dérivé de son FORMTYPE — le contrat exact de
     * l'interface graphique, et le même principe directeur que {@see FormTreeInspector}
     * pour les collections. Source UNIQUE de tout ce que l'on sait dire d'un champ sans
     * le redéclarer nulle part : son libellé lisible, les VALEURS qu'il accepte quand
     * c'est une liste fermée, le défaut que le formulaire lui impose, son aide métier.
     *
     * Pourquoi les valeurs de choix comptent : la plupart des champs « à cocher » ou
     * « à sélectionner » persistent un CODE (`Piste::AVENANT_SOUSCRIPTION = 0`,
     * `Depense::MOYEN_BANQUE = 'banque'`). L'écran le rend en clair, mais l'assistant —
     * et l'import de fichier — n'avaient aucun moyen de savoir ce qui est permis : ils
     * laissaient donc le champ vide, ou envoyaient le libellé affiché à la place du code.
     *
     * Un seul passage, mis en cache par nom court : l'inventaire des champs construisait
     * auparavant deux à trois fois le même formulaire.
     *
     * @return array<string, array{libelle: string, choix: array<int|string, string>,
     *      defautFormulaire: mixed, aFormulaireData: bool, aide: string|null,
     *      requisFormulaire: bool, multiple: bool, pourcentage: bool}>
     */
    public function descripteursChamps(string $shortName, string $fqcn): array
    {
        if (array_key_exists($shortName, $this->descripteurs)) {
            return $this->descripteurs[$shortName];
        }

        $descripteurs = [];
        try {
            $form = $this->formFactory->create('App\\Form\\' . $shortName . 'Type', new $fqcn());
            foreach ($form->all() as $child) {
                $descripteurs[$child->getName()] = $this->descripteurChamp($child);
            }
        } catch (\Throwable) {
            // Pas de FormType exploitable : les appelants retombent sur l'humanisation
            // et sur les seules métadonnées Doctrine (best-effort, comme avant).
            $descripteurs = [];
        }

        return $this->descripteurs[$shortName] = $descripteurs;
    }

    /**
     * @return array{libelle: string, choix: array<int|string, string>, defautFormulaire: mixed,
     *      aFormulaireData: bool, aide: string|null, requisFormulaire: bool, multiple: bool,
     *      pourcentage: bool}
     */
    private function descripteurChamp(FormInterface $child): array
    {
        $config = $child->getConfig();
        $libelle = $config->getOption('label');
        $aide = $config->getOption('help');

        return [
            'libelle' => is_string($libelle) && trim($libelle) !== '' ? $libelle : '',
            'choix' => $this->choixDuChamp($config->getOption('choices')),
            // L'option « data » d'un FormType PRIME sur la valeur portée par l'entité :
            // un défaut posé sur la propriété y serait silencieusement écrasé. On la lit
            // donc pour annoncer le VRAI défaut, sans chercher à la déplacer.
            'defautFormulaire' => $config->getOption('data'),
            'aFormulaireData' => $config->hasOption('data') && $config->getOption('data') !== null,
            'aide' => is_string($aide) && trim($aide) !== '' ? $aide : null,
            'requisFormulaire' => (bool) $config->getOption('required'),
            'multiple' => (bool) $config->getOption('multiple'),
            // PercentType fractionnel : l'écran attend 15, la colonne stocke 0.15.
            'pourcentage' => $this->estPercentType($config->getType()) && $config->getOption('type') !== 'integer',
        ];
    }

    /**
     * Normalise l'option « choices » d'un ChoiceType en `code => libellé`.
     *
     * Le projet la déclare uniformément dans le sens `libellé => code`
     * (`"Souscription" => Piste::AVENANT_SOUSCRIPTION`, ou `array_flip(LABELS)`), d'où
     * le retournement. On ne lit JAMAIS `choice_label` : c'est une closure qui rend du
     * HTML de présentation (`<div><strong>…`), inutilisable comme libellé de valeur.
     *
     * @return array<int|string, string> vide si le champ n'est pas une liste fermée
     */
    private function choixDuChamp(mixed $choices): array
    {
        if (!is_array($choices) || $choices === []) {
            return [];
        }

        $plat = [];
        foreach ($choices as $libelle => $valeur) {
            // Groupes de choix (`'Groupe' => ['Libellé' => valeur]`) : un niveau aplati.
            if (is_array($valeur)) {
                foreach ($valeur as $sousLibelle => $sousValeur) {
                    if (is_scalar($sousValeur)) {
                        $plat[$this->cleChoix($sousValeur)] = (string) $sousLibelle;
                    }
                }
                continue;
            }
            if (is_scalar($valeur)) {
                $plat[$this->cleChoix($valeur)] = (string) $libelle;
            }
            // Une valeur null (« Tous les paquets » => null) n'est pas un code : ignorée.
        }

        return $plat;
    }

    /** Clé de tableau utilisable pour un code de choix (les booléens deviendraient 0/1 en silence). */
    private function cleChoix(mixed $valeur): int|string
    {
        if (is_bool($valeur)) {
            return $valeur ? '1' : '0';
        }

        return is_int($valeur) ? $valeur : (string) $valeur;
    }

    /**
     * Forme COMPARABLE d'un libellé : sans casse, sans accents, ponctuation d'affichage
     * réduite à des espaces. Sert à reconnaître « Résiliation », « resiliation » et
     * « RÉSILIATION » comme le même choix.
     *
     * Table de translittération explicite, et non `iconv('ASCII//TRANSLIT')` : selon la
     * bibliothèque C de l'hôte, celui-ci rend « R'esiliation » (apostrophe insérée)
     * plutôt que « Resiliation ». La comparaison échouait alors sur les postes Windows
     * et réussissait ailleurs — le pire des deux mondes pour une règle métier.
     *
     * Source UNIQUE de cette normalisation : partagée par la résolution à l'écriture
     * (WorkspaceMutationService) et par la lecture d'un fichier joint.
     */
    public function libelleComparable(string $texte): string
    {
        static $accents = [
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'æ' => 'ae',
            'ç' => 'c', 'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
            'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
            'ñ' => 'n', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o', 'œ' => 'oe',
            'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ý' => 'y', 'ÿ' => 'y', 'ß' => 'ss',
        ];

        $texte = mb_strtolower(strip_tags($texte));
        $texte = strtr($texte, $accents);
        $texte = (string) preg_replace('/[^a-z0-9]+/', ' ', $texte);

        return trim($texte);
    }

    /**
     * Libellés lisibles des champs, lus depuis le FormType.
     *
     * Façade au-dessus de {@see descripteursChamps()} — conservée telle quelle pour ses
     * appelants (messages d'erreur 422, inventaire, lecture de fiche).
     *
     * @return array<string, string>
     */
    public function libellesFormulaire(string $shortName, string $fqcn): array
    {
        $labels = [];
        foreach ($this->descripteursChamps($shortName, $fqcn) as $champ => $d) {
            if ($d['libelle'] !== '') {
                $labels[$champ] = $d['libelle'];
            }
        }

        return $labels;
    }

    /**
     * Champs dont le FORMULAIRE attend un POURCENTAGE alors que la base stocke une
     * FRACTION (`PercentType` en mode « fractional », le défaut Symfony : l'écran
     * affiche 15, la colonne contient 0.15).
     *
     * Piège majeur pour l'assistant : la valeur qu'il LIT (0.15) n'est pas celle
     * qu'il doit ÉCRIRE (15). La recopier telle quelle divise le taux par 100, en
     * silence. On expose donc la liste pour l'annoncer explicitement dans
     * l'inventaire des champs et dans la lecture de fiche — au lieu de la coder en
     * dur entité par entité, on la DÉDUIT du formulaire, comme le reste.
     *
     * @return string[] noms de champs
     */
    public function champsPourcentage(string $shortName, string $fqcn): array
    {
        $champs = [];
        foreach ($this->descripteursChamps($shortName, $fqcn) as $champ => $d) {
            if ($d['pourcentage']) {
                $champs[] = $champ;
            }
        }

        return $champs;
    }

    /**
     * Valeurs acceptées par un champ de CHOIX FERMÉ, en `code => libellé`.
     * Tableau vide si le champ n'est pas une liste fermée (texte libre, relation, date).
     *
     * @return array<int|string, string>
     */
    public function choixDisponibles(string $shortName, string $fqcn, string $field): array
    {
        return $this->descripteursChamps($shortName, $fqcn)[$field]['choix'] ?? [];
    }

    /**
     * Valeurs acceptées par un champ de formulaire DÉJÀ CONSTRUIT, en `code => libellé`.
     *
     * À préférer à {@see choixDisponibles()} quand on tient le formulaire réel : un
     * enfant de collection est édité par l'`entry_type` déclaré par son parent, qui n'est
     * pas nécessairement `App\Form\{Nom}Type`. Lire le champ vivant évite de reconstruire
     * — et de se tromper de — formulaire.
     *
     * @return array<int|string, string>
     */
    public function choixDuFormulaire(FormInterface $child): array
    {
        return $this->choixDuChamp($child->getConfig()->getOption('choices'));
    }

    /**
     * Un champ de choix est-il un DISCRIMINANT métier exigé à la création ?
     * {@see CHOIX_METIER_REQUIS} pour le raisonnement champ par champ.
     */
    public function choixRequis(string $shortName, string $field): bool
    {
        return in_array($field, self::CHOIX_METIER_REQUIS[$shortName] ?? [], true);
    }

    private function estPercentType(?ResolvedFormTypeInterface $type): bool
    {
        while ($type !== null) {
            if ($type->getInnerType() instanceof PercentType) {
                return true;
            }
            $type = $type->getParent();
        }

        return false;
    }

    /** Humanise un nom de champ technique (fallback quand le FormType n'a pas de libellé). */
    public function humaniser(string $field): string
    {
        $s = (string) preg_replace('/(?<!^)[A-Z]/', ' $0', $field);
        $s = str_replace('_', ' ', $s);

        return ucfirst(mb_strtolower(trim($s)));
    }

    /** La valeur de l'entité pour ce champ est-elle déjà non nulle (défaut PHP) ? */
    public function valeurNonNulle(object $entity, ClassMetadata $meta, string $field): bool
    {
        try {
            return $meta->getFieldValue($entity, $field) !== null;
        } catch (\Throwable) {
            return false; // propriété typée non initialisée => considérée manquante.
        }
    }

    /** Le champ a-t-il un défaut au niveau de la colonne (rempli par la BDD) ? */
    public function aUnDefaut(ClassMetadata $meta, string $field): bool
    {
        return $this->optionsColonne($meta, $field) !== null;
    }

    /**
     * VALEUR du défaut de colonne, à annoncer (et non plus seulement à compter).
     * `aUnDefaut()` ne répondait qu'oui/non, pour EXCLURE le champ des obligatoires : la
     * valeur était lue puis jetée, si bien qu'on ne pouvait pas dire à l'utilisateur — ni
     * à l'assistant — ce qui allait être écrit.
     *
     * @return mixed null si le champ n'a pas de défaut de colonne
     */
    public function defautColonne(ClassMetadata $meta, string $field): mixed
    {
        return $this->optionsColonne($meta, $field)['default'] ?? null;
    }

    /**
     * Options de mapping d'une colonne, uniquement si elle porte un « default ».
     * Compatible mapping objet (ORM 3) et tableau (ORM 2).
     *
     * @return array<string, mixed>|null
     */
    private function optionsColonne(ClassMetadata $meta, string $field): ?array
    {
        try {
            $mapping = $meta->getFieldMapping($field);
        } catch (\Throwable) {
            return null;
        }
        $options = is_object($mapping) ? ($mapping->options ?? []) : ($mapping['options'] ?? []);

        return is_array($options) && array_key_exists('default', $options) ? $options : null;
    }

    /** Une valeur scalaire est-elle « vide » (null ou chaîne blanche) ? */
    private function estVide(object $entity, ClassMetadata $meta, string $field): bool
    {
        try {
            $v = $meta->getFieldValue($entity, $field);
        } catch (\Throwable) {
            return true; // propriété typée non initialisée => manquante.
        }
        if ($v === null) {
            return true;
        }

        return is_string($v) && trim($v) === '';
    }
}
