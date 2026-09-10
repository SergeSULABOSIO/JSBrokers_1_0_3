<?php

namespace App\Echange\Service;

use App\Ai\Mutation\MutationOperation;
use App\Ai\Mutation\MutationReferences;
use App\Ai\Scope\AiScope;
use App\Echange\Classeur\LecteurJsbx;
use App\Echange\Classeur\LigneLue;
use App\Echange\Etat\EtatDuPortefeuille;
use App\Echange\Reprise\ChaineExistante;
use App\Echange\Reprise\CoherenceDesParts;
use App\Echange\Reprise\LecteurDeLEtat;
use App\Echange\Reprise\ReconstitueurDeTranche;
use App\Entity\EchangeImportRun;
use App\Entity\EchangeOccurrence;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Repository\EchangeImportRunRepository;
use App\Service\Workspace\WorkspaceMutationService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * FAIT AVANCER UN IMPORT D'UN PALIER, et rend la main.
 *
 * ── POURQUOI UN IMPORT NE PEUT PAS TENIR DANS UNE REQUÊTE ───────────────────────────
 * Le contrôle à blanc soumet chaque ligne au circuit d'écriture commun — mêmes droits,
 * mêmes champs obligatoires, même validation par le formulaire qu'une saisie à l'écran.
 * C'est ce qui garantit qu'un import obéit aux mêmes règles, et c'est aussi ce qui coûte
 * cher : construire un arbre de formulaires retient environ sept mégaoctets par ligne, et
 * cette empreinte ne redescend pas.
 *
 * ⚠ D'OÙ UN PLAFOND QUI REFUSAIT LE SEUL CAS UTILE. Calculé sur la mémoire du serveur, il
 * tombait à une trentaine de lignes : un cabinet qui reprend son portefeuille en dépose
 * des centaines. Et le message trompait, car PHP mourait au milieu d'une requête — la
 * connexion tombait, et l'écran accusait « MySQL server has gone away ».
 *
 * Le travail avance donc par PALIERS. Chacun repart d'un processus neuf — un message du
 * worker, ou une requête du navigateur —, si bien que la rétention ne s'accumule plus. Le
 * plafond cesse d'être une limite de fichier pour devenir une taille de lot.
 *
 * ── UN PALIER NE COUPE JAMAIS UNE POLICE EN DEUX ────────────────────────────────────
 * ⚠ C'EST LA RÈGLE QUI REND LES PALIERS SÛRS. Les échéances d'une même police se chaînent
 * par des repères « @étiquette » : la deuxième renvoie à la proposition que la première a
 * créée. Ces repères vivent le temps d'un palier. Séparer deux échéances d'une police,
 * ce serait faire échouer la seconde sur « renvoi inconnu » — un motif qui parle du lien
 * et non de la cause.
 *
 * Les lignes sont donc REGROUPÉES par police avant d'être découpées, et un palier
 * s'étend jusqu'à la fin du groupe entamé. L'ordre du fichier n'en souffre pas :
 * l'utilisateur a toujours eu le droit de le trier.
 *
 * ── ET CHAQUE PALIER EST UNE TRANSACTION ────────────────────────────────────────────
 * Il n'y a plus de « tout ou rien » à l'échelle du fichier : une erreur à la deux
 * millième ligne n'annule plus les mille neuf cent quatre-vingt-dix-neuf premières. Ce
 * qui n'est acceptable que parce que la reprise est devenue IDEMPOTENTE
 * ({@see ChaineExistante}) : redéposer le fichier après un échec reprend là où il faut,
 * sans rien dupliquer.
 */
final class AvanceurDImport
{
    /**
     * Ce qu'une échéance coûte au contrôle, en mémoire non rendue.
     *
     * ⚠ MESURÉ, ET MAJORÉ. Sept mégaoctets par ligne sur un portefeuille réel. On compte
     * huit : un plafond optimiste ne protège de rien.
     */
    private const MEMOIRE_PAR_LIGNE = 8 * 1024 * 1024;

