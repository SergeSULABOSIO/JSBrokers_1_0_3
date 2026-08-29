<?php

namespace App\Tests\Ai;

use App\Ai\Tool\OuvrirRubriqueTool;
use App\Ai\Tool\RetrocommissionsTool;
use App\Ai\Tool\SignalerReversementRetroAgentTool;
use App\Entity\Invite;
use App\Entity\Partenaire;
use App\Service\Retro\BeneficiaireRetro;
use App\Services\Canvas\Provider\Form\InviteFormCanvasProvider;
use App\Services\Canvas\Provider\Form\PartenaireFormCanvasProvider;
use App\Services\Canvas\Provider\List\ReversementRetroAgentListCanvasProvider;
use App\Services\Search\ReversementScope;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * PARITÉ UI ⊆ KET SUR LES RÉTROS DES DEUX FAMILLES D'INTERMÉDIAIRES.
 *
 * La consigne est sans exception : tout ce que l'écran permet, Ket doit le savoir et le
 * faire. Un test à cas choisis n'aurait tenu que les cas qu'on aurait pensé à choisir —
 * c'est ainsi que le partenaire est resté sans rapport de production alors que le socle
 * savait le rendre. Ce test ÉNUMÈRE donc les gestes depuis le CODE de l'écran (actions de
 * fiche, chips de la rubrique) et vérifie que chacun a son pendant côté assistant. Ajouter
 * un geste à l'écran sans l'ouvrir à Ket casse ce test.
 *
 * Il ne vérifie PAS les montants — d'autres tests s'en chargent, et à la maille de la
 * tranche. Il vérifie la SURFACE : ce qui est atteignable de part et d'autre.
 */
class RetrosIntermediairesPariteKetTest extends KernelTestCase
{
    /** @return array<int, array<string, mixed>> */
    private function actions(string $providerClass, object $entite): array
    {
        self::bootKernel();

        return static::getContainer()->get($providerClass)
            ->getCanvas($entite, null)['parametres']['attribute_actions'] ?? [];
    }

    // ===================== 1. Les deux familles ont les MÊMES gestes d'écran =====================

    /**
     * L'ÉCRAN OFFRE LES DEUX MÊMES ACTIONS AUX DEUX FAMILLES.
     *
     * C'est l'énumération de départ : le reste du test s'appuie sur elle. Le partenaire
     * n'avait aucune de ces deux actions — ses chiffres n'existaient qu'en agrégat sur sa
     * fiche, et seul l'assistant savait les détailler. L'asymétrie était donc INVERSE de
     * celle qu'on redoute d'habitude.
     */
    public function testLesDeuxFamillesPortentLesMemesActionsDeFiche(): void
    {
        $agent = $this->actions(InviteFormCanvasProvider::class, new Invite());
        $partenaire = $this->actions(PartenaireFormCanvasProvider::class, new Partenaire());

        foreach (['agent' => $agent, 'partenaire' => $partenaire] as $famille => $actions) {
            $evenements = array_column($actions, 'event');
            // LE RAPPORT EST DEVENU UNE RUBRIQUE. Il ne s'ouvrait que par cette porte et
            // ne se situait nulle part dans l'arbre du menu ; le clic ouvre désormais
            // « Production intermédiaires », pré-filtrée sur le bénéficiaire. Ce qui est
            // TENU ici n'a pas changé d'un pouce : les DEUX familles doivent y accéder,
            // et c'est l'oubli du partenaire que ce test avait attrapé.
            self::assertContains('ui:production.rubrique-request', $evenements, sprintf(
                'La fiche d’un %s doit ouvrir sa production.',
                $famille,
            ));
            self::assertContains('ui:retroagent.reversement-request', $evenements, sprintf(
                'La fiche d’un %s doit permettre de lui verser sa rétrocommission.',
                $famille,
            ));
        }

        // Les URL diffèrent — chaque famille a ses routes — mais le CORPS est partagé côté
        // contrôleur. Ce qu'on exige ici, c'est que les deux pointent quelque part.
        foreach (array_merge($agent, $partenaire) as $action) {
            self::assertNotEmpty($action['url'] ?? null, 'Une action sans URL ne fait rien.');
        }
    }

    // ===================== 2. LIRE : le rapport, pour les deux =====================

    /**
     * `retrocommissions` sait nommer LA FAMILLE, et son énumération vient de l'interface.
     *
     * Si une troisième famille de bénéficiaire apparaissait un jour dans
     * `BeneficiaireRetro`, l'écran la rendrait (le socle est générique) et ce test
     * exigerait que Ket la nomme aussi.
     */
    public function testLaLectureCouvreLesDeuxFamilles(): void
    {
        self::bootKernel();
        $schema = static::getContainer()->get(RetrocommissionsTool::class)->schema();

        self::assertSame(
            [BeneficiaireRetro::TYPE_AGENT, BeneficiaireRetro::TYPE_PARTENAIRE],
            $schema['properties']['type']['enum'],
        );
        self::assertArrayHasKey('beneficiaire', $schema['properties']);
    }

