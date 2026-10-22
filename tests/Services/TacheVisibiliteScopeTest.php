<?php

namespace App\Tests\Services;

use App\Entity\Client;
use App\Entity\Document;
use App\Entity\Feedback;
use App\Entity\Invite;
use App\Entity\Tache;
use App\Service\Workspace\WorkspaceAccessResolver;
use App\Services\Search\TacheVisibiliteScope;
use PHPUnit\Framework\TestCase;

/**
 * LA FRONTIÈRE DES TÂCHES (décisions de Serge du 2026-10-21) — la règle seule, en mémoire.
 *
 * Un invité voit une tâche si elle lui est assignée ou s'il en est l'auteur ; si elle est
 * assignée à — ou créée par — son RESPONSABLE ou l'un de ses ASSISTANTS (un seul niveau) ; si
 * elle n'a pas d'exécutant. Un administrateur (propriétaire ou délégué) voit tout. Retours et
 * documents héritent de la tâche — sauf un document rattaché AUSSI à une autre fiche.
 */
final class TacheVisibiliteScopeTest extends TestCase
{
    private Invite $moi;
    private Invite $responsable;
    private Invite $assistant;
    private Invite $assistantDeLAssistant;
    private Invite $collegue;

    protected function setUp(): void
    {
        $nommer = fn (string $nom): Invite => (new Invite())->setNom($nom)->setProprietaire(false);
        $this->moi = $nommer('Moi');
        $this->responsable = $nommer('Mon responsable');
        $this->assistant = $nommer('Mon assistant');
        $this->assistantDeLAssistant = $nommer("L'assistant de mon assistant");
        $this->collegue = $nommer('Un collègue sans lien');

        $this->responsable->addAssistant($this->moi);
        $this->moi->addAssistant($this->assistant);
        $this->assistant->addAssistant($this->assistantDeLAssistant);
    }

    private function scope(bool $administrateur = false): TacheVisibiliteScope
    {
        $resolveur = $this->createMock(WorkspaceAccessResolver::class);
        $resolveur->method('estAdministrateurDuCabinet')->willReturn($administrateur);

        return new TacheVisibiliteScope($resolveur);
    }

    private function tache(?Invite $executant, ?Invite $auteur): Tache
    {
        $tache = (new Tache())->setDescription('t')->setExecutor($executant);
        $tache->setInvite($auteur);

        return $tache;
    }

    public function testCeQuiMeConcerneDirectement(): void
    {
        $s = $this->scope();
        self::assertTrue($s->estVisible($this->tache($this->moi, $this->collegue), $this->moi), 'assignée à moi');
        self::assertTrue($s->estVisible($this->tache($this->collegue, $this->moi), $this->moi), 'que j’ai assignée (auteur)');
        self::assertTrue($s->estVisible($this->tache(null, $this->collegue), $this->moi), 'sans exécutant');
    }

    public function testMonResponsable(): void
    {
        $s = $this->scope();
        self::assertTrue($s->estVisible($this->tache($this->responsable, $this->collegue), $this->moi), 'assignée à mon responsable');
        self::assertTrue($s->estVisible($this->tache($this->collegue, $this->responsable), $this->moi), 'créée par mon responsable');
    }

    public function testMesAssistantsSurUnSeulNiveau(): void
    {
        $s = $this->scope();
        self::assertTrue($s->estVisible($this->tache($this->assistant, $this->collegue), $this->moi), 'assignée à mon assistant');
        self::assertTrue($s->estVisible($this->tache($this->collegue, $this->assistant), $this->moi), 'créée par mon assistant');
        self::assertFalse($s->estVisible($this->tache($this->assistantDeLAssistant, $this->collegue), $this->moi), 'pas les assistants de mes assistants');
        self::assertFalse($s->estVisible($this->tache($this->collegue, $this->assistantDeLAssistant), $this->moi));
    }

    public function testCeQuiNeMeConcernePas(): void
    {
        $s = $this->scope();
        self::assertFalse($s->estVisible($this->tache($this->collegue, $this->collegue), $this->moi), 'un collègue sans lien');
        // Créée automatiquement, sans auteur humain : la seule règle de l'exécutant s'applique.
        self::assertFalse($s->estVisible($this->tache($this->collegue, null), $this->moi));
        self::assertTrue($s->estVisible($this->tache($this->moi, null), $this->moi));
    }

