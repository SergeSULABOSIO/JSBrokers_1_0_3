<?php

namespace App\Ai\Tool;

use App\Ai\Mutation\MutationPlan;
use App\Ai\Mutation\PlanBuilder;
use App\Ai\Resolution\ResolveurDeReferences;
use App\Ai\Scope\AiScope;
use App\Ai\Trousse\AiToolEcriture;
use App\Entity\Cotation;
use App\Entity\Invite;
use App\Service\Workspace\WorkspaceAccessResolver;
use App\Services\JSBDynamicSearchService;

/**
 * LE CLIENT A DIT OUI : la proposition devient une POLICE, en un seul appel.
 *
 * POURQUOI CET OUTIL EXISTE. Le 2026-08-10, l'utilisateur écrit : « Elle vient de
 * confirmer son accord pour la proposition de SUNU dans la piste concernant son
 * magasin d'habillement ». Il n'existait aucun chemin pour cela. Ket est donc partie
 * chercher — cinq tours de rechercher_entites, 188 000 tokens, la fenêtre d'une
 * minute vidée, et la conversation entière bloquée derrière. Rien n'a été enregistré.
 *
 * C'est pourtant l'acte le plus banal du métier, et le pivot de toute la chaîne de
 * valeur : sans avenant, la cotation reste un PROJET — ses primes et ses commissions
 * ne comptent nulle part (règle isBound). C'est l'avenant qui rend la prime exigible,
 * puis la commission, puis la facturation. Tout en dépend.
 *
 * CE QUE LE SERVEUR FAIT SEUL : retrouver la cotation par ce que l'utilisateur en dit
 * (l'assureur, le client, la piste), en déduire les dates depuis la durée déjà saisie,
 * reprendre la référence de police, et rendre le plan. Le modèle n'a plus qu'à
 * comprendre la phrase.
 *
 * CE QU'IL N'INVENTE JAMAIS : si plusieurs cotations correspondent — le cas NORMAL,
 * puisqu'une piste porte des propositions concurrentes —, il rend la main avec la
 * liste. Choisir à la place du courtier reviendrait à attribuer le marché au mauvais
 * assureur, et le contrat serait faux.
 */
final class SouscrireCotationTool implements AiToolProduisantUnPlan, AiToolEcriture
{
    /** Étape unique, nommée comme dans la trame « proposition » du catalogue. */
    private const ETAPE = 'Le contrat (avenant)';

    public function __construct(
        private readonly PlanBuilder $planBuilder,
        private readonly WorkspaceAccessResolver $accessResolver,
        private readonly ResolveurDeReferences $resolveur,
        private readonly JSBDynamicSearchService $searchService,
    ) {
    }

    public function name(): string
    {
        return 'souscrire_cotation';
    }

    public function description(): string
    {
        return 'TRANSFORMER UNE PROPOSITION ACCEPTÉE EN POLICE (créer l\'avenant), en un seul appel. '
            . 'À utiliser dès que le client a donné son accord : « elle a confirmé son accord pour la '
            . 'proposition de SUNU », « le client a validé l\'offre », « c\'est la couverture de X qu\'il '
            . 'faut valider », « la cotation est acceptée ». Tu donnes ce que l\'utilisateur a DIT — le '
            . 'nom de l\'assureur, celui du client ou de la piste — et LE SERVEUR retrouve la cotation, '
            . 'en déduit les dates depuis sa durée, reprend la référence de police, puis rend un PLAN à '
            . 'valider. N\'APPELLE NI rechercher_entites NI lire_fiche avant lui : il n\'y a aucun '
            . 'identifiant à trouver. Si plusieurs propositions correspondent (le cas normal d\'une piste '
            . 'à plusieurs assureurs), il te rend la liste : demande LAQUELLE, en une ligne. '
            . 'Pour faire évoluer une police DÉJÀ existante (renouveler, proroger, annuler, résilier), '
            . 'c\'est preparer_mouvement_avenant.';
    }

