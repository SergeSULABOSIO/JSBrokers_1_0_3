<?php

namespace App\Ai\Tool;

use App\Ai\AiText;
use App\Ai\Scope\AiScope;
use App\Service\Workspace\WorkspaceAccessResolver;
use App\Services\Canvas\CalculationProvider;
use App\Services\Canvas\CanvasHelper;
use App\Services\JSBDynamicSearchService;

/**
 * Lit un indicateur CALCULÉ (prime totale, commission nette, taux de
 * sinistralité…) — la valeur n'existe pas en base, elle est produite par le
 * moteur d'indicateurs globaux (CalculationProvider::getIndicateursGlobaux) :
 * au niveau ENTREPRISE (sans cible), ou CIBLÉ sur un enregistrement (option
 * clientCible/avenantCible/… du moteur), avec période du/au optionnelle.
 * Dictionnaire des indicateurs (codes, intitulés, unités) partagé avec la
 * colonne de visualisation via CanvasHelper (DRY).
 *
 * GOTCHA (bug KIN AVIA) : ne JAMAIS lire $entity->{$code} après
 * loadAllCalculatedValues — les stratégies posent des clés métier camelCase
 * (montantTTC, primeTotale…), pas les codes du dictionnaire → 0 systématique.
 * Le moteur ciblé renvoie, lui, exactement les codes (c'est ce que les
 * stratégies elles-mêmes consomment).
 *
 * FAIL-CLOSED : canRead sur l'entité ciblée (niveau entreprise = lecture des
 * Propositions/Cotation, source des agrégats) ; résolution de cible
 * strictement scopée à l'entreprise (JSBDynamicSearchService).
 */
final class IndicateurCalculeTool implements AiToolInterface
{
    /** Nombre maximal de candidats restitués sur un nom ambigu. */
    private const MAX_CANDIDATS = 6;

    /**
     * Entités ciblables par le moteur d'indicateurs : nom court => option
     * « cible » de getIndicateursGlobaux (cf. IndicatorCalculationHelper).
     */
    private const CIBLES = [
        'Client'               => 'clientCible',
        'Assureur'             => 'assureurCible',
        'Risque'               => 'risqueCible',
        'Partenaire'           => 'partenaireCible',
        'Invite'               => 'inviteCible',
        'Groupe'               => 'groupeCible',
        'Portefeuille'         => 'portefeuilleCible',
        'Avenant'              => 'avenantCible',
        'Cotation'             => 'cotationCible',
        'Piste'                => 'pisteCible',
        'Tranche'              => 'trancheCible',
        'TypeRevenu'           => 'typeRevenuCible',
        'RevenuPourCourtier'   => 'revenuPourCourtierCible',
        'Paiement'             => 'paiementCible',
        'NotificationSinistre' => 'notificationSinistreCible',
        'ConditionPartage'     => 'conditionPartageCible',
    ];

    public function __construct(
        private readonly WorkspaceAccessResolver $accessResolver,
        private readonly CanvasHelper $canvasHelper,
        private readonly CalculationProvider $calculationProvider,
        private readonly JSBDynamicSearchService $searchService,
        private readonly EntiteLexique $lexique,
        private readonly EntiteLibelle $libelleur,
    ) {
    }

    public function name(): string
    {
        return 'indicateur_calcule';
    }

