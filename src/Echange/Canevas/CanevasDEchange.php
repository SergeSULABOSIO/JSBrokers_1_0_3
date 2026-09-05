<?php

namespace App\Echange\Canevas;

use App\Ai\Mutation\MutationAllowlist;
use App\Entity\Invite;
use App\Service\Workspace\ChampsObligatoiresInspector;
use App\Service\Workspace\WorkspaceAccessResolver;
use App\Services\CanvasBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ToOneOwningSideMapping;
use Doctrine\DBAL\Types\Types;

/**
 * SOURCE UNIQUE de tout ce que le format d'échange expose : quelles entités sont
 * échangeables, dans quel ordre, avec quelles colonnes, quels libellés, quelles
 * valeurs acceptées.
 *
 * ⚠ CE SERVICE NE DÉCLARE RIEN. Il croise quatre sources qui existaient déjà, et c'est
 * tout son intérêt : la spécification demandait un descripteur par entité, soit une
 * quarantaine de fichiers à tenir en phase avec les FormTypes — c'est-à-dire à
 * désynchroniser. Ici, AJOUTER UNE ENTITÉ AU PÉRIMÈTRE D'ÉCHANGE NE DEMANDE QU'UN NOM
 * dans {@see MutationAllowlist::MEMBRES}, et l'assistant la connaît du même geste.
 *
 * Les quatre sources :
 *  1. {@see MutationAllowlist::MEMBRES} — le périmètre. Ces entités sont déjà auditées
 *     (FormType présent, pas de setter à cascade piégeuse) et déjà ouvertes à
 *     l'écriture par l'assistant : l'échange n'ouvre donc aucune porte nouvelle.
 *  2. {@see WorkspaceAccessResolver} — le libellé de rubrique ET le droit. Le filtrage
 *     par les droits n'est pas un raffinement : sans lui, la rubrique serait un
 *     contournement propre de toute la matrice d'accès de l'application.
 *  3. {@see ChampsObligatoiresInspector::descripteursChamps()} — libellé de champ,
 *     énumérations, obligatoire, aide, piège du pourcentage. Le FormType EST le contrat
 *     de ce qu'un utilisateur a le droit d'écrire : un champ qui n'y figure pas est
 *     exporté en lecture seule, jamais réimporté.
 *  4. Les métadonnées Doctrine — type, nullabilité, associations.
 *
 * Le canevas est construit une fois puis mémoïsé : il ne dépend que du code, jamais
 * des données. Seul le FILTRAGE par les droits dépend de l'invité.
 */
final class CanevasDEchange
{
    /**
     * Jamais exportés comme colonnes modifiables.
     *
     * `entreprise` et `invite` viennent d'AuditableTrait : ce sont les colonnes de
     * SCOPING, posées automatiquement. Les exposer laisserait écrire l'appartenance
     * d'un enregistrement à un cabinet — c'est-à-dire déplacer une police chez le
     * voisin depuis un tableur. Elles ne figurent donc pas du tout dans le classeur.
     */
    private const CHAMPS_EXCLUS = ['id', 'entreprise', 'invite'];

    /**
     * Colonnes techniques présentes sur CHAQUE feuille de données, avant les colonnes
     * métier. Le préfixe `_` les distingue à l'œil comme au parsing.
     */
    /** Famille retenue d'office à l'export. Voir codesParDefaut(). */
    public const MODULE_PAR_DEFAUT = 'Production';

    public const COL_UID        = '_uid';
    public const COL_ACTION     = '_action';
    public const COL_REF        = '_ref';
    public const COL_MODIFIE_LE = '_modifie_le';

    /** Valeurs acceptées par la colonne `_action`. `SUPPRIMER` n'est jamais déduit. */
    public const ACTION_CREER     = 'CREER';
    public const ACTION_MAJ       = 'MAJ';
    public const ACTION_SUPPRIMER = 'SUPPRIMER';

