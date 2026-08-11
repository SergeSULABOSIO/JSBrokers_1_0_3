<?php

namespace App\Ai\Tool;

use App\Ai\Document\BullesDeDonnees;
use App\Ai\Document\DocumentEnAttente;
use App\Ai\Document\DocumentFormat;
use App\Ai\Document\DocumentTarificateur;
use App\Ai\Document\PiedDePage;
use App\Ai\Document\RapportSpec;
use App\Ai\Document\Renderer\RapportRendererResolver;
use App\Ai\Mutation\PlanEnAttente;
use App\Ai\Scope\AiScope;
use App\Entity\AssistantMessage;
use App\Repository\AssistantParametresRepository;

/**
 * PRODUIRE UN DOCUMENT TÉLÉCHARGEABLE — Word, Excel, PDF, Markdown, texte, HTML.
 *
 * Ket disait « je ne dispose pas d'un outil direct pour générer et télécharger un
 * fichier Word » et proposait de recopier un tableau de chat dans Word à la main.
 * Cet outil est la réponse : un document officiel, structuré et rédigé, qui sort du
 * logiciel pour vivre chez le client, dans un courriel ou dans un dossier.
 *
 * À NE PAS CONFONDRE avec ce qui existait déjà : telecharger_fichiers et
 * telecharger_documents servent des fichiers qui EXISTENT DÉJÀ ; l'export d'une
 * bulle recopie UN message tel quel. Ici, on FABRIQUE.
 *
 * ── Ce que l'outil fait, et pourquoi dans cet ordre ─────────────────────────────
 * Il ne produit rien. Il chiffre, et présente un plan à valider. C'est le protocole
 * exigé : l'utilisateur doit savoir ce que la production va lui coûter AVANT qu'elle
 * ait lieu. Le coût dépend du nombre de caractères, donc le contenu doit être connu
 * à cet instant — d'où une spec complète dans les arguments, figée dans la meta du
 * message, et rendue telle quelle après validation. Aucun appel de plus au modèle :
 * le budget annoncé est, par construction, le budget débité.
 *
 * ── Pourquoi il n'est marqué NI AiToolProduisantUnPlan NI AiToolEcriture ────────
 * Les deux marqueurs sont liés (PromptSansOutilFantomeTest exige que tout outil de
 * plan appartienne à la trousse d'écriture), et les porter confinerait cet outil aux
 * tours d'écriture. Or la demande arrive presque toujours JUSTE APRÈS une analyse en
 * lecture — « fais-m'en un Word » — et l'utilisateur devrait alors attendre une
 * escalade et un tour de plus pour un document qui n'écrit aucune donnée métier.
 * Produire un livrable est une RESTITUTION, pas une écriture : l'outil reste donc
 * déclaré dans les deux trousses. Le verrou de décision, lui, est bien partagé
 * (cf. DocumentEnAttente::aUneDecisionEnAttente).
 */
final class PreparerDocumentTool implements AiToolInterface
{
    /** Titre de la section rattachée d'office par le filet anti-document-vide. */
    public const TITRE_DONNEES = 'Données détaillées';

    public function __construct(
        private readonly DocumentTarificateur $tarificateur,
        private readonly RapportRendererResolver $resolver,
        private readonly DocumentEnAttente $documentEnAttente,
        private readonly PlanEnAttente $planEnAttente,
        private readonly AssistantParametresRepository $parametresRepository,
        // Le filet : un rapport tiré d'une analyse chiffrée doit emporter ses
        // chiffres, même quand le modèle a rédigé de mémoire.
        private readonly BullesDeDonnees $bulles,
    ) {
    }

    public function name(): string
    {
        return 'preparer_document';
    }

    public function description(): string
    {
        return 'FABRIQUE un DOCUMENT OFFICIEL téléchargeable (Word, Excel, PDF, Markdown, texte, HTML) '
            . "à partir d'un travail que tu viens de produire : analyse, calcul, état, synthèse. "
            . "C'est le SEUL outil qui crée un fichier neuf, destiné à un usage HORS JS Brokers "
            . "(l'envoyer à un client, l'archiver, le retoucher). Il ne produit pas tout de suite : il "
            . "présente un PLAN et son BUDGET en tokens ; après validation par l'utilisateur, la "
            . 'plateforme fabrique le fichier et affiche un bouton de téléchargement sous ta réponse. '
            . 'Ne dis JAMAIS que tu ne peux pas générer, créer, mettre en forme ou fournir un fichier. '
            . 'À ne pas confondre avec telecharger_fichiers / telecharger_documents, qui servent des '
            . 'fichiers DÉJÀ existants. Format par défaut : Word.';
    }

