<?php

namespace App\Tests\Ai;

use App\Ai\Scope\AiScope;
use App\Ai\Tool\AiToolResult;
use App\Ai\Tool\LireSoaTool;
use App\Entity\Assureur;
use App\Entity\Avenant;
use App\Entity\ChargementPourPrime;
use App\Entity\Client;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Piste;
use App\Entity\RolesEnProduction;
use App\Entity\Tranche;
use App\Entity\Utilisateur;
use App\Service\Soa\SoaContextBuilder;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * `lire_soa` SUR DE VRAIES DONNÉES : LES MÊMES CHIFFRES QUE L'ÉCRAN, ET RIEN DE PLUS
 * QUE SON PÉRIMÈTRE.
 *
 * Le test unitaire voisin (`LireSoaToolTest`) vérifie les contrats de forme —
 * largeur des tableaux, sections, non-retours de formule dans les gabarits. Ici on
 * monte une chaîne réelle (client → piste → cotation → police → tranche) et on
 * confronte la réponse de l'outil au service que les trois écrans du relevé
 * utilisent.
 *
 * ── CE QUE CE TEST PROTÈGE ───────────────────────────────────────────────────────
 *  - L'IDENTITÉ DES CHIFFRES. La réponse de Ket est comparée à `SoaContextBuilder`,
 *    pas à des valeurs recopiées dans le test : si l'outil se mettait à recalculer
 *    « payé » et « solde » de son côté, l'écart apparaîtrait ici. C'est le cœur de
 *    la dette payée par ce lot — un relevé est une pièce qu'on montre au client, et
 *    deux soldes différents sur le même compte sont une faute, pas un détail.
 *  - LE FAIL-CLOSED. Un invité sans lecture sur les Clients doit être refusé, et un
 *    client d'un AUTRE cabinet doit rester introuvable — l'outil est atteignable par
 *    une phrase, donc par une tentative.
 */
class LireSoaToolIntegrationTest extends KernelTestCase
{
    private const OWNER_EMAIL = 'phpunit-soa-owner@test.local';
    private const ENTREPRISE_NOM = 'PHPUnit SOA SARL';
    private const ENTREPRISE_B_NOM = 'PHPUnit SOA Autre SARL';
    private const CLIENT_NOM = 'Client SOA Kinshasa';
    private const PRIME = 1200.0;

    protected function setUp(): void
    {
        self::bootKernel();
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

    private function outil(): LireSoaTool
    {
        return static::getContainer()->get(LireSoaTool::class);
    }

    private function cleanUp(): void
    {
        $conn = $this->em()->getConnection();
        $noms = [self::ENTREPRISE_NOM, self::ENTREPRISE_B_NOM];
        $params = ['noms' => $noms];
        $types = ['noms' => ArrayParameterType::STRING];

        $conn->executeStatement(
            'UPDATE utilisateur SET connected_to_id = NULL WHERE email = :email',
            ['email' => self::OWNER_EMAIL],
        );

        // Des feuilles vers la racine : chaque table porte la clé étrangère de la
        // précédente, et MySQL refuse l'inverse.
        foreach ([
            'tranche', 'chargement_pour_prime', 'avenant', 'cotation', 'piste',
            'roles_en_production', 'client', 'assureur',
        ] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom IN (:noms)",
                $params,
                $types,
            );
        }
        $conn->executeStatement(
            'DELETE i FROM invite i JOIN entreprise e ON i.entreprise_id = e.id WHERE e.nom IN (:noms)',
            $params,
            $types,
        );
        $conn->executeStatement('DELETE FROM entreprise WHERE nom IN (:noms)', $params, $types);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :email', ['email' => self::OWNER_EMAIL]);
    }

    private function makeEntreprise(string $nom, Utilisateur $owner): Entreprise
    {
        $entreprise = (new Entreprise())
            ->setNom($nom)
            ->setLicence('LIC-SOA')
            ->setAdresse('1 avenue du Relevé')
            ->setTelephone('+243000000000')
            ->setRccm('RCCM-SOA')
            ->setIdnat('IDNAT-SOA')
            ->setNumimpot('IMP-SOA');
        $entreprise->setUtilisateur($owner);
        $this->em()->persist($entreprise);

        return $entreprise;
    }

