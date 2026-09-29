<?php

namespace App\Ai\Trousse;

use App\Ai\AiRequest;
use App\Ai\AiText;
use App\Ai\Mutation\PlanEnAttente;
use App\Ai\Programme\ProgrammeEnCours;
use App\Entity\AssistantConversation;
use App\Entity\AssistantMessage;

/**
 * Choisit la TROUSSE d'un message — côté SERVEUR, sans rien demander au modèle.
 *
 * POURQUOI PLUS D'APPEL DE ROUTAGE. Un aiguilleur qui interroge le modèle est un
 * TROISIÈME appel, et la règle n'en tolère que deux : planifier, puis rédiger.
 * Le choix des outils appartient de toute façon à la planification elle-même —
 * demander d'abord « de quels outils vas-tu avoir besoin ? », puis les lui donner,
 * c'était poser deux fois la même question.
 *
 * CE QUE COÛTE UNE ERREUR, ET POURQUOI ON PENCHE TOUJOURS DU MÊME CÔTÉ. Une
 * sélection trop LARGE coûte des tokens sur un seul appel. Une sélection trop
 * ÉTROITE prive l'utilisateur d'une capacité et lui fait entendre « je ne peux
 * pas » — le refus que le prompt interdit précisément à Ket de formuler. Les deux
 * prix ne sont pas du même ordre : dans le doute, on élargit.
 *
 * D'où une règle simple et lisible : on ouvre l'écriture dès qu'un indice la
 * suggère, et on ne la ferme que sur une demande manifestement de consultation.
 */
