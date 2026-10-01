<?php

namespace App\Tests\Services;

use App\Entity\Bordereau;
use App\Entity\Client;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Note;
use App\Entity\Paiement;
use App\Entity\Utilisateur;
use App\Services\JSBDynamicSearchService;
use App\Services\Search\NoteReglementScope;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * LES CHIPS DE LA RUBRIQUE NOTES CHANGENT RÉELLEMENT CE QU'ON VOIT.
 *
 * ── POURQUOI CE BANC, ET PAS SEULEMENT UN TEST DE DÉCLARATION ───────────────────────
 * Un chip déclaré n'est pas un chip qui filtre. Ce projet l'a déjà payé : un critère dont
 * l'opérateur était inconnu de l'API était IGNORÉ EN SILENCE et renvoyait l'ensemble
 * complet — la requête partait bien, portait bien le critère, et ne filtrait rien. Un test
 * écrit avec cet opérateur trouvait donc le même total que sans filtre, et le validait.
 *
 * On passe donc par le moteur lui-même, avec les clés de critère que les chips posent, et
 * l'on vérifie des COMPTES et des IDENTIFIANTS — jamais la simple présence d'une ligne.
 *
 * ── CE QUI EST SEMÉ ─────────────────────────────────────────────────────────────────
 * Quatre notes d'un même cabinet, une par état, plus une note d'un cabinet VOISIN. Les
 * montants viennent d'un bordereau : c'est le cas dominant en production, et c'est le
 * second chemin de calcul — celui qu'on oublie.
 */
class JSBDynamicSearchServiceNoteTest extends KernelTestCase
{
    private const OWNER_EMAIL = 'phpunit-chips-note@test.local';
    private const VOISIN_EMAIL = 'phpunit-chips-note-voisin@test.local';
    private const ENTREPRISE_NOM = 'PHPUnit Chips Note SARL';
    private const ENTREPRISE_VOISINE = 'PHPUnit Chips Note Voisine SARL';

    private Entreprise $entreprise;
    /** @var array<string, Note> état => note */
    private array $notes = [];
    private Note $noteVoisine;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->cleanUp();
        $this->seed();
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
        $emails = [self::OWNER_EMAIL, self::VOISIN_EMAIL];
        $noms = [self::ENTREPRISE_NOM, self::ENTREPRISE_VOISINE];