    /**
     * Un cabinet, son propriétaire, un collaborateur SANS aucun droit, et une chaîne
     * complète client → piste → cotation → police → tranche portant une prime.
     * Un second cabinet avec son propre client sert à prouver le cloisonnement.
     *
     * @return array{entreprise: Entreprise, proprietaire: Invite, sansDroit: Invite, client: Client, entrepriseB: Entreprise, inviteB: Invite, clientB: Client}
     */
    private function seed(): array
    {
        $em = $this->em();

        $owner = (new Utilisateur())
            ->setEmail(self::OWNER_EMAIL)
            ->setNom('PHPUnit SOA')
            ->setVerified(true)
            ->setPassword('irrelevant');
        $em->persist($owner);

        $entreprise = $this->makeEntreprise(self::ENTREPRISE_NOM, $owner);
        $owner->setConnectedTo($entreprise);

        $proprietaire = (new Invite())->setNom('Propriétaire SOA');
        $proprietaire->setUtilisateur($owner)->setEntreprise($entreprise)->setProprietaire(true);
        $em->persist($proprietaire);

        // Collaborateur SANS aucun rôle : le fail-closed doit le refuser.
        $sansDroit = (new Invite())->setNom('Collaborateur sans droit');
        $sansDroit->setEntreprise($entreprise)->setProprietaire(false);
        $em->persist($sansDroit);

        $client = $this->makeChaine($entreprise, $proprietaire, self::CLIENT_NOM, 'A', self::PRIME);

        $entrepriseB = $this->makeEntreprise(self::ENTREPRISE_B_NOM, $owner);
        $inviteB = (new Invite())->setNom('Propriétaire B');
        $inviteB->setUtilisateur($owner)->setEntreprise($entrepriseB)->setProprietaire(true);
        $em->persist($inviteB);
        $clientB = $this->makeChaine($entrepriseB, $inviteB, self::CLIENT_NOM . ' Bis', 'B', 500.0);

        $em->flush();
        $em->clear(); // EM partagé : on repart d'entités fraîches.

        return [
            'entreprise'   => $em->getRepository(Entreprise::class)->find($entreprise->getId()),
            'proprietaire' => $em->getRepository(Invite::class)->find($proprietaire->getId()),
            'sansDroit'    => $em->getRepository(Invite::class)->find($sansDroit->getId()),
            'client'       => $em->getRepository(Client::class)->find($client->getId()),
            'entrepriseB'  => $em->getRepository(Entreprise::class)->find($entrepriseB->getId()),
            'inviteB'      => $em->getRepository(Invite::class)->find($inviteB->getId()),
            'clientB'      => $em->getRepository(Client::class)->find($clientB->getId()),
        ];
    }

    /** Client → piste → cotation (assureur + prime) → police → tranche. */
    private function makeChaine(Entreprise $entreprise, Invite $invite, string $nomClient, string $suffixe, float $prime): Client
    {
        $em = $this->em();

        $client = (new Client())->setNom($nomClient)->setExonere(false);
        $client->setEntreprise($entreprise);
        $em->persist($client);

        $piste = (new Piste())
            ->setNom('Piste SOA ' . $suffixe)
            ->setTypeAvenant(0)
            ->setDescriptionDuRisque('Risque de test du relevé de compte')
            ->setExercice((int) date('Y'))
            ->setClient($client);
        $piste->setEntreprise($entreprise)->setInvite($invite);
        $em->persist($piste);

        $assureur = (new Assureur())
            ->setNom('Assureur SOA ' . $suffixe)
            ->setEmail(sprintf('assureur-soa-%s@example.test', strtolower($suffixe)))
            ->setNumimpot('IMP-SOA-' . $suffixe)
            ->setIdnat('NAT-SOA-' . $suffixe)
            ->setRccm('RCCM-SOA-' . $suffixe);
        $assureur->setEntreprise($entreprise);
        $em->persist($assureur);

        $cotation = (new Cotation())->setNom('Cotation SOA ' . $suffixe)->setDuree(365);
        $cotation->setPiste($piste)->setAssureur($assureur);
        $cotation->setEntreprise($entreprise);
        $em->persist($cotation);

        $avenant = (new Avenant())
            ->setReferencePolice('POL-SOA-' . $suffixe)
            ->setNumero('0')
            ->setDescription('Police de test du relevé')
            ->setStartingAt(new \DateTimeImmutable('-60 days'))
            ->setEndingAt(new \DateTimeImmutable('+305 days'));
        $avenant->setEntreprise($entreprise)->setInvite($invite);
        $cotation->addAvenant($avenant);
        $em->persist($avenant);

        $chargement = (new ChargementPourPrime())
            ->setNom('Prime SOA ' . $suffixe)
            ->setMontantFlatExceptionel($prime)
            ->setCotation($cotation);
        $chargement->setEntreprise($entreprise);
        $em->persist($chargement);

        $tranche = (new Tranche())
            ->setNom('Tranche unique ' . $suffixe)
            ->setPourcentage(100.0) // 100 % en POINTS (convention du projet)
            ->setPayableAt(new \DateTimeImmutable('-30 days'))
            ->setEcheanceAt(new \DateTimeImmutable('-10 days'));
        $tranche->setCotation($cotation);
        $tranche->setEntreprise($entreprise);
        $em->persist($tranche);

        return $client;
    }

