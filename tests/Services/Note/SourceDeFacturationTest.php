<?php

namespace App\Tests\Services\Note;

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
use App\Services\Note\SourceDeFacturation;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * LA RÈGLE DE FACTURATION EXISTAIT, ET ELLE NE SERVAIT À PERSONNE.
 *
 * ── CE QUE CE TEST VERROUILLE ───────────────────────────────────────────────
 * « Quels revenus peut-on encore facturer, et à qui » était écrit — en closure
 * privée dans `RevenuPourCourtierAutocompleteField::fetchAndFilterEligibleRevenus()` —
 * et n'était appelé de nulle part. L'écran proposait donc n'importe quel revenu,
 * soldé ou non, et l'assistant n'avait aucun moyen de connaître la règle : sommé
 * de facturer une commission exigible, il a répondu qu'il ne trouvait « aucune
 * tranche de commission associée à cette police ».
 *
 * La règle est désormais un service, et ce test est ce qui empêche qu'elle
 * redevienne du texte : il l'exerce sur une vraie base, dans les quatre cas de
 * destinataire qu'elle distingue.
 *
 * ── CE QU'IL NE TESTE PAS, ET POURQUOI ──────────────────────────────────────
 * Le MONTANT d'une commission ne se calcule pas ici — c'est l'affaire de
 * `IndicatorCalculationHelper`, qui a ses propres tests. Ce qu'on vérifie, c'est
 * le TRI : un revenu dont le solde est retombé à zéro ne doit plus être proposé,
 * et un revenu d'un autre cabinet ne doit jamais apparaître, quel que soit son solde.
 */
class SourceDeFacturationTest extends KernelTestCase
{
    private const OWNER_EMAIL = 'phpunit-sourcefact-owner@test.local';
    private const VOISIN_EMAIL = 'phpunit-sourcefact-voisin@test.local';
    private const ENTREPRISE_NOM = 'PHPUnit SourceFacturation SARL';
    private const VOISIN_NOM = 'PHPUnit SourceFacturation Voisin SARL';

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
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

    private function service(): SourceDeFacturation
    {
        return static::getContainer()->get(SourceDeFacturation::class);
    }

