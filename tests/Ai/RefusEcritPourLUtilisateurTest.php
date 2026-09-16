<?php

namespace App\Tests\Ai;

use App\Ai\Tool\AiToolProduisantUnPlan;
use PHPUnit\Framework\TestCase;

/**
 * TOUT REFUS D'UN OUTIL DE PLAN DOIT PORTER UNE PHRASE ÉCRITE POUR L'UTILISATEUR.
 *
 * ── POURQUOI CE FICHIER EXISTE ──────────────────────────────────────────────
 * Un refus d'outil de plan est un `AiToolResult::ok()` portant `pret: false`. Il
 * remonte jusqu'au courtier par deux chemins — l'avertissement du fil quand la
 * prose décrit un plan que l'outil a refusé, et le rapport de fin de programme —
 * tous deux traduits par MotifDeRefus.
 *
 * MotifDeRefus ne lit QUE des clés destinées à l'humain. Un refus qui n'en porte
 * aucune ne peut donc produire qu'une phrase neutre : vraie, mais pauvre. Le
 * courtier apprend que rien n'a été écrit, sans apprendre pourquoi.
 *
 * Auparavant, cette même absence produisait bien pire : la traduction retombait
 * sur « note », le brouillon adressé au modèle. Le 2026-08-12 puis le 2026-09-14,
 * le catalogue complet des outils de Ket — nom et arguments de chacun — s'est
 * ainsi affiché dans la bulle d'un courtier, et y est resté, persisté en meta.
 * La retombée est fermée ; ce test-ci traite la cause de fond, qui est que
 * quinze refus n'avaient jamais été rédigés pour la personne qui les lit.
 *
 * ⚠ CE TEST NE LIT QUE LES OUTILS PRODUISANT UN PLAN. Les autres ne passent pas
 * par MotifDeRefus : leur imposer la même exigence serait un rite sans objet.
 */
class RefusEcritPourLUtilisateurTest extends TestCase
{
    private const DOSSIER = __DIR__ . '/../../src/Ai/Tool';

    /**
     * Les clés que MotifDeRefus sait rendre à l'utilisateur, essayées avant toute
     * autre. Un refus qui en porte au moins une est rédigé pour lui.
     *
     * Cette liste est le CONTRAT : elle doit rester le miroir exact des branches de
     * `MotifDeRefus::depuis()`. En ajouter une ici sans l'y implémenter rendrait ce
     * test complaisant.
     */
    private const CLES_UTILISATEUR = [
        'manquants',
        'ambigu',
        'aDemander',
        'blocages',
        'planEnAttente',
        'dejaAJour',
        'bloquant',
    ];

    public function testChaqueRefusDUnOutilDePlanEstRedigePourLeCourtier(): void
    {
        $manquants = [];

        foreach ($this->outilsProduisantUnPlan() as $chemin) {
            foreach ($this->refus($chemin) as [$ligne, $bloc]) {
                foreach (self::CLES_UTILISATEUR as $cle) {
                    if (str_contains($bloc, "'" . $cle . "'")) {
                        continue 2;
                    }
                }
                $manquants[] = sprintf('%s:%d', basename($chemin), $ligne);
            }
        }

        self::assertSame([], $manquants, sprintf(
            "Ces refus d'outil de plan ne portent AUCUNE clé destinée à l'utilisateur (%s) :\n  %s\n\n"
            . "Le courtier n'y lira qu'une phrase neutre — et jamais « note », qui est le brouillon "
            . "adressé au modèle. Ajoutez un « bloquant » qui dit, dans les mots du métier, ce qui "
            . "bloque et ce qu'il peut y faire : jamais un nom d'outil, jamais un nom d'argument.",
            implode(', ', self::CLES_UTILISATEUR),
            implode("\n  ", $manquants),
        ));
    }

    /**
     * GARDE-FOU DU GARDE-FOU : si la lecture cesse de trouver des refus, le test
     * passerait en ne prouvant plus rien. On exige donc d'en voir un nombre plausible.
     */
    public function testLaLectureDuCodeTrouveEffectivementDesRefus(): void
    {
        $total = 0;
        foreach ($this->outilsProduisantUnPlan() as $chemin) {
            $total += count($this->refus($chemin));
        }

        self::assertGreaterThan(30, $total, 'Aucun refus relevé, ou presque : la lecture du code a cessé '
            . 'de fonctionner et ce test ne prouverait plus rien.');
    }

