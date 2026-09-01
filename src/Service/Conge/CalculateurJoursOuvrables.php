<?php

namespace App\Service\Conge;

use App\Entity\Invite;
use App\Entity\JourFerie;
use App\Entity\RegimeTravail;
use App\Repository\JourFerieRepository;
use App\Repository\RegimeTravailRepository;

/**
 * COMBIEN DE JOURS UNE ABSENCE COÛTE RÉELLEMENT.
 *
 * Service isolé, sans effet de bord et sans état : on lui donne une période, il rend un
 * décompte. C'est le seul endroit de l'application où l'on décide qu'un samedi ne compte
 * pas — et c'est pour cela qu'il est testable ligne à ligne.
 *
 * ── LES TROIS SOUSTRACTIONS ─────────────────────────────────────────────────────────
 *  1. les jours que l'agent ne travaille pas (son régime) ;
 *  2. les jours fériés déclarés par le cabinet ;
 *  3. une demi-journée par bord de période, quand la demande le précise.
 *
 * ── LE TAUX D'OCCUPATION NE MULTIPLIE RIEN ──────────────────────────────────────────
 * Un temps partiel à quatre jours par semaine est déjà décompté correctement par son
 * régime : ses lundis-vendredis non travaillés ne comptent pas. Multiplier ensuite par
 * 0,80 retrancherait une seconde fois le même temps partiel, et une semaine d'absence
 * lui coûterait 3,2 jours au lieu de 4. Le taux sert à proratiser une DOTATION, jamais
 * un décompte.
 *
 * ── LE DÉFAUT VIT ICI, PAS EN BASE ──────────────────────────────────────────────────
 * Un agent sans régime déclaré est un temps plein du lundi au vendredi. Semer une ligne
 * de régime pour chaque collaborateur à la création du cabinet aurait fabriqué des
 * centaines de lignes disant toutes la même chose, qu'il aurait ensuite fallu maintenir.
 */
class CalculateurJoursOuvrables
{
    public function __construct(
        private readonly RegimeTravailRepository $regimeRepository,
        private readonly JourFerieRepository $jourFerieRepository,
    ) {
    }

    /**
     * Décompte d'une période pour un agent, au demi-jour près.
     *
     * @param bool $demiJourneeDebut Le premier jour n'est pris qu'à moitié.
     * @param bool $demiJourneeFin   Le dernier jour n'est pris qu'à moitié.
     */
    public function calculer(
        ?Invite $agent,
        ?\DateTimeInterface $debut,
        ?\DateTimeInterface $fin,
        bool $demiJourneeDebut = false,
        bool $demiJourneeFin = false,
    ): float {
        if ($debut === null || $fin === null || $fin < $debut) {
            return 0.0;
        }

        $joursOuvres = $this->joursOuvresDe($agent, $debut, $fin);
        $feries = $this->feriesIndexes($agent, $debut, $fin);

        $courant = \DateTimeImmutable::createFromInterface($debut)->setTime(0, 0);
        $borne = \DateTimeImmutable::createFromInterface($fin)->setTime(0, 0);

        $total = 0.0;
        $premierJourCompte = null;
        $dernierJourCompte = null;

        while ($courant <= $borne) {
            if ($this->estTravaille($courant, $joursOuvres, $feries)) {
                $total += 1.0;
                $premierJourCompte ??= $courant;
                $dernierJourCompte = $courant;
            }
            $courant = $courant->modify('+1 day');
        }

        return $this->retirerLesDemiJournees(
            $total,
            $demiJourneeDebut,
            $demiJourneeFin,
            $premierJourCompte,
            $dernierJourCompte,
        );
    }

    /**
     * Les demi-journées de bord ne se retirent que d'un jour RÉELLEMENT décompté.
     *
     * Annoncer « je pars le vendredi après-midi » quand le vendredi est férié ne doit
     * rien retrancher : il n'y avait rien à retrancher. De même, si la période ne tient
     * que sur un seul jour ouvré, les deux bords désignent CE MÊME jour — les compter
     * tous les deux ramènerait la demande à zéro, c'est-à-dire à une absence qui ne
     * consomme rien tout en existant.
     */
    private function retirerLesDemiJournees(
        float $total,
        bool $demiDebut,
        bool $demiFin,
        ?\DateTimeImmutable $premier,
        ?\DateTimeImmutable $dernier,
    ): float {
        if ($total <= 0.0) {
            return 0.0;
        }

        if ($premier !== null && $dernier !== null && $premier->format('Y-m-d') === $dernier->format('Y-m-d')) {
            return ($demiDebut || $demiFin) ? $total - 0.5 : $total;
        }

        if ($demiDebut && $premier !== null) {
            $total -= 0.5;
        }
        if ($demiFin && $dernier !== null) {
            $total -= 0.5;
        }

        return max(0.0, $total);
    }

    /**
     * Le jour est-il travaillé ? Ni week-end au sens du régime, ni jour férié.
     *
     * @param int[]                 $joursOuvres numéros ISO-8601 des jours travaillés
     * @param array<string, true>   $feries      indexés par 'Y-m-d'
     */
    private function estTravaille(\DateTimeImmutable $jour, array $joursOuvres, array $feries): bool
    {
        if (isset($feries[$jour->format('Y-m-d')])) {
            return false;
        }

        return in_array((int) $jour->format('N'), $joursOuvres, true);
    }

    /**
     * Jours ouvrés applicables à l'agent sur la période.
     *
     * Le régime est lu UNE FOIS, au premier jour de la période, et non jour par jour :
     * une demande de trois semaines déclencherait sinon vingt et une requêtes pour une
     * information qui ne change pratiquement jamais en cours d'absence. Un changement de
     * régime au milieu d'un congé est un cas que personne n'a rencontré ; s'il survenait,
     * c'est le régime du départ qui s'applique — celui sous lequel la demande a été posée.
     *
     * @return int[]
     */
    private function joursOuvresDe(?Invite $agent, \DateTimeInterface $debut, \DateTimeInterface $fin): array
    {
        if ($agent === null) {
            return RegimeTravail::JOURS_OUVRES_DEFAUT;
        }

        $regime = $this->regimeRepository->applicableA($agent, $debut);
        $jours = $regime?->getJoursOuvres() ?? [];

        // Un régime enregistré SANS aucun jour travaillé rendrait toute absence gratuite.
        // C'est une saisie incomplète, pas une intention : on retombe sur le défaut.
        return $jours !== [] ? $jours : RegimeTravail::JOURS_OUVRES_DEFAUT;
    }

    /**
     * Jours fériés du cabinet sur la période, indexés par date pour un test en O(1).
     *
     * @return array<string, true>
     */
    private function feriesIndexes(?Invite $agent, \DateTimeInterface $debut, \DateTimeInterface $fin): array
    {
        $entreprise = $agent?->getEntreprise();
        if ($entreprise === null) {
            return [];
        }

        $index = [];
        /** @var JourFerie $ferie */
        foreach ($this->jourFerieRepository->pourPeriode($entreprise, $debut, $fin) as $ferie) {
            $date = $ferie->getDate();
            if ($date !== null) {
                $index[$date->format('Y-m-d')] = true;
            }
        }

        return $index;
    }
}
