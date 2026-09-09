<?php

namespace App\Tests\Ai;

use App\Ai\Action\TypeAction;
use App\Ai\Mutation\PlanEnAttente;
use PHPUnit\Framework\TestCase;

/**
 * LE CONTRAT ENTRE LE SERVEUR ET LE NAVIGATEUR, vérifié dans les deux sens.
 *
 * Ket ne fait pas qu'écrire du texte : elle demande au navigateur d'ouvrir un
 * formulaire, de naviguer, de proposer un téléchargement, d'afficher une barre de
 * validation. Ces demandes voyagent en JSON, et rien ne garantissait qu'elles soient
 * comprises — le `switch` du chat n'avait pas de `default`, donc un type inconnu était
 * **ignoré en silence** : l'utilisateur attendait un bouton qui n'arriverait jamais, et
 * aucune trace n'en subsistait.
 *
 * Ce n'était pas une crainte théorique. Au 2026-08-10, le chat traitait encore
 * « signaler-paiement-prime » alors qu'aucun code serveur ne l'émettait plus. Une
 * dérive réelle, invisible, et que personne n'aurait vue sans ce test.
 *
 * C'est la même famille de garde-fou que PromptSansOutilFantomeTest : une promesse
 * faite à l'utilisateur doit être garantie par le code, pas par la vigilance.
 */
class ContratDesActionsTest extends TestCase
{
    private const CHAT = __DIR__ . '/../../assets/controllers/assistant-chat_controller.js';

    /**
     * Les types réellement traités par le navigateur, lus dans le `switch` de
     * `executeActions`.
     *
     * @return list<string>
     */
    private function typesTraitesParLeNavigateur(): array
    {
        $module = (string) file_get_contents(self::CHAT);

        $debut = strpos($module, 'async executeActions(actions)');
        self::assertNotFalse($debut, 'executeActions introuvable : le contrat ne peut plus être vérifié.');

        // Le `switch` s'arrête au `default` — au-delà commencent d'autres méthodes.
        $fin = strpos($module, 'default:', $debut);
        self::assertNotFalse($fin, 'Le switch des actions doit garder son `default` : sans lui, un type '
            . 'inconnu redeviendrait un silence.');

        preg_match_all("/case '([^']+)'/", substr($module, $debut, $fin - $debut), $trouves);

        return array_values(array_unique($trouves[1]));
    }

    /**
     * Tout type déclaré côté serveur doit être traité par le navigateur.
     *
     * Un manque ici signifie qu'un outil peut émettre une action que personne
     * n'exécutera : l'utilisateur ne verra rien se produire.
     */
    public function testChaqueTypeDeclareEstTraiteParLeNavigateur(): void
    {
        $orphelins = array_diff(TypeAction::valeurs(), $this->typesTraitesParLeNavigateur());

        self::assertSame([], array_values($orphelins), sprintf(
            "Ces types sont déclarés dans TypeAction mais AUCUN `case` du chat ne les traite : %s.\n"
            . "Ajoute le `case` correspondant dans executeActions (assistant-chat_controller.js), "
            . "ou retire le type du registre s'il n'a plus lieu d'être.",
            implode(', ', $orphelins),
        ));
    }

    /**
     * Et réciproquement : tout `case` du navigateur doit correspondre à un type
     * déclaré. Un `case` sans émetteur est du code mort qui donne l'illusion d'une
     * capacité — c'est exactement ce qu'était « signaler-paiement-prime ».
     */
    public function testChaqueCasDuNavigateurCorrespondAUnTypeDeclare(): void
    {
        $inconnus = array_diff($this->typesTraitesParLeNavigateur(), TypeAction::valeurs());

        self::assertSame([], array_values($inconnus), sprintf(
            "Le chat traite des types qu'aucun code serveur ne déclare : %s.\n"
            . "Déclare-les dans App\\Ai\\Action\\TypeAction s'ils sont légitimes, sinon retire le "
            . "`case` — un `case` sans émetteur est du code mort qui simule une capacité.",
            implode(', ', $inconnus),
        ));
    }