    /**
     * Les fichiers d'outils qui produisent un plan — donc ceux dont les refus
     * atteignent l'utilisateur. Déduit du code, jamais recopié.
     *
     * @return list<string>
     */
    private function outilsProduisantUnPlan(): array
    {
        $fichiers = glob(self::DOSSIER . '/*.php') ?: [];
        $retenus = [];

        foreach ($fichiers as $chemin) {
            $code = (string) file_get_contents($chemin);
            // L'interface elle-même la « contient » sans l'implémenter.
            if (basename($chemin) === 'AiToolProduisantUnPlan.php') {
                continue;
            }
            if (preg_match('/implements[^{]*\bAiToolProduisantUnPlan\b/', $code) === 1) {
                $retenus[] = $chemin;
            }
        }

        self::assertNotEmpty($retenus, 'Aucun outil de plan trouvé : ' . AiToolProduisantUnPlan::class
            . ' a-t-elle été renommée ?');

        return $retenus;
    }

    /**
     * Chaque refus d'un fichier : sa ligne, et le littéral de tableau qui le porte.
     *
     * On délimite le tableau en comptant les crochets depuis le « [ » ouvrant — une
     * expression régulière s'arrêterait au premier « ] » d'un sous-tableau, et
     * déclarerait couvert un refus qui ne l'est pas.
     *
     * @return list<array{0: int, 1: string}>
     */
    private function refus(string $chemin): array
    {
        $code = (string) file_get_contents($chemin);
        $trouves = [];

        if (preg_match_all("/'pret'\s*=>\s*false/", $code, $m, PREG_OFFSET_CAPTURE) === false) {
            return [];
        }

        foreach ($m[0] as [$_, $offset]) {
            $ouvrant = strrpos($code, '[', $offset - strlen($code));
            if ($ouvrant === false) {
                continue;
            }
            $profondeur = 0;
            $fin = $ouvrant;
            for ($i = $ouvrant, $n = strlen($code); $i < $n; $i++) {
                if ($code[$i] === '[') {
                    $profondeur++;
                } elseif ($code[$i] === ']') {
                    $profondeur--;
                    if ($profondeur === 0) {
                        $fin = $i;
                        break;
                    }
                }
            }
            $ligne = substr_count(substr($code, 0, $offset), "\n") + 1;
            $bloc = substr($code, $ouvrant, $fin - $ouvrant + 1);

            // PAYLOAD PARTAGÉ. Un outil peut monter le tronc commun d'un refus dans une
            // variable, puis le compléter branche par branche :
            //   $commun = ['pret' => false, …];
            //   return AiToolResult::ok($commun + ['bloquant' => …, 'note' => …]);
            // Le littéral porteur de « pret » n'est alors PAS celui qui part au
            // courtier. S'arrêter à lui condamnerait un outil correct et, pire,
            // laisserait passer une branche qui, elle, ne dit rien à personne. On suit
            // donc chaque chemin de retour, séparément.
            $nom = null;
            $avant = substr($code, max(0, $ouvrant - 40), min(40, $ouvrant));
            if (preg_match('/\$(\w+)\s*=\s*$/', $avant, $capture) === 1) {
                $nom = $capture[1];
            }
            if ($nom !== null) {
                foreach ($this->compositions($code, $nom) as $compose) {
                    $trouves[] = $compose;
                }
                continue;
            }

            $trouves[] = [$ligne, $bloc];
        }

        return $trouves;
    }

    /**
     * Les littéraux qui COMPLÈTENT un tronc commun : « $commun + [ … ] ».
     *
     * @return list<array{0: int, 1: string}>
     */
    private function compositions(string $code, string $nom): array
    {
        $trouvailles = preg_match_all(
            '/\$' . preg_quote($nom, '/') . '\s*\+\s*\[/',
            $code,
            $m,
            PREG_OFFSET_CAPTURE,
        );
        if ($trouvailles === false || $trouvailles === 0) {
            return [];
        }

        $trouves = [];
        foreach ($m[0] as [$texte, $offset]) {
            $ouvrant = $offset + strlen($texte) - 1;
            $profondeur = 0;
            $fin = $ouvrant;
            for ($i = $ouvrant, $n = strlen($code); $i < $n; $i++) {
                if ($code[$i] === '[') {
                    $profondeur++;
                } elseif ($code[$i] === ']') {
                    $profondeur--;
                    if ($profondeur === 0) {
                        $fin = $i;
                        break;
                    }
                }
            }
            $trouves[] = [
                substr_count(substr($code, 0, $ouvrant), "\n") + 1,
                substr($code, $ouvrant, $fin - $ouvrant + 1),
            ];
        }

        return $trouves;
    }
}
