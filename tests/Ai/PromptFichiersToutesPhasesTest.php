<?php

namespace App\Tests\Ai;

use App\Ai\AiContextBuilder;
use App\Ai\Trousse\Phase;
use App\Ai\Trousse\Trousse;
use App\Entity\AssistantConversation;
use App\Entity\AssistantConversationFichier;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Repository\EntrepriseRepository;
use App\Repository\InviteRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * AUCUNE PHASE NE DOIT IGNORER UNE PIÈCE JOINTE PRÉSENTE.
 *
 * L'INCIDENT (2026-08-15). L'utilisateur envoie « ajoute le dans l'avenant » avec un
 * fichier attaché — l'agrafe est affichée sous sa propre bulle — et Ket répond deux
 * fois de suite « aucun fichier n'a été transmis, je vous invite à téléverser le
 * document ». Autrement dit : refaites ce que vous venez de faire.
 *
 * LA CAUSE ÉTAIT UNE ASYMÉTRIE, pas une consigne manquante. Le moteur travaille en
 * trois phases — comprendre, planifier, rédiger — et chacune reçoit SON prompt. La
 * compréhension nommait les pièces jointes, la planification recevait la section
 * complète avec les extraits… et la rédaction, celle qui écrit la bulle que
 * l'utilisateur LIT, ne recevait rien. Quand la planification ne rapportait rien au
 * sujet du fichier, le rédacteur ne voyait qu'un fil sans la moindre mention de pièce
 * et concluait de bonne foi qu'il n'y en avait pas.
 *
 * Une consigne de plus n'aurait rien réglé : le modèle ne désobéissait pas, il ne
 * savait pas. Ce test verrouille le fait, phase par phase.
 *
 * ET LE CACHE DE PRÉFIXE RESTE INTACT : sans pièce jointe, les prompts doivent être
 * STRICTEMENT identiques à ce qu'ils étaient. Le fournisseur met en cache le préfixe
 * (75 % de hit observés) ; une ligne qui apparaîtrait même à vide le ferait manquer à
 * chaque tour, pour une information qui n'existe pas.
 */
class PromptFichiersToutesPhasesTest extends KernelTestCase
{
    private const NOM_FICHIER = 'CONTRAT-KIN-AVIA-2026.pdf';

    /**
     * Les trois phases réellement envoyées au fournisseur, dans leur trousse propre.
     *
     * @return iterable<string, array{0: Trousse, 1: ?Phase}>
     */
    public static function phases(): iterable
    {
        yield 'compréhension' => [Trousse::COMPREHENSION, Phase::COMPREHENSION];
        yield 'planification' => [Trousse::ECRITURE, null];
        yield 'rédaction'     => [Trousse::ECRITURE, Phase::REDACTION];
    }

    /**
     * @dataProvider phases
     */
    public function testChaquePhaseSaitQuUnFichierEstAttache(Trousse $trousse, ?Phase $phase): void
    {
        $prompt = $this->prompt($trousse, $phase, avecFichier: true);

        $this->assertStringContainsString(
            self::NOM_FICHIER,
            $prompt,
            'Cette phase rédige sans savoir qu’une pièce jointe existe : c’est ainsi que Ket '
            . 'a nié un fichier que l’utilisateur voyait affiché sous sa propre bulle.',
        );
    }

    /**
     * La phase de RÉDACTION est celle qui écrit la bulle lue par l'utilisateur : c'est
     * donc la seule où l'interdiction du démenti doit être écrite noir sur blanc.
     */
    public function testLaRedactionSeVoitInterdireDeNierLeFichier(): void
    {
        $prompt = $this->prompt(Trousse::ECRITURE, Phase::REDACTION, avecFichier: true);

        $this->assertStringContainsString('PIÈCES JOINTES RÉELLEMENT PRÉSENTES', $prompt);
        $this->assertStringContainsString('aucun fichier n', $prompt, 'La formule exacte du démenti doit être proscrite.');
        $this->assertStringContainsString('téléverser', $prompt, 'Réclamer un téléversement est proscrit au même titre.');
    }

    /**
     * La rédaction n'a pas à connaître le CONTENU des fichiers — elle n'en a pas
     * l'usage, et l'extrait peut peser vingt mille caractères par pièce. Elle doit
     * savoir qu'ils existent, rien de plus. Seule la planification les lit.
     */
    public function testLaRedactionNeTransportePasLesExtraits(): void
    {
        $prompt = $this->prompt(Trousse::ECRITURE, Phase::REDACTION, avecFichier: true);

        $this->assertStringNotContainsString('Contenu extrait', $prompt);
        $this->assertStringNotContainsString('texte du contrat de test', $prompt);
    }

    /**
     * @dataProvider phases
     */
    public function testSansPieceJointeLePromptEstInchange(Trousse $trousse, ?Phase $phase): void
    {
        $prompt = $this->prompt($trousse, $phase, avecFichier: false);

        $this->assertStringNotContainsString(
            'PIÈCES JOINTES',
            $prompt,
            'Une conversation sans pièce jointe doit produire exactement le prompt d’avant : '
            . 'le préfixe est mis en cache chez le fournisseur, et une ligne à vide le ferait manquer.',
        );
    }

    /** Le prompt réellement envoyé pour cette trousse et cette phase. */
    private function prompt(Trousse $trousse, ?Phase $phase, bool $avecFichier): string
    {
        static::bootKernel();
        $conteneur = static::getContainer();

        $entreprise = $conteneur->get(EntrepriseRepository::class)->findOneBy([]);
        self::assertInstanceOf(Entreprise::class, $entreprise, 'Le jeu de test doit comporter au moins une entreprise.');
        $invite = $conteneur->get(InviteRepository::class)->findOneBy(['entreprise' => $entreprise]);
        self::assertInstanceOf(Invite::class, $invite, 'Le jeu de test doit comporter au moins un invité.');

        $conversation = new AssistantConversation();
        if ($avecFichier) {
            $conversation->addFichier($this->fichier());
        }

        $requete = $conteneur->get(AiContextBuilder::class)->build($entreprise, $invite, $conversation);

        return $conteneur->get(AiContextBuilder::class)->toSystemPrompt($requete, $trousse, $phase);
    }

    /**
     * Une pièce jointe non persistée, mais PORTEUSE D'UN IDENTIFIANT : AiContextBuilder
     * écarte les fichiers sans id (une pièce en cours d'upload n'existe pas encore pour
     * le modèle). On le pose par réflexion plutôt que d'écrire en base — ce test porte
     * sur la construction du prompt, pas sur la persistance.
     */
    private function fichier(): AssistantConversationFichier
    {
        $fichier = (new AssistantConversationFichier())
            ->setNomOriginal(self::NOM_FICHIER)
            ->setMimeType('application/pdf')
            ->setTaille(120_000)
            ->setTexteExtrait('texte du contrat de test');

        $id = new \ReflectionProperty(AssistantConversationFichier::class, 'id');
        $id->setAccessible(true);
        $id->setValue($fichier, 18);

        return $fichier;
    }
}
