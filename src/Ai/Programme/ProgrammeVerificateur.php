<?php

namespace App\Ai\Programme;

use App\Ai\Mutation\MutationPlan;
use App\Ai\Scope\AiScope;
use App\Ai\Verification\RelectureDeControle;
use App\Entity\AssistantProgramme;
use App\Entity\AssistantProgrammeEtape;
use Doctrine\ORM\EntityManagerInterface;

/**
 * ÉTAT DES LIEUX VÉRIFIÉ EN BASE d'un programme terminé — le rapport final.
 *
 * Le principe : ne rien croire sur parole, ni la prose du modèle, ni même le
 * journal d'exécution. Pour chaque étape exécutée on RELIT la base, ligne par
 * ligne, dans le périmètre de l'invité :
 *
 *  1. EXISTENCE  — l'enregistrement journalisé est-il réellement là ?
 *  2. CHAMPS     — vaut-il ce que le plan validé annonçait ?
 *  3. EFFET      — la conséquence métier attendue s'est-elle produite ?
 *                  (une prime signalée est-elle vraiment soldée ?)
 *  4. OMISSIONS  — quelles étapes n'ont PAS été exécutées, et pourquoi ?
 *
 * Les trois premiers axes portent sur UNE écriture et ne doivent rien au fait
 * qu'elle appartienne à une série : ils vivent dans {@see RelectureDeControle},
 * que l'exécution d'un plan ISOLÉ utilise également. Cette classe ne garde donc
 * que ce qui est propre au programme — le parcours des étapes, les OMISSIONS, et
 * l'assemblage du rapport opposable.
 *
 * Chaque écart repéré peut porter une CORRECTION prête à devenir l'étape d'un
 * nouveau programme, que l'utilisateur validera comme les autres.
 */
final class ProgrammeVerificateur
{
    public function __construct(
        private readonly RelectureDeControle $relecture,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Établit le rapport. Le résultat est un tableau sérialisable, stocké sur le
     * programme : il doit survivre au rechargement de page et rester opposable.
     *
     * @return array<string, mixed>
     */
    public function verifier(AssistantProgramme $programme, AiScope $scope): array
    {
        $etapes = [];
        $corrections = [];
        $compte = ['executee' => 0, 'annulee' => 0, 'impossible' => 0, 'echec' => 0, 'en_attente' => 0, 'proposee' => 0];

        foreach ($programme->getEtapes() as $etape) {
            $statut = $etape->getStatut();
            $compte[$statut] = ($compte[$statut] ?? 0) + 1;

            $ligne = [
                'reference' => (string) $etape->getReference(),
                'position'  => $etape->getOrdre(),
                'libelle'   => (string) $etape->getLibelle(),
                'outil'     => (string) $etape->getOutil(),
                'statut'    => $statut,
                'motif'     => $etape->getErreur(),
                'ecrits'    => [],
                'constats'  => [],
                'ecarts'    => [],
            ];

            if ($statut === AssistantProgrammeEtape::STATUT_EXECUTEE) {
                $ligne = $this->verifierEtapeExecutee($etape, $scope, $ligne, $corrections);
            } elseif ($statut !== AssistantProgrammeEtape::STATUT_ANNULEE) {
                // Une étape non exécutée et non refusée est une OMISSION : elle
                // devait être faite et ne l'a pas été. Elle entre telle quelle
                // dans les corrections proposées.
                if ($statut !== AssistantProgrammeEtape::STATUT_PROPOSEE) {
                    $ligne['ecarts'][] = 'Cette étape n’a pas été exécutée.';
                    $corrections[] = [
                        'outil'     => (string) $etape->getOutil(),
                        'libelle'   => (string) $etape->getLibelle(),
                        'arguments' => $etape->getArguments(),
                    ];
                }
            }

            $etape->setVerification(['constats' => $ligne['constats'], 'ecarts' => $ligne['ecarts'], 'ecrits' => $ligne['ecrits']]);
            $etapes[] = $ligne;
        }

        $ecarts = [];
        foreach ($etapes as $ligne) {
            foreach ($ligne['ecarts'] as $ecart) {
                $ecarts[] = sprintf('%s — %s', $ligne['reference'], $ecart);
            }
        }

        $rapport = [
            'reference'   => (string) $programme->getReference(),
            'objectif'    => (string) $programme->getObjectif(),
            'statut'      => $programme->getStatut(),
            'genereLe'    => (new \DateTimeImmutable('now'))->format(\DateTimeImmutable::ATOM),
            'total'       => $programme->nbEtapes(),
            'compte'      => $compte,
            'conforme'    => $ecarts === [],
            'etapes'      => $etapes,
            'ecarts'      => $ecarts,
            'corrections' => $this->dedupliquer($corrections),
        ];

        $programme->setRapport($rapport);
        $this->em->flush();

        return $rapport;
    }

    /**
     * Relecture en base d'une étape exécutée : existence, champs, effet métier.
     *
     * @param array<string, mixed> $ligne
     * @param array<int, array>    $corrections (par référence)
     *
     * @return array<string, mixed>
     */
    private function verifierEtapeExecutee(
        AssistantProgrammeEtape $etape,
        AiScope $scope,
        array $ligne,
        array &$corrections,
    ): array {
        // Le plan VALIDÉ de l'étape, tel qu'il a été stocké sur le message qui l'a
        // présenté : c'est lui qui dit ce qui AURAIT dû être écrit. Absent, la
        // relecture se limite à l'existence (aucune valeur à confronter).
        $meta = $etape->getMessage()?->getMeta() ?? [];
        $planStocke = $meta['mutationPlan']['plan'] ?? null;
        $plan = is_array($planStocke) ? MutationPlan::fromArray($planStocke) : new MutationPlan([]);

        $verdict = $this->relecture->verifier($plan, $etape->getJournal() ?? [], $scope, $corrections);

        $ligne['constats'] = array_merge($ligne['constats'], $verdict['constats']);
        $ligne['ecarts']   = array_merge($ligne['ecarts'], $verdict['ecarts']);
        $ligne['ecrits']   = array_merge($ligne['ecrits'], $verdict['ecrits']);

        return $ligne;
    }

    /**
     * Dédoublonne les corrections : deux écarts peuvent viser la même écriture
     * (un champ ET un effet métier). Proposer deux fois la même étape ferait
     * enregistrer deux fois.
     *
     * @param array<int, array> $corrections
     *
     * @return list<array>
     */
    private function dedupliquer(array $corrections): array
    {
        $vues = [];
        $retenues = [];
        foreach ($corrections as $correction) {
            if (trim((string) ($correction['outil'] ?? '')) === '') {
                continue;
            }
            $cle = md5(json_encode([$correction['outil'], $correction['arguments'] ?? []]) ?: '');
            if (isset($vues[$cle])) {
                continue;
            }
            $vues[$cle] = true;
            $retenues[] = $correction;
        }

        return $retenues;
    }
}
