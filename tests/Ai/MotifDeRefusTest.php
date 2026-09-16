<?php

namespace App\Tests\Ai;

use App\Ai\Mutation\MotifDeRefus;
use App\Ai\Tool\AiToolResult;
use PHPUnit\Framework\TestCase;

/**
 * CE QUE LE COURTIER LIT QUAND UN OUTIL DE PLAN REFUSE.
 *
 * ── POURQUOI CE FICHIER EXISTE ──────────────────────────────────────────────
 * Un résultat d'outil porte DEUX textes, et le contrat est écrit noir sur blanc :
 * `note` est « CE QU'IL FAUT FAIRE, à l'intention du modèle » (AiToolResult:41),
 * `bloquant` est la phrase destinée à l'utilisateur (PlanBuilder:237-243).
 *
 * MotifDeRefus a longtemps retombé sur `note` faute de mieux. Le 2026-08-13, le
 * courtier a lu « reprends le nom exact et rappelle preparer_operations ». Une
 * branche `bloquant` a été ajoutée — pour UN site sur vingt-deux. Le 2026-08-12
 * puis le 2026-09-14, la même retombée lui a déversé dans sa bulle le CATALOGUE
 * COMPLET des outils de Ket, avec le nom et les arguments de chacun, persisté en
 * meta et donc réaffiché à chaque rechargement.
 *
 * La règle que ces tests rendent opposable est simple : `note` ne franchit JAMAIS
 * la frontière. Un refus que nous n'avons pas su rédiger pour l'utilisateur donne
 * une phrase neutre — jamais le brouillon adressé au modèle.
 */
class MotifDeRefusTest extends TestCase
{
    /**
     * LA FUITE ELLE-MÊME, dans sa forme exacte du 2026-09-14.
     *
     * Le texte ci-dessous est celui que `PreparerProgrammeTool` renvoie quand une
     * étape est inexploitable : il tutoie le modèle et lui récite le catalogue.
     * Rien de tout cela n'a de sens pour un courtier, et rien ne doit l'atteindre.
     */
    public function testUneNoteDestineeAuModeleNAtteintJamaisLUtilisateur(): void
    {
        $resultat = AiToolResult::ok([
            'pret' => false,
            'note' => 'L\'étape « Création de la fiche contact de Joëlle Fala Sona » est inexploitable pour '
                . 'preparer_operations : je n\'ai pas pu en dériver d\'arguments. Vérifie qu\'elle porte tout '
                . 'ce que cet outil attend — attacher_fichier : fichierId (requis), nom | signaler_paiement_prime : '
                . 'trancheId (requis), montant, paidAt. N\'affiche AUCUN plan : aucun bouton n\'apparaîtra.',
        ]);

        $motif = MotifDeRefus::depuis($resultat);

        // Le nom technique d'un outil, le nom d'un argument, le tutoiement impératif :
        // trois marqueurs de la langue interne. Aucun ne doit survivre au passage.
        self::assertStringNotContainsString('preparer_operations', $motif);
        self::assertStringNotContainsString('attacher_fichier', $motif);
        self::assertStringNotContainsString('fichierId', $motif);
        self::assertStringNotContainsString('Vérifie qu', $motif);
        self::assertStringNotContainsString('N\'affiche AUCUN plan', $motif);

        // Et il reste une phrase : un refus muet serait une autre façon de mentir.
        self::assertNotSame('', trim($motif));
    }

    /**
     * Le séparateur du catalogue, qui à lui seul trahit la fuite.
     *
     * `OutilsDeProgramme::aideParametres()` joint les signatures par « | ». Ce
     * caractère n'a rien à faire dans une phrase écrite pour un humain : le voir
     * dans un motif, c'est voir passer la liste des outils.
     */
    public function testLeCatalogueDesOutilsNeTraverseJamaisLaFrontiere(): void
    {
        $resultat = AiToolResult::ok([
            'pret' => false,
            'note' => 'souscrire_cotation : cotation, assureur, client, piste | signaler_paiement_prime : '
                . 'trancheId (requis), montant | preparer_demande_conge : periode, debut, fin, typeAbsence',
        ]);

        self::assertStringNotContainsString('|', MotifDeRefus::depuis($resultat));
    }

