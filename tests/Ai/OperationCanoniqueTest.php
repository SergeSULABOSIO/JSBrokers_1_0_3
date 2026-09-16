<?php

namespace App\Tests\Ai;

use App\Ai\Mutation\MutationOperation;
use PHPUnit\Framework\TestCase;

/**
 * LE VERBE D'UNE OPÉRATION, TOLÉRÉ COMME LE RESTE.
 *
 * ── POURQUOI CE FICHIER EXISTE ──────────────────────────────────────────────
 * Le modèle dicte TROIS choses quand il prépare une écriture : l'entité, les
 * champs, et l'opération. Deux d'entre elles ont depuis longtemps une couche de
 * tolérance, décidée à un seul endroit et fail-closed :
 *
 *   · l'entité  → EntiteCanonique::resoudre() (« Risques » → Risque)
 *   · les champs → ChampsDictes::normaliser() (map ou paires, les deux acceptées)
 *
 * La troisième n'en avait aucune. `MutationOperation::fromArray()` prend le verbe
 * brut — `(string) ($data['op'] ?? '')` —, et l'assemblage d'une étape de programme
 * exigeait littéralement « create », « edit » ou « delete ».
 *
 * Or c'est le seul des trois que l'interface affiche TRADUIT : le tableau d'un plan
 * montre « Création ». Un modèle qui relit sa propre présentation et redit
 * « Création » voyait son étape rejetée — en silence, sans que rien ne dise
 * laquelle des quatre causes avait joué.
 *
 * ⚠ LA TOLÉRANCE PORTE SUR LE MOT, JAMAIS SUR LE PÉRIMÈTRE. Un verbe inconnu ne
 * devient pas « create » par défaut : il ne se résout pas. Écrire la mauvaise
 * opération serait bien pire qu'un refus — « supprimer » pris pour « créer »
 * détruirait des données du courtier.
 */
class OperationCanoniqueTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function verbesReconnus(): array
    {
        return [
            // Le contrat lui-même : le chemin nominal reste gratuit.
            'create tel quel'   => ['create', MutationOperation::OP_CREATE],
            'edit tel quel'     => ['edit', MutationOperation::OP_EDIT],
            'delete tel quel'   => ['delete', MutationOperation::OP_DELETE],

            // La casse et les accents, comme pour l'entité.
            'CREATE'            => ['CREATE', MutationOperation::OP_CREATE],
            'Create'            => ['Create', MutationOperation::OP_CREATE],

            // CE QUE L'ÉCRAN AFFICHE, et que le modèle relit : c'est le cas qui a
            // fait échouer l'incident du 2026-09-14.
            'Création affichée' => ['Création', MutationOperation::OP_CREATE],
            'creation sans accent' => ['creation', MutationOperation::OP_CREATE],
            'Édition affichée'  => ['Édition', MutationOperation::OP_EDIT],
            'Suppression affichée' => ['Suppression', MutationOperation::OP_DELETE],

            // Les synonymes que le métier emploie naturellement.
            'ajout'             => ['ajout', MutationOperation::OP_CREATE],
            'modification'      => ['modification', MutationOperation::OP_EDIT],
            'modifier'          => ['modifier', MutationOperation::OP_EDIT],
            'supprimer'         => ['supprimer', MutationOperation::OP_DELETE],

            // Les espaces parasites ne sont pas une intention.
            'espaces'           => ['  create  ', MutationOperation::OP_CREATE],
        ];
    }

    /**
     * @dataProvider verbesReconnus
     */
    public function testUnVerbeDicteSeResoutSurLeContrat(string $dicte, string $attendu): void
    {
        self::assertSame($attendu, MutationOperation::canoniserOp($dicte), sprintf(
            'Le verbe « %s » doit se résoudre en « %s ».',
            $dicte,
            $attendu,
        ));
    }

    /**
     * @return array<string, array{0: ?string}>
     */
    public static function verbesIrresolubles(): array
    {
        return [
            'vide'        => [''],
            'espaces'     => ['   '],
            'null'        => [null],
            'inconnu'     => ['fusionner'],
            'approximatif'=> ['créer ou modifier'],
            'entité'      => ['Contact'],
        ];
    }

    /**
     * FAIL-CLOSED. Un verbe qu'on ne comprend pas ne prend AUCUNE valeur par
     * défaut : il ne se résout pas, et l'appelant refuse en le disant.
     *
     * @dataProvider verbesIrresolubles
     */
    public function testUnVerbeInconnuNePrendAucuneValeurParDefaut(?string $dicte): void
    {
        self::assertNull(MutationOperation::canoniserOp($dicte), sprintf(
            'Le verbe « %s » ne doit se résoudre en RIEN : une opération devinée peut détruire des '
            . 'données du courtier.',
            (string) $dicte,
        ));
    }

    /**
     * Tout ce que la canonisation rend doit être un membre du contrat — sinon elle
     * fabriquerait des opérations que le reste du système ne sait pas exécuter.
     */
    public function testLaCanonisationNeRendJamaisQueDesOperationsDuContrat(): void
    {
        foreach (self::verbesReconnus() as [$dicte, $_]) {
            self::assertContains(
                MutationOperation::canoniserOp($dicte),
                MutationOperation::OPS,
                sprintf('« %s » s\'est résolu hors du contrat.', $dicte),
            );
        }
    }
}