    public function aiguillage(): string
    {
        return 'Une demande de DOCUMENT ou de FICHIER à récupérer : « produis-moi un rapport », « mets ça '
            . 'au propre », « je veux un Word / un Excel / un PDF », « je veux le télécharger », « fais-moi '
            . "une note pour mon client », « exporte ce travail ». J'établis le plan et le budget ; "
            . "l'utilisateur valide, et le fichier apparaît en téléchargement. RÈGLE D'OR : si le résultat "
            . 'est DÉJÀ affiché dans un message du fil, ne le recopie pas — donne son sourceMessageId et je '
            . 'reprends son texte exact, tableaux compris.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'titre' => [
                    'type' => 'string',
                    'description' => 'Titre du document, explicite et autoportant (il sera lu hors du logiciel).',
                ],
                'problematique' => [
                    'type' => 'string',
                    'description' => 'La question ou la problématique qui a nécessité ce travail. Obligatoire.',
                ],
                'introduction' => [
                    'type' => 'string',
                    'description' => "Introduction en la matière : de quoi il s'agit, pour un lecteur qui n'a PAS "
                        . "accès au logiciel. Ni sigle non défini, ni renvoi à un écran, ni « cf. la fiche #42 ».",
                ],
                'definitions' => [
                    'type' => 'array',
                    'description' => 'Définitions des termes techniques employés dans le document (prime nette, '
                        . "exigibilité, avenant…), pour un lecteur qui n'est pas assureur.",
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'terme'       => ['type' => 'string', 'description' => 'Le terme technique.'],
                            'explication' => ['type' => 'string', 'description' => 'Sa définition en une ou deux phrases.'],
                        ],
                        'required' => ['terme', 'explication'],
                    ],
                ],
                'sections' => [
                    'type' => 'array',
                    'description' => 'Les sections du RÉSULTAT (traitement, calculs, analyses). Chaque section '
                        . 'porte SOIT un corps rédigé, SOIT le sourceMessageId d\'un message du fil dont je '
                        . 'reprendrai le texte exact. RÈGLE : si le résultat chiffré est DÉJÀ affiché dans une '
                        . 'bulle (les bulles porteuses de tableaux annoncent leur numéro « message #N » dans le '
                        . 'fil), donne son sourceMessageId. Ne remplace JAMAIS un tableau par un total ou un '
                        . 'commentaire : un rapport sans ses données est un rapport faux.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'titre' => ['type' => 'string', 'description' => 'Titre de la section.'],
                            'corps' => [
                                'type' => 'string',
                                'description' => 'Contenu en markdown (paragraphes, listes, tableaux GFM). '
                                    . 'Les tableaux deviennent de vraies tables Word et de vraies cellules Excel.',
                            ],
                            'sourceMessageId' => [
                                'type' => 'integer',
                                'description' => 'Numéro d\'un message DÉJÀ affiché dans cette conversation (les '
                                    . 'bulles porteuses de données l\'annoncent : « cette bulle est le message #N »), '
                                    . 'dont je reprendrai le contenu exact. OBLIGATOIRE dès que le résultat y '
                                    . 'figure : tu évites de le recopier, et rien n\'est tronqué ni résumé.',
                            ],
                        ],
                        'required' => ['titre'],
                    ],
                ],
                'conclusion' => [
                    'type' => 'string',
                    'description' => 'Conclusion du document. Obligatoire.',
                ],
                'format' => [
                    'type' => 'string',
                    'enum' => DocumentFormat::valeurs(),
                    'description' => "Format de sortie. Omets-le pour Word, le défaut. Ne le renseigne que si "
                        . "l'utilisateur a précisé lequel il veut.",
                ],
                'remplacerPlanEnAttente' => [
                    'type' => 'boolean',
                    'description' => "Ne mets true QUE si une décision attend déjà ET que l'utilisateur demande "
                        . 'de la CHANGER : la précédente est alors annulée et remplacée.',
                ],
            ],
            'required' => ['titre', 'problematique', 'introduction', 'sections', 'conclusion'],
        ];
    }

    /**
     * LLM-only : rédiger un rapport structuré n'est pas simulable par mots-clés.
     * Le moteur simulé ne déclenchera donc jamais cet outil (les tests l'appellent
     * directement, comme pour analyser_fichier_pour_saisie).
     */
    public function match(string $question, AiScope $scope): ?array
    {
        return null;
    }

    public function execute(array $args, AiScope $scope): AiToolResult
    {
        // ── Verrou COMMUN : une seule décision en attente dans le fil.
        if (($refus = $this->verrouDeDecision($args, $scope)) !== null) {
            return $refus;
        }

        $format = DocumentFormat::depuis($args['format'] ?? null);
        if (!$this->resolver->couvre($format)) {
            $format = DocumentFormat::defaut();
        }

        [$sections, $introuvables] = $this->resoudreSections($args, $scope);
        [$sections, $rattachee] = $this->rattacherLesDonneesDuFil($sections, $scope);
        $spec = RapportSpec::depuisArguments($args, $sections);

        // ── Structure incomplète : on NOMME ce qui manque plutôt que de livrer un
        // document mutilé. Même contrat de refus que preparer_operations.
        $manquants = $spec->manquants();
        if ($manquants !== []) {
            return AiToolResult::ok([
                'pret'      => false,
                'manquants' => $manquants,
                'note'      => 'Document incomplet : il manque ' . implode(', ', $manquants)
                    . ". Rédige ces éléments toi-même à partir de ce que tu sais déjà — ne demande à "
                    . "l'utilisateur que ce que tu ne peux pas savoir — puis rappelle preparer_document. "
                    . "N'affiche AUCUN plan ni budget tant que le document n'est pas complet.",
            ]);
        }

        if ($spec->estTropLongue()) {
            return AiToolResult::ok([
                'pret'       => false,
                'caracteres' => $spec->caracteres(),
                'plafond'    => RapportSpec::MAX_CARACTERES,
                'note'       => sprintf(
                    'Document trop long (%d caractères pour un plafond de %d). Propose à l\'utilisateur de le '
                    . 'scinder en deux documents, ou de restreindre la période ou le périmètre.',
                    $spec->caracteres(),
                    RapportSpec::MAX_CARACTERES,
                ),
            ]);
        }

        $texte = $spec->texteFacturable();
        $budget = $this->tarificateur->budget($texte, $format, $scope->entreprise);
        $pied = $this->piedDePage($spec, $scope);

        $apercu = [
            'titre'       => $spec->titre,
            'format'      => $format->value,
            'formatLabel' => $format->libelle(),
            'sections'    => count($spec->sections),
            'definitions' => count($spec->definitions),
            'pages'       => $budget['pages'],
            'caracteres'  => $budget['caracteres'],
            // Le filet s'est-il déclenché ? La barre l'annonce à l'utilisateur —
            // il doit savoir que son document emporte le tableau de la bulle.
            'donneesRattachees' => $rattachee,
        ];

        $note = $budget['suffisant']
            ? 'Présente EN UNE FOIS : le titre du document, ce qu\'il contient (sections, définitions, '
                . 'nombre de pages), et le budget avec son explication — recopie « explication » telle quelle, '
                . "c'est le calcul exact. Précise que l'utilisateur peut changer le format sur la barre "
                . "affichée sous ta réponse, et que le fichier ne sera produit qu'après sa validation. "
                . "N'invente aucun autre chiffre."
            : 'Solde de tokens INSUFFISANT : présente le budget, puis propose d\'acheter des tokens ou '
                . "d'abandonner. Le document n'est pas produit.";

        if ($introuvables !== []) {
            $note .= ' ATTENTION : ' . count($introuvables) . ' section(s) référençaient un message '
                . 'introuvable dans cette conversation et sont vides — signale-le à l\'utilisateur.';
        }

        if ($rattachee) {
            $note .= ' IMPORTANT : ton document ne contenait AUCUN tableau alors que ta réponse précédente '
                . 'en portait ; j\'ai donc ajouté en dernière section « ' . self::TITRE_DONNEES . ' » le texte '
                . 'exact de cette bulle, tableaux compris. Le budget ci-dessus en tient déjà compte. '
                . 'Annonce-le en une phrase à l\'utilisateur : le rapport emporte bien le détail chiffré, '
                . 'et pas seulement son commentaire.';
        }

        return AiToolResult::ok(
            [
                'pret'        => true,
                'apercu'      => $apercu,
                'budget'      => $budget,
                'explication' => $budget['explication'],
                'note'        => $note,
            ],
            uiAction: [
                'type'   => DocumentEnAttente::ACTION_REVUE,
                // La SPEC voyage dans l'uiAction, jamais dans `data` : AiToolResult
                // documente que l'uiAction n'est pas renvoyée au modèle. La lui
                // réexpédier ferait recompter tout le document en entrée au tour
                // suivant, pour rien.
                'spec'   => $spec->toArray(),
                'format' => $format->value,
                'budget' => $budget,
                'apercu' => $apercu,
                'pied'   => $pied->toArray(),
            ],
        );
    }

    /**
     * Le verrou. Une barre de décision attend-elle déjà — d'écriture ou de
     * document ? L'échappatoire est la même que pour les plans d'écriture :
     * remplacer, ce qui annule d'abord.
     */
    private function verrouDeDecision(array $args, AiScope $scope): ?AiToolResult
    {
        if (!DocumentEnAttente::aUneDecisionEnAttente($scope->conversation)) {
            return null;
        }

        if (($args['remplacerPlanEnAttente'] ?? false) === true) {
            $this->documentEnAttente->annulerLePlanEnAttente($scope->conversation);
            $this->planEnAttente->annulerLePlanEnAttente($scope->conversation);

            return null;
        }

        return AiToolResult::ok([
            'pret'          => false,
            'planEnAttente' => true,
            'note'          => 'REFUSÉ : une décision que tu as déjà présentée attend l\'utilisateur — sa barre '
                . 'est encore affichée dans le fil. N\'en prépare pas une seconde : ce serait lui imposer deux '
                . 'validations. Explique-lui en une phrase qu\'une décision est en attente et invite-le à la '
                . 'VALIDER ou à l\'ANNULER. S\'il veut la CHANGER, rappelle-moi avec remplacerPlanEnAttente=true. '
                . 'N\'affiche AUCUN plan ni budget tant qu\'elle n\'est pas tranchée.',
        ]);
    }

    /**
     * Résout les sections : soit le corps rédigé, soit le contenu d'un message du
     * fil désigné par sourceMessageId.
     *
     * C'EST LA VOIE NORMALE POUR UN GROS RÉSULTAT. Les deux moteurs bornent leur
     * sortie à 4 096 jetons — environ 15 000 caractères : un modèle qui recopie un
     * tableau de dix-huit lignes se fait tronquer en plein milieu, et le document
     * sortirait amputé sans que rien ne le signale. En référençant le message, le
     * texte ne transite jamais par le modèle.
     *
     * FAIL-CLOSED : le message doit appartenir à la conversation du scope. Un id
     * d'ailleurs est ignoré (et signalé), jamais lu.
     *
     * @return array{0: list<array{titre: string, corps: string}>, 1: list<int>}
     */
    private function resoudreSections(array $args, AiScope $scope): array
    {
        $sections = [];
        $introuvables = [];

        foreach ((array) ($args['sections'] ?? []) as $brute) {
            if (!is_array($brute)) {
                continue;
            }

            $titre = trim((string) ($brute['titre'] ?? ''));
            $corps = trim((string) ($brute['corps'] ?? ''));
            $sourceId = (int) ($brute['sourceMessageId'] ?? 0);

            if ($sourceId > 0) {
                $reprise = $this->contenuDuMessage($sourceId, $scope);
                if ($reprise === null) {
                    $introuvables[] = $sourceId;
                } else {
                    // Le corps rédigé, quand il existe, INTRODUIT la reprise : le
                    // modèle peut commenter un tableau sans avoir à le recopier.
                    $corps = trim($corps === '' ? $reprise : $corps . "\n\n" . $reprise);
                }
            }

            // Une section RÉDUITE À SON TITRE est écartée. Dans un chat, un titre
            // sans contenu passe pour une intention ; dans un document officiel qui
            // part chez un client, c'est une rubrique vide. Et c'est exactement ce
            // qu'on obtient quand un sourceMessageId n'a pas pu être résolu : mieux
            // vaut alors refuser le plan que livrer un rapport troué.
            if ($corps === '') {
                continue;
            }

            $sections[] = ['titre' => $titre, 'corps' => $corps];
        }

        return [$sections, $introuvables];
    }

    /**
     * LE FILET : un rapport tiré d'une analyse chiffrée doit EMPORTER ses chiffres.
     *
     * L'incident du 11/08/2026. Ket affiche dix-huit lignes de paiements de primes
     * avec commissions, taxes et réserve. L'utilisateur demande « produis-moi un
     * rapport à partir de cette réponse ». Le rapport sort avec son objet, son
     * introduction, ses définitions, sa conclusion — et une seule phrase à la place
     * du tableau : « le montant total cumulé s'élève à 1 911 633,28 $ ». Le
     * document ne contenait pas ses données ; il n'était plus qu'un commentaire.
     *
     * La cause première est réparée ailleurs : les identifiants de bulle figurent
     * désormais dans l'historique (AiContextBuilder::marqueurBulleReprisable), donc
     * `sourceMessageId` est enfin adressable. Ceci en est le FILET, pour le tour où
     * le modèle rédige quand même de mémoire.
     *
     * TROIS CONDITIONS CUMULATIVES, pour qu'il reste étroit :
     *   1. aucune section du document ne porte de tableau — le modèle n'a donc rien
     *      repris, ni recopié à la main ;
     *   2. la DERNIÈRE bulle de l'assistant en porte un — c'est « cette réponse »
     *      que l'utilisateur désigne ;
     *   3. son texte n'est pas déjà présent dans une section (double sécurité
     *      contre une reprise en double).
     *
     * Le rattachement a lieu AVANT le chiffrage : le budget annoncé couvre donc le
     * tableau ajouté, et la promesse « le budget annoncé est le budget débité »
     * tient toujours.
     *
     * @param list<array{titre: string, corps: string}> $sections
     *
     * @return array{0: list<array{titre: string, corps: string}>, 1: bool}
     */
    private function rattacherLesDonneesDuFil(array $sections, AiScope $scope): array
    {
        if ($sections === []) {
            // Document vide : `manquants()` va le refuser, et un tableau rattaché
            // ne ferait que masquer une structure absente.
            return [$sections, false];
        }

        foreach ($sections as $section) {
            if ($this->bulles->porteDesDonnees($section['corps'])) {
                return [$sections, false];
            }
        }

        $porteuse = $this->bulles->dernierePorteuse($scope->conversation);
        if ($porteuse === null) {
            return [$sections, false];
        }

        $contenu = trim((string) $porteuse->getContenu());
        foreach ($sections as $section) {
            if (str_contains($section['corps'], $contenu)) {
                return [$sections, false];
            }
        }

        $sections[] = ['titre' => self::TITRE_DONNEES, 'corps' => $contenu];

        return [$sections, true];
    }

    /**
     * Le contenu d'un message de CETTE conversation, ou null.
     *
     * Le parcours de la collection EST la preuve d'appartenance : le scope porte la
     * conversation déjà restreinte à l'invité et à l'entreprise, donc l'id d'un
     * autre fil ne peut pas être atteint par ce chemin.
     */
    private function contenuDuMessage(int $id, AiScope $scope): ?string
    {
        foreach ($scope->conversation?->getMessages() ?? [] as $message) {
            if ($message->getId() === $id && $message->getRole() === AssistantMessage::ROLE_ASSISTANT) {
                $contenu = trim((string) $message->getContenu());

                return $contenu !== '' ? $contenu : null;
            }
        }

        return null;
    }

    /**
     * La note de bas de page — posée par le SERVEUR. Entreprise, utilisateur, titre
     * et date de production sont des FAITS : les laisser au modèle, c'est accepter
     * qu'un jour une date plausible mais fausse figure sur un document qui circule.
     */
    private function piedDePage(RapportSpec $spec, AiScope $scope): PiedDePage
    {
        $compte = $scope->invite->getUtilisateur();
        $utilisateur = trim((string) ($compte?->getNom() ?? ''));
        if ($utilisateur === '') {
            $utilisateur = (string) ($compte?->getEmail() ?? 'Utilisateur');
        }

        return new PiedDePage(
            entreprise: (string) $scope->entreprise->getNom(),
            utilisateur: $utilisateur,
            titre: $spec->titre,
            produitLe: new \DateTimeImmutable(),
            assistantNom: $this->parametresRepository->nomPour($scope->entreprise),
        );
    }
}
