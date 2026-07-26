<?php

namespace App\Ai\Tool;

use App\Ai\AiText;
use App\Ai\Scope\AiScope;
use App\Entity\Client;
use App\Entity\Risque;
use App\Service\Saturation\SaturationService;
use App\Service\Workspace\WorkspaceAccessResolver;
use App\Services\JSBDynamicSearchService;
use App\Services\Search\PortefeuilleScope;

/**
 * Mesure la SATURATION (cross-selling) du portefeuille — la boussole du courtier :
 * chaque client doit souscrire TOUS les types de risques du catalogue. Répond au
 * niveau d'UN client nommé (taux de couverture + risques manquants) ou de
 * l'ensemble du PORTEFEUILLE de l'interlocuteur (taux moyen, clients non saturés,
 * opportunités de tête). S'appuie sur la source unique SaturationService (la même
 * qui alimente le cross-selling du SOA).
 *
 * FAIL-CLOSED : la couverture dérive des clients, risques et pistes — l'outil
 * exige la lecture des trois ; le périmètre par défaut est le portefeuille de
 * l'invité (comme la rubrique à l'écran), élargi à l'entreprise sur demande.
 */
final class SaturationPortefeuilleTool implements AiToolInterface
{
    /** Nombre maximal de candidats restitués sur un nom de client ambigu. */
    private const MAX_CANDIDATS = 6;

    public function __construct(
        private readonly WorkspaceAccessResolver $accessResolver,
        private readonly SaturationService $saturation,
        private readonly JSBDynamicSearchService $searchService,
        private readonly EntiteLibelle $libelleur,
    ) {
    }

    public function name(): string
    {
        return 'saturation_portefeuille';
    }

    public function description(): string
    {
        return 'Mesure la SATURATION / le cross-selling : taux de couverture (part des '
            . 'risques du catalogue effectivement souscrits) et risques MANQUANTS — pour un '
            . 'CLIENT nommé, ou pour l\'ensemble du PORTEFEUILLE de l\'interlocuteur (taux '
            . 'moyen, nombre de clients non saturés, opportunités de tête). À appeler pour : '
            . '« taux de couverture », « cross-selling », « quels risques n\'a pas le client X », '
            . '« opportunités de vente », « où en est la saturation de mon portefeuille ». '
            . 'Par défaut le portefeuille de l\'utilisateur (paramètre perimetre pour élargir).';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'client' => [
                    'type' => 'string',
                    'description' => "Nom (ou partie du nom) du client à analyser. Absent = tout le portefeuille.",
                ],
                'id' => [
                    'type' => 'integer',
                    'description' => "Identifiant du client (prioritaire sur « client »).",
                ],
                'perimetre' => PortefeuilleScope::proprieteSchema(),
            ],
            'required' => [],
        ];
    }

    public function match(string $question, AiScope $scope): ?array
    {
        $normalized = AiText::normalize($question);
        if (!preg_match(
            '/\b(saturation|cross[- ]?selling|taux de couverture|couverture (du|de|des) (client|portefeuille)|risques? manquants?|opportunites?)\b/',
            $normalized,
        )) {
            return null;
        }

        $args = [];
        if (preg_match('/\bclient\s+(.{2,60}?)(?:\s*\?|$)/', $normalized, $m)) {
            $args['client'] = trim($m[1]);
        }
        if (($p = PortefeuilleScope::detecterPerimetreDepuisTexte($normalized)) !== null) {
            $args['perimetre'] = $p;
        }

        return $args;
    }

    public function execute(array $args, AiScope $scope): AiToolResult
    {
        // FAIL-CLOSED : la saturation dérive des clients, risques et pistes.
        foreach (['Client', 'Risque', 'Piste'] as $entite) {
            if (!$this->accessResolver->canRead($scope->invite, $entite)) {
                return AiToolResult::horsPerimetre('Saturation du portefeuille');
            }
        }

        $id     = (int) ($args['id'] ?? 0);
        $nom    = trim((string) ($args['client'] ?? ''));

        // Cas 1 : couverture d'UN client nommé.
        if ($id > 0 || $nom !== '') {
            return $this->couvertureClient($id, $nom, $scope);
        }

        // Cas 2 : couverture agrégée du PORTEFEUILLE (invité par défaut, entreprise sur demande).
        $perimetreEntreprise = PortefeuilleScope::estEntreprise($args['perimetre'] ?? null);
        $agg = $this->saturation->couverturePortefeuille($scope->entreprise, $scope->invite, $perimetreEntreprise);

        return AiToolResult::ok([
            'niveau'               => 'portefeuille',
            'perimetre'            => $agg['perimetre'],
            'nbClients'            => $agg['nbClients'],
            'nbCatalogue'          => $agg['nbCatalogue'],
            'tauxMoyen'            => $agg['tauxMoyen'],
            'unite'                => '%',
            'nbClientsSatures'     => $agg['nbClientsSatures'],
            'nbClientsSousSatures' => $agg['nbClientsSousSatures'],
            'topOpportunites'      => $agg['topOpportunites'],
            'objectif'             => 'Saturation 100 % : viser tous les risques du catalogue chez chaque client.',
        ]);
    }

    private function couvertureClient(int $id, string $nom, AiScope $scope): AiToolResult
    {
        $displayField = $this->libelleur->displayField(Client::class);
        if ($id > 0) {
            $criteria = ['id' => $id];
        } elseif ($displayField !== null) {
            $criteria = [$displayField => ['operator' => 'LIKE', 'value' => $nom, 'mode' => 'contains']];
        } else {
            return AiToolResult::introuvable('Client');
        }

        $result   = $this->searchService->search(Client::class, $criteria, $scope->entreprise, null, 1, self::MAX_CANDIDATS);
        $entities = ($result['status']['code'] ?? 500) === 200 ? $result['data'] : [];
        if ($entities === []) {
            return AiToolResult::introuvable(sprintf('Client « %s »', $nom !== '' ? $nom : '#' . $id));
        }
        if (\count($entities) > 1) {
            return AiToolResult::ok([
                'entite'    => 'Client',
                'ambigu'    => true,
                'candidats' => array_map(
                    fn (object $e): array => ['id' => $e->getId(), 'libelle' => $this->libelleur->libelle($e, $displayField)],
                    $entities,
                ),
            ]);
        }

        /** @var Client $entity */
        $entity     = $entities[0];
        $couverture = $this->saturation->couvertureClient($entity, $scope->entreprise);

        return AiToolResult::ok([
            'niveau'           => 'client',
            'client'           => $this->libelleur->libelle($entity, $displayField),
            'nbCatalogue'      => $couverture['nbCatalogue'],
            'nbCouverts'       => $couverture['nbCouverts'],
            'tauxCouverture'   => $couverture['tauxCouverture'],
            'unite'            => '%',
            'risquesManquants' => array_map(static fn (Risque $r): string => (string) $r->getNomComplet(), $couverture['nouveaux']),
            'aRelancer'        => array_map(
                static fn (array $a): array => ['risque' => (string) $a['risque']->getNomComplet(), 'motif' => $a['motif']],
                $couverture['aRelancer'],
            ),
            'objectif'         => 'Saturation 100 % : ce client doit souscrire tous les risques du catalogue.',
        ]);
    }
}
