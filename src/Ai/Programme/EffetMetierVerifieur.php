<?php

namespace App\Ai\Programme;

use App\Ai\Scope\AiScope;

/**
 * Vérificateur d'EFFET MÉTIER pour le rapport final d'un programme.
 *
 * Relire l'enregistrement en base ne suffit pas. Le cas qui a motivé tout ceci :
 * trois paiements de prime « enregistrés avec succès », les trois lignes bien
 * présentes en base — et deux tranches toujours dans la liste des impayées, parce
 * que le montant signalé ne couvrait pas la prime. L'écriture était conforme ;
 * la CONSÉQUENCE attendue, non. C'est cet écart-là que ces vérificateurs
 * mesurent, et lui seul qui permet à Ket de proposer une correction utile.
 *
 * Implémentations taggées `app.ai_effet_metier` (cf. config/services.yaml).
 */
interface EffetMetierVerifieur
{
    /** Ce vérificateur sait-il juger l'effet d'une écriture sur cette entité ? */
    public function supporte(string $entiteShortName): bool;

    /**
     * Juge l'effet métier de l'écriture.
     *
     * @return array{constats: list<string>, ecarts: list<string>, correction: array{outil: string, libelle: string, arguments: array}|null}
     *         `constats` = ce qui est conforme (dit au passif dans le rapport) ;
     *         `ecarts`   = ce qui ne l'est pas ;
     *         `correction` = étape prête à entrer dans un programme de correction.
     */
    public function verifier(object $entite, AiScope $scope): array;
}
