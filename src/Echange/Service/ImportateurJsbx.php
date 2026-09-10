<?php

namespace App\Echange\Service;

use App\Echange\Classeur\ClasseurIllisibleException;
use App\Echange\Classeur\EcrivainJsbx;
use App\Echange\Classeur\LecteurJsbx;
use App\Echange\Etat\EtatDuPortefeuille;
use App\Echange\Reprise\LecteurDeLEtat;
use App\Entity\EchangeImportRun;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use App\Token\InsufficientTokensException;
use App\Token\TokenAccountService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * OUVRE ET REFERME UN DOSSIER D'IMPORTATION. Le travail, lui, se fait par paliers.
 *
 * ── UN SEUL FORMAT, ET C'EST LE GABARIT ─────────────────────────────────────────────
 * ⚠ CE SERVICE EN A LONGTEMPS ACCEPTÉ DEUX. À côté du classeur de reprise — une feuille
 * `DONNEES`, une ligne par échéance —, il relisait un format « normalisé » à une feuille
 * par entité, avec sa feuille d'identité, sa ligne de codes techniques masquée et ses
 * listes déroulantes.
 *
 * Or ce format n'était plus PRODUIT nulle part : l'écran ne servait plus que le gabarit,
 * et la route qui le distribuait ignorait délibérément le paramètre qui aurait rendu
 * l'autre. Seul l'import le comprenait encore. Il restait donc deux passes structurelles,
 * deux passes à blanc, deux lecteurs et deux jeux de règles à maintenir — pour un fichier
 * que plus personne ne pouvait obtenir, et une promesse d'écran que le code démentait.
 *
 * Un classeur sans feuille `DONNEES` est désormais refusé, avec un message qui dit quoi
 * faire : télécharger le classeur de reprise.
 *
 * ── LES TROIS TEMPS DE L'IMPORTATION ────────────────────────────────────────────────
 *  PASSE 1 — CE SERVICE. Le fichier est-il un classeur de reprise, vient-il de ce
 *            cabinet ? Un échec ici arrête tout, avec un message unique, et ne décompte
 *            AUCUNE occurrence : un fichier qu'on n'a pas su ouvrir n'a rien coûté.
 *
 *  PASSE 2 — {@see AvanceurDImport}, par paliers. Contrôle à blanc, obligatoire et
 *            gratuit : chaque ligne passe par le DRY-RUN du circuit d'écriture commun,
 *            celui-là même qui sert à l'écran et à l'assistant.
 *
 *  PASSE 3 — {@see AvanceurDImport} de nouveau, sur confirmation explicite.
 *
 * ⚠ POURQUOI LE TRAVAIL N'EST PAS ICI. Le contrôle à blanc retient plusieurs mégaoctets
 * par ligne sans les rendre : le tenir dans une requête imposait un plafond calculé sur
 * la mémoire du serveur, qui tombait à une trentaine de lignes. Ce service ouvre le
 * dossier et rend la main ; les paliers, poussés par le worker ou par le navigateur,
 * repartent chacun d'un processus neuf.
 */
final class ImportateurJsbx
{
    public function __construct(
        private readonly LecteurJsbx $lecteur,
        // Il ne sert plus qu'à deux questions : est-ce un classeur de reprise, et de quel
        // cabinet vient-il ? La lecture des lignes appartient aux paliers.
        private readonly LecteurDeLEtat $lecteurDeLEtat,
        private readonly EntityManagerInterface $em,
        private readonly ManagerRegistry $registre,
        // Le moteur des paliers. Cet orchestrateur ouvre et referme le dossier ; c'est lui
        // qui fait le travail, un lot de lignes à la fois.
        private readonly AvanceurDImport $avanceur,
        private readonly TokenAccountService $tokens,
    ) {
    }

