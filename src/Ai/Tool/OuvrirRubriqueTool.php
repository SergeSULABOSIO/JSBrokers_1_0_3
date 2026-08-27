<?php

namespace App\Ai\Tool;

use App\Services\Search\ReversementScope;
use App\Repository\InviteRepository;
use App\Entity\Invite;
use App\Ai\Action\TypeAction;

use App\Ai\AiText;
use App\Ai\Resolution\CheminsDeRelation;
use App\Ai\Resolution\ResolveurDeReferences;
use App\Ai\Scope\AiScope;
use App\Service\Workspace\WorkspaceAccessResolver;
use App\Services\Search\AvenantEcheanceScope;
use App\Services\Search\CotationSouscriptionScope;
use App\Services\Search\PisteTransformationScope;
use App\Services\Search\TranchePaiementScope;

/**
 * Outil d'ACTION UI : ouvre la RUBRIQUE (liste) d'une entité dans le menu du
 * workspace — « ouvre la rubrique bordereaux », « va dans les clients ». Émet
 * une directive d'intention (uiAction) que le chat traduit en navigation via
 * le bus (`app:workspace.open-rubrique`, geste identique au clic sur le menu).
 * FAIL-CLOSED : lecture requise sur l'entité — le menu lui-même est filtré au
 * périmètre, l'assistant respecte le même contrat.
 */
final class OuvrirRubriqueTool implements AiToolInterface
{
    public function __construct(
        private readonly WorkspaceAccessResolver $accessResolver,
        private readonly EntiteLexique $lexique,
        // La rubrique s'ouvre FILTRÉE : le nom dicté (« Mme Marlette ») devient un
        // identifiant, et le graphe des relations dit par quel chemin la rubrique le
        // rejoint. Mêmes sources que rechercher_entites — c'est ce qui garantit que
        // l'écran montre la MÊME liste que la réponse du chat.
        private readonly ResolveurDeReferences $resolveur,
        private readonly CheminsDeRelation $chemins,
        private readonly EntiteLibelle $libelleur,
        // Un agent bénéficiaire n'est pas une rubrique : il se cherche à part.
        private readonly InviteRepository $inviteRepository,
    ) {
    }

    public function name(): string
    {
        return 'ouvrir_rubrique';
    }

    public function description(): string
    {
        return "Ouvre dans l'espace de travail la RUBRIQUE (liste) d'une catégorie de "
            . 'données — ou le TABLEAU DE BORD (entite=TableauDeBord) : l\'utilisateur voit la '
            . 'vue à l\'écran, ajoutée aux onglets et activée. À appeler quand l\'utilisateur '
            . 'demande d\'ouvrir/afficher une rubrique, une section ou le tableau de bord '
            . '(« ouvre les bordereaux », « ouvre le tableau de bord »). '
            . 'LA RUBRIQUE S\'OUVRE FILTRÉE : transmets le MÊME périmètre que celui de ta '
            . 'réponse écrite — lieA pour un rattachement (lieA={entite:"Client", nom:"Marlette"} '
            . 'accepte un NOM autant qu\'un id, le serveur le résout et calcule le chemin de '
            . 'relations lui-même), filtre pour un texte, et echeance / statutPaiement / '
            . 'validation / transformation pour les filtres rapides des rubriques. RÈGLE '
            . 'ABSOLUE : si ta réponse écrite porte sur un sous-ensemble (les pistes d\'UN '
            . 'client, les polices échues, les tranches impayées), la rubrique doit porter le '
            . 'MÊME sous-ensemble — ouvrir la liste entière pendant que le chat en annonce deux '
            . 'lignes, c\'est se contredire à l\'écran. '
            . 'CET OUTIL NE FERME RIEN : ouvrir le tableau de bord laisse tous les onglets '
            . 'ouverts. Pour FERMER, c\'est fermer_rubrique, et rien d\'autre.';
    }

