<?php

namespace App\Tests\Ai;

use App\Ai\Scope\AiScope;
use App\Ai\Tool\AiToolResult;
use App\Ai\Tool\CompterEntitesTool;
use App\Ai\Tool\RechercherEntitesTool;
use App\Entity\Avenant;
use App\Entity\ChargementPourPrime;
use App\Entity\Client;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Piste;
use App\Entity\Tranche;
use App\Entity\Utilisateur;
use App\Services\JSBDynamicSearchService;
use App\Services\Search\AvenantEcheanceScope;
use App\Services\Search\TranchePaiementScope;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * COHÉRENCE barre de chips (UI) ⇔ assistant IA (Ket).
 *
 * Un utilisateur qui clique un chip et un utilisateur qui pose la même question à Ket
 * doivent obtenir LE MÊME résultat. La garantie est structurelle : les outils génériques
 * de l'assistant (compter_entites / rechercher_entites) injectent le MÊME critère
 * synthétique que les chips (AvenantEcheanceScope / TranchePaiementScope) et traversent
 * donc le MÊME moteur (JSBDynamicSearchService). Ce test le vérifie de bout en bout, sur
 * les deux rubriques concernées, pour CHAQUE valeur de chip.
 */
class CoherenceChipsAssistantTest extends KernelTestCase
{
    private const OWNER_EMAIL = 'phpunit-coherence-ia@test.local';
    private const ENTREPRISE_NOM = 'PHPUnit Coherence IA SARL';

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

    private function service(): JSBDynamicSearchService
    {
        return static::getContainer()->get(JSBDynamicSearchService::class);
    }

