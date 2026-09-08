<?php

namespace App\Tests\Ai;

use App\Ai\Boussole\BoussoleService;
use App\Comptabilite\CourtierSuiviFiscalService;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Service\Onboarding\OnboardingCompletude;
use App\Service\Saturation\SaturationService;
use App\Service\Workspace\WorkspaceAccessResolver;
use App\Services\JSBDynamicSearchService;
use App\Services\Note\NoteRecouvrementService;
use App\Services\Search\ChargeInviteCritereFactory;
use App\Services\Search\PortefeuilleCritereFactory;
use App\Services\Tranche\TranchePaiementService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * BoussoleService : instantané compact de la chaîne de valeur, FAIL-CLOSED (un axe
 * n'apparaît qu'avec le droit de lecture) et FAIL-SAFE (une exception d'axe est
 * avalée — le chat ne doit jamais casser). La priorité = l'axe actionnable le plus
 * urgent. Tests purs.
 */
class BoussoleServiceTest extends TestCase
{
    /**
     * @param array<string,bool> $droits      canRead par entité
     * @param int                $sousSatures clients sous 100 % de couverture
     * @param float              $soldeFiscal solde de taxe dû PAR LE CABINET
     * @param bool               $fiscalThrow CourtierSuiviFiscalService::suivi lève une exception
     * @param int                $notesDues   notes de débit assureur non encaissées
     * @param array<int, array<string, mixed>> $restantes étapes de configuration non faites
     * @param bool               $bloquant    reste-t-il une étape SANS laquelle on ne produit pas
     */
    private function makeService(
        array $droits,
        int $sousSatures = 0,
        float $soldeFiscal = 0.0,
        bool $fiscalThrow = false,
        int $notesDues = 0,
        array $restantes = [],
        bool $bloquant = false,
    ): BoussoleService {
        $resolver = $this->createMock(WorkspaceAccessResolver::class);
        $resolver->method('canRead')->willReturnCallback(
            static fn (Invite $invite, string $shortName): bool => $droits[$shortName] ?? false,
        );

        $saturation = $this->createMock(SaturationService::class);
        $saturation->method('couverturePortefeuille')->willReturn([
            'perimetre'            => 'Portefeuille Test',
            'nbClients'            => 10,
            'nbCatalogue'          => 5,
            'tauxMoyen'            => 55.0,
            'nbClientsSatures'     => 10 - $sousSatures,
            'nbClientsSousSatures' => $sousSatures,
            'topOpportunites'      => [['risque' => 'Santé', 'nbClients' => 4]],
        ]);

        $tranche = $this->createMock(TranchePaiementService::class);
        $tranche->method('lister')->willReturn([
            'items' => [], 'totaux' => ['totalSoldePrime' => 0.0, 'totalSoldeCommission' => 0.0, 'totalRetroExigible' => 0.0],
            'totalItems' => 0, 'currentPage' => 1, 'totalPages' => 1,
        ]);

        // CourtierSuiviFiscalService (le cabinet), et surtout pas SuiviFiscalService
        // (la plateforme Joseara) : sa forme de retour est à DEUX volets, sans
        // clé `totaux` racine — le mock la reproduit pour verrouiller le contrat.
        $fiscal = $this->createMock(CourtierSuiviFiscalService::class);
        if ($fiscalThrow) {
            $fiscal->method('suivi')->willThrowException(new \RuntimeException('boom'));
        } else {
            $fiscal->method('suivi')->willReturn([
                'exercice' => (int) date('Y'),
                'assureur' => ['lignes' => [], 'totaux' => ['collectee' => 0.0, 'deductible' => 0.0, 'netDu' => 0.0, 'reverse' => 0.0, 'solde' => $soldeFiscal]],
                'courtier' => ['lignes' => [], 'totaux' => ['du' => 0.0, 'paye' => 0.0, 'solde' => 0.0]],
            ]);
        }

        $notes = $this->createMock(NoteRecouvrementService::class);
        $notes->method('lister')->willReturn([
            'items' => [], 'totaux' => ['nb' => $notesDues, 'totalFacture' => 0.0, 'totalEncaisse' => 0.0, 'totalSolde' => 0.0],
            'totalItems' => $notesDues, 'currentPage' => 1, 'totalPages' => 1,
        ]);

        $search = $this->createMock(JSBDynamicSearchService::class);
        $search->method('search')->willReturn(['status' => ['code' => 200], 'data' => [], 'totalItems' => 0]);

        $portefeuille = new PortefeuilleCritereFactory($this->createMock(EntityManagerInterface::class));

        // La configuration du cabinet : le service est mocké comme les autres, l'axe ne
        // devant dépendre que de ce qu'il rend — jamais d'un accès à la base.
        $onboarding = $this->createMock(OnboardingCompletude::class);
        $onboarding->method('scoreSeul')->willReturn([
            'score' => $restantes === [] ? 100 : 45,
            'etapes' => $restantes,
            'restantes' => $restantes,
            'complet' => $restantes === [],
        ]);
        $onboarding->method('etapesACiter')->willReturn(array_slice($restantes, 0, 2));
        $onboarding->method('resteDuBloquant')->willReturn($bloquant);

        return new BoussoleService(
            $resolver,
            $saturation,
            $tranche,
            $fiscal,
            $search,
            $portefeuille,
            new ChargeInviteCritereFactory($portefeuille),
            $notes,
            $onboarding,
        );
    }