final class SelecteurDeTrousse
{
    /**
     * Verbes et tournures par lesquels un courtier demande d'AGIR sur ses données.
     *
     * Cette liste ne prétend pas être exhaustive — aucune ne le serait. Elle n'a
     * pas à l'être : ce qu'elle rate part en trousse de lecture, et une demande
     * d'écriture mal aiguillée se rattrape par un nouveau message, pas par un
     * troisième appel.
     *
     * ⚠ CHAQUE ALTERNATIVE EST ANCRÉE SUR UNE FRONTIÈRE DE MOT, et ce n'est pas
     * une coquetterie. Sans le \b de tête, « mission » se trouvait à l'intérieur de
     * COMMISSION — le mot le plus fréquent du courtage. « Quelle est la commission
     * sur la police de Marlette ? », une pure consultation, armait donc la trousse
     * d'écriture : cinquante kilo-octets de déclarations et vingt-sept de protocoles,
     * payés pour rien. Même piège pour « édit » dans CRÉDIT, « change » dans ÉCHANGE,
     * « traite » dans TRAITEMENT, « accord » dans ACCORDÉE et « marque » dans REMARQUE.
     *
     * MESURÉ sur les 241 messages du journal (30 j) : 202 d'entre eux — 84 % —
     * partaient en trousse d'écriture sans appeler le moindre outil d'écriture, pour
     * 27,7 % des jetons d'entrée de la période. Rejouée sur 26 questions de pure
     * consultation, l'ancienne liste en détournait 10 ; la nouvelle, aucune, et sans
     * perdre un seul des 48 cas d'écriture du corpus (zéro faux négatif, cf.
     * SelecteurDeTrousseTest). Le compromis du docblock ci-dessus est donc INTACT :
     * on n'a pas resserré le jugement, on a cessé de lire des mots qui n'y sont pas.
     *
     * Les radicaux restent ouverts À DROITE (« enregistr », « modifi », « souscri »)
     * pour couvrir les flexions ; seuls « chang », « trait » et « accord » sont aussi
     * fermés à droite, parce que leur forme longue est un NOM de consultation.
     * (*UCP) rend \b sensible aux accents quelle que soit la compilation de PCRE :
     * sans lui, « é » cesse d'être une lettre sur certains serveurs et CRÉDIT
     * redeviendrait un déclencheur d'écriture en production seulement.
     */
    private const VERBES_ACTION = '/(*UCP)\b(cr[ée]e|cr[ée]er|ajoute|ajouter|enregistr|saisi|sauvegard|rempli|'
        . 'modifi|corrig|rectifi|chang(?:e|es|ez|er|eons|[ée]e?)\b|mets? à jour|supprim|efface|renouvel|reconduis|reconduir|'
        . 'prorog|prolong|annul|r[ée]sili|marque|signale|affecte|attribue|valide|valider|souscri|'
        . 'fais-le|fais le|vas.?y|essaie|essaye|refais|r[ée]essaie|continue|poursui|'
        // « Je veux que tu t'en charges » — la réponse la plus naturelle à la question
        // que Ket vient elle-même de poser (procédure A ou B). Elle ne contenait aucun
        // verbe de la liste : le 2026-08-11, seule la présence de « enregistrer » deux
        // messages plus haut a sauvé l'aiguillage.
        . 'en charg|charge-toi|proc[ée]dure a|option a|'
        // FORMULAIRE ET ÉDITION, en mots NUS. La tournure exacte « ouvre le formulaire »
        // était trop étroite d'un cheveu : « ouvre-moi par exemple le formulaire d'édition
        // pour Olea » ne la déclenchait pas, la trousse de lecture partait sans
        // ouvrir_dialogue, et Ket a ouvert la RUBRIQUE des partenaires — une liste, là où
        // l'utilisateur demandait un formulaire (incident du 2026-08-10). Un message qui
        // parle de formulaire ou d'édition demande à saisir : c'est le côté du doute où
        // l'on penche, ici comme partout dans ce fichier.
        . 'formulaire|[ée]dit|'
        . 'donne moi le plan|donne-moi le plan|pareil|par o[uù]|'
        // Le VOCABULAIRE DU MÉTIER, ajouté après mesure : « j'ai une offre venant de
        // SFA. Que faire ? » ne contient aucun verbe d'action, et annonce pourtant
        // une saisie. Ces trois mots — offre, cotation, proposition — ouvraient les
        // seuls faux négatifs du corpus.
        . 'offre|cotation|proposition|devis|accord\b|mission|que faire|comment (faire|proc[ée]der)|'
        // ÉMETTRE UNE PIÈCE, CE N'EST PAS LA CHIFFRER. « Facturer » est un mot à double
        // fond : dans « combien puis-je facturer aux assureurs ? » il annonce une
        // LECTURE — le corpus de référence range ce cas sous suivi_impayes — et dans
        // « facture cette commission », un ordre d'écriture. Un radical ouvert à droite
        // confondrait les deux et rouvrirait le sur-armement mesuré le 2026-09-24.
        //
        // On ne retient donc que les formes où le doute n'existe pas : le verbe suivi de
        // CE QU'ON FACTURE, l'impératif adressé (« facture-moi »), et les deux noms de
        // pièce qu'on ne prononce que pour en produire une.
        . 'factur(?:e|es|ez)\s+(?:ce|cet|cette|ces|l[ae]s?|l[\'’]|mon|ma|mes|notre|nos)\b|'
        . 'factur(?:e|ez)[- ](?:moi|nous|le|la|les)\b|'
        . 'note de d[ée]bit|note de cr[ée]dit|'
        . '[ée]met(?:s|tre|tez)\b|'
        . 'r[ée]ponds|trait(?:e|es|ez|er)\b|prends en charge)/iu';

    public function __construct(
        private readonly ProgrammeEnCours $programmeEnCours,
        // Source unique de l'appartenance d'un outil à l'écriture — la même que
        // celle qui décide des déclarations envoyées au fournisseur.
        private readonly TrousseCatalogue $catalogue,
    ) {
    }