        $conn->executeStatement(
            'UPDATE utilisateur SET connected_to_id = NULL WHERE email IN (:emails)',
            ['emails' => $emails],
            ['emails' => \Doctrine\DBAL\ArrayParameterType::STRING],
        );
        // ⚠ `bordereau` APRÈS `note` : la note le référence, et le bordereau référence
        // lui-même l'invité. L'omettre fait échouer la suppression de l'invité.
        foreach (['paiement', 'note', 'bordereau', 'client'] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t
                 JOIN entreprise e ON t.entreprise_id = e.id
                 WHERE e.nom IN (:noms)",
                ['noms' => $noms],
                ['noms' => \Doctrine\DBAL\ArrayParameterType::STRING],
            );
        }
        $conn->executeStatement(
            'DELETE i FROM invite i
             LEFT JOIN utilisateur u ON i.utilisateur_id = u.id
             LEFT JOIN entreprise e ON i.entreprise_id = e.id
             WHERE u.email IN (:emails) OR e.nom IN (:noms)',
            ['emails' => $emails, 'noms' => $noms],
            ['emails' => \Doctrine\DBAL\ArrayParameterType::STRING, 'noms' => \Doctrine\DBAL\ArrayParameterType::STRING],
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

    private function seed(): void
    {
        $em = $this->em();

        [$this->entreprise, $invite] = $this->cabinet(self::OWNER_EMAIL, self::ENTREPRISE_NOM);
        [$voisine, $inviteVoisin] = $this->cabinet(self::VOISIN_EMAIL, self::ENTREPRISE_VOISINE);

        $this->notes[NoteReglementScope::IMPAYEE] = $this->note($this->entreprise, $invite, 'IMPAYEE', 1000.0, 0.0);
        $this->notes[NoteReglementScope::PARTIELLE] = $this->note($this->entreprise, $invite, 'PARTIELLE', 1000.0, 400.0);
        $this->notes[NoteReglementScope::REGLEE] = $this->note($this->entreprise, $invite, 'REGLEE', 1000.0, 1000.0);
        $this->notes[NoteReglementScope::SANS_MONTANT] = $this->note($this->entreprise, $invite, 'ZERO', 0.0, 0.0);

        // Le voisin a une note impayée, elle aussi : si le scope du cabinet fuyait, le chip
        // « Impayées » en rendrait deux.
        $this->noteVoisine = $this->note($voisine, $inviteVoisin, 'VOISIN', 1000.0, 0.0);

        $em->flush();
    }

    /** @return array{0: Entreprise, 1: Invite} */
    private function cabinet(string $email, string $nom): array
    {
        $em = $this->em();

        $user = new Utilisateur();
        $user->setEmail($email)->setNom('PHPUnit')->setVerified(true)->setPassword('x');
        $em->persist($user);

        $entreprise = (new Entreprise())
            ->setNom($nom)->setLicence('LIC-CN')->setAdresse('1 rue du Test')
            ->setTelephone('+243000000000')->setRccm('RCCM-CN')->setIdnat('IDNAT-CN')->setNumimpot('IMP-CN');
        $entreprise->setUtilisateur($user);
        $user->setConnectedTo($entreprise);
        $em->persist($entreprise);

        $invite = (new Invite())->setNom('Administrateur')->setProprietaire(true);
        $invite->setUtilisateur($user)->setEntreprise($entreprise);
        $em->persist($invite);

        $em->flush();

        return [$entreprise, $invite];
    }

    private function note(Entreprise $e, Invite $invite, string $marqueur, float $ht, float $paye): Note
    {
        $em = $this->em();

        $bordereau = (new Bordereau())
            ->setType(0)->setNom('BRD ' . $marqueur)->setReference('BRD-CN-' . uniqid())
            ->setReceivedAt(new \DateTimeImmutable('now'))
            ->setPeriodeDebut(new \DateTimeImmutable('now'))
            ->setPeriodeFin(new \DateTimeImmutable('now'))
            ->setMontantComHtPayableNow($ht)
            ->setMontantTaxePayableNow(0.0);
        $bordereau->setInvite($invite)->setEntreprise($e);
        $em->persist($bordereau);

        $note = (new Note())
            ->setNom('ZZ-NOTE-' . $marqueur)->setReference('CN-' . $marqueur . '-' . uniqid())
            ->setType(Note::TYPE_NOTE_DE_DEBIT)->setAddressedTo(Note::TO_ASSUREUR)
            ->setValidated(true)->setSignature('sig')->setBordereau($bordereau)
            ->setSentAt(new \DateTimeImmutable('now'));
        $note->setInvite($invite)->setEntreprise($e);
        $em->persist($note);

        if ($paye > 0.0) {
            $paiement = (new Paiement())
                ->setMontant($paye)->setPaidAt(new \DateTimeImmutable('now'))
                ->setReference('PAY-CN-' . uniqid())->setNote($note);
            $paiement->setEntreprise($e)->setInvite($invite);
            $em->persist($paiement);
            // ⚠ LES DEUX CÔTÉS. `setNote()` seul laisse la collection inverse vide tant que
            // l'entité n'est pas re-hydratée, et le montant payé, qui la parcourt, rend zéro.
            $note->addPaiement($paiement);
        }

        return $note;
    }

    /** @return array{0: int, 1: list<int>} le total annoncé et les identifiants rendus */
    private function rechercher(?string $valeurChip): array
    {
        /** @var JSBDynamicSearchService $service */
        $service = static::getContainer()->get(JSBDynamicSearchService::class);

        $criteres = $valeurChip === null
            ? []
            : [NoteReglementScope::CRITERION_KEY => ['operator' => '=', 'value' => $valeurChip]];

        $resultat = $service->search(Note::class, $criteres, $this->entreprise, null, 1, 20);

        return [
            (int) $resultat['totalItems'],
            array_map(static fn (Note $n): int => (int) $n->getId(), $resultat['data']),
        ];
    }

    /**
     * CHAQUE CHIP REND EXACTEMENT SA NOTE — et pas celle d'à côté.
     *
     * @dataProvider lesQuatreEtats
     */
    public function testChaqueChipRendSaSeuleNote(string $valeur): void
    {
        [$total, $ids] = $this->rechercher($valeur);

        self::assertSame(1, $total, sprintf('Le chip « %s » doit rendre une seule note.', $valeur));
        self::assertSame([$this->notes[$valeur]->getId()], $ids);
    }

    /** @return iterable<string, array{0: string}> */
    public static function lesQuatreEtats(): iterable
    {
        yield 'Impayées' => [NoteReglementScope::IMPAYEE];
        yield 'Partielles' => [NoteReglementScope::PARTIELLE];
        yield 'Réglées' => [NoteReglementScope::REGLEE];
        yield 'Sans montant' => [NoteReglementScope::SANS_MONTANT];
    }

    /**
     * LES QUATRE CHIPS PARTITIONNENT LA RUBRIQUE : leur somme fait l'ensemble, et aucune
     * note n'est comptée deux fois. Sans cela, un chip laisserait des notes invisibles de
     * tous les autres — et personne ne s'en apercevrait.
     */
    public function testLesQuatreChipsPartitionnentLaRubrique(): void
    {
        [$totalSansFiltre, $idsSansFiltre] = $this->rechercher(null);

        $cumul = [];
        foreach (array_keys(NoteReglementScope::ETATS) as $valeur) {
            [, $ids] = $this->rechercher($valeur);
            $cumul = array_merge($cumul, $ids);
        }

        sort($cumul);
        sort($idsSansFiltre);

        self::assertSame(4, $totalSansFiltre, 'Les quatre notes du cabinet, et elles seules.');
        self::assertSame($idsSansFiltre, $cumul,
            'La réunion des quatre chips doit faire exactement la rubrique, sans doublon ni oubli.',
        );
    }

    /**
     * ⚠ LA NOTE DU CABINET VOISIN N'EST JAMAIS COMPTÉE — sous aucun chip, et sans chip.
     */
    public function testLeCabinetVoisinNEstJamaisCompte(): void
    {
        $idVoisin = $this->noteVoisine->getId();

        [, $idsSansFiltre] = $this->rechercher(null);
        self::assertNotContains($idVoisin, $idsSansFiltre);

        [$total, $ids] = $this->rechercher(NoteReglementScope::IMPAYEE);
        self::assertSame(1, $total, 'Le voisin a lui aussi une note impayée : elle ne doit pas s\'ajouter.');
        self::assertNotContains($idVoisin, $ids);
    }

    /**
     * ⚠ UNE VALEUR INCONNUE NE FAIT PAS LEVER — elle retombe sur la recherche standard.
     *
     * `__reglement_note__` n'est pas une colonne. Si le moteur la laissait passer au chemin
     * SQL parce que sa valeur ne voulait rien dire, Doctrine lèverait sur un champ inconnu :
     * une erreur 500 pour un filtre mal tapé.
     */
    public function testUneValeurInconnueRetombeSurLaRechercheStandard(): void
    {
        [$total, $ids] = $this->rechercher('farfelu');

        self::assertSame(4, $total, 'Critère retiré, recherche standard scopée au cabinet.');
        self::assertNotContains($this->noteVoisine->getId(), $ids);
    }

    /**
     * UN ENCAISSEMENT FAIT BASCULER LA NOTE D'UN CHIP À L'AUTRE, et les deux comptes bougent
     * en miroir. C'est ce qui prouve que le filtre lit l'état RÉEL, et non un drapeau figé
     * au moment du semis.
     */
    public function testUnEncaissementFaitBasculerLaNoteDUnChipALAutre(): void
    {
        $impayee = $this->notes[NoteReglementScope::IMPAYEE];

        [$avantImpayees] = $this->rechercher(NoteReglementScope::IMPAYEE);
        [$avantReglees] = $this->rechercher(NoteReglementScope::REGLEE);
        self::assertSame(1, $avantImpayees);
        self::assertSame(1, $avantReglees);

        $paiement = (new Paiement())
            ->setMontant(1000.0)->setPaidAt(new \DateTimeImmutable('now'))
            ->setReference('PAY-CN-BASCULE')->setNote($impayee);
        $paiement->setEntreprise($this->entreprise)->setInvite($impayee->getInvite());
        $this->em()->persist($paiement);
        $impayee->addPaiement($paiement);
        $this->em()->flush();

        [$apresImpayees, $idsImpayees] = $this->rechercher(NoteReglementScope::IMPAYEE);
        [$apresReglees, $idsReglees] = $this->rechercher(NoteReglementScope::REGLEE);

        self::assertSame(0, $apresImpayees, 'Elle a quitté les impayées.');
        self::assertNotContains($impayee->getId(), $idsImpayees);
        self::assertSame(2, $apresReglees, 'Et elle a rejoint les réglées.');
        self::assertContains($impayee->getId(), $idsReglees);
    }
}
