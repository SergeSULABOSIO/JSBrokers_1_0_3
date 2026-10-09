<?php

namespace App\Tests\Ai;

use App\Ai\AccueilDeKet;
use App\Entity\Invite;
use App\Service\Workspace\WorkspaceAccessResolver;
use App\Services\Canvas\Provider\Icon\IconCanvasProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * L'ACCUEIL D'UNE CONVERSATION VIDE : bref, aimable, à l'heure juste, et qui ne
 * propose que ce qui aura une réponse.
 */
final class AccueilDeKetTest extends KernelTestCase
{
    /**
     * « Bonsoir » de 18 h à 4 h 59, « Bonjour » de 5 h à 17 h 59 — les quatre bornes.
     *
     * @dataProvider bornesDeLaSalutation
     */
    public function testLaSalutationSuitLHeure(string $heure, string $attendu): void
    {
        // Date calculée (aujourd'hui), jamais figée : seule l'heure compte.
        self::assertSame($attendu, AccueilDeKet::salutation(new \DateTimeImmutable('today ' . $heure)));
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function bornesDeLaSalutation(): iterable
    {
        yield 'fin de nuit' => ['04:59', 'Bonsoir'];
        yield 'lever du jour' => ['05:00', 'Bonjour'];
        yield 'fin d’après-midi' => ['17:59', 'Bonjour'];
        yield 'début de soirée' => ['18:00', 'Bonsoir'];
        yield 'minuit' => ['00:00', 'Bonsoir'];
        yield 'midi' => ['12:00', 'Bonjour'];
    }

    /**
     * LE FUSEAU DE L'APPLICATION, PAS CELUI DE PHP. En mutualisé, date.timezone peut
     * valoir UTC : l'heure de mur doit pourtant être celle du cabinet.
     */
    public function testLHeureEstLueDansLeFuseauDeLApplicationMemeSiPhpEstEnUtc(): void
    {
        $fuseauPhp = date_default_timezone_get();
        date_default_timezone_set('UTC');
        try {
            $maintenant = AccueilDeKet::maintenant('Africa/Kinshasa');

            self::assertSame('Africa/Kinshasa', $maintenant->getTimezone()->getName());
            $attendu = (new \DateTimeImmutable('now', new \DateTimeZone('Africa/Kinshasa')))->format('G');
            self::assertSame($attendu, $maintenant->format('G'), 'Heure de mur de Kinshasa, pas d’UTC.');
        } finally {
            date_default_timezone_set($fuseauPhp);
        }
    }

    /** Un fuseau absent ou invalide ne doit jamais empêcher le chat de s'ouvrir. */
    public function testUnFuseauInvalideRetombeSurLeFuseauCourant(): void
    {
        self::assertSame(date_default_timezone_get(), AccueilDeKet::maintenant('')->getTimezone()->getName());
        self::assertSame(date_default_timezone_get(), AccueilDeKet::maintenant('Pas/Un_Fuseau')->getTimezone()->getName());
    }

    /** Le contrôleur lit `app.timezone` : il doit valoir APP_TIMEZONE, et un fuseau valide. */
    public function testLeParametreDuFuseauEstAPP_TIMEZONE(): void
    {
        self::bootKernel();
        $fuseau = (string) static::getContainer()->getParameter('app.timezone');

        self::assertSame((string) ($_ENV['APP_TIMEZONE'] ?? $_SERVER['APP_TIMEZONE'] ?? ''), $fuseau);
        self::assertContains($fuseau, \DateTimeZone::listIdentifiers(), 'APP_TIMEZONE doit être un fuseau reconnu.');
    }

    /**
     * « Nom complet » à l'inscription, pas de champ prénom : le prénom est le premier
     * mot qui n'est pas en capitales (convention française du nom de famille).
     *
     * @dataProvider nomsComplets
     */
    public function testLePrenomEstTireDuNomComplet(?string $nomComplet, ?string $attendu): void
    {
        self::assertSame($attendu, AccueilDeKet::prenom($nomComplet));
    }

    /** @return iterable<string, array{0: ?string, 1: ?string}> */
    public static function nomsComplets(): iterable
    {
        yield 'prénom d’abord' => ['Serge SULA BOSIO', 'Serge'];
        yield 'nom d’abord' => ['SULA BOSIO Serge', 'Serge'];
        yield 'exemple du formulaire' => ['Marie Dupont', 'Marie'];
        yield 'prénom composé' => ['Jean-Pierre KABILA', 'Jean-Pierre'];
        yield 'tout en capitales' => ['MARIE DUPONT', 'MARIE'];
        yield 'un seul mot' => ['Serge', 'Serge'];
        yield 'espaces superflus' => ['  Marie   Dupont ', 'Marie'];
        yield 'vide' => ['', null];
        yield 'nul (aucun utilisateur)' => [null, null];
    }

    public function testLeTitreSalueParLePrenomEtSePasseDeNomSansUtilisateur(): void
    {
        $accueil = $this->accueil(toutLisible: true);
        $matin = new \DateTimeImmutable('today 09:00');

        self::assertSame('Bonjour, Serge', $accueil->pour(new Invite(), 'Serge SULA BOSIO', $matin)['titre']);
        self::assertSame('Bonjour', $accueil->pour(new Invite(), null, $matin)['titre']);
        self::assertSame('Bonsoir', $accueil->pour(new Invite(), '   ', new \DateTimeImmutable('today 20:00'))['titre']);
    }

    public function testLaPhraseVientDeLaListe(): void
    {
        $vu = $this->accueil(toutLisible: true)->pour(new Invite(), 'Serge', new \DateTimeImmutable('today 09:00'));

        self::assertContains($vu['phrase'], AccueilDeKet::PHRASES);
    }

    /**
     * PAS BAVARD, ET JAMAIS EN CONTRADICTION AVEC LA SALUTATION. Une phrase doit
     * valoir à toute heure : pas de « belle journée » après « Bonsoir ».
     */
    public function testLesPhrasesSontCourtesEtValentAToutHeure(): void
    {
        self::assertGreaterThanOrEqual(3, \count(AccueilDeKet::PHRASES), 'Il faut assez de phrases pour que le hasard se voie.');
        foreach (AccueilDeKet::PHRASES as $phrase) {
            self::assertLessThanOrEqual(60, mb_strlen($phrase), sprintf('« %s » est trop longue pour un accueil.', $phrase));
            self::assertDoesNotMatchRegularExpression(
                '/\b(journ[ée]e|matin|matin[ée]e|apr[èe]s-midi|soir|soir[ée]e|nuit|bonjour|bonsoir)\b/iu',
                $phrase,
                sprintf('« %s » dépend du moment de la journée : elle contredirait la salutation.', $phrase),
            );
        }
    }

    /** Toute suggestion porte une icône du dépôt local (sinon : un trou dans l'alignement). */
    public function testChaqueSuggestionAUneIconeConnue(): void
    {
        $icones = new IconCanvasProvider();
        foreach (AccueilDeKet::SUGGESTIONS as $suggestion) {
            self::assertNotNull($icones->resolveIconName($suggestion['icone']), sprintf('Icône inconnue : %s', $suggestion['icone']));
            self::assertNotEmpty($suggestion['entites'], 'Une suggestion sans entité serait proposée à tout le monde.');
        }
    }

    /**
     * UNE SUGGESTION N'EST PROPOSÉE QU'À QUI OBTIENDRAIT UNE RÉPONSE. Mesuré : sans le
     * droit de lecture, « Quelles polices arrivent à échéance ? » vaut un refus et le
     * programme du jour revient vide.
     */
    public function testLesSuggestionsSuiventLesDroitsDeLecture(): void
    {
        $matin = new \DateTimeImmutable('today 09:00');
        $questions = static fn (array $vu): array => array_column($vu['suggestions'], 'question');

        // Tout lisible : les trois.
        self::assertCount(3, $this->accueil(toutLisible: true)->pour(new Invite(), null, $matin)['suggestions']);

        // Sans aucun périmètre : aucune — et l'accueil s'en passe (le gabarit omet la liste).
        self::assertSame([], $this->accueil(lisibles: [])->pour(new Invite(), null, $matin)['suggestions']);

        // Sans les finances (pas de Tranche) : le programme et les échéances, pas les primes.
        self::assertSame(
            ['Quel est mon programme du jour ?', 'Quelles polices arrivent à échéance ?'],
            $questions($this->accueil(lisibles: ['Avenant', 'Tache'])->pour(new Invite(), null, $matin)),
        );

        // Les seules tâches suffisent au programme, pas aux deux autres.
        self::assertSame(
            ['Quel est mon programme du jour ?'],
            $questions($this->accueil(lisibles: ['Tache'])->pour(new Invite(), null, $matin)),
        );
    }

    /** @param list<string> $lisibles */
    private function accueil(bool $toutLisible = false, array $lisibles = []): AccueilDeKet
    {
        $acces = $this->createMock(WorkspaceAccessResolver::class);
        $acces->method('canRead')->willReturnCallback(
            static fn (Invite $invite, string $entite): bool => $toutLisible || \in_array($entite, $lisibles, true)
        );

        return new AccueilDeKet($acces);
    }
}
