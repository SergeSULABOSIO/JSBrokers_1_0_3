<?php

namespace App\Ai\Tool;

use App\Ai\Action\TypeAction;
use App\Ai\AiText;
use App\Ai\Fichier\ConversationFichierResolver;
use App\Ai\Presentation\Colonnes;
use App\Ai\Scope\AiScope;
use App\Ai\Trousse\AiToolEcriture;
use App\Echange\Service\Anomalie;
use App\Echange\Service\ImportImpossibleException;
use App\Echange\Service\ImportateurJsbx;
use App\Entity\EchangeImportRun;
use App\Entity\Invite;
use App\Repository\EchangeImportRunRepository;
use App\Service\Workspace\WorkspaceAccessResolver;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * IMPORTATION DE DONNÉES depuis un classeur joint à la conversation, en trois étapes :
 * contrôle, confirmation, annulation.
 *
 * ⚠ KET NE CONFIRME JAMAIS À LA PLACE DE L'UTILISATEUR, quelle que soit la formulation
 * de la demande — « fais-le », « je te fais confiance », « vas-y directement ».
 * L'étape « confirmation » n'écrit rien par elle-même : elle ouvre l'écran, où le
 * bouton est actionné par une main humaine. Ce n'est pas une précaution d'usage, c'est
 * la nature de l'acte : une importation réécrit le portefeuille d'un cabinet, et
 * personne d'autre que son propriétaire ne peut en répondre.
 *
 * ⚠ ADAPTATEUR MINCE. Aucune lecture de classeur ici, aucune règle de validation,
 * aucun décompte : tout passe par ImportateurJsbx, le même service que l'écran appelle.
 * Ce qui est refusé à l'écran est refusé ici, avec le même motif et à la même ligne.
 *
 * L'outil ne se déclare que si la conversation porte une pièce jointe : le reste du
 * temps, son schéma coûterait des tokens à chaque tour sans jamais servir.
 */
final class EchangeImporterTool implements AiToolInterface, AiToolEcriture, AiToolConditionnel
{
    private const ETAPES = ['controle', 'confirmation', 'annulation'];

    private const ENTITE = 'Echange';

    private const LIBELLE = 'Importation / Exportation';