    /** En deçà, la rubrique cesserait d'être utilisable, et le vrai remède est ailleurs. */
    private const PALIER_MINIMAL = 10;

    /** Au-delà, un palier deviendrait la requête interminable qu'on cherche à supprimer. */
    private const PALIER_MAXIMAL = 200;

    /**
     * LA BORNE ABSOLUE D'UN FICHIER — un garde-fou, plus un plafond de travail.
     *
     * ⚠ ELLE N'A RIEN À VOIR AVEC L'ANCIENNE LIMITE DE DEUX MILLE LIGNES. Celle-là
     * protégeait du TEMPS d'une requête unique, et se doublait d'une limite de mémoire qui
     * tombait à une trentaine de lignes. Le travail avançant par paliers, ni l'une ni
     * l'autre n'a plus lieu d'être : un portefeuille de dix mille échéances se reprend,
     * simplement il y faut plus de paliers.
     *
     * Ce qui reste est un refus de l'absurde — un fichier de plusieurs centaines de
     * milliers de lignes n'est pas un portefeuille de courtier, c'est une erreur de
     * manipulation, et il vaut mieux le dire tout de suite.
     */
    private const LIGNES_MAXIMALES = 50000;

    public function __construct(
        private readonly LecteurJsbx $lecteur,
        private readonly LecteurDeLEtat $lecteurDeLEtat,
        private readonly EtatDuPortefeuille $etat,
        private readonly ReconstitueurDeTranche $reconstitueur,
        private readonly WorkspaceMutationService $mutation,
        private readonly DiagnosticEnAnomalie $diagnostic,
        private readonly CompteurDOccurrences $compteur,
        private readonly FranchiseDeReprise $franchise,
        private readonly EchangeImportRunRepository $runs,
        private readonly EntityManagerInterface $em,
        // ⚠ POUR ROUVRIR CE QUE DOCTRINE FERME. Une exception qui traverse un flush ferme
        // le gestionnaire : le rollback a bien protégé les données, mais tout appel
        // suivant lève « The EntityManager is closed ». Le chemin d'erreur explosait donc
        // à son tour, et l'utilisateur recevait une erreur fatale à la place du rapport
        // qui lui aurait dit quoi corriger.
        private readonly ManagerRegistry $registre,
        /**
         * LA TAILLE DU PALIER, IMPOSÉE — zéro laisse le calcul décider.
         *
         * ⚠ CE N'EST PAS UNE TRAPPE DE TEST, c'est un réglage d'exploitation. Le calcul
         * déduit le lot de `memory_limit`, ce qui est juste pour la mémoire et muet sur
         * le reste : une base lente, un worker partagé, un serveur qu'on ne veut pas voir
         * occupé trente secondes d'affilée sont autant de raisons de vouloir des paliers
         * plus courts, et aucune ne se lit dans une limite de mémoire.
         */
        #[Autowire('%env(int:IMPORT_PALIER)%')]
        private readonly int $palierImpose = 0,
    ) {
    }

    /**
     * COMBIEN DE LIGNES CE SERVEUR PEUT TENIR EN UN PALIER.
     *
     * ⚠ LE CHIFFRE SE CALCULE, IL NE SE DÉCRÈTE PAS. Un nombre en dur serait faux sur deux
     * serveurs différents : trop bas sur une machine généreuse, on multiplierait les
     * allers-retours pour rien ; trop haut sur une machine contrainte, on retomberait sur
     * la mort en cours de route que ce découpage existe pour éviter.
     *
     * ⚠ ET LA MOITIÉ SEULEMENT EST ENGAGÉE. Le classeur ouvert, le noyau et Doctrine
     * occupent déjà leur part avant la première ligne. Viser la limite entière, ce serait
     * la dépasser.
     */
    public function tailleDuPalier(): int
    {
        if ($this->palierImpose > 0) {
            return min($this->palierImpose, self::PALIER_MAXIMAL);
        }

        $limite = $this->memoireDisponible();

        // Sans limite déclarée (« -1 »), le serveur dit qu'il assume : on prend le palier
        // le plus large plutôt que de lui opposer un chiffre inventé.
        if ($limite <= 0) {
            return self::PALIER_MAXIMAL;
        }

        $tenable = (int) floor(($limite * 0.5) / self::MEMOIRE_PAR_LIGNE);

        return max(self::PALIER_MINIMAL, min($tenable, self::PALIER_MAXIMAL));
    }

