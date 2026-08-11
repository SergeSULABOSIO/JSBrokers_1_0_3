<?php

namespace App\Ai\Programme;

use App\Ai\Mutation\MotifDeRefus;
use App\Ai\Mutation\PlanEnAttente;
use App\Ai\Scope\AiScope;
use App\Ai\Tool\AiToolResult;
use App\Entity\AssistantMessage;
use App\Entity\AssistantProgramme;
use App\Entity\AssistantProgrammeEtape;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * MOTEUR D'ENCHAÎNEMENT d'un programme : il sert le plan de l'étape suivante dès
 * que la précédente est tranchée, du premier au dernier, sans en oublier aucun.
 *
 * POURQUOI DÉTERMINISTE (et non « on relance le modèle »). Après l'exécution d'un
 * plan, la boucle conversationnelle est ROMPUE : l'endpoint d'exécution est
 * hors-LLM et ne crée aucun message, si bien que le modèle ne reprend la main
 * qu'au prochain envoi de l'utilisateur. C'est ce trou qui a produit le symptôme
 * constaté en production — Ket exécute le premier plan d'une série de trois,
 * s'arrête, puis, relancée, recopie en prose le tableau du tour précédent au lieu
 * de rappeler son outil. Ici, l'étape suivante est préparée par du CODE, à partir
 * d'un outil et d'arguments enregistrés à l'avance : coût nul en tokens, et
 * aucune place pour un oubli, une recopie ou une invention.
 *
 * L'étape suivante est préparée APRÈS l'écriture de la précédente, jamais avant :
 * chaque outil relit donc l'état RÉEL de la base (un solde restant, par exemple)
 * au moment où son plan est construit.
 */
final class ProgrammeRunner
{
    /**
     * Garde-fou : nombre maximal d'étapes refusées d'affilée qu'on traverse en
     * une passe. Au-delà, on rend la main plutôt que de balayer silencieusement
     * une série entière — l'utilisateur doit voir ce qui bloque.
     */
    private const MAX_REFUS_CONSECUTIFS = 20;

