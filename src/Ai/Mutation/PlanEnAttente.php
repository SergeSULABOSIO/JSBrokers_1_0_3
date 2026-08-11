<?php

namespace App\Ai\Mutation;

use App\Ai\Action\TypeAction;

use App\Entity\AssistantConversation;
use App\Entity\AssistantMessage;
use Doctrine\ORM\EntityManagerInterface;

/**
 * SOURCE UNIQUE de l'état d'un plan d'écriture présenté par Ket : en attente de
 * décision, validé/exécuté, ou annulé. L'état vit dans la meta du message
 * assistant qui a présenté le plan ; jusqu'ici la même règle était réécrite
 * à trois endroits (prompt système, barre de décision, endpoints d'exécution).
 *
 * Sert surtout de VERROU : tant qu'un plan attend une décision, Ket ne peut pas
 * en préparer un second (elle imposerait à l'utilisateur d'empiler des
 * validations). L'unique échappatoire est de REMPLACER le plan en attente —
 * ce qui l'annule d'abord : il n'y a jamais plus d'un plan en attente à la fois.
 */
final class PlanEnAttente
{
    /**
     * Directive UI qui fait apparaître la barre de décision d'un plan.
     *
     * La valeur vient du registre {@see TypeAction}, seule source des types d'action :
     * ces constantes restent parce qu'elles sont lues partout (contrôleur, meta des
     * messages, tests), mais elles n'inventent plus rien.
     */
    public const ACTION_REVUE = TypeAction::PLAN_A_VALIDER->value;

    /**
     * Directive UI AUTORITAIRE : le message décrit un plan / un bouton de
     * validation, mais AUCUN plan n'a réellement été préparé (le modèle a
     * halluciné la surface de décision sans appeler l'outil). On le dit
     * clairement à l'utilisateur — jamais de bouton fantôme, jamais d'attente
     * d'une décision qui ne viendra pas.
     */
    public const ACTION_ABSENT = TypeAction::PLAN_ABSENT->value;

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** @param array<string, mixed> $meta */
    public static function porteUnPlan(array $meta): bool
    {
        return ($meta['mutationPlan'] ?? null) !== null;
    }

    /** @param array<string, mixed> $meta */
    public static function estExecute(array $meta): bool
    {
        return self::porteUnPlan($meta) && ($meta['mutationPlanExecuted'] ?? false) === true;
    }

    /** @param array<string, mixed> $meta */
    public static function estAnnule(array $meta): bool
    {
        return self::porteUnPlan($meta) && ($meta['mutationPlanCancelled'] ?? false) === true;
    }

    /** Plan présenté dont l'utilisateur n'a encore ni validé ni annulé l'exécution. */
    public static function estEnAttente(array $meta): bool
    {
        return self::porteUnPlan($meta) && !self::estExecute($meta) && !self::estAnnule($meta);
    }

    /**
     * Ne conserve que la PREMIÈRE directive de revue de plan d'une réponse. Le
     * verrou de conversation ne voit que les tours PRÉCÉDENTS : si le moteur
     * appelle deux fois l'outil de préparation dans le MÊME tour, il faut encore
     * garantir un seul plan par message — un message ne stocke qu'un plan, la
     * seconde barre de décision serait orpheline. Les autres actions passent
     * inchangées.
     *
     * @param array<int, array> $actions
     *
     * @return array<int, array>
     */
    public static function limiterAUnSeulPlan(array $actions): array
    {
        $planVu = false;
        $retenues = [];
        foreach ($actions as $action) {
            if (($action['type'] ?? null) === self::ACTION_REVUE) {
                if ($planVu) {
                    continue;
                }
                $planVu = true;
            }
            $retenues[] = $action;
        }

        return $retenues;
    }

    /**
     * Extrait d'une liste d'actions le plan à STOCKER côté serveur (meta du
     * message qui le présente) : le plan lui-même, son budget ventilé, l'aperçu
     * autoritaire, les omissions, l'exigence de mot de passe et les impacts.
     * null si aucune action de revue n'est présente.
     *
     * SOURCE UNIQUE : deux chemins produisent aujourd'hui un message porteur de
     * plan — la réponse ordinaire du moteur, et la préparation DÉTERMINISTE de
     * l'étape suivante d'un programme (App\Ai\Programme\ProgrammeRunner). Ils
     * doivent stocker exactement la même structure, sans quoi l'endpoint
     * d'exécution ne saurait relire que l'une des deux.
     *
     * @param array<int, array> $actions
     */
    public static function planStockable(array $actions): ?array
    {
        foreach ($actions as $action) {
            if (($action['type'] ?? null) === self::ACTION_REVUE && isset($action['plan'])) {
                return [
                    'plan'             => $action['plan'],
                    // `budget` porte aussi la ventilation par étape (budget.parEtape) :
                    // c'est elle qui alimente les cases à cocher de l'ÉTENDUE, live
                    // comme après un rechargement de page.
                    'budget'           => $action['budget'] ?? null,
                    // Ce que le plan fait / ne fait pas, tel que l'utilisateur doit le
                    // VOIR avant de valider (indépendant de la prose du modèle).
                    'apercu'           => $action['apercu'] ?? [],
                    'omissions'        => $action['omissions'] ?? [],
                    'requiresPassword' => (bool) ($action['requiresPassword'] ?? false),
                    // Impacts de cascade conservés pour reconstruire la barre de
                    // décision après un rechargement de page (F5).
                    'impacts'          => $action['impacts'] ?? [],
                ];
            }
        }

        return null;
    }