    /**
     * FAIT AVANCER LE TRAVAIL D'UN PALIER — contrôle ou écriture, selon où il en est.
     *
     * Rend le contrôle tel qu'il est après ce palier. `resteAFaire()` dit s'il faut
     * rappeler ; le statut dit si c'est encore la peine.
     */
    public function avancerUnPalier(EchangeImportRun $run): EchangeImportRun
    {
        if ($run->getId() === null || !$this->travaille($run)) {
            return $run;
        }

        // ⚠ UN SEUL PALIER À LA FOIS SUR UN MÊME CONTRÔLE. Deux pousseurs — deux onglets,
        // un double-clic, deux workers — traiteraient la même fenêtre de lignes dans deux
        // transactions séparées, et créeraient chacun « son » client : l'idempotence ne
        // voit que ce qui est commité. Celui qui n'obtient pas le verrou rend simplement
        // la main ; ce n'est pas une erreur, c'est un autre qui travaille.
        if (!$this->runs->prendreLeTravail((int) $run->getId())) {
            return $run;
        }

        try {
            return $this->travailler($run);
        } finally {
            // ⚠ TOUJOURS, MÊME SUR ÉCHEC. Un verrou oublié gèle le contrôle jusqu'à sa
            // péremption, et l'utilisateur ne comprendrait pas pourquoi son import ne
            // repart plus.
            $this->runs->relacherLeTravail((int) $run->getId());
        }
    }

    /** Le palier lui-même, une fois le verrou obtenu. */
    private function travailler(EchangeImportRun $run): EchangeImportRun
    {
        $entreprise = $run->getEntreprise();
        $invite = $run->getInvite();
        $chemin = (string) $run->getCheminFichier();

        if ($entreprise === null || $invite === null) {
            return $run;
        }

        if (!is_file($chemin)) {
            return $this->echouer($run, Anomalie::erreur(
                Anomalie::FICHIER_ILLISIBLE,
                'Le fichier déposé n\'est plus disponible sur le serveur : le travail ne peut pas '
                . 'reprendre. Redéposez-le pour relancer un contrôle.',
            ));
        }

        $classeur = $this->lecteur->ouvrir($chemin);
        $lignes = $this->lignesGroupees($classeur, $entreprise);

        // Le volume n'est connu qu'après la première lecture — et il est REVÉRIFIÉ à
        // chaque palier : un total qui aurait bougé signifierait que le fichier a changé
        // sous nos pieds, et le curseur ne désignerait plus la même ligne.
        if ($run->getTotalLignes() !== count($lignes)) {
            $run->setTotalLignes(count($lignes));
        }

        if ($lignes === []) {
            return $this->echouer($run, Anomalie::erreur(
                Anomalie::MANIFESTE_ABSENT,
                sprintf(
                    'La feuille « %s » ne contient aucune ligne à reprendre. Si vous partez d\'un '
                    . 'gabarit, remplissez-y une ligne par échéance de prime avant de le déposer.',
                    EtatDuPortefeuille::FEUILLE,
                ),
                EtatDuPortefeuille::FEUILLE,
            ));
        }

        if (count($lignes) > self::LIGNES_MAXIMALES) {
            return $this->echouer($run, Anomalie::erreur(
                Anomalie::PLAFOND_DEPASSE,
                sprintf(
                    'Ce fichier porte %d lignes, bien au-delà de ce qu\'un portefeuille de courtier '
                    . 'compte d\'échéances (%d au maximum). Vérifiez que c\'est bien le classeur que '
                    . 'vous vouliez déposer.',
                    count($lignes),
                    self::LIGNES_MAXIMALES,
                ),
                EtatDuPortefeuille::FEUILLE,
            ));
        }

        $fenetre = $this->fenetre($lignes, $run->getCurseur(), $this->tailleDuPalier());
        if ($fenetre === []) {
            return $this->clore($run);
        }

        // ⚠ LES INDEX REPARTENT DE ZÉRO À CHAQUE PALIER, et c'est indispensable. Le palier
        // précédent vient peut-être d'écrire des polices : un index resté tiède les
        // ferait recréer par celui-ci.
        //
        // L'invité voyage avec : c'est lui qui répondra des portefeuilles créés en chemin,
        // information que le classeur ne porte pas et sans laquelle ils ne peuvent naître.
        $this->reconstitueur->reinitialiser($invite);

        $rapport = RapportDeControle::depuisArray($run->getRapport());
        $rapport->declarerRessource(LecteurDeLEtat::RESSOURCE, 'Échéances de prime');

        $aboutit = $run->getStatut() === EchangeImportRun::STATUT_CONTROLE
            ? $this->controlerLaFenetre($fenetre, $entreprise, $invite, $rapport)
            : $this->ecrireLaFenetre($fenetre, $entreprise, $invite, $rapport, $run);

        $curseur = $run->getCurseur() + count($fenetre);

        // ⚠ L'ÉCHEC SE CONSIGNE À PART, parce que Doctrine a pu fermer son gestionnaire.
        // Toucher `$run` ici lèverait « The EntityManager is closed », et l'utilisateur
        // recevrait une erreur fatale à la place du rapport qui lui dit quoi corriger.
        if (!$aboutit) {
            return $this->consignerEchec((int) $run->getId(), $rapport, $curseur, $run->getTotalLignes());
        }

        $run->setCurseur($curseur);
        $run->setRapport($rapport->toArray() + $this->supplement($run));
        $this->em->flush();

        return $run->resteAFaire() ? $run : $this->clore($run);
    }

