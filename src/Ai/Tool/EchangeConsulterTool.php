<?php

namespace App\Ai\Tool;

use App\Ai\AiText;
use App\Ai\Presentation\Colonnes;
use App\Ai\Scope\AiScope;
use App\Echange\Canevas\CanevasDEchange;
use App\Echange\Canevas\RessourceDEchange;
use App\Echange\Service\CompteurDOccurrences;
use App\Entity\EchangeOccurrence;
use App\Entity\Invite;
use App\Repository\EchangeImportRunRepository;
use App\Repository\EchangeOccurrenceRepository;
use App\Service\Workspace\WorkspaceAccessResolver;

/**
 * TOUT CE QUE KET SAIT DIRE de la rubrique « Importation / Exportation », en UN SEUL
 * appel — périmètre, facturation, historique, contrôle en attente.
 *
 * Un outil par sujet aurait multiplié les tours de function calling : chaque tour
 * réexpédie tout le contexte au moteur, et « où en suis-je sur mes exports ? » se
 * paierait alors quatre fois. Le sujet est donc un PARAMÈTRE, et « tout » en est une
 * valeur.
 *
 * ⚠ ADAPTATEUR MINCE. Aucune requête propre, aucun recalcul de coût ou de quota, aucune
 * liste d'entités en dur : le périmètre vient de CanevasDEchange, les chiffres de
 * CompteurDOccurrences — les mêmes services que l'écran interroge. C'est ce qui garantit
 * que Ket et l'écran ne peuvent pas annoncer deux prix différents, et qu'ajouter une
 * donnée au périmètre la rend visible ici sans toucher à cette classe.
 */
final class EchangeConsulterTool implements AiToolInterface
{
    private const SUJETS = ['perimetre', 'facturation', 'historique', 'controle_en_cours', 'tout'];

    /** Nom court de la pseudo-entité gouvernant l'accès à la rubrique. */
    private const ENTITE = 'Echange';

    private const LIBELLE = 'Importation / Exportation';

    public function __construct(
        private readonly CanevasDEchange $canevas,
        private readonly CompteurDOccurrences $compteur,
        private readonly EchangeOccurrenceRepository $occurrences,
        private readonly EchangeImportRunRepository $importRuns,
        private readonly WorkspaceAccessResolver $accessResolver,
    ) {
    }

    public function name(): string
    {
        return 'echange_consulter';
    }

    public function description(): string
    {
        return 'Renseigne sur la rubrique « Importation / Exportation des données » du cabinet : '
            . 'quelles données sont échangeables et dans quel ordre (sujet « perimetre »), combien '
            . 'd\'opérations gratuites restent et ce que coûtera la prochaine (« facturation »), les '
            . 'échanges déjà effectués (« historique »), et s\'il existe un contrôle d\'import en '
            . 'attente de décision (« controle_en_cours »). Le sujet « tout » répond aux quatre à la '
            . 'fois. Le périmètre renvoyé est celui des droits de l\'utilisateur courant, pas celui '
            . 'du cabinet entier.';
    }

