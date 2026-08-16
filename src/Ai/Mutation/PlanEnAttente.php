<?php

namespace App\Ai\Mutation;

use App\Ai\Document\DocumentEnAttente;
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

    /**
     * Directive UI AUTORITAIRE : le message affirme un ENREGISTREMENT qui n'a pas
     * eu lieu. Le contraire de ce que Ket vient d'écrire doit être dit clairement,
     * sans quoi l'utilisateur repart en croyant son dossier constitué.
     */
    public const ACTION_NON_EXECUTE = TypeAction::EXECUTION_ABSENTE->value;

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

        if (self::budgetFabrique($texte)) {
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
     * UN BUDGET FABRIQUÉ DE TOUTES PIÈCES, reconnaissable à sa forme.
     *
     * L'INCIDENT (2026-08-12). Ket a rendu « Budget de l'opération — COÛT ESTIMÉ :
     * 50 € / ENREGISTREMENTS : 7 » sans avoir appelé le moindre outil, et aucune
     * bannière n'est apparue : les marqueurs de l'époque ne voyaient que les
     * revendications de bouton. L'utilisateur a cru qu'un plan l'attendait.
     *
     * DEUX SIGNATURES, chacune impossible à produire honnêtement :
     *
     * (a) UN BUDGET LIBELLÉ EN MONNAIE — n'importe laquelle. Le budget de la
     *     plateforme est en TOKENS et ne porte AUCUNE unité monétaire (PlanBuilder
     *     ne renvoie que coutEstime / soldeDisponible / resteApres / enregistrements).
     *     La règle ne vise donc PAS l'euro en particulier : ce serait le mauvais
     *     raisonnement, et il ferait manquer « 50 $ » ou « 50 USD ». C'est TOUTE
     *     monnaie sous un libellé de BUDGET qui est inventée par construction.
     *     Aucun faux positif : les montants MÉTIER (une prime de 95 $, une
     *     commission) ne se présentent jamais sous un libellé de budget.
     *
     * (b) LE TABLEAU DE BUDGET lui-même : un entête de budget, un décompte
     *     d'ENREGISTREMENTS et une mention d'opérations PLANIFIÉES. Le troisième
     *     terme est ce qui écarte un RÉCAPITULATIF d'après-exécution — celui-là
     *     parle d'opérations réalisées, jamais planifiées.
     *
     * Le verrou « aucune décision émise ni en attente » reste au-dessus
     * ({@see estUnPlanFantome}) : un plan légitime porte toujours une action
     * réelle, il ne peut donc pas déclencher d'avertissement.
     */
    private static function budgetFabrique(string $texte): bool
    {
        $entete = str_contains($texte, 'budget de l\'opération')
            || str_contains($texte, 'budget de l’opération')
            || str_contains($texte, 'budget de l\'operation')
            || str_contains($texte, 'budget de la mission');

        // (a) une monnaie — quelle qu'elle soit — sous un libellé de BUDGET.
        //
        // La fenêtre TRAVERSE les sauts de ligne, et c'est indispensable : dans un
        // tableau Markdown, « Coût estimé » est un entête de colonne et « 50 € » vit
        // dans la ligne SUIVANTE. Elle reste bornée (120 caractères) pour ne pas
        // rapprocher un budget honnête d'un montant métier cité plus loin.
        //
        // Le déclencheur est « coût ESTIMÉ » / « budget », jamais « coût » seul : le
        // coût d'une police, lui, s'exprime légitimement en monnaie.
        if (preg_match(
            '/(?:budget|coût estimé|cout estime|total estimé|total estime)[\s\S]{0,120}?'
            . '\d[\d\s.,]*\s*(?:€|\$|£|(?:usd|eur|euros?|cdf|fc|xaf|xof)\b)/iu',
            $texte,
        ) === 1) {
            return true;
        }

        // (b) le tableau complet, en tokens comme en monnaie.
        return $entete
            && str_contains($texte, 'enregistrement')
            && (str_contains($texte, 'opérations planifiées')
                || str_contains($texte, 'operations planifiees')
                || str_contains($texte, 'plan d\'enregistrement')
                || str_contains($texte, 'plan d’enregistrement'));
    }

    /**
     * La conversation porte-t-elle un plan RÉELLEMENT EXÉCUTÉ ?
     *
     * C'est le seul fait qui autorise Ket à parler d'un enregistrement au passé :
     * une écriture n'a jamais lieu pendant un tour de chat (elle passe par
     * l'endpoint d'exécution, après un clic), elle ne peut donc être QUE dans
     * l'historique.
     */
    public static function aUnPlanExecute(?AssistantConversation $conversation): bool
    {
        if ($conversation === null) {
            return false;
        }

        foreach ($conversation->getMessages() as $message) {
            if ($message->getRole() === AssistantMessage::ROLE_ASSISTANT
                && self::estExecute($message->getMeta() ?? [])) {
                return true;
            }
        }

        return false;
    }

    /**
     * LA PROSE AFFIRME-T-ELLE UN ENREGISTREMENT DÉJÀ FAIT ?
     *
     * L'INCIDENT (2026-08-12, le plus grave de la série). Ket a écrit « Le dossier
     * complet a été préparé et ENREGISTRÉ AVEC SUCCÈS DANS LA BASE DE DONNÉES »,
     * suivi d'un récapitulatif détaillé — client, risque, police, prime, règlement,
     * document. Vérification faite : la base ne contenait RIEN. Aucun plan n'avait
     * été présenté, aucun bouton, aucun budget, et bien sûr aucune exécution.
     *
     * C'est pire qu'un plan fantôme. Un plan fantôme fait attendre un bouton qui ne
     * vient pas — l'utilisateur s'en aperçoit. Une exécution fantôme, elle, le fait
     * PARTIR en croyant son dossier constitué : il ne le découvrira qu'au moment où
     * il en aura besoin, et il aura perdu la pièce entre-temps.
     *
     * Marqueurs à HAUTE PRÉCISION, exigeant un accomplissement ET sa destination :
     * « enregistré avec succès », « créé en base », « opérations réalisées ». Un
     * futur (« je vais créer », « le plan créera ») ne déclenche rien, et un plan
     * légitime non plus — celui-là annonce ce qu'il FERA, pas ce qu'il a fait.
     */
    public static function proseAffirmeUnEnregistrement(string $contenu): bool
    {
        $texte = mb_strtolower($contenu);

        foreach ([
            'enregistré avec succès', 'enregistrés avec succès', 'enregistrée avec succès',
            'enregistrees avec succes', 'enregistre avec succes', 'enregistres avec succes',
            'créé avec succès', 'créés avec succès', 'créée avec succès',
            'cree avec succes', 'crees avec succes',
            'opérations réalisées', 'operations realisees',
            'a bien été enregistré', 'ont bien été enregistrés',
        ] as $marqueur) {
            if (str_contains($texte, $marqueur)) {
                return true;
            }
        }

        // Un accomplissement suivi, de près, de sa DESTINATION : « le dossier a été
        // créé dans la base de données ». La conjonction est ce qui évite de
        // confondre avec l'annonce de ce qu'un plan fera.
        return (bool) preg_match(
            '/\b(?:a été|ont été|avons|ai)\s+(?:bien\s+)?(?:enregistr|cré|sauvegard|ajout)\w*'
            . '[^.!?]{0,60}\b(?:en base|dans la base|base de données|base de donnees)\b/u',
            $texte,
        );
    }

    /**
     * Ces actions portent-elles une DÉCISION qui attend l'utilisateur ?
     *
     * Un plan d'écriture ou un document à produire : dans les deux cas une barre de
     * validation s'affiche, et RIEN n'est écrit tant que personne n'a cliqué. C'est ce
     * fait — et lui seul — qui décide du temps que la rédaction doit employer.
     *
     * Les deux types sont réunis ici parce qu'ils partagent la seule chose qui compte à
     * cet endroit : l'opération n'a pas encore eu lieu. Ce qu'ils font ensuite (écrire
     * en base, fabriquer un fichier) n'y change rien.
     *
     * @param array<int, array> $actions
     */
    public static function porteUneDecision(array $actions): bool
    {
        foreach ($actions as $action) {
            if (in_array($action['type'] ?? null, [self::ACTION_REVUE, DocumentEnAttente::ACTION_REVUE], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * DÉCISION FINALE du garde-fou anti-EXÉCUTION fantôme.
     *
     * Trois conditions, toutes nécessaires :
     *  - la prose affirme un enregistrement accompli ;
     *  - AUCUNE décision n'a été émise ce tour-ci (aucun plan, aucun refus) ;
     *  - et le fil ne porte AUCUN plan réellement exécuté.
     *
     * La troisième est ce qui protège le cas légitime : après une vraie exécution,
     * l'utilisateur demande souvent « c'est bien fait ? » et Ket doit pouvoir
     * répondre oui. Le fil porte alors le marqueur d'exécution, et rien ne se
     * déclenche.
     */
    public static function estUneExecutionFantome(
        string $contenu,
        bool $aucuneDecision,
        bool $aucunPlanExecute,
    ): bool {
        if (!$aucuneDecision || !$aucunPlanExecute) {
            return false;
        }

        return self::proseAffirmeUnEnregistrement($contenu);
    }

    /**
     * LE PASSÉ EMPLOYÉ SOUS UNE BARRE DE VALIDATION — le trou que laissait le garde-fou
     * ci-dessus, et le mensonge le plus facile à croire.
     *
     * L'INCIDENT (2026-08-16). « Le document “AR Demande IDNAT.pdf” a été correctement
     * rattaché au client 96 (Mr. jean de dieu) », et juste en dessous une barre
     * « Valider et exécuter » intacte. Rien n'était écrit. L'utilisateur pouvait fermer
     * la fenêtre en croyant sa pièce classée, et ne s'en apercevoir que le jour où il la
     * chercherait.
     *
     * POURQUOI estUneExecutionFantome() NE LE VOYAIT PAS. Elle exige `$aucuneDecision` —
     * elle se DÉSARME donc dès qu'un plan est présenté. C'était volontaire pour le plan
     * FANTÔME (une prose qui décrit un plan pendant qu'un vrai plan s'affiche n'a rien
     * d'anormal), mais c'est précisément l'inverse ici : le moment où une décision
     * attend est celui où « c'est fait » est le plus trompeur, parce que la barre juste
     * en dessous a l'air d'une confirmation.
     *
     * On ne regarde donc PAS la même chose : là-bas, une prose qui MONTRE un plan ; ici,
     * une prose qui affirme un ENREGISTREMENT ACCOMPLI alors qu'il est encore à
     * décider. Les deux situations sont disjointes, et cette fonction n'a de sens que
     * lorsqu'une décision est réellement en attente — d'où le paramètre, qui vient du
     * serveur et jamais de la prose.
     */
    public static function estUneExecutionPrematuree(string $contenu, bool $decisionEnAttente): bool
    {
        return $decisionEnAttente
            && (self::proseAffirmeUnEnregistrement($contenu) || self::proseAffirmeUneOperationAccomplie($contenu));
    }

    /**
     * Une OPÉRATION dite accomplie, au-delà du seul verbe « enregistrer ».
     *
     * POURQUOI UN LEXIQUE À PART, et non l'élargissement de celui d'à côté. La phrase de
     * l'incident — « le document a été correctement RATTACHÉ au client 96 » — ne parle
     * ni d'enregistrement ni de base de données : elle décrit un RATTACHEMENT, et le
     * détecteur d'exécution fantôme, réglé sur « enregistré / créé … en base », ne la
     * voyait pas. L'élargir là-bas aurait déclenché des démentis sur des tours où AUCUNE
     * décision n'est en jeu, c'est-à-dire sur des phrases parfaitement honnêtes.
     *
     * Ici le risque n'est pas le même : on SAIT déjà, par le serveur, qu'une décision
     * attend une validation à cet instant précis. Dans ce contexte, tout accompli est
     * faux, et un lexique large est le bon réglage.
     *
     * LE TEMPS EST LE CRITÈRE, PAS LE VERBE. Chaque motif exige une marque d'accompli
     * (« a été », « nous avons », « est désormais ») : « sera rattaché », « serait
     * classée », « va être ajouté » ne matchent pas — ce sont précisément les tournures
     * que l'on veut voir employées.
     */
    private static function proseAffirmeUneOperationAccomplie(string $texte): bool
    {
        $texte = mb_strtolower($texte);
        // Les apostrophes typographiques abondent dans les réponses du modèle.
        $texte = str_replace(['’', '‘'], "'", $texte);

        // Le verbe peut être séparé de l'auxiliaire par un adverbe (« a été CORRECTEMENT
        // rattaché ») : on en tolère deux, pas davantage — au-delà, on relierait deux
        // propositions sans rapport.
        $verbes = '(?:rattach|attach|ajout|class|joint|associ|import|televers|télévers|enregistr|cr[ée]|sauvegard)\w*';

        foreach ([
            '/\b(?:a|ont)\s+été\s+(?:\w+\s+){0,2}' . $verbes . '/u',
            '/\b(?:j\'ai|nous avons)\s+(?:\w+\s+){0,2}' . $verbes . '/u',
            '/\best\s+(?:désormais|maintenant|bien)\s+(?:\w+\s+){0,1}(?:rattaché|attaché|lié|associé|classé|enregistré)e?s?\b/u',
        ] as $motif) {
            if (preg_match($motif, $texte) === 1) {
                return true;
            }
        }

        return false;
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
