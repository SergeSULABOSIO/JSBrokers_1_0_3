<?php

namespace App\Tests\Security;

use App\Entity\Client;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Invite;
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
 * LE DIALOGUE DE LIGNE DE NOTE LISTAIT LE PORTEFEUILLE DES AUTRES CABINETS.
 *
 * ── CE QUI S'EST PASSÉ ──────────────────────────────────────────────────────
 * Sur les vingt champs d'autocomplétion du projet, dix-sept scopaient depuis
 * toujours par `FormListenerFactory::setFiltreEntreprise()`. Deux ne le faisaient
 * pas, et ce sont précisément les deux que l'on ouvre pour composer une ligne de
 * facture : le REVENU à facturer et la TRANCHE de prime correspondante. Leur
 * `query_builder` était nu — `createQueryBuilder('r')`, `createQueryBuilder('t')`,
 * sans la moindre clause WHERE.
 *
 * Les endpoints `/autocomplete/revenu_pour_courtier_autocomplete_field` et
 * `/autocomplete/tranche_autocomplete_field` renvoyaient donc les enregistrements
 * de TOUS les cabinets de la plateforme, avec leurs libellés — qui portent le nom
 * du client, celui de l'assureur, la référence de police et les montants. Un
 * courtier authentifié pouvait lire le portefeuille de ses concurrents, et en
 * sélectionner une ligne pour la facturer.
 *
 * ── POURQUOI LE TROU ÉTAIT DÉFENDU PAR UN COMMENTAIRE ────────────────────────
 * Celui de la tranche disait : « le QueryBuilder ne doit plus filtrer, il doit
 * pouvoir retrouver n'importe quelle Tranche par son ID lors de la soumission ».
 * C'était vrai du filtrage par REVENU — celui-ci se fait à l'affichage. Ça ne
 * l'était pas du cabinet : le besoin de résoudre un identifiant s'arrête au
 * cabinet de l'utilisateur. Au-delà, il n'y a plus de besoin, seulement une fuite.
 *
 * ── CE QUE CE TEST INTERROGE ────────────────────────────────────────────────
 * L'ENDPOINT RÉEL, pas le formulaire. C'est lui qui fuyait ; une assertion sur la
 * construction du champ prouverait autre chose. Deux cabinets sont semés, chacun
 * avec son revenu et sa tranche, et l'on vérifie dans les DEUX sens : le voisin
 * est absent, et le sien est présent — un filtre qui vide tout ne corrige rien.
 */
class AutocompleteScopeEntrepriseTest extends WebTestCase
{
    private const OWNER_EMAIL = 'phpunit-autoscope-owner@test.local';
    private const VOISIN_EMAIL = 'phpunit-autoscope-voisin@test.local';
    private const ENTREPRISE_NOM = 'PHPUnit AutoScope SARL';
    private const VOISIN_NOM = 'PHPUnit AutoScope Voisin SARL';

    /** Ce que le libellé du voisin ne doit jamais laisser filtrer : le nom de son client. */
    private const CLIENT_VOISIN = 'Client Confidentiel Du Voisin';

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