    /**
     * LE DÉCLENCHEUR DU DERNIER AIGUILLAGE, pour le journal — et pour rien d'autre.
     *
     * On ne resserre pas des règles qu'on n'a pas mesurées. Le rapport de campagne dit
     * COMBIEN coûte la trousse d'écriture (cinquante-deux outils au lieu de
     * trente-trois, plus vingt-sept kilo-octets de protocoles) mais pas LEQUEL des six
     * déclencheurs la réclame, ni si l'écriture a seulement eu lieu. Sans ces deux
     * chiffres, resserrer revient à parier.
     */
    /**
     * Combien de messages de l'UTILISATEUR le filet lexical relit.
     *
     * Trois, parce qu'une saisie s'étale : « Enregistre la proposition de SUNU »,
     * puis la réponse à une question, puis un montant. Compter les messages de KET
     * dans ce nombre — ce qu'on faisait — revenait à lui laisser armer sa propre
     * trousse, puisque le prompt lui ordonne de proposer l'écriture.
     */
    private const MESSAGES_LUS = 3;

    private string $dernierDeclencheur = 'aucun';

    /**
     * LE MOT QUI A ARMÉ L'ÉCRITURE, quand c'est la liste de verbes qui a mordu.
     *
     * `dernierDeclencheur()` dit LEQUEL des six signaux a parlé ; il ne dit pas, pour
     * le sixième, laquelle des alternatives de VERBES_ACTION s'est reconnue. Or c'est
     * exactement ce qu'il faut pour resserrer la liste : mesuré sur les trente
     * messages qui portent déjà le déclencheur, `verbe-action` arme l'écriture vingt
     * fois sur vingt et une — et une seule de ces vingt écrit. Sans savoir QUEL mot a
     * mordu, on ne peut que retirer des alternatives au hasard.
     *
     * Vide dès que le déclencheur n'est pas `verbe-action`.
     */
    private string $dernierMotArmeur = '';

    public function dernierDeclencheur(): string
    {
        return $this->dernierDeclencheur;
    }

    public function dernierMotArmeur(): string
    {
        return $this->dernierMotArmeur;
    }

