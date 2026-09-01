<?php

namespace App\Tests\Workspace;

use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\JourFerie;
use App\Entity\RegimeTravail;
use App\Repository\JourFerieRepository;
use App\Repository\RegimeTravailRepository;
use App\Service\Conge\CalculateurJoursOuvrables;
use App\Service\Conge\RegimeDeLAgent;
use PHPUnit\Framework\TestCase;

/**
 * COMBIEN DE JOURS UNE ABSENCE COÛTE RÉELLEMENT.
 *
 * C'est le seul endroit de l'application où l'on décide qu'un samedi ne compte pas. Un
 * décompte faux ne se voit pas : la demande passe, le solde descend d'un jour de trop, et
 * personne ne s'en aperçoit avant que quelqu'un réclame ses jours en décembre. D'où un
 * test unitaire pur, sans base, sur chacune des trois soustractions.
 */
class CalculateurJoursOuvrablesTest extends TestCase
{
    private const LUNDI = '2026-09-07';    // le 7 septembre 2026 est un lundi
    private const VENDREDI = '2026-09-11';
    private const DIMANCHE = '2026-09-13';

    public function testUneSemaineComplèteVautCinqJours(): void
    {
        $calculateur = $this->calculateur();

        self::assertSame(5.0, $calculateur->calculer(
            $this->agent(),
            new \DateTimeImmutable(self::LUNDI),
            new \DateTimeImmutable(self::VENDREDI),
        ));
    }

    /** Scénario 4 de la recette : le week-end ne se décompte pas. */
    public function testLeWeekEndNEstPasDecompte(): void
    {
        $calculateur = $this->calculateur();

        // Du lundi au dimanche : sept jours de calendrier, cinq jours ouvrables.
        self::assertSame(5.0, $calculateur->calculer(
            $this->agent(),
            new \DateTimeImmutable(self::LUNDI),
            new \DateTimeImmutable(self::DIMANCHE),
        ));
    }

    /** Scénario 4 (suite) : un jour férié en semaine ne se décompte pas non plus. */
    public function testUnJourFerieEnSemaineNEstPasDecompte(): void
    {
        $ferie = new JourFerie();
        $ferie->setDate(new \DateTimeImmutable('2026-09-09')); // mercredi
        $ferie->setLibelle('Fête nationale');

        $calculateur = $this->calculateur(feries: [$ferie]);

        self::assertSame(4.0, $calculateur->calculer(
            $this->agent(),
            new \DateTimeImmutable(self::LUNDI),
            new \DateTimeImmutable(self::VENDREDI),
        ));
    }

    /** Scénario 5 : une demi-journée de bord retire une demi-journée, pas davantage. */
    public function testUneDemiJourneeDeDebutRetireUnDemiJour(): void
    {
        $calculateur = $this->calculateur();

        self::assertSame(4.5, $calculateur->calculer(
            $this->agent(),
            new \DateTimeImmutable(self::LUNDI),
            new \DateTimeImmutable(self::VENDREDI),
            demiJourneeDebut: true,
        ));
    }

    public function testLesDeuxBordsEnDemiJourneeRetirentUnJourEntier(): void
    {
        $calculateur = $this->calculateur();

        self::assertSame(4.0, $calculateur->calculer(
            $this->agent(),
            new \DateTimeImmutable(self::LUNDI),
            new \DateTimeImmutable(self::VENDREDI),
            demiJourneeDebut: true,
            demiJourneeFin: true,
        ));
    }

    /**
     * SUR UN SEUL JOUR, LES DEUX BORDS DÉSIGNENT LE MÊME JOUR.
     *
     * Les compter tous les deux ramènerait la demande à zéro : une absence qui existe
     * mais ne consomme rien, et que le contrôle de solde laisserait passer sans limite.
     */
    public function testSurUnSeulJourLesDeuxBordsNeRetirentQuUnDemiJour(): void
    {
        $calculateur = $this->calculateur();

        self::assertSame(0.5, $calculateur->calculer(
            $this->agent(),
            new \DateTimeImmutable(self::LUNDI),
            new \DateTimeImmutable(self::LUNDI),
            demiJourneeDebut: true,
            demiJourneeFin: true,
        ));
    }

    /**
     * Scénario 6 : un temps partiel à quatre jours voit son mercredi correctement exclu.
     */
    public function testUnTempsPartielNeDecomptePasSesJoursNonTravailles(): void
    {
        $regime = new RegimeTravail();
        $regime->setJoursOuvres([1, 2, 4, 5]); // ni mercredi, ni week-end
        $regime->setDateDebut(new \DateTimeImmutable('2026-01-01'));

        $calculateur = $this->calculateur(regime: $regime);

        self::assertSame(4.0, $calculateur->calculer(
            $this->agent(),
            new \DateTimeImmutable(self::LUNDI),
            new \DateTimeImmutable(self::VENDREDI),
        ));
    }