    public function __construct(
        private readonly OutilsDeProgramme $outilsDeProgramme,
        private readonly ProgrammeEnCours $programmeEnCours,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Crée le programme et ses étapes (aucune préparation ici : c'est
     * preparerProchaine qui fabrique les plans, un à la fois).
     *
     * @param array<int, array{libelle?: string, outil?: string, arguments?: array}> $etapes
     */
    public function creer(
        AiScope $scope,
        string $objectif,
        array $etapes,
        ?AssistantProgramme $corrige = null,
    ): AssistantProgramme {
        $programme = (new AssistantProgramme())
            ->setReference($this->programmeEnCours->genererReference())
            ->setConversation($scope->conversation)
            ->setEntreprise($scope->entreprise)
            ->setInvite($scope->invite)
            ->setObjectif($objectif)
            ->setCorrige($corrige);

        $ordre = 0;
        foreach ($etapes as $brut) {
            ++$ordre;
            $etape = (new AssistantProgrammeEtape())
                ->setOrdre($ordre)
                ->setReference(sprintf('%s/%02d', $programme->getReference(), $ordre))
                ->setLibelle(trim((string) ($brut['libelle'] ?? '')) ?: sprintf('Étape %d', $ordre))
                ->setOutil((string) ($brut['outil'] ?? ''))
                ->setArguments(is_array($brut['arguments'] ?? null) ? $brut['arguments'] : []);
            $programme->addEtape($etape);
            $this->em->persist($etape);
        }

        $this->em->persist($programme);
        $this->em->flush();

        return $programme;
    }

    /**
     * Prépare et PRÉSENTE l'étape suivante. Les étapes que leur outil refuse sont
     * traversées (statut « impossible » + motif conservé pour le rapport) : une
     * étape infaisable ne doit jamais figer la série.
     *
     * Renvoie null quand il n'y a plus rien à présenter — c'est à l'appelant de
     * clore le programme et d'établir le rapport.
     *
     * `$creerMessage = false` pour la PREMIÈRE étape : son plan voyage sur le
     * message ordinaire de la réponse du tour (celui que le contrôleur va créer),
     * il n'y a pas lieu d'en fabriquer un second — c'est le contrôleur qui
     * rattachera ce message à l'étape (attacherMessage).
     *
     * @return array{idMessage: ?int, contenu: ?string, action: array, programme: array}|null
     */
    public function preparerProchaine(AssistantProgramme $programme, AiScope $scope, bool $creerMessage = true): ?array
    {
        if (!$programme->estEnCours()) {
            return null;
        }

        $refus = 0;
        while (($etape = $programme->prochaineEtape()) !== null) {
            if (++$refus > self::MAX_REFUS_CONSECUTIFS) {
                $this->logger->warning('Ket : trop d’étapes de programme refusées d’affilée, arrêt de la passe.', [
                    'programme' => $programme->getReference(),
                ]);

                return null;
            }

            $resultat = $this->executerOutil($etape, $scope);
            if ($resultat === null) {
                continue; // étape déjà marquée « impossible » avec son motif.
            }

            $plan = PlanEnAttente::planStockable([$resultat->uiAction ?? []]);
            if ($plan === null) {
                $this->marquerImpossible($etape, 'L’outil n’a produit aucun plan exécutable pour cette étape.');
                continue;
            }

            return $this->presenter($programme, $etape, $plan, $creerMessage);
        }

        return null;
    }

    /**
     * Exécute l'outil de l'étape. Renvoie le résultat SEULEMENT s'il porte un plan
     * prêt ; sinon l'étape est marquée « impossible » avec un motif lisible et on
     * renvoie null.
     *
     * Le test porte sur `pret === true`, JAMAIS sur le seul statut : les refus des
     * outils de plan (informations manquantes, blocage métier, verrou) sont des
     * AiToolResult::ok() avec `pret: false` — les prendre pour des succès a déjà
     * produit un incident (deux consignes contraires envoyées au modèle).
     */
    private function executerOutil(AssistantProgrammeEtape $etape, AiScope $scope): ?AiToolResult
    {
        $absentes = [];

        // Résolution FAIL-CLOSED, déléguée à la source unique : un programme ne
        // peut déclencher qu'un outil producteur de plan pilotable par paramètres
        // plats. Refaire ce filtrage ici ferait diverger « ce qu'on a le droit de
        // déclarer » et « ce qu'on a le droit d'exécuter ».
        $outil = $this->outilsDeProgramme->outil((string) $etape->getOutil());
        if ($outil === null) {
            $this->marquerImpossible($etape, sprintf('Outil « %s » inconnu ou non autorisé.', (string) $etape->getOutil()));

            return null;
        }

        // RÉFÉRENCES ENTRE ÉTAPES. Une étape peut viser ce qu'une étape PRÉCÉDENTE a
        // créé (« la dépense du fournisseur qu'on vient d'enregistrer ») : impossible à
        // écrire d'avance, l'identifiant n'existait pas au moment de la déclaration.
        // Ici, il existe — l'étape précédente est écrite —, et c'est le SERVEUR qui
        // l'injecte. C'est le pendant, à l'échelle de la série, du chaînage « @ref »
        // interne à un plan.
        $arguments = $this->injecterReferences(
            $etape->getArguments(),
            $this->identifiantsDesEtapesEcrites($etape),
            $absentes,
        );
        if ($absentes !== []) {
            $this->marquerImpossible($etape, sprintf(
                'Cette étape renvoie à %s, qui n’a pas été enregistré (étape passée, refusée ou en échec).',
                implode(', ', array_map(static fn (string $ref) => '« ' . $ref . ' »', $absentes)),
            ));

            return null;
        }

        try {
            // `remplacerPlanEnAttente` : à ce point du programme, le plan précédent
            // est TRANCHÉ (exécuté ou annulé) et le verrou est donc ouvert. On ne
            // force rien — si un plan isolé attendait par ailleurs, le refus est
            // légitime et sera reporté tel quel.
            $resultat = $outil->execute($arguments, $scope);
        } catch (\Throwable $e) {
            $this->logger->error('Ket : échec de préparation d’une étape de programme.', [
                'etape'     => $etape->getReference(),
                'outil'     => $etape->getOutil(),
                'exception' => $e,
            ]);
            $this->marquerImpossible($etape, 'Une erreur technique a empêché de préparer cette étape.');

            return null;
        }

        if ($resultat->status !== AiToolResult::STATUS_OK) {
            $this->marquerImpossible($etape, $this->motifRefus($resultat));

            return null;
        }
        if (($resultat->data['pret'] ?? false) !== true) {
            $this->marquerImpossible($etape, $this->motifRefus($resultat));

            return null;
        }

        return $resultat;
    }

    /**
     * Ce que les étapes DÉJÀ ÉCRITES de la série ont créé : étiquette => identifiant.
     *
     * L'étiquette est celle que porte l'opération de l'étape (`ref`), et
     * l'identifiant vient de son JOURNAL d'exécution — la seule liste vraie de ce qui
     * a été écrit. Une étape annulée, refusée ou en échec n'apporte donc RIEN, et
     * c'est exactement ce qu'il faut : une référence vers un enregistrement qui
     * n'existe pas doit faire échouer l'étape suivante, jamais lui donner un
     * identifiant arbitraire.
     *
     * @return array<string, int>
     */
    private function identifiantsDesEtapesEcrites(AssistantProgrammeEtape $courante): array
    {
        $programme = $courante->getProgramme();
        if ($programme === null) {
            return [];
        }

        $identifiants = [];
        foreach ($programme->getEtapes() as $etape) {
            if ($etape === $courante || $etape->getStatut() !== AssistantProgrammeEtape::STATUT_EXECUTEE) {
                continue;
            }
            $ref = null;
            foreach ((array) (($etape->getArguments() ?? [])['operations'] ?? []) as $operation) {
                if (is_array($operation) && trim((string) ($operation['ref'] ?? '')) !== '') {
                    $ref = trim((string) $operation['ref']);
                    break;
                }
            }
            if ($ref === null) {
                continue;
            }
            foreach ((array) ($etape->getJournal() ?? []) as $ligne) {
                $id = is_array($ligne) ? ($ligne['id'] ?? null) : null;
                if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                    $identifiants[$ref] = (int) $id;
                    break;
                }
            }
        }

        return $identifiants;
    }