    /**
     * Le DERNIER message de la conversation qui porte un plan encore en attente
     * de décision, ou null. (Il ne peut y en avoir qu'un : la préparation d'un
     * nouveau plan est verrouillée tant que celui-ci n'est pas tranché.)
     */
    public function messageEnAttente(?AssistantConversation $conversation): ?AssistantMessage
    {
        if ($conversation === null) {
            return null;
        }

        $enAttente = null;
        foreach ($conversation->getMessages() as $message) {
            if ($message->getRole() === AssistantMessage::ROLE_ASSISTANT
                && self::estEnAttente($message->getMeta() ?? [])) {
                $enAttente = $message;
            }
        }

        return $enAttente;
    }

    /**
     * La conversation porte-t-elle déjà un plan en attente de décision ? Variante
     * STATIQUE (sans EM), n'a besoin que de l'état du fil : utilisée par le
     * garde-fou anti-plan fantôme.
     */
    public static function aUnPlanEnAttente(?AssistantConversation $conversation): bool
    {
        if ($conversation === null) {
            return false;
        }

        foreach ($conversation->getMessages() as $message) {
            if ($message->getRole() === AssistantMessage::ROLE_ASSISTANT
                && self::estEnAttente($message->getMeta() ?? [])) {
                return true;
            }
        }

        return false;
    }

    /**
     * La prose PRÉSENTE-t-elle une décision à prendre — un tableau de budget, un
     * « bouton de validation », une « boîte de confirmation » — comme si un plan
     * était prêt à valider ? Combinée à l'ABSENCE de tout plan réellement émis ce
     * tour-ci ET en attente dans le fil, elle révèle un plan FANTÔME : le modèle
     * a décrit un plan (voire affirmé que le bouton est « actif ») sans appeler
     * preparer_operations — aucune barre de décision ne s'affichera jamais.
     *
     * Volontairement CONSERVATEUR (marqueurs à haute précision) : c'est le double
     * verrou « aucun plan émis + aucun plan en attente » qui garantit qu'on ne se
     * déclenche que sur une vraie divergence, pas sur un plan légitime (celui-là
     * porte une action réelle) ni sur un récapitulatif d'après-exécution.
     */
    public static function proseSimuleUneDecision(string $contenu): bool
    {
        $texte = mb_strtolower($contenu);

        // Revendication explicite de la surface de décision (ce que le modèle
        // fabrique : « le bouton de validation est actif », « la boîte de
        // confirmation va apparaître », « cliquez sur Valider »…).
        foreach ([
            'bouton de validation',
            'boîte de confirmation',
            'boite de confirmation',
            'valider et exécuter',
            'valider et executer',
            'cliquez sur valider',
            'cliquer sur valider',
            // Revendications sans le mot « bouton », vues en production : la prose
            // affirme que le plan EST prêt, ce qui suffit à faire chercher une barre
            // de décision qui n'existe pas.
            'prêt à être exécuté',
            'pret a etre execute',
            'prêt à être validé',
            'pret a etre valide',
        ] as $marqueur) {
            if (str_contains($texte, $marqueur)) {
                return true;
            }
        }

        // Tableau de BUDGET rendu en prose : « Total estimé » avec le solde.
        if (str_contains($texte, 'total estimé')
            && (str_contains($texte, 'solde disponible') || str_contains($texte, 'reste après'))) {
            return true;
        }

        // ANNONCE D'UN APPEL D'OUTIL À VENIR. Une phrase ne déclenche rien, et il n'y aura
        // pas de tour suivant : « je lance immédiatement preparer_mouvement_avenant » est
        // une promesse que rien ne tiendra. L'utilisateur attend, puis relance — c'est
        // exactement ce qui s'est produit en production.
        //
        // On exige la CONJONCTION d'un verbe d'intention à la 1re personne et du nom d'un
        // outil d'écriture : nommer un outil pour expliquer un manque (« il me faudrait
        // preparer_operations, mais la référence manque ») est un comportement LÉGITIME
        // qu'il ne faut pas signaler.
        return (bool) preg_match(
            '/\b(?:je (?:lance|vais|prepare|prépare|appelle|relance|execute|exécute)|j\'appelle|j\'execute|j\'exécute|je m\'en occupe)\b[^.!?]{0,120}'
            . '(?:preparer_mouvement_avenant|preparer_operations|parcours_saisie|modifier_composition_prime)/u',
            $texte,
        );
    }

