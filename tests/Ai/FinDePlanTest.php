<?php

namespace App\Tests\Ai;

use App\Ai\Mutation\FinDePlan;
use PHPUnit\Framework\TestCase;

/**
 * UN PLAN MEURT DE TROIS FAÇONS, ET ON NE LES CONFOND PLUS.
 *
 * ── POURQUOI CE FICHIER EXISTE ──────────────────────────────────────────────
 * `mutationPlanCancelled` sert aujourd'hui à dire trois choses différentes :
 *
 *   1. l'utilisateur a cliqué sur « Annuler »  (AssistantIaController)
 *   2. le modèle a REMPLACÉ le plan            (PlanBuilder, remplacerPlanEnAttente)
 *   3. — bientôt — le plan a péri faute de décision
 *
 * Le gabarit, lui, ne connaît qu'un seul libellé : « Plan annulé — aucune donnée
 * n'a été modifiée. » Conséquence observée le 2026-09-14 : le courtier a vu ce
 * bandeau apparaître sous des plans qu'il n'avait JAMAIS annulés, simplement parce
 * que Ket en avait présenté une version complétée. Le fil lui attribuait des gestes
 * qu'il n'avait pas faits.
 *
 * ── UN ÉTAT, TROIS MOTIFS (et non trois booléens) ───────────────────────────
 * `estEnAttente()` reste vrai/faux, et tout le code décisionnel continue de ne
 * lire que cela — le verrou, la trousse, le court-circuit de compréhension. Ce qui
 * diffère entre les trois fins n'est pas l'ÉTAT, c'est ce qu'on en DIT. Trois
 * booléens autoriseraient « annulé ET périmé », qui ne veut rien dire.
 *
 * ── POURQUOI PAS DANS TypeAction ────────────────────────────────────────────
 * `TypeAction` est la source des actions que le serveur ÉMET et que le navigateur
 * EXÉCUTE, et son test refuse à raison un cas sans émetteur. Une fin de plan
 * n'émet aucune action : c'est un état persisté que le gabarit relit. Elle
 * appartient à la famille de `planExecute` / `planAnnule`, pas à celle des
 * panneaux à bouton.
 */
class FinDePlanTest extends TestCase
{
    /** La clé de meta est un contrat de persistance : elle ne se renomme pas à la légère. */
    public function testLaCleDeMetaEstStable(): void
    {
        self::assertSame('mutationPlanFin', FinDePlan::CLE_META);
    }

    public function testLesTroisFinsExistentEtSeDistinguent(): void
    {
        $valeurs = array_map(static fn (FinDePlan $f): string => $f->value, FinDePlan::cases());

        self::assertSame(['utilisateur', 'remplace', 'perime'], $valeurs, 'Trois fins, et trois seulement : '
            . 'une quatrième façon de mourir devrait se justifier par écrit.');
    }

    /**
     * LE BANDEAU « PLAN ANNULÉ » EST RÉSERVÉ AU CLIC. C'est tout l'objet de ce lot :
     * n'attribuer à l'utilisateur que les gestes qu'il a réellement faits.
     */
    public function testSeuleLaDecisionDeLUtilisateurSAnnonceCommeUneAnnulation(): void
    {
        self::assertStringContainsString('annulé', FinDePlan::UTILISATEUR->libelle());

        foreach ([FinDePlan::REMPLACE, FinDePlan::PERIME] as $fin) {
            self::assertStringNotContainsString('annulé', $fin->libelle(), sprintf(
                'La fin « %s » ne doit pas se présenter comme une annulation : personne n\'a cliqué sur '
                . '« Annuler », et le dire serait attribuer à l\'utilisateur un geste qu\'il n\'a pas fait.',
                $fin->value,
            ));
        }
    }

    /**
     * Chaque fin dit que RIEN N'A ÉTÉ ÉCRIT. C'est la seule information dont le
     * courtier a besoin dans les trois cas, et l'omettre laisserait un doute là où
     * il n'y en a aucun.
     */
    public function testChaqueFinRassureSurLAbsenceDEcriture(): void
    {
        foreach (FinDePlan::cases() as $fin) {
            self::assertMatchesRegularExpression(
                '/(rien n’a été|aucune donnée|n’a été enregistré)/ui',
                $fin->libelle(),
                sprintf('La fin « %s » doit dire explicitement que rien n\'a été enregistré.', $fin->value),
            );
        }
    }

    /**
     * La classe CSS pilote l'apparence du bandeau, en direct comme après F5. Elle
     * doit exister pour chaque fin, et rester un identifiant simple.
     */
    public function testChaqueFinPorteSaClasseDAffichage(): void
    {
        $classes = [];
        foreach (FinDePlan::cases() as $fin) {
            $classe = $fin->classeCss();
            self::assertMatchesRegularExpression('/^[a-z]+$/', $classe, sprintf(
                'La classe de « %s » doit être un mot simple : elle est suffixée à « aic-plan-status-- ».',
                $fin->value,
            ));
            $classes[] = $classe;
        }

        self::assertSame($classes, array_unique($classes), 'Deux fins ne peuvent pas partager la même '
            . 'apparence : le courtier ne les distinguerait plus.');
    }

    /** Relecture depuis la meta : une valeur inconnue ne devient pas « annulé » par défaut. */
    public function testUneValeurInconnueNeSeLitPasCommeUneAnnulation(): void
    {
        self::assertSame(FinDePlan::UTILISATEUR, FinDePlan::depuisMeta(['mutationPlanFin' => 'utilisateur']));
        self::assertSame(FinDePlan::PERIME, FinDePlan::depuisMeta(['mutationPlanFin' => 'perime']));
        self::assertNull(FinDePlan::depuisMeta(['mutationPlanFin' => 'n’importe quoi']));
        self::assertNull(FinDePlan::depuisMeta([]));
    }
}