    public function aiguillage(): string
    {
        return '« ouvre la rubrique X », « ouvre / montre-moi cette liste dans le workspace », « ouvre le '
            . 'tableau de bord » (entite=TableauDeBord pour ce dernier). Quand la demande combine une '
            . 'RÉPONSE et un AFFICHAGE (« donne-moi les pistes de Mme Marlette ET ouvre cette liste »), '
            . 'appelle rechercher_entites ET cet outil dans le MÊME tour, avec le MÊME lieA : la liste à '
            . 'l\'écran doit être celle que tu énumères. JAMAIS pour fermer : « ferme la rubrique X » '
            . 'appelle fermer_rubrique — ouvrir le tableau de bord ne ferme aucun onglet, et l\'annoncer '
            . 'comme une fermeture est un mensonge visible à l\'écran.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'entite' => [
                    'type' => 'string',
                    'description' => "Nom court de l'entité de la rubrique à ouvrir, ou TableauDeBord.",
                    'enum' => array_merge(['TableauDeBord'], $this->lexique->nomsCourts()),
                ],
                'lieA' => [
                    'type' => 'object',
                    'description' => 'Restreint la liste affichée aux enregistrements RATTACHÉS à une '
                        . 'fiche précise — le même paramètre que rechercher_entites, et il faut y '
                        . 'mettre la même chose. Le NOM suffit : {"entite":"Client","nom":"Marlette"}. '
                        . 'Le serveur résout le nom et trouve seul le chemin de relations (direct ou '
                        . 'à plusieurs niveaux).',
                    'properties' => [
                        'entite' => ['type' => 'string', 'enum' => $this->lexique->nomsCourts()],
                        'id'     => ['type' => 'integer'],
                        'nom'    => ['type' => 'string'],
                    ],
                    'required' => ['entite'],
                ],
                'filtre' => [
                    'type' => 'string',
                    'description' => 'Filtre TEXTE appliqué au champ de recherche de la rubrique '
                        . '(le libellé des enregistrements). À n\'utiliser que si la demande porte '
                        . 'vraiment sur un texte : pour un rattachement, c\'est lieA.',
                ],
                // MÊME VOCABULAIRE que rechercher_entites, délibérément : une intention
                // exprimée une fois doit piloter la réponse ÉCRITE et l'écran sans être
                // retraduite. Deux jeux de noms auraient produit deux listes.
                'echeance' => [
                    'type' => 'string',
                    'enum' => array_keys(AvenantEcheanceScope::VALEURS),
                    'description' => 'AVENANT uniquement : ouvre la rubrique sur une fenêtre d\'échéance '
                        . '(chip de la rubrique) — echus, sous_30j, de_31_a_60j, au_dela_60j, '
                        . 'non_renouvelables. Exactement les valeurs de rechercher_entites.',
                ],
                'axes' => TranchePaiementScope::proprieteSchema(
                    'TRANCHE uniquement : ouvre la rubrique sur les mêmes groupes de chips.'
                ),
                'validation' => [
                    'type' => 'string',
                    'enum' => array_keys(CotationSouscriptionScope::VALEURS),
                    'description' => 'COTATION uniquement : ouvre la rubrique sur un statut de '
                        . 'souscription (souscrites, en_attente, caduques).',
                ],
                'transformation' => [
                    'type' => 'string',
                    'enum' => array_keys(PisteTransformationScope::VALEURS),
                    'description' => 'PISTE uniquement : ouvre la rubrique sur un statut de '
                        . 'transformation (transformees, en_cours).',
                ],
                // REVERSEMENTS : les mêmes chips que l'écran, mot pour mot. Le vocabulaire
                // vient de ReversementScope, comme les options de la barre — deux listes
                // de valeurs auraient fini par désigner deux sous-ensembles.
                'justificatif' => ReversementScope::proprieteSchema(
                    ReversementScope::CLE_JUSTIFICATIF,
                    'REVERSEMENT uniquement : les versements selon qu\'ils portent une pièce justificative ou non.',
                ),
                'periode' => ReversementScope::proprieteSchema(
                    ReversementScope::CLE_PERIODE,
                    'REVERSEMENT uniquement : la fenêtre de dates du versement.',
                ),
                'virement' => ReversementScope::proprieteSchema(
                    ReversementScope::CLE_VIREMENT,
                    'REVERSEMENT uniquement : virement GROUPÉ (qui solde plusieurs affaires) ou ISOLÉ.',
                ),
                'type' => ReversementScope::proprieteSchema(
                    ReversementScope::CLE_TYPE,
                    'REVERSEMENT uniquement : la famille du bénéficiaire — AGENT interne (salarié) '
                        . 'ou PARTENAIRE externe (intermédiaire). Les deux vivent dans la même '
                        . 'rubrique ; ce paramètre est le seul moyen de lire l\x27une sans l\x27autre.',
                ),
                'beneficiaire' => [
                    'type' => 'string',
                    'description' => 'REVERSEMENT uniquement : le NOM de l\'agent bénéficiaire '
                        . '(« les versements d\'Alice »). Un agent n\'est pas une rubrique et ne '
                        . 'se met donc pas dans lieA : c\'est ce paramètre qui le résout.',
                ],
            ],
            'required' => ['entite'],
        ];
    }

    /**
     * Chemin simulé : « tableau de bord / dashboard » (vue spéciale, sans mot
     * « rubrique » requis), sinon « rubrique/section/module » + entité du lexique.
     */
    public function match(string $question, AiScope $scope): ?array
    {
        $normalized = AiText::normalize($question);

        if (preg_match('/\b(tableau de bord|dashboard)\b/', $normalized)
            && preg_match('/\b(ouvre[sz]?|ouvrir|affiche[rsz]?|montre[rsz]?|va|aller)\b/', $normalized)) {
            return ['entite' => 'TableauDeBord'];
        }

        if (!preg_match('/\b(rubrique|section|module)\b/', $normalized)) {
            return null;
        }

        $shortName = $this->lexique->matchEntite($normalized);

        return $shortName === null ? null : ['entite' => $shortName];
    }

    public function execute(array $args, AiScope $scope): AiToolResult
    {
        $shortName = (string) ($args['entite'] ?? '');

        // TABLEAU DE BORD : vue spéciale hors carte de rubriques, accessible à
        // tous les invités (son contenu est de toute façon filtré au périmètre).
        if ($shortName === 'TableauDeBord') {
            return AiToolResult::ok(
                [
                    'entite'  => 'TableauDeBord',
                    'libelle' => 'Tableau de bord',
                    'note'    => 'Le tableau de bord s\'ouvre dans un onglet de l\'espace de travail et devient actif.',
                ],
                uiAction: ['type' => TypeAction::OUVRIR_RUBRIQUE->value, 'entite' => 'TableauDeBord'],
            );
        }

        $labels = $this->accessResolver->libellesEntites();
        if (!isset($labels[$shortName])) {
            return AiToolResult::introuvable($shortName);
        }

        // FAIL-CLOSED : le menu est filtré au périmètre — même contrat ici.
        if (!$this->accessResolver->canRead($scope->invite, $shortName)) {
            return AiToolResult::horsPerimetre($labels[$shortName]);
        }

        // LA RUBRIQUE S'OUVRE FILTRÉE. C'est tout l'objet de ce bloc, et la correction
        // de l'incident du 2026-08-10 : « donne-moi les pistes de Mme Marlette et ouvre
        // cette liste » rendait DEUX pistes dans le chat et affichait CINQ pistes à
        // l'écran, celles de tout le monde. Le chat et l'écran se contredisaient sous
        // les yeux de l'utilisateur. Le filtre est donc calculé ICI, en PHP, à partir de
        // la même intention et avec les mêmes sources que rechercher_entites.
        $criteres = [];
        $filtres = [];

        $lieA = is_array($args['lieA'] ?? null) ? $args['lieA'] : [];
        if ($lieA !== []) {
            $issue = $this->critereDeRattachement($shortName, $lieA, $scope, $criteres, $filtres);
            if ($issue !== null) {
                return $issue;
            }
        }

        // Filtre TEXTE : le champ de recherche de la rubrique porte sur le libellé.
        $filtre = trim((string) ($args['filtre'] ?? ''));
        $champLibelle = $this->libelleur->displayField('App\\Entity\\' . $shortName);
        if ($filtre !== '' && $champLibelle !== null) {
            $criteres[$champLibelle] = [
                'operator' => 'LIKE',
                'value'    => $filtre,
                'mode'     => 'contains',
                'label'    => $filtre,
            ];
            $filtres[] = sprintf('texte « %s »', $filtre);
        }

        // Filtres rapides des rubriques : MÊMES sources uniques que rechercher_entites,
        // donc mêmes bornes, mêmes libellés et mêmes chips actifs à l'écran.
        $axesTranche = $shortName === 'Tranche'
            ? TranchePaiementScope::normaliserAxes(is_array($args['axes'] ?? null) ? $args['axes'] : [])
            : [];
        $criteresRubrique = AvenantEcheanceScope::critereRecherche($shortName, $args['echeance'] ?? null)
            + TranchePaiementScope::critereRecherche($shortName, $axesTranche)
            + CotationSouscriptionScope::critereRecherche($shortName, $args['validation'] ?? null)
            + PisteTransformationScope::critereRecherche($shortName, $args['transformation'] ?? null)
            + ReversementScope::criteresDepuisArguments($shortName, $args);

        // LE BÉNÉFICIAIRE : une RELATION, donc un critère d'identité — la même forme que
        // celle du chip-sélecteur de l'écran et du bouton du rapport de production.
        // `lieA` ne pouvait pas servir : il n'accepte que les entités du lexique, et un
        // agent n'en est pas une (les invités sont gouvernés à part, délibérément).
        $beneficiaire = trim((string) ($args['beneficiaire'] ?? ''));
        if ($shortName === ReversementScope::ENTITE && $beneficiaire !== '') {
            $agent = $this->agentNomme($beneficiaire, $scope);
            if ($agent === null) {
                // On n'ouvre SURTOUT pas la liste entière en lot de consolation : ce serait
                // annoncer les versements d'une personne et en montrer ceux de tout le monde.
                return AiToolResult::introuvable(
                    'Agent « ' . $beneficiaire . ' »',
                    'Aucun agent de cet espace ne porte ce nom. La rubrique n\'a PAS été ouverte.',
                );
            }
            $criteresRubrique += ReversementScope::critereBeneficiaire((int) $agent->getId(), (string) $agent->getNom());
            $filtres[] = sprintf('bénéficiaire « %s »', $agent->getNom());
        }

        if (isset($criteresRubrique[AvenantEcheanceScope::CRITERION_KEY])) {
            $filtres[] = AvenantEcheanceScope::libelle((string) $criteresRubrique[AvenantEcheanceScope::CRITERION_KEY]['value']);
        } elseif ($axesTranche !== []) {
            $filtres[] = TranchePaiementScope::libelleCombinaison($axesTranche);
        } elseif (isset($criteresRubrique[CotationSouscriptionScope::CRITERION_KEY])) {
            $filtres[] = CotationSouscriptionScope::libelle((string) $criteresRubrique[CotationSouscriptionScope::CRITERION_KEY]['value']);
        } elseif (isset($criteresRubrique[PisteTransformationScope::CRITERION_KEY])) {
            $filtres[] = PisteTransformationScope::libelle((string) $criteresRubrique[PisteTransformationScope::CRITERION_KEY]['value']);
        }
        foreach ([ReversementScope::CLE_JUSTIFICATIF, ReversementScope::CLE_PERIODE, ReversementScope::CLE_VIREMENT, ReversementScope::CLE_TYPE] as $cleReversement) {
            if (isset($criteresRubrique[$cleReversement])) {
                $filtres[] = ReversementScope::libelle($cleReversement, (string) $criteresRubrique[$cleReversement]['value']);
            }
        }
        $criteres += $criteresRubrique;

        return AiToolResult::ok(
            array_filter([
                'entite'  => $shortName,
                'libelle' => $labels[$shortName],
                'filtres' => $filtres !== [] ? $filtres : null,
                // DIS CE QUI EST À L'ÉCRAN, pas ce que tu espères y voir. Le modèle a
                // annoncé « la rubrique a été ouverte » sur une liste qui ne portait pas
                // son filtre : la note nomme désormais le filtre réellement posé.
                'note'    => $filtres === []
                    ? 'La rubrique s\'ouvre ENTIÈRE dans l\'espace de travail : aucun filtre n\'a été '
                        . 'transmis. Si ta réponse écrite ne porte que sur une partie des '
                        . 'enregistrements, ne dis PAS que la liste affichée est celle-là.'
                    : 'La rubrique s\'ouvre FILTRÉE sur : ' . implode(' · ', $filtres)
                        . '. Annonce ce filtre en une demi-phrase, pour que l\'utilisateur '
                        . 'sache pourquoi l\'écran ne montre pas tout.',
            ], static fn ($v) => $v !== null),
            uiAction: array_filter([
                'type'     => TypeAction::OUVRIR_RUBRIQUE->value,
                'entite'   => $shortName,
                'criteres' => $criteres !== [] ? $criteres : null,
            ], static fn ($v) => $v !== null),
        );
    }

    /**
     * L'agent NOMMÉ, cherché dans l'espace de travail — exact d'abord, partiel ensuite.
     *
     * Même règle que `retrocommissions`, qui résout déjà un bénéficiaire par son nom :
     * « SUNU » ne doit pas être écrasé par « SUNU IARD RDC ». Un invité n'est pas une
     * rubrique — il n'a donc ni lexique ni résolveur générique, et c'est pour cela que
     * cette petite recherche vit ici.
     */
    private function agentNomme(string $terme, AiScope $scope): ?Invite
    {
        if (ctype_digit($terme)) {
            return $this->inviteRepository->findOneBy(['id' => (int) $terme, 'entreprise' => $scope->entreprise]);
        }

        $candidats = $this->inviteRepository->createQueryBuilder('i')
            ->andWhere('i.entreprise = :e')->setParameter('e', $scope->entreprise)
            ->andWhere('LOWER(i.nom) LIKE :t')->setParameter('t', '%' . mb_strtolower($terme) . '%')
            ->orderBy('i.nom', 'ASC')
            ->getQuery()->getResult();

        foreach ($candidats as $candidat) {
            if (mb_strtolower((string) $candidat->getNom()) === mb_strtolower($terme)) {
                return $candidat;
            }
        }

        return $candidats[0] ?? null;
    }

    /**
     * Traduit un rattachement dicté (« les pistes de Mme Marlette ») en critère de
     * liste. Deux résolutions successives, toutes deux déléguées aux sources uniques :
     * le NOM devient un identifiant (ResolveurDeReferences), puis le CHEMIN de
     * relations qui mène de la rubrique à cette fiche est trouvé dans le graphe
     * (CheminsDeRelation).
     *
     * UN SEUL CHEMIN, LE PLUS COURT — et c'est une différence assumée avec
     * rechercher_entites, qui les combine tous en OR. Les critères d'une liste à
     * l'écran se cumulent en ET : y poser deux chemins ne ramènerait rien du tout.
     *
     * @param array<string, mixed>  $lieA
     * @param array<string, mixed>  $criteres (par référence)
     * @param array<int, string>    $filtres  (par référence)
     *
     * @return AiToolResult|null non-null = on rend la main sans rien ouvrir
     */
    private function critereDeRattachement(
        string $shortName,
        array $lieA,
        AiScope $scope,
        array &$criteres,
        array &$filtres,
    ): ?AiToolResult {
        $labels = $this->accessResolver->libellesEntites();
        $cible = (string) ($lieA['entite'] ?? '');
        $cibleFqcn = 'App\\Entity\\' . $cible;
        if (!isset($labels[$cible]) || !class_exists($cibleFqcn)) {
            return AiToolResult::introuvable($cible);
        }
        // FAIL-CLOSED : on ne filtre pas sur une fiche que l'invité n'a pas le droit de lire.
        if (!$this->accessResolver->canRead($scope->invite, $cible)) {
            return AiToolResult::horsPerimetre($labels[$cible]);
        }

        $id = (int) ($lieA['id'] ?? 0);
        $libelleCible = '#' . $id;
        if ($id <= 0) {
            $nom = trim((string) ($lieA['nom'] ?? ''));
            if ($nom === '') {
                return AiToolResult::introuvable($labels[$cible]);
            }
            $reference = $this->resolveur->resoudre($cible, $nom, $scope);
            if (!$reference->estResolue()) {
                // Introuvable ou ambigu : une QUESTION. On n'ouvre SURTOUT pas la rubrique
                // entière en lot de consolation — c'est précisément la contradiction qu'on
                // corrige ici.
                return AiToolResult::ok([
                    'pret'      => false,
                    'aDemander' => [$reference->question()],
                    'note'      => 'La rubrique n’a PAS été ouverte : le rattachement demandé ne se '
                        . 'résout pas en un enregistrement unique. Pose la question telle quelle, en '
                        . 'UNE ligne, en PROPOSANT les « valeurs » quand il y en a. N’ouvre pas la '
                        . 'liste entière à la place.',
                ]);
            }
            $id = (int) $reference->id;
            $libelleCible = (string) $reference->libelle;
        }

        $chemin = $this->chemins->pluCourtVers('App\\Entity\\' . $shortName, $cibleFqcn);
        if ($chemin === null) {
            // Aucune relation ne relie les deux : mieux vaut le DIRE que d'ouvrir une
            // liste non filtrée en laissant croire qu'elle l'est.
            return AiToolResult::ok([
                'pret'      => false,
                'aDemander' => [[
                    'champ'    => $cible,
                    'libelle'  => $labels[$cible],
                    'question' => sprintf(
                        'Les « %s » ne sont rattachés à aucun « %s » : sur quoi voulez-vous filtrer cette liste ?',
                        $labels[$shortName],
                        mb_strtolower($labels[$cible]),
                    ),
                ]],
                'note'      => 'La rubrique n’a PAS été ouverte : le filtre demandé n’existe pas sur '
                    . 'cette rubrique. Ne l’ouvre pas sans filtre en prétendant le contraire.',
            ]);
        }

        // Filtrage par IDENTITÉ : opérateur d'égalité sans targetField — le moteur de
        // recherche joint le chemin et compare l'id (JSBDynamicSearchService, sous-cas 2.1).
        $criteres[$chemin] = ['operator' => '=', 'value' => $id, 'label' => $libelleCible];
        $filtres[] = sprintf('%s « %s »', mb_strtolower($labels[$cible]), $libelleCible);

        return null;
    }
}
