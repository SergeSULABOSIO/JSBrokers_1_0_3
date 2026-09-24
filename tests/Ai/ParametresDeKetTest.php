<?php

namespace App\Tests\Ai;

use App\Ai\AiContextBuilder;
use App\Ai\Boussole\PlanDuJourService;
use App\Ai\Reglage\ApplicationDesReglages;
use App\Ai\Reglage\ReglagesDeKet;
use App\Ai\Tool\VigieEcheancesTool;
use App\Repository\PlateformeParametresRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * LES QUATRE SEUILS RÉGLABLES ARRIVENT JUSQU'AU COMPORTEMENT.
 *
 * ── CE QUE CE FICHIER PROTÈGE ───────────────────────────────────────────────
 * Un écran de réglage qui n'a pas d'effet est pire que pas d'écran du tout :
 * l'agent croit avoir agi. Le danger est d'autant plus réel ici que chaque seuil
 * garde sa CONSTANTE comme valeur de naissance — il suffit d'oublier un
 * `self::` pour que le réglage s'enregistre, s'affiche, et ne change rien.
 *
 * On vérifie donc les deux bouts : la valeur bornée que rend le service, et
 * l'effet observable dans la classe qui l'utilise.
 *
 * ⚠ SINGLETON GLOBAL SANS ROLLBACK : purge en setUp ET en tearDown.
 */
class ParametresDeKetTest extends KernelTestCase
{
    use JeuDeTestKetTrait;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->purger();
    }

    protected function tearDown(): void
    {
        $this->purger();
        parent::tearDown();
    }

    private function purger(): void
    {
        $conn = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $conn->executeStatement('DELETE FROM ket_reglage_journal');
        $conn->executeStatement('DELETE FROM plateforme_parametres');
        static::getContainer()->get(ReglagesDeKet::class)->refresh();
    }

    private function reglages(): ReglagesDeKet
    {
        return static::getContainer()->get(ReglagesDeKet::class);
    }

    /** Écrit un seuil directement, sans passer par la validation de la console. */
    private function poser(string $clef, int $valeur): void
    {
        $repository = static::getContainer()->get(PlateformeParametresRepository::class);
        $repository->getSingleton()->setKetReglages(['parametres' => [$clef => $valeur]]);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $this->reglages()->refresh();
    }

    /** Sans réglage, chaque seuil vaut sa constante : le déploiement ne change rien. */
    public function testSansReglageChaqueSeuilVautSaConstante(): void
    {
        $reglages = $this->reglages();

        foreach (ReglagesDeKet::PARAMETRES as $clef => $regle) {
            self::assertSame(
                $regle['defaut'],
                $reglages->parametre($clef),
                sprintf('« %s » doit valoir sa valeur de naissance.', $clef),
            );
            self::assertFalse($reglages->estPersonnalise($clef));
        }
    }

    /**
     * HORS BORNES = IGNORÉ, pas « accepté puis appliqué ». Une valeur peut arriver
     * en base par un import ou un script, sans passer par l'écran : l'appelant ne
     * doit jamais avoir à se défendre lui-même.
     */
    public function testUneValeurHorsBornesEnBaseEstIgnoree(): void
    {
        foreach ([-5, 0, 100000] as $absurde) {
            $this->poser('vigie.horizon_jours', $absurde);

            self::assertSame(
                30,
                $this->reglages()->parametre('vigie.horizon_jours'),
                sprintf('La valeur %d devait être ignorée au profit du défaut.', $absurde),
            );
        }
    }

    public function testUneValeurDansLesBornesEstRendue(): void
    {
        $this->poser('vigie.horizon_jours', 45);

        self::assertSame(45, $this->reglages()->parametre('vigie.horizon_jours'));
        self::assertTrue($this->reglages()->estPersonnalise('vigie.horizon_jours'));
    }

    public function testUneClefInconnueLeve(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->reglages()->parametre('clef.qui.nexiste.pas');
    }

    /**
     * L'EFFET RÉEL, ET NON LA SEULE VALEUR RENDUE. Le schéma de l'outil annonce au
     * modèle son horizon par défaut : si le réglage ne l'atteignait pas, Ket
     * continuerait d'annoncer trente jours tout en en regardant quarante-cinq.
     */
    public function testLHorizonRegleAtteintLeSchemaDeLaVigie(): void
    {
        $outil = static::getContainer()->get(VigieEcheancesTool::class);

        $avant = json_encode($outil->schema(), JSON_UNESCAPED_UNICODE);
        self::assertStringContainsString('défaut 30', (string) $avant);

        $this->poser('vigie.horizon_jours', 45);

        $apres = json_encode($outil->schema(), JSON_UNESCAPED_UNICODE);
        self::assertStringContainsString(
            'défaut 45',
            (string) $apres,
            'Le seuil réglé doit atteindre ce que Ket annonce au modèle.'
        );
    }

    /**
     * LA PROFONDEUR DU FIL EST CELLE QUI COÛTE. C'est le seul des quatre seuils dont
     * l'effet se mesure en jetons, et la console l'annonce comme tel : si le réglage
     * n'atteignait pas la fenêtre d'historique, le chiffre affiché serait un mensonge.
     */
    public function testLaProfondeurDuFilChangeLaFenetreDHistorique(): void
    {
        [$entreprise, $invite] = $this->jeuDeTestKet();
        $builder = static::getContainer()->get(AiContextBuilder::class);

        $conversation = new \App\Entity\AssistantConversation();
        for ($i = 0; $i < 30; ++$i) {
            $message = (new \App\Entity\AssistantMessage())
                ->setRole(\App\Entity\AssistantMessage::ROLE_USER)
                ->setContenu('Question numéro ' . $i);
            $conversation->addMessage($message);
        }

        $large = \count($builder->build($entreprise, $invite, $conversation)->messages);

        $this->poser('fil.max_messages', 6);
        $etroit = \count($builder->build($entreprise, $invite, $conversation)->messages);

        self::assertGreaterThan(
            $etroit,
            $large,
            'Réduire la profondeur du fil doit réduire le nombre de messages envoyés.'
        );
        self::assertSame(6, $etroit);
    }

    /** Le service de plan du jour lit bien son seuil, plutôt que sa constante. */
    public function testLePlanDuJourLitSonSeuil(): void
    {
        self::assertSame(8, PlanDuJourService::MAX_LIGNES_PAR_SECTION, 'La constante reste la valeur de naissance.');

        $this->poser('plan_du_jour.max_lignes', 3);

        self::assertSame(3, $this->reglages()->parametre('plan_du_jour.max_lignes'));
    }

    /**
     * LE CHEMIN DE LA CONSOLE REFUSE CE QUE LE SERVICE IGNORERAIT — et il le refuse
     * AVANT d'écrire, avec un message qui nomme les bornes.
     */
    public function testLeChemenDEcritureRefuseUneValeurHorsBornes(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/entre 7 et 180/');

        static::getContainer()->get(ApplicationDesReglages::class)
            ->reglerParametre('vigie.horizon_jours', 1000, 'Tentative hors bornes.', null);
    }
}
