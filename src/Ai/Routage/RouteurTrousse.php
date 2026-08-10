<?php

namespace App\Ai\Routage;

use App\Ai\AiRequest;
use App\Ai\Mutation\PlanEnAttente;
use App\Ai\Programme\ProgrammeEnCours;
use App\Ai\Trousse\Trousse;
use Psr\Log\LoggerInterface;

/**
 * Choisit la TROUSSE d'un message : quels outils déclarer, et quelle part du prompt
 * système envoyer.
 *
 * POURQUOI UN APPEL DE MODÈLE, ET NON UNE RÈGLE. La question a été tranchée par la
 * mesure, pas par goût. Quatre règles lexicales déterministes ont été rejouées hors
 * ligne sur les 31 messages journalisés des 8-9 août : la meilleure atteint 58 %
 * d'exactitude, contre 48 % pour « tout déclarer toujours ». L'écart est du bruit.
 * La raison est visible dans les échecs — « Vas y. Essaie encore », « Jusqu'à 5.
 * Donne moi le plan », « Oui, prépare la nouvelle piste » : l'intention vit dans le
 * FIL, pas dans les mots de la bulle. Un classifieur par mots-clés y est
 * structurellement aveugle ; comprendre, c'est précisément ce qu'un modèle sait faire.
 *
 * MAIS ON NE PAIE PAS POUR CE QUI EST CERTAIN. Deux situations se décident sans
 * appeler personne, et elles sont fréquentes : un plan qui attend une décision, ou un
 * programme en cours. Dans les deux cas la suite est forcément une écriture — router
 * y serait une dépense pure.
 *
 * FAIL-OPEN, TOUJOURS. Panne, indécision, quota : on retombe sur la trousse COMPLÈTE.
 * Se tromper vers l'écriture coûte un tour lourd ; se tromper vers la lecture
 * priverait l'utilisateur d'une capacité. Le second prix est bien plus élevé.
 */
class RouteurTrousse
{
    /**
     * Nombre de messages de fin d'historique soumis au routeur.
     *
     * Trois, parce que c'est ce qu'il faut pour lire une continuation (« vas y »,
     * « essaie encore ») — et pas davantage : le routeur doit rester le tour le
     * moins cher de la chaîne.
     */
    private const MESSAGES_DE_CONTEXTE = 3;

    private const INSTRUCTION = <<<'TXT'
        Tu es un AIGUILLEUR. Tu ne réponds JAMAIS à la demande de l'utilisateur : tu choisis
        seulement quels outils devront être mis à la disposition de l'assistant pour y répondre.

        Réponds « ecriture » si la demande — ou la suite évidente de la conversation — suppose de
        CRÉER, MODIFIER, SUPPRIMER ou ENREGISTRER quoi que ce soit dans les données du courtier,
        d'ouvrir un formulaire de saisie, de préparer un plan, ou de poursuivre une saisie déjà
        engagée. Réponds « ecriture » aussi lorsque le dernier message est une simple continuation
        (« vas-y », « essaie encore », « oui », « jusqu'à 5 », « fais pareil ») ET que ce qui
        précède portait sur une saisie : l'intention est dans le fil, pas dans les mots.

        Réponds « lecture » quand la demande se satisfait de consulter, compter, analyser,
        expliquer, ventiler, exporter ou afficher — sans rien inscrire en base.

        Dans le doute, réponds « ecriture ».
        TXT;

    public function __construct(
        private readonly RouteurModele $modele,
        private readonly CatalogueCondense $catalogue,
        private readonly PlanEnAttente $planEnAttente,
        private readonly ProgrammeEnCours $programmeEnCours,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{trousse: Trousse, origine: string, tokens: int, millisecondes: int}
     *         origine : « plan_en_attente », « programme », « modele », « repli »
     */
    public function router(AiRequest $requete): array
    {
        $conversation = $requete->scope->conversation;

        // Court-circuits : la suite est certaine, la payer serait absurde.
        if (PlanEnAttente::aUnPlanEnAttente($conversation)) {
            return $this->decision(Trousse::ECRITURE, 'plan_en_attente', 0, 0);
        }
        if ($this->programmeEnCours->courant($conversation) !== null) {
            return $this->decision(Trousse::ECRITURE, 'programme', 0, 0);
        }

        $debut = hrtime(true);
        $resultat = $this->modele->choisirTrousse(
            self::INSTRUCTION,
            $this->catalogue->texte(),
            array_slice($requete->messages, -self::MESSAGES_DE_CONTEXTE),
        );
        $millisecondes = (int) round((hrtime(true) - $debut) / 1_000_000);

        $choisie = Trousse::tryFrom((string) $resultat['trousse']);
        if ($choisie === null) {
            $this->logger->info('Routage indécis : trousse complète par défaut.');

            return $this->decision(Trousse::ECRITURE, 'repli', (int) $resultat['tokens'], $millisecondes);
        }

        return $this->decision($choisie, 'modele', (int) $resultat['tokens'], $millisecondes);
    }

    /** @return array{trousse: Trousse, origine: string, tokens: int, millisecondes: int} */
    private function decision(Trousse $trousse, string $origine, int $tokens, int $millisecondes): array
    {
        return [
            'trousse'       => $trousse,
            'origine'       => $origine,
            'tokens'        => $tokens,
            'millisecondes' => $millisecondes,
        ];
    }
}
