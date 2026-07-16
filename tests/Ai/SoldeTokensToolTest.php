<?php

namespace App\Tests\Ai;

use App\Ai\Scope\AiScope;
use App\Ai\Tool\AiToolResult;
use App\Ai\Tool\SoldeTokensTool;
use App\Entity\AssistantMessage;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Utilisateur;
use App\Token\ParametresTokenService;
use App\Token\TokenAccountService;
use PHPUnit\Framework\TestCase;

/**
 * Outil « solde_tokens » : solde du PROPRIÉTAIRE de l'entreprise du scope
 * (aucun argument — pas de solde d'une autre entreprise), introuvable sans
 * propriétaire, rappel de la logique de consommation aux valeurs dynamiques
 * du plan, déclencheurs simulés sans collision avec les questions métier
 * (« solde du client » = donnée comptable). Tests purs.
 */
class SoldeTokensToolTest extends TestCase
{
    private function makeTool(): SoldeTokensTool
    {
        $compte = $this->createMock(TokenAccountService::class);
        $compte->method('getBalance')->willReturn([
            'free'            => 750,
            'paid'            => 9200,
            'total'           => 9950,
            'windowStartedAt' => new \DateTimeImmutable('2026-07-16 06:00'),
            'nextRenewalAt'   => new \DateTimeImmutable('2026-07-16 14:00'),
            'allowance'       => 1000,
        ]);
        $compte->method('coutContexteIa')->willReturn(8);

        $parametres = $this->createMock(ParametresTokenService::class);
        $parametres->method('readWeight')->willReturn(2);
        $parametres->method('defaultWriteWeight')->willReturn(5);
        $parametres->method('weightFor')->willReturnCallback(
            static fn (string $fqcn) => $fqcn === AssistantMessage::class ? 10 : 5,
        );
        $parametres->method('freeAllowance')->willReturn(1000);
        $parametres->method('freeWindowHours')->willReturn(8);

        return new SoldeTokensTool($compte, $parametres);
    }

    private function makeScope(bool $avecProprietaire = true): AiScope
    {
        $entreprise = (new Entreprise())->setNom('Cabinet Alpha');
        if ($avecProprietaire) {
            $entreprise->setUtilisateur(new Utilisateur());
        }

        return new AiScope($entreprise, new Invite());
    }

    public function testSoldeDuProprietaireRestitue(): void
    {
        $result = $this->makeTool()->execute([], $this->makeScope());

        $this->assertSame(AiToolResult::STATUS_OK, $result->status);
        $this->assertSame('Cabinet Alpha', $result->data['entreprise']);
        $this->assertSame(9950, $result->data['total']);
        $this->assertSame(9200, $result->data['prepayes']);
        $this->assertSame(750, $result->data['gratuits']);
        $this->assertSame(1000, $result->data['allocationGratuite']);
        $this->assertSame('2026-07-16 14:00', $result->data['prochainRenouvellementGratuit']);
        $this->assertNull($result->uiAction);
    }

    public function testLogiqueConsommationAuxValeursDuPlan(): void
    {
        $result = $this->makeTool()->execute([], $this->makeScope());

        $texte = $result->data['logiqueConsommation'];
        $this->assertStringContainsString('2 tokens par enregistrement', $texte);
        $this->assertStringContainsString('10 tokens par message', $texte);
        $this->assertStringContainsString('8 tokens par objet attaché', $texte);
        $this->assertStringContainsString('1000 tokens offerts', $texte);
        $this->assertStringContainsString('toutes les 8 heures', $texte);
        $this->assertStringContainsString('PROPRIÉTAIRE', $texte);
    }

    public function testSansProprietaireIntrouvable(): void
    {
        $result = $this->makeTool()->execute([], $this->makeScope(avecProprietaire: false));

        $this->assertSame(AiToolResult::STATUS_INTROUVABLE, $result->status);
    }

    public function testMatchDeclencheursEtNonCollisions(): void
    {
        $tool = $this->makeTool();
        $scope = $this->makeScope();

        $this->assertSame([], $tool->match('Quel est notre solde de tokens ?', $scope));
        $this->assertSame([], $tool->match('Combien de tokens nous reste-t-il ?', $scope));
        $this->assertSame([], $tool->match('Combien de crédits restants avons-nous ?', $scope));
        $this->assertSame([], $tool->match('Comment se consomment les tokens ?', $scope));

        // Domaine d'autres outils : soldes métier, comptages d'entités.
        $this->assertNull($tool->match('Quel est le solde du client Alpha ?', $scope));
        $this->assertNull($tool->match('Quel solde restant dû sur la tranche 2/4 ?', $scope));
        $this->assertNull($tool->match('Combien de clients avons-nous ?', $scope));
    }
}