    /**
     * DÉPÔT ET CONTRÔLE COMPLET, dans cette requête-ci.
     *
     * ⚠ C'EST LE CHEMIN DE CEUX QUI N'ONT NI NAVIGATEUR NI WORKER : l'assistant, les
     * commandes de diagnostic, les tests. L'écran, lui, dépose puis laisse les paliers
     * avancer — c'est la seule façon de tenir un portefeuille réel en mémoire.
     *
     * @param bool $confirmeAutreCabinet l'utilisateur a explicitement accepté d'importer
     *                                   un fichier issu d'un autre cabinet
     */
    public function controler(
        string $chemin,
        string $nomFichier,
        Entreprise $entreprise,
        Invite $invite,
        bool $suppressionsAutorisees = false,
        bool $confirmeAutreCabinet = false,
        ?Progression $progression = null,
    ): EchangeImportRun {
        $run = $this->deposer(
            $chemin,
            $nomFichier,
            $entreprise,
            $invite,
            $suppressionsAutorisees,
            $confirmeAutreCabinet,
            $progression,
        );

        return $this->avanceur->avancerJusquAuBout($run, $progression);
    }

    /**
     * PASSE 1 SEULE : ce fichier est-il recevable, et de qui vient-il ?
     *
     * N'écrit rien en base métier ; persiste le contrôle, son rapport, et l'état du
     * travail qui reste à faire. Un classeur de reprise en ressort au statut `CONTROLE`,
     * curseur à zéro : tout est prêt, rien n'est jugé.
     */
    public function deposer(
        string $chemin,
        string $nomFichier,
        Entreprise $entreprise,
        Invite $invite,
        bool $suppressionsAutorisees = false,
        bool $confirmeAutreCabinet = false,
        ?Progression $progression = null,
    ): EchangeImportRun {
        $progression ??= Progression::muette();
        $progression->etape('Lecture du fichier');
        $rapport = new RapportDeControle();

        $run = (new EchangeImportRun())
            ->setNomFichier($nomFichier)
            ->setCheminFichier($chemin)
            ->setEmpreinteFichier(is_file($chemin) ? hash_file('sha256', $chemin) : null)
            ->setStatut(EchangeImportRun::STATUT_CONTROLE)
            ->setSuppressionsAutorisees($suppressionsAutorisees)
            ->setExpireLe(new \DateTimeImmutable('+' . EchangeImportRun::DUREE_DE_VIE_HEURES . ' hours'));
        $run->setEntreprise($entreprise);
        $run->setInvite($invite);

        try {
            $classeur = $this->lecteur->ouvrir($chemin);
        } catch (ClasseurIllisibleException $e) {
            $rapport->ajouter(Anomalie::erreur(Anomalie::FICHIER_ILLISIBLE, $e->getMessage()));

            return $this->cloturer($run, $rapport, EchangeImportRun::STATUT_ECHEC);
        }

        if (!$this->estUnClasseurDeReprise($classeur, $rapport)) {
            return $this->cloturer($run, $rapport, EchangeImportRun::STATUT_ECHEC);
        }

        if (!$this->passeStructurelleDeLEtat($classeur, $entreprise, $rapport, $confirmeAutreCabinet)) {
            return $this->cloturer($run, $rapport, EchangeImportRun::STATUT_ECHEC);
        }

        // ⚠ ET C'EST TOUT, POUR CETTE REQUÊTE. Le contrôle à blanc ne tient pas dans une
        // requête — il retient plusieurs mégaoctets par ligne, sans les rendre. Il avance
        // par PALIERS, que le worker ou le navigateur pousseront. Le dépôt se contente
        // d'ouvrir le dossier : le fichier est un classeur de reprise, il vient de ce
        // cabinet, et le travail peut commencer.
        $run->setRapport($rapport->toArray());

        return $this->cloturer($run, $rapport, EchangeImportRun::STATUT_CONTROLE);
    }

