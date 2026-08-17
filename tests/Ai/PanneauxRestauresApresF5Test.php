<?php

namespace App\Tests\Ai;

use App\Ai\Action\TypeAction;
use PHPUnit\Framework\TestCase;

/**
 * LE CONTRAT DE SURVIE AU RECHARGEMENT, vérifié sur les trois bouts qui le portent.
 *
 * L'ANOMALIE QUI A FAIT ÉCRIRE CE TEST. Le tableau des fichiers à télécharger — avec son
 * bouton par ligne et son « Tout télécharger » — disparaissait au premier F5. Le fil
 * gardait la phrase de Ket annonçant les fichiers, et plus aucun moyen de les obtenir.
 * La donnée, elle, n'avait jamais bougé : `meta.actions` contenait l'action complète en
 * base. Il ne manquait que le LECTEUR. Et rien ne le signalait : huit panneaux étaient
 * restaurés, celui-là ne l'était pas, et l'écart ne se voyait qu'en appuyant sur F5
 * devant un tableau de fichiers — c'est-à-dire jamais pendant le développement.
 *
 * CE QUE LE TEST GARANTIT. Pour chaque type d'action qui se déclare restaurable
 * ({@see TypeAction::attributDeRestauration()}), les trois bouts de la chaîne doivent
 * exister ensemble :
 *
 *  1. le GABARIT pose l'attribut sur la bulle, sinon rien n'est écrit dans la page ;
 *  2. le CONTRÔLEUR relit cet attribut dans une méthode `restore*`, sinon rien n'est lu ;
 *  3. `connect()` APPELLE cette méthode, sinon la restauration existe et ne s'exécute pas.
 *
 * Chacun des trois manque silencieusement : aucun ne produit d'erreur, tous produisent le
 * même symptôme — un panneau qui s'évapore. C'est la même famille de garde-fou que
 * ContratDesActionsTest, qui vérifie déjà que rien de ce que le serveur émet n'est ignoré
 * par le navigateur ; celui-ci vérifie que rien de ce qui est affiché n'est perdu.
 *
 * Les démentis autoritaires (PLAN_ABSENT et sa famille) sont volontairement hors contrat :
 * ils ne portent aucun bouton, ils rectifient une phrase — leur disparition ne prive
 * l'utilisateur d'aucun geste. Le jour où l'on décidera qu'ils doivent survivre, il
 * suffira de leur donner un attribut dans l'énumération : ce test les prendra en charge
 * sans être modifié.
 */
class PanneauxRestauresApresF5Test extends TestCase
{
    private const CHAT = __DIR__ . '/../../assets/controllers/assistant-chat_controller.js';
    private const GABARIT = __DIR__ . '/../../templates/components/_assistant_ia_chat.html.twig';

    /** @return iterable<string, array{TypeAction, string}> */
    public static function panneauxRestaurables(): iterable
    {
        foreach (TypeAction::cases() as $type) {
            $attribut = $type->attributDeRestauration();
            if ($attribut !== null) {
                yield $type->value => [$type, $attribut];
            }
        }
    }

    /**
     * Le gabarit doit ÉCRIRE l'attribut sur la bulle.
     *
     * Sans lui, la page ne contient rien à relire : la charge utile reste en base, le
     * navigateur ne la voit jamais, et le panneau ne peut pas revenir.
     *
     * @dataProvider panneauxRestaurables
     */
    public function testLeGabaritPoseLAttributSurLaBulle(TypeAction $type, string $attribut): void
    {
        $gabarit = (string) file_get_contents(self::GABARIT);

        self::assertStringContainsString(
            $attribut . '="',
            $gabarit,
            sprintf(
                'Le panneau « %s » se déclare restaurable, mais le gabarit du chat ne pose pas %s : '
                . 'la charge utile resterait en base et le panneau disparaîtrait au rechargement.',
                $type->value,
                $attribut,
            ),
        );
    }