    public function __construct(
        private readonly ImportateurJsbx $importateur,
        private readonly EchangeImportRunRepository $importRuns,
        private readonly ConversationFichierResolver $fichiers,
        private readonly WorkspaceAccessResolver $accessResolver,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function name(): string
    {
        return 'echange_importer';
    }

    public function description(): string
    {
        return 'Importe des données depuis un classeur Excel joint à la conversation. '
            . 'Étape « controle » : lit le fichier joint et rend un rapport détaillé de ce qui '
            . 'SERAIT écrit — gratuit, aucune donnée modifiée. Étape « confirmation » : ouvre '
            . 'l\'écran où l\'utilisateur valide lui-même l\'écriture (tu ne peux pas valider à sa '
            . 'place). Étape « annulation » : abandonne le contrôle en attente. '
            . 'Commence TOUJOURS par « controle ».';
    }

    public function aiguillage(): string
    {
        return '« importe ce fichier », « charge ces données », « vérifie ce classeur avant de '
            . 'l\'importer » — lorsqu\'un classeur Excel est joint à la conversation.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'etape' => [
                    'type' => 'string',
                    'enum' => self::ETAPES,
                    'description' => 'controle = analyser le fichier joint et rendre le rapport (défaut, '
                        . 'gratuit, n\'écrit rien) ; confirmation = ouvrir l\'écran de validation pour que '
                        . 'l\'utilisateur exécute lui-même ; annulation = abandonner le contrôle en attente.',
                ],
                'idFichier' => [
                    'type' => 'integer',
                    'description' => 'Identifiant de la pièce jointe à contrôler (marqueur @fichier:<id>). '
                        . 'Absent, la dernière pièce jointe de la conversation est prise.',
                ],
                'autoriserSuppressions' => [
                    'type' => 'boolean',
                    'description' => 'N\'active les suppressions demandées par le fichier que si '
                        . 'l\'utilisateur l\'a explicitement réclamé. Faux par défaut.',
                ],
            ],
        ];
    }

    /**
     * Ne coûte sa déclaration que lorsqu'un fichier est joint : hors de ce cas, l'outil
     * n'aurait rien à lire.
     */
    public function estDisponible(AiScope $scope): bool
    {
        return $scope->conversation !== null && count($scope->conversation->getFichiers()) > 0;
    }

    public function match(string $question, AiScope $scope): ?array
    {
        $n = AiText::normalize($question);
        if (!preg_match('/\b(importe[rz]?|charge[rz]?|integre[rz]?|reprends?)\b/', $n)) {
            return null;
        }
        if (!preg_match('/\b(fichier|classeur|excel|donnees|jsbx)\b/', $n)) {
            return null;
        }

        if (preg_match('/\b(annule[rz]?|abandonne[rz]?|laisse tomber)\b/', $n)) {
            return ['etape' => 'annulation'];
        }
        if (preg_match('/\b(confirme[rz]?|valide[rz]?|execute[rz]?)\b/', $n)) {
            return ['etape' => 'confirmation'];
        }

        return ['etape' => 'controle'];
    }

    public function execute(array $args, AiScope $scope): AiToolResult
    {
        // FAIL-CLOSED : importer exige l'ÉCRITURE sur la rubrique, pas sa simple lecture.
        if (!$this->accessResolver->can($scope->invite, self::ENTITE, Invite::ACCESS_ECRITURE)) {
            return AiToolResult::horsPerimetre(self::LIBELLE);
        }

        // ⚠ La casse est normalisée AVANT de reconnaître l'étape. Sans cela,
        // « CONFIRMATION » ne correspondait à rien et retombait sur le défaut —
        // c'est-à-dire relançait un contrôle, ce qui ANNULE celui en attente. Un
        // utilisateur qui insiste en majuscules perdait ainsi son rapport, et le
        // repli silencieux transformait une demande mal orthographiée en perte de
        // travail. Ce qu'on ne reconnaît toujours pas, lui, reste un contrôle : c'est
        // la seule étape qui n'ait aucune conséquence.
        $etape = mb_strtolower(trim((string) ($args['etape'] ?? 'controle')));
        if (!in_array($etape, self::ETAPES, true)) {
            $etape = 'controle';
        }

        return match ($etape) {
            'confirmation' => $this->confirmation($scope),
            'annulation'   => $this->annulation($scope),
            default        => $this->controle($args, $scope),
        };
    }

    // ─────────────────────────────────────────────────────────────────────────────

    private function controle(array $args, AiScope $scope): AiToolResult
    {
        if ($scope->conversation === null) {
            return AiToolResult::introuvable('', 'Aucune conversation : impossible de retrouver une pièce jointe.');
        }

        $fichier = $this->fichierVise($args, $scope);
        if ($fichier === null) {
            return AiToolResult::introuvable('', 'Aucun classeur n\'est joint à cette conversation. '
                . 'Demande à l\'utilisateur de déposer le fichier .xlsx qu\'il souhaite importer.');
        }

        $piece = $this->fichiers->resoudre('@fichier:' . $fichier->getId(), $scope, false);
        if (!$piece->estResolue()) {
            // Le motif du refus NOMME ce qui manque et ce qui existe : on le relaie tel
            // quel plutôt que de le remplacer par un « fichier illisible » qui n'aiderait
            // pas l'utilisateur à choisir la bonne pièce jointe.
            return AiToolResult::introuvable(
                (string) $fichier->getNomOriginal(),
                $piece->motif ?? 'Le fichier joint n\'a pas pu être lu sur le serveur. Demande à l\'utilisateur de le redéposer.',
            );
        }
        $chemin = $piece->upload->getPathname();

        // Un contrôle déjà en attente est remplacé : deux rapports concurrents pour un
        // même utilisateur, dont un périmé, ne peuvent que tromper.
        $enCours = $this->importRuns->enAttentePour($scope->entreprise, $scope->invite);
        if ($enCours !== null) {
            $this->importateur->annuler($enCours);
        }

        // ⚠ Le chemin est COPIÉ : le fichier de la conversation appartient à la pièce
        // jointe, et l'import efface son dépôt une fois abouti. L'effacer reviendrait à
        // supprimer la pièce jointe de l'utilisateur sous ses yeux.
        $copie = $this->copier($chemin);

        $run = $this->importateur->controler(
            $copie,
            $fichier->getNomOriginal() ?? 'import.xlsx',
            $scope->entreprise,
            $scope->invite,
            (bool) ($args['autoriserSuppressions'] ?? false),
        );

        return AiToolResult::ok($this->restituer($run, $scope));
    }

    /**
     * L'utilisateur confirme — et c'est LUI qui confirme.
     *
     * On ouvre l'écran positionné sur le contrôle en attente ; le bouton y est actionné
     * par une main humaine. Aucun argument, aucune formulation, aucune insistance ne
     * permet de sauter cette étape : l'outil n'a tout simplement pas de chemin qui écrive.
     */
    private function confirmation(AiScope $scope): AiToolResult
    {
        $run = $this->importRuns->enAttentePour($scope->entreprise, $scope->invite);
        if ($run === null) {
            return AiToolResult::introuvable('', 'Aucun contrôle n\'attend de décision. '
                . 'Lance d\'abord l\'étape « controle » sur le fichier joint.');
        }
        if (!$run->estConfirmable()) {
            return AiToolResult::ok([
                'pret'  => false,
                'motif' => 'controle_non_confirmable',
                'note'  => 'Ce contrôle porte des erreurs bloquantes ou a expiré : il ne peut pas être exécuté. '
                    . 'Présente les anomalies, et propose de corriger le fichier puis de le redéposer.',
            ]);
        }

        $rapport = $run->getRapport();

        return AiToolResult::ok(
            [
                'pret'          => true,
                'fichier'       => $run->getNomFichier(),
                'creations'     => $rapport['creations'] ?? 0,
                'modifications' => $rapport['modifications'] ?? 0,
                'suppressions'  => $rapport['suppressions'] ?? 0,
                'note' => 'L\'écran d\'importation s\'ouvre chez l\'utilisateur, positionné sur ce contrôle. '
                    . 'DIS-LUI CLAIREMENT que c\'est à lui de cliquer pour confirmer : tu ne peux pas valider '
                    . 'une importation à sa place, et tu ne dois pas laisser croire que c\'est fait.',
            ],
            uiAction: [
                'type' => TypeAction::OUVRIR_URL->value,
                'url'  => $this->urlGenerator->generate('admin.echange.workspace', [
                    'idEntreprise' => $scope->entreprise->getId(),
                    'onglet'       => 'importer',
                ]),
            ],
        );
    }

    private function annulation(AiScope $scope): AiToolResult
    {
        $run = $this->importRuns->enAttentePour($scope->entreprise, $scope->invite);
        if ($run === null) {
            return AiToolResult::ok(['annule' => false, 'note' => 'Aucun contrôle n\'était en attente.']);
        }

        try {
            $this->importateur->annuler($run);
        } catch (ImportImpossibleException $e) {
            return AiToolResult::ok(['annule' => false, 'note' => $e->getMessage()]);
        }

        return AiToolResult::ok([
            'annule'  => true,
            'fichier' => $run->getNomFichier(),
            'note'    => 'Le contrôle a été abandonné. Aucune donnée n\'a été modifiée, et rien n\'a été facturé.',
        ]);
    }

    /**
     * Rapport rendu au modèle : la synthèse d'abord, les anomalies situées ensuite.
     *
     * @return array<string, mixed>
     */
    private function restituer(EchangeImportRun $run, AiScope $scope): array
    {
        $rapport = $run->getRapport();

        $anomalies = [];
        foreach (array_slice($rapport['anomalies'] ?? [], 0, 25) as $anomalie) {
            $anomalies[] = [
                'gravite' => $anomalie['gravite'] === Anomalie::ERREUR ? 'Erreur' : 'Avertissement',
                'ou'      => trim(implode(', ', array_filter([
                    $anomalie['feuille'] ?? null,
                    isset($anomalie['ligne']) ? 'ligne ' . $anomalie['ligne'] : null,
                    isset($anomalie['colonne']) ? 'colonne ' . $anomalie['colonne'] : null,
                ]))) ?: '—',
                'probleme' => $anomalie['message'] ?? '',
            ];
        }

        $confirmable = (bool) ($rapport['confirmable'] ?? false);

        return [
            'idControle'    => $run->getId(),
            'fichier'       => $run->getNomFichier(),
            'confirmable'   => $confirmable,
            'lignes_lues'   => $rapport['lignes_lues'] ?? 0,
            'creations'     => $rapport['creations'] ?? 0,
            'modifications' => $rapport['modifications'] ?? 0,
            'suppressions'  => $rapport['suppressions'] ?? 0,
            'nb_erreurs'    => $rapport['nb_erreurs'] ?? 0,
            'anomalies'     => $anomalies,
            'note' => $confirmable
                ? 'Rien n\'a encore été écrit. Présente la synthèse, puis PROPOSE à l\'utilisateur de '
                    . 'confirmer — et attends sa réponse. C\'est lui, et lui seul, qui valide une importation.'
                : 'Rien n\'a été écrit et rien ne le sera tant que ces erreurs subsistent. Explique-les en '
                    . 'nommant la feuille et la ligne, puis propose de corriger le fichier et de le redéposer.',
            'presentation' => $anomalies === [] ? null : Colonnes::de([
                'gravite'  => Colonnes::STATUT,
                'ou'       => Colonnes::TEXTE,
                'probleme' => Colonnes::TEXTE,
            ]),
        ];
    }

    /** Dernière pièce jointe de la conversation, ou celle explicitement désignée. */
    private function fichierVise(array $args, AiScope $scope): ?object
    {
        $id = (int) ($args['idFichier'] ?? 0);
        if ($id > 0) {
            return $this->fichiers->trouver($id, $scope);
        }

        $fichiers = $scope->conversation?->getFichiers();
        if ($fichiers === null || count($fichiers) === 0) {
            return null;
        }

        // La plus récente : c'est celle que l'utilisateur vient de déposer en parlant.
        $derniere = null;
        foreach ($fichiers as $fichier) {
            $derniere = $fichier;
        }

        return $derniere;
    }

    /**
     * Copie de travail du fichier joint.
     *
     * L'import efface son dépôt une fois abouti — c'est voulu, il porte des données
     * personnelles. Mais la pièce jointe, elle, appartient à la conversation de
     * l'utilisateur : la lui supprimer sous les yeux serait un effet de bord qu'il n'a
     * pas demandé.
     */
    private function copier(string $chemin): string
    {
        $copie = tempnam(sys_get_temp_dir(), 'ket_import_') . '.xlsx';
        copy($chemin, $copie);

        return $copie;
    }
}