    public function trousseDe(AiRequest $requete): Trousse
    {
        $conversation = $requete->scope->conversation;
        $retenir = function (string $declencheur, Trousse $trousse, string $mot = ''): Trousse {
            $this->dernierDeclencheur = $declencheur;
            $this->dernierMotArmeur = $mot;

            return $trousse;
        };

        // Un plan attend une décision, ou une série est en cours : la suite est
        // forcément une écriture. Rien à deviner.
        if (PlanEnAttente::aUnPlanEnAttente($conversation)) {
            return $retenir('plan-en-attente', Trousse::ECRITURE);
        }
        if ($this->programmeEnCours->courant($conversation) !== null) {
            return $retenir('programme-en-cours', Trousse::ECRITURE);
        }
        // Une pièce jointe dans le fil sert presque toujours à saisir quelque chose.
        if ($conversation !== null && \count($conversation->getFichiers()) > 0) {
            return $retenir('piece-jointe', Trousse::ECRITURE);
        }

        // SIGNAL STRUCTUREL, et non lexical : le tour précédent a utilisé un outil
        // d'écriture. Une saisie engagée se poursuit — l'utilisateur répond à une
        // question, fournit un montant, dit « le taux est de 15 % ». Aucun verbe
        // d'action là-dedans, et pourtant l'écriture continue. Mesuré : ce signal
        // n'ouvre aucun faux positif de plus, il est donc gratuit.
        if ($this->dernierTourAEcrit($conversation)) {
            return $retenir('dernier-tour-a-ecrit', Trousse::ECRITURE);
        }

        // SIGNAL STRUCTUREL DÉCISIF : au tour précédent, Ket a PROPOSÉ d'écrire.
        //
        // C'est le seul cas où l'aiguillage pouvait s'enfermer. En trousse de lecture,
        // le prompt lui ordonne de proposer l'écriture (« Voulez-vous que je
        // l'enregistre ? », « préférez-vous que je m'en charge ? ») — sans quoi elle
        // répondrait « je ne peux pas », ce qui est faux. Mais la réponse de
        // l'utilisateur à cette question est un simple « oui », « allez-y », « option
        // A » : aucun verbe d'action, aucun vocabulaire métier. Sans ce signal, la
        // lecture repart, Ket propose une deuxième fois, et l'utilisateur tourne en
        // rond sur une capacité qu'on lui a promise au tour d'avant. Une question
        // posée engage celui qui l'a posée : si Ket a offert d'écrire, elle doit
        // pouvoir tenir l'offre au tour suivant.
        if ($this->dernierTourAProposeDEcrire($conversation)) {
            return $retenir('a-propose-d-ecrire', Trousse::ECRITURE);
        }

        // ── ACQUIESCEMENT : AUCUN OUTIL N'EST NÉCESSAIRE ───────────────────────────
        //
        // ⚠ LA PLACE DE CE TEST EST SA GARANTIE. Il vient APRÈS les six signaux
        // structurels, et pas avant. Un « ok » qui répond à une proposition d'écrire, à
        // un plan en attente ou à une saisie engagée est une CONFIRMATION : les signaux
        // ci-dessus l'ont déjà attrapé et ont armé l'écriture. S'il passait en tête, cette
        // validation partirait sans outil d'écriture, et le courtier n'aurait jamais le
        // bouton qu'il vient de demander.
        //
        // ON NE LIT QUE LE DERNIER MESSAGE, et il doit être ENTIÈREMENT un acquiescement.
        // « Ok, et maintenant la liste des clients » n'en est pas un ; le reconnaître à la
        // présence du mot plutôt qu'à la forme entière du message coûterait un tour de
        // recours à chaque fois.
        //
        // CE QUI N'EST PAS DANS LA LISTE, ET POURQUOI. « c'est fait ? » est une QUESTION :
        // le courtier demande une vérification en base, et elle appelle une lecture.
        // « essaie encore », « la suivante », « vas y » relancent une demande précédente —
        // le corpus réel les montre sous rechercher_entites et sous suivi_impayes. Une
        // remise en forme (« refais le tableau, trié par montant ») n'appelle non plus
        // aucun outil, mais rien ne la distingue sûrement d'une demande de données : elle
        // garde donc sa trousse. On capte moins que le gisement, et c'est voulu.
        if ($this->estUnAcquiescement($conversation, $requete)) {
            return $retenir('acquiescement', Trousse::AUCUN);
        }

        // ⚠ ON NE LIT QUE CE QUE L'UTILISATEUR A ÉCRIT — KET NE S'ARME PLUS ELLE-MÊME.
        //
        // CE QUE FAISAIT LA FENÊTRE. Elle concaténait les trois derniers messages du
        // fil, TOUS RÔLES CONFONDUS — donc les réponses de Ket. Or le prompt lui
        // ORDONNE de proposer l'écriture : « voulez-vous que je l'enregistre ? »,
        // « je peux ouvrir le formulaire », « voici la proposition ». Elle armait
        // ainsi sa propre trousse en parlant, et le gardait trois tours. Le filet
        // lexical, fait pour lire une INTENTION D'UTILISATEUR, se lisait lui-même.
        //
        // MESURÉ le 2026-09-25 sur les 39 conversations réelles (821 tours) :
        //   trois derniers messages, tous rôles (l'ancienne)  → 80,6 % armaient
        //   trois derniers messages, UTILISATEUR seul         → 53,8 %
        //   dernier message utilisateur seul                  → 28,7 %
        // 426 tours sur 821 — plus de la moitié — n'étaient armés QUE par la présence
        // de Ket dans la fenêtre, et ce sont des consultations manifestes : « il
        // reste combien de polices échues chez moi ? », « la suivante », « ok ».
        // Pour un taux d'écriture réellement constatée de 5 %.
        //
        // POURQUOI ON GARDE TROIS MESSAGES, et pas le seul dernier. Le seul dernier
        // descendrait à 28,7 %, mais retirerait une capacité que le corpus protège :
        // une saisie qui s'étale (« Enregistre la proposition de SUNU », puis, deux
        // tours plus loin, « le taux est de 15 % »). Les deux signaux structurels
        // ci-dessus la rattrapent souvent — pas toujours : Ket peut poser une
        // question sans appeler d'outil et sans employer l'une de ses tournures
        // d'offre. Un faux négatif prive l'utilisateur d'une capacité et lui fait
        // entendre « je ne peux pas » ; un faux positif ne coûte que des jetons. Le
        // compromis du docblock de tête reste donc intact — on a seulement cessé de
        // compter la voix de Ket comme une intention de l'utilisateur.
        //
        // La source est l'ENTITÉ, pas la requête : le contenu y est brut, sans les
        // marqueurs que le serveur ajoute pour le modèle — dont la bulle citée, qui
        // recopie un EXTRAIT EXACT du message cité, souvent une réponse de Ket.
        $demandes = $conversation?->derniersContenusUtilisateur(self::MESSAGES_LUS);
        if ($demandes === null || $demandes === []) {
            // Sans conversation (tests unitaires, fil non persisté), la requête est la
            // seule source — elle ne porte alors aucun marqueur.
            $demandes = [];
            foreach ($requete->messages as $message) {
                if (($message['role'] ?? null) === 'user') {
                    $demandes[] = (string) ($message['content'] ?? '');
                }
            }
            $demandes = array_slice($demandes, -self::MESSAGES_LUS);
        }
        $recent = implode(' ', $demandes);

        // Le mot qui a mordu est CAPTURÉ, pas seulement constaté : c'est lui, et non
        // le nom du signal, qui dira quelle alternative retirer de la liste.
        return preg_match(self::VERBES_ACTION, $recent, $trouve) === 1
            ? $retenir('verbe-action', Trousse::ECRITURE, mb_strtolower(trim($trouve[0])))
            : $retenir('aucun', Trousse::LECTURE);
    }