    private function cleanUp(): void
    {
        $conn = $this->em()->getConnection();
        foreach (['avenant', 'tranche', 'chargement_pour_prime', 'cotation', 'piste', 'client', 'portefeuille', 'invite'] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :nom",
                ['nom' => self::ENTREPRISE_NOM]
            );
        }
        $conn->executeStatement("DELETE FROM entreprise WHERE nom = :nom", ['nom' => self::ENTREPRISE_NOM]);
        $conn->executeStatement("DELETE FROM utilisateur WHERE email = :email", ['email' => self::OWNER_EMAIL]);
    }

    /**
     * Avenants répartis dans les quatre fenêtres d'échéance + deux tranches (une échue
     * impayée, une à échoir impayée) sur une cotation à prime réelle.
     *
     * @return array{entreprise: Entreprise, invite: Invite}
     */
    private function seed(): array
    {
        $em = $this->em();

        $owner = new Utilisateur();
        $owner->setEmail(self::OWNER_EMAIL)->setNom('PHPUnit Coherence')->setVerified(true)->setPassword('irrelevant');
        $em->persist($owner);

        $entreprise = new Entreprise();
        $entreprise->setNom(self::ENTREPRISE_NOM)->setLicence('LIC-CO')->setAdresse('1 rue Cohérence')
            ->setTelephone('+243000000011')->setRccm('RCCM-CO')->setIdnat('IDNAT-CO')->setNumimpot('IMP-CO');
        $entreprise->setUtilisateur($owner);
        $em->persist($entreprise);

        // Propriétaire : contourne le fail-closed des droits (les outils IA sont fail-closed).
        $invite = new Invite();
        $invite->setNom('Propriétaire Cohérence')->setUtilisateur($owner)->setEntreprise($entreprise)->setProprietaire(true);
        $em->persist($invite);

        $client = (new Client())->setNom('Client Cohérence')->setExonere(false);
        $client->setEntreprise($entreprise);
        $em->persist($client);

        $piste = (new Piste())->setNom('Piste Cohérence')->setTypeAvenant(0)
            ->setDescriptionDuRisque('Risque cohérence')->setExercice(2026)
            ->setClient($client)->setEntreprise($entreprise)->setInvite($invite);
        $em->persist($piste);

        $cotation = (new Cotation())->setNom('Cotation Cohérence')->setDuree(365);
        $cotation->setPiste($piste);
        $cotation->setEntreprise($entreprise);
        $em->persist($cotation);

        $chargement = (new ChargementPourPrime())->setNom('Prime Cohérence')
            ->setMontantFlatExceptionel(1000.0)->setCotation($cotation);
        $chargement->setEntreprise($entreprise);
        $em->persist($chargement);

        // Un avenant par fenêtre d'échéance (échu / sous 30 j / 31-60 j / au-delà de 60 j).
        foreach ([['ECHU', '-10 days'], ['J10', '+10 days'], ['J45', '+45 days'], ['J90', '+90 days']] as [$ref, $delta]) {
            $fin = new \DateTimeImmutable($delta);
            $avenant = new Avenant();
            $avenant->setCotation($cotation)->setReferencePolice('POL-' . $ref)->setNumero('0')
                ->setDescription('Avenant ' . $ref)
                ->setStartingAt($fin->modify('-365 days'))->setEndingAt($fin);
            $avenant->setEntreprise($entreprise);
            $avenant->setInvite($invite);
            $em->persist($avenant);
        }

        // Deux tranches impayées : une échue, une à échoir (statut dérivé, filtre en mémoire).
        foreach ([['Tranche échue', 50, '-10 days'], ['Tranche à échoir', 0.5, '+10 days']] as [$nom, $pct, $delta]) {
            $tranche = (new Tranche())->setNom($nom)->setPourcentage($pct)
                ->setPayableAt(new \DateTimeImmutable('-60 days'))
                ->setEcheanceAt(new \DateTimeImmutable($delta));
            $tranche->setCotation($cotation);
            $tranche->setEntreprise($entreprise);
            $em->persist($tranche);
        }

        $em->flush();
        $entrepriseId = $entreprise->getId();
        $inviteId = $invite->getId();
        $em->clear();

        return [
            'entreprise' => $this->em()->getRepository(Entreprise::class)->find($entrepriseId),
            'invite' => $this->em()->getRepository(Invite::class)->find($inviteId),
        ];
    }

    private function compter(): CompterEntitesTool
    {
        return static::getContainer()->get(CompterEntitesTool::class);
    }

    private function rechercher(): RechercherEntitesTool
    {
        return static::getContainer()->get(RechercherEntitesTool::class);
    }

    /**
     * Pour CHAQUE chip de la rubrique Avenants : le compte affiché par la liste et le compte
     * annoncé par Ket doivent être identiques, et la liste de Ket doit restituer les mêmes
     * enregistrements dans le même ordre (tri par urgence).
     */
    public function testAvenantsChaqueChipCoincideAvecLAssistant(): void
    {
        ['entreprise' => $entreprise, 'invite' => $invite] = $this->seed();
        $scope = new AiScope($entreprise, $invite);

        foreach (array_keys(AvenantEcheanceScope::VALEURS) as $statut) {
            // Ce que la barre de chips affiche.
            $chip = $this->service()->search(
                Avenant::class,
                [AvenantEcheanceScope::CRITERION_KEY => $statut],
                $entreprise,
            );
            $idsChip = array_map(static fn (Avenant $a) => $a->getId(), $chip['data']);

            // Ce que Ket répond à « combien d'avenants … ? ».
            $compte = $this->compter()->execute(['entite' => 'Avenant', 'echeance' => $statut], $scope);
            $this->assertSame(AiToolResult::STATUS_OK, $compte->status, "Chip {$statut}");
            $this->assertSame(
                (int) $chip['totalItems'],
                $compte->data['count'],
                "Chip « {$statut} » : le comptage de Ket doit égaler celui de la rubrique."
            );

            // Ce que Ket répond à « quels avenants … ? ».
            $liste = $this->rechercher()->execute(['entite' => 'Avenant', 'echeance' => $statut], $scope);
            $this->assertSame(AiToolResult::STATUS_OK, $liste->status);
            $this->assertSame(
                $idsChip,
                array_column($liste->data['items'], 'id'),
                "Chip « {$statut} » : mêmes enregistrements, même ordre d'urgence."
            );
        }
    }

    /**
     * Idem pour la rubrique Tranches (statut de paiement dérivé, filtré/trié en mémoire) :
     * les outils génériques de Ket doivent passer par le même critère synthétique.
     */
    public function testTranchesChaqueChipCoincideAvecLAssistant(): void
    {
        ['entreprise' => $entreprise, 'invite' => $invite] = $this->seed();
        $scope = new AiScope($entreprise, $invite);

        foreach (array_keys(TranchePaiementScope::VALEURS) as $statut) {
            $chip = $this->service()->search(
                Tranche::class,
                [TranchePaiementScope::CRITERION_KEY => $statut],
                $entreprise,
            );
            $idsChip = array_map(static fn (Tranche $t) => $t->getId(), $chip['data']);

            $compte = $this->compter()->execute(['entite' => 'Tranche', 'statutPaiement' => $statut], $scope);
            $this->assertSame(AiToolResult::STATUS_OK, $compte->status, "Chip {$statut}");
            $this->assertSame(
                (int) $chip['totalItems'],
                $compte->data['count'],
                "Chip « {$statut} » : le comptage de Ket doit égaler celui de la rubrique."
            );

            $liste = $this->rechercher()->execute(['entite' => 'Tranche', 'statutPaiement' => $statut], $scope);
            $this->assertSame(
                $idsChip,
                array_column($liste->data['items'], 'id'),
                "Chip « {$statut} » : mêmes tranches, même ordre d'urgence."
            );
        }
    }

    /**
     * Sans filtre, le comportement historique est strictement conservé (non-régression) :
     * l'outil compte/liste toute la rubrique.
     */
    public function testSansFiltreComportementHistoriqueInchange(): void
    {
        ['entreprise' => $entreprise, 'invite' => $invite] = $this->seed();
        $scope = new AiScope($entreprise, $invite);

        $compte = $this->compter()->execute(['entite' => 'Avenant'], $scope);
        $this->assertSame(4, $compte->data['count'], 'Les 4 avenants de l\'entreprise.');
        $this->assertArrayNotHasKey('filtre', $compte->data, 'Aucun filtre annoncé quand aucun n\'est demandé.');

        $compteTranches = $this->compter()->execute(['entite' => 'Tranche'], $scope);
        $this->assertSame(2, $compteTranches->data['count']);

        // Une valeur inconnue est ignorée (pas d'erreur, pas de filtre appliqué).
        $compteInvalide = $this->compter()->execute(['entite' => 'Avenant', 'echeance' => 'valeur-inconnue'], $scope);
        $this->assertSame(4, $compteInvalide->data['count']);

        // Le filtre d'une rubrique ne fuit jamais vers une autre entité.
        $compteCroise = $this->compter()->execute(['entite' => 'Client', 'echeance' => AvenantEcheanceScope::STATUT_ECHUS], $scope);
        $this->assertSame(1, $compteCroise->data['count'], 'Le filtre échéance ne s\'applique qu\'aux avenants.');
    }

    /**
     * Moteur simulé : une question en langage naturel exprimant une fenêtre d'échéance ou un
     * statut de paiement doit produire l'argument de filtre correspondant — sans quoi Ket
     * répondrait sur la rubrique entière alors que l'utilisateur voit un chip actif.
     */
    public function testDetectionLangageNaturel(): void
    {
        $entreprise = new Entreprise();
        $scope = new AiScope($entreprise, new Invite());

        $cas = [
            "combien d'avenants échoient dans les 30 prochains jours ?" => ['echeance', AvenantEcheanceScope::STATUT_30J],
            'combien d\'avenants sont échus ?' => ['echeance', AvenantEcheanceScope::STATUT_ECHUS],
            'combien d\'avenants entre 31 et 60 jours ?' => ['echeance', AvenantEcheanceScope::STATUT_31_60J],
            'combien d\'avenants au-delà de 60 jours ?' => ['echeance', AvenantEcheanceScope::STATUT_60_PLUS],
            'combien de tranches impayées ?' => ['statutPaiement', TranchePaiementScope::STATUT_IMPAYEES],
            'combien de tranches payées ?' => ['statutPaiement', TranchePaiementScope::STATUT_PAYEES],
        ];

        foreach ($cas as $question => [$cle, $attendu]) {
            $args = $this->compter()->match($question, $scope);
            $this->assertIsArray($args, "Question non reconnue : {$question}");
            $this->assertSame($attendu, $args[$cle] ?? null, "Question : {$question}");
        }

        // Une question sans fenêtre exprimée ne pose AUCUN filtre (comptage global).
        $args = $this->compter()->match('combien d\'avenants ?', $scope);
        $this->assertArrayNotHasKey('echeance', $args);
    }
}