    private function accorderLectureClient(Invite $invite, Entreprise $entreprise): void
    {
        $role = (new RolesEnProduction())->setNom('Lecture clients SOA');
        $role->setAccessClient([Invite::ACCESS_LECTURE]);
        $role->setEntreprise($entreprise);
        $invite->addRolesEnProduction($role);
        $this->em()->persist($role);
        $this->em()->flush();
    }

    /**
     * LES CHIFFRES DE KET SONT CEUX DU SERVICE QUE L'ÉCRAN UTILISE.
     */
    public function testLeReleveRestitueEstCeluiDeLEcran(): void
    {
        $data = $this->seed();
        $scope = new AiScope($data['entreprise'], $data['proprietaire']);

        $resultat = $this->outil()->execute(
            ['id' => $data['client']->getId(), 'sections' => ['recapitulatif', 'polices', 'echeancier']],
            $scope,
        );
        $reponse = $resultat->data;

        self::assertSame(self::CLIENT_NOM, $reponse['client']);
        self::assertArrayHasKey('reference', $reponse);
        self::assertArrayHasKey('monnaie', $reponse);

        // La même chaîne, vue par le service des trois écrans du relevé.
        $contexte = static::getContainer()->get(SoaContextBuilder::class)->build(
            $data['client'],
            $data['entreprise'],
            $data['proprietaire'],
        );

        self::assertCount(1, $contexte['polices'], 'La chaîne de test doit porter une police.');
        self::assertCount(1, $contexte['tranches'], 'La chaîne de test doit porter une tranche.');

        // Police : « payé » et « solde » viennent de l'entrée calculée par le
        // service, pas d'un calcul refait dans l'outil.
        $policeEcran = $contexte['polices'][0];
        $policeKet = $reponse['polices']['lignes'][0];
        self::assertSame('POL-SOA-A', $policeKet['reference']);
        self::assertSame(round($policeEcran['primePayee'], 6), round($policeKet['paye'], 6));
        self::assertSame(round($policeEcran['primeSolde'], 6), round($policeKet['solde'], 6));

        // Tranche : idem, et le repli de la tranche (montant payé propre) diffère
        // de celui d'une police (zéro) — c'est justement ce que le service porte.
        $trancheEcran = $contexte['tranches'][0];
        $trancheKet = $reponse['echeancier']['lignes'][0];
        self::assertSame(round($trancheEcran['primePayee'], 6), round($trancheKet['paye'], 6));
        self::assertSame(round($trancheEcran['primeSolde'], 6), round($trancheKet['solde'], 6));

        // Invariant de lecture d'un relevé : payé + solde = dû, à la ligne près.
        self::assertSame(
            round((float) $policeKet['primeTTC'], 6),
            round($policeKet['paye'] + $policeKet['solde'], 6),
            "Sur une police, « payé » + « solde » doit faire la prime : sans quoi la ligne ne se lit pas.",
        );

        // Récapitulatif : les deux rubriques du relevé, dans l'ordre du relevé.
        self::assertSame(
            ["Primes d'assurance", 'Indemnisations sinistres'],
            array_column($reponse['recapitulatif']['lignes'], 'rubrique'),
        );

        // La prime du client se retrouve bien au récapitulatif (la chaîne n'en porte
        // qu'une) : sans cela, on comparerait des zéros à des zéros.
        self::assertSame(
            self::PRIME,
            round((float) $reponse['recapitulatif']['lignes'][0]['du'], 2),
            'Le récapitulatif doit porter la prime de la chaîne de test.',
        );

        // Chaque section porte sa légende, et les sections NON demandées sont nommées.
        foreach (['recapitulatif', 'polices', 'echeancier'] as $section) {
            self::assertNotEmpty($reponse[$section]['legende'] ?? '');
        }
        self::assertSame(['sinistres', 'ratios'], $reponse['sectionsNonDemandees']);
    }

