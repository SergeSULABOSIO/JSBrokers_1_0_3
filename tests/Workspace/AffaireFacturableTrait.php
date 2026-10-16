<?php

namespace App\Tests\Workspace;

use App\Entity\Article;
use App\Entity\Assureur;
use App\Entity\AutoriteFiscale;
use App\Entity\Chargement;
use App\Entity\ChargementPourPrime;
use App\Entity\Client;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Note;
use App\Entity\Piste;
use App\Entity\RevenuPourCourtier;
use App\Entity\Taxe;
use App\Entity\Tranche;
use App\Entity\TypeRevenu;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;

/**
 * UN CABINET ET UNE AFFAIRE FACTURABLE, pour les tests de l'émission des notes : prime
 * nette 10 000, commission de 10 % (1 000 HT), taxe de l'assureur à 16 % (160), une
 * échéance à 100 % — une note sur la commission vaut 1 160 TTC.
 */
trait AffaireFacturableTrait
{
    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * @param list<string> $cabinets
     * @param list<string> $emails
     */
    private function effacerLesCabinets(array $cabinets, array $emails): void
    {
        $conn = $this->em()->getConnection();
        $marques = implode(',', array_fill(0, count($emails), '?'));
        $conn->executeStatement("UPDATE utilisateur SET connected_to_id = NULL WHERE email IN ($marques)", $emails);
        $tables = $conn->fetchFirstColumn(
            "SELECT TABLE_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'entreprise_id' AND TABLE_NAME <> 'entreprise'"
        );
        $marquesC = implode(',', array_fill(0, count($cabinets), '?'));
        foreach ($conn->fetchFirstColumn("SELECT id FROM entreprise WHERE nom IN ($marquesC)", $cabinets) as $id) {
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
        $conn->executeStatement("DELETE FROM utilisateur WHERE email IN ($marques)", $emails);
        $this->em()->clear();
    }

    /**
     * @return array{owner: int, entreprise: int, invite: int, assureur: int, client: int, autorite: int,
     *               revenu: int, tranche: int, type: int, ligne: int, taxe: int}
     */
    private function semerUneAffaire(string $nom, string $email, bool $clientExonere = false): array
    {
        $em = $this->em();
        $owner = (new Utilisateur())->setEmail($email)->setNom('Propriétaire')->setVerified(true)->setPassword('x');
        $em->persist($owner);
        $cabinet = (new Entreprise())->setNom($nom)->setLicence('L')->setAdresse('1 rue')
            ->setTelephone('+243000000067')->setRccm('R')->setIdnat('I')->setNumimpot('N')->setUtilisateur($owner);
        $em->persist($cabinet);
        $owner->setConnectedTo($cabinet);
        $invite = (new Invite())->setNom('Propriétaire')->setUtilisateur($owner)->setEntreprise($cabinet)->setProprietaire(true);
        $em->persist($invite);

        $taxe = (new Taxe())->setCode('TVA')->setDescription('TVA')->setTauxIARD('16')->setTauxVIE('16')->setRedevable(Taxe::REDEVABLE_ASSUREUR);
        $taxe->setEntreprise($cabinet);
        $em->persist($taxe);
        $autorite = (new AutoriteFiscale())->setNom('DGI')->setAbreviation('DGI')->setTaxe($taxe);
        $autorite->setEntreprise($cabinet);
        $em->persist($autorite);

        $client = (new Client())->setNom('Client ' . $nom)->setExonere($clientExonere);
        $client->setEntreprise($cabinet);
        $em->persist($client);
        $assureur = (new Assureur())->setNom('Assureur ' . $nom);
        $assureur->setEntreprise($cabinet);
        $em->persist($assureur);
        $piste = (new Piste())->setNom('Piste')->setTypeAvenant(Piste::AVENANT_SOUSCRIPTION)
            ->setDescriptionDuRisque('Risque')->setExercice((int) date('Y'))->setClient($client);
        $piste->setEntreprise($cabinet)->setInvite($invite);
        $em->persist($piste);
        $cotation = (new Cotation())->setNom('Cotation')->setDuree(12);
        $cotation->setPiste($piste)->setAssureur($assureur)->setEntreprise($cabinet);
        $em->persist($cotation);

        $primeNette = (new Chargement())->setNom('Prime nette')->setFonction(Chargement::FONCTION_PRIME_NETTE);
        $primeNette->setEntreprise($cabinet);
        $em->persist($primeNette);
        $ligne = (new ChargementPourPrime())->setNom('Prime nette')->setMontantFlatExceptionel(10000.0)
            ->setType($primeNette)->setCotation($cotation);
        $ligne->setEntreprise($cabinet);
        $em->persist($ligne);
        $cotation->addChargement($ligne);

        $type = (new TypeRevenu())->setNom('Commission')->setPourcentage(10.0)->setShared(false)
            ->setMultipayments(true)->setTypeChargement($primeNette)->setRedevable(TypeRevenu::REDEVABLE_ASSUREUR);
        $type->setEntreprise($cabinet);
        $em->persist($type);
        $revenu = (new RevenuPourCourtier())->setNom('Commission');
        $revenu->setCotation($cotation)->setTypeRevenu($type)->setEntreprise($cabinet);
        $em->persist($revenu);
        $cotation->addRevenu($revenu);
        $tranche = (new Tranche())->setNom('Tranche unique')->setPourcentage(100.0)
            ->setPayableAt(new \DateTimeImmutable('-10 days'))->setEcheanceAt(new \DateTimeImmutable('+20 days'));
        $tranche->setCotation($cotation)->setEntreprise($cabinet);
        $em->persist($tranche);
        $cotation->addTranche($tranche);
        $em->flush();
        // Une monnaie de travail, comme tout cabinet initialisé : le PDF écrit le montant en lettres.
        $em->getConnection()->executeStatement(
            'INSERT INTO monnaie (entreprise_id, nom, code, tauxusd, fonction, locale, created_at) VALUES (?, ?, ?, 1, 0, 0, NOW())',
            [$cabinet->getId(), 'Dollar américain', 'USD'],
        );

        return [
            'owner' => (int) $owner->getId(), 'entreprise' => (int) $cabinet->getId(), 'invite' => (int) $invite->getId(),
            'assureur' => (int) $assureur->getId(), 'client' => (int) $client->getId(), 'autorite' => (int) $autorite->getId(),
            'revenu' => (int) $revenu->getId(), 'tranche' => (int) $tranche->getId(), 'type' => (int) $type->getId(),
            'ligne' => (int) $ligne->getId(), 'taxe' => (int) $taxe->getId(),
        ];
    }

    /** Un brouillon portant une ligne (la commission entière), écrit hors de l'écran. */
    private function brouillon(array $c, string $nom, int $type = Note::TYPE_NOTE_DE_DEBIT, bool $avecLigne = true, int $adresse = Note::TO_ASSUREUR): int
    {
        $em = $this->em();
        $cabinet = $em->find(Entreprise::class, $c['entreprise']);
        $note = (new Note())->setNom($nom)->setType($type)->setAddressedTo($adresse)->setSignature('t')->setValidated(false);
        match ($adresse) {
            Note::TO_AUTORITE_FISCALE => $note->setAutoritefiscale($em->find(AutoriteFiscale::class, $c['autorite'])),
            Note::TO_CLIENT => $note->setClient($em->find(Client::class, $c['client'])),
            default => $note->setAssureur($em->find(Assureur::class, $c['assureur'])),
        };
        // L'invité qui l'écrit : le journal ne lit que les notes rattachées à un invité du cabinet.
        $note->setEntreprise($cabinet)->setInvite($em->find(Invite::class, $c['invite']));
        if ($avecLigne) {
            $article = (new Article())->setQuantite(1.0)
                ->setRevenuFacture($em->find(RevenuPourCourtier::class, $c['revenu']))
                ->setTranche($em->find(Tranche::class, $c['tranche']));
            $article->setEntreprise($cabinet);
            $note->addArticle($article);
        }
        $em->persist($note);
        $em->flush();

        return (int) $note->getId();
    }

    private function note(int $id): Note
    {
        $this->em()->clear();

        return $this->em()->find(Note::class, $id);
    }
}
