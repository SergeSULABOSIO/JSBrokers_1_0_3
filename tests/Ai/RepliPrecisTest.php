<?php

namespace App\Tests\Ai;

use App\Ai\Redaction\RepliPrecis;
use PHPUnit\Framework\TestCase;

/**
 * LE REPLI DE RÉDACTION : ce que Ket dit quand le modèle a réclamé un outil de plus
 * au lieu d'écrire.
 *
 * L'INCIDENT QUI A MOTIVÉ CE FICHIER (2026-08-10, conversation 35). Deux messages
 * consécutifs d'un courtier — « renouvelle la police de Kibali Goldmines SA », puis
 * « donne-moi les détails du renouvellement à venir du client Kibali » — se sont
 * soldés par la MÊME phrase creuse : « il me manque un élément pour conclure,
 * reformulez ». Le journal des tokens montre pourtant que l'outil avait tourné les
 * deux fois. Ce n'est jamais l'information qui manquait : c'est la restitution.
 *
 * La règle vérifiée ici est donc simple, et elle est absolue : dès qu'un outil a
 * rapporté quelque chose — une question, un blocage, des candidats, une recherche
 * vide —, l'utilisateur reçoit CE quelque chose, nommé, et jamais la phrase creuse.
 */
class RepliPrecisTest extends TestCase
{
    private function repli(): RepliPrecis
    {
        return new RepliPrecis();
    }

    /** Aucun outil n'a tourné : il n'y a réellement rien à raconter. */
    public function testSansAucunResultatLeRepliGeneriqueSubsiste(): void
    {
        $this->assertSame(RepliPrecis::GENERIQUE, $this->repli()->depuis([]));
    }

    /**
     * LE CAS DE L'INCIDENT. Une question déjà formulée par l'outil est exactement ce
     * que la phrase générique écrasait — alors qu'elle est la seule chose utile.
     */
    public function testUneQuestionDOutilEstServieTelleQuelle(): void
    {
        $texte = $this->repli()->depuis([[
            'outil' => 'preparer_mouvement_avenant',
            'data'  => ['pret' => false, 'aDemander' => [[
                'champ'    => 'dateEffet',
                'question' => 'À quelle date cette résiliation prend-elle effet ?',
            ]]],
        ]]);

        $this->assertStringContainsString('À quelle date cette résiliation prend-elle effet ?', $texte);
        $this->assertStringNotContainsString('Reformulez', $texte);
        $this->assertStringNotContainsString('il me manque un élément', $texte);
    }

    /**
     * Une résolution qui échoue porte le TERME cherché et les valeurs possibles :
     * l'utilisateur doit voir où l'on s'est trompé, et pouvoir répondre d'un mot.
     */
    public function testUneReferenceIntrouvableNommeLeTermeEtPropseLesValeurs(): void
    {
        $texte = $this->repli()->depuis([[
            'outil' => 'rechercher_entites',
            'data'  => ['pret' => false, 'aDemander' => [[
                'champ'    => 'Client',
                'libelle'  => 'Client',
                'probleme' => 'introuvable',
                'terme'    => 'Kibali',
                'valeurs'  => ['Kibali Goldmines SA', 'Kibali Mining'],
            ]]],
        ]]);

        $this->assertStringContainsString('Kibali', $texte);
        $this->assertStringContainsString('Kibali Goldmines SA', $texte);
        $this->assertStringContainsString('Kibali Mining', $texte);
        $this->assertStringNotContainsString('Reformulez', $texte);
    }

    /**
     * Un blocage se dit avec sa RAISON et avec ce qui a été cherché : sans cela,
     * l'utilisateur répète sa demande à l'identique, et le tour est perdu deux fois.
     */
    public function testUnBlocageEstRestitueAvecCeQuiAEteCherche(): void
    {
        $texte = $this->repli()->depuis([[
            'outil' => 'preparer_mouvement_avenant',
            'data'  => [
                'pret'       => false,
                'bloquant'   => 'Aucune police rattachée au client « Kibali » dans cet espace de travail.',
                'cherchePar' => ['référence de police contenant « Kibali »', 'nom de client « Kibali »'],
            ],
        ]]);

        $this->assertStringContainsString('Aucune police rattachée au client « Kibali »', $texte);
        $this->assertStringContainsString('J’ai cherché sur :', $texte);
        $this->assertStringContainsString('nom de client « Kibali »', $texte);
    }

