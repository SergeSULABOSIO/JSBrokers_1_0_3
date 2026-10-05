<?php

namespace App\Tests\Workspace;

use PHPUnit\Framework\TestCase;

/**
 * LA VALEUR CHOISIE N'EST PAS UNE OPTION DE LISTE.
 *
 * ── LE DÉFAUT ───────────────────────────────────────────────────────────────────────
 * Un champ « Assureur » renseigné affichait sept à dix-huit lignes : le nom, une ligne de
 * contact, puis CINQ tuiles chiffrées empilées — prime, commission, deux taxes, rétro —
 * qui restaient là en permanence alors que le choix était fait.
 *
 * La cause n'est pas le CSS mais le bundle : dans `symfony/ux-autocomplete`,
 * `render.option` et `render.item` sont IDENTIQUES (controller.js, l. 348-350). Le serveur
 * ne produit qu'une seule chaîne, qui sert la liste déroulante ET le champ. Et un `render`
 * passé depuis PHP serait silencieusement écrasé (`mergeConfigs_fn`, spread plat,
 * l. 371-381) : la distinction ne peut pas se faire côté serveur.
 *
 * Les tuiles servent à CHOISIR entre deux assureurs. Une fois le choix arrêté, elles ne
 * répondent plus à aucune question.
 *
 * ── POURQUOI CE TEST LIT LE CSS ─────────────────────────────────────────────────────
 * Parce que c'est le CSS qui porte la décision, et qu'une règle non scopée toucherait
 * TOUS les Tom Select de l'application — la barre de recherche, les select simples — qui
 * n'ont rien demandé. Le scope n'est pas une précaution de style : c'est la différence
 * entre corriger un champ et casser les autres.
 */
class AutocompletionValeurChoisieTest extends TestCase
{
    private const CSS = __DIR__ . '/../../assets/styles/app.css';

    private function css(): string
    {
        return (string) file_get_contents(self::CSS);
    }

    /** Les lignes de règle qui visent une valeur choisie, sans les commentaires. */
    private function reglesDeValeurChoisie(): array
    {
        $lignes = [];
        foreach (explode("\n", $this->css()) as $ligne) {
            $nette = trim($ligne);
            if ($nette === '' || str_starts_with($nette, '*') || str_starts_with($nette, '/*')) {
                continue;
            }
            if (str_contains($nette, '.ts-control') && str_contains($nette, '{')) {
                $lignes[] = $nette;
            }
        }

        return $lignes;
    }

    public function testLAideAuChoixQuitteLeChampUneFoisLeChoixFait(): void
    {
        self::assertMatchesRegularExpression(
            '/\.ts-control \.item:has\(\.jsb-autocomplete-item\) \.jsb-autocomplete-aide\s*\{\s*display:\s*none/',
            $this->css(),
            'L\'aide au choix doit disparaître du champ. Elle sert à CHOISIR ; une fois '
            . 'le choix fait, elle ne répond plus à aucune question et occupe jusqu’à cinq lignes.',
        );
    }

    /**
     * CHAQUE RÈGLE EST SCOPÉE — c'est l'assertion qui protège le reste de l'application.
     *
     * `.ts-control .item { display: none }` sans scope viderait tous les champs Tom Select
     * du produit. Le `:has(.jsb-autocomplete-item)` restreint la portée à notre balisage.
     */
    public function testAucuneRegleNeVisePasLesAutresTomSelect(): void
    {
        $nues = array_values(array_filter(
            $this->reglesDeValeurChoisie(),
            static fn (string $regle): bool => !str_contains($regle, ':has(.jsb-autocomplete-item)'),
        ));

        self::assertSame([], $nues, sprintf(
            "Ces règles visent .ts-control sans se restreindre à notre balisage :\n  %s\n"
            . 'Elles toucheraient TOUS les Tom Select de l\'application — la barre de recherche, '
            . 'les select simples — qui n\'ont rien demandé. Le scope :has(.jsb-autocomplete-item) '
            . 'est ce qui sépare « corriger un champ » de « casser les autres ».',
            implode("\n  ", $nues),
        ));
    }

    /**
     * LE MODE MULTIPLE, où le défaut se multipliait.
     *
     * Trois champs sont `multiple` et passent par ces providers : les partenaires d'un
     * client (ClientType), les assistants d'un invité (InviteType), les comptes bancaires
     * d'une note (NoteType). Cinq valeurs sélectionnées empileraient cinq fois deux lignes.
     */
    public function testEnModeMultipleSeulLeNomSurvit(): void
    {
        self::assertMatchesRegularExpression(
            '/\.ts-wrapper\.multi [^{]*:has\(\.jsb-autocomplete-item\)[^{]*\.jsb-autocomplete-context\s*\{\s*display:\s*none/',
            $this->css(),
            'En mode multiple, la ligne de contact doit disparaître aussi : sans cela, le défaut '
            . 'qu\'on vient de fermer revient multiplié par le nombre de valeurs choisies.',
        );
    }

    /**
     * Le padding retiré est celui de l'OPTION DE MENU, pas celui du contrôle.
     *
     * Le distinguer importe : toucher au padding de `.ts-control` déformerait le champ
     * lui-même, et ce padding-là vient du thème.
     */
    public function testSeulLePaddingDeLOptionEstAnnule(): void
    {
        self::assertMatchesRegularExpression(
            '/\.ts-control \.item:has\(\.jsb-autocomplete-item\) \.jsb-autocomplete-item\s*\{\s*padding:\s*0/',
            $this->css(),
            'Le padding de 10px/12px est celui de l\'option de menu ; dans le champ, il ferait '
            . 'grandir le contrôle.',
        );
        self::assertDoesNotMatchRegularExpression(
            '/^\s*\.ts-control\s*\{\s*padding:\s*0/m',
            $this->css(),
            'Le padding de .ts-control vient du thème : y toucher déformerait le champ lui-même.',
        );
    }
}
