<?php

namespace App\Tests\Console;

use App\Entity\ErreurApplicative;
use App\Repository\ErreurApplicativeRepository;
use App\Supervision\EnregistreurDErreurs;
use App\Supervision\OrigineErreur;
use Doctrine\ORM\EntityManagerInterface;
use App\Supervision\HandlerDeSupervision;
use Monolog\Level;
use Monolog\LogRecord;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * La supervision des erreurs : regroupement, comptage, régression, paliers.
 *
 * ── CE QUE CES TESTS PROTÈGENT ──────────────────────────────────────────────
 * Le comptage est la seule chose qui transforme une avalanche d'erreurs en
 * liste de tâches ordonnée. S'il se met à compter faux — deux défauts confondus
 * en un, ou un défaut éclaté en cent lignes — la rubrique ne sert plus à
 * prioriser, elle sert à se donner l'impression de prioriser. C'est pire que
 * rien, parce qu'on lui fait confiance.
 */
class SupervisionDesErreursTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ErreurApplicativeRepository $depot;
    private EnregistreurDErreurs $enregistreur;

    protected function setUp(): void
    {
        self::bootKernel();
        $conteneur = static::getContainer();

        $this->em = $conteneur->get(EntityManagerInterface::class);
        $this->depot = $conteneur->get(ErreurApplicativeRepository::class);
        $this->enregistreur = $conteneur->get(EnregistreurDErreurs::class);

        // Table vidée : ces tests parlent de comptage, ils ne doivent rien
        // hériter d'une exécution précédente.
        $this->em->createQuery('DELETE FROM ' . ErreurApplicative::class . ' e')->execute();
        $this->em->clear();
    }

    /**
     * Le cœur du dispositif : mille occurrences font UNE ligne et le nombre
     * mille. Sans cela, la rubrique serait un journal de plus.
     */
    public function testUnMemeDefautRencontreTroisFoisNeFaitQuUneLigne(): void
    {
        for ($i = 1; $i <= 3; ++$i) {
            $this->enregistrer(message: "Client $i introuvable");
        }

        $erreurs = $this->depot->findAll();

        self::assertCount(1, $erreurs, 'Trois occurrences du même défaut doivent faire UNE ligne.');
        self::assertSame(3, $erreurs[0]->getNombreOccurrences());
    }

    /**
     * Le message ne fait PAS partie de l'empreinte : « Client 42 introuvable »
     * et « Client 77 introuvable » sont le même défaut, à la même ligne du même
     * fichier. Les séparer viderait le compteur de son sens.
     */
    public function testDeuxMessagesDifferentsAuMemeEndroitSontLeMemeDefaut(): void
    {
        $this->enregistrer(message: 'Client 42 introuvable');
        $this->enregistrer(message: 'Client 77 introuvable');

        $erreurs = $this->depot->findAll();

        self::assertCount(1, $erreurs);
        self::assertSame(2, $erreurs[0]->getNombreOccurrences());
        // Le contexte décrit la DERNIÈRE fois : c'est elle qu'on reproduira.
        self::assertSame('Client 77 introuvable', $erreurs[0]->getMessage());
    }

    /** Deux lieux différents restent deux défauts distincts. */
    public function testDeuxLieuxDifferentsFontDeuxDefauts(): void
    {
        $this->enregistrer(ligne: 10);
        $this->enregistrer(ligne: 99);

        self::assertCount(2, $this->depot->findAll());
    }

    /** Le côté sépare aussi : un même nom d'erreur en PHP et en JS diffère. */
    public function testLeCoteSepareLesDefauts(): void
    {
        $this->enregistrer(cote: ErreurApplicative::COTE_SERVEUR);
        $this->enregistrer(cote: ErreurApplicative::COTE_NAVIGATEUR);

        self::assertCount(2, $this->depot->findAll());
    }

    /**
     * Le signal le plus précieux du dispositif : un défaut marqué résolu qui
     * reparaît dit qu'on a cru avoir terminé. Il doit revenir en tête, sans
     * quoi il resterait enterré sous les erreurs « résolues ».
     */
    public function testUnDefautResoluQuiReparaitRepasseEnNouveau(): void
    {
        $this->enregistrer();

        $erreur = $this->depot->findAll()[0];
        $erreur->setStatut(ErreurApplicative::STATUT_RESOLUE);
        $this->em->flush();
        $this->em->clear();

        $this->enregistrer();

        $apres = $this->depot->findAll()[0];
        self::assertSame(
            ErreurApplicative::STATUT_NOUVELLE,
            $apres->getStatut(),
            'Un correctif qui n\'a pas tenu doit se voir.'
        );
        self::assertSame(2, $apres->getNombreOccurrences());
    }

    /**
     * Les paliers d'aggravation ne se franchissent qu'UNE fois chacun : sinon
     * chaque occurrence au-delà de dix produirait une alerte, et la boîte
     * cesserait d'être lue — exactement ce que le dispositif doit éviter.
     */
    public function testChaquePalierNeSeFranchitQuUneSeuleFois(): void
    {
        $erreur = new ErreurApplicative();
        $franchis = [];

        for ($i = 1; $i <= 120; ++$i) {
            $erreur->enregistrerOccurrence(new \DateTimeImmutable());
            $palier = $erreur->palierFranchi();
            if (null !== $palier) {
                $franchis[] = $palier;
            }
        }

        self::assertSame([10, 100], $franchis, 'Entre 10 et 100, le silence doit revenir.');
    }

    /** Ce qui est ouvert n'est jamais purgé, si vieux soit-il. */
    public function testLaPurgeEpargneCeQuiEstEncoreOuvert(): void
    {
        $vieux = new \DateTimeImmutable('-200 days');

        foreach ([ErreurApplicative::STATUT_NOUVELLE, ErreurApplicative::STATUT_RESOLUE] as $index => $statut) {
            $this->enregistrer(ligne: 500 + $index);
            $erreur = $this->depot->findOneBy(['ligne' => 500 + $index]);
            $erreur->setStatut($statut)->setDerniereOccurrenceAt($vieux);
        }
        $this->em->flush();
        $this->em->clear();

        $supprimes = $this->depot->purgerTranchesAvant(new \DateTimeImmutable('-90 days'));

        self::assertSame(1, $supprimes, 'Seul le défaut TRANCHÉ doit partir.');
        $restant = $this->depot->findAll()[0];
        self::assertSame(
            ErreurApplicative::STATUT_NOUVELLE,
            $restant->getStatut(),
            'Un défaut que personne n\'a regardé reste un défaut : l\'effacer n\'est pas le corriger.'
        );
    }

    /** La branche est déduite, et distingue bien les trois applications. */
    public function testLaBrancheDistingueLesTroisApplications(): void
    {
        $origine = static::getContainer()->get(OrigineErreur::class);

        self::assertSame(ErreurApplicative::BRANCHE_CONSOLE, $origine->depuisNomDeRoute('console.supervision.index'));
        self::assertSame(ErreurApplicative::BRANCHE_WORKSPACE, $origine->depuisNomDeRoute('admin.assureur.index'));
        self::assertSame(ErreurApplicative::BRANCHE_PORTAIL, $origine->depuisNomDeRoute('app_login'));

        self::assertSame(ErreurApplicative::BRANCHE_CONSOLE, $origine->depuisUrl('https://www.joseara.com/console/supervision'));
        self::assertSame(ErreurApplicative::BRANCHE_WORKSPACE, $origine->depuisUrl('https://www.joseara.com/espacedetravail/1/1'));
        self::assertSame(ErreurApplicative::BRANCHE_PORTAIL, $origine->depuisUrl('https://www.joseara.com/'));
    }

    /**
     * UNE ADRESSE QUI N'EXISTE PAS N'EST PAS UN DÉFAUT DE L'APPLICATION.
     *
     * Relevé en production le 2026-09-20 : « No route found for GET
     * /wp-admin/install.php », 546 occurrences, PREMIÈRE LIGNE du tableau des défauts.
     * Ce ne sont pas nos utilisateurs, ce sont des robots qui cherchent un WordPress.
     * Symfony journalise les 404 au niveau ERROR, le même que les vraies pannes : les
     * compter reléguait les défauts réels plus bas dans une liste triée par fréquence.
     * Une supervision qu'on cesse de lire ne supervise plus rien.
     */
    public function testUneAdresseInconnueNEstPasUnDefaut(): void
    {
        $this->handler()->handle($this->enregistrementDe(
            new NotFoundHttpException('No route found for "GET /wp-admin/install.php"'),
        ));
        $this->em->clear();

        self::assertSame([], $this->depot->findAll(), 'Un 404 de robot n’a rien à faire dans la liste des défauts.');
    }

    /**
     * ⚠ ET SEULEMENT LE 404. Un 400, un 403, un 409 viennent de NOS routes, qui ont bel
     * et bien répondu : une poussée de ces statuts est un signal — c'est ainsi qu'on a
     * trouvé le refus de la voix sur les réponses à tableau.
     */
    public function testUnRefusDeNosPropresRoutesResteUnDefaut(): void
    {
        $this->handler()->handle($this->enregistrementDe(
            new BadRequestHttpException('Le texte à lire ne correspond pas à cette réponse.'),
        ));
        $this->em->clear();

        self::assertCount(1, $this->depot->findAll(), 'Un 400 de nos routes reste un défaut à regarder.');
    }

    private function handler(): HandlerDeSupervision
    {
        $conteneur = static::getContainer();

        return new HandlerDeSupervision(
            $this->enregistreur,
            $conteneur->get(OrigineErreur::class),
            $conteneur->get(RequestStack::class),
            $conteneur->get(Security::class),
        );
    }

    private function enregistrementDe(\Throwable $exception): LogRecord
    {
        return new LogRecord(
            new \DateTimeImmutable(),
            'request',
            Level::Error,
            $exception->getMessage(),
            ['exception' => $exception],
        );
    }

    private function enregistrer(
        string $cote = ErreurApplicative::COTE_SERVEUR,
        string $message = 'Panne simulée',
        int $ligne = 10,
    ): void {
        $this->enregistreur->enregistrer(
            cote: $cote,
            branche: ErreurApplicative::BRANCHE_CONSOLE,
            type: 'RuntimeException',
            message: $message,
            fichier: 'src/Essai/Simulation.php',
            ligne: $ligne,
            trace: '#0 essai',
        );
        $this->em->clear();
    }
}