    /**
     * Le contrôleur doit RELIRE l'attribut, et le faire dans une méthode `restore*`
     * elle-même appelée par `connect()`.
     *
     * Les deux moitiés comptent. Une restauration écrite mais jamais appelée est du code
     * mort qui donne l'illusion que le cas est traité — le pire des deux états, puisqu'il
     * décourage d'aller chercher plus loin.
     *
     * @dataProvider panneauxRestaurables
     */
    public function testLeControleurRelitLAttributAuMontage(TypeAction $type, string $attribut): void
    {
        $module = (string) file_get_contents(self::CHAT);

        $methode = $this->methodeDeRestaurationLisant($module, $attribut);
        self::assertNotNull(
            $methode,
            sprintf(
                'Aucune méthode « restore* » du contrôleur ne lit %s : le panneau « %s » ne '
                . 'reviendrait pas après un F5, alors que sa charge utile est bien dans la page.',
                $attribut,
                $type->value,
            ),
        );

        self::assertStringContainsString(
            'this.' . $methode . '()',
            $this->corpsDeConnect($module),
            sprintf(
                '%s() existe mais connect() ne l’appelle pas : une restauration jamais exécutée '
                . 'laisse croire que le cas est traité.',
                $methode,
            ),
        );
    }

    /**
     * L'attribut doit être RETIRÉ après lecture.
     *
     * Sans ce retrait, une seconde passe de restauration — ou un rendu de l'historique
     * qui repasse sur la même bulle — rajouterait le panneau une seconde fois sous le
     * même message. C'est la précaution que prennent déjà toutes les restaurations
     * existantes ; elle cesse d'être une habitude pour devenir une exigence.
     *
     * @dataProvider panneauxRestaurables
     */
    public function testLAttributEstRetireApresLecture(TypeAction $type, string $attribut): void
    {
        $module = (string) file_get_contents(self::CHAT);
        $methode = $this->methodeDeRestaurationLisant($module, $attribut);
        self::assertNotNull($methode, 'Prérequis : la méthode de restauration doit exister.');

        self::assertStringContainsString(
            sprintf("removeAttribute('%s')", $attribut),
            $this->corpsDeLaMethode($module, $methode),
            sprintf(
                '%s() ne retire pas %s : le panneau « %s » risquerait d’être affiché deux fois '
                . 'sous la même bulle.',
                $methode,
                $attribut,
                $type->value,
            ),
        );
    }

    /**
     * Le nom de la méthode `restore*` qui lit cet attribut, ou null.
     *
     * On cherche par le CONTENU plutôt que par une convention de nommage : c'est la
     * lecture de l'attribut qui fait la restauration, pas le nom de la méthode.
     */
    private function methodeDeRestaurationLisant(string $module, string $attribut): ?string
    {
        preg_match_all('/\n    (restore\w+)\(\) \{/', $module, $trouves);

        foreach ($trouves[1] as $methode) {
            if (str_contains($this->corpsDeLaMethode($module, $methode), $attribut)) {
                return $methode;
            }
        }

        return null;
    }

    /**
     * Le corps d'une méthode du contrôleur, du `{` ouvrant à l'accolade de même niveau.
     *
     * Un comptage d'accolades suffit ici et vaut mieux qu'une borne « jusqu'à la méthode
     * suivante » : celle-là se décale dès qu'on insère un commentaire contenant une
     * parenthèse, et le test se mettrait à lire le corps du voisin.
     */
    private function corpsDeLaMethode(string $module, string $methode): string
    {
        $debut = strpos($module, "\n    " . $methode . '() {');
        if ($debut === false) {
            return '';
        }

        $ouvrante = strpos($module, '{', $debut);
        $profondeur = 0;
        $longueur = strlen($module);

        for ($i = $ouvrante; $i < $longueur; ++$i) {
            if ($module[$i] === '{') {
                ++$profondeur;
            } elseif ($module[$i] === '}') {
                --$profondeur;
                if ($profondeur === 0) {
                    return substr($module, $ouvrante, $i - $ouvrante + 1);
                }
            }
        }

        return substr($module, $ouvrante);
    }

    /** Le corps de `connect()`, où se décide ce qui est rejoué au montage. */
    private function corpsDeConnect(string $module): string
    {
        return $this->corpsDeLaMethode($module, 'connect');
    }
}