    public function aiguillage(): string
    {
        return '« qu\'est-ce que je peux exporter ? », « combien me reste-t-il d\'exports gratuits ? », '
            . '« où en est mon import ? », « ai-je déjà exporté mes données ? » — et avant toute '
            . 'proposition d\'export, pour annoncer le coût réel plutôt que de le supposer.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'sujet' => [
                    'type' => 'string',
                    'enum' => self::SUJETS,
                    'description' => 'perimetre = données échangeables et leur ordre ; facturation = '
                        . 'gratuites restantes, coût de la prochaine, solde ; historique = opérations '
                        . 'passées ; controle_en_cours = import déposé attendant confirmation ; '
                        . 'tout = les quatre (défaut).',
                ],
            ],
        ];
    }

    public function match(string $question, AiScope $scope): ?array
    {
        $n = AiText::normalize($question);
        if (!preg_match('/\b(export\w*|import\w*|echange\w*|jsbx|classeur)\b/', $n)) {
            return null;
        }

        if (preg_match('/\b(cout|coute\w*|gratuit\w*|facturation|tarif|token\w*)\b/', $n)) {
            return ['sujet' => 'facturation'];
        }
        if (preg_match('/\b(historique|deja|passe\w*|journal)\b/', $n)) {
            return ['sujet' => 'historique'];
        }
        if (preg_match('/\b(en cours|attente|controle|rapport|anomalie\w*)\b/', $n)) {
            return ['sujet' => 'controle_en_cours'];
        }
        if (preg_match('/\b(perimetre|quelles? donnees|quoi|liste)\b/', $n)) {
            return ['sujet' => 'perimetre'];
        }

        return ['sujet' => 'tout'];
    }

    public function execute(array $args, AiScope $scope): AiToolResult
    {
        // FAIL-CLOSED : la même porte que l'écran, refusée pour le même motif.
        if (!$this->accessResolver->canRead($scope->invite, self::ENTITE)) {
            return AiToolResult::horsPerimetre(self::LIBELLE);
        }

        $sujet = (string) ($args['sujet'] ?? 'tout');
        if (!in_array($sujet, self::SUJETS, true)) {
            $sujet = 'tout';
        }

        $data = ['sujet' => $sujet];

        if ($sujet === 'perimetre' || $sujet === 'tout') {
            $data += $this->perimetre($scope->invite);
        }
        if ($sujet === 'facturation' || $sujet === 'tout') {
            $data += $this->facturation($scope);
        }
        if ($sujet === 'historique' || $sujet === 'tout') {
            $data += $this->historique($scope);
        }
        if ($sujet === 'controle_en_cours' || $sujet === 'tout') {
            $data += $this->controleEnCours($scope);
        }

        return AiToolResult::ok($data);
    }

    /** @return array<string, mixed> */
    private function perimetre(Invite $invite): array
    {
        $lisibles = $this->canevas->ressourcesLisibles($invite);
        $ecrivables = array_keys($this->canevas->ressourcesEcrivables($invite));

        $lignes = [];
        foreach ($lisibles as $ressource) {
            $lignes[] = [
                'ordre'       => $ressource->rang + 1,
                'donnee'      => $ressource->libelle,
                'code'        => $ressource->code,
                'colonnes'    => count($ressource->colonnes),
                'depend_de'   => $ressource->dependances === [] ? '—' : implode(', ', $ressource->dependances),
                'a_l_import'  => in_array($ressource->code, $ecrivables, true) ? 'Modifiable' : 'Lecture seule',
            ];
        }

        return [
            'perimetre' => $lignes,
            'nb_donnees_exportables' => count($lignes),
            'nb_donnees_importables' => count($ecrivables),
            'note_perimetre' => 'Cette liste est celle des droits de cet utilisateur, pas celle du cabinet : '
                . 'une donnée qu\'il ne peut pas consulter à l\'écran ne peut pas sortir dans un fichier. '
                . 'Les feuilles sont ordonnées de façon qu\'une donnée référencée précède celle qui la référence.',
            'presentation' => $lignes === [] ? null : Colonnes::de([
                'ordre'      => Colonnes::IDENTIFIANT,
                'donnee'     => Colonnes::TEXTE,
                'code'       => Colonnes::TEXTE,
                'colonnes'   => Colonnes::NOMBRE,
                'depend_de'  => Colonnes::TEXTE,
                'a_l_import' => Colonnes::STATUT,
            ]),
        ];
    }

    /** @return array<string, mixed> */
    private function facturation(AiScope $scope): array
    {
        // Le chiffre vient du compteur, jamais d'un calcul refait ici : c'est la seule
        // façon que Ket et l'écran annoncent le même prix.
        $etat = $this->compteur->etat($scope->entreprise);

        return [
            'facturation' => $etat,
            'note_facturation' => 'Seule l\'EXPORTATION porte un forfait, et seulement une fois le quota '
                . 'gratuit épuisé. L\'importation n\'en porte aucun : chaque ligne écrite paie le même '
                . 'métrage qu\'une saisie à l\'écran.',
        ];
    }

    /** @return array<string, mixed> */
    private function historique(AiScope $scope): array
    {
        $lignes = [];
        foreach ($this->occurrences->historiquePour($scope->entreprise, 25) as $occurrence) {
            $lignes[] = [
                'date'      => $occurrence->getCreatedAt()?->format('d/m/Y H:i') ?? '',
                'operation' => $occurrence->getTypeLibelle(),
                'auteur'    => $occurrence->getInvite()?->getNom() ?? '—',
                'donnees'   => count($occurrence->getPerimetre()),
                'lignes'    => $occurrence->getNbLignes(),
                'tokens'    => $occurrence->getTokensDebites(),
            ];
        }

        return [
            'historique' => $lignes,
            'nb_occurrences' => $this->compteur->consommees($scope->entreprise),
            'presentation_historique' => $lignes === [] ? null : Colonnes::de([
                'date'      => Colonnes::DATE,
                'operation' => Colonnes::STATUT,
                'auteur'    => Colonnes::TEXTE,
                'donnees'   => Colonnes::NOMBRE,
                'lignes'    => Colonnes::NOMBRE,
                'tokens'    => Colonnes::NOMBRE,
            ], ['lignes', 'tokens']),
        ];
    }

    /** @return array<string, mixed> */
    private function controleEnCours(AiScope $scope): array
    {
        if ($scope->invite === null) {
            return ['controle_en_cours' => null];
        }

        $run = $this->importRuns->enAttentePour($scope->entreprise, $scope->invite);
        if ($run === null) {
            return [
                'controle_en_cours' => null,
                'note_controle' => 'Aucun contrôle d\'import n\'attend de décision.',
            ];
        }

        return [
            'controle_en_cours' => [
                'id'          => $run->getId(),
                'fichier'     => $run->getNomFichier(),
                'depose_le'   => $run->getCreatedAt()?->format('d/m/Y H:i') ?? '',
                'expire_le'   => $run->getExpireLe()?->format('d/m/Y H:i') ?? '',
                'statut'      => $run->getStatutLibelle(),
                'suppressions_autorisees' => $run->isSuppressionsAutorisees(),
                'rapport'     => $run->getRapport(),
            ],
        ];
    }
}
