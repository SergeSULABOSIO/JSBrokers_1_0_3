<?php

namespace App\Tests\Ai;

use App\Ai\TitreDeConversation;
use App\Entity\AssistantConversation;
use PHPUnit\Framework\TestCase;

/**
 * LE TITRE AUTOMATIQUE D'UNE CONVERSATION : court, débarrassé des politesses,
 * jamais écrasé une fois posé.
 */
final class TitreDeConversationTest extends TestCase
{
    /**
     * @dataProvider questions
     */
    public function testLeTitreGardeLeCoeurDeLaDemande(string $question, ?string $attendu, string $nomAssistant = 'Ket'): void
    {
        self::assertSame($attendu, TitreDeConversation::depuis($question, $nomAssistant));
    }

    /** @return iterable<string, array{0: string, 1: ?string, 2?: string}> */
    public static function questions(): iterable
    {
        yield 'salutation et ponctuation finale' => ['Bonjour, qui es-tu ?', 'Qui es-tu'];
        yield 'demande ordinaire' => ['Combien de clients avons-nous ?', 'Combien de clients avons-nous'];
        yield 'formule « peux-tu me »' => ['Ket, peux-tu me lister les clients ?', 'Lister les clients'];
        yield 'apostrophe typographique m’' => ['peux-tu m’aider à relancer AXA ?', 'Aider à relancer AXA'];
        yield 'apostrophe droite' => ["Peux-tu m'aider à relancer AXA ?", 'Aider à relancer AXA'];
        yield 's’il vous plaît en tête' => ['S’il vous plaît, liste les polices SFA', 'Liste les polices SFA'];
        yield 's’il vous plaît en fin' => ['Liste les polices SFA s’il vous plaît.', 'Liste les polices SFA'];
        yield 'stp en fin' => ['Montre-moi les sinistres de mars stp', 'Montre-moi les sinistres de mars'];
        yield 'est-ce que' => ['Est-ce que la police 2026-014 est payée ?', 'La police 2026-014 est payée'];
        yield 'je voudrais' => ['Je voudrais le relevé de compte de Delvaux', 'Le relevé de compte de Delvaux'];
        yield 'dis-moi' => ['Dis-moi combien de tranches sont impayées', 'Combien de tranches sont impayées'];
        yield 'espace insécable' => ["Bonjour\u{00A0}Ket\u{202F}: quelles primes ?", 'Quelles primes'];
        yield 'guillemets et markdown' => ['« **Primes** » à encaisser', 'Primes à encaisser'];
        yield 'titre et citation markdown en tête' => ['## > Primes à encaisser', 'Primes à encaisser'];
        yield 'balisage gardé tel quel' => ['<b>Primes</b> à encaisser ?', '<b>Primes</b> à encaisser'];
        yield 'comparaison gardée' => ['Primes > 1000 USD ?', 'Primes > 1000 USD'];
        yield 'majuscule posée' => ['quelles polices arrivent à échéance ?', 'Quelles polices arrivent à échéance'];
        yield 'accents conservés' => ['Échéancier de la police AXA', 'Échéancier de la police AXA'];

        // RIEN D'EXPLOITABLE : la conversation reste « CONV#… », la question suivante retentera.
        yield 'salutation seule' => ['Bonjour !', null];
        yield 'nom de l’assistant seul' => ['Ket ?', null];
        yield 'ça va' => ['Salut, ça va ?', null];
        yield 'ok merci' => ['Ok merci', null];
        yield 'casse et accents ignorés' => ['OUI', null];
        yield 'test' => ['test.', null];
        yield 'coucou' => ['Coucou !!', null];
        yield 'trop court' => ['?', null];

        // LE NOM CONFIGURÉ, PAS « KET » EN DUR.
        yield 'nom personnalisé' => ['Jess, peux-tu lister les assureurs ?', 'Lister les assureurs', 'Jess'];
        yield 'nom personnalisé à caractères spéciaux' => ['A.I. : liste les assureurs', 'Liste les assureurs', 'A.I.'];
        yield '« Ket » n’est plus retiré quand l’assistant s’appelle autrement' => ['Ket, liste les assureurs', 'Ket, liste les assureurs', 'Jess'];
    }

