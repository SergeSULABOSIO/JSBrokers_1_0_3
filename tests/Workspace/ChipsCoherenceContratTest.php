<?php

namespace App\Tests\Workspace;

use App\Services\Canvas\Provider\List\ReversementRetroAgentListCanvasProvider;
use App\Services\Search\ReversementScope;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * LE CONTRAT DE COHÉRENCE DES CHIPS, ENTRE PHP ET JAVASCRIPT.
 *
 * Trois règles font se parler les chips d'une même barre — visibilité, retrait de l'orphelin,
 * implication. Elles sont DÉCLARÉES en PHP (`ReversementScope`, les canevas de liste) et
 * APPLIQUÉES en JavaScript (`chip-preset-etat.js`, `visibilite-conditions.js`). Une valeur
 * traverse donc les deux mondes, et rien dans les deux langages ne force l'accord.
 *
 * ── CE QUI ARRIVERAIT SANS CE TEST ───────────────────────────────────────────────────
 * Renommer une famille d'un côté seulement, ou changer le séparateur de « agent:12 »,
 * ne casse RIEN : le PHP continue de produire ses valeurs, le JS continue de les lire —
 * simplement il ne les reconnaît plus. Le chip ne s'allume pas, l'option ne se masque pas,
 * l'orphelin n'est pas retiré. Aucune erreur, aucune trace : juste des règles qui ne
 * s'appliquent plus. C'est la même famille de garde-fou que `ContratDesActionsTest`.
 *
 * On lit donc le module JS depuis PHP, et l'on vérifie l'accord sur les trois points où il
 * peut se rompre : le séparateur, la grammaire des conditions, et les opérateurs employés.
 */
class ChipsCoherenceContratTest extends KernelTestCase
{
    private const MODULE_ETAT = __DIR__ . '/../../assets/controllers/chip-preset-etat.js';
    private const MODULE_CONDITIONS = __DIR__ . '/../../assets/controllers/visibilite-conditions.js';
    private const CONTROLEUR_LISTE = __DIR__ . '/../../assets/controllers/list-manager_controller.js';

    /**
     * LE SÉPARATEUR DE FAMILLE est le même des deux côtés.
     *
     * PHP écrit « agent:12 » ; le JS décide de la famille en testant le début de la chaîne.
     * Un séparateur qui diverge fait échouer TOUTES les comparaisons de famille — donc les
     * deux chips s'allument ensemble, ou aucun.
     */
    public function testLeSeparateurDeFamilleEstPartage(): void
    {
        $valeur = ReversementScope::valeurBeneficiaire(ReversementScope::TYPE_AGENT, 12);
        self::assertSame('agent:12', $valeur, 'La forme « famille:id » est le contrat.');

        $module = (string) file_get_contents(self::MODULE_ETAT);
        self::assertStringContainsString(
            "chip.prefixe + ':'",
            $module,
            'Le module JS doit tester le MÊME séparateur que celui que PHP écrit.',
        );
    }

    /**
     * LES DEUX FAMILLES SONT NOMMÉES À L'IDENTIQUE dans les valeurs produites.
     *
     * Les noms voyagent en clair dans la valeur du critère et dans le préfixe du chip :
     * c'est PHP qui les écrit, et le JS ne fait que les comparer entre eux. Ce test épingle
     * les deux chaînes, pour qu'un renommage soit un choix et non un accident.
     */
    public function testLesDeuxFamillesGardentLeursNoms(): void
    {
        self::assertSame('agent', ReversementScope::TYPE_AGENT);
        self::assertSame('partenaire', ReversementScope::TYPE_PARTENAIRE);

        // Et le canevas les pose bien comme préfixes des deux sélecteurs : c'est le seul
        // endroit où la valeur produite et le chip qui l'affiche doivent se correspondre.
        self::bootKernel();
        $chips = static::getContainer()->get(ReversementRetroAgentListCanvasProvider::class)
            ->getCanvas()['filtres_predefinis'] ?? [];
        $beneficiaire = array_values(array_filter(
            $chips,
            static fn (array $g) => $g['critere'] === ReversementScope::CLE_BENEFICIAIRE,
        ))[0];

        $prefixes = [];
        foreach ($beneficiaire['options'] as $option) {
            if (isset($option['selecteur']['prefixe'])) {
                $prefixes[] = $option['selecteur']['prefixe'];
            }
        }
        self::assertSame(
            [ReversementScope::TYPE_AGENT, ReversementScope::TYPE_PARTENAIRE],
            $prefixes,
        );
    }

    /**
     * LES OPÉRATEURS DÉCLARÉS EN PHP SONT CEUX QUE LE JS SAIT ÉVALUER.
     *
     * `evaluerCondition` rend `false` sur un opérateur inconnu — un refus délibéré (« un
     * champ affiché par erreur est une porte ouverte »), mais qui, appliqué à un chip,
     * MASQUE l'option sans rien dire. Déclarer un opérateur que le JS ignore ferait donc
     * disparaître « Choisir un partenaire… » pour toujours.
     */
    public function testLesOperateursDeclaresSontEvaluablesParLeJs(): void
    {
        $conditions = ReversementScope::conditionsVisibiliteSelecteur(ReversementScope::TYPE_AGENT);
        $operateurs = array_column($conditions, 'operator');
        self::assertNotEmpty($operateurs);

        $moteur = (string) file_get_contents(self::MODULE_CONDITIONS);
        foreach ($operateurs as $operateur) {
            self::assertStringContainsString(
                "condition.operator === '{$operateur}'",
                $moteur,
                sprintf(
                    'L’opérateur « %s » est déclaré par PHP mais le moteur JS ne le connaît pas : '
                    . 'l’option serait masquée en silence.',
                    $operateur,
                ),
            );
        }
    }

    /**
     * LA CHAÎNE VIDE DOIT ÊTRE UNE VALEUR ACCEPTÉE, pas une absence.
     *
     * La condition d'un sélecteur vaut « aucun filtre de type, OU cette famille ». Si le JS
     * traitait l'absence de critère comme `undefined`, `evaluerCondition` rendrait `false`
     * et les DEUX sélecteurs disparaîtraient à l'ouverture de la rubrique — le contraire
     * exact de ce qu'on veut, et sur l'écran le plus fréquent.
     */
    public function testLAbsenceDeTypeEstUneValeurDeclaree(): void
    {
        $conditions = ReversementScope::conditionsVisibiliteSelecteur(ReversementScope::TYPE_PARTENAIRE);

        self::assertContains('', $conditions[0]['value'], 'La chaîne vide vaut « Tous ».');
        self::assertContains(ReversementScope::TYPE_PARTENAIRE, $conditions[0]['value']);
    }

    /**
     * LE CONTRÔLEUR LIT BIEN LES DEUX ATTRIBUTS que le gabarit pose.
     *
     * Un attribut posé mais jamais lu ne lève rien : la règle devient simplement inerte.
     * C'est arrivé sur ce projet — un champ Vich renommé, un upload jeté en silence.
     */
    public function testLeControleurLitLesDeuxDeclarations(): void
    {
        $controleur = (string) file_get_contents(self::CONTROLEUR_LISTE);

        // Stimulus expose `data-visibility-conditions` en `dataset.visibilityConditions`.
        self::assertStringContainsString('dataset.visibilityConditions', $controleur);
        self::assertStringContainsString('dataset.selecteurImplique', $controleur);

        $gabarit = (string) file_get_contents(__DIR__ . '/../../templates/components/_List_manager.html.twig');
        self::assertStringContainsString('data-visibility-conditions=', $gabarit);
        self::assertStringContainsString('data-selecteur-implique=', $gabarit);
    }
}
