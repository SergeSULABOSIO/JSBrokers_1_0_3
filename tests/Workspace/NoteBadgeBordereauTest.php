<?php

namespace App\Tests\Workspace;

use App\Entity\Article;
use App\Entity\Bordereau;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Note;
use App\Entity\Utilisateur;
use App\Services\Canvas\Provider\List\NoteListCanvasProvider;
use App\Services\CanvasBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * UNE NOTE ISSUE D'UN BORDEREAU SE RECONNAÎT DANS LA LISTE.
 *
 * ── CE QUE CE BANC FERME ────────────────────────────────────────────────────────────
 * Rien ne distinguait une note de bordereau d'une note composée à la main : mêmes
 * montants, même statut, même ligne. Or les deux ne se corrigent pas de la même façon —
 * l'une se reprend dans son bordereau, l'autre ligne à ligne.
 *
 * ── LE PIÈGE QUE CE BANC NOMME ──────────────────────────────────────────────────────
 * L'indicateur a DEUX chemins de calcul, et il ne bascule sur celui du bordereau que si la
 * note n'a AUCUN article. Poser la provenance dans ce seul chemin laisserait sans badge
 * toute note qui porterait les deux — et personne ne s'en apercevrait, puisqu'un badge
 * absent ne ressemble à rien d'autre qu'à une note ordinaire.
 *
 * La provenance se lit donc sur la RELATION, jamais sur le chemin emprunté.
 */