    /**
     * Remplace récursivement, dans les arguments d'une étape, toute valeur « @ref »
     * par l'identifiant réel. Les étiquettes sans correspondance sont COLLECTÉES
     * plutôt que laissées passer : l'étape sera traversée avec un motif lisible.
     *
     * Les autres marqueurs « @ » (une pièce jointe « @fichier:7 ») ne sont jamais
     * touchés — ils appartiennent au plan, pas à la série.
     *
     * @param array<mixed>       $arguments
     * @param array<string, int> $identifiants
     * @param list<string>       $absentes     (par référence)
     *
     * @return array<mixed>
     */
    private function injecterReferences(array $arguments, array $identifiants, array &$absentes): array
    {
        foreach ($arguments as $cle => $valeur) {
            if (is_array($valeur)) {
                $arguments[$cle] = $this->injecterReferences($valeur, $identifiants, $absentes);
                continue;
            }
            if (!is_string($valeur) || !str_starts_with($valeur, '@') || str_starts_with($valeur, '@fichier:')) {
                continue;
            }
            $ref = substr($valeur, 1);
            if (isset($identifiants[$ref])) {
                $arguments[$cle] = $identifiants[$ref];
                continue;
            }
            if (!in_array($ref, $absentes, true)) {
                $absentes[] = $ref;
            }
        }

        return $arguments;
    }

    /**
     * Crée le message assistant qui PORTE le plan de l'étape. La prose est écrite
     * par le serveur, pas par le modèle : elle ne peut donc ni omettre l'étape, ni
     * en annoncer une autre, ni prétendre qu'un bouton existe quand il n'existe
     * pas. L'aperçu autoritaire du plan reste, lui, rendu par la barre de décision.
     *
     * @param array<string, mixed> $plan
     *
     * @return array{idMessage: ?int, contenu: ?string, action: array, programme: array}
     */
    private function presenter(
        AssistantProgramme $programme,
        AssistantProgrammeEtape $etape,
        array $plan,
        bool $creerMessage,
    ): array {
        $bandeau = $this->bandeau($programme, $etape);

        if (!$creerMessage) {
            // Première étape : le plan voyage sur le message ordinaire du tour.
            // L'étape est proposée sans message ; le contrôleur l'y rattachera
            // dès que ce message existera (attacherMessage).
            $etape->setStatut(AssistantProgrammeEtape::STATUT_PROPOSEE);
            $this->em->flush();

            return [
                'idMessage' => null,
                'contenu'   => null,
                'action'    => ['type' => PlanEnAttente::ACTION_REVUE] + $plan + ['programme' => $bandeau],
                'programme' => $bandeau,
            ];
        }

        $contenu = $this->prose($programme, $etape);

        $message = (new AssistantMessage())
            ->setRole(AssistantMessage::ROLE_ASSISTANT)
            ->setContenu($contenu)
            ->setMeta([
                // `engine: programme` : cette bulle n'a coûté aucun appel au modèle.
                'engine'       => 'programme',
                'tool'         => $etape->getOutil(),
                'mutationPlan' => $plan,
                'programme'    => $bandeau,
            ]);
        $programme->getConversation()?->addMessage($message);
        $this->em->persist($message);
        $this->em->flush(); // l'id du message est nécessaire à l'action de revue.

        $action = ['type' => PlanEnAttente::ACTION_REVUE, 'idMessage' => (int) $message->getId()]
            + $plan
            + ['programme' => $bandeau];

        $meta = $message->getMeta() ?? [];
        $meta['actions'] = [$action];
        $message->setMeta($meta);

        $etape->setMessage($message);
        $etape->setStatut(AssistantProgrammeEtape::STATUT_PROPOSEE);
        $this->em->flush();

        return [
            'idMessage' => (int) $message->getId(),
            'contenu'   => $contenu,
            'action'    => $action,
            'programme' => $bandeau,
        ];
    }