    /**
     * LES SECTIONS PAR DÉFAUT SUFFISENT À RÉPONDRE, SANS PARAMÈTRE.
     */
    public function testSansSectionsLeReleveRepondQuandMeme(): void
    {
        $data = $this->seed();
        $reponse = $this->outil()
            ->execute(['nom' => 'Kinshasa'], new AiScope($data['entreprise'], $data['proprietaire']))
            ->data;

        foreach (['recapitulatif', 'echeancier', 'ratios'] as $section) {
            self::assertArrayHasKey($section, $reponse);
        }
        self::assertSame(['polices', 'sinistres'], $reponse['sectionsNonDemandees']);
        self::assertArrayHasKey('tauxSP', $reponse['ratios']);
        self::assertArrayHasKey('indiceSolvabilite', $reponse['ratios']);
    }

    /**
     * UN INVITÉ SANS LECTURE SUR LES CLIENTS EST REFUSÉ.
     *
     * Le relevé d'un client est la pièce la plus complète qui existe sur lui : primes,
     * soldes, sinistralité. Un collaborateur hors périmètre ne doit pas l'obtenir en
     * la demandant à Ket alors que l'écran la lui refuse.
     */
    public function testUnInviteSansLectureClientEstRefuse(): void
    {
        $data = $this->seed();

        $resultat = $this->outil()->execute(
            ['id' => $data['client']->getId()],
            new AiScope($data['entreprise'], $data['sansDroit']),
        );

        self::assertArrayNotHasKey('recapitulatif', $resultat->data);
        // Le refus porte le STATUT hors-périmètre du contrat d'outil, et non un
        // relevé vide : c'est lui que le modèle lit pour dire honnêtement pourquoi
        // il ne répond pas.
        self::assertSame(AiToolResult::STATUS_HORS_PERIMETRE, $resultat->status);
        self::assertArrayHasKey('libelle', $resultat->data);

        // Et l'outil ne doit même pas être DÉCRIT au modèle pour cet invité.
        self::assertFalse(
            $this->outil()->estDisponible(new AiScope($data['entreprise'], $data['sansDroit'])),
        );
        self::assertTrue(
            $this->outil()->estDisponible(new AiScope($data['entreprise'], $data['proprietaire'])),
        );
    }

    /**
     * LE CLIENT D'UN AUTRE CABINET RESTE INTROUVABLE.
     *
     * Le cloisonnement ne passe pas par le prompt mais par la recherche, scopée sur
     * l'entreprise du périmètre. L'invité de ce test a pourtant tous les droits —
     * dans SON cabinet.
     */
    public function testUnClientDUnAutreCabinetResteIntrouvable(): void
    {
        $data = $this->seed();
        $this->accorderLectureClient($data['sansDroit'], $data['entreprise']);

        $resultat = $this->outil()->execute(
            ['id' => $data['clientB']->getId()],
            new AiScope($data['entreprise'], $data['proprietaire']),
        );

        self::assertArrayNotHasKey('recapitulatif', $resultat->data);

        // Et par le NOM, qui est volontairement proche de celui du client du cabinet.
        $parNom = $this->outil()->execute(
            ['nom' => 'Kinshasa Bis'],
            new AiScope($data['entreprise'], $data['proprietaire']),
        );
        self::assertArrayNotHasKey('recapitulatif', $parNom->data);
    }
}
