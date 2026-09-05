<?php

namespace App\Echange\Service;

use App\Ai\Mutation\MutationOperation;
use App\Ai\Mutation\MutationReferences;
use App\Ai\Scope\AiScope;
use App\Echange\Canevas\CanevasDEchange;
use App\Echange\Canevas\RessourceDEchange;
use App\Echange\Classeur\ClasseurIllisibleException;
use App\Echange\Classeur\EcrivainJsbx;
use App\Echange\Classeur\LecteurJsbx;
use App\Echange\Classeur\LigneLue;
use App\Echange\Classeur\Manifeste;
use App\Entity\EchangeImportRun;
use App\Entity\Entreprise;
use App\Entity\Utilisateur;
use App\Entity\EchangeOccurrence;
use App\Entity\Invite;
use App\Service\Workspace\WorkspaceMutationService;
use App\Token\TokenPricing;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * ORCHESTRE l'importation. Ce service porte les passes 1 et 2 ; la passe 3 (écriture)
 * les suit.
 *
 *  PASSE 1 — CONTRÔLE STRUCTUREL. Le fichier est-il un classeur d'échange, vient-il de
 *            ce cabinet, sa structure est-elle intacte ? Un échec ici arrête tout
 *            immédiatement, avec un message unique, et ne décompte AUCUNE occurrence :
 *            un fichier qu'on n'a pas su ouvrir n'a rien coûté à personne.
 *
 *  PASSE 2 — CONTRÔLE À BLANC. Obligatoire et gratuit. Aucune écriture. Chaque ligne
 *            est traduite en opération et soumise au DRY-RUN du circuit d'écriture
 *            commun — celui-là même qui sert à l'écran et à l'assistant. C'est ce qui
 *            garantit qu'un import respecte exactement les mêmes règles qu'une saisie :
 *            droits ressource par ressource, champs obligatoires, validation par le
 *            formulaire, impacts de suppression.
 *
 * Le résultat est un RAPPORT persisté, que l'utilisateur lit avant de décider. Tant
 * qu'il porte une erreur bloquante, la confirmation reste fermée : il n'existe pas
 * d'import partiel, et accepter « le reste » laisserait la base dans un état que
 * personne n'a décrit ni voulu.
 */
final class ImportateurJsbx
{
    public function __construct(
        private readonly CanevasDEchange $canevas,
        private readonly LecteurJsbx $lecteur,
        private readonly TraducteurDeLigne $traducteur,
        private readonly ResolveurDeRenvois $resolveur,
        private readonly WorkspaceMutationService $mutation,
        private readonly CompteurDOccurrences $compteur,
        private readonly EntityManagerInterface $em,
        private readonly ManagerRegistry $registre,
    ) {
    }

    /**
     * Passes 1 et 2 sur un fichier déposé. N'écrit rien en base métier ; persiste
     * seulement le contrôle et son rapport.
     *
     * @param bool     $confirmeAutreCabinet l'utilisateur a explicitement accepté d'importer
     *                                       un fichier issu d'un autre cabinet
     * @param string[] $donnees              codes des données à retenir ; vide = tout ce que
     *                                       le fichier contient
     */
    public function controler(
        string $chemin,
        string $nomFichier,
        Entreprise $entreprise,
        Invite $invite,
        bool $suppressionsAutorisees = false,
        bool $confirmeAutreCabinet = false,
        ?Progression $progression = null,
        array $donnees = [],
    ): EchangeImportRun {
        $progression ??= Progression::muette();
        $progression->etape('Lecture du fichier');
        $rapport = new RapportDeControle();
        $this->resolveur->reinitialiser();

        $run = (new EchangeImportRun())
            ->setNomFichier($nomFichier)
            ->setCheminFichier($chemin)
            ->setEmpreinteFichier(is_file($chemin) ? hash_file('sha256', $chemin) : null)
            ->setStatut(EchangeImportRun::STATUT_CONTROLE)
            ->setSuppressionsAutorisees($suppressionsAutorisees)
            ->setDonnees($donnees)
            ->setExpireLe(new \DateTimeImmutable('+' . EchangeImportRun::DUREE_DE_VIE_HEURES . ' hours'));
        $run->setEntreprise($entreprise);
        $run->setInvite($invite);

        // ── PASSE 1 ─────────────────────────────────────────────────────────────────
        try {
            $classeur = $this->lecteur->ouvrir($chemin);
        } catch (ClasseurIllisibleException $e) {
            $rapport->ajouter(Anomalie::erreur(Anomalie::FICHIER_ILLISIBLE, $e->getMessage()));

            return $this->cloturer($run, $rapport, EchangeImportRun::STATUT_ECHEC);
        }

        $ecrivables = $this->canevas->ressourcesEcrivables($invite);
        $inventaire = $this->lecteur->inventaireDesFeuilles($classeur, $this->canevas->toutes());
        $inventaire = $this->restreindre($inventaire, $donnees);

        if (!$this->passeStructurelle($classeur, $entreprise, $inventaire, $rapport, $confirmeAutreCabinet)) {
            return $this->cloturer($run, $rapport, EchangeImportRun::STATUT_ECHEC);
        }

        // ── PASSE 2 ─────────────────────────────────────────────────────────────────
        $operations = $this->passeAblanc($classeur, $inventaire['presentes'], $ecrivables, $entreprise, $invite, $rapport, $progression);

        $run->setRapport($rapport->toArray() + ['operations' => count($operations)]);

        return $this->cloturer(
            $run,
            $rapport,
            $rapport->confirmable()
                ? EchangeImportRun::STATUT_EN_ATTENTE_CONFIRMATION
                : EchangeImportRun::STATUT_ECHEC,
        );
    }

