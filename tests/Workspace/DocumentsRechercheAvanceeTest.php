<?php

namespace App\Tests\Workspace;

use App\Ai\Resolution\CritereLieA;
use App\Ai\Scope\AiScope;
use App\Entity\Assureur;
use App\Entity\Avenant;
use App\Entity\Client;
use App\Entity\Cotation;
use App\Entity\Document;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Piste;
use App\Entity\Risque;
use App\Entity\Utilisateur;
use App\Services\CanvasBuilder;
use App\Services\JSBDynamicSearchService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * CHERCHER UN DOCUMENT PAR CE À QUOI IL SE RATTACHE.
 *
 * CE QUI MANQUAIT, ET CE QUI NE MANQUAIT PAS. La recherche avancée de la rubrique
 * Documents ne proposait que le nom du fichier et deux dates : pour retrouver une pièce,
 * il fallait déjà en connaître le nom, ce qui suppose de l'avoir sous les yeux. Côté Ket,
 * en revanche, le rattachement était déjà interrogeable — par `lieA`, qui résout les
 * chemins de relations tout seul. Le déséquilibre était donc à sens unique : l'assistant
 * savait faire ce que l'écran ne savait pas.
 *
 * D'OÙ LA FORME DE CE TEST. Il vérifie les deux moitiés, et surtout qu'elles portent sur
 * la MÊME chose. Le canevas d'entité est la source unique de la fiche, de la liste et des
 * critères de recherche : y déclarer un attribut ouvre le filtre à l'écran, l'en retirer
 * le referme — et ce test échouera alors, au lieu de laisser la rubrique redevenir
 * silencieusement aveugle.
 */
class DocumentsRechercheAvanceeTest extends KernelTestCase
{
    private const OWNER_EMAIL = 'phpunit-recdoc-owner@test.local';
    private const ENTREPRISE_NOM = 'PHPUnit RecDoc SARL';

    /** Les six rattachements demandés, plus le classeur qui les réunit. */
    private const RATTACHEMENTS = ['client', 'assureur', 'risque', 'piste', 'cotation', 'avenant', 'classeur'];

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