    /**
     * Rattache le message assistant du tour courant à l'étape que l'outil vient de
     * proposer (la PREMIÈRE étape d'un programme voyage sur le message ordinaire de
     * la réponse, il n'y a pas lieu d'en fabriquer un second).
     *
     * Si ce message ne porte finalement AUCUN plan — cas dégradé : le moteur a
     * appelé deux outils de plan dans le même tour et le second a été élagué —
     * l'étape retourne « en attente » plutôt que de rester proposée sans surface
     * de décision : elle sera reprise par une poursuite explicite.
     */
    public function attacherMessage(AssistantProgramme $programme, AssistantMessage $message): void
    {
        $etape = $programme->etapeProposee();
        if ($etape === null || $etape->getMessage() !== null) {
            return;
        }

        if (!PlanEnAttente::porteUnPlan($message->getMeta() ?? [])) {
            $etape->setStatut(AssistantProgrammeEtape::STATUT_EN_ATTENTE);
            $this->logger->warning('Ket : l’étape proposée n’a trouvé aucun plan sur le message du tour.', [
                'programme' => $programme->getReference(),
                'etape'     => $etape->getReference(),
            ]);
            $this->em->flush();

            return;
        }

        $etape->setMessage($message);
        $this->em->flush();
    }

    /** L'étape vient d'être exécutée : on conserve son journal (liste vraie des écritures). */
    public function marquerExecutee(AssistantProgrammeEtape $etape, array $journal): void
    {
        $etape->setStatut(AssistantProgrammeEtape::STATUT_EXECUTEE);
        $etape->setJournal($journal);
        $this->em->flush();
    }

    /** L'utilisateur a refusé cette étape : la série continue, l'omission sera dite. */
    public function marquerAnnulee(AssistantProgrammeEtape $etape): void
    {
        $etape->setStatut(AssistantProgrammeEtape::STATUT_ANNULEE);
        $etape->setErreur('Étape refusée par l’utilisateur.');
        $this->em->flush();
    }

    public function marquerEchec(AssistantProgrammeEtape $etape, string $message): void
    {
        $etape->setStatut(AssistantProgrammeEtape::STATUT_ECHEC);
        $etape->setErreur($message);
        $this->em->flush();
    }

    private function marquerImpossible(AssistantProgrammeEtape $etape, string $motif): void
    {
        $etape->setStatut(AssistantProgrammeEtape::STATUT_IMPOSSIBLE);
        $etape->setErreur($motif);
        $this->em->flush();
    }

    /**
     * Motif LISIBLE d'un refus d'outil, tiré de ce que l'outil a réellement dit
     * (champs manquants, blocages, note). Jamais une phrase générique inventée :
     * le rapport final doit pouvoir citer la cause exacte.
     *
     * La traduction elle-même appartient à MotifDeRefus : le fil de conversation
     * affiche désormais le MÊME motif quand la prose du modèle décrit un plan que
     * l'outil a refusé de préparer, et les deux ne doivent pas raconter deux
     * histoires du même refus.
     */
    private function motifRefus(AiToolResult $resultat): string
    {
        return MotifDeRefus::depuis($resultat);
    }

    /** Bandeau d'avancement affiché au-dessus de la barre de décision (et après un F5). */
    public function bandeau(AssistantProgramme $programme, AssistantProgrammeEtape $etape): array
    {
        return [
            'idProgramme'    => (int) $programme->getId(),
            'reference'      => (string) $programme->getReference(),
            'etapeReference' => (string) $etape->getReference(),
            'etapeLibelle'   => (string) $etape->getLibelle(),
            'position'       => $etape->getOrdre(),
            'total'          => $programme->nbEtapes(),
        ];
    }

    /** Prose déterministe de la bulle qui présente une étape. */
    private function prose(AssistantProgramme $programme, AssistantProgrammeEtape $etape): string
    {
        return sprintf(
            "**Programme %s — étape %d sur %d**\n\n%s\n\n"
            . 'Voici le plan de cette étape. Ce qui figure sous les boutons est la liste exacte de ce '
            . "qui sera enregistré ; validez pour que je l'exécute, ou passez cette étape — je "
            . 'continuerai la série et le rapport final dira ce qui a été fait et ce qui ne l’a pas été.',
            $programme->getReference(),
            $etape->getOrdre(),
            $programme->nbEtapes(),
            $etape->getLibelle(),
        );
    }
}