    /**
     * LES FORMULES QUI N'APPELLENT AUCUNE DONNÉE, et rien d'autre.
     *
     * Liste FERMÉE et volontairement courte : chaque entrée doit pouvoir constituer un
     * message entier sans qu'aucune lecture ne soit nécessaire. Le doute profite
     * toujours à la trousse complète — un faux positif coûte un tour de recours,
     * un faux négatif ne coûte que des jetons.
     */
    private const ACQUIESCEMENTS = '/^(?:ok|okay|d.accord|tres bien|parfait|super|nickel|'
        . 'genial|excellent|merci|beaucoup|bien|recu|entendu|compris|note'
        . ')$/u';

    /*
     * ⚠ LES SALUTATIONS N'Y SONT PAS, ET C'EST UNE MESURE, PAS UNE PRUDENCE.
     *
     * « bonjour », « salut » semblaient les cas les plus évidents d'un message sans
     * données. Deux tests antérieurs à ce lot disent le contraire, et ils ont raison :
     *
     *  · `SelecteurDeTrousseTest` inscrit « salut » parmi les consultations qui doivent
     *    rester en LECTURE — et le corpus réel lui donne raison, un « salut » y ouvre
     *    une conversation dont le tour suivant appelle un outil ;
     *  · `AnthropicAiEngineTest` interroge le moteur avec « Bonjour » et vérifie que les
     *    déclarations d'outils portent bien leur point de rupture de cache. Sans outil
     *    déclaré, il n'y a plus rien à vérifier.
     *
     * Une salutation ouvre un échange ; un acquiescement le referme. Seul le second ne
     * demande rien.
     */