    private function cleanUp(): void
    {
        $conn = $this->em()->getConnection();
        $noms = [self::ENTREPRISE_NOM, self::VOISIN_NOM];
        $emails = [self::OWNER_EMAIL, self::VOISIN_EMAIL];

        // Ordre des contraintes de clés étrangères : les enfants d'abord.
        $tables = ['revenu_pour_courtier', 'tranche', 'cotation', 'piste', 'client', 'portefeuille', 'type_revenu', 'invite'];
        foreach ($tables as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom IN (:noms)",
                ['noms' => $noms],
                ['noms' => \Doctrine\DBAL\ArrayParameterType::STRING],
            );
        }
        // Dénoue la FK circulaire utilisateur.connected_to_id ↔ entreprise avant suppression.
        $conn->executeStatement(
            'UPDATE utilisateur SET connected_to_id = NULL WHERE email IN (:emails)',
            ['emails' => $emails],
            ['emails' => \Doctrine\DBAL\ArrayParameterType::STRING],
        );
        $conn->executeStatement(
            'DELETE FROM entreprise WHERE nom IN (:noms)',
            ['noms' => $noms],
            ['noms' => \Doctrine\DBAL\ArrayParameterType::STRING],
        );
        $conn->executeStatement(
            'DELETE FROM utilisateur WHERE email IN (:emails)',
            ['emails' => $emails],
            ['emails' => \Doctrine\DBAL\ArrayParameterType::STRING],
        );
    }

    /**
     * Deux cabinets complets et symétriques, puis connexion comme invité du premier.
     *
     * On rend les identifiants des deux revenus et des deux tranches : c'est sur eux
     * que porte l'assertion, parce que c'est eux que la réponse d'autocomplétion
     * transporte.
     *
     * @return array{revenuMien: int, revenuVoisin: int, trancheMienne: int, trancheVoisine: int}
     */
    private function seed(): array
    {
        $em = $this->em();

        $mien = $this->semerUnCabinet(self::ENTREPRISE_NOM, self::OWNER_EMAIL, 'Client Maison', 'MIEN');
        $voisin = $this->semerUnCabinet(self::VOISIN_NOM, self::VOISIN_EMAIL, self::CLIENT_VOISIN, 'VOISIN');

        $em->flush();

        $ids = [
            'revenuMien' => $mien['revenu']->getId(),
            'revenuVoisin' => $voisin['revenu']->getId(),
            'trancheMienne' => $mien['tranche']->getId(),
            'trancheVoisine' => $voisin['tranche']->getId(),
        ];
        $ownerId = $mien['owner']->getId();

        $em->clear();

        $this->client->loginUser($em->getRepository(Utilisateur::class)->find($ownerId));

        return $ids;
    }

    /**
     * Un cabinet entier : utilisateur, entreprise, invité propriétaire, puis la chaîne
     * portefeuille → client → piste → cotation, d'où pendent une tranche et un revenu.
     * Le strict nécessaire pour que les deux endpoints aient quelque chose à renvoyer.
     *
     * @return array{owner: Utilisateur, revenu: RevenuPourCourtier, tranche: Tranche}
     */
    private function semerUnCabinet(string $nomEntreprise, string $email, string $nomClient, string $suffixe): array
    {
        $em = $this->em();

        $owner = (new Utilisateur())
            ->setEmail($email)
            ->setNom('PHPUnit AutoScope')
            ->setVerified(true)
            ->setPassword('irrelevant');
        $em->persist($owner);

        $entreprise = (new Entreprise())
            ->setNom($nomEntreprise)
            ->setLicence('LIC-' . $suffixe)
            ->setAdresse('1 rue du Cloisonnement')
            ->setTelephone('+243000000004')
            ->setRccm('RCCM-' . $suffixe)
            ->setIdnat('IDNAT-' . $suffixe)
            ->setNumimpot('IMP-' . $suffixe)
            ->setUtilisateur($owner);
        $em->persist($entreprise);

        // L'entreprise ACTIVE : c'est elle que setFiltreEntreprise() lit, via getConnectedTo().
        $owner->setConnectedTo($entreprise);

        $invite = (new Invite())->setNom('Proprietaire ' . $suffixe);
        $invite->setUtilisateur($owner)->setEntreprise($entreprise)->setProprietaire(true);
        $em->persist($invite);

        $portefeuille = (new Portefeuille())->setNom('Portefeuille ' . $suffixe)->setGestionnaire($invite);
        $portefeuille->setEntreprise($entreprise);
        $em->persist($portefeuille);

        $client = (new Client())->setNom($nomClient)->setExonere(false);
        $client->setEntreprise($entreprise)->setPortefeuille($portefeuille);
        $em->persist($client);

        $piste = (new Piste())
            ->setNom('Piste ' . $suffixe)
            ->setTypeAvenant(Piste::AVENANT_SOUSCRIPTION)
            ->setDescriptionDuRisque('Risque de test cloisonnement')
            ->setExercice((int) (new \DateTimeImmutable('now'))->format('Y'))
            ->setClient($client);
        $piste->setEntreprise($entreprise)->setInvite($invite);
        $em->persist($piste);

        $cotation = (new Cotation())->setNom('Cotation ' . $suffixe)->setDuree(12);
        $cotation->setPiste($piste);
        $cotation->setEntreprise($entreprise);
        $em->persist($cotation);

        // `shared`, `multipayments` et `redevable` sont NOT NULL sans defaut : aucun
        // formulaire ne les laisse vides, aucun test ne peut donc s'en dispenser.
        $typeRevenu = (new TypeRevenu())
            ->setNom('Commission ' . $suffixe)
            ->setShared(false)
            ->setMultipayments(false)
            ->setRedevable(TypeRevenu::REDEVABLE_ASSUREUR);
        $typeRevenu->setEntreprise($entreprise);
        $em->persist($typeRevenu);

        $revenu = (new RevenuPourCourtier())->setNom('Revenu ' . $suffixe);
        $revenu->setCotation($cotation)->setTypeRevenu($typeRevenu);
        $revenu->setEntreprise($entreprise);
        $em->persist($revenu);

        // Dates CALCULÉES : un test soumis à une date figée se met à mentir tout seul.
        $tranche = (new Tranche())
            ->setNom('Tranche ' . $suffixe)
            ->setPourcentage(100.0) // 100 % en POINTS (convention du projet).
            ->setPayableAt(new \DateTimeImmutable('-30 days'))
            ->setEcheanceAt(new \DateTimeImmutable('-5 days'));
        $tranche->setCotation($cotation);
        $tranche->setEntreprise($entreprise);
        $em->persist($tranche);

        return ['owner' => $owner, 'revenu' => $revenu, 'tranche' => $tranche];
    }

    /**
     * Les identifiants réellement proposés par un endpoint d'autocomplétion.
     *
     * @return list<int>
     */
    private function identifiantsProposes(string $alias): array
    {
        $this->client->request('GET', '/autocomplete/' . $alias);
        self::assertResponseIsSuccessful(sprintf(
            'L\'endpoint « %s » doit répondre : sans lui, ce test ne prouverait rien.',
            $alias,
        ));

        $charge = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($charge, 'La réponse d\'autocomplétion n\'est pas du JSON : la lecture a changé de forme.');

        $ids = [];
        foreach ($charge['results'] ?? [] as $option) {
            if (isset($option['value'])) {
                $ids[] = (int) $option['value'];
            }
        }

        return $ids;
    }

    public function testLesRevenusDUnAutreCabinetNeSontJamaisProposes(): void
    {
        $seed = $this->seed();

        $proposes = $this->identifiantsProposes('revenu_pour_courtier_autocomplete_field');

        self::assertContains(
            $seed['revenuMien'],
            $proposes,
            'Le revenu du cabinet de l\'utilisateur a disparu de la liste. Un filtre qui vide tout ne '
            . 'corrige rien : le dialogue de ligne de note ne proposerait plus aucun revenu à facturer.',
        );
        self::assertNotContains($seed['revenuVoisin'], $proposes, sprintf(
            'FUITE INTER-CABINETS : le revenu #%d appartient à « %s » et il est proposé à un invité de '
            . '« %s ». Le query_builder de RevenuPourCourtierAutocompleteField doit passer par '
            . 'FormListenerFactory::setFiltreEntreprise(), comme les dix-sept autres champs du dossier.',
            $seed['revenuVoisin'],
            self::VOISIN_NOM,
            self::ENTREPRISE_NOM,
        ));
    }

    public function testLesTranchesDUnAutreCabinetNeSontJamaisProposees(): void
    {
        $seed = $this->seed();

        $proposes = $this->identifiantsProposes('tranche_autocomplete_field');

        self::assertContains(
            $seed['trancheMienne'],
            $proposes,
            'La tranche du cabinet de l\'utilisateur a disparu de la liste. La résolution d\'un '
            . 'identifiant à la soumission doit rester possible DANS le cabinet — c\'est tout ce que '
            . 'le commentaire du champ exigeait.',
        );
        self::assertNotContains($seed['trancheVoisine'], $proposes, sprintf(
            'FUITE INTER-CABINETS : la tranche #%d appartient à « %s » et elle est proposée à un '
            . 'invité de « %s ». Son libellé porte le client, l\'assureur, la police et les montants.',
            $seed['trancheVoisine'],
            self::VOISIN_NOM,
            self::ENTREPRISE_NOM,
        ));
    }

    /**
     * LA FUITE SE LISAIT, elle ne se déduisait pas d'un identifiant. Le libellé d'une
     * option porte le nom du client et celui de l'assureur : le vrai dommage était là,
     * et une assertion sur les seuls identifiants ne le couvrirait pas.
     */
    public function testAucunNomDeClientVoisinNApparaitDansLaReponse(): void
    {
        $this->seed();

        foreach (['revenu_pour_courtier_autocomplete_field', 'tranche_autocomplete_field'] as $alias) {
            $this->client->request('GET', '/autocomplete/' . $alias);
            self::assertStringNotContainsString(
                self::CLIENT_VOISIN,
                (string) $this->client->getResponse()->getContent(),
                sprintf(
                    'Le nom du client d\'un AUTRE cabinet apparaît dans la réponse de « %s ». '
                    . 'C\'est le portefeuille du concurrent qui est lisible, pas seulement un numéro.',
                    $alias,
                ),
            );
        }
    }
}
