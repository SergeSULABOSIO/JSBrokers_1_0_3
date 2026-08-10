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
     * Les constantes historiques du plan ne doivent pas redevenir une seconde vérité :
     * elles sont lues dans le contrôleur, la meta des messages et de nombreux tests.
     */
    public function testLesConstantesDuPlanPointentVersLeRegistre(): void
    {
        self::assertSame(TypeAction::PLAN_A_VALIDER->value, PlanEnAttente::ACTION_REVUE);
        self::assertSame(TypeAction::PLAN_ABSENT->value, PlanEnAttente::ACTION_ABSENT);
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
