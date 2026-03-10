<?php

// src/Services/Canvas/Indicator/IndicatorCalculationHelper.php
namespace App\Services\Canvas\Indicator;

class IndicatorCalculationHelper
{
    /**
     * Fournit une interprétation textuelle d'un taux de sinistralité (S/P).
     */
    public function getInterpretationTauxSP(float $taux): string
    {
        if ($taux == 0) {
            return "Aucun sinistre enregistré ou prime nulle.";
        }
        if ($taux < 70) {
            return "Excellent. Le portefeuille est très rentable.";
        } elseif ($taux <= 80) {
            return "Sain. Équilibre classique.";
        } elseif ($taux <= 100) {
            return "Prudence. Rentabilité faible.";
        }
        return "Déficitaire. Pertes techniques.";
    }

    /**
     * Génère une chaîne de caractères lisible pour les permissions d'accès aux rôles.
     *
     * @param object $entity L'entité de rôle.
     * @param array $params Le tableau de paramètres contenant le nom du champ d'accès.
     * @return string
     */
    public function getRoleAccessString(object $entity, array $params): string
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