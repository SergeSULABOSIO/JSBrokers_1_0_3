<?php

namespace App\Tests\Ai;

use App\Ai\Scope\AiScope;
use App\Ai\Tool\AiToolResult;
use App\Ai\Tool\PreparerFacturationTool;
use App\Ai\Tool\PreparerOperationsTool;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Service\Workspace\WorkspaceAccessResolver;
use App\Services\JSBDynamicSearchService;
use App\Services\Note\SourceDeFacturation;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Outil « preparer_facturation » : GARDES et ROUTAGE.
 *
 * Le chemin nominal — un plan Note + ligne réellement préparé — est couvert par
 * `PreparerFacturationToolIntegrationTest`, qui a besoin d'une vraie base. Ici on
 * éprouve ce qui doit se décider AVANT toute recherche, et la reconnaissance de
 * l'intention par le moteur simulé.
 *
 * ── LA FRONTIÈRE QUI COMPTE ─────────────────────────────────────────────────
 * « Combien puis-je facturer ? » est une LECTURE, et le corpus de référence la
 * fige sous `suivi_impayes`. Cet outil-ci ÉMET la pièce ; il ne doit jamais
 * répondre à une question de chiffrage, sans quoi il volerait à son voisin des
 * demandes de recouvrement — l'erreur déjà payée une fois le 2026-09-27.
 */
class PreparerFacturationToolTest extends KernelTestCase
{
    protected function setUp(): void
    {
        static::bootKernel();
    }

    /**
     * @param bool $peutEcrireNote     droit d'écriture sur « Notes »
     * @param bool $peutLireLesRevenus droit de lecture sur « Revenus »
     */
    private function outil(
        bool $peutEcrireNote,
        bool $peutLireLesRevenus,
        ?JSBDynamicSearchService $recherche = null,
    ): PreparerFacturationTool {
        $resolver = $this->createMock(WorkspaceAccessResolver::class);
        $resolver->method('libellesEntites')->willReturn([
            'Note' => 'Notes',
            'RevenuPourCourtier' => 'Revenus',
            'Tranche' => 'Tranches',
            'Avenant' => 'Avenants',
        ]);
        $resolver->method('can')->willReturnCallback(
            static fn (Invite $invite, string $shortName, int $level) => match ($shortName) {
                'Note' => $peutEcrireNote,
                'RevenuPourCourtier' => $peutLireLesRevenus,
                default => false,
            },
        );

        return new PreparerFacturationTool(
            $resolver,
            $recherche ?? $this->createMock(JSBDynamicSearchService::class),
            static::getContainer()->get(SourceDeFacturation::class),
            static::getContainer()->get(PreparerOperationsTool::class),
        );
    }

    private function scope(): AiScope
    {
        return new AiScope(new Entreprise(), new Invite());
    }

    /**
     * SANS DROIT D'ÉCRITURE SUR LES NOTES, on ne cherche même pas. Le
     * `expects(never())` est l'assertion utile : une garde qui laisserait passer la
     * recherche aurait déjà lu des données hors périmètre avant de refuser.
     */
    public function testSansDroitDEcritureSurLesNotesRienNEstCherche(): void
    {
        $recherche = $this->createMock(JSBDynamicSearchService::class);
        $recherche->expects($this->never())->method('search');

        $resultat = $this->outil(false, true, $recherche)->execute(['trancheId' => 1], $this->scope());

        self::assertSame(AiToolResult::STATUS_HORS_PERIMETRE, $resultat->status);
    }

    /**
     * FACTURER SANS POUVOIR LIRE LES REVENUS, ce serait facturer à l'aveugle : on
     * proposerait une note dont on ne peut pas justifier une seule ligne.
     */
    public function testSansDroitDeLectureSurLesRevenusOnNeFacturePas(): void
    {
        $recherche = $this->createMock(JSBDynamicSearchService::class);
        $recherche->expects($this->never())->method('search');

        $resultat = $this->outil(true, false, $recherche)->execute(['trancheId' => 1], $this->scope());

        self::assertSame(AiToolResult::STATUS_HORS_PERIMETRE, $resultat->status);
    }

    /** Sans cible, il n'y a rien à facturer : on le dit, on n'invente pas d'échéance. */
    public function testSansCibleLOutilEstIntrouvable(): void
    {
        $resultat = $this->outil(true, true)->execute([], $this->scope());

        self::assertSame(AiToolResult::STATUS_INTROUVABLE, $resultat->status);
    }

    /**
     * @dataProvider ordresDeFacturer
     */
    public function testUnOrdreDeFacturerEstReconnu(string $question, array $attendu): void
    {
        self::assertSame($attendu, $this->outil(true, true)->match($question, $this->scope()));
    }

    public static function ordresDeFacturer(): iterable
    {
        yield 'échéance nommée' => [
            'Facture la commission de la tranche 74 à l’assureur.',
            ['trancheId' => 74],
        ];
        yield 'note de débit sur une échéance' => [
            'Prépare-moi la note de débit pour la tranche n° 12.',
            ['trancheId' => 12],
        ];
        yield 'police entière' => [
            'Établis la note de débit de la commission sur la police 305.',
            ['avenantId' => 305],
        ];
        yield 'avoir' => [
            'Émets un avoir sur la tranche 9.',
            ['trancheId' => 9],
        ];
    }

    /**
     * @dataProvider cequiNestPasUnOrdre
     */
    public function testCeQuiNestPasUnOrdreDeFacturerNeDeclenchePas(string $question): void
    {
        self::assertNull($this->outil(true, true)->match($question, $this->scope()));
    }

    public static function cequiNestPasUnOrdre(): iterable
    {
        // ⚠ CE CAS EST DANS LE CORPUS DE RÉFÉRENCE, sous suivi_impayes et en trousse
        // de LECTURE. Si cet outil le prenait, il volerait les questions de
        // recouvrement à celui dont c'est le métier.
        yield 'chiffrage' => ['Quelle est la somme des commissions que je peux déjà facturer aux assureurs ?'];
        yield 'chiffrage direct' => ['Combien puis-je facturer à SUNU ?'];
        yield 'demande de liste' => ['Liste les commissions facturables.'];
        yield 'sans identifiant' => ['Facture cette commission à l’assureur.'];
        yield 'hors sujet' => ['Montre-moi les clients de Kinshasa.'];
    }

    /**
     * L'EXCLUSION EST ÉCRITE CHEZ CELUI QUI DOIT RECULER. Mesuré le 2026-09-27 : une
     * phrase qui exclut fait perdre l'outil qui la porte. C'est donc à cet outil-ci
     * de renvoyer vers `suivi_impayes` pour le chiffrage — jamais l'inverse.
     */
    public function testLaDescriptionRenvoieLeChiffrageAuVoisin(): void
    {
        $description = $this->outil(true, true)->description();

        self::assertStringContainsString('suivi_impayes', $description,
            'La description doit nommer l’outil de chiffrage pour lui céder ces demandes.',
        );
        self::assertMatchesRegularExpression('/N\'ÉTABLIT AUCUN CHIFFRE/u', $description,
            'L’exclusion doit être explicite : c’est elle qui fait reculer cet outil sur les '
            . 'questions de montant.',
        );
    }
}