    /**
     * Ne retient que les données demandées. Vide = tout ce que le fichier contient.
     *
     * Une feuille écartée devient exactement une FEUILLE HORS PÉRIMÈTRE — le cas que
     * l'import sait déjà traiter sans erreur. On ne l'invente donc pas : on réutilise le
     * chemin qui existe.
     *
     * ⚠ CE FILTRE NE MET RIEN À L'ABRI. Écarter les taxes alors qu'une ligne de tranche y
     * renvoie produit un renvoi irrésolu, donc une erreur bloquante. C'est voulu : le
     * filtrage échoue bruyamment plutôt que d'écrire des liens vides, et l'utilisateur
     * apprend que ces deux données ne se séparent pas.
     *
     * @param array{presentes: array<string, RessourceDEchange>, absentes: string[], inconnues: string[]} $inventaire
     * @param string[]                                                                                    $donnees
     *
     * @return array{presentes: array<string, RessourceDEchange>, absentes: string[], inconnues: string[]}
     */
    private function restreindre(array $inventaire, array $donnees): array
    {
        if ($donnees === []) {
            return $inventaire;
        }

        $retenues = [];
        foreach ($inventaire['presentes'] as $code => $ressource) {
            if (in_array($code, $donnees, true)) {
                $retenues[$code] = $ressource;
                continue;
            }
            // Écartée par choix : elle rejoint les feuilles hors périmètre, sans bruit.
            $inventaire['absentes'][] = $ressource->feuille;
        }

        $inventaire['presentes'] = $retenues;

        return $inventaire;
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Passe 1 — structure
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * @param array{presentes: array<string, RessourceDEchange>, absentes: string[], inconnues: string[]} $inventaire
     *
     * @return bool false = arrêt immédiat
     */
    private function passeStructurelle(
        Spreadsheet $classeur,
        Entreprise $entreprise,
        array $inventaire,
        RapportDeControle $rapport,
        bool $confirmeAutreCabinet,
    ): bool {
        $valeurs = $this->lecteur->lireManifeste($classeur);
        if ($valeurs === []) {
            $rapport->ajouter(Anomalie::erreur(
                Anomalie::MANIFESTE_ABSENT,
                'Ce fichier n\'a pas été produit par la rubrique « Importation / Exportation » : '
                . 'sa feuille d\'identité est absente. Exportez d\'abord vos données pour obtenir un '
                . 'classeur au bon format, puis retravaillez-le.',
                EcrivainJsbx::FEUILLE_MANIFESTE,
            ));

            return false;
        }

        $manifeste = Manifeste::depuisValeurs($valeurs);

        // Le fichier vient-il de ce cabinet ? Divergence = avertissement BLOQUANT, que
        // l'utilisateur peut lever explicitement — importer les données d'un cabinet
        // dans un autre est parfois voulu (une reprise), jamais anodin.
        if ($manifeste->uidCabinet !== '' && $manifeste->uidCabinet !== (string) $entreprise->getId()) {
            if (!$confirmeAutreCabinet) {
                $rapport->ajouter(Anomalie::erreur(
                    Anomalie::AUTRE_CABINET,
                    sprintf(
                        'Ce fichier a été exporté par un AUTRE cabinet (« %s »). Les identifiants qu\'il '
                        . 'contient ne désignent rien ici, et les lignes seraient créées en double. '
                        . 'Confirmez explicitement si c\'est bien une reprise de données voulue.',
                        $manifeste->nomCabinet !== '' ? $manifeste->nomCabinet : $manifeste->uidCabinet,
                    ),
                    EcrivainJsbx::FEUILLE_MANIFESTE,
                ));

                return false;
            }
            $rapport->ajouter(Anomalie::avertissement(
                Anomalie::AUTRE_CABINET,
                sprintf('Reprise assumée depuis le cabinet « %s ».', $manifeste->nomCabinet ?: $manifeste->uidCabinet),
                EcrivainJsbx::FEUILLE_MANIFESTE,
            ));
        }

        if ($inventaire['presentes'] === []) {
            $rapport->ajouter(Anomalie::erreur(
                Anomalie::MANIFESTE_ABSENT,
                'Le fichier ne contient aucune feuille de données reconnue. '
                . 'Ses onglets ont peut-être été renommés : ils doivent garder le nom qu\'ils avaient à l\'export.',
            ));

            return false;
        }

        // Une feuille inconnue est IGNORÉE, pas refusée : l'utilisateur a le droit de
        // garder son brouillon dans le fichier. On le lui dit, simplement.
        foreach ($inventaire['inconnues'] as $nom) {
            $rapport->ajouter(Anomalie::avertissement(
                Anomalie::FEUILLE_INCONNUE,
                sprintf('L\'onglet « %s » n\'est pas une donnée échangeable : il a été ignoré.', $nom),
                $nom,
            ));
        }

        // Une colonne technique supprimée rend le fichier menteur sur son contenu. On
        // NOMME celle qui manque : « la structure a été modifiée » n'aiderait personne.
        $altere = false;
        foreach ($inventaire['presentes'] as $ressource) {
            $manquants = $this->lecteur->codesManquants($classeur, $ressource);
            if ($manquants === []) {
                continue;
            }
            $altere = true;
            $rapport->ajouter(Anomalie::erreur(
                Anomalie::STRUCTURE_ALTEREE,
                sprintf(
                    'La structure de l\'onglet « %s » a été modifiée : %s %s absente%s de la ligne des codes techniques. '
                    . 'Réexportez vos données plutôt que de reconstruire le fichier à la main.',
                    $ressource->feuille,
                    implode(', ', array_map(static fn (string $c) => '« ' . $c . ' »', $manquants)),
                    count($manquants) > 1 ? 'sont' : 'est',
                    count($manquants) > 1 ? 's' : '',
                ),
                $ressource->feuille,
            ));
        }

        return !$altere;
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Passe 2 — contrôle à blanc
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * @param array<string, RessourceDEchange> $presentes
     * @param array<string, RessourceDEchange> $ecrivables
     *
     * @return array<int, array{ressource: string, operation: MutationOperation, ligne: LigneLue}>
     */
    private function passeAblanc(
        Spreadsheet $classeur,
        array $presentes,
        array $ecrivables,
        Entreprise $entreprise,
        Invite $invite,
        RapportDeControle $rapport,
        ?Progression $progression = null,
    ): array {
        $progression ??= Progression::muette();
        $scope = new AiScope($entreprise, $invite, null);

        // UN SEUL registre de renvois pour toute la passe : c'est lui qui permet à la
        // ligne d'un client de désigner un groupe créé quelques feuilles plus haut.
        // En créer un par ligne — ce que fait analyserOperation quand on ne lui en passe
        // pas — reviendrait à oublier chaque repère aussitôt déclaré.
        $refs = MutationReferences::dryRun();

        // Toutes les feuilles sont lues d'abord, et TOUS les repères déclarés avant la
        // moindre résolution : un contrat peut désigner un client écrit plus bas, ou
        // dans une feuille suivante. Exiger l'ordre de lecture rendrait le format
        // dépendant d'un tri que l'utilisateur a le droit de changer.
        $lignesParRessource = [];
        $total = 0;
        foreach ($presentes as $code => $ressource) {
            $lignes = $this->lecteur->lignesDe($classeur, $ressource);
            $lignesParRessource[$code] = $lignes;
            $total += count($lignes);

            foreach ($lignes as $ligne) {
                $repere = $ligne->texte(CanevasDEchange::COL_REF);
                if ($repere !== '' && $ligne->texte(CanevasDEchange::COL_UID) === '') {
                    $this->resolveur->declarerRepere($repere);
                }
            }
        }

        $rapport->compterLignes($total);
        // Le volume n'est connu qu'ici : un import ne sait pas ce qu'il pèse avant
        // d'avoir ouvert ses feuilles. Mieux vaut un total posé tard qu'un pourcentage
        // faux depuis le début.
        $progression->totaliser($total);
        if ($total > TokenPricing::ECHANGE_PLAFOND_LIGNES) {
            $rapport->ajouter(Anomalie::erreur(
                Anomalie::PLAFOND_DEPASSE,
                sprintf(
                    'Ce fichier contient %d lignes, au-delà du plafond de %d par import. '
                    . 'Découpez-le en plusieurs fichiers : chaque ligne est écrite par le même circuit '
                    . 'qu\'une saisie à l\'écran, ce qui garantit les mêmes contrôles mais demande du temps.',
                    $total,
                    TokenPricing::ECHANGE_PLAFOND_LIGNES,
                ),
            ));

            return [];
        }

        $operations = [];
        foreach ($presentes as $code => $ressource) {
            $rapport->declarerRessource($code, $ressource->libelle);

            // Droit d'écriture, ressource par ressource. Une feuille qu'on n'a pas le
            // droit d'écrire n'est pas ignorée en silence : elle est signalée, sinon
            // l'utilisateur croirait ses modifications enregistrées.
            if (!isset($ecrivables[$code])) {
                if ($lignesParRessource[$code] !== []) {
                    $rapport->ajouter(Anomalie::erreur(
                        Anomalie::DROIT_INSUFFISANT,
                        sprintf(
                            'Vous n\'avez pas le droit de modifier « %s ». Retirez cet onglet du fichier, '
                            . 'ou demandez ce droit à l\'administrateur de votre cabinet.',
                            $ressource->libelle,
                        ),
                        $ressource->feuille,
                    ));
                    $rapport->compterErreur($code);
                }
                // Ses lignes ne seront pas traitées : on les décompte tout de même, sans
                // quoi la barre resterait bloquée sur une feuille qu'on a écartée.
                $progression->avancer(count($lignesParRessource[$code]));
                continue;
            }

            $progression->etape($ressource->libelle);
            foreach ($lignesParRessource[$code] as $ligne) {
                $operation = $this->analyserLigne($ligne, $ressource, $entreprise, $scope, $refs, $rapport);
                $progression->avancer();
                if ($operation !== null) {
                    $operations[] = ['ressource' => $code, 'operation' => $operation, 'ligne' => $ligne];
                }
            }
        }

        return $operations;
    }

    private function analyserLigne(
        LigneLue $ligne,
        RessourceDEchange $ressource,
        Entreprise $entreprise,
        AiScope $scope,
        MutationReferences $refs,
        RapportDeControle $rapport,
    ): ?MutationOperation {
        [$action, $cibleId, $anomalie] = $this->traducteur->action($ligne, $ressource);
        if ($anomalie !== null) {
            $rapport->ajouter($anomalie);
            $rapport->compterErreur($ressource->code);

            return null;
        }

        if ($action === MutationOperation::OP_DELETE) {
            return $this->analyserSuppression($ligne, $ressource, $cibleId, $scope, $refs, $rapport);
        }

        $anomalies = [];
        $champs = $this->traducteur->champs($ligne, $ressource, $entreprise, $anomalies);
        foreach ($anomalies as $a) {
            $rapport->ajouter($a);
            $rapport->compterErreur($ressource->code);
        }
        if ($anomalies !== []) {
            return null;
        }

        // Conflit de modification : la ligne a bougé en base depuis l'export.
        if ($action === MutationOperation::OP_EDIT && $cibleId !== null) {
            $this->verifierConflit($ligne, $ressource, $cibleId, $entreprise, $rapport);
        }

        $operation = new MutationOperation(
            op: $action,
            entityShortName: $ressource->code,
            targetId: $cibleId,
            fields: $champs,
            ref: $action === MutationOperation::OP_CREATE
                ? ($ligne->texte(CanevasDEchange::COL_REF) ?: null)
                : null,
        );

        // Une création ou une modification sans le moindre champ n'écrit rien. La
        // laisser passer produirait un journal « exécuté » sur une ligne qui n'a
        // touché aucune donnée — exactement le mensonge que ecritQuelqueChose() évite.
        if (!$operation->ecritQuelqueChose()) {
            return null;
        }

        // DRY-RUN par le circuit COMMUN : droits, champs obligatoires, validation du
        // formulaire. Rien de tout cela n'est réécrit ici, et c'est le seul moyen qu'un
        // import obéisse exactement aux mêmes règles qu'une saisie.
        $diagnostic = $this->mutation->analyserOperation($operation, $scope, $refs);
        if (!$diagnostic['ok']) {
            $this->signalerDiagnostic($diagnostic, $ligne, $ressource, $rapport);

            return null;
        }

        // Le repère n'est déclaré qu'une fois la ligne jugée VALIDE : y renvoyer alors
        // qu'elle ne sera jamais écrite produirait une seconde erreur, en cascade, qui
        // masquerait la première — la seule qu'il faille corriger.
        if ($operation->ref !== null) {
            $refs->declarer($operation->ref);
        }

        $rapport->compter($ressource->code, $action);

        return $operation;
    }

    private function analyserSuppression(
        LigneLue $ligne,
        RessourceDEchange $ressource,
        ?int $cibleId,
        AiScope $scope,
        MutationReferences $refs,
        RapportDeControle $rapport,
    ): ?MutationOperation {
        $operation = new MutationOperation(
            op: MutationOperation::OP_DELETE,
            entityShortName: $ressource->code,
            targetId: $cibleId,
        );

        $diagnostic = $this->mutation->analyserOperation($operation, $scope, $refs);
        if (!$diagnostic['ok']) {
            $this->signalerDiagnostic($diagnostic, $ligne, $ressource, $rapport);

            return null;
        }

        // Les impacts d'une suppression sont REMONTÉS même quand elle est possible :
        // effacer une opportunité peut emporter des enregistrements que l'utilisateur
        // n'a pas en tête, et il doit le lire avant de confirmer, pas après.
        foreach ($diagnostic['impacts'] as $impact) {
            $rapport->ajouter(Anomalie::avertissement(
                Anomalie::SUPPRESSION_BLOQUEE,
                sprintf('Suppression de « %s » : %s', $ressource->libelle, $impact),
                $ligne->feuille,
                $ligne->numero,
                $ligne->colonne(CanevasDEchange::COL_ACTION),
            ));
        }

        $rapport->compter($ressource->code, MutationOperation::OP_DELETE);

        return $operation;
    }

    /**
     * Détection de conflit : la ligne a-t-elle été modifiée en base depuis l'export ?
     *
     * AVERTISSEMENT et non erreur : l'utilisateur est le mieux placé pour savoir si sa
     * version doit l'emporter sur celle de son collègue. Le bloquer d'office ferait
     * perdre un travail hors ligne pour une modification peut-être insignifiante ; ne
     * rien dire l'écraserait en silence.
     */
    private function verifierConflit(
        LigneLue $ligne,
        RessourceDEchange $ressource,
        int $cibleId,
        Entreprise $entreprise,
        RapportDeControle $rapport,
    ): void {
        $brut = $ligne->valeur(CanevasDEchange::COL_MODIFIE_LE);
        if (!is_numeric($brut)) {
            // Colonne vide : soit l'entité ne porte pas d'horodatage, soit l'utilisateur
            // l'a effacée. Dans les deux cas, aucun conflit n'est détectable — et le
            // dictionnaire l'annonce plutôt que de le laisser croire.
            return;
        }

        try {
            $duFichier = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $brut);
        } catch (\Throwable) {
            return;
        }

        $enBase = $this->em->createQueryBuilder()
            ->select('e.updatedAt')
            ->from($ressource->fqcn, 'e')
            ->andWhere('e.id = :id')
            ->andWhere('e.entreprise = :entreprise')
            ->setParameter('id', $cibleId)
            ->setParameter('entreprise', $entreprise)
            ->getQuery()
            ->getOneOrNullResult();

        $date = $enBase['updatedAt'] ?? null;
        if (!$date instanceof \DateTimeInterface) {
            return;
        }

        // Une seconde de tolérance : Excel arrondit les dates au format de sa cellule,
        // et signaler un conflit sur un arrondi ferait douter de tous les autres.
        if ($date->getTimestamp() > $duFichier->getTimestamp() + 1) {
            $rapport->ajouter(Anomalie::avertissement(
                Anomalie::CONFLIT_MODIFICATION,
                sprintf(
                    'Cette ligne a été modifiée dans l\'application le %s, après votre export. '
                    . 'Confirmer écrasera cette modification par la vôtre.',
                    $date->format('d/m/Y à H:i'),
                ),
                $ligne->feuille,
                $ligne->numero,
                $ligne->colonne(CanevasDEchange::COL_MODIFIE_LE),
            ));
        }
    }

    /** @param array<string, mixed> $diagnostic */
    private function signalerDiagnostic(array $diagnostic, LigneLue $ligne, RessourceDEchange $ressource, RapportDeControle $rapport): void
    {
        $message = match ($diagnostic['statut']) {
            'hors_perimetre' => sprintf('« %s » est hors de votre périmètre d\'écriture.', $ressource->libelle),
            'introuvable'    => sprintf(
                'Aucune ligne de « %s » ne porte cet identifiant dans votre cabinet. '
                . 'Elle a peut-être été supprimée depuis votre export.',
                $ressource->libelle,
            ),
            'bloque'         => implode(' ', $diagnostic['impacts'] ?: ['Opération impossible.']),
            default          => $this->messageDesManquants($diagnostic['manquants'] ?? []),
        };

        $rapport->ajouter(Anomalie::erreur(
            match ($diagnostic['statut']) {
                'hors_perimetre' => Anomalie::DROIT_INSUFFISANT,
                'introuvable'    => Anomalie::LIGNE_INTROUVABLE,
                'bloque'         => Anomalie::SUPPRESSION_BLOQUEE,
                default          => Anomalie::CHAMP_OBLIGATOIRE,
            },
            $message,
            $ligne->feuille,
            $ligne->numero,
        ));
        $rapport->compterErreur($ressource->code);
    }

    /** @param array<string, string[]> $manquants */
    private function messageDesManquants(array $manquants): string
    {
        if ($manquants === []) {
            return 'Cette ligne ne peut pas être enregistrée en l\'état.';
        }

        $morceaux = [];
        foreach ($manquants as $champ => $messages) {
            $morceaux[] = sprintf('%s (%s)', $champ, implode(' ', (array) $messages));
        }

        return 'Informations manquantes ou invalides : ' . implode(' ; ', $morceaux) . '.';
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

    // ─────────────────────────────────────────────────────────────────────────────
    // Passe 3 — écriture
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * PASSE 3 — ÉCRITURE, sur CONFIRMATION EXPLICITE de l'utilisateur.
     *
     * ⚠ LE FICHIER EST INTÉGRALEMENT RECONTRÔLÉ ICI, et ce n'est pas une précaution
     * superflue : entre le contrôle et le clic, un collègue a pu supprimer une ligne
     * visée, un administrateur a pu retirer un droit, une valeur a pu changer.
     * Réexécuter un plan mémorisé écrirait sur la foi d'un état qui n'existe plus. C'est
     * la règle que suit déjà l'exécution des plans de l'assistant : recharger,
     * revalider, puis seulement écrire.
     *
     * ⚠ TRANSACTION UNIQUE, TOUT OU RIEN. Aucun import partiel n'est acceptable : une
     * erreur en cours de route ramène la base exactement où elle était.
     *
     * @throws ImportImpossibleException si le contrôle n'est plus exécutable
     */
    public function executer(EchangeImportRun $run, ?Utilisateur $acteur, ?Progression $progression = null): EchangeImportRun
    {
        $progression ??= Progression::muette();
        $progression->etape('Vérification du fichier');
        $entreprise = $run->getEntreprise();
        $invite = $run->getInvite();

        if (!$run->estConfirmable()) {
            throw new ImportImpossibleException(
                'Ce contrôle n\'est plus en attente de décision : il a expiré, a déjà été exécuté, ou a été '
                . 'annulé. Redéposez le fichier pour repartir d\'un contrôle à jour.',
            );
        }
        if ($entreprise === null || $invite === null) {
            throw new ImportImpossibleException('Ce contrôle n\'est rattaché à aucun cabinet identifiable.');
        }

        $chemin = (string) $run->getCheminFichier();
        if (!is_file($chemin)) {
            throw new ImportImpossibleException(
                'Le fichier déposé n\'est plus disponible sur le serveur. Redéposez-le pour relancer le contrôle.',
            );
        }

        // ── RECONTRÔLE INTÉGRAL ─────────────────────────────────────────────────────
        $rapport = new RapportDeControle();
        $this->resolveur->reinitialiser();

        $classeur = $this->lecteur->ouvrir($chemin);
        $inventaire = $this->lecteur->inventaireDesFeuilles($classeur, $this->canevas->toutes());
        // ⚠ LE PÉRIMÈTRE CHOISI AU DÉPÔT EST RÉAPPLIQUÉ. Le recontrôle repart du fichier
        // entier : sans cette ligne, confirmer un import volontairement restreint à la
        // production réécrirait aussi les taxes et les monnaies que l'utilisateur avait
        // écartées — et rien, ni à l'écran ni au rapport, ne le lui aurait dit.
        $inventaire = $this->restreindre($inventaire, $run->getDonnees());
        $ecrivables = $this->canevas->ressourcesEcrivables($invite);

        // L'origine étrangère du fichier a déjà été assumée au dépôt : on ne redemande
        // pas la même confirmation, elle a été donnée une fois.
        if (!$this->passeStructurelle($classeur, $entreprise, $inventaire, $rapport, true)) {
            return $this->echouer($run, $rapport);
        }

        $etapes = $this->passeAblanc($classeur, $inventaire['presentes'], $ecrivables, $entreprise, $invite, $rapport, $progression);
        if (!$rapport->confirmable()) {
            return $this->echouer($run, $rapport);
        }

        // Une suppression non autorisée au dépôt BLOQUE, elle n'est pas ignorée : passée
        // sous silence, l'utilisateur croirait ses lignes supprimées.
        if ($rapport->nbSuppressions() > 0 && !$run->isSuppressionsAutorisees()) {
            $rapport->ajouter(Anomalie::erreur(
                Anomalie::SUPPRESSION_REFUSEE,
                sprintf(
                    'Ce fichier demande %d suppression(s) alors qu\'elles n\'ont pas été autorisées au dépôt. '
                    . 'Redéposez-le en cochant explicitement l\'autorisation de supprimer.',
                    $rapport->nbSuppressions(),
                ),
            ));

            return $this->echouer($run, $rapport);
        }

        // ── ÉCRITURE ────────────────────────────────────────────────────────────────
        $run->setStatut(EchangeImportRun::STATUT_EN_COURS);
        $this->em->flush();

        // ⚠ ON REPART D'UNE UNITÉ DE TRAVAIL PROPRE.
        //
        // Le contrôle à blanc VALIDE en soumettant le formulaire de chaque entité sur une
        // COPIE. Un formulaire à collection fabrique au passage des enfants rattachés à
        // cette copie — inoffensifs tant que rien ne flushe, mais l'écriture qui suit,
        // elle, flushe : Doctrine découvre alors « une entité nouvelle atteinte par
        // Classeur#client » et refuse tout le lot, y compris les lignes irréprochables.
        //
        // Chez l'assistant, le dry-run et l'exécution vivent dans deux requêtes séparées,
        // ce qui masquait le problème. Ici, ils se suivent dans la même : on vide donc,
        // puis on recharge ce dont l'écriture a besoin. Les opérations, elles, ne sont que
        // des identifiants et des scalaires : elles traversent sans dommage.
        $idRun = (int) $run->getId();
        $idActeur = $acteur?->getId();
        $this->em->clear();

        $run = $this->em->find(EchangeImportRun::class, $idRun);
        if ($run === null) {
            throw new ImportImpossibleException('Le contrôle a disparu en cours d\'exécution.');
        }
        $entreprise = $run->getEntreprise();
        $invite = $run->getInvite();
        $acteur = $idActeur === null ? null : $this->em->find(Utilisateur::class, $idActeur);

        $scope = new AiScope($entreprise, $invite, null);
        $journal = [];

        // L'écriture a son PROPRE dénominateur : les opérations réellement planifiées.
        // Réutiliser celui du contrôle donnerait une barre qui repart de zéro sans le
        // dire, ou qui reste bloquée à cent pour cent pendant toute l'écriture.
        $progression->etape('Enregistrement');
        $progression->totaliser(count($etapes));

        try {
            $this->em->wrapInTransaction(function () use ($etapes, $scope, $acteur, $entreprise, $invite, $run, $rapport, $progression, &$journal): void {
                $refs = MutationReferences::live();
                $idsParRepere = [];

                // Créations et modifications, dans l'ORDRE TOPOLOGIQUE : une donnée
                // référencée est écrite avant celle qui la référence.
                foreach ($etapes as $index => $etape) {
                    if ($etape['operation']->isDelete()) {
                        continue;
                    }
                    $resultat = $this->mutation->executer($etape['operation'], $scope, $acteur, $refs);
                    $journal[] = $resultat;
                    $etapes[$index]['idEcrit'] = $resultat['id'] ?? $etape['operation']->targetId;
                    $progression->avancer();

                    if ($etape['operation']->ref !== null && isset($resultat['id'])) {
                        $idsParRepere[mb_strtolower($etape['operation']->ref)] = (int) $resultat['id'];
                    }
                }

                // RENVOIS DIFFÉRÉS — la seconde passe qui referme le cycle du modèle.
                // Une opportunité désigne la police qu'elle fait évoluer, laquelle pend
                // d'une proposition qui appartient à l'opportunité : aucun ordre ne
                // permet d'écrire ce lien du premier coup. On le pose maintenant que
                // les deux extrémités existent, DANS LA MÊME TRANSACTION — un lien
                // manquant n'est pas un demi-succès acceptable.
                foreach ($this->renvoisDifferes($etapes, $idsParRepere, $entreprise) as $complement) {
                    $journal[] = $this->mutation->executer($complement, $scope, $acteur, $refs);
                }

                // Suppressions en ORDRE INVERSE : l'enfant part avant le parent, sans
                // quoi la première clé étrangère venue ferait échouer le lot entier.
                foreach (array_reverse($etapes) as $etape) {
                    if ($etape['operation']->isDelete()) {
                        $journal[] = $this->mutation->executer($etape['operation'], $scope, $acteur, $refs);
                        $progression->avancer();
                    }
                }

                // Occurrence dans la MÊME transaction que l'écriture : ou bien l'import
                // a eu lieu ET a été tracé, ou bien ni l'un ni l'autre. Coût nul —
                // l'import ne porte pas de forfait, chaque ligne a payé son métrage.
                $this->compteur->enregistrer(
                    $entreprise,
                    $invite,
                    $acteur,
                    EchangeOccurrence::TYPE_IMPORT,
                    array_values(array_unique(array_map(
                        static fn (array $etape) => $etape['ressource'],
                        $etapes,
                    ))),
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
                $this->em->flush();
            });
        } catch (\Throwable $e) {
            // Transaction annulée : tout ce qui avait été écrit est revenu en arrière.
            // On ne laisse donc jamais un rapport qui annoncerait un succès partiel.
            $rapport->ajouter(Anomalie::erreur(
                Anomalie::VALEUR_INVALIDE,
                sprintf(
                    'L\'importation a été interrompue et AUCUNE modification n\'a été conservée. Motif : %s',
                    $e->getMessage(),
                ),
            ));

            return $this->echouer($run, $rapport);
        }

        $run->setStatut(EchangeImportRun::STATUT_TERMINE);
        $run->setRapport($rapport->toArray() + [
            'execute' => true,
            'operations_executees' => count($journal),
        ]);

        // Le dépôt a joué son rôle : on ne garde pas des données personnelles sur le
        // disque une fois qu'elles sont en base.
        @unlink($chemin);
        $run->setCheminFichier(null);
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

    /**
     * Opérations d'édition posant les renvois qu'aucun ordre d'écriture ne permettait.
     *
     * Construites APRÈS les créations, quand les identifiants réels existent enfin.
     *
     * @param array<int, array{ressource: string, operation: MutationOperation, ligne: LigneLue, idEcrit?: ?int}> $etapes
     * @param array<string, int>                                                                                  $idsParRepere
     *
     * @return MutationOperation[]
     */
    private function renvoisDifferes(array $etapes, array $idsParRepere, Entreprise $entreprise): array
    {
        $complements = [];

        foreach ($etapes as $etape) {
            if ($etape['operation']->isDelete()) {
                continue;
            }

            $ressource = $this->canevas->ressource($etape['ressource']);
            if ($ressource === null || $ressource->renvoisDifferes === []) {
                continue;
            }

            $cibleId = $etape['idEcrit'] ?? $etape['operation']->targetId;
            if ($cibleId === null) {
                continue;
            }

            $champs = [];
            foreach ($ressource->renvoisDifferes as $codeColonne) {
                $colonne = $ressource->colonne($codeColonne);
                if ($colonne === null || !array_key_exists($codeColonne, $etape['ligne']->valeurs)) {
                    continue;
                }

                $renvoi = $this->resolveur->resoudre($etape['ligne']->valeur($codeColonne), $colonne, $entreprise);
                if ($renvoi->estRefus() || !$renvoi->estEcrivable() || $renvoi->valeur === null) {
                    continue;
                }

                // Un repère local se résout MAINTENANT en identifiant réel : au moment
                // du contrôle, la ligne visée n'existait pas encore.
                $valeur = $renvoi->valeur;
                if ($renvoi->statut === 'repere') {
                    $cle = mb_strtolower(ltrim((string) $valeur, MutationReferences::PREFIXE));
                    if (!isset($idsParRepere[$cle])) {
                        continue;
                    }
                    $valeur = $idsParRepere[$cle];
                }

                $champs[$codeColonne] = $valeur;
            }

            if ($champs !== []) {
                $complements[] = new MutationOperation(
                    op: MutationOperation::OP_EDIT,
                    entityShortName: $ressource->code,
                    targetId: (int) $cibleId,
                    fields: $champs,
                );
            }
        }

        return $complements;
    }

    /**
     * Consigne l'échec — et y parvient MÊME quand l'écriture a fait fermer
     * l'EntityManager.
     *
     * ⚠ Doctrine ferme son gestionnaire dès qu'une exception traverse un flush. Le
     * rollback a bien protégé les données, mais tout appel suivant lève « The
     * EntityManager is closed » : le chemin d'erreur explosait donc à son tour, et
     * l'utilisateur recevait une erreur fatale à la place du rapport qui lui aurait dit
     * quoi corriger. On rouvre un gestionnaire, on y rattache le contrôle, et on écrit
     * ce que l'on sait.
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
            // Rien de plus à tenter : les données sont saines (rollback), et le statut
            // en base reste celui d'avant l'écriture, donc jamais « terminé ».
        }

        return $run;
    }
}
