<?php

namespace App\Service\Conge;

use App\Entity\Invite;
use App\Entity\RegimeTravail;
use App\Repository\RegimeTravailRepository;

/**
 * QUELS JOURS UN COLLABORATEUR TRAVAILLE — source unique.
 *
 * ── POURQUOI UN SERVICE À PART ──────────────────────────────────────────────────────
 * Cette règle vivait en privé dans CalculateurJoursOuvrables, ce qui suffisait tant qu'un
 * seul consommateur en avait besoin. Le calendrier d'équipe en a besoin aussi — il doit
 * griser les jours qu'un temps partiel ne travaille pas — et la recopier aurait créé deux
 * réponses possibles à une même question. Le jour où l'une change, la grille et le
 * décompte cessent de se ressembler, et personne ne sait laquelle a raison.
 *
 * ── LE DÉFAUT VIT ICI, PAS EN BASE ──────────────────────────────────────────────────
 * Un agent sans régime déclaré est un temps plein du lundi au vendredi. Semer une ligne
 * de régime pour chaque collaborateur à la création du cabinet aurait fabriqué des
 * centaines de lignes disant toutes la même chose, qu'il aurait ensuite fallu maintenir.
 */
class RegimeDeLAgent
{
    public function __construct(
        private readonly RegimeTravailRepository $regimeRepository,
    ) {
    }

    /**
     * Les jours travaillés par cet agent à cette date, en numéros ISO-8601.
     *
     * @return int[]
     */
    public function joursOuvresDe(?Invite $agent, ?\DateTimeInterface $aLaDate = null): array
    {
        if ($agent === null) {
            return RegimeTravail::JOURS_OUVRES_DEFAUT;
        }

        $regime = $this->regimeRepository->applicableA($agent, $aLaDate ?? new \DateTimeImmutable('today'));
        $jours = $regime?->getJoursOuvres() ?? [];

        // Un régime enregistré SANS aucun jour travaillé rendrait toute absence gratuite.
        // C'est une saisie incomplète, pas une intention : on retombe sur le défaut.
        return $jours !== [] ? $jours : RegimeTravail::JOURS_OUVRES_DEFAUT;
    }

    /** Le taux d'occupation applicable, 1.00 par défaut. */
    public function tauxDe(?Invite $agent, ?\DateTimeInterface $aLaDate = null): float
    {
        if ($agent === null) {
            return 1.0;
        }

        $regime = $this->regimeRepository->applicableA($agent, $aLaDate ?? new \DateTimeImmutable('today'));

        return $regime === null ? 1.0 : (float) $regime->getTauxOccupation();
    }
}