    /** @var array<string, RessourceDEchange>|null mémoïsation du canevas complet */
    private ?array $canevas = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly WorkspaceAccessResolver $accessResolver,
        private readonly ChampsObligatoiresInspector $inspecteur,
        private readonly CanvasBuilder $canvasBuilder,
        private readonly OrdreTopologique $ordre,
    ) {
    }

    /**
     * TOUTES les ressources échangeables, en ordre topologique — sans considération de
     * droits. Réservé aux usages qui n'ont pas d'utilisateur (tests de structure,
     * diagnostic). Tout chemin exposé à un humain passe par les deux méthodes suivantes.
     *
     * @return array<string, RessourceDEchange>
     */
    public function toutes(): array
    {
        return $this->canevas ??= $this->construire();
    }

    /**
     * Ressources que CET invité a le droit de LIRE — le périmètre d'un export.
     *
     * @return array<string, RessourceDEchange>
     */
    public function ressourcesLisibles(Invite $invite): array
    {
        return array_filter(
            $this->toutes(),
            fn (RessourceDEchange $r) => $this->accessResolver->canRead($invite, $r->code),
        );
    }

    /**
     * Ressources que CET invité a le droit d'ÉCRIRE — le périmètre d'un import.
     * Une ligne visant une ressource absente d'ici est une erreur bloquante au contrôle.
     *
     * @return array<string, RessourceDEchange>
     */
    public function ressourcesEcrivables(Invite $invite): array
    {
        return array_filter(
            $this->toutes(),
            fn (RessourceDEchange $r) => $this->accessResolver->can($invite, $r->code, Invite::ACCESS_ECRITURE),
        );
    }

    public function ressource(string $code): ?RessourceDEchange
    {
        return $this->toutes()[$code] ?? null;
    }

    /**
     * Ferme un périmètre demandé sur ses dépendances : cocher une entité coche celles
     * dont elle a besoin, et l'écran les présente verrouillées. Sans cette fermeture,
     * un export « juste les polices » produirait des renvois vers des lignes absentes.
     *
     * @param string[]                         $codes      demandés
     * @param array<string, RessourceDEchange> $autorisees périmètre lisible de l'invité
     *
     * @return string[] codes retenus, en ordre topologique
     */
    /**
     * LE PÉRIMÈTRE PROPOSÉ D'OFFICE À L'EXPORT : la famille Production, fermée sur ses
     * dépendances.
     *
     * Exporter les quarante-deux données est rarement ce qu'on veut : le geste courant
     * est de sortir son activité — clients, polices, propositions — et non ses taxes,
     * ses monnaies ni ses types d'absence, qui ne bougent presque jamais et qu'on
     * réimporterait par-dessus eux-mêmes. Proposer tout revenait à faire décocher
     * trente lignes à chaque fois, et donc, en pratique, à ne rien décocher du tout.
     *
     * ⚠ RIEN N'EST DÉCLARÉ ICI. La famille vient de la carte des droits, et ce qui
     * l'accompagne vient de la MÊME fermeture que celle d'un clic : une police tire son
     * client, et la piste dont elle est née. Tenir une liste de codes « données de
     * production » aurait créé une seconde vérité, qui aurait divergé au premier ajout
     * d'entité.
     *
     * ⚠ ET CE N'EST QU'UNE PROPOSITION. Tout reste cochable d'un geste ; le choix, une
     * fois fait, survit au rechargement.
     *
     * @param array<string, RessourceDEchange> $lisibles le périmètre de l'invité
     *
     * @return string[] codes retenus d'office, dans l'ordre du classeur
     */
    public function codesParDefaut(array $lisibles): array
    {
        $amorce = [];
        foreach ($lisibles as $code => $ressource) {
            if ($ressource->module === self::MODULE_PAR_DEFAUT) {
                $amorce[] = $code;
            }
        }

        // Un invité qui n'a aucun droit en Production n'aurait rien de coché : mieux
        // vaut alors lui proposer tout ce qu'il peut lire que rien du tout.
        if ($amorce === []) {
            return array_keys($lisibles);
        }

        return $this->fermerSurLesDependances($amorce, $lisibles);
    }

    public function fermerSurLesDependances(array $codes, array $autorisees): array
    {
        $retenus = [];
        $file = array_values(array_intersect($codes, array_keys($autorisees)));

        while ($file !== []) {
            $code = array_shift($file);
            if (isset($retenus[$code])) {
                continue;
            }
            $retenus[$code] = true;
            foreach ($autorisees[$code]->dependances as $dep) {
                // Une dépendance HORS du périmètre lisible n'est pas tirée de force :
                // le droit prime sur la complétude du fichier. La colonne qui y renvoie
                // reste exportée en clair, et le dictionnaire dit pourquoi.
                if (isset($autorisees[$dep]) && !isset($retenus[$dep])) {
                    $file[] = $dep;
                }
            }
        }

        return array_values(array_filter(
            array_keys($autorisees),
            static fn (string $code) => isset($retenus[$code]),
        ));
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Construction
    // ─────────────────────────────────────────────────────────────────────────────

    /** @return array<string, RessourceDEchange> */
    private function construire(): array
    {
        $libelles = $this->accessResolver->libellesEntites();
        $modules = $this->accessResolver->modulesEntites();
        $feuilles = [];
        $brutes = [];
        $arcs = [];
        $arcsDurs = [];

        foreach (MutationAllowlist::MEMBRES as $code) {
            $fqcn = 'App\\Entity\\' . $code;
            if (!class_exists($fqcn)) {
                // Membre sans classe Doctrine : impossible ici (l'allowlist n'en contient
                // pas), mais l'ignorer coûte moins qu'une erreur fatale au chargement.
                continue;
            }

            $meta = $this->em->getClassMetadata($fqcn);
            $libelle = $libelles[$code] ?? $code;

            [$colonnes, $arcsDeLaRessource, $arcsDursDeLaRessource] = $this->colonnesDe($code, $fqcn, $meta);

            foreach ($arcsDeLaRessource as $cible => $cols) {
                $arcs[$code][$cible] = array_merge($arcs[$code][$cible] ?? [], $cols);
            }
            foreach ($arcsDursDeLaRessource as $cible => $cols) {
                $arcsDurs[$code][$cible] = array_merge($arcsDurs[$code][$cible] ?? [], $cols);
            }

            $brutes[$code] = new RessourceDEchange(
                code: $code,
                libelle: $libelle,
                // Le module vient de la MEME carte que le libellé : rien de nouveau à
                // déclarer, et les deux ne peuvent pas diverger.
                module: $modules[$code] ?? 'Autres',
                fqcn: $fqcn,
                feuille: $this->nomDeFeuille($libelle, $code, $feuilles),
                rang: 0,
                colonnes: $colonnes,
                dependances: [],
            );
        }

        // Les dépendances se lisent des arcs, une fois toutes les ressources connues :
        // une cible hors périmètre n'est pas une dépendance, seulement une colonne
        // descriptive.
        $avecDependances = [];
        foreach ($brutes as $code => $ressource) {
            $deps = array_values(array_filter(
                array_unique(array_merge(
                    array_keys($arcs[$code] ?? []),
                    array_keys($arcsDurs[$code] ?? []),
                )),
                static fn (string $cible) => isset($brutes[$cible]) && $cible !== $code,
            ));
            sort($deps);

            $avecDependances[$code] = new RessourceDEchange(
                code: $ressource->code,
                libelle: $ressource->libelle,
                module: $ressource->module,
                fqcn: $ressource->fqcn,
                feuille: $ressource->feuille,
                rang: 0,
                colonnes: $ressource->colonnes,
                dependances: $deps,
            );
        }

        return $this->ordre->trier($avecDependances, $arcs, $arcsDurs);
    }

    /**
     * Colonnes d'une ressource, et les arcs de dépendance qu'elles induisent.
     *
     * @return array{0: ColonneDEchange[], 1: array<string, string[]>, 2: array<string, string[]>}
     */
    private function colonnesDe(string $code, string $fqcn, ClassMetadata $meta): array
    {
        // Le FORMTYPE est le contrat de ce qu'un utilisateur peut écrire. Un champ qui
        // n'y figure pas existe peut-être en base, mais aucune interface ne le propose :
        // on l'exporte pour information et on l'ignore au retour.
        $descripteurs = $this->inspecteur->descripteursChamps($code, $fqcn);
        $formatsCanevas = $this->formatsDuCanevas($fqcn);

        $colonnes = [];
        $arcs = [];
        $arcsDurs = [];

        // ── Champs scalaires ────────────────────────────────────────────────────────
        foreach ($meta->getFieldNames() as $champ) {
            if (in_array($champ, self::CHAMPS_EXCLUS, true)) {
                continue;
            }
            // createdAt / updatedAt sont exportés comme colonnes TECHNIQUES
            // (`_modifie_le`), pas comme colonnes métier : les redonner ici les rendrait
            // modifiables, or l'ORM les repose au flush — une promesse qu'on ne tient pas.
            if (in_array($champ, ChampsObligatoiresInspector::CHAMPS_SYSTEME, true)) {
                continue;
            }

            $descripteur = $descripteurs[$champ] ?? null;
            $choix = $descripteur['choix'] ?? [];
            $type = $choix !== []
                ? ColonneDEchange::TYPE_ENUM
                : $this->typeDepuisDoctrine($meta->getTypeOfField($champ));

            $colonnes[] = new ColonneDEchange(
                code: $champ,
                libelle: $this->libelleDe($descripteur, $champ),
                type: $type,
                obligatoire: $this->estObligatoire($meta, $champ, $descripteur),
                // Absent du FormType => aucune interface ne l'écrit => lecture seule.
                lectureSeule: $descripteur === null,
                choix: $choix,
                formatExcel: $formatsCanevas[$champ] ?? $this->formatParDefaut($type),
                aide: $descripteur['aide'] ?? null,
                pourcentage: (bool) ($descripteur['pourcentage'] ?? false),
                // Un champ à choix MULTIPLES porte un TABLEAU de codes : l'export les
                // sépare dans une seule cellule, et la relecture doit les redécouper.
                // Sans ce drapeau, elle ne verrait qu'une valeur unique introuvable.
                multiple: (bool) ($descripteur['multiple'] ?? false),
            );
        }

        // ── Associations vers-un ────────────────────────────────────────────────────
        foreach ($meta->getAssociationMappings() as $champ => $mapping) {
            if (in_array($champ, self::CHAMPS_EXCLUS, true)) {
                continue;
            }
            // Une collection n'est pas une colonne : elle se lit depuis la feuille de
            // l'entité portée, par sa propre colonne de renvoi. Le canevas de recherche
            // écarte les collections pour la même raison.
            if (!$meta->isSingleValuedAssociation($champ)) {
                continue;
            }
            // Côté inverse d'une association : la colonne n'existe pas en base, l'écrire
            // n'aurait aucun effet et la lire duplique l'information du côté porteur.
            // Le test `instanceof` couvre les deux d'un coup — seul le côté propriétaire
            // d'une relation vers-un porte des joinColumns.
            if (!$mapping instanceof ToOneOwningSideMapping) {
                continue;
            }

            $cible = $this->nomCourt($mapping->targetEntity);
            $dansLePerimetre = in_array($cible, MutationAllowlist::MEMBRES, true);
            $descripteur = $descripteurs[$champ] ?? null;

            $colonnes[] = new ColonneDEchange(
                code: $champ,
                libelle: $this->libelleDe($descripteur, $champ),
                type: ColonneDEchange::TYPE_REFERENCE,
                obligatoire: $dansLePerimetre && !$this->associationEstNullable($mapping),
                lectureSeule: $descripteur === null,
                referenceCode: $cible,
                referenceHorsPerimetre: !$dansLePerimetre,
                aide: $descripteur['aide'] ?? null,
            );

            // Un renvoi hors périmètre, ou vers soi-même, ne contraint aucun ordre.
            if (!$dansLePerimetre || $cible === $code) {
                continue;
            }
            if ($this->associationEstNullable($mapping)) {
                $arcs[$cible][] = $champ;
            } else {
                $arcsDurs[$cible][] = $champ;
            }
        }

        return [$colonnes, $arcs, $arcsDurs];
    }

    /**
     * Formats de nombre Excel dérivés du CANEVAS D'ENTITÉ : c'est lui qui sait qu'un
     * champ est un montant (donc deux décimales et un séparateur de milliers) ou un
     * taux. On ne redéclare pas cette connaissance ici.
     *
     * @return array<string, string>
     */
    private function formatsDuCanevas(string $fqcn): array
    {
        try {
            $canevas = $this->canvasBuilder->getEntityCanvas($fqcn);
        } catch (\Throwable) {
            return [];
        }

        $formats = [];
        foreach ($canevas['liste'] ?? [] as $attribut) {
            $code = $attribut['code'] ?? null;
            if (!is_string($code)) {
                continue;
            }
            $formats[$code] = match ($attribut['format'] ?? null) {
                'Monetaire'   => '#,##0.00',
                'Pourcentage' => '0.00',
                'Entier'      => '0',
                'Nombre'      => '#,##0.00',
                default       => null,
            } ?? $formats[$code] ?? null;

            if ($formats[$code] === null) {
                unset($formats[$code]);
            }
        }

        return $formats;
    }

    /** @param array{libelle?: string}|null $descripteur */
    private function libelleDe(?array $descripteur, string $champ): string
    {
        $libelle = $descripteur['libelle'] ?? '';

        return trim($libelle) !== '' ? $libelle : $this->inspecteur->humaniser($champ);
    }

    /**
     * Un champ est obligatoire s'il l'est en base (colonne non nullable sans défaut) ou
     * si le formulaire l'exige. On interroge les deux : la nullabilité tolère les
     * données héritées, le formulaire porte l'exigence métier.
     *
     * @param array{requisFormulaire?: bool, aFormulaireData?: bool}|null $descripteur
     */
    private function estObligatoire(ClassMetadata $meta, string $champ, ?array $descripteur): bool
    {
        if ($descripteur === null) {
            return false;
        }
        // Un défaut posé par le FormType rend le champ facultatif à la saisie : le
        // laisser vide n'est pas une omission, c'est accepter le défaut.
        if ($descripteur['aFormulaireData'] ?? false) {
            return false;
        }
        if ($descripteur['requisFormulaire'] ?? false) {
            return true;
        }

        try {
            return $meta->isNullable($champ) === false;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * La clé étrangère tolère-t-elle NULL ? C'est cette réponse, et elle seule, qui
     * décide si une dépendance peut être différée pour trancher un cycle.
     *
     * ⚠ On lit les PROPRIÉTÉS, pas les clés de tableau. Depuis Doctrine ORM 3, les
     * mappings sont des objets ; ils restent accessibles comme des tableaux, mais chaque
     * accès émet une dépréciation — et une association parcourue pour quarante-deux
     * entités en émettait plus de quinze cents à elle seule, noyant le rapport de tests
     * sous du bruit que personne ne relirait.
     */
    private function associationEstNullable(ToOneOwningSideMapping $mapping): bool
    {
        foreach ($mapping->joinColumns as $colonne) {
            if ($colonne->nullable === false) {
                return false;
            }
        }

        return true;
    }

    private function typeDepuisDoctrine(?string $type): string
    {
        return match ($type) {
            Types::INTEGER, Types::BIGINT, Types::SMALLINT => ColonneDEchange::TYPE_ENTIER,
            Types::FLOAT, Types::DECIMAL                   => ColonneDEchange::TYPE_DECIMAL,
            Types::BOOLEAN                                 => ColonneDEchange::TYPE_BOOLEEN,
            Types::DATE_MUTABLE, Types::DATE_IMMUTABLE     => ColonneDEchange::TYPE_DATE,
            Types::DATETIME_MUTABLE, Types::DATETIME_IMMUTABLE,
            Types::DATETIMETZ_MUTABLE, Types::DATETIMETZ_IMMUTABLE => ColonneDEchange::TYPE_DATETIME,
            default                                        => ColonneDEchange::TYPE_TEXTE,
        };
    }

    private function formatParDefaut(string $type): ?string
    {
        return match ($type) {
            ColonneDEchange::TYPE_ENTIER  => '0',
            ColonneDEchange::TYPE_DECIMAL => '#,##0.00',
            default                       => null,
        };
    }

    /**
     * Nom d'onglet Excel : 31 caractères au plus, sans les caractères qu'Excel refuse
     * (`[ ] : * ? / \`), et UNIQUE dans le classeur — deux libellés tronqués peuvent se
     * rejoindre, et Excel refuse alors d'ouvrir le fichier.
     *
     * @param array<string, true> $dejaPris (par référence)
     */
    private function nomDeFeuille(string $libelle, string $code, array &$dejaPris): string
    {
        $nom = trim((string) preg_replace('/[\\[\\]:*?\\/\\\\]+/u', ' ', $libelle));
        $nom = trim((string) preg_replace('/\s+/u', ' ', $nom));
        if ($nom === '') {
            $nom = $code;
        }
        $nom = mb_substr($nom, 0, 31);

        if (isset($dejaPris[$nom])) {
            // Suffixe numérique, en rognant juste ce qu'il faut pour rester sous 31.
            $i = 2;
            do {
                $suffixe = ' ' . $i;
                $candidat = mb_substr($nom, 0, 31 - mb_strlen($suffixe)) . $suffixe;
                ++$i;
            } while (isset($dejaPris[$candidat]));
            $nom = $candidat;
        }

        $dejaPris[$nom] = true;

        return $nom;
    }

    private function nomCourt(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');

        return $pos === false ? $fqcn : substr($fqcn, $pos + 1);
    }
}