    /**
     * POUSSE LE TRAVAIL JUSQU'AU BOUT, dans ce processus-ci.
     *
     * ⚠ C'EST LE MÊME MOTEUR, avec un pousseur de plus — jamais un second chemin. Il sert
     * aux commandes de diagnostic, aux tests, et à l'assistant, qui n'ont pas de
     * navigateur pour rappeler et pas de worker à attendre. La rétention mémoire, elle,
     * n'est pas rendue par un palier qui se termine : sur un gros fichier, seule la voie
     * asynchrone protège vraiment.
     */
    public function avancerJusquAuBout(EchangeImportRun $run, ?Progression $progression = null): EchangeImportRun
    {
        $progression ??= Progression::muette();

        while ($this->travaille($run)) {
            $avant = $run->getCurseur();
            // ⚠ ON RÉASSIGNE, et ce n'est pas une coquetterie : un palier qui échoue rouvre
            // le gestionnaire que Doctrine a fermé et RECHARGE le contrôle. L'objet d'avant
            // est alors détaché, figé sur l'état d'avant l'échec — la boucle lirait un
            // curseur qui n'a pas bougé, conclurait au blocage et rendrait la main en
            // laissant le statut « en cours », c'est-à-dire un import qui paraît suspendu
            // alors qu'il a échoué et l'a dit.
            $run = $this->avancerUnPalier($run);

            $progression->totaliser($run->getTotalLignes());
            $progression->avancer(max(0, $run->getCurseur() - $avant));

            // Un palier qui n'avance pas ne le fera pas davantage au suivant : mieux vaut
            // rendre la main que tourner sans fin sur un fichier qui ne se lit plus.
            if ($run->getCurseur() === $avant) {
                break;
            }
        }

        return $run;
    }