    /**
     * Le message est-il ENTIÈREMENT un acquiescement ?
     *
     * La ponctuation et les formules de politesse enchaînées sont tolérées (« Ok, merci ! »),
     * pas le reste : dès qu'un mot hors liste apparaît, on rend la trousse complète.
     */
    private function estUnAcquiescement(?AssistantConversation $conversation, AiRequest $requete): bool
    {
        $dernier = $conversation?->derniersContenusUtilisateur(1)[0] ?? null;
        if ($dernier === null) {
            // Sans conversation (tests unitaires, fil non persisté), la requête est la
            // seule source — même règle, même lecture du seul DERNIER message utilisateur.
            foreach (array_reverse($requete->messages) as $message) {
                if (($message['role'] ?? null) === 'user') {
                    $dernier = (string) ($message['content'] ?? '');
                    break;
                }
            }
        }
        if ($dernier === null || trim($dernier) === '') {
            return false;
        }

        $normalise = AiText::normalize($dernier);
        // Les séparateurs deviennent des frontières de segment : « ok, merci » est deux
        // acquiescements, « ok donne la liste » n'en est pas un.
        $segments = preg_split('/[\s,;.!?…\-]+/u', $normalise, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($segments === []) {
            return false;
        }

        // Un message d'un seul tenant peut valoir plusieurs mots (« tres bien », « bien
        // recu ») : on essaie donc les regroupements de deux avant de refuser.
        $i = 0;
        $n = \count($segments);
        while ($i < $n) {
            $deux = $i + 1 < $n ? $segments[$i] . ' ' . $segments[$i + 1] : null;
            if ($deux !== null && preg_match(self::ACQUIESCEMENTS, $deux) === 1) {
                $i += 2;
                continue;
            }
            if (preg_match(self::ACQUIESCEMENTS, $segments[$i]) === 1) {
                ++$i;
                continue;
            }

            return false;
        }

        return true;
    }

    /**
     * Le dernier tour de l'assistant s'est-il conclu par un outil d'ÉCRITURE ?
     *
     * L'appartenance est lue dans le catalogue (marqueur AiToolEcriture), jamais
     * dans une liste recopiée ici : ajouter un outil d'écriture suffit à ce que la
     * continuité de saisie le prenne en compte.
     */
    private function dernierTourAEcrit(?AssistantConversation $conversation): bool
    {
        $dernier = $this->dernierMessageAssistant($conversation);
        if ($dernier === null) {
            return false;
        }

        $outil = ($dernier->getMeta() ?? [])['tool'] ?? null;

        return is_string($outil) && $this->catalogue->estOutilDEcriture($outil);
    }

    /**
     * Le tour précédent a-t-il OFFERT d'écrire quelque chose ?
     *
     * Les marqueurs sont ceux des phrases que le prompt lui fait écrire — la question
     * des procédures A/B et l'invitation de la trousse de lecture. On reconnaît donc
     * NOS propres tournures, pas celles de l'utilisateur : c'est ce qui rend ce test
     * fiable là où deviner une intention ne l'est pas.
     */
    private function dernierTourAProposeDEcrire(?AssistantConversation $conversation): bool
    {
        $dernier = $this->dernierMessageAssistant($conversation);
        if ($dernier === null) {
            return false;
        }

        $texte = mb_strtolower((string) $dernier->getContenu());

        foreach ([
            'en charg',
            'procédure a',
            'procédure b',
            'voulez-vous que je l',
            'souhaitez-vous que je',
            'préférez-vous',
            'que je m’en',
            "que je m'en",
            'que je le crée',
            'que je l’enregistre',
            "que je l'enregistre",
            'ouvrir le formulaire',
            'remplir le formulaire',
        ] as $marqueur) {
            if (str_contains($texte, $marqueur)) {
                return true;
            }
        }

        return false;
    }

    private function dernierMessageAssistant(?AssistantConversation $conversation): ?AssistantMessage
    {
        return $conversation?->dernierMessageAssistant();
    }
}