    private function axes(array $etat): array
    {
        return array_map(static fn (array $i): string => $i['axe'], $etat['items']);
    }

    public function testFailClosedAucunDroitAucunAxe(): void
    {
        $etat = $this->makeService([])->etat(new Entreprise(), new Invite());

        $this->assertSame([], $etat['items']);
        $this->assertNull($etat['prioritaire']);
    }

    public function testAxeSaturationSeulSiSeulDroitClient(): void
    {
        $etat = $this->makeService(['Client' => true], sousSatures: 4)->etat(new Entreprise(), new Invite());

        $this->assertSame(['saturation'], $this->axes($etat));
        $this->assertNotNull($etat['prioritaire']);
        $this->assertSame('saturation', $etat['prioritaire']['axe']);
    }

    public function testFiscalEstPrioritaireSurSaturation(): void
    {
        $etat = $this->makeService(
            ['Client' => true, 'DocumentComptable' => true],
            sousSatures: 3,
            soldeFiscal: 150.0,
        )->etat(new Entreprise(), new Invite());

        $this->assertContains('saturation', $this->axes($etat));
        $this->assertContains('fiscal', $this->axes($etat));
        // Fiscal (urgence 90) l'emporte sur saturation (50).
        $this->assertSame('fiscal', $etat['prioritaire']['axe']);
    }

    public function testFiscalAJourNestPasActionnable(): void
    {
        $etat = $this->makeService(['DocumentComptable' => true], soldeFiscal: 0.0)->etat(new Entreprise(), new Invite());

        $this->assertSame(['fiscal'], $this->axes($etat));
        $this->assertFalse($etat['items'][0]['actionnable']);
        $this->assertNull($etat['prioritaire']);
    }

    public function testFailSafeAxeQuiLeveEstIgnore(): void
    {
        // Le calcul fiscal lève, mais la saturation reste présente et le chat ne casse pas.
        $etat = $this->makeService(
            ['Client' => true, 'DocumentComptable' => true],
            sousSatures: 2,
            fiscalThrow: true,
        )->etat(new Entreprise(), new Invite());

        $this->assertSame(['saturation'], $this->axes($etat));
        $this->assertSame('saturation', $etat['prioritaire']['axe']);
    }

    /**
     * NON-RÉGRESSION : l'axe fiscal lit la TVA DU CABINET (CourtierSuiviFiscalService,
     * deux volets) et non celle de la plateforme Joseara (SuiviFiscalService, qui
     * ne prend aucune entreprise). Un solde nul côté cabinet doit laisser l'axe au
     * vert quoi qu'il arrive ailleurs — sinon le courtier verrait, sur l'axe le plus
     * urgent du barème, une dette qui ne lui appartient pas.
     */
    public function testAxeFiscalLitLeSuiviDuCabinet(): void
    {
        $etat = $this->makeService(['DocumentComptable' => true], soldeFiscal: 0.0)->etat(new Entreprise(), new Invite());

        $this->assertSame(['fiscal'], $this->axes($etat));
        $this->assertFalse($etat['items'][0]['actionnable']);
        $this->assertSame(0.0, $etat['items'][0]['montant']);
        $this->assertSame('Taxes à jour', $etat['items'][0]['libelle']);
    }

    public function testAxeFiscalNommeLaDetteDueSurLesPrimes(): void
    {
        $etat = $this->makeService(['DocumentComptable' => true], soldeFiscal: 320.5)->etat(new Entreprise(), new Invite());

        $this->assertTrue($etat['items'][0]['actionnable']);
        $this->assertSame(320.5, $etat['items'][0]['montant']);
        // Les deux mondes de taxes ne sont jamais confondus dans le libellé.
        $this->assertStringContainsString('taxe sur primes collectée', $etat['items'][0]['libelle']);
        $this->assertStringNotContainsString('commissions du courtier', $etat['items'][0]['libelle']);
    }