    public function testUnAdministrateurVoitTout(): void
    {
        self::assertTrue($this->scope(true)->estVisible($this->tache($this->collegue, $this->collegue), $this->moi));
    }

    public function testUnLienRetireAgitAussitot(): void
    {
        $s = $this->scope();
        $tache = $this->tache($this->responsable, $this->collegue);
        self::assertTrue($s->estVisible($tache, $this->moi));
        $this->responsable->removeAssistant($this->moi);
        self::assertFalse($s->estVisible($tache, $this->moi), 'aucun cache : le lien retiré ferme la vue tout de suite');
    }

    public function testLesRetoursHeritentDeLaTache(): void
    {
        $s = $this->scope();
        $invisible = $this->tache($this->collegue, $this->collegue);
        self::assertFalse($s->feedbackVisible((new Feedback())->setTache($invisible), $this->moi));
        self::assertTrue($s->feedbackVisible((new Feedback())->setTache($this->tache($this->moi, null)), $this->moi));
    }

    public function testUnDocumentNeDisparaitQueSiLaTacheInvisibleEstSonSeulRattachement(): void
    {
        $s = $this->scope();
        $invisible = $this->tache($this->collegue, $this->collegue);

        self::assertFalse($s->documentVisible((new Document())->setTache($invisible), $this->moi), 'seul rattachement : invisible');
        self::assertFalse($s->documentVisible((new Document())->setFeedback((new Feedback())->setTache($invisible)), $this->moi), 'via le retour d’une tâche invisible');
        self::assertTrue($s->documentVisible((new Document())->setTache($invisible)->setClient(new Client()), $this->moi), 'aussi rattaché à un client : visible par ce chemin');
        self::assertTrue($s->documentVisible((new Document())->setClient(new Client()), $this->moi), 'sans tâche : non concerné');
    }

    /**
     * UNE PIÈCE EST VISIBLE SI AU MOINS UN RATTACHEMENT L'EST (Serge, 2026-10-21) : une fiche
     * sans scope dédié compte comme visible ; tâche et congé passent par LEUR scope.
     */
    public function testUnePieceAPlusieursRattachements(): void
    {
        $s = $this->scope(); // ni administrateur, ni valideur des congés
        $tacheInvisible = $this->tache($this->collegue, $this->collegue);
        $congeDuCollegue = (new \App\Entity\DemandeConge())->setAgent($this->collegue);
        $monConge = (new \App\Entity\DemandeConge())->setAgent($this->moi);

        self::assertFalse($s->documentVisible((new Document())->setTache($tacheInvisible)->setDemandeConge($congeDuCollegue), $this->moi), 'tâche invisible + congé invisible');
        self::assertTrue($s->documentVisible((new Document())->setTache($tacheInvisible)->setDemandeConge($congeDuCollegue)->setPiste(new \App\Entity\Piste()), $this->moi), '… + une piste du cabinet');
        self::assertFalse($s->documentVisible((new Document())->setDemandeConge($congeDuCollegue), $this->moi), 'le congé d’un autre seul');
        self::assertTrue($s->documentVisible((new Document())->setDemandeConge($monConge), $this->moi), 'mon congé');
        self::assertTrue($s->documentVisible(new Document(), $this->moi), 'aucun rattachement : le cabinet');
    }

    /** Pas de lecteur (anonyme, non-membre) : AUCUNE tâche, pas même celles sans exécutant. */
    public function testSansLecteurAucuneTache(): void
    {
        $s = $this->scope();
        self::assertFalse($s->estVisible($this->tache(null, null), null), 'sans exécutant : rien non plus');
        self::assertFalse($s->estVisible($this->tache($this->moi, $this->moi), null));
        self::assertSame([], $s->identifiantsDuCercle(null));
        self::assertSame(TacheVisibiliteScope::RIEN, TacheVisibiliteScope::conditionSurLaTache('t', 'p', []));
    }
}