    public function description(): string
    {
        return "Donne la valeur d'un indicateur financier calculé (prime totale, commission "
            . 'nette, solde prime, taux de sinistralité…) pour : un enregistrement nommé '
            . '(client, portefeuille, avenant, assureur, risque, partenaire…) OU l\'ENTREPRISE '
            . 'entière (entite=Entreprise, sans cible, période du/au optionnelle). À appeler '
            . 'pour tout chiffre métier calculé — les attributs stockés relèvent de lire_fiche, '
            . 'les états comptables de document_comptable.';
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
                'entite' => [
                    'type' => 'string',
                    'description' => "Entité porteuse de l'indicateur (défaut Client ; Entreprise = totaux du cabinet).",
                    'enum' => array_merge(['Entreprise'], array_keys(self::CIBLES)),
                ],
                'cible' => [
                    'type' => 'string',
                    'description' => "Nom (ou partie du nom) de l'enregistrement ciblé. Inutile pour entite=Entreprise.",
                ],
                'id' => [
                    'type' => 'integer',
                    'description' => "Identifiant de l'enregistrement ciblé (prioritaire sur cible).",
                ],
                'du' => [
                    'type' => 'string',
                    'description' => 'Début de période AAAA-MM-JJ (optionnel).',
                ],
                'au' => [
                    'type' => 'string',
                    'description' => 'Fin de période AAAA-MM-JJ (optionnel).',
                ],
            ],
            'required' => ['code'],
        ];
    }

    public function match(string $question, AiScope $scope): ?array
    {
        $normalized = AiText::normalize($question);

        $code = $this->matchIndicateur($normalized);
        if ($code === null) {
            return null;
        }

        // Niveau entreprise : « … de l'entreprise / du cabinet / au total / global ».
        if (preg_match('/\b(entreprise|cabinet|societe|globale?|au total)\b/', $normalized)) {
            return ['code' => $code, 'entite' => 'Entreprise'];
        }

        // « … du <entité> <nom> » — la cible est capturée après le mot-clé d'entité.
        $shortName = $this->lexique->matchEntite($normalized);
        if ($shortName === null) {
            return null;
        }
        foreach ($this->lexique->lexique()[$shortName] as $keyword) {
            if (preg_match('/\b' . preg_quote($keyword, '/') . '\s+(?:de |du |de la |d )?(.{2,60}?)(?:\s*\?|$)/', $normalized, $m)) {
                return ['code' => $code, 'entite' => $shortName, 'cible' => trim($m[1])];
            }
        }

        return null;
    }

    public function execute(array $args, AiScope $scope): AiToolResult
    {
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

        $shortName = (string) ($args['entite'] ?? 'Client');
        $options = $this->optionsPeriode($args);

        // Niveau ENTREPRISE : indicateurs globaux du cabinet (hors carte de rubriques).
        // FAIL-CLOSED : les agrégats sont dérivés des cotations/avenants — la lecture
        // des Propositions (Cotation) gouverne l'accès à ces totaux.
        if ($shortName === 'Entreprise') {
            if (!$this->accessResolver->canRead($scope->invite, 'Cotation')) {
                return AiToolResult::horsPerimetre("Indicateurs financiers de l'entreprise");
            }

            return $this->lireIndicateur($code, $indicateur, $options, $scope, 'Entreprise', (string) $scope->entreprise->getNom());
        }

        $labels = $this->accessResolver->libellesEntites();
        if (!isset($labels[$shortName])) {
            return AiToolResult::introuvable($shortName);
        }
        if (!isset(self::CIBLES[$shortName])) {
            return AiToolResult::introuvable(sprintf(
                'indicateurs indisponibles pour %s — entités ciblables : Entreprise, %s',
                $shortName,
                implode(', ', array_keys(self::CIBLES)),
            ));
        }

        // FAIL-CLOSED : l'indicateur d'une entité est une donnée de cette entité.
        if (!$this->accessResolver->canRead($scope->invite, $shortName)) {
            return AiToolResult::horsPerimetre($labels[$shortName]);
        }

        $fqcn = 'App\\Entity\\' . $shortName;
        if (!class_exists($fqcn)) {
            return AiToolResult::introuvable($shortName);
        }

        $id = (int) ($args['id'] ?? 0);
        $cible = trim((string) ($args['cible'] ?? ''));
        $displayField = $this->libelleur->displayField($fqcn);

        if ($id > 0) {
            $criteria = ['id' => $id];
        } elseif ($cible !== '' && $displayField !== null) {
            $criteria = [$displayField => ['operator' => 'LIKE', 'value' => $cible, 'mode' => 'contains']];
        } else {
            return AiToolResult::introuvable($labels[$shortName]);
        }

        $result = $this->searchService->search($fqcn, $criteria, $scope->entreprise, null, 1, self::MAX_CANDIDATS);
        $entities = ($result['status']['code'] ?? 500) === 200 ? $result['data'] : [];
        if ($entities === []) {
            return AiToolResult::introuvable(sprintf('%s « %s »', $labels[$shortName], $cible !== '' ? $cible : '#' . $id));
        }
        if (count($entities) > 1) {
            return AiToolResult::ok([
                'entite'    => $shortName,
                'libelle'   => $labels[$shortName],
                'ambigu'    => true,
                'candidats' => array_map(
                    fn (object $e) => ['id' => $e->getId(), 'libelle' => $this->libelleur->libelle($e, $displayField)],
                    $entities,
                ),
            ]);
        }

        $entity = $entities[0];
        // Moteur CIBLÉ : les stats sont indexées par les codes du dictionnaire —
        // jamais de lecture $entity->{$code} (cf. GOTCHA de classe).
        $options[self::CIBLES[$shortName]] = $entity;

        return $this->lireIndicateur($code, $indicateur, $options, $scope, $shortName, $this->libelleur->libelle($entity, $displayField));
    }

    /** Lit le code demandé dans les indicateurs globaux (ciblés ou non) du moteur. */
    private function lireIndicateur(string $code, array $indicateur, array $options, AiScope $scope, string $entiteNom, string $cibleNom): AiToolResult
    {
        $stats = $this->calculationProvider->getIndicateursGlobaux($scope->entreprise, false, $options);
        if (!array_key_exists($code, $stats)) {
            return AiToolResult::introuvable(sprintf('%s (indicateurs disponibles : %s)', $code, implode(', ', array_keys($stats))));
        }

        $data = [
            'entite'     => $entiteNom,
            'cible'      => $cibleNom,
            'indicateur' => $indicateur['intitule'],
            'code'       => $code,
            'valeur'     => (float) $stats[$code],
            // Peut être null si l'entreprise n'a pas de monnaie d'affichage configurée.
            'unite'      => (string) ($indicateur['unite'] ?? ''),
        ];
        foreach (['entre' => 'du', 'et' => 'au'] as $option => $cle) {
            if (isset($options[$option])) {
                $data[$cle] = $options[$option];
            }
        }

        return AiToolResult::ok($data);
    }

    /** Période du/au => options entre/et du moteur d'indicateurs (dates validées). */
    private function optionsPeriode(array $args): array
    {
        $options = [];
        foreach (['du' => 'entre', 'au' => 'et'] as $arg => $option) {
            $valeur = trim((string) ($args[$arg] ?? ''));
            if ($valeur !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $valeur)) {
                $options[$option] = $valeur;
            }
        }

        return $options;
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
}