    /**
     * Des candidats se présentent par ce qui permet de CHOISIR — la période, le client,
     * le statut —, jamais par leur identifiant interne : l'utilisateur ne connaît pas
     * les identifiants, et les lui montrer ne l'aide pas à trancher.
     */
    public function testDesCandidatsSePresententParLeursTraitsLisiblesEtNonParLeurId(): void
    {
        $texte = $this->repli()->depuis([[
            'outil' => 'preparer_mouvement_avenant',
            'data'  => ['pret' => false, 'ambigu' => [
                [
                    'id'        => 127,
                    'police'    => 'POL-2025',
                    'client'    => 'Kibali Goldmines SA',
                    'periode'   => '02/06/2026 → 01/06/2027',
                    'enVigueur' => true,
                ],
                [
                    'id'      => 129,
                    'police'  => 'POL-2025',
                    'client'  => 'Kibali Goldmines SA',
                    'periode' => '02/05/2026 → 01/06/2026',
                ],
            ]],
        ]]);

        $this->assertStringContainsString('02/06/2026 → 01/06/2027', $texte);
        $this->assertStringContainsString('02/05/2026 → 01/06/2026', $texte);
        $this->assertStringContainsString('en vigueur aujourd’hui', $texte);
        $this->assertStringNotContainsString('127', $texte, 'Un identifiant interne n’aide pas l’utilisateur à choisir.');
    }

    /**
     * Une recherche vide DIT ce qui a été cherché. « Aucun résultat » tout court laisse
     * croire que la donnée n'existe pas — alors que c'est souvent le filtre qui portait
     * à côté.
     */
    public function testUneRechercheVideDitCeQuiAEteCherche(): void
    {
        $texte = $this->repli()->depuis([[
            'outil' => 'rechercher_entites',
            'data'  => [
                'entite'     => 'Avenant',
                'libelle'    => 'Polices',
                'filtre'     => 'Kibali',
                'perimetre'  => 'Portefeuille Kinshasa',
                'totalItems' => 0,
                'items'      => [],
            ],
        ]]);

        $this->assertStringContainsString('Polices', $texte);
        $this->assertStringContainsString('Kibali', $texte);
        $this->assertStringContainsString('Portefeuille Kinshasa', $texte);
        $this->assertStringNotContainsString('Reformulez', $texte);
    }

    /**
     * Plusieurs outils dans le même tour : chacun apporte sa part, sans doublon.
     * L'utilisateur reçoit UN message, pas une succession de fragments répétés.
     */
    public function testPlusieursResultatsSontRegroupesSansDoublon(): void
    {
        $questionA = ['pret' => false, 'aDemander' => [['champ' => 'x', 'question' => 'Quelle date ?']]];

        $texte = $this->repli()->depuis([
            ['outil' => 'a', 'data' => $questionA],
            ['outil' => 'b', 'data' => $questionA],
            ['outil' => 'c', 'data' => ['pret' => false, 'bloquant' => 'Cette police est déjà résiliée.']],
        ]);

        $this->assertSame(1, substr_count($texte, 'Quelle date ?'), 'Deux outils disant la même chose = une seule ligne.');
        $this->assertStringContainsString('Cette police est déjà résiliée.', $texte);
    }

    /** Un résultat qui a abouti n'a rien à réclamer : il ne pollue pas le repli. */
    public function testUnResultatCompletNAlimentePasLeRepli(): void
    {
        $texte = $this->repli()->depuis([[
            'outil' => 'compter_entites',
            'data'  => ['entite' => 'Client', 'total' => 42],
        ]]);

        $this->assertSame(RepliPrecis::GENERIQUE, $texte);
    }
}
