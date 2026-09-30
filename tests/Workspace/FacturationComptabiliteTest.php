<?php

namespace App\Tests\Workspace;

use App\Comptabilite\CourtierEcritureComptableService;
use App\Comptabilite\PlanComptable;
use App\Entity\Bordereau;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Note;
use App\Entity\Paiement;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * FACTURER ENTRE EN COMPTABILITÉ — et régler éteint la créance.
 *
 * ── CE QUE CE BANC PROTÈGE ──────────────────────────────────────────────────
 * Émettre une note ne produisait RIEN. Pas une écriture, pas un produit, pas une
 * créance — il n'existait même aucun compte où la loger. Une commission réclamée à un
 * assureur n'apparaissait ni au journal, ni au grand livre, ni au bilan : le cabinet
 * ne pouvait pas lire ce qu'on lui devait.
 *
 * Le fait générateur du produit est désormais l'ÉMISSION d'une note validée. Quatre
 * façons de rompre cela en silence, chacune fermée ici :
 *
 *   1. compter le produit DEUX FOIS — à l'émission puis au règlement —, ce qui
 *      doublerait le chiffre d'affaires sans qu'aucun total ne proteste, puisque
 *      chaque écriture reste équilibrée prise isolément ;
 *   2. solder un 411 qui n'est jamais né : le règlement d'une note que l'émission a
 *      ignorée rendrait le compte créditeur, c'est-à-dire une dette envers l'assureur
 *      qui vient de payer ;
 *   3. comptabiliser les BROUILLONS, ce qui ferait revivre rétroactivement tout
 *      l'historique du cabinet — les écritures étant dérivées, rien ne l'en empêche ;
 *   4. laisser un avoir sans effet sur la créance qu'il annule.
 *
 * ── POURQUOI DES NOTES DE BORDEREAU ─────────────────────────────────────────
 * Une note à articles exigerait toute la machinerie de commission (cotation,
 * chargement, revenu, échéance) pour prouver une règle qui n'en dépend pas. La note de
 * bordereau porte ses montants elle-même : c'est le même chemin comptable, en dix
 * lignes de semis. Le lien écran → journal, lui, est prouvé là où il vit, dans
 * {@see FacturationEcritureTest}.
 */
class FacturationComptabiliteTest extends KernelTestCase
{
    private const OWNER_EMAIL = 'phpunit-factcompta@test.local';
    private const ENTREPRISE_NOM = 'PHPUnit Facturation Compta SARL';
    private const PASSWORD = 'Test1234!';

    /** Exercice isolé : aucun autre banc n'y écrit. */
    private const EXERCICE = 2034;

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