    /**
     * LES ACTIONS QUI EXIGENT LES COLONNES SONT REFUSÉES, ET SEULEMENT ELLES.
     *
     * Sur téléphone et tablette, la conversation est la seule surface : trois actions
     * (ouvrir une rubrique, fermer un onglet, poser une fiche) n'ont rien à viser. Le
     * chat doit donc les refuser AVANT d'émettre leur événement, et le dire.
     *
     * ── CE QUI ARRIVERAIT SANS CE TEST ─────────────────────────────────────────────
     * Deux dérives symétriques, toutes deux muettes :
     *  - un refus OUBLIÉ sur l'une des trois : l'événement partirait vers un
     *    `workspace-manager` absent, Ket annoncerait « j'ouvre la liste » et rien
     *    n'arriverait — le défaut exact que ce dispositif existe pour empêcher ;
     *  - un refus AJOUTÉ sur une action qui n'en a pas besoin : `open-dialog`, le
     *    picker de SOA, les téléchargements et les panneaux `ket-*` reposent sur
     *    `cerveau` / `dialog-manager`, qui vivent sur le `<body>` et fonctionnent
     *    partout. Refuser `open-dialog` sur mobile emporterait TOUTE l'écriture
     *    métier en ambulatoire, c'est-à-dire la raison d'être du mode Ket.
     */
    public function testSeulesLesActionsAColonnesSontRefuseesParLeChat(): void
    {
        $module = (string) file_get_contents(self::CHAT);

        $debut = strpos($module, 'async executeActions(actions)');
        self::assertNotFalse($debut);
        $fin = strpos($module, 'default:', $debut);
        self::assertNotFalse($fin);
        $corpsDuSwitch = substr($module, $debut, $fin - $debut);

        // Le refus est écrit une fois, dans une méthode nommée : on repère donc les
        // `case` qui l'appellent, sans dépendre de la formulation du message.
        preg_match_all(
            "/case '([^']+)':(?:(?!case ').)*?_sansColonnes\(/s",
            $corpsDuSwitch,
            $trouves,
        );
        $refuses = array_values(array_unique($trouves[1]));
        sort($refuses);

        $attendus = TypeAction::valeursExigeantLesColonnes();
        sort($attendus);

        self::assertSame($attendus, $refuses, sprintf(
            "Le chat doit refuser exactement les actions qui exigent l'interface à colonnes.
"
            . "Attendu (TypeAction::exigeLesColonnes) : %s
Refusé par le chat : %s
"
            . "Un manque = un événement émis dans le vide sur téléphone ; un excès = une "
            . "capacité retirée sans raison (open-dialog fonctionne partout).",
            implode(', ', $attendus),
            implode(', ', $refuses),
        ));
    }

    /**
     * LE CHAT SAIT DE QUEL APPAREIL IL PARLE.
     *
     * Le refus ci-dessus se décide sur une valeur Stimulus, que le gabarit du chat
     * doit poser depuis le serveur. Sans elle, `_sansColonnes` répondrait toujours
     * « non » et le mode Ket perdrait son filet — silencieusement, puisque le refus
     * est justement un chemin qu'on ne prend jamais en régime normal.
     */
    public function testLeGabaritDuChatPublieLeTerminal(): void
    {
        $gabarit = (string) file_get_contents(__DIR__ . '/../../templates/components/_assistant_ia_chat.html.twig');
        self::assertStringContainsString(
            'data-assistant-chat-terminal-value="{{ terminal_courant() }}"',
            $gabarit,
            'Le chat doit recevoir le terminal du serveur, jamais le deviner.',
        );

        $module = (string) file_get_contents(self::CHAT);
        self::assertStringContainsString(
            'terminal: String,',
            $module,
            'La valeur Stimulus doit être déclarée, sinon `terminalValue` lèvera.',
        );
    }

    /**
     * Les constantes historiques du plan ne doivent pas redevenir une seconde vérité :
     * elles sont lues dans le contrôleur, la meta des messages et de nombreux tests.
     */
    public function testLesConstantesDuPlanPointentVersLeRegistre(): void
    {
        self::assertSame(TypeAction::PLAN_A_VALIDER->value, PlanEnAttente::ACTION_REVUE);
        self::assertSame(TypeAction::PLAN_ABSENT->value, PlanEnAttente::ACTION_ABSENT);
        self::assertSame(TypeAction::EXECUTION_ABSENTE->value, PlanEnAttente::ACTION_NON_EXECUTE);
    }

    /**
     * Les deux démentis sont DISTINCTS, et doivent le rester : « aucun plan n'a pu
     * être préparé » et « rien n'a été enregistré » ne disent pas la même chose à
     * l'utilisateur, et n'ont pas la même gravité. Les confondre reviendrait à
     * répondre « il n'y a pas de bouton » à quelqu'un qui croit son dossier écrit.
     */
    public function testLesDeuxDementisRestentDistincts(): void
    {
        self::assertNotSame(TypeAction::PLAN_ABSENT->value, TypeAction::EXECUTION_ABSENTE->value);
    }

    /**
     * Les champs requis sont ceux SANS LESQUELS le handler du navigateur sort par une
     * garde silencieuse. Les vérifier au hasard ne servirait à rien : on s'assure ici
     * qu'ils sont bien déclarés là où le front en dépend vraiment.
     */
    public function testLesChampsRequisCouvrentCeDontLeNavigateurADEvidentBesoin(): void
    {
        self::assertSame(['url'], TypeAction::OUVRIR_URL->champsRequis());
        self::assertSame(['clientId'], TypeAction::OUVRIR_ENVOI_SOA->champsRequis());
        self::assertSame(['fichiers'], TypeAction::TELECHARGER_FICHIERS->champsRequis());
        self::assertSame(['entite', 'id'], TypeAction::VISUALISER_FICHE->champsRequis());
        self::assertSame(['idMessage', 'destinataires'], TypeAction::ENVOYER_MESSAGE_DIRECT->champsRequis());

        // Le plan, lui, est vérifié bien plus finement par PlanEnAttente::planStockable :
        // le dupliquer ici créerait deux vérités.
        self::assertSame([], TypeAction::PLAN_A_VALIDER->champsRequis());
    }
}