    /**
     * LE TRAVAIL D'UN PALIER DE CONTRÔLE : traduire, juger, n'écrire rien.
     *
     * ⚠ UNE LIGNE EST ATOMIQUE. Si l'une de ses opérations est refusée, on écarte TOUTE la
     * ligne : écrire le client et la proposition en abandonnant l'échéance laisserait un
     * dossier à moitié repris, que le rapport annoncerait comme un succès.
     *
     * @param LigneLue[] $fenetre
     *
     * @return bool faux = le palier n'a pas abouti et le travail s'arrête
     */
    private function controlerLaFenetre(array $fenetre, Entreprise $entreprise, Invite $invite, RapportDeControle $rapport): bool
    {
        $scope = new AiScope($entreprise, $invite, null);
        $refs = MutationReferences::dryRun();
        $colonnes = $this->etat->colonnes($entreprise);

        // ⚠ LA COHÉRENCE D'UNE POLICE NE SE VOIT PAS LIGNE À LIGNE. Les parts d'échéance ne
        // se jugent qu'ENSEMBLE — quatre lignes à cent pour cent sont chacune plausible, et
        // leur somme absurde. La fenêtre est justement l'endroit où c'est possible : une
        // police y tient toujours entière, c'est ce que garantit `fenetre()`.
        $reprochesDeGroupe = [];
        foreach (CoherenceDesParts::verifier($fenetre) as $reproche) {
            $reprochesDeGroupe[(int) $reproche->ligne][] = $reproche;
        }

        foreach ($fenetre as $ligne) {
            $anomalies = $reprochesDeGroupe[$ligne->numero] ?? [];
            $operations = $this->reconstitueur->pour($ligne, $colonnes, $entreprise, $anomalies);
            $rapport->compterLignes(1);

            // ⚠ SEULE UNE ERREUR ÉCARTE LA LIGNE. Un avertissement dit quelque chose
            // d'utile — « plusieurs types portent ce nom, le premier a été retenu » — sans
            // empêcher la reprise.
            $refuse = false;
            foreach ($anomalies as $anomalie) {
                if ($anomalie->gravite === Anomalie::ERREUR) {
                    $refuse = true;
                    break;
                }
            }

            $retenues = [];

            foreach ($operations as $operation) {
                if (!$operation->isDelete() && !$operation->ecritQuelqueChose()) {
                    continue;
                }

                $diagnostic = $this->mutation->analyserOperation($operation, $scope, $refs);
                if (!$diagnostic['ok']) {
                    // ⚠ LE CATALOGUE SUIT, et c'est lui qui rend le reproche utilisable :
                    // il permet de retrouver la COLONNE du classeur qui écrit le champ
                    // manquant. Sans elle, l'utilisateur lit « ligne 2 » et doit parcourir
                    // soixante colonnes — et le classeur annoté ne surligne rien, faute de
                    // cellule à désigner.
                    $this->diagnostic->signaler(
                        $diagnostic,
                        $ligne,
                        $operation->entityShortName,
                        LecteurDeLEtat::RESSOURCE,
                        $rapport,
                        $colonnes,
                    );
                    $refuse = true;
                    break;
                }

                // Le repère n'est déclaré qu'une fois l'opération jugée VALIDE : y renvoyer
                // alors qu'elle ne sera jamais écrite produirait une seconde erreur, en
                // cascade, qui masquerait la première — la seule qu'il faille corriger.
                if ($operation->ref !== null) {
                    $refs->declarer($operation->ref);
                }

                $retenues[] = $operation;
            }

            foreach ($anomalies as $anomalie) {
                $rapport->ajouter($anomalie);
            }

            if ($refuse) {
                $rapport->compterErreur(LecteurDeLEtat::RESSOURCE);
                $this->oublierLesReperes($operations);

                continue;
            }

            foreach ($retenues as $operation) {
                $rapport->compter(LecteurDeLEtat::RESSOURCE, $operation->op);
            }
        }

        // Un contrôle ne s'arrête jamais en route : son objet est justement de dresser la
        // liste COMPLÈTE de ce qui bloque. Il aboutit toujours ; c'est son rapport qui
        // décide si la confirmation s'ouvre.
        return true;
    }