    private function cleanUp(): void
    {
        $conn = $this->em()->getConnection();

        $conn->executeStatement(
            'UPDATE utilisateur SET connected_to_id = NULL WHERE email = :email',
            ['email' => self::OWNER_EMAIL],
        );
        foreach (['paiement', 'note', 'bordereau'] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t
                 JOIN entreprise e ON t.entreprise_id = e.id
                 WHERE e.nom = :nom",
                ['nom' => self::ENTREPRISE_NOM],
            );
        }
        $conn->executeStatement(
            'DELETE i FROM invite i
             LEFT JOIN utilisateur u ON i.utilisateur_id = u.id
             LEFT JOIN entreprise e ON i.entreprise_id = e.id
             WHERE u.email = :email OR e.nom = :nom',
            ['email' => self::OWNER_EMAIL, 'nom' => self::ENTREPRISE_NOM],
        );
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :nom', ['nom' => self::ENTREPRISE_NOM]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :email', ['email' => self::OWNER_EMAIL]);
    }

    /**
     * Un cabinet nu : ni capital, ni dépense, ni rétrocommission. Les seules écritures
     * seront celles des notes créées par chaque test — leurs totaux sont donc lisibles
     * sans soustraction.
     *
     * @return array{0: Entreprise, 1: Invite}
     */
    private function cabinet(): array
    {
        $em = $this->em();
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new Utilisateur();
        $user->setEmail(self::OWNER_EMAIL)->setNom('PHPUnit')->setVerified(true);
        $user->setPassword($hasher->hashPassword($user, self::PASSWORD));
        $em->persist($user);

        $entreprise = (new Entreprise())
            ->setNom(self::ENTREPRISE_NOM)
            ->setLicence('LIC-FC')->setAdresse('1 rue du Test')->setTelephone('+243000000000')
            ->setRccm('RCCM-FC')->setIdnat('IDNAT-FC')->setNumimpot('IMP-FC');
        $entreprise->setUtilisateur($user);
        $user->setConnectedTo($entreprise);
        $em->persist($entreprise);

        $invite = (new Invite())->setNom('Administrateur')->setProprietaire(true);
        $invite->setUtilisateur($user)->setEntreprise($entreprise);
        $em->persist($invite);

        $em->flush();

        return [$entreprise, $invite];
    }

    /**
     * Une note de bordereau, de HT et de taxe donnés. `$sentAt` date la PIÈCE : c'est
     * elle qui datera l'écriture d'émission, pas l'horodatage de création.
     */
    private function note(
        Entreprise $entreprise,
        Invite $invite,
        string $reference,
        float $ht,
        float $taxe,
        int $type = Note::TYPE_NOTE_DE_DEBIT,
        bool $validee = true,
        string $sentAt = '2034-03-10',
    ): Note {
        $em = $this->em();

        $bordereau = (new Bordereau())
            ->setType(0)->setNom('Bordereau ' . $reference)->setReference('BRD-' . $reference)
            ->setReceivedAt(new \DateTimeImmutable($sentAt))
            ->setPeriodeDebut(new \DateTimeImmutable($sentAt))
            ->setPeriodeFin(new \DateTimeImmutable($sentAt))
            ->setMontantComHtPayableNow($ht)
            ->setMontantTaxePayableNow($taxe);
        $bordereau->setInvite($invite)->setEntreprise($entreprise);
        $em->persist($bordereau);

        $note = (new Note())
            ->setNom('Note ' . $reference)->setReference($reference)
            ->setType($type)->setAddressedTo(Note::TO_ASSUREUR)
            ->setValidated($validee)->setSignature('sig')
            ->setBordereau($bordereau)
            ->setSentAt(new \DateTimeImmutable($sentAt));
        $note->setInvite($invite)->setEntreprise($entreprise);
        $em->persist($note);
        $em->flush();

        return $note;
    }

    private function payer(Entreprise $entreprise, Note $note, float $montant, string $date = '2034-04-20'): void
    {
        $paiement = (new Paiement())
            ->setMontant($montant)->setPaidAt(new \DateTimeImmutable($date))
            ->setReference('PAY-' . $note->getReference())
            ->setNote($note);
        $paiement->setEntreprise($entreprise);
        $this->em()->persist($paiement);
        $this->em()->flush();
    }

    private function documents(Entreprise $entreprise): array
    {
        // ⚠ LE SERVICE MET SES ÉCRITURES EN CACHE, par entreprise et pour toute la durée
        // du conteneur. On le prend APRÈS le dernier flush, jamais avant : autrement le
        // banc lirait une comptabilité antérieure à ses propres données.
        return static::getContainer()
            ->get(CourtierEcritureComptableService::class)
            ->documents($entreprise, self::EXERCICE);
    }

    /** @return array<string, array> les écritures du journal, indexées par pièce */
    private function journalParPiece(array $documents): array
    {
        $parPiece = [];
        foreach ($documents['journal']['ecritures'] as $ecriture) {
            $parPiece[$ecriture['piece']] = $ecriture;
        }

        return $parPiece;
    }

    /**
     * Le solde net (débit − crédit) d'un compte, lu sur la balance.
     *
     * ⚠ ON COMPARE EN CHAÎNE, ET IL LE FAUT. La balance et le grand livre tirent leurs
     * comptes d'`array_keys()` sur les agrégats — et PHP transforme toute clé numérique
     * en ENTIER. `$ligne['compte']` vaut donc `411`, pas `'411'`, et un `===` contre la
     * constante du plan comptable est silencieusement faux. Le journal, lui, porte bien
     * des chaînes : les deux lectures ne se ressemblent qu'en apparence.
     */
    private function soldeDe(array $documents, string $compte): float
    {
        foreach ($documents['balance']['lignes'] as $ligne) {
            if ((string) $ligne['compte'] === $compte) {
                return round($ligne['cloD'] - $ligne['cloC'], 2);
            }
        }

        return 0.0;
    }

    /**
     * LE GESTE ENTIER : facturer inscrit la créance et le produit, régler éteint la
     * créance — et ne touche PLUS au produit.
     */
    public function testFacturerInscritLaCreanceEtLeProduit(): void
    {
        [$entreprise, $invite] = $this->cabinet();
        $note = $this->note($entreprise, $invite, 'FC-DEBIT', 1000.0, 160.0);
        $this->payer($entreprise, $note, 400.0);

        $documents = $this->documents($entreprise);
        $parPiece = $this->journalParPiece($documents);

        // ── L'ÉMISSION ──────────────────────────────────────────────────────
        self::assertArrayHasKey('FC-DEBIT', $parPiece,
            'Émettre une note doit produire une écriture. C\'est tout l\'objet de ce lot : '
            . 'la facturation ne laissait aucune trace comptable.',
        );
        $emission = $parPiece['FC-DEBIT'];
        self::assertSame('facturation', $emission['type']);
        self::assertSame('2034-03-10', $emission['date']->format('Y-m-d'),
            'L\'écriture porte la date de la PIÈCE, pas celle de sa saisie : un exercice se '
            . 'coupe à une date, et le courtier doit pouvoir la porter.',
        );

        $debits = [];
        $credits = [];
        foreach ($emission['lignes'] as $l) {
            $debits[$l['compte']] = ($debits[$l['compte']] ?? 0.0) + $l['debit'];
            $credits[$l['compte']] = ($credits[$l['compte']] ?? 0.0) + $l['credit'];
        }
        self::assertEqualsWithDelta(1160.0, $debits[PlanComptable::CLIENTS] ?? 0.0, 0.01,
            'La créance naît pour le TTC : c\'est ce que l\'assureur doit.',
        );
        self::assertEqualsWithDelta(1000.0, $credits[PlanComptable::SERVICES_VENDUS] ?? 0.0, 0.01);
        self::assertEqualsWithDelta(160.0, $credits[PlanComptable::TVA_FACTUREE] ?? 0.0, 0.01);
        self::assertEqualsWithDelta(array_sum($debits), array_sum($credits), 0.01,
            'L\'écriture doit être équilibrée.',
        );

        // ── LE RÈGLEMENT ────────────────────────────────────────────────────
        $reglement = $parPiece['PAY-FC-DEBIT'] ?? null;
        self::assertNotNull($reglement);
        self::assertSame('encaissement', $reglement['type']);

        $comptesDuReglement = array_column($reglement['lignes'], 'compte');
        self::assertContains(PlanComptable::CLIENTS, $comptesDuReglement,
            'Le règlement éteint la créance.',
        );
        self::assertNotContains(PlanComptable::SERVICES_VENDUS, $comptesDuReglement,
            '⚠ RECOMPTER LE PRODUIT AU RÈGLEMENT DOUBLERAIT LE CHIFFRE D\'AFFAIRES — et '
            . 'chaque écriture resterait équilibrée, donc aucun total ne protesterait.',
        );
        self::assertNotContains(PlanComptable::TVA_FACTUREE, $comptesDuReglement,
            'La taxe a été collectée à la facturation ; la recollecter la déclarerait deux fois.',
        );

        // ── CE QUI EN RESTE DANS LES ÉTATS ──────────────────────────────────
        self::assertEqualsWithDelta(760.0, $this->soldeDe($documents, PlanComptable::CLIENTS), 0.01,
            'Facturé 1160, encaissé 400 : il reste 760 dus.',
        );
        self::assertEqualsWithDelta(1000.0, $documents['resultat']['totalProduits'], 0.01,
            'Le chiffre d\'affaires est le FACTURÉ : il ne dépend pas de ce qui a été payé.',
        );
    }

    /** La créance se lit au GRAND LIVRE et au BILAN, là où le courtier la cherche. */
    public function testLaCreanceApparaitAuGrandLivreEtAuBilan(): void
    {
        [$entreprise, $invite] = $this->cabinet();
        $this->note($entreprise, $invite, 'FC-GL', 500.0, 80.0);

        $documents = $this->documents($entreprise);

        $compte411 = null;
        foreach ($documents['grandLivre'] as $compte) {
            if ((string) $compte['compte'] === PlanComptable::CLIENTS) {
                $compte411 = $compte;
            }
        }
        self::assertNotNull($compte411, 'Le compte 411 doit exister au grand livre.');
        self::assertEqualsWithDelta(580.0, $compte411['solde'], 0.01);

        $creances = null;
        foreach ($documents['bilan']['actif'] as $poste) {
            if (str_starts_with($poste['libelle'], 'Créances')) {
                $creances = $poste['cloture'];
            }
        }
        self::assertEqualsWithDelta(580.0, $creances, 0.01,
            'Une commission facturée et non encaissée est un ACTIF. Sans ce poste, elle '
            . 'n\'apparaissait nulle part, et le bilan sous-estimait le cabinet d\'autant.',
        );
    }

    /**
     * ⚠ UN BROUILLON N'EST PAS UNE FACTURE — et c'est ce qui rend la bascule sûre.
     *
     * Les écritures étant DÉRIVÉES, comptabiliser toute note ferait revivre
     * rétroactivement tout l'historique du cabinet, essais et brouillons compris. Aucune
     * note ancienne n'étant validée, l'histoire garde exactement les écritures qu'elle
     * avait : le règlement d'une note non validée reste l'encaissement d'hier.
     */
    public function testUneNoteNonValideeNeCreeAucuneCreanceEtGardeLEcritureDHier(): void
    {
        [$entreprise, $invite] = $this->cabinet();
        $note = $this->note($entreprise, $invite, 'FC-BROUILLON', 1000.0, 160.0, Note::TYPE_NOTE_DE_DEBIT, false);
        $this->payer($entreprise, $note, 580.0);

        $documents = $this->documents($entreprise);
        $parPiece = $this->journalParPiece($documents);

        self::assertArrayNotHasKey('FC-BROUILLON', $parPiece,
            'Un brouillon ne produit aucune écriture d\'émission.',
        );
        self::assertEqualsWithDelta(0.0, $this->soldeDe($documents, PlanComptable::CLIENTS), 0.01,
            'Aucune créance ne naît d\'un brouillon.',
        );

        // L'écriture d'hier, au mot près : le produit naît de l'encaissement, proratisé.
        $comptes = array_column($parPiece['PAY-FC-BROUILLON']['lignes'], 'compte');
        self::assertContains(PlanComptable::SERVICES_VENDUS, $comptes);
        self::assertNotContains(PlanComptable::CLIENTS, $comptes,
            '⚠ SOLDER UN 411 QUI N\'EST JAMAIS NÉ le rendrait créditeur : une dette envers '
            . 'l\'assureur qui vient de payer.',
        );
        self::assertEqualsWithDelta(500.0, $documents['resultat']['totalProduits'], 0.01,
            'La part HT des 580 encaissés — le régime d\'avant, inchangé.',
        );
    }

    /** Un avoir annule la créance et le produit : il est le miroir exact de la facture. */
    public function testUnAvoirRetrancheLaCreanceEtLeProduit(): void
    {
        [$entreprise, $invite] = $this->cabinet();
        $this->note($entreprise, $invite, 'FC-FACT', 1000.0, 160.0);
        $this->note($entreprise, $invite, 'FC-AVOIR', 400.0, 64.0, Note::TYPE_NOTE_DE_CREDIT, true, '2034-05-05');

        $documents = $this->documents($entreprise);
        $avoir = $this->journalParPiece($documents)['FC-AVOIR'] ?? null;

        self::assertNotNull($avoir);
        self::assertSame('avoir_emis', $avoir['type']);

        self::assertEqualsWithDelta(696.0, $this->soldeDe($documents, PlanComptable::CLIENTS), 0.01,
            '1160 facturés − 464 annulés : on ne réclame plus que 696.',
        );
        self::assertEqualsWithDelta(600.0, $documents['resultat']['totalProduits'], 0.01,
            'Un avoir est une réduction de produit : 1000 − 400.',
        );
    }
}
