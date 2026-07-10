<?php

namespace App\Ai\Tool;

use App\Ai\AiText;
use App\Ai\Scope\AiScope;
use App\Entity\Client;
use App\Repository\ClientRepository;
use App\Service\Workspace\WorkspaceAccessResolver;
use App\Services\Canvas\CanvasHelper;
use App\Services\CanvasBuilder;

/**
 * Lit un indicateur CALCULÉ (prime totale, commission nette, taux de
 * sinistralité…) d'un client de l'entreprise : c'est la preuve du circuit
 * « valeurs calculées » — la valeur n'existe pas en base, elle est produite par
 * la stratégie d'indicateurs du client (CanvasBuilder::loadAllCalculatedValues).
 * Dictionnaire des indicateurs (codes, intitulés, unités) partagé avec la
 * colonne de visualisation via CanvasHelper (DRY).
 */
final class IndicateurCalculeTool implements AiToolInterface
{
    public function __construct(
        private readonly WorkspaceAccessResolver $accessResolver,
        private readonly CanvasBuilder $canvasBuilder,
        private readonly CanvasHelper $canvasHelper,
        private readonly ClientRepository $clientRepository,
    ) {
    }

    public function name(): string
    {
        return 'indicateur_calcule';
    }

    public function description(): string
    {
        return "Donne la valeur d'un indicateur financier calculé (prime totale, commission "
            . 'nette, solde prime, taux de sinistralité…) pour un client nommé de l’entreprise. '
            . 'À appeler quand l’utilisateur demande un chiffre métier sur un client précis.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'code' => [
                    'type' => 'string',
                    'description' => "Code de l'indicateur calculé (ex. prime_totale, commission_nette).",
                    'enum' => array_column($this->indicateurs(), 'code'),
                ],
                'clientNom' => [
                    'type' => 'string',
                    'description' => 'Nom (ou partie du nom) du client concerné.',
                ],
            ],
            'required' => ['code', 'clientNom'],
        ];
    }

    public function match(string $question, AiScope $scope): ?array
    {
        $normalized = AiText::normalize($question);

        // « … du client Dupont » / « … pour le client Dupont & fils »
        if (!preg_match('/\bclient\s+(.{2,60}?)(?:\s*\?|$)/', $normalized, $m)) {
            return null;
        }
        $clientNom = trim($m[1]);

        $code = $this->matchIndicateur($normalized);
        if ($code === null || $clientNom === '') {
            return null;
        }

        return ['code' => $code, 'clientNom' => $clientNom];
    }

    public function execute(array $args, AiScope $scope): AiToolResult
    {
        // FAIL-CLOSED : l'indicateur d'un client est une donnée client.
        if (!$this->accessResolver->canRead($scope->invite, 'Client')) {
            return AiToolResult::horsPerimetre('Clients');
        }

        $code = (string) ($args['code'] ?? '');
        $indicateur = null;
        foreach ($this->indicateurs() as $candidate) {
            if ($candidate['code'] === $code) {
                $indicateur = $candidate;
                break;
            }
        }
        if ($indicateur === null) {
            return AiToolResult::introuvable($code);
        }

        $client = $this->findClient((string) ($args['clientNom'] ?? ''), $scope);
        if (!$client instanceof Client) {
            return AiToolResult::introuvable((string) ($args['clientNom'] ?? ''));
        }

        $this->canvasBuilder->loadAllCalculatedValues($client);
        $valeur = $client->{$code} ?? null;

        return AiToolResult::ok([
            'client'     => $client->getNom(),
            'indicateur' => $indicateur['intitule'],
            'code'       => $code,
            'valeur'     => $valeur === null ? 0.0 : (float) $valeur,
            'unite'      => $indicateur['unite'],
        ]);
    }

    /** Dictionnaire des indicateurs calculés (mêmes définitions que la colonne de visualisation). */
    private function indicateurs(): array
    {
        return $this->canvasHelper->getGlobalIndicatorsCanvas('Client');
    }

    /** Repère l'indicateur cité dans la question (intitulés les plus longs d'abord). */
    private function matchIndicateur(string $normalizedQuestion): ?string
    {
        $candidats = [];
        foreach ($this->indicateurs() as $indicateur) {
            $candidats[$indicateur['code']] = AiText::normalize($indicateur['intitule']);
        }
        uasort($candidats, fn (string $a, string $b) => mb_strlen($b) <=> mb_strlen($a));

        foreach ($candidats as $code => $intitule) {
            if (str_contains($normalizedQuestion, $intitule)) {
                return $code;
            }
        }

        return null;
    }

    /** Recherche insensible aux accents/majuscules, STRICTEMENT scopée à l'entreprise. */
    private function findClient(string $nom, AiScope $scope): ?Client
    {
        if ($nom === '') {
            return null;
        }

        $candidats = $this->clientRepository->createQueryBuilder('c')
            ->andWhere('c.entreprise = :entreprise')
            ->setParameter('entreprise', $scope->entreprise)
            ->getQuery()
            ->getResult();

        $nomNormalise = AiText::normalize($nom);
        foreach ($candidats as $client) {
            if (str_contains(AiText::normalize((string) $client->getNom()), $nomNormalise)) {
                return $client;
            }
        }

        return null;
    }
}