class NoteBadgeBordereauTest extends KernelTestCase
{
    private const OWNER_EMAIL = 'phpunit-badge-bordereau@test.local';
    private const ENTREPRISE_NOM = 'PHPUnit Badge Bordereau SARL';
    private const REFERENCE_BORDEREAU = 'ZZ-BRD-DISTINCTIF';

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
        // Enfants d'abord, et `bordereau` APRÈS `note` : la note le référence.
        foreach (['article', 'note', 'bordereau'] as $table) {
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

    /** @return array{0: Entreprise, 1: Invite} */
    private function cabinet(): array
    {
        $em = $this->em();

        $user = new Utilisateur();
        $user->setEmail(self::OWNER_EMAIL)->setNom('PHPUnit')->setVerified(true)->setPassword('x');
        $em->persist($user);

        $entreprise = (new Entreprise())
            ->setNom(self::ENTREPRISE_NOM)->setLicence('LIC-BB')->setAdresse('1 rue du Test')
            ->setTelephone('+243000000000')->setRccm('RCCM-BB')->setIdnat('IDNAT-BB')->setNumimpot('IMP-BB');
        $entreprise->setUtilisateur($user);
        $user->setConnectedTo($entreprise);
        $em->persist($entreprise);

        $invite = (new Invite())->setNom('Administrateur')->setProprietaire(true);
        $invite->setUtilisateur($user)->setEntreprise($entreprise);
        $em->persist($invite);

        $em->flush();

        return [$entreprise, $invite];
    }

    private function note(Entreprise $e, Invite $invite, bool $avecBordereau, bool $avecArticle = false): Note
    {
        $em = $this->em();

        $note = (new Note())
            ->setNom('Note BB')->setReference('BB-' . uniqid())
            ->setType(Note::TYPE_NOTE_DE_DEBIT)->setAddressedTo(Note::TO_ASSUREUR)
            ->setValidated(true)->setSignature('sig')
            ->setSentAt(new \DateTimeImmutable('now'));
        $note->setInvite($invite)->setEntreprise($e);

        if ($avecBordereau) {
            $bordereau = (new Bordereau())
                ->setType(0)->setNom('Bordereau BB')->setReference(self::REFERENCE_BORDEREAU)
                ->setReceivedAt(new \DateTimeImmutable('now'))
                ->setPeriodeDebut(new \DateTimeImmutable('now'))
                ->setPeriodeFin(new \DateTimeImmutable('now'))
                ->setMontantComHtPayableNow(1000.0)
                ->setMontantTaxePayableNow(160.0);
            $bordereau->setInvite($invite)->setEntreprise($e);
            $em->persist($bordereau);
            $note->setBordereau($bordereau);
        }

        $em->persist($note);

        if ($avecArticle) {
            // Un article NU suffit : il ne porte aucun montant, mais il rend la collection
            // non vide — et c'est elle qui décide du chemin de calcul emprunté.
            $article = (new Article())->setQuantite(1.0);
            $article->setNote($note);
            $article->setInvite($invite)->setEntreprise($e);
            $em->persist($article);
            $note->addArticle($article);
        }

        $em->flush();

        return $note;
    }

    private function hydrater(Note $note): Note
    {
        /** @var CanvasBuilder $builder */
        $builder = static::getContainer()->get(CanvasBuilder::class);
        $builder->loadAllCalculatedValues($note);

        return $note;
    }

    /** Une note de bordereau porte son badge, et nomme le bordereau dont elle vient. */
    public function testUneNoteDeBordereauPorteSonBadgeEtSaReference(): void
    {
        [$entreprise, $invite] = $this->cabinet();
        $note = $this->hydrater($this->note($entreprise, $invite, true));

        self::assertSame('Bordereau', $note->bordereauAffiche);
        self::assertSame(self::REFERENCE_BORDEREAU, $note->bordereauReference,
            'Savoir qu\'une note vient d\'un bordereau ne dit pas DUQUEL.',
        );
    }

    /**
     * UNE NOTE ORDINAIRE RESTE MUETTE. Le rendu n'affiche un badge que si sa valeur n'est
     * pas vide : c'est ce qui évite de marquer « ordinaire » le cas courant, et c'est ce
     * qui rend l'autre badge visible d'un coup d'œil.
     */
    public function testUneNoteOrdinaireNePorteAucunBadge(): void
    {
        [$entreprise, $invite] = $this->cabinet();
        $note = $this->hydrater($this->note($entreprise, $invite, false, true));

        self::assertNull($note->bordereauAffiche);
        self::assertNull($note->bordereauReference);
    }

    /**
     * ⚠ LE PIÈGE : UNE NOTE QUI PORTE LES DEUX.
     *
     * L'indicateur ne bascule sur le chemin du bordereau que si la note n'a AUCUN article.
     * Une note qui porterait les deux passe par l'autre chemin — et resterait sans badge si
     * la provenance n'y était pas posée aussi. Elle est pourtant bien liée à un bordereau.
     */
    public function testUneNoteQuiPorteBordereauEtArticlesGardeSonBadge(): void
    {
        [$entreprise, $invite] = $this->cabinet();
        $note = $this->hydrater($this->note($entreprise, $invite, true, true));

        self::assertSame('Bordereau', $note->bordereauAffiche,
            'La provenance se lit sur la RELATION, jamais sur le chemin de calcul emprunté.',
        );
        self::assertSame(self::REFERENCE_BORDEREAU, $note->bordereauReference);
    }

    /**
     * LE CANEVAS DÉCLARE LE BADGE, AU NIVEAU NEUTRE.
     *
     * Les niveaux existants disent tous une urgence ou une action à mener — critique,
     * exigible, rétro à payer. Une PROVENANCE n'en est pas une : lui emprunter le cobalt
     * ferait croire à un geste attendu sur ces notes-là.
     */
    public function testLeCanevasDeclareLeBadgeSansNiveauDUrgence(): void
    {
        /** @var NoteListCanvasProvider $provider */
        $provider = static::getContainer()->get(NoteListCanvasProvider::class);
        $colonne = $provider->getCanvas()['colonne_principale'];

        self::assertSame(
            [['attribut_code' => 'bordereauAffiche']],
            $colonne['badges'] ?? null,
            'Un seul badge, et aucun niveau : ni `niveau_fixe`, ni `attribut_niveau`.',
        );

        $codes = array_column($colonne['textes_secondaires'], 'attribut_code');
        self::assertContains('bordereauReference', $codes,
            'La référence du bordereau doit rejoindre la ligne secondaire, à côté de « Réf ».',
        );
    }
}