    /**
     * LE FICHIER EST-IL UN CLASSEUR DE REPRISE ?
     *
     * ⚠ LE REFUS DOIT DIRE QUOI FAIRE. « Feuille DONNEES absente » est exact et inutile :
     * l'utilisateur ne sait pas qu'elle devrait s'y trouver, ni comment l'obtenir. Il
     * arrive le plus souvent avec un classeur à lui — un extrait de son ancien logiciel,
     * un tableau maison — et ce qui lui manque, c'est le gabarit.
     */
    private function estUnClasseurDeReprise(Spreadsheet $classeur, RapportDeControle $rapport): bool
    {
        if (LecteurDeLEtat::estUnClasseurDEtat($classeur)) {
            return true;
        }

        $rapport->ajouter(Anomalie::erreur(
            Anomalie::MANIFESTE_ABSENT,
            sprintf(
                'Ce fichier n\'est pas un classeur de reprise : il ne contient pas de feuille '
                . '« %s ». Téléchargez le classeur de reprise vierge depuis cet écran, remplissez-y '
                . 'une ligne par échéance de prime, puis déposez-le. Un export de vos données a '
                . 'exactement la même forme : il se redépose tel quel.',
                EtatDuPortefeuille::FEUILLE,
            ),
        ));

        return false;
    }

