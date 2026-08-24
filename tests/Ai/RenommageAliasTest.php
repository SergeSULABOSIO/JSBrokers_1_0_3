<?php

namespace App\Tests\Ai;

use App\Ai\Mutation\EntiteCanonique;
use App\Ai\Tool\EntiteLexique;
use App\Service\Workspace\WorkspaceAccessResolver;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * RENOMMER UNE RUBRIQUE NE RENOMME PAS LE VOCABULAIRE DES COURTIERS.
 *
 * « Reversements de rétrocommission » est devenu « Rétros agents » le 2026-08-24, parce que
 * l'intitulé complet se tronquait en « Reversements d… » dans un onglet. Mais l'assistant
 * DÉRIVE ses mots-clés des libellés de la carte de permissions (`EntiteLexique`) : renommer
 * sans plus aurait retiré l'ancien terme de ce qu'il comprend, et un courtier qui l'emploie
 * encore — c'est-à-dire tous, pendant des mois — se serait vu refuser sa demande.
 *
 * C'est exactement l'incident du 2026-08-12 que `EntiteCanonique` a été écrite pour clore :
 * Ket désignait les entités par les libellés de l'écran, l'allowlist ne connaissait que les
 * noms courts, et le courtier n'enregistrait rien.
 *
 * D'où le slot d'ALIAS, et d'où ce test : il tient les deux noms à la fois, et il tomberait
 * si quelqu'un renommait de nouveau sans conserver le précédent.
 */
class RenommageAliasTest extends KernelTestCase
{
    private const NOM_COURT = 'ReversementRetroAgent';
    private const NOUVEAU = 'Rétros agents';
    private const ANCIEN = 'Reversements de rétrocommission';

    protected function setUp(): void
    {
        static::bootKernel();
    }

    private function lexique(): EntiteLexique
    {
        return static::getContainer()->get(EntiteLexique::class);
    }

    /** Le libellé affiché est bien le nouveau — c'est lui qu'on lit à l'écran. */
    public function testLeLibelleAfficheEstLeNouveau(): void
    {
        $labels = static::getContainer()->get(WorkspaceAccessResolver::class)->libellesEntites();

        self::assertSame(self::NOUVEAU, $labels[self::NOM_COURT] ?? null);
    }

    /**
     * LES DEUX NOMS MÈNENT À LA MÊME RUBRIQUE.
     *
     * Le nouveau parce qu'il est affiché, l'ancien parce qu'il est encore dit.
     */
    public function testLAncienEtLeNouveauNomSontComprisTousLesDeux(): void
    {
        $motsCles = $this->lexique()->lexique()[self::NOM_COURT] ?? [];

        self::assertNotSame([], $motsCles, 'La rubrique a disparu du lexique de l’assistant.');

        foreach ([self::NOUVEAU, self::ANCIEN] as $terme) {
            self::assertContains(
                \App\Ai\AiText::normalize($terme),
                $motsCles,
                sprintf('« %s » doit rester compris de l’assistant.', $terme),
            );
        }
    }

    /**
     * L'ALIAS SUIT LES MÊMES VARIANTES que le libellé courant.
     *
     * Le lexique dérive une forme singulier/plurielle de chaque terme ; un alias qui n'en
     * bénéficierait pas laisserait « reversement de rétrocommission » au singulier sur le
     * carreau, alors que c'est une façon courante de le dire.
     */
    public function testLAliasBeneficieDesMemesVariantes(): void
    {
        $motsCles = $this->lexique()->lexique()[self::NOM_COURT] ?? [];
        $ancienNormalise = \App\Ai\AiText::normalize(self::ANCIEN);

        self::assertContains(rtrim($ancienNormalise, 's'), $motsCles);
    }

    /**
     * L'ALIAS NE VOLE RIEN À PERSONNE.
     *
     * Le lexique retire de lui-même tout mot-clé revendiqué par deux entités — écrire dans
     * la mauvaise entité serait bien pire qu'un refus. On vérifie donc qu'aucune AUTRE
     * rubrique ne s'est vue dépouiller de ses mots-clés par l'ajout de cet alias.
     */
    public function testAucuneAutreRubriqueNePerdSesMotsCles(): void
    {
        $lexique = $this->lexique()->lexique();

        foreach (['Avenant', 'Client', 'Note', 'Paiement'] as $voisine) {
            self::assertNotEmpty(
                $lexique[$voisine] ?? [],
                sprintf('« %s » ne doit pas avoir perdu ses mots-clés.', $voisine),
            );
        }
    }

    /**
     * ET L'ÉCRITURE SUIT. `EntiteCanonique` renverse ce lexique pour accepter les termes
     * dictés par le modèle : les deux noms doivent y résoudre le même nom court, sans quoi
     * un plan d'écriture rédigé avec l'ancien vocabulaire serait refusé étape par étape.
     */
    public function testLesDeuxNomsResolventPourLEcriture(): void
    {
        $canonique = static::getContainer()->get(EntiteCanonique::class);

        foreach ([self::NOUVEAU, self::ANCIEN] as $terme) {
            self::assertSame(
                self::NOM_COURT,
                $canonique->resoudre($terme),
                sprintf('« %s » doit résoudre vers %s pour l’écriture.', $terme, self::NOM_COURT),
            );
        }
    }
}
