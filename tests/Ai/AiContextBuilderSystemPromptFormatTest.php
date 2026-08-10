<?php

namespace App\Tests\Ai;

use App\Ai\AiContextBuilder;
use App\Ai\AiRequest;
use App\Ai\Scope\AiScope;
use App\Entity\Entreprise;
use App\Entity\Invite;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * AiContextBuilder::toSystemPrompt() n'utilise que systemContext (jamais le
 * scope) : ce test construit une AiRequest en mémoire, sans fixtures ni
 * accès base — il garde la consigne Markdown/pastille du prompt système
 * stable face à un futur refactor (non-régression de la ligne 145-146
 * historique, remplacée pour autoriser le Markdown restreint).
 */
class AiContextBuilderSystemPromptFormatTest extends KernelTestCase
{
    public function testPromptAutoriseMarkdownEtEnseigneLaConventionPastille(): void
    {
        static::bootKernel();
        $builder = static::getContainer()->get(AiContextBuilder::class);

        $request = new AiRequest(
            systemContext: [
                'assistantNom'   => 'Ket',
                'entrepriseNom'  => 'PHPUnit Format SARL',
                'perimetre'      => [],
                'date'           => '2026-07-20',
                'objetsAttaches' => [],
            ],
            messages: [],
            scope: new AiScope(new Entreprise(), new Invite()),
        );

        $prompt = $builder->toSystemPrompt($request);

        $this->assertStringContainsString('Markdown', $prompt);
        $this->assertStringContainsString('[Payée](#success)', $prompt);
        $this->assertStringContainsString('[En retard](#danger)', $prompt);
        $this->assertStringContainsString('[À surveiller](#warning)', $prompt);
        $this->assertStringContainsString('[Info](#info)', $prompt);
        $this->assertStringContainsString('[Aucun impayé](#neutral)', $prompt);
    }

    /**
     * LE CONTRAT DE PRÉSENTATION, verrouillé règle par règle.
     *
     * L'INCIDENT (capture du 2026-08-10, tableau des primes signalées). Le prompt ne
     * disait rien de l'alignement, rien du format des dates, rien de la ligne de
     * totaux — et surtout n'interdisait pas d'AJOUTER une colonne absente des données.
     * Ket a donc livré des montants alignés à gauche, des dates « 2026-08-05 », un
     * total noyé dans les lignes, un « Client » découpé dans le préfixe des références
     * et un « Assureur partenaire » posé en bouche-trou.
     *
     * Ces assertions sont volontairement littérales : chacune correspond à un défaut
     * observé, et le contrat est aussi la spec que doivent honorer le renderer du chat
     * et le parseur d'export.
     */
    public function testPromptImposeLeContratDePresentationDesTableaux(): void
    {
        static::bootKernel();
        $builder = static::getContainer()->get(AiContextBuilder::class);

        $request = new AiRequest(
            systemContext: [
                'assistantNom'   => 'Ket',
                'entrepriseNom'  => 'PHPUnit Présentation SARL',
                'perimetre'      => [],
                'date'           => '2026-08-11',
                'objetsAttaches' => [],
            ],
            messages: [],
            scope: new AiScope(new Entreprise(), new Invite()),
        );

        $prompt = $builder->toSystemPrompt($request);

        // (1) Les colonnes déclarées par l'outil font autorité.
        $this->assertStringContainsString('presentation.colonnes', $prompt);
        // (2) LA règle qui manquait : une colonne ne s'invente pas.
        $this->assertStringContainsString('COLONNE FANTÔME', $prompt);
        $this->assertStringContainsString('Assureur partenaire', $prompt);
        // (3) L'alignement, écrit en GFM et désormais honoré par le renderer.
        $this->assertStringContainsString('---:', $prompt);
        // (4) Les formats : date lisible, montant avec sa monnaie, taux en points.
        $this->assertStringContainsString('jj/mm/aaaa', $prompt);
        $this->assertStringContainsString('1 234,50 $', $prompt);
        // (5) La ligne de totaux, distincte et jamais posée sur une date ou un taux.
        $this->assertStringContainsString('**TOTAL**', $prompt);
        $this->assertStringContainsString('presentation.totaliser', $prompt);
        // (6) Une troncature muette se lit comme un inventaire complet.
        $this->assertStringContainsString('éléments au total', $prompt);
    }

    /**
     * Les émojis sont un JEU FERMÉ. Sans énumération explicite, le registre dérive d'un
     * message à l'autre et le même tableau se lit différemment dans le chat, dans un
     * e-mail et dans un export PDF.
     */
    public function testPromptEnumereUnJeuFermeDEmojis(): void
    {
        static::bootKernel();
        $builder = static::getContainer()->get(AiContextBuilder::class);

        $request = new AiRequest(
            systemContext: [
                'assistantNom'   => 'Ket',
                'entrepriseNom'  => 'PHPUnit Émojis SARL',
                'perimetre'      => [],
                'date'           => '2026-08-11',
                'objetsAttaches' => [],
            ],
            messages: [],
            scope: new AiScope(new Entreprise(), new Invite()),
        );

        $prompt = $builder->toSystemPrompt($request);

        $this->assertStringContainsString('ÉMOJIS', $prompt);
        foreach (['📅', '💰', '📄', '👤', '📊', '⚠', '✅', '📌'] as $emoji) {
            $this->assertStringContainsString($emoji, $prompt, sprintf('L’émoji %s doit être énuméré.', $emoji));
        }
        $this->assertStringContainsString('Aucun autre', $prompt);
        $this->assertStringContainsString('Jamais dans une cellule', $prompt);
    }

