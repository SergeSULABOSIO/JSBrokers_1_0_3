<?php

namespace App\Tests\Workspace;

use App\Entity\Chargement;
use App\Entity\ChargementPourPrime;
use App\Entity\Client;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Piste;
use App\Entity\RevenuPourCourtier;
use App\Entity\TypeRevenu;
use App\Entity\Utilisateur;
use App\Services\Canvas\Indicator\IndicatorCalculationHelper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * LA COMMISSION PORTE SUR LA SOMME DES LIGNES DU CHARGEMENT CIBLE (écart 3 de l'assiette,
 * décision de Serge du 2026-10-15).
 *
 * Une cotation peut porter deux lignes « prime nette » (10 000 + 4 000) : l'écran ne
 * comptait que la PREMIÈRE (1 500 à 15 %), Ket les additionnait déjà (2 100). La règle
 * est désormais la somme, partout — et les notes, recalculées depuis le revenu, la suivent.
 * Aucun historique à reprendre : la mesure en production du 2026-10-15 n'a trouvé aucune
 * cotation à plusieurs lignes du chargement cible.
 */
class AssietteSommeDesLignesTest extends KernelTestCase
{
    private const EMAIL = 'phpunit-assiette-somme@test.local';
    private const CABINET = 'PHPUnit Assiette Somme SARL';

    private Entreprise $cabinet;
    private Invite $invite;
    private Chargement $primeNette;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->cleanUp();
        $this->semerLeCabinet();
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
        $conn->executeStatement('UPDATE utilisateur SET connected_to_id = NULL WHERE email = ?', [self::EMAIL]);
        foreach ($conn->fetchFirstColumn('SELECT id FROM entreprise WHERE nom = ?', [self::CABINET]) as $id) {
            $tables = $conn->fetchFirstColumn(
                "SELECT TABLE_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'entreprise_id' AND TABLE_NAME <> 'entreprise'"
            );
            $conn->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
            try {
                foreach ($tables as $table) {
                    $conn->executeStatement("DELETE FROM `$table` WHERE entreprise_id = ?", [$id]);
                }
                $conn->executeStatement('DELETE FROM entreprise WHERE id = ?', [$id]);
            } finally {
                $conn->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
            }
        }
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = ?', [self::EMAIL]);
        $this->em()->clear();
    }

    private function semerLeCabinet(): void
    {
        $em = $this->em();
        $owner = (new Utilisateur())->setEmail(self::EMAIL)->setNom('Somme')->setVerified(true)->setPassword('x');
        $em->persist($owner);
        $this->cabinet = (new Entreprise())->setNom(self::CABINET)->setLicence('L')->setAdresse('1 rue')
            ->setTelephone('+243000000066')->setRccm('R')->setIdnat('I')->setNumimpot('N')->setUtilisateur($owner);
        $em->persist($this->cabinet);
        $this->invite = (new Invite())->setNom('Propriétaire')->setUtilisateur($owner)->setEntreprise($this->cabinet)->setProprietaire(true);
        $em->persist($this->invite);

        $this->primeNette = (new Chargement())->setNom('Prime nette')->setFonction(Chargement::FONCTION_PRIME_NETTE);
        $this->primeNette->setEntreprise($this->cabinet);
        $em->persist($this->primeNette);
        $em->flush();
    }

    /** Après un clear() : les entités semées, de nouveau gérées. */
    private function recharger(): void
    {
        $em = $this->em();
        $this->cabinet = $em->find(Entreprise::class, $this->cabinet->getId());
        $this->invite = $em->find(Invite::class, $this->invite->getId());
        $this->primeNette = $em->find(Chargement::class, $this->primeNette->getId());
    }

    private function type(string $nom, ?float $pourcentage, ?float $forfait): TypeRevenu
    {
        $this->recharger();
        $type = (new TypeRevenu())->setNom($nom)->setPourcentage($pourcentage)->setMontantflat($forfait)
            ->setShared(false)->setMultipayments(true)->setTypeChargement($this->primeNette)
            ->setRedevable(TypeRevenu::REDEVABLE_ASSUREUR);
        $type->setEntreprise($this->cabinet);
        $this->em()->persist($type);
        $this->em()->flush();

        return $type;
    }

    /**
     * Une cotation, ses lignes de prime nette, et un revenu de ce type : rend la commission HT
     * que l'écran (et donc la note) calcule, relue de la base.
     *
     * @param list<float> $lignes
     */
    private function commissionHt(array $lignes, TypeRevenu $type): float
    {
        $em = $this->em();
        $this->recharger();
        $type = $em->find(TypeRevenu::class, $type->getId());
        $client = (new Client())->setNom('Client ' . $type->getNom())->setExonere(false);
        $client->setEntreprise($this->cabinet);
        $em->persist($client);
        $piste = (new Piste())->setNom('Piste ' . $type->getNom())->setTypeAvenant(Piste::AVENANT_SOUSCRIPTION)
            ->setDescriptionDuRisque('Risque')->setExercice((int) date('Y'))->setClient($client);
        $piste->setEntreprise($this->cabinet)->setInvite($this->invite);
        $em->persist($piste);
        $cotation = (new Cotation())->setNom('Cotation ' . $type->getNom())->setDuree(12);
        $cotation->setPiste($piste)->setEntreprise($this->cabinet);
        $em->persist($cotation);

        foreach ($lignes as $i => $montant) {
            $ligne = (new ChargementPourPrime())->setNom('Prime nette #' . $i)->setMontantFlatExceptionel($montant)
                ->setType($this->primeNette)->setCotation($cotation);
            $ligne->setEntreprise($this->cabinet);
            $em->persist($ligne);
        }

        $revenu = (new RevenuPourCourtier())->setNom('Revenu ' . $type->getNom());
        $revenu->setCotation($cotation)->setTypeRevenu($type)->setEntreprise($this->cabinet);
        $em->persist($revenu);
        $em->flush();
        $id = $revenu->getId();

        $em->clear();

        return static::getContainer()->get(IndicatorCalculationHelper::class)
            ->getRevenuMontantHt($em->find(RevenuPourCourtier::class, $id));
    }

    public function testLaCommissionPorteSurLaSommeDesLignes(): void
    {
        self::assertEqualsWithDelta(2100.0, $this->commissionHt([10000.0, 4000.0], $this->type('Deux lignes', 15.0, null)), 0.001,
            'Deux lignes de prime nette (10 000 + 4 000) à 15 % : 2 100, pas 1 500 (la première seule).');
    }

    public function testUneLigneUniqueEtUnForfaitNeChangentPas(): void
    {
        self::assertEqualsWithDelta(1500.0, $this->commissionHt([10000.0], $this->type('Une ligne', 15.0, null)), 0.001);
        self::assertEqualsWithDelta(800.0, $this->commissionHt([10000.0, 4000.0], $this->type('Forfait', null, 800.0)), 0.001,
            'Un forfait ne dépend pas de l’assiette.');
    }
}