    // ===================== 3. ÉCRIRE : le versement, pour les deux =====================

    /**
     * L'OUTIL D'ÉCRITURE ACCEPTE LES DEUX BÉNÉFICIAIRES, ET NE PEUT PAS EXIGER L'UN.
     *
     * `required: ['agentId']` aurait rendu le partenaire purement inatteignable — la garde
     * la plus silencieuse possible, puisque le schéma est ce que le modèle lit pour décider.
     * Le XOR ne s'exprimant pas en JSON Schema, il est vérifié dans le corps de l'outil, et
     * le refus doit nommer l'alternative.
     */
    public function testLEcritureAccepteLesDeuxBeneficiairesSansEnExigerAucun(): void
    {
        self::bootKernel();
        $schema = static::getContainer()->get(SignalerReversementRetroAgentTool::class)->schema();

        self::assertArrayHasKey('agentId', $schema['properties']);
        self::assertArrayHasKey('partenaireId', $schema['properties']);
        self::assertNotContains(
            'agentId',
            $schema['required'],
            'Exiger agentId rendrait le versement à un partenaire impossible à formuler.',
        );
        // Le justificatif, LUI, reste requis : c'est la règle de l'écran, et un versement
        // sans preuve est un décaissement que rien ne rattache à la banque.
        self::assertContains('fichierId', $schema['required']);
    }

    /**
     * LA MAILLE EST L'ÉCHÉANCE, DES DEUX CÔTÉS.
     *
     * Le picker propose des tranches ; l'outil doit en accepter. S'il ne connaissait que
     * l'avenant, Ket réglerait des affaires quand l'écran règle des échéances — et il
     * faudrait inventer une répartition que personne n'a écrite.
     */
    public function testLEcritureSeFaitALaMailleDeLEcheance(): void
    {
        self::bootKernel();
        $items = static::getContainer()->get(SignalerReversementRetroAgentTool::class)
            ->schema()['properties']['lignes']['items']['properties'];

        self::assertArrayHasKey('trancheId', $items, 'Le versement s’enregistre par TRANCHE.');
        // L'avenant reste accepté comme RACCOURCI (« règle cette police ») : il désigne
        // alors toutes les échéances exigibles. Le retirer ferait perdre un geste naturel.
        self::assertArrayHasKey('avenantId', $items);
    }

    /**
     * LA DESCRIPTION NE DOIT PLUS INTERDIRE LE PARTENAIRE.
     *
     * Elle disait « NE PAS utiliser pour un PARTENAIRE externe : sa rétrocommission se
     * facture par note de crédit ». C'était vrai, et c'est ce que le modèle lit pour
     * décider : laisser la phrase aurait suffi à rendre l'outil inaccessible aux
     * partenaires malgré un schéma qui les accepte.
     */
    public function testLaDescriptionNInterditPlusLePartenaire(): void
    {
        self::bootKernel();
        $description = static::getContainer()->get(SignalerReversementRetroAgentTool::class)->description();

        self::assertStringContainsString('partenaireId', $description);
        self::assertStringNotContainsString('NE PAS utiliser pour un PARTENAIRE', $description);
        self::assertStringNotContainsString('note de crédit', $description);
        // Le compte comptable des DEUX familles est annoncé : c'est ce qui reste asymétrique.
        self::assertStringContainsString('6611', $description);
        self::assertStringContainsString('632', $description);
    }

    // ===================== 4. FILTRER : chaque chip de l'écran a son argument =====================

