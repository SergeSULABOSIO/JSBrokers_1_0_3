<?php

namespace App\Tests\Workspace;

use App\Entity\DemandeConge;
use App\Entity\HistoriqueDemande;
use App\Entity\JourFerie;
use App\Entity\MouvementConge;
use App\Entity\RegimeTravail;
use App\Entity\TypeAbsence;
use App\Token\ParametresTokenService;
use App\Token\TokenPricing;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * LA RUBRIQUE SE FACTURE À L'ACTE — scénarios 27 et 28 de la recette.
 *
 * ── PAS DE MÉCANIQUE DE FACTURATION PROPRE ──────────────────────────────────────────
 * Le métrage de l'application est GÉNÉRIQUE : un poids par entité, lu par
 * TokenAccountService::meterWrite au point de passage unique des écritures. Écrire un
 * second circuit de facturation pour les congés aurait donné deux endroits où lire le
 * tarif, donc un endroit où l'oublier. Ce test vérifie donc le BARÈME, pas une plomberie.
 *
 * ── ET IL RESTE RÉGLABLE ────────────────────────────────────────────────────────────
 * Le poids est éditable en console comme tous les autres : la constante n'est qu'un repli.
 * Un cabinet qui ne pose pas de congés ne paie rien pour la rubrique — aucun abonnement,
 * aucun montant par agent.
 */
class CongeTokensTest extends KernelTestCase
{
    /** Scénario 27 : une demande de congé coûte 50 tokens, comme une proposition. */
    public function testUneDemandeDeCongeVautCinquanteTokens(): void
    {
        self::assertSame(50, TokenPricing::WRITE_WEIGHTS[DemandeConge::class] ?? null);
    }

    /** Le barème effectif passe par le service : c'est lui que le métrage consulte. */
    public function testLeBaremeEffectifSuitLaConstante(): void
    {
        self::bootKernel();

        /** @var ParametresTokenService $parametres */
        $parametres = static::getContainer()->get(ParametresTokenService::class);

        self::assertSame(50, $parametres->weightFor(DemandeConge::class));
    }

    /**
     * LES LIGNES DÉRIVÉES NE SONT PAS DES ACTES DE L'UTILISATEUR.
     *
     * Mouvement de compteur et ligne d'historique naissent d'une décision déjà facturée :
     * leur donner un poids propre reviendrait à faire payer une même décision trois fois.
     * Elles restent au poids standard.
     */
    public function testLesLignesDeriveesRestentAuPoidsStandard(): void
    {
        self::bootKernel();

        /** @var ParametresTokenService $parametres */
        $parametres = static::getContainer()->get(ParametresTokenService::class);
        $standard = $parametres->defaultWriteWeight();

        foreach ([MouvementConge::class, HistoriqueDemande::class] as $derivee) {
            self::assertSame(
                $standard,
                $parametres->weightFor($derivee),
                sprintf('%s est une conséquence, pas un acte facturable en propre.', $derivee),
            );
        }
    }

    /** Le paramétrage n'est pas un acte de production : il reste au poids standard. */
    public function testLeParametrageResteAuPoidsStandard(): void
    {
        self::bootKernel();

        /** @var ParametresTokenService $parametres */
        $parametres = static::getContainer()->get(ParametresTokenService::class);
        $standard = $parametres->defaultWriteWeight();

        foreach ([TypeAbsence::class, JourFerie::class, RegimeTravail::class] as $reference) {
            self::assertSame($standard, $parametres->weightFor($reference));
        }
    }

    /**
     * Scénario 28 : LE COÛT N'EST JAMAIS AFFICHÉ À L'AGENT.
     *
     * Il n'est pas le payeur, et lui montrer un prix ne peut que le dissuader de poser des
     * jours auxquels il a droit. Le bandeau de son tableau de bord ne doit donc porter ni
     * token, ni tarif, ni coût.
     */
    public function testLeBandeauDeLAgentNAfficheAucunCout(): void
    {
        $gabarit = file_get_contents(__DIR__ . '/../../templates/components/dashboard/_block_conges.html.twig');

        self::assertIsString($gabarit);
        foreach (['token', 'tarif', 'coût', 'cout', 'prix'] as $interdit) {
            self::assertStringNotContainsStringIgnoringCase(
                $interdit,
                // On retire les commentaires Twig : ils EXPLIQUENT justement pourquoi le
                // coût est absent, et les compter reviendrait à interdire de le dire.
                (string) preg_replace('/\{#.*?#\}/s', '', $gabarit),
                sprintf("Le bandeau de l'agent ne doit pas parler de « %s ».", $interdit),
            );
        }
    }

    /**
     * L'entité est exposée au plan tarifaire de la console : sans cela, le poids ne serait
     * réglable nulle part et le tarif deviendrait un chiffre en dur.
     */
    public function testLaDemandeEstExposeeAuPlanTarifaire(): void
    {
        $source = file_get_contents(__DIR__ . '/../../src/Twig/Extension/PlanTarifaireExtension.php');

        self::assertIsString($source);
        self::assertStringContainsString('DemandeConge::class', $source);
        self::assertStringContainsString('Demande de congé', $source);
    }
}