    private function cleanUp(): void
    {
        $conn = $this->em()->getConnection();
        $noms = [self::ENTREPRISE_NOM, self::VOISIN_NOM];
        $emails = [self::OWNER_EMAIL, self::VOISIN_EMAIL];

        $tables = ['revenu_pour_courtier', 'tranche', 'chargement_pour_prime', 'cotation', 'piste', 'client', 'portefeuille', 'type_revenu', 'chargement', 'invite'];
        foreach ($tables as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom IN (:noms)",
                ['noms' => $noms],
                ['noms' => \Doctrine\DBAL\ArrayParameterType::STRING],
            );
        }
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
     * Un cabinet avec une affaire chiffrée : prime de 1 000, une échéance à 100 %,
     * et une commission de 10 % qui n'a jamais été encaissée — elle reste donc due.
     *
     * @return array{entreprise: Entreprise, assureurId: ?int, clientId: int, revenuId: int, tranche: Tranche}
     */
    private function semerUnCabinet(string $nomEntreprise, string $email, string $suffixe): array
    {
        $em = $this->em();

        $owner = (new Utilisateur())
            ->setEmail($email)
            ->setNom('PHPUnit SourceFact')
            ->setVerified(true)
            ->setPassword('irrelevant');
        $em->persist($owner);

        $entreprise = (new Entreprise())
            ->setNom($nomEntreprise)
            ->setLicence('LIC-' . $suffixe)
            ->setAdresse('1 rue de la Facturation')
            ->setTelephone('+243000000005')
            ->setRccm('RCCM-' . $suffixe)
            ->setIdnat('IDNAT-' . $suffixe)
            ->setNumimpot('IMP-' . $suffixe)
            ->setUtilisateur($owner);
        $em->persist($entreprise);
        $owner->setConnectedTo($entreprise);

        $invite = (new Invite())->setNom('Proprietaire ' . $suffixe);
        $invite->setUtilisateur($owner)->setEntreprise($entreprise)->setProprietaire(true);
        $em->persist($invite);

        $portefeuille = (new Portefeuille())->setNom('Portefeuille ' . $suffixe)->setGestionnaire($invite);
        $portefeuille->setEntreprise($entreprise);
        $em->persist($portefeuille);

        $client = (new Client())->setNom('Client ' . $suffixe)->setExonere(false);
        $client->setEntreprise($entreprise)->setPortefeuille($portefeuille);
        $em->persist($client);

        $piste = (new Piste())
            ->setNom('Piste ' . $suffixe)
            ->setTypeAvenant(Piste::AVENANT_SOUSCRIPTION)
            ->setDescriptionDuRisque('Risque de test facturation')
            ->setExercice((int) (new \DateTimeImmutable('now'))->format('Y'))
            ->setClient($client);
        $piste->setEntreprise($entreprise)->setInvite($invite);
        $em->persist($piste);

        $cotation = (new Cotation())->setNom('Cotation ' . $suffixe)->setDuree(12);
        $cotation->setPiste($piste);
        $cotation->setEntreprise($entreprise);
        $em->persist($cotation);

        // LE TYPE DE CHARGEMENT EST L'ASSIETTE, et sans lui la commission reste à 0 :
        // c'est lui qui dit sur QUOI le taux du courtier s'applique. Un semis qui
        // l'oublie mesure une commission nulle et croit à un défaut de la règle.
        $primeNette = (new Chargement())
            ->setNom('Prime nette ' . $suffixe)
            ->setFonction(Chargement::FONCTION_PRIME_NETTE);
        $primeNette->setEntreprise($entreprise);
        $em->persist($primeNette);

        $chargement = (new ChargementPourPrime())
            ->setNom('Prime ' . $suffixe)
            ->setMontantFlatExceptionel(1000.0)
            ->setType($primeNette)
            ->setCotation($cotation);
        $chargement->setEntreprise($entreprise);
        $em->persist($chargement);
        // ⚠ LE COTE INVERSE, SANS QUOI L'ASSIETTE EST VIDE. Le calcul lit
        // `cotation->getChargements()` : poser seulement `chargement->setCotation()`
        // laisse la collection en memoire vide, et la commission tombe a 0 — un faux
        // negatif qui ferait accuser la regle.
        $cotation->addChargement($chargement);

        $typeRevenu = (new TypeRevenu())
            ->setNom('Commission ' . $suffixe)
            ->setShared(false)
            ->setMultipayments(false)
            ->setTypeChargement($primeNette)
            ->setRedevable(TypeRevenu::REDEVABLE_ASSUREUR);
        $typeRevenu->setEntreprise($entreprise);
        $em->persist($typeRevenu);

        // Taux EXCEPTIONNEL en POINTS (convention du projet depuis 2026-07-25) :
        // 10 points de la prime, soit une commission de 100 restant intégralement due.
        $revenu = (new RevenuPourCourtier())
            ->setNom('Revenu ' . $suffixe)
            ->setTauxExceptionel(10.0);
        $revenu->setCotation($cotation)->setTypeRevenu($typeRevenu);
        $revenu->setEntreprise($entreprise);
        $em->persist($revenu);
        $cotation->addRevenu($revenu);

        $tranche = (new Tranche())
            ->setNom('Tranche ' . $suffixe)
            ->setPourcentage(100.0)
            ->setPayableAt(new \DateTimeImmutable('-30 days'))
            ->setEcheanceAt(new \DateTimeImmutable('-5 days'));
        $tranche->setCotation($cotation);
        $tranche->setEntreprise($entreprise);
        $em->persist($tranche);
        $cotation->addTranche($tranche);

        $em->flush();

        return [
            'entreprise' => $entreprise,
            'assureurId' => $cotation->getAssureur()?->getId(),
            'clientId' => $client->getId(),
            'revenuId' => $revenu->getId(),
            'tranche' => $tranche,
        ];
    }

    /** @return list<int> */
    private function identifiants(array $revenus): array
    {
        return array_map(static fn (RevenuPourCourtier $r): int => (int) $r->getId(), $revenus);
    }

    public function testUneCommissionNonEncaisseeEstFacturableAuClient(): void
    {
        $mien = $this->semerUnCabinet(self::ENTREPRISE_NOM, self::OWNER_EMAIL, 'MIEN');

        $facturables = $this->service()->revenusFacturables(
            $mien['entreprise'],
            Note::TO_CLIENT,
            $mien['clientId'],
        );

        self::assertContains($mien['revenuId'], $this->identifiants($facturables),
            'Une commission jamais encaissée doit être facturable : c\'est le cas nominal, celui '
            . 'que l\'assistant n\'arrivait pas à trouver.',
        );
    }

    /**
     * LE SEUIL EST LA RAISON D'ÊTRE DE LA RÈGLE. Un revenu sans montant n'a rien à
     * réclamer : le proposer quand même, c'est ce que faisait l'écran, et c'est
     * comment on émet une note de débit à zéro.
     */
    public function testUnRevenuSansMontantNEstPasFacturable(): void
    {
        $mien = $this->semerUnCabinet(self::ENTREPRISE_NOM, self::OWNER_EMAIL, 'MIEN');
        $em = $this->em();

        // Ni taux ni forfait : la commission vaut 0, il n'y a donc rien à facturer.
        $vide = (new RevenuPourCourtier())->setNom('Revenu sans montant');
        $vide->setCotation($mien['tranche']->getCotation())
            ->setTypeRevenu($em->getRepository(TypeRevenu::class)->findOneBy(['nom' => 'Commission MIEN']));
        $vide->setEntreprise($mien['entreprise']);
        $em->persist($vide);
        $mien['tranche']->getCotation()->addRevenu($vide);
        $em->flush();

        $facturables = $this->identifiants($this->service()->revenusFacturables(
            $mien['entreprise'],
            Note::TO_CLIENT,
            $mien['clientId'],
        ));

        self::assertContains($mien['revenuId'], $facturables, 'Le revenu chiffré doit rester proposé.');
        self::assertNotContains((int) $vide->getId(), $facturables,
            'Un revenu dont la commission vaut 0 est proposé à la facturation. Cela est très '
            . 'exactement ce que le seuil de la règle doit écarter — sans lui, on émet une note à zéro.',
        );
    }

