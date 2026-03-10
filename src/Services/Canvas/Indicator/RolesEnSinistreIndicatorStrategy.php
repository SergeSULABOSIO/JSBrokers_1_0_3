<?php

namespace App\Services\Canvas\Indicator;

use App\Entity\RolesEnSinistre;
use App\Services\ServiceDates;
use Symfony\Contracts\Translation\TranslatorInterface;

class RolesEnSinistreIndicatorStrategy implements IndicatorCalculationStrategyInterface
{
    public function __construct(
        private ServiceDates $serviceDates,
        private TranslatorInterface $translator
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === RolesEnSinistre::class;
    }

    public function calculate(object $entity): array
    {
        /** @var RolesEnSinistre $entity */
        $invite = $entity->getInvite();
        $inviteNom = $invite ? $invite->getNom() : 'N/A';
        
        $indicateurs = [
            'inviteNom' => $inviteNom,
        ];
        
        $accessFields = ['accessTypePiece', 'accessNotification', 'accessReglement'];
        
        foreach ($accessFields as $field) {
            if (method_exists($entity, 'get' . ucfirst($field))) {
                $indicateurs[$field . 'String'] = $this->Role_getAccessString($entity, [$field]);
            }
        }

        return $indicateurs;
    }

    // --- Méthodes privées déplacées depuis CalculationProvider ---

    /**
     * Génère une chaîne de caractères lisible pour les permissions d'accès.
     *
     * @param object $entity L'entité de rôle.
     * @param array $params Le tableau de paramètres contenant le nom du champ d'accès.
     * @return string
     */
    private function Role_getAccessString(object $entity, array $params): string
    {
        if (empty($params[0])) {
            return 'Paramètre manquant';
        }
 
        $fieldCode = $params[0];
        $getter = 'get' . ucfirst($fieldCode);

        if (!method_exists($entity, $getter)) {
            return 'Champ d\'accès invalide';
        }

        $accessArray = $entity->{$getter}();
 
        if (!is_array($accessArray) || empty($accessArray)) {
            return 'Aucun accès défini';
        }

        $permissionMap = [
            0 => 'read',   'read'   => 'read',   // 0 = Lecture
            1 => 'create', 'create' => 'create', // 1 = Ecriture
            2 => 'update', 'update' => 'update',
            3 => 'delete', 'delete' => 'delete',
        ];
        
        $permissionLabels = [
            'create' => 'Ecriture',
            'read'   => 'Lecture',
            'update' => 'Modification',
            'delete' => 'Suppression',
        ];

        $labels = [];
        foreach ($accessArray as $permission) {
            $permissionKey = $permissionMap[$permission] ?? null;
            if ($permissionKey && isset($permissionLabels[$permissionKey])) {
                $labels[] = $permissionLabels[$permissionKey];
            }
        }

        return empty($labels) ? 'Aucun accès valide' : implode(', ', $labels);
    }
}