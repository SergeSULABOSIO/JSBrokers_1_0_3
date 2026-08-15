<?php

namespace App\Tests\Ai;

use App\Ai\Fichier\FichierNieAtort;
use PHPUnit\Framework\TestCase;

/**
 * LE DÉMENTI DE PIÈCE JOINTE — la ceinture du correctif de prompt.
 *
 * La cause première (une phase de rédaction aveugle aux fichiers) est corrigée dans
 * AiContextBuilder et verrouillée par PromptFichiersToutesPhasesTest. Ce garde-fou-ci
 * existe parce que le contenu d'une bulle reste écrit par un modèle : aucune consigne,
 * si ferme soit-elle, ne le contraint absolument. Le serveur, lui, SAIT ce qui est
 * attaché.
 *
 * DEUX EXIGENCES CONTRAIRES, et l'équilibre est tout le sujet :
 *  - il doit attraper les formulations RÉELLES de l'incident, mot pour mot ;
 *  - il ne doit JAMAIS démentir une réponse honnête sur un fichier bien reçu
 *    (« je n'ai pas pu en extraire le texte », « votre PDF est scanné »), sans quoi on
 *    ajouterait une confusion à une réponse juste.
 */
class FichierNieAtortTest extends TestCase
{
    /** @var list<array{id:int, nom:string}> */
    private const FICHIERS = [
        ['id' => 18, 'nom' => 'CONTRAT-ORANGE-RDC.pdf'],
    ];

    /**
     * Les DEUX phrases de l'incident du 2026-08-15, recopiées de la capture. Si ce
     * test venait à tomber, c'est ce cas précis qui reviendrait chez l'utilisateur.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function dementis(): iterable
    {
        yield 'incident, message 1' => [
            "Votre fichier n'apparaît toujours pas dans le fil de notre conversation. "
            . "Pour l'associer au dossier d'Orange RDC SA, je vous invite à utiliser l'option de "
            . "pièce jointe de votre interface pour le téléverser.",
        ];
        yield 'incident, message 2' => [
            "Aucun fichier n'a été transmis ou reçu dans nos derniers échanges. Pour que je puisse "
            . "l'ajouter à l'avenant MIC2026-001245787454-2026, je vous invite à téléverser le document.",
        ];
        yield 'apostrophe typographique' => ["Aucun fichier n’a été transmis."];
        yield 'sans accent ni casse'     => ['AUCUN FICHIER NA ETE TRANSMIS'];
        yield 'réclamation d’envoi'      => ['Merci de téléverser le document pour que je puisse le classer.'];
        yield 'négation de réception'    => ["Je n'ai reçu aucun document dans cette conversation."];
    }

    /**
     * @dataProvider dementis
     */
    public function testUnDementiDeclencheLaMiseAuPoint(string $prose): void
    {
        $mise = FichierNieAtort::detecter($prose, self::FICHIERS);

        $this->assertNotNull($mise, 'Ce démenti doit être rattrapé par le serveur.');
        $this->assertSame([['id' => 18, 'nom' => 'CONTRAT-ORANGE-RDC.pdf']], $mise['fichiers']);
    }

    /**
     * Les réponses HONNÊTES sur un fichier bien reçu. Les démentir serait pire que le
     * défaut d'origine : on contredirait Ket alors qu'elle a raison.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function reponsesHonnetes(): iterable
    {
        yield 'extraction impossible' => [
            "Votre PDF est scanné : je n'ai pas pu en extraire le texte. Voulez-vous que je le classe tel quel ?",
        ];
        yield 'classement proposé' => [
            "J'ai bien votre fichier CONTRAT-ORANGE-RDC.pdf. À quel enregistrement voulez-vous l'attacher ?",
        ];
        yield 'plan présenté' => [
            "Le fichier sera enregistré comme document de l'avenant MIC2026-001. Validez pour l'attacher.",
        ];
        yield 'donnée absente du fichier' => [
            "Le document ne mentionne aucun montant de prime : pouvez-vous me le donner ?",
        ];
        yield 'aucun résultat de recherche' => [
            "Aucun client de ce nom dans votre portefeuille.",
        ];
    }

    /**
     * @dataProvider reponsesHonnetes
     */
    public function testUneReponseHonneteNEstJamaisDementie(string $prose): void
    {
        $this->assertNull(FichierNieAtort::detecter($prose, self::FICHIERS));
    }

    /**
     * SANS PIÈCE JOINTE, le démenti est la VÉRITÉ — et c'est le cas le plus fréquent.
     * Le garde-fou ne se déclenche que sur une contradiction avec un fait du serveur.
     */
    public function testSansPieceJointeAucuneMiseAuPoint(): void
    {
        $this->assertNull(FichierNieAtort::detecter("Aucun fichier n'a été transmis.", []));
    }

    /** Une réponse vide n'affirme rien : rien à démentir. */
    public function testUneProseVideNeDeclencheRien(): void
    {
        $this->assertNull(FichierNieAtort::detecter('   ', self::FICHIERS));
    }

    /**
     * La mise au point NOMME les pièces : « vous avez bien un fichier » sans dire
     * lequel ne permet pas à l'utilisateur de reformuler sa demande.
     */
    public function testLaMiseAuPointNommeToutesLesPieces(): void
    {
        $mise = FichierNieAtort::detecter("Aucun fichier n'a été transmis.", [
            ['id' => 18, 'nom' => 'CONTRAT.pdf'],
            ['id' => 19, 'nom' => 'ATTESTATION.pdf'],
        ]);

        $this->assertNotNull($mise);
        $this->assertCount(2, $mise['fichiers']);
        $this->assertSame('ATTESTATION.pdf', $mise['fichiers'][1]['nom']);
    }

    /**
     * Une pièce sans identifiant n'existe pas encore pour le modèle (upload en cours) :
     * l'annoncer comme présente serait affirmer à notre tour quelque chose de faux.
     */
    public function testUnePieceSansIdentifiantNeComptePas(): void
    {
        $this->assertNull(FichierNieAtort::detecter(
            "Aucun fichier n'a été transmis.",
            [['id' => 0, 'nom' => 'en-cours.pdf']],
        ));
    }
}
