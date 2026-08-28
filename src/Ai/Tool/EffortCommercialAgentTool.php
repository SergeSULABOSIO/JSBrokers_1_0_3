<?php

namespace App\Ai\Tool;

use App\Ai\Resolution\Reference;
use App\Ai\Resolution\ResolveurDeReferences;
use App\Ai\Scope\AiScope;
use App\Ai\Trousse\AiToolEcriture;
use App\Entity\ConditionPartage;
use App\Entity\Piste;
use App\Service\Partage\RattachementDuPartage;
use App\Service\Workspace\WorkspaceAccessResolver;
use Doctrine\ORM\EntityManagerInterface;

/**
 * « CETTE AFFAIRE VIENT DE L'EFFORT D'ALICE » — le rattachement d'une condition d'agent,
 * dit à l'assistant.
 *
 * ── POURQUOI CET OUTIL EXISTE ───────────────────────────────────────────────────────
 * L'écran sait le faire depuis n'importe où dans l'arbre d'une affaire, et en lot. Si
 * l'assistant ne le savait pas, « demande-le à Ket » deviendrait le geste qu'on ne fait
 * pas — exactement ce que le déplacement du point d'entrée cherchait à corriger. Et si
 * l'assistant le savait SANS les refus de l'écran, il en deviendrait le contournement.
 *
 * ── AUCUNE LOGIQUE D'ÉCRITURE PROPRE ────────────────────────────────────────────────
 * Il TRADUIT en opérations `edit` sur la PISTE — la relation y est côté propriétaire, donc
 * posable par simple liste d'identifiants — et DÉLÈGUE à preparer_operations, donc au même
 * WorkspaceMutationService : validation, budget, verrou « un seul plan en attente »,
 * exécution transactionnelle, journal. Même pattern que SignalerReversementRetroAgentTool.
 *
 * ── LES MÊMES REFUS QUE L'ÉCRAN, ET AVANT LE PLAN ───────────────────────────────────
 * `RattachementDuPartage` rend les motifs ; on les consulte AVANT de préparer quoi que ce
 * soit. Préparer puis refuser à l'exécution ferait valider à l'utilisateur une écriture qui
 * n'aurait jamais lieu.
 *
 * ⚠ Cet outil est la porte NOMMÉE, pas la seule : le chemin générique
 * (`preparer_operations` sur Piste.conditionsPartageAgent) est gardé au niveau du moteur,
 * sans quoi la règle se contournerait d'un autre outil.
 */
final class EffortCommercialAgentTool implements AiToolProduisantUnPlan, AiToolConditionnel, AiToolEcriture
{
    /** Les entités depuis lesquelles on peut désigner une affaire — celles de son arbre. */
    private const CIBLES = ['Piste', 'Cotation', 'Avenant', 'Tranche'];