    /**
     * LE CLOISONNEMENT PASSE PAR LA RÈGLE AUSSI. Le query_builder du champ est
     * scopé, mais l'assistant appelle ce service directement : s'il n'était pas
     * scopé lui-même, il rouvrirait par la fenêtre ce qu'on a fermé à la porte.
     */
    public function testUnRevenuDUnAutreCabinetNEstJamaisFacturable(): void
    {
        $mien = $this->semerUnCabinet(self::ENTREPRISE_NOM, self::OWNER_EMAIL, 'MIEN');
        $voisin = $this->semerUnCabinet(self::VOISIN_NOM, self::VOISIN_EMAIL, 'VOISIN');

        // On interroge avec MON entreprise, mais en visant le client du VOISIN.
        $facturables = $this->service()->revenusFacturables(
            $mien['entreprise'],
            Note::TO_CLIENT,
            $voisin['clientId'],
        );

        self::assertNotContains($voisin['revenuId'], $this->identifiants($facturables),
            'Le revenu d\'un autre cabinet est rendu facturable. Le service doit filtrer sur '
            . 'r.entreprise, quelle que soit la cible demandée.',
        );
    }

    /**
     * SANS DESTINATAIRE, « FACTURABLE » NE VEUT RIEN DIRE — et c'est exactement le
     * trou par lequel l'écran proposait tout : le contexte n'étant jamais lu, aucun
     * tri ne s'appliquait. La règle est fail-closed.
     */
    public function testSansDestinataireConnuAucunRevenuNEstPropose(): void
    {
        $mien = $this->semerUnCabinet(self::ENTREPRISE_NOM, self::OWNER_EMAIL, 'MIEN');

        $facturables = $this->service()->revenusFacturables($mien['entreprise'], Note::TO_NULL, null);

        self::assertSame([], $facturables,
            'Un destinataire inconnu doit rendre une liste VIDE, pas la liste entière : on ne '
            . 'propose pas de facturer sans savoir à qui.',
        );
    }

    /**
     * La rétrocommission n'est pas la commission. Sans condition de partage, rien
     * n'est dû à un intermédiaire — et le revenu ne doit donc pas être proposé
     * pour une note au partenaire, alors qu'il l'est pour une note au client.
     */
    public function testUnRevenuSansRetrocommissionNEstPasFacturableAuPartenaire(): void
    {
        $mien = $this->semerUnCabinet(self::ENTREPRISE_NOM, self::OWNER_EMAIL, 'MIEN');

        $facturables = $this->service()->revenusFacturables($mien['entreprise'], Note::TO_PARTENAIRE, null);

        self::assertNotContains($mien['revenuId'], $this->identifiants($facturables),
            'Aucune condition de partage n\'existe sur cette affaire : il n\'y a donc rien à '
            . 'reverser, et le revenu ne doit pas être proposé pour une note au partenaire. '
            . 'Confondre les deux soldes ferait payer un intermédiaire qui n\'a droit à rien.',
        );
    }

    /**
     * L'EN-TÊTE SE DÉDUIT, IL NE SE DEMANDE PAS. C'est ce qui permet à l'assistant
     * de présenter un plan prérempli au lieu d'interroger le courtier sur ce que le
     * dossier porte déjà.
     */
    public function testLEnteteSeDeduitDeLaTrancheQuOnFacture(): void
    {
        $mien = $this->semerUnCabinet(self::ENTREPRISE_NOM, self::OWNER_EMAIL, 'MIEN');

        $entete = $this->service()->entetePour($mien['tranche'], Note::TYPE_NOTE_DE_DEBIT, Note::TO_ASSUREUR);

        self::assertSame(Note::TYPE_NOTE_DE_DEBIT, $entete['type']);
        self::assertSame(Note::TO_ASSUREUR, $entete['addressedTo']);
        self::assertStringContainsString('Commission', $entete['nom'],
            'L\'objet de la note doit dire ce qu\'on réclame : c\'est ce que lira le destinataire.',
        );
    }

    /** Un avoir porte un autre motif : facturer et rembourser ne se disent pas pareil. */
    public function testUnAvoirNAnnoncePasUneCommission(): void
    {
        $mien = $this->semerUnCabinet(self::ENTREPRISE_NOM, self::OWNER_EMAIL, 'MIEN');

        $entete = $this->service()->entetePour($mien['tranche'], Note::TYPE_NOTE_DE_CREDIT, Note::TO_CLIENT);

        self::assertSame(Note::TYPE_NOTE_DE_CREDIT, $entete['type']);
        self::assertSame(Note::TO_CLIENT, $entete['addressedTo']);
        self::assertStringContainsString('Avoir', $entete['nom']);
    }
}
