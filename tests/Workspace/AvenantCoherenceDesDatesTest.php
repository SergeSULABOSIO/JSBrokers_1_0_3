<?php

namespace App\Tests\Workspace;

use App\Entity\Avenant;
use App\Service\Workspace\ChampsObligatoiresInspector;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\FormFactoryInterface;

/**
 * UNE POLICE COUVRE UNE PÉRIODE : SA FIN VIENT APRÈS SON DÉBUT.
 *
 * ── POURQUOI CE TEST EXISTE ─────────────────────────────────────────────────────────
 *
 * La règle paraît trop évidente pour mériter du code. Elle n'était pourtant appliquée
 * NULLE PART : ni validateur Symfony (le projet n'en pose aucun sur ses entités métier),
 * ni service, ni contrainte CHECK — aucune des 79 migrations n'en porte. Une police dont
 * l'échéance précède la date d'effet s'enregistrait donc en silence, par le formulaire
 * comme par un plan de Ket.
 *
 * Et rien ne venait la rattraper en aval : {@see \App\Services\AvenantActionService}
 * dérive la durée par $startingAt->diff($endingAt)->days, qui est TOUJOURS positif. Une
 * fin antérieure au début produisait donc une durée parfaitement plausible. Tout ce qui
 * pend à cette période — échéancier, primes exigibles, commissions, fenêtres de
 * renouvellement — reposait alors sur une durée fausse, sans qu'aucune erreur ne le dise.
 *
 * ── CE QUE CE TEST VERROUILLE ───────────────────────────────────────────────────────
 *
 * La règle est exercée SANS le modèle : elle doit tenir même si Ket se trompe. C'est tout
 * l'objet d'une règle appliquée côté serveur plutôt que confiée au prompt.
 *
 * ⚠ AUCUNE DATE N'EST FIGÉE ICI : elles sont toutes calculées à partir de l'instant du
 * test. Un test qui code ses dates en dur finit par mesurer le calendrier.
 */
class AvenantCoherenceDesDatesTest extends TestCase
{
    private function inspecteur(): ChampsObligatoiresInspector
    {
        // Les dépendances ne servent pas à cette règle : elle ne lit que l'entité.
        return new ChampsObligatoiresInspector(
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(FormFactoryInterface::class),
        );
    }

    private function avenant(string $debut, string $fin): Avenant
    {
        return (new Avenant())
            ->setStartingAt(new \DateTimeImmutable($debut))
            ->setEndingAt(new \DateTimeImmutable($fin));
    }

    public function testUnePoliceQuiFinitAvantDeCommencerEstRefusee(): void
    {
        $incoherences = $this->inspecteur()->incoherencesMetier(
            $this->avenant('+30 days', '+10 days'),
            'Avenant',
        );

        self::assertArrayHasKey('endingAt', $incoherences, 'La date de fin doit être reprochée.');
        self::assertStringContainsString('postérieure', $incoherences['endingAt'][0]);
    }

    /**
     * ⚠ UNE DURÉE NULLE EST LÉGITIME, et ce test est là pour empêcher qu'on la refuse.
     *
     * Un avenant de RÉSILIATION porte startingAt == endingAt : il marque l'INSTANT où la
     * police sort du portefeuille, pas une période de couverture. C'est ce que construit
     * {@see \App\Ai\Mouvement\MouvementAvenantBuilder} et ce qu'exige déjà
     * MouvementAvenantTest (« 2026-06-15 » en date de début comme de fin).
     *
     * La première version de cette règle refusait l'égalité « par bon sens » : elle a
     * cassé l'exécution d'une résiliation. Le bon sens n'est pas une source de règle
     * métier — le code qui construit le mouvement en est une.
     */
    public function testUneResiliationADureeNulleEstAcceptee(): void
    {
        $jour = (new \DateTimeImmutable('+15 days'))->format('Y-m-d H:i:s');

        self::assertSame(
            [],
            $this->inspecteur()->incoherencesMetier($this->avenant($jour, $jour), 'Avenant'),
            'Une résiliation sort la police à une date donnée : début et fin s’y confondent.',
        );
    }

    public function testUnePolicePeriodeNormaleEstAcceptee(): void
    {
        self::assertSame(
            [],
            $this->inspecteur()->incoherencesMetier($this->avenant('now', '+1 year'), 'Avenant'),
        );
    }

    /**
     * UNE POLICE INCOMPLÈTE N'EST PAS UNE POLICE INCOHÉRENTE. Les dates manquantes
     * relèvent des champs obligatoires, qui les réclament avec leur propre message ;
     * les reprocher ici ferait dire deux choses différentes du même manque.
     */
    public function testUneDateAbsenteNeDeclenchePasLaRegle(): void
    {
        $inspecteur = $this->inspecteur();

        self::assertSame([], $inspecteur->incoherencesMetier(new Avenant(), 'Avenant'));

        $sansFin = (new Avenant())->setStartingAt(new \DateTimeImmutable('+30 days'));
        self::assertSame([], $inspecteur->incoherencesMetier($sansFin, 'Avenant'));
    }

    /**
     * MÊME DISCIPLINE QUE LES AUTRES RÈGLES DE COMBINAISON : on ne reproche que ce que
     * l'écran courant peut corriger. Un formulaire qui n'expose aucune des deux dates
     * recevrait sinon un refus qu'il ne saurait pas lever.
     */
    public function testUnEcranQuiNExposeAucuneDateNeRecoitAucunReproche(): void
    {
        self::assertSame(
            [],
            $this->inspecteur()->incoherencesMetier(
                $this->avenant('+30 days', '+10 days'),
                'Avenant',
                ['numero', 'client'],
            ),
        );
    }

    /**
     * Quand seule la date d'effet est éditable, c'est ELLE qu'on nomme : reprocher
     * « endingAt » sur un écran qui ne la montre pas serait incompréhensible.
     */
    public function testLeReprocheViseLeChampQueLEcranExpose(): void
    {
        self::assertArrayHasKey(
            'startingAt',
            $this->inspecteur()->incoherencesMetier(
                $this->avenant('+30 days', '+10 days'),
                'Avenant',
                ['startingAt'],
            ),
        );
    }
}
