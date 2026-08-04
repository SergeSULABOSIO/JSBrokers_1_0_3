<?php

namespace App\Services\Search;

use App\Entity\Invite;

/**
 * Fabrique des critères de la CHARGE DE TRAVAIL d'un invité : les tâches et les
 * prochaines actions de feedback qui lui incombent.
 *
 * SOURCE UNIQUE de cette règle, partagée par PlanDuJourService (le programme du
 * jour affiché à l'ouverture du chat) et BoussoleService (les axes « tâches » et
 * « feedbacks » injectés dans le contexte de Ket) : sans elle, le plan et le rappel
 * de fin de réponse pourraient annoncer deux nombres différents.
 *
 * DEUX APPARTENANCES DISTINCTES, volontairement non fusionnées :
 *
 *  1. L'ASSIGNATION — « c'est à moi de le faire ». Une tâche non assignée relève de
 *     tout le monde : la règle est donc « executor = moi OU executor IS NULL »,
 *     exactement celle que TacheController pose déjà sur la rubrique Tâches.
 *  2. Le PORTEFEUILLE — « c'est mon client ». Chemin de relations emprunté à
 *     PortefeuilleScope, comme partout ailleurs.
 *
 * Elles ne se recouvrent pas : une tâche assignée à moi peut porter sur le client
 * d'un collègue, et une tâche de mon portefeuille peut être assignée à un autre.
 * Le moteur de recherche combinant tous les critères en AND, leur UNION n'est pas
 * exprimable en une requête — les consommateurs interrogent les deux jeux et
 * fusionnent en mémoire, ce qui donne aussi les deux sections attendues à l'écran.
 */
final class ChargeInviteCritereFactory
{
    /** Horizon par défaut d'une « todo list de la journée » : le retard, ce jour, la semaine. */
    public const HORIZON_JOURS = 7;

    public function __construct(private readonly PortefeuilleCritereFactory $portefeuilleCritere)
    {
    }

    /**
     * Tâches ouvertes qui M'INCOMBENT : assignées à moi, ou à personne.
     *
     * @return array<string, mixed>
     */
    public function tachesAssignees(Invite $invite): array
    {
        return [
            'closed' => ['operator' => '=', 'value' => false],
            'executor' => ['IS_NULL_OR_EQ' => $invite],
        ];
    }

    /**
     * Tâches ouvertes portant sur un client de MON PORTEFEUILLE. Le critère de
     * périmètre est vide si l'invité ne gère aucun portefeuille : l'appelant doit
     * alors considérer la section comme sans objet plutôt que d'afficher toutes
     * les tâches de l'entreprise.
     *
     * @return array<string, mixed>
     */
    public function tachesPortefeuille(Invite $invite): array
    {
        return ['closed' => ['operator' => '=', 'value' => false]]
            + $this->portefeuilleCritere->pour('Tache', $invite);
    }

    /**
     * Restreint un jeu de critères de tâches à celles qui ÉCHOIENT : en retard,
     * ce jour, ou dans les prochains jours. `toBeEndedAt` est une vraie colonne,
     * le bornage se fait donc en SQL (CAS 1 du moteur, borne haute inclusive).
     *
     * @param array<string, mixed> $criteres
     * @return array<string, mixed>
     */
    public function borneEcheance(array $criteres, \DateTimeImmutable $jour, int $horizonJours = self::HORIZON_JOURS): array
    {
        $criteres['toBeEndedAt'] = ['to' => $jour->modify('+' . max(0, $horizonJours) . ' days')->format('Y-m-d')];

        return $criteres;
    }

    /**
     * Feedbacks DONT JE SUIS L'AUTEUR portant une prochaine action due ou en retard.
     * `hasNextAction` seul ne suffit pas : c'est la date qui fait l'action à mener.
     *
     * @return array<string, mixed>
     */
    public function feedbacksAuteur(Invite $invite, \DateTimeImmutable $jour): array
    {
        return $this->feedbacksDus($jour) + [
            'invite' => ['operator' => '=', 'value' => $invite->getId()],
        ];
    }

    /**
     * Idem sur le périmètre portefeuille (le feedback rejoint le client par sa tâche).
     *
     * @return array<string, mixed>
     */
    public function feedbacksPortefeuille(Invite $invite, \DateTimeImmutable $jour): array
    {
        return $this->feedbacksDus($jour) + $this->portefeuilleCritere->pour('Feedback', $invite);
    }

    /**
     * Indique si le critère de périmètre portefeuille a bien pu être posé. Un invité
     * sans portefeuille produit un critère vide, qui élargirait silencieusement la
     * requête à toute l'entreprise — le consommateur doit donc s'abstenir.
     */
    public function aUnPortefeuille(string $entityShortName, Invite $invite): bool
    {
        return $this->criterePortefeuille($entityShortName, $invite) !== [];
    }

    /**
     * Passe-plat vers la fabrique du périmètre portefeuille, pour que les
     * consommateurs de cette fabrique n'aient qu'une seule dépendance à injecter.
     *
     * @return array<string, mixed>
     */
    public function criterePortefeuille(string $entityShortName, Invite $invite): array
    {
        return $this->portefeuilleCritere->pour($entityShortName, $invite);
    }

    /** @return array<string, mixed> */
    private function feedbacksDus(\DateTimeImmutable $jour): array
    {
        return [
            'hasNextAction' => ['operator' => '=', 'value' => true],
            'nextActionAt' => ['to' => $jour->format('Y-m-d')],
        ];
    }
}