    /**
     * LE TRAVAIL D'UN PALIER D'ÉCRITURE : une transaction, et ce qu'elle porte.
     *
     * ⚠ AUCUN SECOND CONTRÔLE À BLANC ICI. `executer()` valide déjà par le formulaire et
     * lève si quelque chose cloche : refaire le dry-run construirait un second arbre de
     * formulaires par ligne, doublerait le coût mémoire du palier, et jugerait exactement
     * la même chose.
     *
     * @param LigneLue[] $fenetre
     */
    private function ecrireLaFenetre(array $fenetre, Entreprise $entreprise, Invite $invite, RapportDeControle $rapport, EchangeImportRun $run): bool
    {
        $scope = new AiScope($entreprise, $invite, null);
        $acteur = $invite->getUtilisateur();
        $colonnes = $this->etat->colonnes($entreprise);

        // ⚠ LE SOLDE DE FRANCHISE SE LIT UNE FOIS PAR PALIER, PAS UNE FOIS PAR LIGNE. Une
        // requête par écriture serait ruineuse ; et surtout le compteur du run n'est flushé
        // qu'en fin de transaction, si bien qu'une relecture en cours de palier rendrait une
        // valeur périmée — donc un décompte qui dérive.
        $couvertes = $this->franchise->couvertesDansCePalier(
            $entreprise,
            $run->getLignesFranchisees(),
            count($fenetre),
        );
        $offertesCePalier = 0;

        try {
            $this->em->wrapInTransaction(function () use ($fenetre, $colonnes, $scope, $acteur, $entreprise, $couvertes, &$offertesCePalier): void {
                $refs = MutationReferences::live();
                $rang = 0;

                foreach ($fenetre as $ligne) {
                    ++$rang;
                    $anomalies = [];
                    $operations = $this->reconstitueur->pour($ligne, $colonnes, $entreprise, $anomalies);

                    foreach ($anomalies as $anomalie) {
                        if ($anomalie->gravite === Anomalie::ERREUR) {
                            throw new ImportImpossibleException(sprintf(
                                'Ligne %d : %s',
                                $ligne->numero,
                                $anomalie->message,
                            ));
                        }
                    }

                    // ⚠ LA FRANCHISE EXONÈRE, ELLE N'AJOUTE PAS DE FORFAIT. L'import débite
                    // déjà, entité par entité, par le circuit d'écriture commun : facturer
                    // un prix au-delà du seuil ferait payer deux fois le même geste. On
                    // coupe donc le métrage sur les lignes offertes, et on le laisse faire
                    // sur les suivantes — au tarif d'écriture ordinaire, sans rien inventer.
                    $offerte = $rang <= $couvertes;

                    foreach ($operations as $operation) {
                        if (!$operation->isDelete() && !$operation->ecritQuelqueChose()) {
                            continue;
                        }
                        $this->mutation->executer($operation, $scope, $acteur, $refs, metrer: !$offerte);
                    }

                    if ($offerte) {
                        ++$offertesCePalier;
                    }
                }
            });

            // ⚠ APRÈS LA TRANSACTION, ET SEULEMENT SI ELLE A ABOUTI. Un palier annulé n'a
            // rien écrit : lui décompter des lignes gratuites les ferait perdre pour rien.
            $run->ajouterLignesFranchisees($offertesCePalier);
        } catch (\Throwable $e) {
            // La transaction du palier est annulée : rien de CE palier n'a été conservé.
            // Les paliers précédents, eux, le sont — et on le dit, parce que l'utilisateur
            // doit savoir que son portefeuille est à moitié repris et qu'un nouveau dépôt
            // reprendra sans rien dupliquer.
            $rapport->ajouter(Anomalie::erreur(
                Anomalie::VALEUR_INVALIDE,
                sprintf(
                    'L\'importation s\'est arrêtée à la ligne %d et ce palier n\'a rien conservé. '
                    . 'Les lignes déjà écrites le restent : redéposez le même fichier pour reprendre '
                    . 'là où il s\'est arrêté, sans rien créer en double. Motif : %s',
                    $fenetre[0]->numero,
                    $e->getMessage(),
                ),
                $fenetre[0]->feuille,
                $fenetre[0]->numero,
            ));

            return false;
        }

        return true;
    }