    public function __construct(
        private readonly PreparerOperationsTool $preparer,
        private readonly ResolveurDeReferences $resolveur,
        private readonly RattachementDuPartage $rattachement,
        private readonly WorkspaceAccessResolver $accessResolver,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function name(): string
    {
        return 'effort_commercial_agent';
    }

    public function description(): string
    {
        return "Rattache une CONDITION DE PARTAGE à une ou PLUSIEURS affaires — ou défait ce "
            . "rattachement. C'est lui qui rend une rétrocommission due : sans lui, l'affaire est "
            . "réputée gagnée par le cabinet seul et personne ne touche rien. "
            . "LES DEUX FAMILLES passent par ici : une condition d'AGENT interne (« ces affaires "
            . "viennent de l'effort d'Alice ») comme une condition de PARTENAIRE externe (« ces "
            . "affaires relèvent de l'accord SUNU 20 % »). Tu ne choisis JAMAIS une famille : tu "
            . "donnes une condition, et elle porte déjà la sienne. "
            . "Donne `action` (rattacher / detacher), les `cibles` (l'affaire elle-même OU n'importe "
            . "quel objet de son arbre : une police, une proposition, une tranche — le serveur remonte "
            . "à l'affaire) et la `condition` de partage par son NOM. "
            . "PLUSIEURS cibles à la fois sont permises, et c'est TOUT OU RIEN : si une seule affaire "
            . "refuse, rien n'est écrit. "
            . "Une affaire n'a qu'UN bénéficiaire PAR FAMILLE : un apporteur externe ET un agent "
            . "interne peuvent donc y coexister — c'est la mécanique normale, le partenaire se sert "
            . "d'abord et l'agent partage le reliquat. Ce qui est refusé, c'est un SECOND bénéficiaire "
            . "du même camp ; pour en changer, il faut détacher d'abord. "
            . "Et dès qu'une rétrocommission a été VERSÉE, plus rien ne peut être détaché ni changé, "
            . "quelle que soit la famille. "
            . "POUR UN PARTENAIRE, une règle de plus : si l'affaire ne désigne aucun apporteur, le "
            . "rattachement le DÉSIGNE du même geste — annonce-le, cela change à qui revient l'argent ; "
            . "si elle en désigne déjà un AUTRE, le geste est refusé en nommant les deux. "
            . "Prépare un PLAN à valider ; n'écrit rien. Pour consulter ce qui est dû ou déjà versé, "
            . "utiliser retrocommissions.";
    }

    public function aiguillage(): string
    {
        return 'RATTACHER une condition de partage — d\'agent interne OU de partenaire externe — à une '
            . 'ou plusieurs affaires, depuis n\'importe quel objet de leur arbre (« cette police vient '
            . 'd\'Alice », « ces trois affaires sont de Bruno », « ces affaires relèvent de l\'accord '
            . 'SUNU »), ou DÉTACHER celle en place. Ne verse rien et ne calcule aucun montant.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'enum' => ['rattacher', 'detacher'],
                    'description' => 'Rattacher une condition d\'agent, ou détacher celle en place.',
                ],
                'condition' => [
                    'type' => 'string',
                    'description' => 'La condition de partage, par son NOM (ou son identifiant). '
                        . 'Obligatoire pour rattacher, inutile pour détacher. Elle peut désigner un '
                        . 'AGENT interne comme un PARTENAIRE externe : c\'est ELLE qui porte la '
                        . 'famille, et le serveur en déduit la place qu\'elle occupe.',
                ],
                'cibles' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'description' => 'Les objets visés. Chacun peut être l\'affaire elle-même ou '
                        . 'n\'importe quel objet de son arbre : le serveur remonte à l\'affaire et '
                        . 'dédoublonne (deux polices d\'une même affaire ne comptent que pour une).',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'entite' => [
                                'type' => 'string',
                                'enum' => self::CIBLES,
                                'description' => 'Le type de l\'objet visé.',
                            ],
                            'reference' => [
                                'type' => 'string',
                                'description' => 'Son nom, son numéro de police, ou son identifiant.',
                            ],
                        ],
                        'required' => ['entite', 'reference'],
                    ],
                ],
                'remplacerPlanEnAttente' => [
                    'type' => 'boolean',
                    'description' => 'Ne mets true QUE si un plan attend déjà une décision ET que '
                        . 'l\'utilisateur demande de le CHANGER.',
                ],
            ],
            'required' => ['action', 'cibles'],
        ];
    }

    /** Chemin simulé : « rattache la condition 3 à l'avenant 7 ». */
    public function match(string $question, AiScope $scope): ?array
    {
        $normalized = mb_strtolower($question, 'UTF-8');

        if (!preg_match('/\b(rattache[rz]?|detache[rz]?|détache[rz]?)\b/u', $normalized, $verbe)) {
            return null;
        }
        if (!preg_match('/\b(avenant|police|piste|affaire|cotation|proposition|tranche)\s*#?(\d+)\b/u', $normalized, $cible)) {
            return null;
        }

        $entite = match ($cible[1]) {
            'avenant', 'police' => 'Avenant',
            'cotation', 'proposition' => 'Cotation',
            'tranche' => 'Tranche',
            default => 'Piste',
        };
        $detache = str_starts_with($verbe[1], 'd');

        $args = [
            'action' => $detache ? 'detacher' : 'rattacher',
            'cibles' => [['entite' => $entite, 'reference' => $cible[2]]],
        ];
        if (!$detache && preg_match('/\bcondition\s*#?(\d+)\b/u', $normalized, $c)) {
            $args['condition'] = $c[1];
        }

        return $args;
    }

    /** Miroir de la garde d'execute() : ne pas décrire un outil qui refusera. */
    public function estDisponible(AiScope $scope): bool
    {
        return $this->accessResolver->can($scope->invite, 'Piste', \App\Entity\Invite::ACCESS_ECRITURE);
    }

    public function execute(array $args, AiScope $scope): AiToolResult
    {
        // Le rattachement s'écrit sur la PISTE : c'est ce droit-là qui gouverne, quel que
        // soit l'objet depuis lequel on désigne l'affaire.
        if (!$this->accessResolver->can($scope->invite, 'Piste', \App\Entity\Invite::ACCESS_ECRITURE)) {
            return AiToolResult::horsPerimetre('Rattachement des conditions de partage');
        }

        $action = (string) ($args['action'] ?? '');
        if (!in_array($action, ['rattacher', 'detacher'], true)) {
            return AiToolResult::introuvable('Action', 'Écris « rattacher » ou « detacher ».');
        }

        $pistes = $this->pistesDesCibles($args['cibles'] ?? [], $scope, $refusResolution);
        if ($refusResolution !== null) {
            return $refusResolution;
        }
        if ($pistes === []) {
            return AiToolResult::introuvable(
                'Affaires visées',
                'Aucune des cibles ne remonte à une affaire de cet espace de travail.',
            );
        }

        return $action === 'rattacher'
            ? $this->rattacher($args, $pistes, $scope)
            : $this->detacher($pistes, $scope);
    }

    /**
     * @param Piste[] $pistes
     */
    private function rattacher(array $args, array $pistes, AiScope $scope): AiToolResult
    {
        $condition = $this->conditionDictee($args, $scope, $refus);
        if ($refus !== null) {
            return $refus;
        }

        // LES MÊMES REFUS QUE L'ÉCRAN, ET AVANT LE PLAN.
        $motif = $this->rattachement->refusDuLot($pistes, $condition);
        if ($motif !== null) {
            return AiToolResult::ok([
                'pret'     => false,
                'bloquant' => $motif,
                'note'     => 'Dis-le en UNE phrase, et propose de détacher d\'abord ou de retirer '
                    . 'l\'affaire de la sélection. Ne présente AUCUN plan et n\'annonce AUCUN bouton.',
            ]);
        }

        $estAgent = $condition->estPourAgent();
        $beneficiaire = $estAgent ? $condition->getAgent() : $condition->getPartenaire();
        $nom = $beneficiaire?->getNom() ?: ($estAgent ? 'l\'agent' : 'l\'intermédiaire');

        $operations = [];
        $designations = [];
        foreach ($pistes as $piste) {
            // On CONSERVE ce qui est déjà rattaché et l'on ajoute : une affaire peut
            // porter un apporteur ET un agent. Écraser la liste — ce que faisait ce code
            // quand une seule famille existait — aurait détaché l'autre en silence, sans
            // passer par le refus qui protège un versement déjà parti.
            $champs = ['conditionsPartageAgent' => $this->rattachementsApres($piste, $condition)];

            // LA DÉSIGNATION D'INTERMÉDIAIRE VOYAGE DANS LE MÊME PLAN. Une condition de
            // partenaire rattachée à une affaire sans apporteur n'aurait AUCUN effet : le
            // calcul ne retient que les conditions de l'intermédiaire désigné. Le geste la
            // pose donc — et l'annonce, parce qu'elle change qui touche l'argent.
            if (!$estAgent && $piste->getPartenaire() === null && $condition->getPartenaire() !== null) {
                $champs['partenaire'] = $condition->getPartenaire()->getId();
                $designations[] = $piste->getNom() ?: ('#' . $piste->getId());
            }

            $operations[] = [
                'op'     => 'edit',
                'entite' => 'Piste',
                'id'     => $piste->getId(),
                'champs' => $champs,
                'etape'  => sprintf(
                    '%s de %s — affaire « %s »',
                    $estAgent ? 'Effort commercial' : 'Apport',
                    $nom,
                    $piste->getNom() ?: ('#' . $piste->getId()),
                ),
            ];
        }

        return $this->preparerEtAnnoncer($operations, $args, $scope, sprintf(
            'Annonce AU FUTUR que %d affaire(s) reviendront à %s, et que sa rétrocommission deviendra '
            . 'due dès l\'encaissement de la commission.%s Rien n\'est écrit tant que l\'utilisateur '
            . 'n\'a pas cliqué sur « Valider et exécuter ».',
            count($pistes),
            $nom,
            // L'ÉCRITURE IMPLICITE SE DIT. Poser l'apporteur d'une affaire change qui
            // touche l'argent : la laisser découvrir serait le contraire d'un plan.
            $designations === [] ? '' : sprintf(
                ' DIS AUSSI que %s deviendra l\'intermédiaire de %s, qui n\'en avait aucun.',
                $nom,
                count($designations) > 1
                    ? count($designations) . ' de ces affaires'
                    : '« ' . $designations[0] . ' »',
            ),
        ));
    }

    /**
     * LA LISTE COMPLÈTE DU MANYTOMANY APRÈS RATTACHEMENT — l'existant, plus la nouvelle.
     *
     * Le FormType attend la liste entière : écrire la seule condition ajoutée revenait à
     * DÉTACHER toutes les autres. Tant qu'une affaire ne pouvait porter qu'un agent, cela
     * ne se voyait pas — le gating garantissait la place vide. Depuis qu'un apporteur et
     * un agent y coexistent, écraser aurait effacé l'autre famille en silence, et sans
     * passer par le refus qui protège un versement déjà parti.
     *
     * On ne pose que la collection PARTAGÉE : une condition propre à l'affaire
     * (`ConditionPartage::piste`) n'y figure pas et ne doit pas y être recopiée.
     *
     * @return array<int, int> identifiants, la nouvelle comprise
     */
    private function rattachementsApres(Piste $piste, ConditionPartage $ajoutee): array
    {
        $identifiants = [];
        foreach ($piste->getConditionsPartageAgent() as $existante) {
            $identifiants[] = (int) $existante->getId();
        }
        $identifiants[] = (int) $ajoutee->getId();

        return array_values(array_unique($identifiants));
    }

    /**
     * @param Piste[] $pistes
     */
    private function detacher(array $pistes, AiScope $scope): AiToolResult
    {
        $operations = [];
        foreach ($pistes as $piste) {
            // TOUT CE QUI EST RATTACHÉ EST JUGÉ. Une affaire peut porter un apporteur ET un
            // agent : ne contrôler qu'une famille laisserait le détachement effacer l'autre
            // sans passer par le refus qui protège un versement déjà parti.
            foreach ($this->rattachement->conditions($piste) as $rattachee) {
                $motif = $this->rattachement->refusDeDetachement($piste, $rattachee);
                if ($motif !== null) {
                    return AiToolResult::ok([
                        'pret'     => false,
                        'bloquant' => $motif,
                        'note'     => 'Dis-le en UNE phrase. Ne présente AUCUN plan et n\'annonce AUCUN bouton.',
                    ]);
                }
            }

            // Liste VIDE : le ManyToMany se défait comme il se pose, par la liste complète.
            $operations[] = [
                'op'     => 'edit',
                'entite' => 'Piste',
                'id'     => $piste->getId(),
                'champs' => ['conditionsPartageAgent' => null],
                'etape'  => sprintf(
                    'Retour au cabinet — affaire « %s »',
                    $piste->getNom() ?: ('#' . $piste->getId()),
                ),
            ];
        }

        return $this->preparerEtAnnoncer($operations, [], $scope,
            'Annonce AU FUTUR que ces affaires redeviendront un effort du cabinet seul, et qu\'aucune '
            . 'rétrocommission n\'y sera plus due. Termine en invitant à valider.');
    }

    /** @param array<int, array> $operations */
    private function preparerEtAnnoncer(array $operations, array $args, AiScope $scope, string $note): AiToolResult
    {
        $resultat = $this->preparer->execute([
            'operations' => $operations,
            'remplacerPlanEnAttente' => ($args['remplacerPlanEnAttente'] ?? false) === true,
        ], $scope);

        // LE REFUS DU MOTEUR PASSE AVANT TOUT, ET SEUL : ses refus sont des STATUS_OK
        // porteurs de « pret: false », et leur agrafer une consigne « présente le plan »
        // ferait rédiger un plan en prose sans bouton (plan fantôme).
        if ($resultat->status !== AiToolResult::STATUS_OK || ($resultat->data['pret'] ?? false) !== true) {
            return $resultat;
        }

        // ⚠ L'UNION `+` NE REMPLACE PAS UNE CLÉ EXISTANTE, et le plan en porte déjà une :
        // `note` y explique comment présenter le tableau, les impacts et le budget. La
        // consigne écrite ici — « annonce AU FUTUR que ces affaires reviendront à X » —
        // était donc SILENCIEUSEMENT jetée, et ne parvenait jamais au modèle. Même famille
        // de défaut que l'union qui écrasait `defauts` : rien ne lève, la phrase disparaît.
        //
        // Les deux consignes sont NÉCESSAIRES et ne se contredisent pas : l'une dit la
        // forme, l'autre le fond. On les enchaîne, celle du plan d'abord.
        $consigne = trim((string) ($resultat->data['note'] ?? ''));
        $donnees = $resultat->data;
        $donnees['note'] = $consigne === '' ? $note : $consigne . ' ' . $note;

        return AiToolResult::ok($donnees, $resultat->uiAction);
    }

    /**
     * Les AFFAIRES visées, dédoublonnées — quelle que soit la porte par laquelle on les
     * désigne.
     *
     * @return Piste[]
     */
    private function pistesDesCibles(array $cibles, AiScope $scope, ?AiToolResult &$refus): array
    {
        $refus = null;
        $pistes = [];

        foreach ((array) $cibles as $cible) {
            $entite = (string) ($cible['entite'] ?? '');
            if (!in_array($entite, self::CIBLES, true)) {
                $refus = AiToolResult::introuvable(
                    'Type « ' . $entite . ' »',
                    'Les objets acceptés sont : ' . implode(', ', self::CIBLES) . '.',
                );

                return [];
            }

            $reference = $this->resoudre($entite, (string) ($cible['reference'] ?? ''), $scope, $refus);
            if ($refus !== null) {
                return [];
            }

            $objet = $this->em->find('App\\Entity\\' . $entite, $reference->id);
            $piste = $this->rattachement->piste($objet);
            if ($piste !== null && $piste->getId() !== null
                && $piste->getEntreprise()?->getId() === $scope->entreprise?->getId()) {
                $pistes[$piste->getId()] = $piste;
            }
        }

        return array_values($pistes);
    }

    /** La condition dictée, à condition qu'elle existe ET qu'elle désigne un agent. */
    private function conditionDictee(array $args, AiScope $scope, ?AiToolResult &$refus): ?ConditionPartage
    {
        $refus = null;
        $terme = trim((string) ($args['condition'] ?? ''));
        if ($terme === '') {
            $refus = AiToolResult::introuvable(
                'Condition de partage',
                'Précise la condition à rattacher, par son nom — c\'est elle qui porte l\'agent et son taux.',
            );

            return null;
        }

        $reference = $this->resoudre('ConditionPartage', $terme, $scope, $refus);
        if ($refus !== null) {
            return null;
        }

        /** @var ConditionPartage|null $condition */
        $condition = $this->em->find(ConditionPartage::class, $reference->id);
        if ($condition === null || $condition->getEntreprise()?->getId() !== $scope->entreprise?->getId()) {
            $refus = AiToolResult::introuvable('Condition « ' . $terme . ' »');

            return null;
        }
        // AUCUNE GARDE DE FAMILLE ICI, et c'est le point du lot. Une condition de
        // PARTENAIRE était refusée — « un effort commercial d'agent se déclare avec une
        // condition qui désigne un agent » —, ce qui interdisait à Ket un geste que
        // l'écran ne proposait pas davantage. Les deux savent désormais le faire, et la
        // famille se LIT sur la condition : l'utilisateur choisit une condition, jamais
        // une famille. Les refus, eux, restent ceux de l'autorité partagée.
        return $condition;
    }

    /**
     * Résolution par NOM, avec la QUESTION déjà rédigée du résolveur.
     *
     * Introuvable ou ambigu, on ne devine pas : on rend `aDemander`, la forme que les
     * outils du projet emploient pour faire poser la question — avec l'aperçu du
     * référentiel ou la liste des candidats, selon le cas.
     */
    private function resoudre(string $entite, string $terme, AiScope $scope, ?AiToolResult &$refus): ?Reference
    {
        $reference = $this->resolveur->resoudre($entite, $terme, $scope);
        if ($reference->estResolue()) {
            return $reference;
        }

        $refus = AiToolResult::ok([
            'pret'      => false,
            'aDemander' => [$reference->question()],
            'note'      => 'Demande la précision manquante en UNE phrase. Ne présente AUCUN plan '
                . 'et n\'annonce AUCUN bouton.',
        ]);

        return null;
    }
}
