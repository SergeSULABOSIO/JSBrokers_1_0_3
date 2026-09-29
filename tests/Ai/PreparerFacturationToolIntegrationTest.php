<?php

namespace App\Tests\Ai;

use App\Ai\Mutation\PlanEnAttente;
use App\Ai\Scope\AiScope;
use App\Ai\Tool\AiToolResult;
use App\Ai\Tool\PreparerFacturationTool;
use App\Entity\Assureur;
use App\Entity\Avenant;
use App\Entity\Chargement;
use App\Entity\ChargementPourPrime;
use App\Entity\Client;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Note;
use App\Entity\Piste;
use App\Entity\Portefeuille;
use App\Entity\RevenuPourCourtier;
use App\Entity\Tranche;
use App\Entity\TypeRevenu;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * « preparer_facturation » SUR LA VRAIE BASE : ce que les mocks ne peuvent pas prouver.
 *
 * ── LE CAS QUI A FAIT NAÎTRE CET OUTIL ──────────────────────────────────────
 * Une prime soldée, une commission devenue exigible, et l'écran qui l'affiche :
 * « Prime payée, commission due · Commission exigible — 11,60 USD ». Le courtier
 * demande le plan de la note de débit ; Ket répond qu'elle ne trouve « aucune
 * tranche de commission associée à cette police ». Ce test est la preuve que ce
 * n'est plus vrai — et il vérifie la chose exacte qui manquait : que la LIGNE de
 * la note existe, rattachée au bon revenu et à la bonne échéance.
 *
 * ── POURQUOI WebTestCase ────────────────────────────────────────────────────
 * La ligne de note passe par `ArticleType`, dont les champs d'autocomplétion
 * scopent sur l'utilisateur connecté (`getConnectedTo`). On se connecte donc comme
 * le fait le chat authentifié réel.
 *
 * ── ET RIEN N'EST ÉCRIT ─────────────────────────────────────────────────────
 * L'outil prépare, il ne persiste pas. Chaque cas se termine en comptant les notes
 * réellement présentes en base : zéro.
 */
class PreparerFacturationToolIntegrationTest extends WebTestCase
{
    private const OWNER_EMAIL = 'phpunit-facturation-owner@test.local';
    private const ENTREPRISE_NOM = 'PHPUnit Facturation SARL';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        self::ensureKernelShutdown(); // le kernel d'un test précédent peut être resté démarré.
        $this->client = static::createClient();
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

    private function outil(): PreparerFacturationTool
    {
        return static::getContainer()->get(PreparerFacturationTool::class);
    }