    /**
     * LA FIN D'UNE PHASE : le contrôle s'ouvre à la décision, l'écriture se referme.
     */
    private function clore(EchangeImportRun $run): EchangeImportRun
    {
        $rapport = RapportDeControle::depuisArray($run->getRapport());

        if ($run->getStatut() === EchangeImportRun::STATUT_CONTROLE) {
            $run->setStatut($rapport->confirmable()
                ? EchangeImportRun::STATUT_EN_ATTENTE_CONFIRMATION
                : EchangeImportRun::STATUT_ECHEC);
            $this->em->flush();

            return $run;
        }

        $entreprise = $run->getEntreprise();
        $invite = $run->getInvite();

        if ($entreprise !== null && $invite !== null) {
            // Occurrence et écriture ne sont plus dans la même transaction — l'écriture
            // en compte désormais plusieurs. Elle est donc posée à la fin, une fois pour
            // tout l'import, et sa clé d'idempotence l'empêche de compter deux fois.
            // Coût nul : l'import ne porte pas de forfait, chaque ligne a payé son métrage.
            $this->compteur->enregistrer(
                $entreprise,
                $invite,
                $invite->getUtilisateur(),
                EchangeOccurrence::TYPE_IMPORT,
                [LecteurDeLEtat::RESSOURCE],
                $rapport->lignesLues(),
                $this->compteur->cleIdempotence(
                    $entreprise,
                    $invite,
                    EchangeOccurrence::TYPE_IMPORT,
                    [],
                    'run-' . $run->getId(),
                ),
                $run->getEmpreinteFichier(),
                $run->getNomFichier(),
            );
        }

        $run->setStatut(EchangeImportRun::STATUT_TERMINE);
        $run->setRapport($rapport->toArray() + ['execute' => true]);

        // Le dépôt a joué son rôle : on ne garde pas des données personnelles sur le
        // disque une fois qu'elles sont en base.
        $chemin = $run->getCheminFichier();
        if ($chemin !== null && is_file($chemin)) {
            @unlink($chemin);
        }
        $run->setCheminFichier(null);
        $this->em->flush();

        return $run;
    }

    /**
     * ARRÊTE LE TRAVAIL EN LE DISANT — et y parvient même sur un gestionnaire fermé.
     *
     * ⚠ DOCTRINE FERME SON GESTIONNAIRE dès qu'une exception traverse un flush. Le
     * rollback a bien protégé les données du palier, mais tout appel suivant lève. On
     * rouvre donc, on rapatrie le contrôle dans le gestionnaire neuf, et on écrit ce que
     * l'on sait — le curseur y compris, pour que l'écran sache où le travail s'est arrêté.
     */
    private function consignerEchec(int $idRun, RapportDeControle $rapport, int $curseur, int $total): EchangeImportRun
    {
        $em = $this->em;
        if (!$em->isOpen()) {
            $this->registre->resetManager();
            $em = $this->registre->getManager();
        }

        $run = $em->find(EchangeImportRun::class, $idRun);
        if ($run === null) {
            throw new ImportImpossibleException('Le contrôle a disparu en cours d\'exécution.');
        }

        $run->setStatut(EchangeImportRun::STATUT_ECHEC);
        $run->setCurseur($curseur);
        $run->setTotalLignes($total);
        $run->setRapport($rapport->toArray() + ['curseur' => $curseur, 'total_lignes' => $total]);
        $em->flush();

        return $run;
    }

    private function echouer(EchangeImportRun $run, Anomalie $anomalie): EchangeImportRun
    {
        $rapport = RapportDeControle::depuisArray($run->getRapport());
        $rapport->ajouter($anomalie);

        $run->setStatut(EchangeImportRun::STATUT_ECHEC);
        $run->setRapport($rapport->toArray());
        $this->em->flush();

        return $run;
    }

    /** Ce que le rapport dit de l'avancement, et que l'écran relit sans recompter. */
    private function supplement(EchangeImportRun $run): array
    {
        return [
            'curseur' => $run->getCurseur(),
            'total_lignes' => $run->getTotalLignes(),
        ];
    }