    /**
     * DÉCISION FINALE du garde-fou anti-plan fantôme, en un seul endroit.
     *
     * Deux preuves, et l'une des deux suffit :
     *  - la prose REVENDIQUE la surface de décision ({@see proseSimuleUneDecision}) :
     *    marqueurs de haute précision, valables même si aucun outil n'a tourné ;
     *  - un outil de plan a RÉELLEMENT REFUSÉ ce tour-ci (signal serveur) ET la prose
     *    affiche malgré tout un plan ({@see proseAfficheUnPlan}).
     *
     * La seconde branche est celle qui manquait le 2026-08-11 : deux messages de suite
     * ont montré un tableau de plan complet et un budget recopié du tour précédent
     * alors que l'outil venait de refuser, et le garde-fou lexical seul n'a rien vu.
     *
     * `$aucuneDecision` (aucun plan d'écriture ni de document émis, aucun en attente,
     * aucun refus de périmètre) est le VERROU qui rend l'ensemble sans faux positif :
     * un plan légitime porte toujours une action réelle.
     */
    public static function estUnPlanFantome(
        string $contenu,
        bool $aucuneDecision,
        bool $unOutilDePlanARefuse,
    ): bool {
        if (!$aucuneDecision) {
            return false;
        }

        return self::proseSimuleUneDecision($contenu)
            || ($unOutilDePlanARefuse && self::proseAfficheUnPlan($contenu));
    }

    /**
     * La prose PRÉSENTE-t-elle un plan — un tableau d'opérations, un budget, une
     * invitation à valider — sans revendiquer explicitement la surface de décision ?
     *
     * POURQUOI CE SECOND TEST, PLUS LARGE. {@see proseSimuleUneDecision()} est
     * volontairement conservateur parce qu'il ne s'appuie QUE sur des mots. Le
     * 2026-08-11, il est passé à côté de deux messages consécutifs qui affichaient un
     * tableau de plan complet, un « Budget estimé : 10 unités (Solde disponible :
     * 115 321) » recopié du tour précédent et « Veuillez valider ce plan pour
     * finaliser l'enregistrement » — sans aucun bouton, puisque l'outil venait de
     * refuser. Élargir les mots-clés indéfiniment serait un jeu perdu.
     *
     * Ce test est donc fait pour être croisé avec un signal STRUCTUREL : un outil de
     * plan a tourné ce tour-ci et a REFUSÉ (cf. AiReply::$plansRefuses). C'est cette
     * conjonction qui fait la preuve — les mots seuls ne la feraient pas.
     */
    public static function proseAfficheUnPlan(string $contenu): bool
    {
        $texte = mb_strtolower($contenu);

        foreach ([
            'voici le plan',
            'plan d’opération',
            "plan d'opération",
            'plan d’operation',
            "plan d'operation",
            'budget estimé',
            'budget estime',
            'coût estimé',
            'cout estime',
            'valider ce plan',
            'validez ce plan',
            'plan pour validation',
            'plan à valider',
            'plan a valider',
        ] as $marqueur) {
            if (str_contains($texte, $marqueur)) {
                return true;
            }
        }

        // Le TABLEAU de plan lui-même : un en-tête markdown portant les colonnes
        // imposées par le protocole (opération/entité). Rendre ce tableau, c'est
        // affirmer qu'un plan existe.
        return (bool) preg_match('/\|[^|\n]*\b(?:opération|operation|entité|entite)\b[^|\n]*\|/u', $texte);
    }

    /**
     * Annule le plan en attente d'une conversation (décision implicite de
     * l'utilisateur qui demande un plan DIFFÉRENT). Même effet que le bouton
     * « Annuler » : la barre de décision devient un feedback permanent, et rien
     * n'a été écrit. Renvoie le libellé de ce qui a été annulé, ou null.
     *
     * Le flush est IMMÉDIAT et voulu : l'annulation doit survivre même si la suite
     * du tour échoue (moteur en erreur, réponse perdue). Sans quoi l'utilisateur
     * pourrait se retrouver avec l'ancienne barre encore active alors que Ket la
     * croit annulée — exactement les deux plans concurrents qu'on veut interdire.
     */
    public function annulerLePlanEnAttente(?AssistantConversation $conversation): ?string
    {
        $message = $this->messageEnAttente($conversation);
        if ($message === null) {
            return null;
        }

        $meta = $message->getMeta() ?? [];
        $meta['mutationPlanCancelled'] = true;
        $message->setMeta($meta);
        $this->em->flush();

        return $this->resume($meta['mutationPlan'] ?? []);
    }

    /**
     * Résumé lisible d'un plan stocké (« 3 opérations, 180 tokens »), pour dire à
     * l'utilisateur de QUOI on parle sans lui réafficher tout le tableau.
     *
     * @param array<string, mixed> $mutationPlan
     */
    public function resume(array $mutationPlan): string
    {
        $operations = is_array($mutationPlan['plan'] ?? null) ? count($mutationPlan['plan']) : 0;
        $cout = (int) ($mutationPlan['budget']['coutEstime'] ?? 0);

        return sprintf(
            '%d opération%s, %d token%s',
            $operations,
            $operations > 1 ? 's' : '',
            $cout,
            $cout > 1 ? 's' : '',
        );
    }
}