    /**
     * LE TAUX D'OCCUPATION NE MULTIPLIE RIEN.
     *
     * Le temps partiel est déjà pris en compte par les jours travaillés ; multiplier
     * ensuite par le taux le retrancherait une seconde fois, et une semaine d'absence
     * coûterait 3,2 jours à un 80 % au lieu de 4.
     */
    public function testLeTauxDOccupationNeMultipliePasLeDecompte(): void
    {
        $regime = new RegimeTravail();
        $regime->setJoursOuvres([1, 2, 4, 5]);
        $regime->setTauxOccupation('0.80');
        $regime->setDateDebut(new \DateTimeImmutable('2026-01-01'));

        $calculateur = $this->calculateur(regime: $regime);

        self::assertSame(4.0, $calculateur->calculer(
            $this->agent(),
            new \DateTimeImmutable(self::LUNDI),
            new \DateTimeImmutable(self::VENDREDI),
        ));
    }

    /**
     * UN RÉGIME SANS AUCUN JOUR TRAVAILLÉ EST UNE SAISIE INCOMPLÈTE, PAS UNE INTENTION.
     * Le prendre au mot rendrait toute absence gratuite pour ce collaborateur.
     */
    public function testUnRegimeSansJourRetombeSurLeDefaut(): void
    {
        $regime = new RegimeTravail();
        $regime->setJoursOuvres([]);
        $regime->setDateDebut(new \DateTimeImmutable('2026-01-01'));

        $calculateur = $this->calculateur(regime: $regime);

        self::assertSame(5.0, $calculateur->calculer(
            $this->agent(),
            new \DateTimeImmutable(self::LUNDI),
            new \DateTimeImmutable(self::VENDREDI),
        ));
    }

    /** Une demi-journée annoncée sur un bord férié ne retranche rien : il n'y avait rien. */
    public function testUneDemiJourneeSurUnBordFerieNeRetrancheRien(): void
    {
        $ferie = new JourFerie();
        $ferie->setDate(new \DateTimeImmutable(self::LUNDI));
        $ferie->setLibelle('Lundi férié');

        $calculateur = $this->calculateur(feries: [$ferie]);

        // Le lundi ne compte pas ; le premier jour DÉCOMPTÉ est le mardi, et c'est de
        // lui que la demi-journée se retire.
        self::assertSame(3.5, $calculateur->calculer(
            $this->agent(),
            new \DateTimeImmutable(self::LUNDI),
            new \DateTimeImmutable(self::VENDREDI),
            demiJourneeDebut: true,
        ));
    }

    public function testUnePeriodeInverseeNeVautRien(): void
    {
        $calculateur = $this->calculateur();

        self::assertSame(0.0, $calculateur->calculer(
            $this->agent(),
            new \DateTimeImmutable(self::VENDREDI),
            new \DateTimeImmutable(self::LUNDI),
        ));
    }

    public function testUnWeekEndSeulNeVautRien(): void
    {
        $calculateur = $this->calculateur();

        self::assertSame(0.0, $calculateur->calculer(
            $this->agent(),
            new \DateTimeImmutable('2026-09-12'), // samedi
            new \DateTimeImmutable(self::DIMANCHE),
        ));
    }

    public function testSansAgentNiDatesLeDecompteEstNul(): void
    {
        $calculateur = $this->calculateur();

        self::assertSame(0.0, $calculateur->calculer(null, null, null));
    }

    // ─────────────────────────────── Montage ───────────────────────────────────────

    /**
     * @param JourFerie[] $feries
     */
    private function calculateur(?RegimeTravail $regime = null, array $feries = []): CalculateurJoursOuvrables
    {
        // On monte le VRAI RegimeDeLAgent sur un repository simulé : c'est lui qui porte
        // le repli « temps plein du lundi au vendredi », et le tester à travers un double
        // reviendrait à tester le double.
        $regimeRepository = $this->createMock(RegimeTravailRepository::class);
        $regimeRepository->method('applicableA')->willReturn($regime);

        $ferieRepository = $this->createMock(JourFerieRepository::class);
        $ferieRepository->method('pourPeriode')->willReturn($feries);

        return new CalculateurJoursOuvrables(new RegimeDeLAgent($regimeRepository), $ferieRepository);
    }

    private function agent(): Invite
    {
        $agent = new Invite();
        $agent->setNom('Agent de test');
        $agent->setEntreprise(new Entreprise());

        return $agent;
    }
}