    /**
     * LES LIGNES DU FICHIER, REGROUPÉES PAR POLICE.
     *
     * ⚠ LE TRI EST CE QUI REND LES PALIERS POSSIBLES : sans lui, deux échéances d'une même
     * police pourraient tomber de part et d'autre d'une frontière de palier, et la seconde
     * échouerait sur un repère que le premier a emporté avec lui.
     *
     * Il est aussi DÉTERMINISTE, et il le doit : le curseur désigne un rang dans cette
     * liste. Un tri qui varierait d'un palier à l'autre ferait reprendre le travail
     * ailleurs qu'où il s'était arrêté — en sautant des lignes, ou en les rejouant.
     *
     * @return LigneLue[]
     */
    private function lignesGroupees(Spreadsheet $classeur, Entreprise $entreprise): array
    {
        $lignes = $this->lecteurDeLEtat->lignes($classeur, $this->etat->colonnes($entreprise));

        usort($lignes, static function (LigneLue $a, LigneLue $b): int {
            return [self::groupeDe($a), $a->numero] <=> [self::groupeDe($b), $b->numero];
        });

        return $lignes;
    }

    /**
     * Le groupe d'une ligne : sa police, ou elle-même.
     *
     * Une ligne sans référence ne se rattache à rien — elle porte son identifiant, ou elle
     * sera refusée. Elle forme donc son propre groupe, et rien ne l'empêche d'être seule
     * dans un palier.
     */
    private static function groupeDe(LigneLue $ligne): string
    {
        return ChaineExistante::cle(
            $ligne->texte('policeReference'),
            $ligne->texte('policeNumeroAvenant'),
        ) ?? ('#' . $ligne->numero);
    }

    /**
     * LA TRANCHE DE LIGNES DE CE PALIER, étendue jusqu'à la fin du groupe entamé.
     *
     * ⚠ UN GROUPE PLUS GROS QUE LE PALIER PASSE ENTIER. Une police à cent échéances ne se
     * coupe pas : la scinder produirait des renvois irrésolus, ce qui est pire qu'un
     * palier un peu large. Le cas est rare, et le refuser reviendrait à refuser la police.
     *
     * @param LigneLue[] $lignes
     *
     * @return LigneLue[]
     */
    private function fenetre(array $lignes, int $curseur, int $taille): array
    {
        $total = count($lignes);
        if ($curseur >= $total) {
            return [];
        }

        $fin = min($curseur + $taille, $total);

        while ($fin < $total && self::groupeDe($lignes[$fin]) === self::groupeDe($lignes[$fin - 1])) {
            ++$fin;
        }

        return array_slice($lignes, $curseur, $fin - $curseur);
    }

    /**
     * Une ligne refusée n'engage pas les suivantes : ses repères doivent être oubliés.
     *
     * @param MutationOperation[] $operations
     */
    private function oublierLesReperes(array $operations): void
    {
        $reperes = [];
        foreach ($operations as $operation) {
            if ($operation->ref !== null) {
                $reperes[] = $operation->ref;
            }
        }

        $this->reconstitueur->oublier($reperes);
    }

    /** Le travail est-il dans une phase qui avance ? */
    private function travaille(EchangeImportRun $run): bool
    {
        return in_array(
            $run->getStatut(),
            [EchangeImportRun::STATUT_CONTROLE, EchangeImportRun::STATUT_EN_COURS],
            true,
        );
    }

    /** La limite mémoire de PHP, en octets. Rend 0 quand elle n'est pas bornée. */
    private function memoireDisponible(): int
    {
        $brut = trim((string) ini_get('memory_limit'));
        if ($brut === '' || $brut === '-1') {
            return 0;
        }

        $unite = strtolower(substr($brut, -1));
        $nombre = (int) $brut;

        return match ($unite) {
            'g' => $nombre * 1024 * 1024 * 1024,
            'm' => $nombre * 1024 * 1024,
            'k' => $nombre * 1024,
            default => $nombre,
        };
    }
}
