<?php

namespace App\Ai\Tool;

use App\Ai\Mutation\MutationAllowlist;
use App\Ai\Mutation\MutationOperation;
use App\Ai\Trousse\AiToolEcriture;
use App\Ai\Programme\OutilsDeProgramme;
use App\Ai\Programme\ProgrammeEnCours;
use App\Ai\Programme\ProgrammeRunner;
use App\Ai\Scope\AiScope;

/**
 * Outil de MISSION EN PLUSIEURS PLANS — le « programme ».
 *
 * Quand l'utilisateur demande la MÊME opération sur PLUSIEURS objets (« signale
 * le paiement des tranches 60, 64 et 74 »), la bonne réponse n'est ni un plan
 * géant ni un plan puis un silence : c'est une SÉRIE de plans, validés l'un après
 * l'autre, dont aucun ne doit être oublié.
 *
 * Le modèle déclare ici la série ENTIÈRE, une seule fois. Ensuite, il n'a plus
 * rien à faire : après chaque exécution, c'est du CODE qui prépare et présente
 * l'étape suivante (ProgrammeRunner), puis qui établit le rapport final vérifié
 * en base. C'est la correction du défaut constaté en production — Ket exécutait
 * le premier plan d'une série, s'arrêtait, et affirmait ensuite que toute la
 * série était passée.
 *
 * FAIL-CLOSED : une étape ne peut déclencher qu'un outil PRODUCTEUR DE PLAN, et
 * seulement ceux qui se pilotent par paramètres plats (liste dérivée du code, cf.
 * OutilsDeProgramme). Chaque étape reste soumise, au moment où son plan est
 * construit, au contrôle d'accès de son propre outil : le programme n'ouvre
 * aucun droit.
 */
final class PreparerProgrammeTool implements AiToolProduisantUnPlan, AiToolEcriture
{
    /** En deçà de deux étapes, ce n'est pas une série : l'outil de plan suffit. */
    private const MIN_ETAPES = 2;

    /** Garde-fou de volume : au-delà, la mission doit être découpée. */
    private const MAX_ETAPES = 50;

    public function __construct(
        private readonly ProgrammeRunner $runner,
        private readonly ProgrammeEnCours $programmeEnCours,
        private readonly OutilsDeProgramme $outilsDeProgramme,
    ) {
    }

    public function name(): string
    {
        return 'preparer_programme';
    }

    public function description(): string
    {
        return 'Prépare un PROGRAMME : une série de plans d\'écriture à valider l\'un après l\'autre, '
            . 'du premier au dernier. À APPELER DÈS QUE la demande porte sur PLUSIEURS objets '
            . '(« signale le paiement des tranches 60, 64 et 74 », « marque ces 5 polices non '
            . 'renouvelables ») ET DÈS QUE l\'utilisateur demande explicitement de procéder EN '
            . 'PLUSIEURS TEMPS, même sur des entités différentes (« créons d\'abord ce fournisseur, '
            . 'ensuite nous enregistrerons la dépense ») : une étape par temps, la seconde renvoyant '
            . 'au résultat de la première par « ref »/« @ref ». Déclare TOUTES les étapes ici, en une '
            . 'seule fois. Tu n\'auras rien '
            . 'à relancer ensuite — après chaque validation, la plateforme prépare et présente '
            . 'elle-même l\'étape suivante, puis établit un rapport final vérifié en base. '
            . 'N\'appelle donc JAMAIS un outil de plan étape par étape pour une série : tu t\'arrêterais '
            . 'au premier. Une seule étape = n\'utilise pas cet outil, appelle directement l\'outil de plan. '
            . 'Outils utilisables par une étape et leurs paramètres — ' . $this->outilsDeProgramme->aideParametres()
            . '. Mets poursuivre=true (sans etapes) si un programme est en cours et que l\'utilisateur '
            . 'demande de continuer ou dit ne plus voir de bouton.';
    }