    public function testPromptEnseigneLaSyntaxeGraphique(): void
    {
        static::bootKernel();
        $builder = static::getContainer()->get(AiContextBuilder::class);

        $request = new AiRequest(
            systemContext: [
                'assistantNom'   => 'Ket',
                'entrepriseNom'  => 'PHPUnit Graphique SARL',
                'perimetre'      => [],
                'date'           => '2026-07-28',
                'objetsAttaches' => [],
            ],
            messages: [],
            scope: new AiScope(new Entreprise(), new Invite()),
        );

        $prompt = $builder->toSystemPrompt($request);

        // Capacité graphique : syntaxe du bloc balisé + champ légende obligatoire.
        $this->assertStringContainsString('GRAPHIQUES', $prompt);
        $this->assertStringContainsString('```chart', $prompt);
        $this->assertStringContainsString('"legende"', $prompt);
        $this->assertStringContainsString('OBLIGATOIRE', $prompt);
        // Le graphique complète, ne remplace pas — et ne s'invente pas de chiffres.
        $this->assertStringContainsString('COMPLÈTE', $prompt);
        $this->assertStringContainsString("n'invente jamais de chiffre", $prompt);
    }

    public function testPromptPorteLeGlossaireFinancierEtLaConcision(): void
    {
        static::bootKernel();
        $builder = static::getContainer()->get(AiContextBuilder::class);

        $request = new AiRequest(
            systemContext: [
                'assistantNom'   => 'Ket',
                'entrepriseNom'  => 'PHPUnit Glossaire SARL',
                'perimetre'      => [],
                'date'           => '2026-07-25',
                'objetsAttaches' => [],
            ],
            messages: [],
            scope: new AiScope(new Entreprise(), new Invite()),
        );

        $prompt = $builder->toSystemPrompt($request);

        // Glossaire : CA = commissions encaissées, distinct de généré / production / prime.
        $this->assertStringContainsString('GLOSSAIRE FINANCIER', $prompt);
        $this->assertStringContainsString('commissions réellement ENCAISSÉES', $prompt);
        // Règle isBound : une proposition non validée ne compte aucune prime/commission.
        $this->assertStringContainsString('RÈGLE isBound', $prompt);
        $this->assertStringContainsString('SANS avenant', $prompt);
        $this->assertStringContainsString('chiffre_affaires_mensuel', $prompt);
        // Concision.
        $this->assertStringContainsString('CONCISION', $prompt);
    }

    public function testPromptSepareLesDeuxMondesDeTaxesEtInterditDinventerUnTaux(): void
    {
        static::bootKernel();
        $builder = static::getContainer()->get(AiContextBuilder::class);

        $request = new AiRequest(
            systemContext: [
                'assistantNom'   => 'Ket',
                'entrepriseNom'  => 'PHPUnit Taxes SARL',
                'perimetre'      => [],
                'date'           => '2026-07-28',
                'objetsAttaches' => [],
            ],
            messages: [],
            scope: new AiScope(new Entreprise(), new Invite()),
        );

        $prompt = $builder->toSystemPrompt($request);

        // Deux mondes de taxes distincts : prime vs commission.
        $this->assertStringContainsString('SUR LA PRIME', $prompt);
        $this->assertStringContainsString('SUR LA COMMISSION', $prompt);
        $this->assertStringContainsString('taxeAssureurMontant', $prompt);
        $this->assertStringContainsString('taxeCourtierMontant', $prompt);
        // Interdiction d'inventer un taux + où lire le vrai taux.
        $this->assertStringContainsString("N'INVENTE JAMAIS un taux de taxe", $prompt);
        $this->assertStringContainsString('lire_fiche(entite=Taxe)', $prompt);
    }

    public function testPromptPorteLaBoussoleEtSonEtat(): void
    {
        static::bootKernel();
        $builder = static::getContainer()->get(AiContextBuilder::class);

        $request = new AiRequest(
            systemContext: [
                'assistantNom'   => 'Ket',
                'entrepriseNom'  => 'PHPUnit Boussole SARL',
                'perimetre'      => [],
                'date'           => '2026-07-26',
                'objetsAttaches' => [],
                'boussole'       => [
                    'items' => [
                        ['axe' => 'saturation', 'libelle' => '4 client(s) sous 100 % de couverture', 'actionnable' => true, 'urgence' => 50],
                        ['axe' => 'fiscal', 'libelle' => 'TVA à jour', 'actionnable' => false, 'urgence' => 0],
                    ],
                    'prioritaire' => ['axe' => 'saturation', 'libelle' => '4 client(s) sous 100 % de couverture', 'urgence' => 50],
                ],
            ],
            messages: [],
            scope: new AiScope(new Entreprise(), new Invite()),
        );

        $prompt = $builder->toSystemPrompt($request);

        // Mission permanente + cadence + état dynamique injecté.
        $this->assertStringContainsString('TA BOUSSOLE', $prompt);
        $this->assertStringContainsString('RAPPEL À CHAQUE INTERACTION', $prompt);
        $this->assertStringContainsString('ÉTAT DE LA BOUSSOLE', $prompt);
        $this->assertStringContainsString('PRIORITÉ ACTUELLE', $prompt);
        $this->assertStringContainsString('4 client(s) sous 100 % de couverture', $prompt);
        // Routage vers le nouvel outil.
        $this->assertStringContainsString('saturation_portefeuille', $prompt);
        // Le programme du jour est déjà affiché par le serveur à l'ouverture d'une
        // conversation vide : sans ce garde-fou, Ket le redéroule à la 1re question
        // et l'utilisateur lit deux fois la même liste.
        $this->assertStringContainsString('DÉJÀ vu son programme du jour', $prompt);
        $this->assertStringContainsString('plan_du_jour', $prompt);
    }
}