    private function cleanUp(): void
    {
        $conn = $this->em()->getConnection();
        $tables = [
            'article', 'note', 'revenu_pour_courtier', 'tranche', 'chargement_pour_prime',
            'avenant', 'cotation', 'piste', 'client', 'portefeuille', 'type_revenu',
            'chargement', 'assureur', 'invite',
        ];
        foreach ($tables as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :nom",
                ['nom' => self::ENTREPRISE_NOM],
            );
        }
        // Dénoue la FK circulaire utilisateur.connected_to_id ↔ entreprise.
        $conn->executeStatement('UPDATE utilisateur SET connected_to_id = NULL WHERE email = :email', ['email' => self::OWNER_EMAIL]);
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :nom', ['nom' => self::ENTREPRISE_NOM]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :email', ['email' => self::OWNER_EMAIL]);
    }

    /**
     * Une affaire complète et souscrite : prime de 1 000 assise sur la prime nette,
     * commission de 10 points jamais encaissée, une échéance unique à 100 %.
     *
     * @return array{entreprise: Entreprise, invite: Invite, tranche: Tranche, avenant: Avenant, revenuId: int, assureurId: int}
     */
    private function seed(): array
    {
        $em = $this->em();

        $owner = (new Utilisateur())
            ->setEmail(self::OWNER_EMAIL)
            ->setNom('PHPUnit Facturation')
            ->setVerified(true)
            ->setPassword('irrelevant');
        $em->persist($owner);

        $entreprise = (new Entreprise())
            ->setNom(self::ENTREPRISE_NOM)
            ->setLicence('LIC-FACT')
            ->setAdresse('1 rue des Notes')
            ->setTelephone('+243000000006')
            ->setRccm('RCCM-FACT')
            ->setIdnat('IDNAT-FACT')
            ->setNumimpot('IMP-FACT')
            ->setUtilisateur($owner);
        $em->persist($entreprise);
        $owner->setConnectedTo($entreprise);

        $invite = (new Invite())->setNom('Proprietaire FACT');
        $invite->setUtilisateur($owner)->setEntreprise($entreprise)->setProprietaire(true);
        $em->persist($invite);

        $assureur = (new Assureur())->setNom('Assureur FACT');
        $assureur->setEntreprise($entreprise);
        $em->persist($assureur);

        $portefeuille = (new Portefeuille())->setNom('Portefeuille FACT')->setGestionnaire($invite);
        $portefeuille->setEntreprise($entreprise);
        $em->persist($portefeuille);

        $client = (new Client())->setNom('Client FACT')->setExonere(false);
        $client->setEntreprise($entreprise)->setPortefeuille($portefeuille);
        $em->persist($client);

        $piste = (new Piste())
            ->setNom('Piste FACT')
            ->setTypeAvenant(Piste::AVENANT_SOUSCRIPTION)
            ->setDescriptionDuRisque('Risque de test facturation')
            ->setExercice((int) (new \DateTimeImmutable('now'))->format('Y'))
            ->setClient($client);
        $piste->setEntreprise($entreprise)->setInvite($invite);
        $em->persist($piste);

        $cotation = (new Cotation())->setNom('Cotation FACT')->setDuree(12);
        $cotation->setPiste($piste)->setAssureur($assureur);
        $cotation->setEntreprise($entreprise);
        $em->persist($cotation);

        // L'assiette : sans type de chargement, la commission reste à 0.
        $primeNette = (new Chargement())->setNom('Prime nette FACT')->setFonction(Chargement::FONCTION_PRIME_NETTE);
        $primeNette->setEntreprise($entreprise);
        $em->persist($primeNette);

        $chargement = (new ChargementPourPrime())
            ->setNom('Prime FACT')
            ->setMontantFlatExceptionel(1000.0)
            ->setType($primeNette)
            ->setCotation($cotation);
        $chargement->setEntreprise($entreprise);
        $em->persist($chargement);
        $cotation->addChargement($chargement);

        $typeRevenu = (new TypeRevenu())
            ->setNom('Commission FACT')
            ->setShared(false)
            ->setMultipayments(false)
            ->setTypeChargement($primeNette)
            ->setRedevable(TypeRevenu::REDEVABLE_ASSUREUR);
        $typeRevenu->setEntreprise($entreprise);
        $em->persist($typeRevenu);

        // 10 POINTS de la prime — convention du projet : tous les taux sont des points.
        $revenu = (new RevenuPourCourtier())->setNom('Revenu FACT')->setTauxExceptionel(10.0);
        $revenu->setCotation($cotation)->setTypeRevenu($typeRevenu);
        $revenu->setEntreprise($entreprise);
        $em->persist($revenu);
        $cotation->addRevenu($revenu);

        // Dates CALCULÉES : une date figée ferait mentir ce test le mois prochain.
        $avenant = (new Avenant())
            ->setDescription('Avenant FACT')
            ->setReferencePolice('POL-FACT-001')
            ->setStartingAt(new \DateTimeImmutable('-1 month'))
            ->setEndingAt(new \DateTimeImmutable('+11 months'))
            ->setCotation($cotation);
        $avenant->setEntreprise($entreprise);
        $em->persist($avenant);
        $cotation->addAvenant($avenant);

        $tranche = (new Tranche())
            ->setNom('Tranche FACT')
            ->setPourcentage(100.0)
            ->setPayableAt(new \DateTimeImmutable('-30 days'))
            ->setEcheanceAt(new \DateTimeImmutable('-5 days'));
        $tranche->setCotation($cotation);
        $tranche->setEntreprise($entreprise);
        $em->persist($tranche);
        $cotation->addTranche($tranche);

        $em->flush();

        $ids = [
            'entrepriseId' => $entreprise->getId(),
            'inviteId' => $invite->getId(),
            'trancheId' => $tranche->getId(),
            'avenantId' => $avenant->getId(),
            'revenuId' => $revenu->getId(),
            'assureurId' => $assureur->getId(),
            'ownerId' => $owner->getId(),
        ];
        $em->clear();

        $this->client->loginUser($em->getRepository(Utilisateur::class)->find($ids['ownerId']));

        return [
            'entreprise' => $em->getRepository(Entreprise::class)->find($ids['entrepriseId']),
            'invite' => $em->getRepository(Invite::class)->find($ids['inviteId']),
            'tranche' => $em->getRepository(Tranche::class)->find($ids['trancheId']),
            'avenant' => $em->getRepository(Avenant::class)->find($ids['avenantId']),
            'revenuId' => $ids['revenuId'],
            'assureurId' => $ids['assureurId'],
        ];
    }

    private function notesEnBase(): int
    {
        return (int) $this->em()->getRepository(Note::class)
            ->createQueryBuilder('n')->select('COUNT(n.id)')
            ->join('n.entreprise', 'e')->where('e.nom = :nom')->setParameter('nom', self::ENTREPRISE_NOM)
            ->getQuery()->getSingleScalarResult();
    }

    /**
     * LE CAS DE LA CAPTURE. Une échéance dont la commission reste due : l'outil doit
     * rendre un plan validable, avec la note ET sa ligne — c'est la ligne qui portait
     * tout le défaut, puisque c'est elle qui rattache le revenu à l'échéance.
     */
    public function testUneEcheanceDueProduitUnPlanAvecSaLigne(): void
    {
        $seed = $this->seed();

        $resultat = $this->outil()->execute(
            ['trancheId' => $seed['tranche']->getId()],
            new AiScope($seed['entreprise'], $seed['invite']),
        );

        self::assertSame(AiToolResult::STATUS_OK, $resultat->status);
        self::assertTrue($resultat->data['pret'] ?? false, 'Un plan prêt à valider doit être préparé.');
        self::assertSame(PlanEnAttente::ACTION_REVUE, $resultat->uiAction['type']);

        $operation = $resultat->uiAction['plan'][0];
        self::assertSame('Note', $operation['entite']);
        self::assertSame('create', $operation['op']);

        $champs = $operation['fields'];
        self::assertSame(Note::TYPE_NOTE_DE_DEBIT, $champs['type'], 'Réclamer une commission, c\'est un débit.');
        self::assertSame(Note::TO_ASSUREUR, $champs['addressedTo'], 'La commission est due par l\'assureur.');
        self::assertSame($seed['assureurId'], $champs['assureur']);
        self::assertStringContainsString('POL-FACT-001', (string) $champs['nom'],
            'L\'objet doit nommer la police : c\'est ce que lira l\'assureur.',
        );

        // LA LIGNE — ce qui manquait. Sans elle, la note serait vide et son total nul.
        $lignes = $operation['collections']['articles'] ?? [];
        self::assertCount(1, $lignes, 'Une ligne par revenu encore dû était attendue.');
        self::assertSame($seed['revenuId'], $lignes[0]['fields']['revenuFacture']);
        self::assertSame($seed['tranche']->getId(), $lignes[0]['fields']['tranche'],
            'La ligne doit être rattachée à l\'échéance facturée : c\'est à cette maille que le '
            . 'recouvrement se suit.',
        );

        self::assertSame(0, $this->notesEnBase(), 'Dry-run : rien ne doit être écrit.');
    }

    /** Le type et le destinataire dictés priment sur la déduction. */
    public function testUnAvoirAuClientEstPrepareQuandOnLeDemande(): void
    {
        $seed = $this->seed();

        $resultat = $this->outil()->execute(
            ['trancheId' => $seed['tranche']->getId(), 'type' => 'credit', 'destinataire' => 'client'],
            new AiScope($seed['entreprise'], $seed['invite']),
        );

        self::assertSame(AiToolResult::STATUS_OK, $resultat->status);
        self::assertTrue($resultat->data['pret'] ?? false);

        $champs = $resultat->uiAction['plan'][0]['fields'];
        self::assertSame(Note::TYPE_NOTE_DE_CREDIT, $champs['type']);
        self::assertSame(Note::TO_CLIENT, $champs['addressedTo']);
        self::assertArrayHasKey('client', $champs, 'Un avoir au client se rattache au client.');
        self::assertSame(0, $this->notesEnBase());
    }

    /** Facturer la police entière : la note porte les revenus, sans échéance imposée. */
    public function testUnePoliceEntiereSeFactureAussi(): void
    {
        $seed = $this->seed();

        $resultat = $this->outil()->execute(
            ['avenantId' => $seed['avenant']->getId()],
            new AiScope($seed['entreprise'], $seed['invite']),
        );

        self::assertSame(AiToolResult::STATUS_OK, $resultat->status);
        self::assertTrue($resultat->data['pret'] ?? false);

        $lignes = $resultat->uiAction['plan'][0]['collections']['articles'] ?? [];
        self::assertCount(1, $lignes);
        self::assertSame($seed['revenuId'], $lignes[0]['fields']['revenuFacture']);
        self::assertSame(0, $this->notesEnBase());
    }

    /**
     * FAIL-CLOSED SUR LE CABINET : une échéance qui n'est pas la nôtre est introuvable,
     * et rien n'est préparé. C'est la même garde que celle du champ d'écran, au même
     * endroit qu'elle doit être — dans l'outil.
     */
    public function testUneEcheanceDUnAutreCabinetEstIntrouvable(): void
    {
        $seed = $this->seed();

        $resultat = $this->outil()->execute(
            ['trancheId' => 999999999],
            new AiScope($seed['entreprise'], $seed['invite']),
        );

        self::assertSame(AiToolResult::STATUS_INTROUVABLE, $resultat->status);
        self::assertNull($resultat->uiAction);
        self::assertSame(0, $this->notesEnBase());
    }
}