    public function aiguillage(): string
    {
        return 'Dès que la demande porte sur PLUSIEURS objets distincts (« signale le paiement des tranches 60, '
            . '64 et 74 », « marque ces cinq polices non renouvelables », « fais pareil pour les trois autres ») '
            . 'ou que l\'utilisateur demande d\'AVANCER PAR ÉTAPES VALIDÉES SÉPARÉMENT (« créons d\'abord le '
            . 'fournisseur, puis enregistrons la dépense »). Appelle-moi UNE SEULE FOIS en déclarant TOUTES les '
            . 'étapes : un outil de plan objet par objet s\'arrêterait au premier, et il n\'y a pas de tour '
            . 'suivant pour reprendre la main.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'objectif' => [
                    'type' => 'string',
                    'description' => 'La mission en une phrase, dans les termes de l\'utilisateur '
                        . '(« signaler le paiement des tranches 60, 64 et 74 »). Sert d\'entête au rapport final.',
                ],
                'etapes' => [
                    'type' => 'array',
                    'minItems' => self::MIN_ETAPES,
                    'description' => 'Les étapes de la série, DANS L\'ORDRE et SANS EN OMETTRE AUCUNE : '
                        . 'une étape par objet visé.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'libelle' => [
                                'type' => 'string',
                                'description' => 'Ce que fait l\'étape, en clair, avec de quoi la reconnaître '
                                    . '(« Tranche 64 — CASH MANAGEMENT SOLUTIONS »).',
                            ],
                            'outil' => [
                                'type' => 'string',
                                'enum' => $this->outilsDeProgramme->noms(),
                                'description' => 'Outil de plan que cette étape déclenchera.',
                            ],
                            'cibleId' => [
                                'type' => 'integer',
                                'minimum' => 1,
                                'description' => 'Identifiant de l\'objet visé (la tranche, l\'avenant, '
                                    . 'l\'enregistrement à modifier ou supprimer…). Renseigne-le systématiquement : '
                                    . 'c\'est lui qui distingue une étape de la suivante.',
                            ],
                            // ÉCRITURE ORDINAIRE dans une série. Ces trois champs ne servent
                            // qu'aux étapes dont l'outil sait assembler ses arguments (cf.
                            // EtapeAssemblable) : c'est ce qui rend enfin exprimable
                            // « créer le fournisseur, PUIS enregistrer la dépense ».
                            'entite' => [
                                'type' => 'string',
                                'enum' => MutationAllowlist::membres(),
                                'description' => 'Pour une étape d\'écriture ordinaire (preparer_operations) : '
                                    . 'nom court de l\'entité métier concernée.',
                            ],
                            'operation' => [
                                'type' => 'string',
                                'enum' => MutationOperation::OPS,
                                'description' => 'Pour une étape d\'écriture ordinaire : create = créer, '
                                    . 'edit = modifier (cibleId requis), delete = supprimer (cibleId requis). '
                                    . 'Par défaut create.',
                            ],
                            'champs' => [
                                'type' => 'array',
                                'description' => 'Pour une étape d\'écriture ordinaire : les champs de '
                                    . 'l\'enregistrement, en paires. Ex. [{"cle":"montant","valeur":"150"},'
                                    . '{"cle":"fournisseur","valeur":"Loyken Motors"}]. Une relation se donne par '
                                    . 'son NOM (le serveur la résout), une date comme l\'utilisateur l\'a dictée.',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'cle'    => ['type' => 'string', 'description' => 'Nom du champ.'],
                                        'valeur' => ['type' => 'string', 'description' => 'Valeur du champ, en texte.'],
                                    ],
                                    'required' => ['cle', 'valeur'],
                                ],
                            ],
                            'ref' => [
                                'type' => 'string',
                                'description' => 'Étiquette posée sur une étape de CRÉATION dont une étape '
                                    . 'SUIVANTE a besoin. L\'étape suivante donne alors à son champ de relation la '
                                    . 'valeur "@etiquette" : la plateforme y injectera l\'identifiant réel dès que '
                                    . 'la première étape aura été validée et écrite. Ex. étape 1 crée le '
                                    . 'fournisseur (ref:"fournisseur"), étape 2 enregistre la dépense '
                                    . '(champs:[{"cle":"fournisseur","valeur":"@fournisseur"}]).',
                            ],
                            // Paires PLATES, et non un objet libre : un paramètre complexe
                            // optionnel est ce que les modèles omettent le plus souvent
                            // (cf. OutilsDeProgramme). Les valeurs sont retypées côté serveur.
                            'arguments' => [
                                'type' => 'array',
                                'description' => 'Paramètres supplémentaires de l\'outil, en paires. Ex. '
                                    . '[{"cle":"montant","valeur":"758579.10"}]. Utilise EXACTEMENT les noms de '
                                    . 'paramètres de l\'outil choisi. Omets ce qui doit garder sa valeur par défaut.',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'cle'    => ['type' => 'string', 'description' => 'Nom du paramètre de l\'outil.'],
                                        'valeur' => ['type' => 'string', 'description' => 'Valeur, en texte (elle sera retypée).'],
                                    ],
                                    'required' => ['cle', 'valeur'],
                                ],
                            ],
                        ],
                        'required' => ['libelle', 'outil'],
                    ],
                ],
                'poursuivre' => [
                    'type' => 'boolean',
                    'description' => 'true pour REPRENDRE le programme en cours à son étape suivante '
                        . '(n\'envoie alors ni objectif ni etapes). À utiliser si l\'enchaînement a été '
                        . 'interrompu et que l\'utilisateur demande de continuer.',
                ],
                'remplacerProgrammeEnCours' => [
                    'type' => 'boolean',
                    'description' => 'true UNIQUEMENT si un programme est en cours et que l\'utilisateur '
                        . 'demande d\'en changer : l\'ancien est alors interrompu (ses étapes non faites '
                        . 'sont marquées comme telles) et remplacé par celui-ci.',
                ],
            ],
            'required' => [],
        ];
    }

    /** Chemin simulé neutralisé : une série d'écritures relève du LLM réel. */
    public function match(string $question, AiScope $scope): ?array
    {
        return null;
    }

    public function execute(array $args, AiScope $scope): AiToolResult
    {
        if ($scope->conversation === null) {
            return AiToolResult::ok([
                'pret' => false,
                'note' => 'Un programme ne peut être conduit qu\'au fil d\'une conversation.',
            ]);
        }

        $courant = $this->programmeEnCours->courant($scope->conversation);

        // POURSUITE d'un programme dont l'enchaînement a été rompu.
        if (($args['poursuivre'] ?? false) === true) {
            if ($courant === null) {
                return AiToolResult::ok([
                    'pret' => false,
                    'note' => 'Aucun programme n\'est en cours : il n\'y a rien à poursuivre. Si l\'utilisateur '
                        . 'veut traiter plusieurs objets, prépare un NOUVEAU programme avec toutes ses étapes.',
                ]);
            }
            $prochaine = $this->runner->preparerProchaine($courant, $scope, creerMessage: false);
            if ($prochaine === null) {
                return AiToolResult::ok([
                    'pret'      => false,
                    'programme' => $courant->getReference(),
                    'note'      => 'Toutes les étapes de ce programme sont tranchées : il n\'y a plus rien à présenter. '
                        . 'Le rapport final sera établi automatiquement.',
                ]);
            }

            return $this->succes($prochaine);
        }

        $etapesBrutes = $args['etapes'] ?? null;
        if (!is_array($etapesBrutes) || count($etapesBrutes) < self::MIN_ETAPES) {
            return AiToolResult::ok([
                'pret' => false,
                'note' => sprintf(
                    'Un programme demande au moins %d étapes. Pour un seul objet, appelle directement l\'outil de plan.',
                    self::MIN_ETAPES,
                ),
            ]);
        }
        if (count($etapesBrutes) > self::MAX_ETAPES) {
            return AiToolResult::ok([
                'pret' => false,
                'note' => sprintf(
                    'Ce programme compte %d étapes, au-delà du maximum de %d. Propose à l\'utilisateur de traiter '
                    . 'la série en plusieurs missions successives.',
                    count($etapesBrutes),
                    self::MAX_ETAPES,
                ),
            ]);
        }

        // VERROU de série : jamais deux programmes en cours dans un même fil.
        if ($courant !== null) {
            if (($args['remplacerProgrammeEnCours'] ?? false) !== true) {
                return AiToolResult::ok([
                    'pret'              => false,
                    'programmeEnCours'  => $courant->getReference(),
                    'note'              => sprintf(
                        'REFUSÉ : le programme %s est en cours (%d étapes sur %d tranchées). Ne prépare pas une '
                        . 'seconde série. Invite l\'utilisateur à trancher l\'étape affichée ; s\'il veut CHANGER '
                        . 'de mission, rappelle-moi avec remplacerProgrammeEnCours=true ; s\'il dit ne plus voir '
                        . 'de bouton, rappelle-moi avec poursuivre=true.',
                        $courant->getReference(),
                        $courant->nbTranchees(),
                        $courant->nbEtapes(),
                    ),
                ]);
            }
            $this->programmeEnCours->interrompre($courant, 'Programme remplacé à la demande de l’utilisateur.');
        }

        $etapes = [];
        foreach ($etapesBrutes as $brut) {
            if (!is_array($brut)) {
                continue;
            }
            $outil = trim((string) ($brut['outil'] ?? ''));
            if (!$this->outilsDeProgramme->estEligible($outil)) {
                return AiToolResult::ok([
                    'pret' => false,
                    'note' => sprintf(
                        'L\'outil « %s » ne peut pas être piloté par une étape de programme. Outils possibles : %s.',
                        $outil,
                        implode(', ', $this->outilsDeProgramme->noms()),
                    ),
                ]);
            }
            $arguments = $this->outilsDeProgramme->arguments($outil, $brut);
            // Une étape dont les arguments n'ont pas pu être assemblés (entité hors
            // périmètre, édition sans cible, création sans champ) est REFUSÉE ICI, en
            // nommant l'étape. La laisser passer produirait un programme qui échoue à
            // sa préparation, donc un « aucune étape n'a pu être préparée » sans
            // indication de la coupable.
            if ($arguments === []) {
                return AiToolResult::ok([
                    'pret' => false,
                    'note' => sprintf(
                        'L\'étape « %s » est inexploitable pour %s : je n\'ai pas pu en dériver d\'arguments. '
                        . 'Vérifie qu\'elle porte tout ce que cet outil attend — %s.',
                        (string) ($brut['libelle'] ?? 'sans libellé'),
                        $outil,
                        $this->outilsDeProgramme->aideParametres(),
                    ),
                ]);
            }

            $etapes[] = [
                'libelle'   => (string) ($brut['libelle'] ?? ''),
                'outil'     => $outil,
                'arguments' => $arguments,
            ];
        }

        if (count($etapes) < self::MIN_ETAPES) {
            return AiToolResult::ok([
                'pret' => false,
                'note' => 'Les étapes fournies sont inexploitables : redonne-les avec, pour chacune, un libellé, '
                    . 'un outil et l\'identifiant de l\'objet visé.',
            ]);
        }

        $programme = $this->runner->creer(
            $scope,
            trim((string) ($args['objectif'] ?? '')) ?: 'Mission en plusieurs étapes',
            $etapes,
        );

        $prochaine = $this->runner->preparerProchaine($programme, $scope, creerMessage: false);
        if ($prochaine === null) {
            // Aucune étape n'a pu être préparée : le programme est mort-né, on le
            // clôt immédiatement plutôt que de laisser un verrou orphelin.
            $this->programmeEnCours->interrompre($programme, 'Aucune étape n’a pu être préparée.');

            return AiToolResult::ok([
                'pret'    => false,
                'refusees' => $this->motifs($programme),
                'note'    => 'Aucune étape de ce programme n\'a pu être préparée. Explique à l\'utilisateur, '
                    . 'étape par étape, la raison donnée dans « refusees ». N\'affiche AUCUN plan.',
            ]);
        }

        return $this->succes($prochaine, $programme->getReference(), count($etapes));
    }

    /**
     * Réponse de succès : le modèle apprend que la série est LANCÉE et qu'il n'a
     * rien d'autre à faire. La consigne est explicite parce que la tentation
     * naturelle du modèle — décrire les étapes suivantes comme si elles étaient
     * déjà validées, ou reproposer un plan au tour d'après — est exactement ce
     * qu'on corrige ici.
     *
     * @param array{action: array, programme: array} $prochaine
     */
    private function succes(array $prochaine, ?string $reference = null, ?int $total = null): AiToolResult
    {
        $bandeau = $prochaine['programme'];

        return AiToolResult::ok(
            [
                'pret'      => true,
                'programme' => [
                    'reference' => $reference ?? $bandeau['reference'],
                    'etape'     => $bandeau['position'],
                    'total'     => $total ?? $bandeau['total'],
                    'libelle'   => $bandeau['etapeLibelle'],
                ],
                'note' => sprintf(
                    'Programme %s LANCÉ. La barre de décision de l\'étape %d sur %d (« %s ») est affichée sous ta '
                    . 'réponse. Annonce la mission et cette étape-là, RIEN DE PLUS : n\'affirme jamais qu\'une '
                    . 'étape suivante est faite, et ne prépare plus aucun plan pour cette série — la plateforme '
                    . 'présentera elle-même chaque étape suivante après validation, puis un rapport final vérifié '
                    . 'en base.',
                    $bandeau['reference'],
                    $bandeau['position'],
                    $bandeau['total'],
                    $bandeau['etapeLibelle'],
                ),
            ],
            uiAction: $prochaine['action'],
        );
    }

    /** @return list<string> */
    private function motifs(\App\Entity\AssistantProgramme $programme): array
    {
        $motifs = [];
        foreach ($programme->getEtapes() as $etape) {
            $motifs[] = sprintf('%s — %s', (string) $etape->getLibelle(), (string) ($etape->getErreur() ?? 'refusée'));
        }

        return $motifs;
    }
}