    /**
     * PLUSIEURS CANDIDATS : une question à un clic, pas une impasse.
     *
     * Cinq refus du catalogue portent `ambigu` et rien d'autre — deux homonymes,
     * deux polices concurrentes. `RepliPrecis` sait déjà les restituer ; MotifDeRefus
     * les ignorait et retombait sur la note, si bien que le courtier lisait
     * « Demande LEQUEL, en UNE ligne, puis ARRÊTE-TOI » — une consigne adressée à
     * quelqu'un d'autre que lui, et qui ne nomme même pas les candidats.
     */
    public function testUnRefusAmbiguNommeLesCandidatsPlutotQueDeReciterLaConsigne(): void
    {
        $resultat = AiToolResult::ok([
            'pret'   => false,
            'ambigu' => ['Joëlle Fama Sona', 'Joëlle Fala Sona'],
            'note'   => 'Plusieurs collaborateurs portent ce nom. Demande LEQUEL, en UNE ligne, puis '
                . 'ARRÊTE-TOI : tu me rappelleras au message SUIVANT.',
        ]);

        $motif = MotifDeRefus::depuis($resultat);

        self::assertStringContainsString('Joëlle Fama Sona', $motif);
        self::assertStringContainsString('Joëlle Fala Sona', $motif);
        self::assertStringNotContainsString('ARRÊTE-TOI', $motif);
        self::assertStringNotContainsString('rappelleras', $motif);
    }

    /**
     * Les candidats arrivent sous DEUX formes selon l'outil : une liste de noms
     * (CongesTool) ou une liste de résumés structurés (PreparerMouvementAvenantTool,
     * via `resumer()`). Les deux doivent se lire.
     */
    public function testUnCandidatStructureEstLisibleAussi(): void
    {
        $resultat = AiToolResult::ok([
            'pret'   => false,
            'ambigu' => [
                ['police' => 'AXA-2026-118', 'client' => 'Ngoy Mbayo'],
                ['police' => 'AXA-2026-204', 'client' => 'Ngoy Mbayo'],
            ],
            'note'   => 'Demande LAQUELLE puis ARRÊTE-TOI.',
        ]);

        $motif = MotifDeRefus::depuis($resultat);

        self::assertStringContainsString('AXA-2026-118', $motif);
        self::assertStringContainsString('AXA-2026-204', $motif);
        self::assertStringNotContainsString('ARRÊTE-TOI', $motif);
    }

    /**
     * NON-RÉGRESSION : la branche qui fonctionnait doit continuer. `bloquant` est la
     * phrase de l'utilisateur, elle passe avant tout le reste et s'affiche telle quelle.
     */
    public function testLaPhraseEcritePourLUtilisateurPasseTelleQuelle(): void
    {
        $resultat = AiToolResult::ok([
            'pret'     => false,
            'bloquant' => 'Je n’ai pas pu enregistrer cette modification : le champ visé n’existe pas sous ce '
                . 'nom sur « Risques ». Rien n’a été écrit.',
            'note'     => 'Reprends le nom exact et rappelle preparer_operations.',
        ]);

        $motif = MotifDeRefus::depuis($resultat);

        self::assertStringContainsString('Rien n’a été écrit', $motif);
        self::assertStringNotContainsString('preparer_operations', $motif);
    }

    /**
     * NON-RÉGRESSION : les autres branches destinées à l'utilisateur gardent la main.
     */
    public function testLesBranchesUtilisateurGardentLaPriorite(): void
    {
        $manquants = MotifDeRefus::depuis(AiToolResult::ok([
            'pret'      => false,
            'manquants' => ['Téléphone'],
            'note'      => 'Rappelle preparer_operations avec le champ manquant.',
        ]));
        self::assertStringContainsString('Téléphone', $manquants);
        self::assertStringNotContainsString('preparer_operations', $manquants);

        $attente = MotifDeRefus::depuis(AiToolResult::ok([
            'pret'          => false,
            'planEnAttente' => true,
            'note'          => 'REFUSÉ : rappelle-moi avec remplacerPlanEnAttente=true.',
        ]));
        self::assertStringNotContainsString('remplacerPlanEnAttente', $attente);
    }
}