    /** Au-delà de 40 caractères : coupe au dernier espace avant le 39ᵉ, puis « … ». */
    public function testUneLongueQuestionEstCoupeeSurUnMotEntier(): void
    {
        $titre = TitreDeConversation::depuis(
            'Ket, peux-tu me lister les polices AXA qui arrivent à échéance ce mois-ci ?',
            'Ket',
        );

        self::assertSame('Lister les polices AXA qui arrivent à…', $titre);
        self::assertLessThanOrEqual(TitreDeConversation::MAX, mb_strlen((string) $titre));
    }

    /** Un seul long mot (une référence) : coupe franche à 39, « … » compris on fait 40. */
    public function testUnMotSansEspaceEstCoupeFranchement(): void
    {
        $titre = TitreDeConversation::depuis(str_repeat('A', 60), 'Ket');

        self::assertSame(str_repeat('A', 39) . '…', $titre);
        self::assertSame(TitreDeConversation::MAX, mb_strlen((string) $titre));
    }

    /**
     * QUOI QU'ON LUI DONNE, JAMAIS PLUS DE 40 CARACTÈRES.
     *
     * @dataProvider longuesQuestions
     */
    public function testLeTitreNeDepasseJamaisLaLimite(string $question): void
    {
        $titre = TitreDeConversation::depuis($question, 'Ket');

        self::assertNotNull($titre);
        self::assertLessThanOrEqual(TitreDeConversation::MAX, mb_strlen($titre));
    }

    /** @return iterable<string, array{0: string}> */
    public static function longuesQuestions(): iterable
    {
        yield 'espace pile au 39ᵉ' => [str_repeat('a', 38) . ' ' . str_repeat('b', 10)];
        yield 'espace au 40ᵉ' => [str_repeat('a', 39) . ' ' . str_repeat('b', 10)];
        yield 'accents multioctets' => [str_repeat('é', 30) . ' ' . str_repeat('à', 30)];
        yield 'phrase réelle' => ['Fais-moi le décompte de la rétrocommission due à l’agent Serge SULA pour 2026'];
    }

    // ── La règle « jamais écrasé », portée par l'entité ─────────────────────────

    public function testUneConversationSansTitreLePrendDeSaPremiereQuestion(): void
    {
        $conversation = new AssistantConversation();
        $conversation->titrerDepuis('Bonjour, qui es-tu ?', 'Ket');

        self::assertSame('Qui es-tu', $conversation->getTitre());
    }

    public function testUnTitreDejaPoseNEstJamaisEcrase(): void
    {
        $choisi = (new AssistantConversation())->setTitre('Dossier SFA 2026');
        $choisi->titrerDepuis('Quelles primes restent à encaisser ?', 'Ket');
        self::assertSame('Dossier SFA 2026', $choisi->getTitre(), 'Le titre de l’utilisateur prime.');

        $auto = new AssistantConversation();
        $auto->titrerDepuis('Liste les polices SFA', 'Ket');
        $auto->titrerDepuis('Et les sinistres ?', 'Ket');
        self::assertSame('Liste les polices SFA', $auto->getTitre(), 'Le 2ᵉ message ne rebaptise pas.');
    }

    public function testUnMessageBanalLaisseLeTitreParDefautEtLaSuiteRetente(): void
    {
        $conversation = new AssistantConversation();
        $conversation->titrerDepuis('Bonjour !', 'Ket');
        self::assertNull($conversation->getTitre());
        self::assertStringStartsWith('CONV#', $conversation->libelle());

        $conversation->titrerDepuis('Quelles polices arrivent à échéance ?', 'Ket');
        self::assertSame('Quelles polices arrivent à échéance', $conversation->getTitre());
    }
}