    public function aiguillage(): string
    {
        return 'Le client a ACCEPTÉ une proposition et il faut la concrétiser en police : « elle a '
            . 'confirmé son accord », « il a validé l\'offre de X », « la cotation est acceptée », '
            . '« c\'est la couverture de X qui est à valider ». Appelle-moi DIRECTEMENT, sans aucune '
            . 'recherche préalable : je retrouve la proposition par l\'assureur, le client ou la piste, '
            . 'et je rends le plan de création du contrat en un seul appel.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'cotation' => [
                    'type' => 'string',
                    'description' => 'La proposition acceptée : son nom, ou son identifiant si tu l\'as '
                        . 'déjà. Laisse vide si l\'utilisateur l\'a désignée autrement (par l\'assureur, '
                        . 'le client ou la piste) — renseigne alors les champs correspondants.',
                ],
                'assureur' => [
                    'type' => 'string',
                    'description' => 'NOM de l\'assureur dont la proposition est acceptée (ex. « SUNU »), '
                        . 'tel que dicté. Sert à retrouver la bonne proposition parmi les concurrentes.',
                ],
                'client' => [
                    'type' => 'string',
                    'description' => 'NOM du client qui a donné son accord, tel que dicté.',
                ],
                'piste' => [
                    'type' => 'string',
                    'description' => 'Nom de l\'opportunité (piste) portant la proposition, si l\'utilisateur '
                        . 'la désigne ainsi (ex. « la piste du magasin d\'habillement »).',
                ],
                'referencePolice' => [
                    'type' => 'string',
                    'description' => 'Référence de police communiquée par l\'assureur, si l\'utilisateur '
                        . 'la donne. Sinon, ne renseigne rien : elle sera à compléter plus tard.',
                ],
                'numero' => [
                    'type' => 'string',
                    'description' => 'Numéro d\'avenant, si dicté. Par défaut « 0 » — la police d\'origine.',
                ],
                'dateEffet' => [
                    'type' => 'string',
                    'description' => 'Date de prise d\'effet de la couverture (AAAA-MM-JJ), si l\'utilisateur '
                        . 'la donne. Par défaut : aujourd\'hui.',
                ],
                'remplacerPlanEnAttente' => [
                    'type' => 'boolean',
                    'description' => 'true seulement si un plan attend déjà une décision ET que l\'utilisateur '
                        . 'demande de le CHANGER.',
                ],
            ],
        ];
    }

    public function match(string $question, AiScope $scope): ?array
    {
        return null;
    }

    public function execute(array $args, AiScope $scope): AiToolResult
    {
        // FAIL-CLOSED : créer un contrat suppose le droit d'écriture sur les Avenants.
        if (!$this->accessResolver->can($scope->invite, 'Avenant', Invite::ACCESS_ECRITURE)) {
            return AiToolResult::horsPerimetre($this->accessResolver->libellesEntites()['Avenant'] ?? 'Contrats');
        }

        $refus = $this->planBuilder->refusSiPlanEnAttente(
            $scope,
            ($args['remplacerPlanEnAttente'] ?? false) === true,
            $this->name(),
        );
        if ($refus !== null) {
            return $refus;
        }

        [$cotation, $question] = $this->trouverLaCotation($args, $scope);
        if ($cotation === null) {
            return $question;
        }

        $defauts = [];
        $debut = $this->dateEffet($args, $defauts);
        $duree = (int) $cotation->getDuree();
        $fin = $duree > 0 ? $debut->modify(sprintf('+%d month', $duree))->modify('-1 day') : null;
        if ($fin !== null) {
            $defauts[] = sprintf(
                'Période de couverture : du %s au %s (durée de %d mois portée par la proposition).',
                $debut->format('d/m/Y'),
                $fin->format('d/m/Y'),
                $duree,
            );
        }

        $numero = trim((string) ($args['numero'] ?? ''));
        if ($numero === '') {
            $numero = '0';
            $defauts[] = 'Numéro d’avenant : 0 (la police d’origine).';
        }

        $champs = array_filter([
            'cotation'        => $cotation->getId(),
            'startingAt'      => $debut->format('Y-m-d\TH:i'),
            'endingAt'        => $fin?->format('Y-m-d\TH:i'),
            'numero'          => $numero,
            'referencePolice' => trim((string) ($args['referencePolice'] ?? '')) ?: null,
            'description'     => sprintf('Souscription de la proposition « %s ».', (string) $cotation->getNom()),
        ], static fn ($v) => $v !== null && $v !== '');

        $resultat = $this->planBuilder->construire(
            MutationPlan::fromArray([[
                'op'     => 'create',
                'entite' => 'Avenant',
                'etape'  => self::ETAPE,
                'champs' => $champs,
            ]]),
            $scope,
            $this->name(),
        );

        if (($resultat->data['pret'] ?? false) !== true) {
            return $resultat;
        }

        return AiToolResult::ok(
            $resultat->data + [
                'resolutions' => [sprintf('Proposition souscrite : « %s ».', (string) $cotation->getNom())],
                'defauts'     => $defauts,
                'noteDefauts' => 'ANNONCE ces « resolutions » et ces « defauts » sous le plan : la période et le '
                    . 'numéro d’avenant ont été DÉDUITS, l’utilisateur doit pouvoir les corriger d’une phrase. '
                    . 'Rappelle aussi que c’est cette souscription qui rend la prime exigible, puis la '
                    . 'commission — et que les autres propositions de la même piste deviennent caduques.',
            ],
            uiAction: $resultat->uiAction,
        );
    }

    /**
     * Retrouve la proposition visée à partir de ce que l'utilisateur en a dit.
     *
     * Trois désignations, de la plus précise à la plus indirecte : son nom, son
     * assureur, son client ou sa piste. Plusieurs candidates = on demande, jamais on
     * ne choisit : une piste porte des propositions CONCURRENTES, et se tromper
     * reviendrait à attribuer le marché au mauvais assureur.
     *
     * @return array{0: ?Cotation, 1: AiToolResult}
     */
    private function trouverLaCotation(array $args, AiScope $scope): array
    {
        $nom = trim((string) ($args['cotation'] ?? ''));
        if ($nom !== '') {
            $reference = $this->resolveur->resoudre('Cotation', $nom, $scope);
            if ($reference->estResolue()) {
                $trouvee = $this->chargerCotation($reference->id, $scope);
                if ($trouvee !== null) {
                    return [$trouvee, $this->demander([])];
                }
            }

            return [null, $this->demander([$reference->question()])];
        }

        // Désignation indirecte : on cherche les cotations liées à ce que
        // l'utilisateur a nommé (l'assureur, le client, la piste).
        $criteres = [];
        $questions = [];
        $pistes = null;

        foreach (['assureur' => 'Assureur', 'piste' => 'Piste'] as $champ => $entite) {
            $terme = trim((string) ($args[$champ] ?? ''));
            if ($terme === '') {
                continue;
            }
            $reference = $this->resolveur->resoudre($entite, $terme, $scope);
            if (!$reference->estResolue()) {
                $questions[] = $reference->question();
                continue;
            }
            if ($champ === 'piste') {
                $pistes = [$reference->id];
                continue;
            }
            $criteres[$champ] = $reference->id;
        }

        // LE CLIENT NE PORTE PAS LA COTATION : c'est la piste qui les relie. On passe
        // donc par ses opportunités — un critère « piste.client » n'existe pas, et
        // l'écrire renvoyait silencieusement zéro résultat.
        $client = trim((string) ($args['client'] ?? ''));
        if ($client !== '' && $pistes === null) {
            $reference = $this->resolveur->resoudre('Client', $client, $scope);
            if (!$reference->estResolue()) {
                $questions[] = $reference->question();
            } else {
                $pistes = array_keys($this->resolveur->chercherLies('Piste', 'client', (int) $reference->id, $scope));
            }
        }

        if ($questions !== []) {
            return [null, $this->demander($questions)];
        }
        if ($criteres === [] && $pistes === null) {
            return [null, $this->demander([[
                'champ'    => 'Cotation',
                'libelle'  => 'Proposition',
                'probleme' => 'absent',
            ]])];
        }

        $candidates = [];
        foreach ($pistes ?? [null] as $piste) {
            $filtre = $criteres + ($piste === null ? [] : ['piste' => $piste]);
            $resultat = $this->searchService->search(Cotation::class, $filtre, $scope->entreprise, null, 1, 10);
            if (($resultat['status']['code'] ?? 500) === 200) {
                foreach ($resultat['data'] ?? [] as $trouvee) {
                    $candidates[(int) $trouvee->getId()] = $trouvee;
                }
            }
        }
        $candidates = array_values($candidates);

        // Une proposition DÉJÀ souscrite n'est plus à souscrire : la retenir
        // créerait un second contrat sur le même marché.
        $aSouscrire = [];
        foreach ($candidates as $candidate) {
            if ($candidate instanceof Cotation && count($candidate->getAvenants()) === 0) {
                $aSouscrire[] = $candidate;
            }
        }

        if ($aSouscrire === []) {
            return [null, $this->demander([[
                'champ'    => 'Cotation',
                'libelle'  => 'Proposition',
                'probleme' => $candidates === [] ? 'introuvable' : 'deja_souscrite',
            ]])];
        }
        if (count($aSouscrire) > 1) {
            $valeurs = [];
            foreach ($aSouscrire as $candidate) {
                $valeurs[(int) $candidate->getId()] = (string) $candidate->getNom();
            }

            return [null, $this->demander([[
                'champ'    => 'Cotation',
                'libelle'  => 'Proposition',
                'probleme' => 'ambigu',
                'valeurs'  => $valeurs,
            ]])];
        }

        return [$aSouscrire[0], $this->demander([])];
    }

    private function chargerCotation(?int $id, AiScope $scope): ?Cotation
    {
        if ($id === null) {
            return null;
        }
        $resultat = $this->searchService->search(Cotation::class, ['id' => $id], $scope->entreprise, null, 1, 1);
        $trouvee = $resultat['data'][0] ?? null;

        return $trouvee instanceof Cotation ? $trouvee : null;
    }

    /** Date d'effet dictée, ou celle du jour (défaut annoncé). */
    private function dateEffet(array $args, array &$defauts): \DateTimeImmutable
    {
        $donnee = trim((string) ($args['dateEffet'] ?? ''));
        if ($donnee !== '') {
            try {
                return new \DateTimeImmutable($donnee);
            } catch (\Exception) {
                // Date illisible : le jour même, et le défaut est annoncé comme les autres.
            }
        }

        $aujourdhui = new \DateTimeImmutable('today');
        $defauts[] = sprintf('Prise d’effet : %s (aucune date dictée).', $aujourdhui->format('d/m/Y'));

        return $aujourdhui;
    }

    /** @param array<int, array<string, mixed>> $questions */
    private function demander(array $questions): AiToolResult
    {
        return AiToolResult::ok([
            'pret'      => false,
            'aDemander' => $questions,
            'note'      => 'Je n’ai pas identifié la proposition avec certitude, et je ne devine jamais : '
                . 'une piste porte des propositions CONCURRENTES, et me tromper attribuerait le marché au '
                . 'mauvais assureur. Pose la question en UNE ligne — en PROPOSANT les options de « valeurs » '
                . 'quand elles sont fournies — puis rappelle souscrire_cotation. N’appelle pas '
                . 'rechercher_entites : les candidates sont déjà ci-dessus.',
        ]);
    }
}