    private function cleanUp(): void
    {
        $conn = $this->em()->getConnection();
        foreach (['document', 'classeur', 'avenant', 'cotation', 'piste', 'client', 'assureur', 'risque', 'invite'] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :nom",
                ['nom' => self::ENTREPRISE_NOM],
            );
        }
        $conn->executeStatement('UPDATE utilisateur SET connected_to_id = NULL WHERE email = :e', ['e' => self::OWNER_EMAIL]);
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :nom', ['nom' => self::ENTREPRISE_NOM]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :e', ['e' => self::OWNER_EMAIL]);
        $this->em()->clear();
    }

    /**
     * LE CANEVAS OUVRE LES SEPT CRITÈRES, chacun comme une vraie RELATION.
     *
     * Le type compte autant que la présence : « Relation » est ce qui fait rendre un
     * sélecteur d'enregistrement dans la boîte de recherche avancée. Déclaré en « Texte »,
     * le critère demanderait à l'utilisateur de taper un identifiant.
     */
    public function testLaRubriqueOuvreLesCriteresDeRattachement(): void
    {
        $canvas = static::getContainer()->get(CanvasBuilder::class)->getEntityCanvas(Document::class);
        $attributs = [];
        foreach ($canvas['liste'] ?? [] as $attribut) {
            $attributs[$attribut['code']] = $attribut;
        }

        foreach (self::RATTACHEMENTS as $code) {
            self::assertArrayHasKey(
                $code,
                $attributs,
                sprintf('Le critère « %s » doit exister : sans lui, on ne retrouve une pièce qu’en connaissant son nom.', $code),
            );
            self::assertSame(
                'Relation',
                $attributs[$code]['type'],
                sprintf('« %s » doit être une Relation, sinon la recherche avancée demande de taper un identifiant.', $code),
            );
            self::assertArrayHasKey('targetEntity', $attributs[$code], sprintf('« %s » doit dire vers quoi il pointe.', $code));
            self::assertArrayHasKey('displayField', $attributs[$code], sprintf('« %s » doit dire quoi afficher.', $code));
        }
    }

    /**
     * LE FILTRE FILTRE VRAIMENT — pas seulement à l'affichage.
     *
     * Un critère déclaré mais non pris en compte par le moteur rendrait TOUTES les lignes,
     * ce qui est le pire des résultats : l'utilisateur croit avoir restreint sa recherche
     * et conclut de ce qu'il voit. On compare donc un filtre à l'absence de filtre.
     */
    public function testChercherParRattachementRestreintReellement(): void
    {
        $seed = $this->seed();
        $recherche = static::getContainer()->get(JSBDynamicSearchService::class);

        $tout = $recherche->search(Document::class, [], $seed['entreprise'], null, 1, 100);
        self::assertGreaterThanOrEqual(4, \count($tout['data']), 'Prérequis : le jeu d’essai porte plusieurs pièces.');

        foreach (['client', 'assureur', 'risque', 'avenant'] as $champ) {
            $resultat = $recherche->search(
                Document::class,
                [$champ => $seed[$champ]->getId()],
                $seed['entreprise'],
                null,
                1,
                100,
            );

            self::assertCount(
                1,
                $resultat['data'],
                sprintf('Chercher par « %s » doit rendre la seule pièce qui s’y rattache.', $champ),
            );
            self::assertSame(
                sprintf('Pièce du %s', $champ),
                $resultat['data'][0]->getNom(),
                sprintf('Et ce doit être la BONNE pièce, pas n’importe laquelle.', $champ),
            );
        }
    }

    /**
     * KET CHERCHE LA MÊME CHOSE, par son propre chemin.
     *
     * L'assistant ne passe pas par les critères de l'écran mais par `lieA`, qui résout
     * seul les chemins de relations. Le vérifier ici garde les deux moitiés solidaires :
     * la rubrique et Ket doivent répondre à « les pièces de ce client », et ne pas se
     * mettre à diverger le jour où l'une des deux évolue.
     *
     * KET VOIT PLUS LARGE, ET C'EST VOULU : `lieA` combine TOUS les chemins menant au
     * client — la pièce posée sur le client, mais aussi celle de sa police. L'écran, lui,
     * filtre sur le rattachement enregistré, littéralement. Deux questions différentes,
     * deux réponses justes.
     */
    public function testKetRetrouveLesMemesPiecesParLieA(): void
    {
        $seed = $this->seed();
        $criteres = static::getContainer()->get(CritereLieA::class);

        $invite = $this->em()->getRepository(Invite::class)->findOneBy(['entreprise' => $seed['entreprise']]);
        $scope = new AiScope($seed['entreprise'], $invite, null);

        foreach (['client', 'assureur', 'risque', 'avenant'] as $champ) {
            $resolution = $criteres->resoudre(
                ['entite' => ucfirst($champ), 'id' => $seed[$champ]->getId()],
                Document::class,
                $scope,
            );

            self::assertNotSame(
                [],
                $resolution->criteria,
                sprintf('Ket doit savoir relier un Document à « %s » : sans critère, elle rendrait TOUT.', $champ),
            );
        }
    }

    /**
     * Un dossier complet, une pièce par rattachement — c'est ce qui rend le test probant :
     * un filtre qui ne filtre pas rendrait les quatre au lieu d'une.
     *
     * @return array{entreprise: Entreprise, client: Client, assureur: Assureur, risque: Risque, avenant: Avenant}
     */
    private function seed(): array
    {
        $em = $this->em();

        $owner = (new Utilisateur())->setEmail(self::OWNER_EMAIL)->setNom('PHPUnit')->setVerified(true)->setPassword('x');
        $em->persist($owner);

        $entreprise = (new Entreprise())
            ->setNom(self::ENTREPRISE_NOM)->setLicence('LIC-REC')->setAdresse('1 rue')->setTelephone('+243000000040')
            ->setRccm('R-REC')->setIdnat('I-REC')->setNumimpot('N-REC')->setUtilisateur($owner);
        $em->persist($entreprise);
        $owner->setConnectedTo($entreprise);

        $invite = (new Invite())->setNom('Owner')->setUtilisateur($owner)->setEntreprise($entreprise)->setProprietaire(true);
        $em->persist($invite);

        $client = (new Client())->setNom('Client cherchable');
        $client->setEntreprise($entreprise)->setInvite($invite);
        $em->persist($client);

        $assureur = (new Assureur())
            ->setNom('Assureur cherchable')->setEmail('assureur-rec@example.test')
            ->setNumimpot('IMP-REC')->setIdnat('NAT-REC')->setRccm('RCCM-REC');
        $assureur->setEntreprise($entreprise)->setInvite($invite);
        $em->persist($assureur);

        $risque = (new Risque())
            ->setCode('RREC')->setBranche(Risque::BRANCHE_IARD_OU_NON_VIE)
            ->setNomComplet('Risque cherchable')->setImposable(true)->setEntreprise($entreprise);
        $em->persist($risque);

        $piste = (new Piste())
            ->setNom('Piste cherchable')->setTypeAvenant(0)->setDescriptionDuRisque('R')
            ->setExercice(2026)->setClient($client);
        $piste->setEntreprise($entreprise)->setInvite($invite);
        $em->persist($piste);

        $cotation = (new Cotation())->setNom('Proposition cherchable')->setDuree(365);
        $cotation->setPiste($piste)->setAssureur($assureur)->setEntreprise($entreprise)->setInvite($invite);
        $em->persist($cotation);

        $avenant = (new Avenant())
            ->setReferencePolice('POL-REC-1')->setNumero('0')->setDescription('Police cherchable')
            ->setStartingAt(new \DateTimeImmutable('2026-03-01'))
            ->setEndingAt(new \DateTimeImmutable('2027-02-28'));
        $avenant->setCotation($cotation)->setEntreprise($entreprise)->setInvite($invite);
        $em->persist($avenant);

        foreach (['client' => $client, 'assureur' => $assureur, 'risque' => $risque, 'avenant' => $avenant] as $champ => $cible) {
            $document = (new Document())->setNom(sprintf('Pièce du %s', $champ));
            $document->{'set' . ucfirst($champ)}($cible);
            $document->setEntreprise($entreprise)->setInvite($invite);
            $em->persist($document);
        }

        $em->flush();

        return compact('entreprise', 'client', 'assureur', 'risque', 'avenant');
    }
}
