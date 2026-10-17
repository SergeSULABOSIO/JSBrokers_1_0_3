<?php

namespace App\Tests\Workspace;

use App\Service\TexteRiche\ChampsEnTexteRiche;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * LE REGISTRE DES CHAMPS RICHES SUIT LES FORMULAIRES.
 *
 * Tout <textarea> d'un dialogue d'entité devient un éditeur Quill (_form_row.html.twig),
 * donc peut porter du HTML. Un champ oublié au registre (ChampsEnTexteRiche) ne serait ni
 * assaini à l'écriture, ni contrôlé en longueur : ce test refuse l'oubli.
 */
class ChampsEnTexteRicheTest extends KernelTestCase
{
    private const RACINE = __DIR__ . '/../../src';

    /** @return array<string, list<string>> entité courte => champs TextareaType de son formulaire de dialogue */
    private function textareasDesDialogues(): array
    {
        $trouves = [];
        foreach (glob(self::RACINE . '/Services/Canvas/Provider/Form/*FormCanvasProvider.php') as $provider) {
            $entite = basename($provider, 'FormCanvasProvider.php');
            $type = self::RACINE . "/Form/{$entite}Type.php";
            if (!is_file($type)) {
                continue;
            }
            preg_match_all('/->add\(\s*[\'"](\w+)[\'"]\s*,\s*TextareaType::class/', (string) file_get_contents($type), $m);
            if ($m[1] !== []) {
                $trouves[$entite] = $m[1];
            }
        }

        return $trouves;
    }

    public function testChaqueTextareaDUnDialogueEstAuRegistre(): void
    {
        $dialogues = $this->textareasDesDialogues();
        // Garde contre un test qui passerait à vide (une regex qui ne trouve plus rien).
        self::assertGreaterThanOrEqual(15, \count($dialogues), 'Trop peu de textarea trouvés : la lecture des formulaires est cassée.');

        $absents = [];
        foreach ($dialogues as $entite => $champs) {
            $declares = ChampsEnTexteRiche::de('App\\Entity\\' . $entite);
            foreach (array_diff($champs, $declares) as $champ) {
                $absents[] = "$entite::$champ";
            }
        }

        self::assertSame([], $absents, 'Champs riches absents de ChampsEnTexteRiche (ni assainis, ni contrôlés) : ' . implode(', ', $absents));
    }

    public function testChaqueChampDuRegistreEstUneColonneTexte(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $invalides = [];
        foreach (ChampsEnTexteRiche::CHAMPS as $classe => $champs) {
            $meta = $em->getClassMetadata($classe);
            foreach ($champs as $champ) {
                if (!$meta->hasField($champ) || !\in_array($meta->getTypeOfField($champ), ['string', 'text'], true)) {
                    $invalides[] = "$classe::$champ";
                }
            }
        }

        self::assertSame([], $invalides, 'Entrées du registre qui ne sont pas des colonnes texte : ' . implode(', ', $invalides));
    }
}