    public function testAxeRecouvrementNotesApparaitAvecLeDroitNote(): void
    {
        $etat = $this->makeService(['Note' => true], notesDues: 3)->etat(new Entreprise(), new Invite());

        $this->assertSame(['recouvrement_notes'], $this->axes($etat));
        $this->assertSame(3, $etat['items'][0]['compte']);
        $this->assertTrue($etat['items'][0]['actionnable']);
        // Périmètre cabinet : le libellé doit le dire, une note n'a pas de portefeuille.
        $this->assertStringContainsString('cabinet', $etat['items'][0]['libelle']);
    }

    public function testAxeRecouvrementEstPlusUrgentQueLesCommissionsExigibles(): void
    {
        $this->assertGreaterThan(
            BoussoleService::URGENCE['commissions'],
            BoussoleService::URGENCE['recouvrement_notes'],
            'Une commission déjà FACTURÉE et non encaissée passe avant une commission encore à facturer.',
        );
    }

    public function testAxeFeedbacksApparaitAvecLeDroitFeedback(): void
    {
        $etat = $this->makeService(['Feedback' => true])->etat(new Entreprise(), new Invite());

        $this->assertSame(['feedbacks'], $this->axes($etat));
        // Aucun feedback dû (moteur mocké à 0) : axe présent mais au vert.
        $this->assertSame(0, $etat['items'][0]['compte']);
        $this->assertFalse($etat['items'][0]['actionnable']);
    }

    public function testAxeFeedbacksAbsentSansLeDroit(): void
    {
        $etat = $this->makeService(['Tache' => true])->etat(new Entreprise(), new Invite());

        $this->assertSame(['taches'], $this->axes($etat));
    }

    // ---------------------------------------------- Configuration du cabinet

    /** Un cabinet dont il manque l'essentiel : la dette passe devant le devoir fiscal. */
    private function proprietaire(): Invite
    {
        return (new Invite())->setProprietaire(true);
    }

    /** @return array<int, array<string, mixed>> */
    private function etapesRestantes(): array
    {
        return [
            ['cle' => 'comptes_bancaires', 'libelle' => 'Comptes bancaires', 'bloc' => 'Finances', 'poids' => 3, 'fait' => false, 'nombre' => 0],
            ['cle' => 'jours_feries', 'libelle' => 'Jours fériés', 'bloc' => 'Administration', 'poids' => 1, 'fait' => false, 'nombre' => 0],
        ];
    }

    public function testConfigurationBloquantePasseDevantLeFiscal(): void
    {
        $etat = $this->makeService(
            ['DocumentComptable' => true],
            soldeFiscal: 500.0,
            restantes: $this->etapesRestantes(),
            bloquant: true,
        )->etat(new Entreprise(), $this->proprietaire());

        $this->assertContains('configuration', $this->axes($etat));
        $this->assertContains('fiscal', $this->axes($etat));
        // 95 contre 90 : on ne reverse pas proprement une taxe sur des commissions
        // qu'aucun compte bancaire ne permet d'encaisser.
        $this->assertSame('configuration', $etat['prioritaire']['axe']);
    }

    public function testConfigurationNommeCeQuiManque(): void
    {
        $etat = $this->makeService(
            [],
            restantes: $this->etapesRestantes(),
            bloquant: true,
        )->etat(new Entreprise(), $this->proprietaire());

        $this->assertStringContainsString('Comptes bancaires', $etat['items'][0]['libelle']);
        $this->assertStringContainsString('45 %', $etat['items'][0]['libelle']);
        $this->assertSame(2, $etat['items'][0]['compte']);
        // Une dette de configuration ne se chiffre pas en argent.
        $this->assertArrayNotHasKey('montant', $etat['items'][0]);
    }

    public function testConfigurationDeConfortRepasseDerriereLeFiscal(): void
    {
        $etat = $this->makeService(
            ['DocumentComptable' => true],
            soldeFiscal: 500.0,
            restantes: $this->etapesRestantes(),
            bloquant: false,
        )->etat(new Entreprise(), $this->proprietaire());

        // Plus rien ne bloque la production : 60 contre 90, le fiscal reprend la tête.
        $this->assertSame('fiscal', $etat['prioritaire']['axe']);
    }

    public function testConfigurationCompleteEstAuVert(): void
    {
        $etat = $this->makeService([], restantes: [])->etat(new Entreprise(), $this->proprietaire());

        $this->assertSame(['configuration'], $this->axes($etat));
        $this->assertFalse($etat['items'][0]['actionnable']);
        $this->assertNull($etat['prioritaire']);
    }

    public function testConfigurationInvisiblePourUnInvite(): void
    {
        // Configurer le cabinet n'est pas l'affaire d'un invité : le lui rappeler serait
        // lui demander ce qu'il ne peut pas faire.
        $etat = $this->makeService(
            [],
            restantes: $this->etapesRestantes(),
            bloquant: true,
        )->etat(new Entreprise(), new Invite());

        $this->assertSame([], $this->axes($etat));
    }
}