    /**
     * PASSE 1 : ce fichier vient-il bien de ce cabinet ?
     *
     * ⚠ IL N'Y A PAS DE FEUILLE D'IDENTITÉ À CONTRÔLER. Elle a été retirée du classeur
     * parce qu'elle ne disait rien au lecteur ; son identité utile — de quel cabinet vient
     * ce fichier — a rejoint le dictionnaire. Le contrôle, lui, reste le même et pour la
     * même raison : les identifiants d'un autre cabinet ne désignent rien ici, et toutes
     * les lignes seraient créées en double.
     *
     * ⚠ UN FICHIER SANS IDENTITÉ N'EST PAS REFUSÉ. Un gabarit rempli hors ligne, un
     * classeur recomposé à la main : ils n'ont pas d'identifiant de cabinet, et c'est
     * légitime. On ne peut alors rien vérifier, donc on ne prétend rien.
     */
    private function passeStructurelleDeLEtat(
        Spreadsheet $classeur,
        Entreprise $entreprise,
        RapportDeControle $rapport,
        bool $confirmeAutreCabinet,
    ): bool {
        $cabinet = $this->lecteurDeLEtat->cabinet($classeur);

        if ($cabinet !== '' && $cabinet !== (string) $entreprise->getId()) {
            if (!$confirmeAutreCabinet) {
                $rapport->ajouter(Anomalie::erreur(
                    Anomalie::AUTRE_CABINET,
                    'Ce fichier a été produit par un AUTRE cabinet. Les identifiants qu\'il '
                    . 'contient ne désignent rien ici, et chaque ligne serait créée en double. '
                    . 'Confirmez explicitement si c\'est bien une reprise de données voulue.',
                    EcrivainJsbx::FEUILLE_DICTIONNAIRE,
                ));

                return false;
            }

            $rapport->ajouter(Anomalie::avertissement(
                Anomalie::AUTRE_CABINET,
                'Reprise assumée depuis un autre cabinet : les identifiants du fichier ne '
                . 'désignant rien ici, les lignes sont rattachées par leurs libellés.',
                EcrivainJsbx::FEUILLE_DICTIONNAIRE,
            ));
        }

        return true;
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Écriture
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * ÉCRITURE COMPLÈTE, dans cette requête-ci — même public que `controler()`.
     *
     * @throws ImportImpossibleException si le contrôle n'est plus exécutable
     */
    public function executer(EchangeImportRun $run, ?Utilisateur $acteur, ?Progression $progression = null): EchangeImportRun
    {
        $progression ??= Progression::muette();
        $run = $this->demarrerLEcriture($run, $acteur, $progression);

        return $this->avanceur->avancerJusquAuBout($run, $progression);
    }

    /**
     * OUVRE L'ÉCRITURE et rend la main — le pendant de `deposer()` pour la passe 3.
     *
     * Le contrôle en ressort au statut `EN_COURS`, curseur à zéro : les paliers feront le
     * travail, poussés par le worker ou par le navigateur.
     *
     * ⚠ L'ORIGINE ÉTRANGÈRE A DÉJÀ ÉTÉ ASSUMÉE AU DÉPÔT : on ne redemande pas une
     * confirmation qui a été donnée une fois.
     *
     * @throws ImportImpossibleException si le contrôle n'est plus exécutable
     */
    public function demarrerLEcriture(EchangeImportRun $run, ?Utilisateur $acteur, ?Progression $progression = null): EchangeImportRun
    {
        $progression ??= Progression::muette();
        $progression->etape('Vérification du fichier');
        $entreprise = $run->getEntreprise();

        if (!$run->estConfirmable()) {
            throw new ImportImpossibleException(
                'Ce contrôle n\'est plus en attente de décision : il a expiré, a déjà été exécuté, ou a été '
                . 'annulé. Redéposez le fichier pour repartir d\'un contrôle à jour.',
            );
        }
        if ($entreprise === null || $run->getInvite() === null) {
            throw new ImportImpossibleException('Ce contrôle n\'est rattaché à aucun cabinet identifiable.');
        }

        $chemin = (string) $run->getCheminFichier();
        if (!is_file($chemin)) {
            throw new ImportImpossibleException(
                'Le fichier déposé n\'est plus disponible sur le serveur. Redéposez-le pour relancer le contrôle.',
            );
        }

        $rapport = new RapportDeControle();
        $classeur = $this->lecteur->ouvrir($chemin);

        if (!$this->estUnClasseurDeReprise($classeur, $rapport)
            || !$this->passeStructurelleDeLEtat($classeur, $entreprise, $rapport, true)) {
            return $this->echouer($run, $rapport);
        }

        // ⚠ LE GARDE-FOU DES SUPPRESSIONS SE LIT SUR LE RAPPORT DU CONTRÔLE, et non sur un
        // recontrôle : c'est le contrôle qui a compté ce que le fichier demande, et
        // l'utilisateur a confirmé SUR CE RAPPORT-LÀ. Passées sous silence, il croirait ses
        // lignes supprimées.
        $controle = RapportDeControle::depuisArray($run->getRapport());
        if ($controle->nbSuppressions() > 0 && !$run->isSuppressionsAutorisees()) {
            $controle->ajouter(Anomalie::erreur(
                Anomalie::SUPPRESSION_REFUSEE,
                sprintf(
                    'Ce fichier demande %d suppression(s) alors qu\'elles n\'ont pas été autorisées '
                    . 'au dépôt. Redéposez-le en cochant explicitement l\'autorisation de supprimer.',
                    $controle->nbSuppressions(),
                ),
            ));

            return $this->echouer($run, $controle);
        }

        // ── LE VERROU RÉEL DU BUDGET ────────────────────────────────────────────────
        //
        // ⚠ UN BOUTON DÉSACTIVÉ N'EST PAS UNE GARDE. La route de confirmation est
        // appelable directement, et le solde a pu fondre entre l'annonce et l'accord —
        // un autre onglet, un import parallèle. On revérifie donc ICI, en dernier
        // recours, contre le coût FIGÉ dans le rapport.
        //
        // ⚠ ET ON LIT LE RAPPORT AVANT LE `clear()` QUI SUIT : après lui, `$run` n'est
        // plus géré et sa colonne JSON n'est plus lisible.
        $tokensEstimes = (int) ($run->getRapport()['tokens_estimes'] ?? 0);
        $entreprise = $run->getEntreprise();
        if ($tokensEstimes > 0 && $entreprise !== null) {
            $disponible = $this->tokens->availableFor($entreprise);
            if ($disponible < $tokensEstimes) {
                $proprietaire = $entreprise->getUtilisateur();

                throw new InsufficientTokensException(
                    required: $tokensEstimes,
                    available: $disponible,
                    nextRenewalAt: $proprietaire instanceof Utilisateur
                        ? $this->tokens->nextRenewalAt($proprietaire)
                        : null,
                );
            }
        }

        // ⚠ ON REPART D'UNE UNITÉ DE TRAVAIL PROPRE. Le contrôle à blanc valide en
        // soumettant le formulaire de chaque entité sur une COPIE ; un formulaire à
        // collection fabrique au passage des enfants rattachés à cette copie — inoffensifs
        // tant que rien ne flushe, mais l'écriture, elle, flushe : Doctrine découvrirait
        // « une entité nouvelle atteinte par… » et refuserait tout le lot, y compris les
        // lignes irréprochables.
        $idRun = (int) $run->getId();
        $this->em->clear();
        $run = $this->em->find(EchangeImportRun::class, $idRun)
            ?? throw new ImportImpossibleException('Le contrôle a disparu en cours d\'exécution.');

        // Le curseur repart de zéro : ce n'est plus la même phase, et le rang des lignes
        // contrôlées ne dit rien de celles qui restent à écrire.
        $run->setStatut(EchangeImportRun::STATUT_EN_COURS);
        $run->setCurseur(0);
        $this->em->flush();

        return $run;
    }

    /** Annule un contrôle en attente. Gratuit, et sans le moindre effet sur les données. */
    public function annuler(EchangeImportRun $run): EchangeImportRun
    {
        if (in_array($run->getStatut(), [EchangeImportRun::STATUT_TERMINE, EchangeImportRun::STATUT_ANNULE], true)) {
            return $run;
        }

        $run->setStatut(EchangeImportRun::STATUT_ANNULE);

        $chemin = $run->getCheminFichier();
        if ($chemin !== null && is_file($chemin)) {
            @unlink($chemin);
        }
        $run->setCheminFichier(null);
        $this->em->flush();

        return $run;
    }

    private function cloturer(EchangeImportRun $run, RapportDeControle $rapport, string $statut): EchangeImportRun
    {
        $run->setStatut($statut);
        if ($run->getRapport() === []) {
            $run->setRapport($rapport->toArray());
        }

        $this->em->persist($run);
        $this->em->flush();

        return $run;
    }

    /**
     * Consigne l'échec — et y parvient MÊME quand Doctrine a fermé son gestionnaire.
     *
     * ⚠ Doctrine ferme son gestionnaire dès qu'une exception traverse un flush. Le
     * rollback a bien protégé les données, mais tout appel suivant lève « The
     * EntityManager is closed » : le chemin d'erreur explosait donc à son tour, et
     * l'utilisateur recevait une erreur fatale à la place du rapport qui lui aurait dit
     * quoi corriger. On rouvre un gestionnaire, on y rattache le contrôle, et on écrit ce
     * que l'on sait.
     *
     * Le pire cas — même la réouverture échoue — laisse le contrôle en base tel qu'il
     * était : jamais un statut « terminé » mensonger.
     */
    private function echouer(EchangeImportRun $run, RapportDeControle $rapport): EchangeImportRun
    {
        $run->setStatut(EchangeImportRun::STATUT_ECHEC);
        $run->setRapport($rapport->toArray());

        try {
            $em = $this->em;
            if (!$em->isOpen()) {
                $this->registre->resetManager();
                $em = $this->registre->getManager();
                // Le contrôle vient d'un gestionnaire mort : on le rapatrie dans le neuf.
                $run = $em->merge($run);
            }
            $em->flush();
        } catch (\Throwable) {
            // Rien de plus à tenter : les données sont saines (rollback), et le statut en
            // base reste celui d'avant l'écriture, donc jamais « terminé ».
        }

        return $run;
    }
}
