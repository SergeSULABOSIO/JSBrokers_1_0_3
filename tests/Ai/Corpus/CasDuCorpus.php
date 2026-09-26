<?php

namespace App\Tests\Ai\Corpus;

use App\Ai\Trousse\Trousse;

/**
 * UN CAS DU CORPUS DE RÉFÉRENCE : une question réelle, et ce qu'elle DEVAIT produire.
 *
 * ── CE QUE CE N'EST PAS ──────────────────────────────────────────────────────────
 *
 * Ce n'est pas une assertion. Un cas dit ce qu'on attend ; il ne dit pas que le code
 * l'obtient. L'écart entre les deux EST la mesure du chantier — le figer en test
 * rendrait vert un indicateur qu'on cherche justement à faire monter, et masquerait
 * la seule chose qu'on veut voir.
 *
 * Seules les invariants de STRUCTURE sont assertés (cf. CorpusFigeTest) : l'outil
 * attendu existe, un cas d'écriture n'attend que des outils d'écriture, aucune donnée
 * réelle n'a survécu. Le taux de bonne réponse, lui, s'imprime — il ne se verrouille pas.
 */
final class CasDuCorpus
{
    /**
     * @param string       $libelle  identifiant lisible du cas, stable : les rapports avant/après
     *                               se comparent ligne à ligne sur cette clé
     * @param string       $question la question, ANONYMISÉE (cf. CorpusDeReference)
     * @param list<string> $outils   les outils attendus ; vide = aucun outil ne doit partir
     * @param Trousse      $trousse  la trousse qui aurait dû être armée
     * @param string       $famille  le type de question, pour le classement du chantier G
     * @param string       $contexte l'état du fil AVANT la question — décisif pour les
     *                               relances (« essaie encore » n'a de sens que par ce qui
     *                               précède). Une des valeurs de self::CONTEXTES.
     * @param string       $note     pourquoi ce cas mérite sa place. Vide quand il est ordinaire.
     */
    public function __construct(
        public readonly string $libelle,
        public readonly string $question,
        public readonly array $outils,
        public readonly Trousse $trousse,
        public readonly string $famille,
        public readonly string $contexte = self::CONTEXTE_AUCUN,
        public readonly string $note = '',
    ) {
    }

    // ── Contextes ───────────────────────────────────────────────────────────────
    //
    // Les quatre derniers correspondent aux signaux STRUCTURELS de SelecteurDeTrousse.
    // Ils ne sont pas décoratifs : sur les relances (« ok », « vas y », « la suivante »),
    // le contexte décide seul, et les traiter tous comme « aucun » ferait mesurer une
    // ambiguïté qui n'existe pas dans la vraie conversation.

    public const CONTEXTE_AUCUN = 'aucun';
    public const CONTEXTE_PLAN_EN_ATTENTE = 'plan-en-attente';
    public const CONTEXTE_PROGRAMME_EN_COURS = 'programme-en-cours';
    public const CONTEXTE_DERNIER_TOUR_A_ECRIT = 'dernier-tour-a-ecrit';
    public const CONTEXTE_A_PROPOSE_D_ECRIRE = 'a-propose-d-ecrire';
    public const CONTEXTE_PIECE_JOINTE = 'piece-jointe';

    public const CONTEXTES = [
        self::CONTEXTE_AUCUN,
        self::CONTEXTE_PLAN_EN_ATTENTE,
        self::CONTEXTE_PROGRAMME_EN_COURS,
        self::CONTEXTE_DERNIER_TOUR_A_ECRIT,
        self::CONTEXTE_A_PROPOSE_D_ECRIRE,
        self::CONTEXTE_PIECE_JOINTE,
    ];

    // ── Familles ────────────────────────────────────────────────────────────────
    //
    // Le classement du chantier G. Mesuré sur 826 paires réelles : six familles de
    // LECTURE couvrent 79,3 % des lectures, et les trois premières 59,3 %. Ce sont
    // elles qu'un routage direct supprimerait du tour de planification.

    /** « la liste des polices échues », « avons-nous un client nommé X ? » */
    public const LISTE = 'liste';
    /** « les primes impayées », « les tranches à commission encaissée » */
    public const IMPAYES = 'impayes';
    /** « top 5 de nos clients », « la répartition des primes par risque » */
    public const CLASSEMENT = 'classement';
    /** « les détails de la piste 42 », « tous ses détails » */
    public const FICHE = 'fiche';
    /** « les fichiers du client X », « ce document contient-il un fichier ? » */
    public const FICHIERS = 'fichiers';
    /** « comment se calcule la rétrocommission ? », « c'est quoi un bordereau ? » */
    public const GUIDE = 'guide';
    /** Toute demande d'enregistrement, de correction ou de suppression. */
    public const ECRITURE = 'ecriture';
    /** « ouvre le formulaire d'édition », « ferme la rubrique » — une action d'interface. */
    public const ECRAN = 'ecran';
    /** « produis-moi un rapport en HTML à partir de ceci ». */
    public const DOCUMENT = 'document';
    /** Conversation pure : acquiescement, remerciement, salutation, remise en forme. */
    public const AUCUN = 'aucun';
    /** Relance dont le sens dépend ENTIÈREMENT du fil : « essaie encore », « la suivante ». */
    public const AMBIGU = 'ambigu';

    public const FAMILLES = [
        self::LISTE, self::IMPAYES, self::CLASSEMENT, self::FICHE, self::FICHIERS,
        self::GUIDE, self::ECRITURE, self::ECRAN, self::DOCUMENT, self::AUCUN, self::AMBIGU,
    ];
}