    /**
     * CHAQUE CHIP DE LA RUBRIQUE EST ATTEIGNABLE PAR `ouvrir_rubrique`.
     *
     * C'est le cœur de l'énumération : les chips sont lus dans le canevas de LISTE, donc
     * dans la source de vérité de l'écran, et chacun doit trouver son argument. Ajouter un
     * chip sans l'exposer à Ket casse ici — c'était la dérive à empêcher, et elle est
     * arrivée : le chip « Type » a existé à l'écran avant d'être dit à l'assistant.
     */
    public function testChaqueChipDeLaRubriqueAUnArgumentChezKet(): void
    {
        self::bootKernel();
        $chips = static::getContainer()->get(ReversementRetroAgentListCanvasProvider::class)
            ->getCanvas()['filtres_predefinis'] ?? [];
        self::assertNotEmpty($chips, 'La rubrique doit déclarer des chips.');

        // La correspondance chip => argument. Elle est écrite ICI, en un seul endroit : si
        // un chip apparaît sans entrée dans cette table, le test le dit au lieu de
        // l'ignorer — c'est ce qui rend l'énumération complète et non indicative.
        $argumentPour = [
            ReversementScope::CLE_JUSTIFICATIF => 'justificatif',
            ReversementScope::CLE_PERIODE      => 'periode',
            ReversementScope::CLE_VIREMENT     => 'virement',
            ReversementScope::CLE_TYPE         => 'type',
            ReversementScope::CLE_BENEFICIAIRE => 'beneficiaire',
        ];

        $schema = static::getContainer()->get(OuvrirRubriqueTool::class)->schema()['properties'];
        foreach (array_column($chips, 'critere') as $critere) {
            self::assertArrayHasKey($critere, $argumentPour, sprintf(
                'Le chip « %s » n’a aucun argument déclaré : l’écran sait filtrer là où Ket ne sait pas.',
                $critere,
            ));
            self::assertArrayHasKey($argumentPour[$critere], $schema, sprintf(
                'L’argument « %s » manque au schéma d’ouvrir_rubrique.',
                $argumentPour[$critere],
            ));
        }
    }

    /**
     * LES VALEURS DES CHIPS SONT CELLES DE L'ASSISTANT, sans exception.
     *
     * Deux listes de valeurs finissent toujours par désigner deux sous-ensembles. Elles
     * viennent donc de la même constante — et ce test le constate plutôt que de l'espérer.
     */
    public function testLesValeursDesChipsSontCellesDeLAssistant(): void
    {
        self::bootKernel();
        $schema = static::getContainer()->get(OuvrirRubriqueTool::class)->schema()['properties'];

        foreach ([
            ReversementScope::CLE_JUSTIFICATIF => 'justificatif',
            ReversementScope::CLE_PERIODE      => 'periode',
            ReversementScope::CLE_VIREMENT     => 'virement',
            ReversementScope::CLE_TYPE         => 'type',
        ] as $cle => $argument) {
            self::assertSame(
                array_keys(ReversementScope::GROUPES[$cle]),
                $schema[$argument]['enum'],
                sprintf('Les valeurs de « %s » divergent entre l’écran et Ket.', $argument),
            );
        }
    }

    /**
     * LE CHIP-SÉLECTEUR PROPOSE DEUX FAMILLES ; `beneficiaire` DOIT LES RÉSOUDRE TOUTES DEUX.
     *
     * L'écran laisse choisir un agent OU un partenaire. Ket ne cherchait que parmi les
     * agents : « ouvre ce que j'ai versé à SUNU Courtage » renvoyait donc un introuvable
     * sur une rubrique qui contient précisément ces versements.
     *
     * On lit les entités des sélecteurs DANS le canevas, et l'on exige que la résolution
     * de l'outil les couvre — vérifiée sur le code, l'outil n'ayant pas de base ici.
     */
    public function testLeSelecteurDeBeneficiaireEtKetResolventLesMemesFamilles(): void
    {
        self::bootKernel();
        $chips = static::getContainer()->get(ReversementRetroAgentListCanvasProvider::class)
            ->getCanvas()['filtres_predefinis'] ?? [];

        $beneficiaire = array_values(array_filter(
            $chips,
            static fn (array $c) => $c['critere'] === ReversementScope::CLE_BENEFICIAIRE,
        ));
        self::assertCount(1, $beneficiaire, 'Un seul chip de bénéficiaire, deux familles dedans.');

        $entites = array_column(
            array_column(array_values(array_filter(
                $beneficiaire[0]['options'],
                static fn (array $o) => isset($o['selecteur']),
            )), 'selecteur'),
            'entite',
        );
        self::assertSame(['Invite', 'Partenaire'], $entites);

        // La résolution côté Ket : une méthode par famille, dérivée du code plutôt que
        // devinée. Sans la seconde, le chip proposerait ce que l'assistant ne trouve pas.
        $outil = new \ReflectionClass(OuvrirRubriqueTool::class);
        self::assertTrue($outil->hasMethod('agentNomme'));
        self::assertTrue(
            $outil->hasMethod('partenaireNomme'),
            'Ket doit résoudre un PARTENAIRE par son nom : le chip de l’écran le propose.',
        );

        // Et le paramètre le DIT : ce que le modèle lit décide de ce qu'il tente.
        $description = static::getContainer()->get(OuvrirRubriqueTool::class)
            ->schema()['properties']['beneficiaire']['description'];
        self::assertStringContainsString('partenaire', mb_strtolower($description));
    }
}
