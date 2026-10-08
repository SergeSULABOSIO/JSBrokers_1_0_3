<?php

namespace App\Tests\Workspace;

use App\Echange\Etat\MouvementDeReglement;
use App\Entity\Article;
use App\Entity\AutoriteFiscale;
use App\Entity\Avenant;
use App\Entity\Bordereau;
use App\Entity\Chargement;
use App\Entity\ChargementPourPrime;
use App\Entity\Client;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Note;
use App\Entity\Paiement;
use App\Entity\PaiementPrime;
use App\Entity\Partenaire;
use App\Entity\Piste;
use App\Entity\ReversementRetroAgent;
use App\Entity\RevenuPourCourtier;
use App\Entity\Taxe;
use App\Entity\Tranche;
use App\Entity\TypeRevenu;
use App\Entity\Utilisateur;
use App\Services\Canvas\Indicator\IndicatorCalculationHelper;
use App\Services\Tranche\ReleveDeTranche;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * LE PIED D'UN ONGLET DU RELEVÉ ÉGALE LE « PAYÉ » DE LA FICHE, AU CENTIME.
 *
 * Le relevé montre, ligne à ligne, ce qui a réglé une tranche. Si la somme de ses lignes
 * s'écartait de l'indicateur — celui de la fiche, de la barre des totaux et de Ket —,
 * le gestionnaire lirait deux vérités sur un même écran. Ce test tient l'égalité dans
 * les cas où elle se perd le plus facilement :
 *  - un règlement qui couvre PLUSIEURS tranches (la part, pas le brut) ;
 *  - l'arrondi : 33,333 × 3 donne 99,99 pour 100,00 — la dernière ligne reprend l'écart ;
 *  - une note sans montant facturable (aucune division par zéro, rien d'imputé) ;
 *  - un remboursement (montant négatif), compté dans la somme ;
 *  - le complément d'un bordereau, qui n'a pas de pièce derrière lui et le dit ;
 *  - les deux familles de rétrocession, séparées en XOR sur le même enregistrement ;
 *  - les taxes, chacune pour son redevable.
 */
class ReleveDeTranchePariteTest extends KernelTestCase
{
    private const OWNER_EMAIL = 'phpunit-releve-parite@test.local';
    private const ENT = 'PHPUnit Releve Parite SARL';

    private Entreprise $ent;
    private Invite $invite;

    protected function setUp(): void
    {
        static::bootKernel();
        $this->cleanUp();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        parent::tearDown();
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function releve(): ReleveDeTranche
    {
        return static::getContainer()->get(ReleveDeTranche::class);
    }

    private function cleanUp(): void
    {
        $conn = $this->em()->getConnection();
        $conn->executeStatement('UPDATE utilisateur SET connected_to_id = NULL WHERE email = :e', ['e' => self::OWNER_EMAIL]);
        foreach (['reversement_retro_agent', 'paiement_prime', 'paiement', 'article', 'note', 'bordereau', 'avenant', 'tranche',
                  'revenu_pour_courtier', 'type_revenu', 'chargement_pour_prime', 'chargement', 'cotation', 'piste', 'client', 'partenaire', 'autorite_fiscale', 'taxe', 'invite'] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :nom",
                ['nom' => self::ENT],
            );
        }
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :nom', ['nom' => self::ENT]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :e', ['e' => self::OWNER_EMAIL]);
        $this->em()->clear();
        // L'imputation des bordereaux est mémoïsée par cabinet : à vider entre deux semis.
        static::getContainer()->get(IndicatorCalculationHelper::class)->reset();
    }

    // ───────────────────────────── Semis ─────────────────────────────

    /**
     * Une affaire validée (avec avenant) et DEUX tranches de 50 % : la note de commission
     * porte un article sur la première et DEUX sur la seconde — la première reçoit donc
     * exactement le TIERS de chaque règlement.
     *
     * @return array{t1: Tranche, t2: Tranche, revenu: RevenuPourCourtier, avenant: Avenant}
     */
    private function semer(float $commission = 300.0, bool $affaireComplete = false): array
    {
        $em = $this->em();
        $owner = (new Utilisateur())->setEmail(self::OWNER_EMAIL)->setNom('Relevé')->setVerified(true)->setPassword('x');
        $em->persist($owner);
        $this->ent = (new Entreprise())->setNom(self::ENT)->setLicence('LIC')->setAdresse('1 rue')
            ->setTelephone('+2430000')->setRccm('R')->setIdnat('I')->setNumimpot('N')->setUtilisateur($owner);
        $em->persist($this->ent);
        $owner->setConnectedTo($this->ent);
        $this->invite = (new Invite())->setNom('Le Patron')->setProprietaire(true);
        $this->invite->setUtilisateur($owner)->setEntreprise($this->ent);
        $em->persist($this->invite);

        $client = (new Client())->setNom('Client Relevé')->setExonere(false)->setEntreprise($this->ent);
        $em->persist($client);
        $piste = (new Piste())->setNom('Piste Relevé')->setTypeAvenant(0)->setDescriptionDuRisque('Risque')
            ->setExercice(2026)->setClient($client)->setEntreprise($this->ent)->setInvite($this->invite);
        $em->persist($piste);
        $cotation = (new Cotation())->setNom('Cotation Relevé')->setDuree(365);
        $cotation->setPiste($piste)->setEntreprise($this->ent);
        $em->persist($cotation);

        // AFFAIRE COMPLÈTE : une prime réelle, une commission PARTAGEABLE avec le partenaire
        // de la piste (20 %), et les deux taxes sur commission — de quoi rendre exigibles,
        // au fil des règlements, la commission, les taxes et la rétrocession.
        if ($affaireComplete) {
            $partenaire = (new Partenaire())->setNom('Partenaire de la piste')->setPart(20.0);
            $partenaire->setEntreprise($this->ent);
            $em->persist($partenaire);
            $piste->setPartenaire($partenaire);
            $primeNette = (new Chargement())->setNom('Prime nette')->setFonction(Chargement::FONCTION_PRIME_NETTE);
            $primeNette->setEntreprise($this->ent);
            $em->persist($primeNette);
            $chargement = (new ChargementPourPrime())->setNom('Prime')->setMontantFlatExceptionel(10000.0)->setType($primeNette)->setCotation($cotation);
            $chargement->setEntreprise($this->ent);
            $em->persist($chargement);
            $cotation->addChargement($chargement);
            foreach ([Taxe::REDEVABLE_ASSUREUR => ['TVA', '16'], Taxe::REDEVABLE_COURTIER => ['ARCA', '2']] as $redevable => [$code, $taux]) {
                $taxe = (new Taxe())->setCode($code)->setDescription($code)->setTauxIARD($taux)->setTauxVIE($taux)->setRedevable($redevable);
                $taxe->setEntreprise($this->ent);
                $em->persist($taxe);
            }
        }

        $type = (new TypeRevenu())->setNom('Commission')->setMontantflat($commission)->setShared($affaireComplete)
            ->setMultipayments(true)->setRedevable(TypeRevenu::REDEVABLE_ASSUREUR);
        $type->setEntreprise($this->ent);
        $em->persist($type);
        $revenu = (new RevenuPourCourtier())->setNom('Revenu')->setTypeRevenu($type);
        $revenu->setEntreprise($this->ent);
        $cotation->addRevenu($revenu);
        $em->persist($revenu);

        $t1 = $this->tranche($cotation, 'T1');
        $t2 = $this->tranche($cotation, 'T2');

        $avenant = (new Avenant())->setReferencePolice('POL-REL')->setNumero('0')->setDescription('Avenant')
            ->setStartingAt(new \DateTimeImmutable('-60 days'))->setEndingAt(new \DateTimeImmutable('+305 days'));
        $avenant->setEntreprise($this->ent)->setInvite($this->invite);
        $cotation->addAvenant($avenant);
        $em->persist($avenant);
        $em->flush();

        return ['t1' => $t1, 't2' => $t2, 'revenu' => $revenu, 'avenant' => $avenant];
    }

    private function tranche(Cotation $cotation, string $nom): Tranche
    {
        $t = (new Tranche())->setNom($nom)->setPourcentage(50.0)
            ->setPayableAt(new \DateTimeImmutable('-30 days'))->setEcheanceAt(new \DateTimeImmutable('-5 days'));
        $t->setEntreprise($this->ent);
        $cotation->addTranche($t);
        $this->em()->persist($t);

        return $t;
    }

    private function note(int $destinataire, string $ref, ?AutoriteFiscale $autorite = null): Note
    {
        $note = (new Note())->setNom($ref)->setReference($ref)->setType(Note::TYPE_NOTE_DE_DEBIT)
            ->setAddressedTo($destinataire)->setValidated(true)->setSignature('sig');
        $note->setEntreprise($this->ent)->setInvite($this->invite);
        if ($autorite !== null) {
            $note->setAutoritefiscale($autorite);
        }
        $this->em()->persist($note);

        return $note;
    }

    private function article(Note $note, Tranche $tranche, ?RevenuPourCourtier $revenu, float $quantite = 1.0): void
    {
        $article = (new Article())->setQuantite($quantite)->setRevenuFacture($revenu);
        $article->setEntreprise($this->ent);
        $tranche->addArticle($article);
        $note->addArticle($article);
        $this->em()->persist($article);
    }

    private function paiement(Note $note, float $montant, string $ref): void
    {
        $p = (new Paiement())->setMontant($montant)->setPaidAt(new \DateTimeImmutable('-2 days'))->setReference($ref);
        $p->setEntreprise($this->ent);
        $note->addPaiement($p);
        $this->em()->persist($p);
    }

    /** @return array<string, array> les blocs du relevé, indexés par leur clé */
    private function blocs(Tranche $tranche, string $famille): array
    {
        $blocs = [];
        foreach ($this->releve()->pour($tranche, $famille) as $bloc) {
            $blocs[$bloc['cle']] = $bloc;
        }

        return $blocs;
    }

    /** @param list<MouvementDeReglement> $lignes */
    private function somme(array $lignes): float
    {
        return round(array_sum(array_map(static fn (MouvementDeReglement $l) => $l->montant, $lignes)), 2);
    }

    // ───────────────────────────── Commission ─────────────────────────────

    /**
     * Trois règlements de 100 sur une note dont la tranche porte le tiers : chaque part
     * vaut 33,333…, arrondie à 33,33, et trois fois 33,33 font 99,99. L'indicateur, lui,
     * dit 100,00 : la dernière ligne reprend le centime.
     */
    public function testLaDerniereLigneAbsorbeLEcartDArrondi(): void
    {
        ['t1' => $t1, 't2' => $t2, 'revenu' => $revenu] = $this->semer();
        $note = $this->note(Note::TO_ASSUREUR, 'ND-ARR');
        $this->article($note, $t1, $revenu);
        $this->article($note, $t2, $revenu, 2.0);
        foreach (['V1', 'V2', 'V3'] as $ref) {
            $this->paiement($note, 100.0, $ref);
        }
        $this->em()->flush();

        $bloc = $this->blocs($t1, 'commission')['commission'];

        self::assertCount(3, $bloc['lignes']);
        self::assertSame(100.0, round((float) $t1->montant_paye, 2), 'L\'indicateur : le tiers de 300.');
        self::assertSame(100.0, $this->somme($bloc['lignes']), 'Σ lignes = indicateur, au centime.');
        self::assertSame(100.0, $bloc['total']);
        self::assertSame([33.33, 33.33, 33.34], array_map(static fn ($l) => $l->montant, $bloc['lignes']), 'Seule la dernière ligne porte l\'écart.');
        self::assertSame(100.0, $bloc['lignes'][0]->montantBrut, 'Le règlement entier reste lisible à côté de la part.');
    }

    /** Un remboursement est un montant négatif : il se montre signé et il compte. */
    public function testUnRemboursementNegatifEstCompteDansLaSomme(): void
    {
        ['t1' => $t1, 't2' => $t2, 'revenu' => $revenu] = $this->semer();
        $note = $this->note(Note::TO_ASSUREUR, 'ND-NEG');
        $this->article($note, $t1, $revenu);
        $this->article($note, $t2, $revenu, 2.0);
        $this->paiement($note, 300.0, 'V1');
        $this->paiement($note, -30.0, 'REMB');
        $this->em()->flush();

        $bloc = $this->blocs($t1, 'commission')['commission'];

        self::assertSame(90.0, round((float) $t1->montant_paye, 2));
        self::assertSame(90.0, $this->somme($bloc['lignes']));
        self::assertContains(-10.0, array_map(static fn ($l) => $l->montant, $bloc['lignes']), 'La part du remboursement est négative.');
    }

    /**
     * Une note sans montant facturable : aucune division par zéro, rien d'imputé — le
     * calcul l'ignore, la ligne le dit, la somme n'en est pas faussée.
     */
    public function testUneNoteSansMontantFacturableNImputeRien(): void
    {
        ['t1' => $t1, 'revenu' => $revenu] = $this->semer();
        $vide = (new TypeRevenu())->setNom('Gratuit')->setMontantflat(0.0)->setShared(false)
            ->setMultipayments(true)->setRedevable(TypeRevenu::REDEVABLE_ASSUREUR);
        $vide->setEntreprise($this->ent);
        $this->em()->persist($vide);
        $revenuNul = (new RevenuPourCourtier())->setNom('Revenu nul')->setTypeRevenu($vide);
        $revenuNul->setEntreprise($this->ent);
        $t1->getCotation()->addRevenu($revenuNul);
        $this->em()->persist($revenuNul);

        $note = $this->note(Note::TO_ASSUREUR, 'ND-ZERO');
        $this->article($note, $t1, $revenuNul);
        $this->paiement($note, 50.0, 'V-ZERO');
        $this->em()->flush();

        $bloc = $this->blocs($t1, 'commission')['commission'] ?? null;

        self::assertNotNull($bloc, 'Le bloc se montre : il porte une ligne.');
        self::assertCount(1, $bloc['lignes']);
        self::assertSame(0.0, $bloc['lignes'][0]->montant);
        self::assertFalse($bloc['lignes'][0]->imputable);
        self::assertSame(round((float) $t1->montant_paye, 2), $this->somme($bloc['lignes']));
    }

    /**
     * Encaissée par un BORDEREAU dont la note ne porte aucun article : aucune pièce n'est
     * attachée à la tranche. Le complément se montre — inféré, et nommé comme tel.
     */
    public function testLeComplementDUnBordereauEstUneLigneInferee(): void
    {
        ['t1' => $t1, 'avenant' => $avenant] = $this->semer();
        $bordereau = (new Bordereau())->setType(Bordereau::TYPE_BOREDERAU_PRODUCTION)
            ->setNom('Bordereau')->setReference('BRD-07')
            ->setReceivedAt(new \DateTimeImmutable('-10 days'))
            ->setPeriodeDebut(new \DateTimeImmutable('-40 days'))->setPeriodeFin(new \DateTimeImmutable('-10 days'))
            ->setMontantComHtPayableNow(300.0)->setMontantTaxePayableNow(0.0)
            ->setAnalysisResults([['type' => 'match', 'row_index' => 0, 'reference_police' => 'POL-REL', 'avenant_id' => $avenant->getId()]])
            ->setInvite($this->invite)->setEntreprise($this->ent);
        $this->em()->persist($bordereau);
        $note = $this->note(Note::TO_ASSUREUR, 'ND-BRD');
        $bordereau->addNote($note);
        $this->paiement($note, 300.0, 'VIR-BRD');
        $this->em()->flush();

        $bloc = $this->blocs($t1, 'commission')['commission'];

        self::assertGreaterThan(0.0, (float) $t1->montant_paye, 'Le bordereau a fait rentrer de l\'argent sur la tranche.');
        self::assertSame(round((float) $t1->montant_paye, 2), $this->somme($bloc['lignes']));
        $inferees = array_filter($bloc['lignes'], static fn (MouvementDeReglement $l) => $l->estInferee());
        self::assertCount(1, $inferees, 'Une seule ligne de complément, signalée comme inférée.');
        self::assertStringContainsString('BRD-07', (string) array_values($inferees)[0]->reference);
    }

    // ───────────────────────────── Prime ─────────────────────────────

    /** Signalement + facture client : deux circuits, une somme, celle de l'indicateur. */
    public function testLaPrimeAdditionneSignalementsEtFacturesClient(): void
    {
        ['t1' => $t1, 't2' => $t2, 'revenu' => $revenu] = $this->semer();
        $signalement = (new PaiementPrime())->setPaidAt(new \DateTimeImmutable('-4 days'))->setMontant(120.0)->setReference('PP-1');
        $signalement->setEntreprise($this->ent);
        $t1->addPaiementsPrime($signalement);
        $this->em()->persist($signalement);
        $note = $this->note(Note::TO_CLIENT, 'NC-1');
        $this->article($note, $t1, $revenu);
        $this->article($note, $t2, $revenu, 2.0);
        $this->paiement($note, 90.0, 'VC-1');
        $this->em()->flush();

        $bloc = $this->blocs($t1, 'prime')['prime'];

        self::assertGreaterThan(120.0, (float) $t1->primePayee, 'Signalement ET facture client : plus que le seul signalement.');

        self::assertSame(round((float) $t1->primePayee, 2), $this->somme($bloc['lignes']));
        $natures = array_map(static fn ($l) => $l->nature, $bloc['lignes']);
        self::assertContains(MouvementDeReglement::NATURE_SIGNALEMENT, $natures);
        self::assertContains(MouvementDeReglement::NATURE_FACTURE, $natures);
        $ligneSignalement = array_values(array_filter($bloc['lignes'], static fn ($l) => $l->pieceEntite === 'PaiementPrime'))[0];
        self::assertSame($signalement->getId(), $ligneSignalement->pieceId, 'Le signalement porte son id : il se corrige depuis sa ligne.');
    }

    // ───────────────────────────── Rétrocommissions ─────────────────────────────

    /** Un versement d'agent ne paraît jamais chez le partenaire, et inversement. */
    public function testLesDeuxFamillesDeRetroSontSepareesEnXor(): void
    {
        ['t1' => $t1] = $this->semer();
        $agent = (new Invite())->setNom('Agent interne')->setProprietaire(false);
        $agent->setEntreprise($this->ent);
        $this->em()->persist($agent);
        $partenaire = (new Partenaire())->setNom('Partenaire SUNU')->setPart(20.0);
        $partenaire->setEntreprise($this->ent);
        $this->em()->persist($partenaire);

        foreach ([[$agent, null, 30.0, 'VA'], [null, $partenaire, 20.0, 'VP']] as [$a, $p, $montant, $ref]) {
            $r = (new ReversementRetroAgent())->setMontant($montant)->setPaidAt(new \DateTimeImmutable('-1 day'))->setReference($ref);
            $r->setAgent($a)->setPartenaire($p)->setTranche($t1);
            $r->setEntreprise($this->ent);
            $this->em()->persist($r);
        }
        $this->em()->flush();
        $this->em()->refresh($t1);

        $blocs = $this->blocs($t1, 'retrocommission');

        self::assertSame(20.0, $this->somme($blocs['retro-partenaire']['lignes']));
        self::assertSame(round((float) $t1->retroCommissionReversee, 2), $this->somme($blocs['retro-partenaire']['lignes']));
        self::assertSame(30.0, $this->somme($blocs['retro-agent']['lignes']));
        self::assertSame(round((float) $t1->retroAgentReversee, 2), $this->somme($blocs['retro-agent']['lignes']));

        // Aucun partage n'est paramétré : rien n'était dû. Le bloc se montre quand même
        // (il porte un versement), et il NOMME l'excédent — jamais « Solde 0,00 » (réglé).
        $soldes = array_column($blocs['retro-partenaire']['chiffres'], 'valeur', 'libelle');
        self::assertArrayNotHasKey('Solde', $soldes);
        self::assertSame(20.0, $soldes['Trop-versé'] ?? null, 'Un versement sans dû se lit « Trop-versé 20,00 ».');
    }

    // ───────────────────────────── Taxes ─────────────────────────────

    /** Chaque taxe ne compte que les notes fiscales de SON redevable. */
    public function testChaqueTaxeNeCompteQueSonRedevable(): void
    {
        ['t1' => $t1, 'revenu' => $revenu] = $this->semer();
        $autorites = [];
        foreach ([Taxe::REDEVABLE_ASSUREUR => 'TVA', Taxe::REDEVABLE_COURTIER => 'ARCA'] as $redevable => $code) {
            $taxe = (new Taxe())->setCode($code)->setDescription($code)->setTauxIARD('16')->setTauxVIE('16')->setRedevable($redevable);
            $taxe->setEntreprise($this->ent);
            $this->em()->persist($taxe);
            $autorite = (new AutoriteFiscale())->setNom('Autorité ' . $code)->setAbreviation($code)->setTaxe($taxe);
            $autorite->setEntreprise($this->ent);
            $this->em()->persist($autorite);
            $autorites[$redevable] = $autorite;
        }
        $tva = $this->note(Note::TO_AUTORITE_FISCALE, 'NF-TVA', $autorites[Taxe::REDEVABLE_ASSUREUR]);
        $this->article($tva, $t1, $revenu);
        $this->paiement($tva, 25.0, 'VT-1');
        $arca = $this->note(Note::TO_AUTORITE_FISCALE, 'NF-ARCA', $autorites[Taxe::REDEVABLE_COURTIER]);
        $this->article($arca, $t1, $revenu);
        $this->paiement($arca, 4.0, 'VT-2');
        $this->em()->flush();

        $blocs = $this->blocs($t1, 'taxe');

        self::assertGreaterThan(0.0, (float) $t1->taxeAssureurPayee, 'Le cas compare de vrais montants, pas deux zéros.');
        self::assertGreaterThan(0.0, (float) $t1->taxeCourtierPayee);

        self::assertSame(round((float) $t1->taxeAssureurPayee, 2), $this->somme($blocs['taxe-assureur']['lignes']));
        self::assertSame(round((float) $t1->taxeCourtierPayee, 2), $this->somme($blocs['taxe-courtier']['lignes']));
        self::assertSame(
            ['Note NF-TVA'],
            array_values(array_unique(array_map(static fn ($l) => $l->libelle, $blocs['taxe-assureur']['lignes']))),
            'La note de l\'autre redevable n\'y paraît pas.',
        );
    }

    // ─────────────── Rafraîchissement (B4) : ce que dit la fiche RECHARGÉE ───────────────

    /** Les chiffres d'un bloc, par libellé, relus sur une tranche fraîche — comme la fiche rechargée. */
    private function chiffresFrais(int $trancheId, string $famille, string $bloc): array
    {
        $this->em()->clear();
        static::getContainer()->get(IndicatorCalculationHelper::class)->reset();
        $tranche = $this->em()->getRepository(Tranche::class)->find($trancheId);

        // LE CABINET DES TAXES EST CELUI DE L'UTILISATEUR CONNECTÉ (ServiceTaxes::getMontantTaxe
        // sans entreprise explicite) : sans session, toute taxe due vaudrait 0, et le test
        // comparerait deux zéros. On connecte le propriétaire, comme le ferait la requête.
        $owner = $this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => self::OWNER_EMAIL]);
        static::getContainer()->get('security.token_storage')->setToken(
            new \Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken($owner, 'main', $owner->getRoles()),
        );

        return array_column($this->blocs($tranche, $famille)[$bloc]['chiffres'] ?? [], 'valeur', 'libelle');
    }

    /**
     * UN NOUVEAU SIGNALEMENT qui solde la prime rend la commission EXIGIBLE : c'est l'onglet
     * Commission — pas seulement l'onglet Prime — qui change. D'où le rechargement de la
     * fiche entière (app:fiche.modifiee), et non du seul onglet d'où part le geste.
     */
    public function testUnNouveauSignalementRendLaCommissionExigible(): void
    {
        ['t1' => $t1] = $this->semer(300.0, true);
        $id = $t1->getId();
        $avant = $this->chiffresFrais($id, 'commission', 'commission');
        self::assertGreaterThan(0.0, $avant['Due']);
        self::assertSame(0.0, $avant['Exigible'], 'Prime impayée : rien d\'exigible.');

        $prime = (float) $this->chiffresFrais($id, 'prime', 'prime')['Prime due'];
        self::assertGreaterThan(0.0, $prime);
        $tranche = $this->em()->getRepository(Tranche::class)->find($id);
        $signalement = (new PaiementPrime())->setPaidAt(new \DateTimeImmutable('-1 day'))->setMontant($prime)->setReference('PP-SOLDE');
        $signalement->setEntreprise($tranche->getEntreprise());
        $tranche->addPaiementsPrime($signalement);
        $this->em()->persist($signalement);
        $this->em()->flush();

        $apres = $this->chiffresFrais($id, 'commission', 'commission');
        self::assertSame($avant['Due'], $apres['Exigible'], 'Prime soldée : toute la commission devient exigible.');
        self::assertSame(0.0, $this->chiffresFrais($id, 'prime', 'prime')['Solde']);
    }

    /**
     * UN ENCAISSEMENT DE COMMISSION rend exigibles une part des TAXES et de la
     * RÉTROCESSION, au prorata (Exigibilite) : deux autres onglets que celui de la
     * commission.
     */
    public function testUnEncaissementRendTaxesEtRetrosExigibles(): void
    {
        ['t1' => $t1, 'revenu' => $revenu] = $this->semer(300.0, true);
        $id = $t1->getId();
        $revenuId = $revenu->getId();
        $taxeAvant = $this->chiffresFrais($id, 'taxe', 'taxe-assureur');
        $retroAvant = $this->chiffresFrais($id, 'retrocommission', 'retro-partenaire');
        self::assertGreaterThan(0.0, $taxeAvant['Due'] ?? 0.0, 'La TVA sur commission est due.');
        self::assertGreaterThan(0.0, $retroAvant['Due'] ?? 0.0, 'La rétrocession du partenaire est due.');
        self::assertSame(0.0, $taxeAvant['Exigible']);
        self::assertSame(0.0, $retroAvant['Exigible']);

        $tranche = $this->em()->getRepository(Tranche::class)->find($id);
        $revenu = $this->em()->getRepository(RevenuPourCourtier::class)->find($revenuId);
        $this->ent = $tranche->getEntreprise();
        $this->invite = $this->em()->getRepository(Invite::class)->findOneBy(['entreprise' => $this->ent, 'proprietaire' => true]);
        $note = $this->note(Note::TO_ASSUREUR, 'ND-ENC');
        $this->article($note, $tranche, $revenu);
        $this->paiement($note, 100.0, 'VIR-ENC');
        $this->em()->flush();

        $taxeApres = $this->chiffresFrais($id, 'taxe', 'taxe-assureur');
        $retroApres = $this->chiffresFrais($id, 'retrocommission', 'retro-partenaire');
        self::assertGreaterThan(0.0, $taxeApres['Exigible'], 'L\'encaissement rend une part de la TVA exigible.');
        self::assertGreaterThan(0.0, $retroApres['Exigible'], 'L\'encaissement rend une part de la rétrocession exigible.');
        self::assertLessThan($taxeApres['Due'], $taxeApres['Exigible'], 'Au prorata : un encaissement partiel n\'en rend exigible qu\'une part.');
    }
}
