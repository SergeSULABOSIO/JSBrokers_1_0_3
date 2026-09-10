<?php

namespace App\Echange\Reprise;

use App\Ai\Mutation\DefautsContextuels;
use App\Ai\Mutation\MutationPlan;
use App\Ai\Mutation\MutationOperation;
use App\Ai\Mutation\NormaliseurDeDates;
use App\Echange\Canevas\CanevasDEchange;
use App\Echange\Classeur\LigneLue;
use App\Echange\Etat\CatalogueDesColonnes;
use App\Echange\Etat\ColonneEtat;
use App\Echange\Etat\EtatDuPortefeuille;
use App\Echange\Service\Anomalie;
use App\Echange\Service\ResolveurDeRenvois;
use App\Entity\Chargement;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Note;
use App\Entity\TypeRevenu;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\Shared\Date as DateExcel;

/**
 * UNE LIGNE DEVIENT UNE CHAÎNE D'ÉCRITURES : client, opportunité, proposition, police,
 * échéance.
 *
 * ── CE QUE CE SERVICE RÉSOUT ────────────────────────────────────────────────────────
 * La feuille `DONNEES` décrit une TRANCHE par ligne, mais chaque ligne porte toute son
 * ascendance. Une police à quatre échéances occupe donc quatre lignes qui répètent le
 * même client, le même risque, la même proposition.
 *
 * ⚠ SANS CONVERGENCE, CES QUATRE LIGNES CRÉERAIENT QUATRE POLICES. Et quatre clients, et
 * quatre propositions. C'est la faute la plus probable de toute la reprise et la plus
 * coûteuse : elle ne casse rien, elle DUPLIQUE — le portefeuille double de volume, les
 * primes se comptent plusieurs fois, et l'on ne s'en aperçoit qu'aux totaux.
 *
 * D'où le REGISTRE : un niveau déjà écrit pour un repère ne l'est plus, et les lignes
 * suivantes s'y RENVOIENT. Le repère vient de {@see CleNaturelle}, dérivé du contenu de la
 * ligne et non de son rang — l'ordre du fichier n'a donc aucune importance, et
 * l'utilisateur a le droit de le trier.
 *
 * ── AUCUN CIRCUIT D'ÉCRITURE NOUVEAU ────────────────────────────────────────────────
 * ⚠ CE SERVICE N'ÉCRIT RIEN. Il rend des {@see MutationOperation} chaînées par
 * « @étiquette » — celles-là mêmes que produit l'assistant et qu'exécute
 * `WorkspaceMutationService`, qui sait déjà descendre récursivement dans les collections.
 * Droits, champs obligatoires, validation par le formulaire, dry-run : tout est écrit
 * ailleurs, et le redire ici serait s'engager à le maintenir deux fois.
 *
 * ── TROIS NIVEAUX POUR RATTACHER, ET JAMAIS DE DEVINETTE ────────────────────────────
 * Pour un client, un risque, un assureur, un portefeuille, un intermédiaire :
 *   1. la ligne porte un libellé qui désigne UNE entité du cabinet → on s'y rattache ;
 *   2. il en désigne PLUSIEURS → refus nommé, car créer un homonyme de plus serait pire ;
 *   3. il n'en désigne aucune → on la crée, sous un repère stable.
 *
 * ⚠ LES CATALOGUES SUIVENT UNE AUTRE RÈGLE, et c'est délibéré : voir `reconnu()`. Deux
 * clients nommés « SARL Martin » sont deux affaires ; deux types de chargement nommés
 * « Prime nette » sont un même poste d'assiette écrit deux fois. Le premier cas se
 * refuse, le second retient le premier venu en le signalant.
 */
final class ReconstitueurDeTranche
{
    /**
     * Les colonnes sans lesquelles une ligne ne peut rien produire.
     *
     * ⚠ LA RÉFÉRENCE DE POLICE EST LA CLÉ, ET RIEN D'AUTRE NE CONVIENT. Deux polices du
     * même client, chez le même assureur, sur le même risque et démarrant le même jour
     * sont parfaitement possibles : les départager sur ces valeurs fusionnerait deux
     * affaires distinctes, et les séparer à tort en dupliquerait une.
     */
    private const COLONNE_CLE = 'policeReference';

    /**
     * La référence portée par toute écriture d'OUVERTURE.
     *
     * ⚠ ELLE N'EST PAS DÉCORATIVE. Sans elle, un règlement de reprise ressemble trait pour
     * trait à un encaissement réel : impossible, six mois plus tard, de distinguer ce que
     * le cabinet a vraiment reçu de ce qu'on a déclaré en reprenant ses données.
     */
    private const REFERENCE_OUVERTURE = 'REPRISE';

    /**
     * De combien la commission encaissée peut dépasser un revenu FORFAITAIRE avant qu'on
     * y voie un taux écrit sans son signe pourcent.
     *
     * ⚠ DEUX, ET NON UN VIRGULE DEUX. Les taxes n'ajoutent qu'une fraction au forfait
     * (seize pour cent ici), mais un cabinet peut avoir arrondi, ou porté sur une échéance
     * un encaissement qui en couvre deux. Serrer la marge ferait reprocher à des fichiers
     * justes ; on ne veut attraper que l'absurde — cinq contre mille cent soixante.
     */
    private const MARGE_DU_FORFAIT = 2.0;

    /** @var array<string, true> repères déjà produits dans cette passe */
    private array $registre = [];

    /**
     * @var array<string, string> repère du revenu posé pour chaque proposition
     *
     * ⚠ IL FAUT LE RETENIR ENTRE LES LIGNES. La deuxième échéance d'une même police ne
     * recrée pas la proposition — donc pas ses revenus —, mais elle doit pouvoir ouvrir
     * sa propre commission, et son article a besoin du revenu à facturer. Sans ce
     * registre, la première échéance ouvrait sa commission et les suivantes non : un
     * encaissement perdu sur trois échéances sur quatre.
     *
     * ⚠ ET SEULEMENT CE QUE CETTE PASSE A POSÉ. Un revenu déjà en base n'a pas de repère
     * local : y renvoyer produirait un lien irrésolu. L'absence est donc une réponse
     * valable, que `ouvrirLaCommission()` traduit en refus nommé.
     */
    private array $revenuParProposition = [];

    /**
     * @var array<string, int|null> qui DOIT la commission de chaque proposition
     *
     * ⚠ IL DÉCIDE À QUI LA NOTE DE REPRISE EST ADRESSÉE, et cela ne se devine pas :
     * `TypeRevenu::$redevable` le dit — l'assureur précompte, ou le client règle. Une note
     * adressée au mauvais payeur fausserait le relevé de compte de l'un comme de l'autre.
     *
     * Retenu en même temps que le repère ci-dessus, et pour la même raison : le type de
     * revenu n'est connu qu'au moment où la proposition se construit.
     */
    private array $redevableParProposition = [];

    public function __construct(
        private readonly ResolveurDeRenvois $resolveur,
        private readonly NormaliseurDeDates $dates,
        private readonly EntityManagerInterface $em,
        private readonly DefautsContextuels $defauts,
        // Le registre ci-dessus ne connaît que le fichier en cours. Celui-ci connaît le
        // portefeuille : sans lui, deux dépôts successifs empilent deux fois la même police.
        private readonly ChaineExistante $chaine,
    ) {
    }

    /**
     * L'invité pour le compte de qui la reprise est faite.
     *
     * ⚠ IL SERT DE GESTIONNAIRE PAR DÉFAUT. Un portefeuille exige un gestionnaire de
     * compte, et le classeur de reprise ne porte pas cette colonne : sans lui, toute
     * ligne nommant un portefeuille était refusée sur « gestionnaire : relation
     * obligatoire à préciser » — un motif illisible, désignant une case qui n'existe pas.
     *
     * Celui qui dépose le fichier est le choix évident : c'est lui qui prend la reprise en
     * charge, et le gestionnaire se change ensuite d'un clic à l'écran.
     */
    private ?Invite $pourLeCompteDe = null;

    /** Le registre est propre entre deux contrôles — le service est partagé. */
    public function reinitialiser(?Invite $pourLeCompteDe = null): void
    {
        $this->pourLeCompteDe = $pourLeCompteDe;
        $this->registre = [];
        $this->revenuParProposition = [];
        $this->redevableParProposition = [];
        // ⚠ ET LES DEUX INDEX DE LA BASE AVEC LUI. Une passe d'écriture vient peut-être de
        // créer des polices, des clients, des risques : un index resté tiède ne les
        // connaîtrait pas, et la passe suivante les recréerait.
        //
        // Mesuré : sans la ligne du résolveur, le second palier d'une écriture butait sur
        // « Duplicate entry pour uniq_risque_entreprise_cle » — l'index UNIQUE de la base
        // rattrapait ce que l'application avait laissé passer, et faisait échouer tout le
        // palier sur une erreur SQL que rien ne préparait à lire.
        $this->chaine->reinitialiser();
        $this->resolveur->reinitialiser();
    }

    /**
     * OUBLIE les repères d'une ligne REFUSÉE.
     *
     * ⚠ SANS CELA, UNE SEULE LIGNE FAUTIVE EN ENTRAÎNE TOUTES LES AUTRES — et les accuse à
     * sa place. Le registre retient « cette proposition est déjà produite », sans savoir
     * si l'opération a été ACCEPTÉE. Si la première échéance d'une police est rejetée, les
     * suivantes croient la proposition créée et renvoient à « @cot-… » : elles échouent
     * toutes sur « renvoi inconnu », un motif qui parle du lien et non de la cause. Le
     * rapport annonce alors huit erreurs pour une, et la vraie se noie.
     *
     * Oubliés, les repères sont reproduits par la ligne suivante — qui échouera pour SA
     * raison, la bonne, ou passera si elle est correcte.
     *
     * @param string[] $reperes
     */
    public function oublier(array $reperes): void
    {
        foreach ($reperes as $repere) {
            unset($this->registre[$repere]);

            foreach ($this->revenuParProposition as $proposition => $revenu) {
                if ($proposition === $repere || $revenu === $repere) {
                    unset($this->revenuParProposition[$proposition]);
                }
            }
        }
    }

    /**
     * LA CHAÎNE D'ÉCRITURES D'UNE LIGNE, dans l'ordre où elle doit être exécutée.
     *
     * @param array<string, ColonneEtat> $colonnes
     * @param Anomalie[]                 $anomalies rempli des refus rencontrés
     *
     * @return array<int, MutationOperation> vide si la ligne ne peut rien produire
     */
    public function pour(LigneLue $ligne, array $colonnes, Entreprise $entreprise, array &$anomalies): array
    {
        $action = mb_strtoupper($ligne->texte(CanevasDEchange::COL_ACTION));
        $id = $this->identifiant($ligne);

        // ── Une suppression est écrite, jamais déduite ───────────────────────────────
        if ($action === CanevasDEchange::ACTION_SUPPRIMER) {
            return $this->suppression($ligne, $id, $anomalies);
        }

        $reference = $ligne->texte(self::COLONNE_CLE);
        $cle = CleNaturelle::cleDeLaPolice($reference);

        // ⚠ UNE LIGNE SANS RÉFÉRENCE DE POLICE NE PEUT PAS ÊTRE RATTACHÉE — sauf si elle
        // porte l'identifiant d'une tranche existante, cas où l'ascendance est déjà en
        // base et n'a pas à être devinée.
        if ($cle === null && $id === null) {
            $anomalies[] = $this->refus(
                $ligne,
                self::COLONNE_CLE,
                'Cette ligne ne porte aucune référence de police. Sans elle, impossible de savoir '
                . 'de quel contrat il s\'agit, ni s\'il existe déjà chez vous. Remplissez la colonne '
                . 'de la référence — celle qui figure sur la police de votre assureur.',
            );

            return [];
        }

        // Une tranche déjà identifiée : on ne retouche que l'échéance elle-même. Refaire
        // toute son ascendance à chaque export réimporté écrirait des modifications que
        // personne n'a demandées — et le journal annoncerait cinq écritures pour une.
        if ($id !== null) {
            $operation = $this->tranche($ligne, $colonnes, $id, null);

            return $operation === null ? [] : [$operation];
        }

        // ── CETTE POLICE EST-ELLE DÉJÀ DANS LE PORTEFEUILLE ? ───────────────────────
        //
        // ⚠ C'EST LA QUESTION QUE LE REGISTRE NE POSAIT PAS. Il fait converger les lignes
        // d'un MÊME fichier, et rien de plus : le deuxième dépôt d'un portefeuille —
        // découpé parce qu'il était trop gros, corrigé après une erreur, ou complété six
        // mois plus tard — recréait l'opportunité, la proposition et la police. Rien ne
        // cassait, tout doublait, et cela ne se voyait qu'aux totaux.
        //
        // La réponse ne change RIEN au reste de cette méthode : une chaîne trouvée est un
        // identifiant, exactement comme un client reconnu par `rattacher()`.
        $numeroAvenant = $ligne->texte('policeNumeroAvenant');

        if ($this->chaine->estAmbigue($reference, $numeroAvenant, $entreprise)) {
            $anomalies[] = $this->refus(
                $ligne,
                self::COLONNE_CLE,
                sprintf(
                    'Vous avez déjà plusieurs polices portant la référence « %s ». On ne peut pas '
                    . 'deviner à laquelle rattacher cette échéance. Ouvrez la rubrique des polices, '
                    . 'gardez-en une seule, puis redéposez ce fichier.',
                    $reference,
                ),
            );

            return [];
        }

        $existante = $this->chaine->pour($reference, $numeroAvenant, $entreprise);
        $idPiste = $existante['piste'] ?? null;
        $idCotation = $existante['cotation'] ?? null;
        $idAvenant = $existante['avenant'] ?? null;

        $operations = [];

        // ── Les niveaux nommés par un libellé ───────────────────────────────────────
        $client = $this->rattacher('Client', $ligne, 'assure', CleNaturelle::CLIENT, $entreprise, $anomalies, $operations, [
            'nom' => $ligne->texte('assure'),
        ]);
        if ($client === null) {
            return [];
        }

        // Le portefeuille se pose sur le CLIENT, et n'est donc renseigné qu'à sa création.
        $portefeuille = $this->rattacher('Portefeuille', $ligne, 'portefeuille', CleNaturelle::PORTEFEUILLE, $entreprise, $anomalies, $operations, [
            'nom' => $ligne->texte('portefeuille'),
            // ⚠ SANS GESTIONNAIRE, LE PORTEFEUILLE NE PEUT PAS NAÎTRE — et le classeur ne
            // porte pas cette colonne. Celui qui dépose le fichier en répond ; cela se
            // change d'un clic à l'écran, alors qu'un refus incompréhensible bloquait tout.
            'gestionnaire' => $this->pourLeCompteDe?->getId(),
        ]);

        $risque = $this->rattacher('Risque', $ligne, 'risque', CleNaturelle::RISQUE, $entreprise, $anomalies, $operations, [
            'nomComplet' => $ligne->texte('risque'),
            'code' => $ligne->texte('risque'),
            // ⚠ « IMPOSABLE » EST OBLIGATOIRE ET N'A PAS DE DÉFAUT DE FORMULAIRE : sans
            // cette ligne, toute reprise nommant un risque que le cabinet n'a pas encore
            // échouait sur « imposable : champ obligatoire à renseigner » — un motif
            // exact, mais qui désigne une case que le classeur ne porte pas et que
            // l'utilisateur ne peut donc pas remplir.
            //
            // La valeur est celle du semis officiel du projet
            // ({@see \App\Services\ServiceInitialisationEntreprise::initialiserRisques()}) :
            // en assurance, la prime est taxée, et l'exception se règle à la fiche.
            'imposable' => true,
        ]);

        $assureur = $this->rattacher('Assureur', $ligne, 'assureur', CleNaturelle::ASSUREUR, $entreprise, $anomalies, $operations, [
            'nom' => $ligne->texte('assureur'),
        ]);

        $intermediaire = $this->rattacher('Partenaire', $ligne, 'intermediaire', CleNaturelle::PARTENAIRE, $entreprise, $anomalies, $operations, [
            'nom' => $ligne->texte('intermediaire'),
        ]);

        // ⚠ UNE OPPORTUNITÉ SANS RISQUE EST IMPOSSIBLE, ET LE REFUS DOIT LE DIRE ICI.
        //
        // `Piste::descriptionDuRisque` est obligatoire et se déduit du risque de la ligne.
        // Sans lui, le contrôle échouait plus loin sur « descriptionDuRisque : champ
        // obligatoire à renseigner » — un motif exact qui désigne une colonne que le
        // classeur ne porte pas sous ce nom, et que l'utilisateur ne peut donc pas
        // remplir. On nomme la colonne qu'il a sous les yeux.
        //
        // Rien de tout cela ne concerne une police DÉJÀ en base : son opportunité existe,
        // et le risque n'a pas à être redonné.
        if ($idPiste === null && $risque === null && $ligne->texte('risque') === '') {
            $anomalies[] = $this->refus(
                $ligne,
                'risque',
                'Cette ligne ne dit pas ce qui est assuré. Remplissez la colonne du risque — '
                . 'par exemple « RC Automobile » ou « Incendie ». Le nom suffit : s\'il n\'existe '
                . 'pas encore chez vous, il sera créé.',
            );

            return [];
        }

        // ── L'opportunité ───────────────────────────────────────────────────────────
        //
        // Déjà en base : on s'y rattache par son identifiant et l'on n'écrit rien. Refaire
        // son ascendance à chaque dépôt produirait des modifications que personne n'a
        // demandées, et un journal annonçant cinq écritures pour une.
        $piste = (string) CleNaturelle::pourChaine(CleNaturelle::PISTE, $reference);
        if ($idPiste === null && $this->neuf($piste)) {
            $champs = [
                'nom' => $this->nomDeLAffaire($ligne),
                'client' => $client,
                'risque' => $risque,
                // ⚠ L'EXERCICE SUIT LA DATE D'EFFET, PAS L'ANNÉE COURANTE. `PisteType`
                // propose bien l'année en cours par défaut — juste pour une saisie du
                // jour, faux pour une reprise : rapatrier en 2027 des polices de 2025 les
                // rangerait toutes dans le mauvais exercice, et les états par période
                // deviendraient inexploitables sur toute la reprise.
                'exercice' => $this->exercice($ligne),
                // ⚠ LA DESCRIPTION DU RISQUE VIENT DE LA LIGNE, ET NON DU PLAN.
                // `DefautsContextuels` la déduit du champ `risque` — mais seulement quand
                // celui-ci est un RENVOI vers un risque créé dans le même plan, sa règle
                // étant de ne rien poser dont la source ne soit sous ses yeux. Or une
                // reprise rattache le plus souvent un risque DÉJÀ en base : le champ porte
                // alors un identifiant, dont le plan ne sait pas lire le nom, et la
                // déduction n'a pas lieu. La ligne, elle, porte le libellé : c'est la
                // source la plus proche, et la seule qui ne dépende de rien.
                'descriptionDuRisque' => $ligne->texte('risque'),
            ];
            if ($intermediaire !== null) {
                $champs['partenaire'] = $intermediaire;
            }
            $operations[] = new MutationOperation(
                op: MutationOperation::OP_CREATE,
                entityShortName: 'Piste',
                fields: $this->sansVide($champs),
                ref: $piste,
            );
        }

        $renvoiPiste = $idPiste ?? CleNaturelle::renvoiVers($piste);

        // ⚠ UNE POLICE SANS DATES N'EN EST PAS UNE, et le refus doit le dire ICI.
        //
        // La couverture court d'une date à une autre : les deux sont obligatoires. Sans
        // elles, le contrôle échouait plus loin sur « Durée (en mois) » — la durée se
        // DÉDUIT de ces dates — et l'utilisateur cherchait une colonne « durée » que le
        // classeur ne porte pas, pendant que les deux cases à remplir restaient muettes.
        //
        // Rien de tout cela ne concerne une police déjà en base : ses dates y sont.
        $dateEffet = $this->date($ligne, 'policeDateEffet', 'Avenant', 'startingAt');
        $dateEcheance = $this->date($ligne, 'policeEcheance', 'Avenant', 'endingAt');

        if ($idAvenant === null) {
            foreach (['policeDateEffet' => $dateEffet, 'policeEcheance' => $dateEcheance] as $code => $valeur) {
                if ($valeur !== null) {
                    continue;
                }

                $anomalies[] = $this->refus($ligne, $code, sprintf(
                    'Remplissez la colonne « %s » : une police couvre une période, et cette '
                    . 'date en marque %s. Écrivez-la comme dans votre tableur, par exemple '
                    . '01/01/2026.',
                    $colonnes[$code]->libelle ?? $code,
                    $code === 'policeDateEffet' ? 'le début' : 'la fin',
                ));
            }

            if ($dateEffet === null || $dateEcheance === null) {
                return [];
            }
        }

        // ── La proposition, et avec elle la PRIME et la RÉMUNÉRATION ────────────────
        $cotation = (string) CleNaturelle::pourChaine(CleNaturelle::COTATION, $reference);
        if ($idCotation === null && $this->neuf($cotation)) {
            $collections = [];

            // ⚠ C'EST ICI QUE LA PRIME REVIENT. `ChargementPourPrime` n'a pas de feuille
            // dans le format normalisé — elle est absente du périmètre d'échange —, si
            // bien qu'une reprise par ce format rend des propositions SANS PRIME. On
            // l'écrit donc en COLLECTION IMBRIQUÉE de la proposition, ce que le circuit
            // d'écriture sait déjà faire.
            foreach ($this->chargementsDeLaLigne($ligne) as $fonction => $montant) {
                $type = $this->typePourFonction($entreprise, $fonction);
                if ($type === null) {
                    $anomalies[] = $this->refus($ligne, CatalogueDesColonnes::codeDeFonction($fonction), sprintf(
                        'Votre cabinet n\'a pas encore de « %s » dans sa liste des composantes de '
                        . 'la prime. Créez-la dans la rubrique « Chargements », puis redéposez ce '
                        . 'fichier : sans elle, ce montant ne saurait pas où se placer.',
                        CatalogueDesColonnes::FONCTIONS[$fonction],
                    ));
                    continue;
                }

                $collections['chargements'][] = new MutationOperation(
                    op: MutationOperation::OP_CREATE,
                    entityShortName: 'ChargementPourPrime',
                    fields: $this->sansVide([
                        'nom' => CatalogueDesColonnes::FONCTIONS[$fonction],
                        'type' => $type,
                        'montantFlatExceptionel' => $montant,
                    ]),
                );
            }

            // ⚠ ET LE TAUX N'EST ÉCRIT QUE S'IL DÉROGE. Un type marqué « pourcentage du
            // risque » va chercher le sien à la LECTURE : le recopier le figerait, et la
            // commission cesserait de suivre le risque le jour où son taux change.
            foreach ($this->termes($ligne, 'commissionRevenus', $anomalies) as $nom => $terme) {
                $type = $this->reconnu('TypeRevenu', $nom, $entreprise, $ligne, 'commissionRevenus', $anomalies);
                if ($type === null) {
                    continue;
                }

                // ⚠ « 5 » ET « 5% » NE DISENT PAS LA MÊME CHOSE. Écrit sans le signe
                // pourcent, un taux de commission devient un forfait de cinq unités
                // monétaires : la note de reprise affiche alors 5,80 de dû pour 1 160,00
                // encaissés, et un solde négatif de −1 154,20. Le contrôle laissait passer,
                // parce qu'un forfait de cinq est une valeur licite en soi.
                $reproche = $this->ambiguiteDuRevenu($ligne, $nom, $terme);
                if ($reproche !== null) {
                    $anomalies[] = $reproche;
                    continue;
                }

                $champs = ['nom' => $nom, 'typeRevenu' => $type];
                if ($terme['valeur'] !== null) {
                    $champs[$terme['estTaux'] ? 'tauxExceptionel' : 'montantFlatExceptionel'] = $terme['valeur'];
                }

                // ⚠ UN REPÈRE SUR L'ENFANT, ET IL SERT. L'écriture d'ouverture de la
                // commission a besoin de désigner CE revenu : un article sans revenu à
                // facturer vaut zéro par construction. Le circuit d'écriture déclare le
                // repère des enfants de collection avec leur identifiant, au dry-run comme
                // à l'exécution — c'est ce qui rend ce renvoi possible.
                $this->revenuParProposition[$cotation] ??= (string) CleNaturelle::pourLibelle(
                    'rev',
                    $cotation . ' ' . $nom,
                );
                // Le payeur suit le repère : c'est lui qui dira à qui adresser la note de
                // reprise d'une commission déjà encaissée.
                $this->redevableParProposition[$cotation] ??= $this->redevableDuType($type);
                $repereRevenu = $this->revenuParProposition[$cotation];

                $collections['revenus'][] = new MutationOperation(
                    op: MutationOperation::OP_CREATE,
                    entityShortName: 'RevenuPourCourtier',
                    fields: $this->sansVide($champs),
                    ref: $repereRevenu,
                );
            }

            $operations[] = new MutationOperation(
                op: MutationOperation::OP_CREATE,
                entityShortName: 'Cotation',
                fields: $this->sansVide([
                    'nom' => $this->nomDeLAffaire($ligne),
                    'piste' => $renvoiPiste,
                    'assureur' => $assureur,
                    // ⚠ LA DURÉE SE LIT SUR LA PÉRIODE, elle ne se suppose pas : un contrat
                    // de vingt-deux jours n'est pas une police annuelle. `DefautsContextuels`
                    // sait la déduire, mais en allant la chercher sur l'avenant EN
                    // COLLECTION de la proposition — or la convergence impose ici de le
                    // garder en opération distincte, une police et son avenant n° 2
                    // partageant la même proposition. On emprunte donc la formule, sans la
                    // réécrire.
                    'duree' => $this->defauts->dureeEnMois($dateEffet, $dateEcheance),
                ]),
                collections: $collections,
                ref: $cotation,
            );
        }

        $renvoiCotation = $idCotation ?? CleNaturelle::renvoiVers($cotation);

        // ── La police ───────────────────────────────────────────────────────────────
        $avenant = (string) CleNaturelle::pourAvenant($reference, $numeroAvenant);
        if ($idAvenant === null && $this->neuf($avenant)) {
            $operations[] = new MutationOperation(
                op: MutationOperation::OP_CREATE,
                entityShortName: 'Avenant',
                fields: $this->sansVide([
                    'referencePolice' => $reference,
                    'numero' => $numeroAvenant,
                    'startingAt' => $dateEffet,
                    'endingAt' => $dateEcheance,
                    'cotation' => $renvoiCotation,
                ]),
                ref: $avenant,
            );
        } elseif ($idAvenant !== null && $idCotation === null && $this->neuf($avenant)) {
            // ⚠ UNE POLICE SANS PROPOSITION EXISTE, et il faut la rattacher plutôt que de
            // la doubler. Le lien est nullable en base : une police a pu être créée à la
            // main, ou perdre sa proposition. La recréer violerait l'unicité de sa
            // référence ; l'ignorer laisserait une proposition neuve sans police.
            $operations[] = new MutationOperation(
                op: MutationOperation::OP_EDIT,
                entityShortName: 'Avenant',
                targetId: $idAvenant,
                fields: ['cotation' => $renvoiCotation],
            );
        }

        // ── L'échéance elle-même : une par ligne, jamais dédupliquée ────────────────
        //
        // ⚠ ELLE PORTE UN REPÈRE, contrairement aux niveaux au-dessus qui convergent : ce
        // repère ne sert pas à dédupliquer — chaque ligne fait sa tranche — mais à ce que
        // les écritures d'OUVERTURE puissent la désigner. Il est donc unique par ligne.
        $repereTranche = (string) CleNaturelle::pourLibelle(
            'tra',
            $cle . ' ' . $ligne->numero . ' ' . $ligne->texte('trancheNom'),
        );

        // ⚠ ET ELLE NE SE DÉDOUBLE PAS D'UN DÉPÔT À L'AUTRE. Le repère ci-dessus porte le
        // numéro de ligne — c'est ce qui le rend unique DANS le fichier, et c'est son seul
        // rôle. Il ne peut donc rien dire du portefeuille : sans la clé ci-dessous, un
        // redépôt ajoutait une échéance de plus à chaque fois, sous la bonne police.
        $signes = ChaineExistante::signesDeLEcheance(
            $ligne->texte('trancheNom'),
            $this->date($ligne, 'tranchePayableAt', 'Tranche', 'payableAt'),
            $this->date($ligne, 'trancheEcheanceAt', 'Tranche', 'echeanceAt'),
        );

        // Rien pour la distinguer, et une proposition qui existe déjà : on le DIT. Écrire
        // en silence une échéance qu'un second dépôt ajoutera de nouveau, c'est promettre
        // une idempotence qu'on ne tient pas.
        if ($signes === [] && $idCotation !== null) {
            $anomalies[] = Anomalie::avertissement(
                Anomalie::VALEUR_INVALIDE,
                'Cette échéance n\'a ni nom, ni date : rien ne la distingue des autres échéances '
                . 'de la même police. Elle sera bien enregistrée, mais si vous redéposez ce fichier '
                . 'elle le sera une seconde fois. Remplissez au moins son nom ou une de ses dates '
                . 'pour l\'éviter.',
                $ligne->feuille,
                $ligne->numero,
                $ligne->colonne('trancheNom'),
            );
        }

        $idEcheance = $this->chaine->echeance($idCotation, $signes, $entreprise);

        // Retrouvée : on la met à jour. Le rattachement à la proposition n'est alors plus
        // à écrire — il existe, et le réaffirmer serait une modification de plus au journal.
        $tranche = $this->tranche($ligne, $colonnes, $idEcheance, $idEcheance === null ? $renvoiCotation : null);
        if ($tranche === null) {
            return $operations;
        }

        // La prime déjà réglée devient UNE écriture, imbriquée sous l'échéance.
        //
        // ⚠ À LA CRÉATION SEULEMENT, et c'est vital depuis que les échéances se
        // retrouvent en base. Relire un solde d'ouverture sur une échéance existante
        // ajouterait un second règlement à chaque dépôt, sans rien signaler : la prime
        // paraîtrait encaissée deux fois, et le solde du client tomberait à zéro.
        $paiement = $idEcheance === null ? $this->ouverturePrime($ligne) : null;
        if ($paiement !== null) {
            $tranche = $tranche->withCollections(['paiementsPrime' => [$paiement]]);
        }

        $operations[] = new MutationOperation(
            op: $tranche->op,
            entityShortName: $tranche->entityShortName,
            targetId: $tranche->targetId,
            fields: $tranche->fields,
            collections: $tranche->collections,
            ref: $repereTranche,
        );

        // ⚠ MÊME RÈGLE QUE POUR LA PRIME : un solde d'ouverture ne se relit pas. Rejouer
        // ces écritures sur une échéance déjà reprise doublerait les encaissements et les
        // reversements à chaque dépôt du même fichier.
        //
        // ⚠ ELLE MANQUAIT À LA COMMISSION, et c'était sans conséquence tant qu'elle
        // n'écrivait rien. Maintenant qu'elle écrit, l'oubli coûterait une note de plus par
        // aller-retour.
        if ($idEcheance === null) {
            $this->ouvrirLaCommission(
                $ligne,
                $repereTranche,
                $this->revenuParProposition[$cotation] ?? null,
                $this->redevableParProposition[$cotation] ?? null,
                $assureur,
                $client,
                $operations,
                $anomalies,
            );
            $this->ouvrirLaRetro($ligne, $repereTranche, $intermediaire, $operations, $anomalies);
        }

        // ⚠ LES CHAMPS OBLIGATOIRES DÉDUCTIBLES SONT POSÉS PAR LE SERVICE QUI EXISTE.
        //
        // Une opportunité exige un type d'avenant, un nom et une description du risque ;
        // une proposition, un nom et une durée. Rien de tout cela ne figure dans une ligne
        // du classeur — et rien n'a besoin d'y figurer : `DefautsContextuels` le DÉDUIT du
        // dossier lui-même (le nom vient du risque et du client, la durée se lit sur la
        // période de la police, une création sans police de base est une souscription).
        //
        // Faute de l'employer, les soixante-dix-neuf lignes d'une reprise étaient rejetées
        // sur « typeAvenant : champ obligatoire », et les échéances suivantes de chaque
        // police en cascade sur un renvoi « @cot-… inconnu » — une erreur qui accusait le
        // lien au lieu de sa cause. Réécrire ces déductions ici en aurait fait une seconde
        // version à tenir en accord avec celle de l'assistant.
        ['plan' => $plan] = $this->defauts->appliquer(new MutationPlan($operations));

        return $plan->operations;
    }

    /**
     * L'ÉCRITURE D'OUVERTURE DE LA PRIME : ce que le client avait déjà réglé.
     *
     * ⚠ UNE SEULE ÉCRITURE POUR UN SOLDE, ET C'EST ASSUMÉ. Un solde de 8 000 devient un
     * règlement de 8 000, non les trois versements qui l'ont composé : une ligne plate ne
     * peut pas porter un journal. C'est la sémantique d'une reprise — on repart d'une
     * situation juste, pas d'une comptabilité rejouée.
     *
     * ⚠ ET SEULEMENT À LA CRÉATION. Cette méthode n'est appelée que sur une échéance
     * NOUVELLE : la relire sur une échéance existante ajouterait un second règlement à
     * chaque dépôt du même fichier, et les encaissements doubleraient à chaque
     * aller-retour.
     */
    private function ouverturePrime(LigneLue $ligne): ?MutationOperation
    {
        $montant = $this->nombre($ligne, 'ouverturePrimeEncaissee');
        if ($montant === null || $montant <= 0.0) {
            return null;
        }

        return new MutationOperation(
            op: MutationOperation::OP_CREATE,
            entityShortName: 'PaiementPrime',
            fields: $this->sansVide([
                'montant' => $montant,
                'paidAt' => $this->date($ligne, 'ouverturePrimeLe', 'PaiementPrime', 'paidAt')
                    ?? $this->date($ligne, 'policeDateEffet', 'PaiementPrime', 'paidAt'),
                'reference' => self::REFERENCE_OUVERTURE,
                'description' => 'Situation reprise depuis un classeur de reprise.',
            ]),
        );
    }

    /**
     * L'ENCAISSEMENT DE COMMISSION DEVIENT UNE NOTE, SON ARTICLE ET SON RÈGLEMENT.
     *
     * ── POURQUOI CELA A LONGTEMPS ÉTÉ REFUSÉ, ET POURQUOI C'ÉTAIT UNE ERREUR ────────
     * ⚠ DEUX COLONNES NON NULLES ABSENTES DU FORMULAIRE bloquaient toute création de note
     * par le circuit commun : `Note::$validated` et `Note::$signature`. Le contrôle à blanc
     * les réclamait — elles sont obligatoires — sans qu'aucun champ ne permette de les
     * fournir. Constaté le 08/09/2026 : cinquante refus, un par échéance encaissée.
     *
     * On en avait conclu qu'une note de reprise posait une question métier — validée par
     * qui, signée par qui ? Elle n'en pose aucune : l'écran lui-même y met `false` et
     * l'horodatage du moment, depuis toujours. Ces valeurs ont rejoint
     * {@see \App\Service\Workspace\ValeursDeNaissance}, que le circuit commun applique
     * à toute création — et le blocage a disparu avec elles.
     *
     * ── CE QUE LE CALCUL EXIGE, ET RIEN DE PLUS ───────────────────────────────────
     * `getTrancheMontantCommissionEncaissee()` applique à chaque article la proportion
     * payée de la note ENTIÈRE. Avec UNE note par échéance, UN article et UN règlement, la
     * commission encaissée vaut donc exactement ce qui a été versé — que l'encaissement
     * soit complet ou partiel, sans qu'aucun montant ne soit forcé.
     *
     * ⚠ UNE NOTE PAR ÉCHÉANCE, ET C'EST UNE CONTRAINTE DU CALCUL : une note qui grouperait
     * plusieurs échéances ne saurait pas exprimer des taux d'encaissement différents.
     *
     * ⚠ L'ARTICLE EXIGE `tranche` ET `revenuFacture`, sinon `getArticleMontant()` rend zéro
     * et la note ne compte rien. C'est pourquoi le repère du revenu voyage jusqu'ici.
     *
     * ⚠ ET LE DESTINATAIRE SE DÉDUIT, IL NE SE DEVINE PAS. `TypeRevenu::$redevable` dit qui
     * doit la commission ; le calcul n'accepte d'ailleurs que les notes adressées au client
     * ou à l'assureur.
     *
     * @param array<int, MutationOperation> $operations
     * @param Anomalie[]                    $anomalies
     */
    private function ouvrirLaCommission(
        LigneLue $ligne,
        string $repereTranche,
        ?string $repereRevenu,
        ?int $redevable,
        int|string|null $assureur,
        int|string|null $client,
        array &$operations,
        array &$anomalies,
    ): void {
        $montant = $this->nombre($ligne, 'ouvertureCommissionEncaissee');
        if ($montant === null || $montant <= 0.0) {
            return;
        }

        // ⚠ SANS REVENU, LA NOTE NE COMPTERAIT RIEN. `getArticleMontant()` se calcule du
        // revenu facturé : un article qui n'en désigne aucun vaut zéro, et la note serait
        // une coquille que le portefeuille afficherait sans jamais l'additionner. Mieux
        // vaut le dire que d'écrire un chiffre qui ne comptera pas.
        if ($repereRevenu === null) {
            $anomalies[] = Anomalie::avertissement(
                Anomalie::VALEUR_INVALIDE,
                sprintf(
                    'La commission de %s que vous avez déjà encaissée n\'a pas pu être '
                    . 'enregistrée : cette ligne ne dit pas de quelle commission il s\'agit. '
                    . 'Remplissez la colonne « Commission · Revenus » — par exemple '
                    . '« Commission Ordinaire » — et l\'encaissement suivra.',
                    number_format($montant, 2, ',', ' '),
                ),
                $ligne->feuille,
                $ligne->numero,
                $ligne->colonne('ouvertureCommissionEncaissee'),
            );

            return;
        }

        // Le payeur décide de l'adressage : le calcul ne retient que les notes adressées au
        // client ou à l'assureur, et le relevé de compte de chacun en dépend.
        $auClient = $redevable === TypeRevenu::REDEVABLE_CLIENT;
        $destinataire = $auClient ? $client : $assureur;

        if ($destinataire === null) {
            $anomalies[] = Anomalie::avertissement(
                Anomalie::VALEUR_INVALIDE,
                sprintf(
                    'La commission de %s que vous avez déjà encaissée n\'a pas pu être '
                    . 'enregistrée : on ne sait pas qui vous l\'a versée. Renseignez la '
                    . 'colonne « %s » de cette ligne.',
                    number_format($montant, 2, ',', ' '),
                    $auClient ? 'Assuré' : 'Assureur',
                ),
                $ligne->feuille,
                $ligne->numero,
                $ligne->colonne('ouvertureCommissionEncaissee'),
            );

            return;
        }

        $date = $this->date($ligne, 'ouvertureCommissionLe', 'Paiement', 'paidAt')
            ?? $this->date($ligne, 'policeDateEffet', 'Paiement', 'paidAt');

        $operations[] = new MutationOperation(
            op: MutationOperation::OP_CREATE,
            entityShortName: 'Note',
            fields: $this->sansVide([
                'nom' => 'Commission encaissée — reprise',
                // Une commission est un DÉBIT : le courtier réclame ce qui lui revient.
                'type' => Note::TYPE_NOTE_DE_DEBIT,
                'addressedTo' => $auClient ? Note::TO_CLIENT : Note::TO_ASSUREUR,
                $auClient ? 'client' : 'assureur' => $destinataire,
                // ⚠ PAS DE RÉFÉRENCE ICI : le formulaire de la note désactive ce champ —
                // « générée automatiquement » — et ignore donc toute valeur soumise. Elle
                // est posée à la naissance de l'entité, comme la signature et la
                // validation ({@see \App\Service\Workspace\ValeursDeNaissance}).
                'description' => 'Situation reprise depuis un classeur de reprise.',
            ]),
            collections: [
                // ⚠ L'ARTICLE PORTE LES DEUX LIENS, sans quoi son montant vaut zéro.
                'articles' => [new MutationOperation(
                    op: MutationOperation::OP_CREATE,
                    entityShortName: 'Article',
                    fields: [
                        'tranche' => CleNaturelle::renvoiVers($repereTranche),
                        'revenuFacture' => CleNaturelle::renvoiVers($repereRevenu),
                        'quantite' => 1,
                    ],
                )],
                // Le règlement : c'est LUI qui porte le montant encaissé, et le calcul en
                // tire la proportion payée de la note.
                'paiements' => [new MutationOperation(
                    op: MutationOperation::OP_CREATE,
                    entityShortName: 'Paiement',
                    fields: $this->sansVide([
                        'montant' => $montant,
                        'paidAt' => $date,
                        'reference' => self::REFERENCE_OUVERTURE,
                        'description' => 'Situation reprise depuis un classeur de reprise.',
                    ]),
                )],
            ],
        );
    }

    /** Qui doit la commission de ce type de revenu — l'assureur précompte, ou le client règle. */
    private function redevableDuType(int $idType): ?int
    {
        return $this->em->find(TypeRevenu::class, $idType)?->getRedevable();
    }

    /**
     * L'ÉCRITURE D'OUVERTURE DE LA RÉTROCOMMISSION : ce qui avait déjà été reversé.
     *
     * ⚠ UN REVERSEMENT SANS BÉNÉFICIAIRE N'A PAS DE SENS. `ReversementRetroAgent` porte un
     * agent OU un partenaire, en XOR : sans l'un des deux, la ligne serait une somme
     * versée à personne. On refuse en le disant, plutôt que d'écrire un orphelin.
     *
     * @param array<int, MutationOperation> $operations
     * @param Anomalie[]                    $anomalies
     */
    private function ouvrirLaRetro(
        LigneLue $ligne,
        string $repereTranche,
        int|string|null $intermediaire,
        array &$operations,
        array &$anomalies,
    ): void {
        $montant = $this->nombre($ligne, 'ouvertureRetroReversee');
        if ($montant === null || $montant <= 0.0) {
            return;
        }

        if ($intermediaire === null) {
            $anomalies[] = $this->refus($ligne, 'ouvertureRetroReversee', sprintf(
                'Vous indiquez %s de rétrocommission déjà reversée, mais la colonne de '
                . 'l\'intermédiaire est vide : on ne sait pas à qui cette somme a été versée. '
                . 'Nommez l\'intermédiaire, ou effacez ce montant.',
                number_format($montant, 2, ',', ' '),
            ));

            return;
        }

        $operations[] = new MutationOperation(
            op: MutationOperation::OP_CREATE,
            entityShortName: 'ReversementRetroAgent',
            fields: $this->sansVide([
                'partenaire' => $intermediaire,
                'tranche' => CleNaturelle::renvoiVers($repereTranche),
                'montant' => $montant,
                'paidAt' => $this->date($ligne, 'ouvertureRetroLe', 'ReversementRetroAgent', 'paidAt')
                    ?? $this->date($ligne, 'policeDateEffet', 'ReversementRetroAgent', 'paidAt'),
                'reference' => self::REFERENCE_OUVERTURE,
                'description' => 'Situation reprise depuis un classeur de reprise.',
            ]),
        );
    }

    /**
     * L'ÉCHÉANCE. Une ligne = une tranche : jamais de convergence à ce niveau.
     *
     * ⚠ LA PART EST EN POINTS, comme partout dans l'application. Et l'on écrit les deux
     * colonnes telles qu'elles viennent : `getTrancheTauxFactor()` porte déjà la règle —
     * la part l'emporte, le montant ne servant que si elle est absente. Mesuré sur les
     * données réelles, 71 tranches sur 80 renseignent les deux ; en refuser une seule
     * aurait rejeté presque tout un portefeuille.
     *
     * @param array<string, ColonneEtat> $colonnes
     */
    private function tranche(LigneLue $ligne, array $colonnes, ?int $id, ?string $renvoiCotation): ?MutationOperation
    {
        $champs = $this->sansVide([
            'nom' => $ligne->texte('trancheNom'),
            'pourcentage' => $this->nombre($ligne, 'tranchePart'),
            'montantFlat' => $this->nombre($ligne, 'trancheMontantFlat'),
            'payableAt' => $this->date($ligne, 'tranchePayableAt', 'Tranche', 'payableAt'),
            'echeanceAt' => $this->date($ligne, 'trancheEcheanceAt', 'Tranche', 'echeanceAt'),
            'cotation' => $renvoiCotation,
        ]);

        // Ne retenir que ce que le fichier porte : une colonne non exportée n'a pas été
        // modifiée, et l'écrire à vide effacerait une valeur que personne n'a touchée.
        $champs = array_intersect_key($champs, $this->presentes($colonnes, [
            'trancheNom' => 'nom',
            'tranchePart' => 'pourcentage',
            'trancheMontantFlat' => 'montantFlat',
            'tranchePayableAt' => 'payableAt',
            'trancheEcheanceAt' => 'echeanceAt',
        ]) + ['cotation' => 'cotation']);

        if ($champs === []) {
            return null;
        }

        return new MutationOperation(
            op: $id === null ? MutationOperation::OP_CREATE : MutationOperation::OP_EDIT,
            entityShortName: 'Tranche',
            targetId: $id,
            fields: $champs,
        );
    }

    /**
     * ⚠ UNE SUPPRESSION NE PORTE QUE SUR L'ÉCHÉANCE, jamais sur son ascendance.
     *
     * Supprimer la police, la proposition et le client parce qu'on a effacé une échéance
     * serait une catastrophe silencieuse : les autres échéances de la même police
     * disparaîtraient avec elle. Le circuit d'écriture commun applique par ailleurs ses
     * propres garde-fous de liens protégés.
     *
     * @param Anomalie[] $anomalies
     *
     * @return array<int, MutationOperation>
     */
    private function suppression(LigneLue $ligne, ?int $id, array &$anomalies): array
    {
        if ($id === null) {
            $anomalies[] = $this->refus(
                $ligne,
                EtatDuPortefeuille::COLONNE_IDENTITE,
                'Cette ligne demande une suppression sans indiquer QUELLE échéance supprimer : '
                . 'la colonne « id » est vide. Conservez l\'identifiant tel qu\'il a été exporté.',
            );

            return [];
        }

        return [new MutationOperation(
            op: MutationOperation::OP_DELETE,
            entityShortName: 'Tranche',
            targetId: $id,
        )];
    }

    /**
     * RATTACHE À L'EXISTANT, OU PRÉPARE UNE CRÉATION — et refuse l'ambiguïté.
     *
     * Rend l'étiquette à écrire dans le champ de renvoi (« Client:12 » devient un
     * identifiant, une création devient « @cli-kin-avia »), ou `null` si le libellé est
     * vide ou refusé.
     *
     * @param array<string, mixed>          $champsDeCreation
     * @param Anomalie[]                    $anomalies
     * @param array<int, MutationOperation> $operations
     */
    private function rattacher(
        string $entite,
        LigneLue $ligne,
        string $codeColonne,
        string $prefixe,
        Entreprise $entreprise,
        array &$anomalies,
        array &$operations,
        array $champsDeCreation,
    ): int|string|null {
        $libelle = $ligne->texte($codeColonne);
        if ($libelle === '') {
            return null;
        }

        $renvoi = $this->resolveur->reconnaitre($entite, $libelle, $entreprise);

        if ($renvoi->estRefus()) {
            $anomalies[] = $this->refus($ligne, $codeColonne, $renvoi->motif);

            return null;
        }

        // Reconnu : on s'y rattache par son identifiant, et l'on ne crée rien.
        if ($renvoi->valeur !== null) {
            return (int) $renvoi->valeur;
        }

        $repere = CleNaturelle::pourLibelle($prefixe, $libelle);
        if ($repere === null) {
            return null;
        }

        if ($this->neuf($repere)) {
            $operations[] = new MutationOperation(
                op: MutationOperation::OP_CREATE,
                entityShortName: $entite,
                fields: $this->sansVide($champsDeCreation),
                ref: $repere,
            );
        }

        return CleNaturelle::renvoiVers($repere);
    }

    /**
     * UN ÉLÉMENT DE CATALOGUE — type de chargement, type de revenu.
     *
     * ⚠ ON NE CRÉE JAMAIS UN TYPE À LA VOLÉE. Un type de revenu porte un taux, un
     * redevable, un chargement d'assiette : le fabriquer depuis un simple nom donnerait
     * une configuration muette dont la commission vaudrait zéro par construction. Un type
     * inconnu est donc un refus qui dit quoi créer, et où.
     *
     * ⚠ MAIS UN NOM PORTÉ PAR PLUSIEURS TYPES N'EST PAS UN REFUS, ICI. Mesuré sur le
     * cabinet réel : son catalogue porte « Prime nette » SIX fois et « Commission
     * Ordinaire » six fois — séquelles d'une initialisation rejouée, et les doublons y
     * sont rigoureusement identiques. Refuser aurait bloqué toutes les lignes du
     * portefeuille, et pour un choix sans conséquence. On retient donc le premier, et on
     * le DIT en avertissement : l'utilisateur apprend qu'il a un catalogue à nettoyer,
     * sans que sa reprise en dépende.
     *
     * La différence avec un client homonyme est de nature : deux « SARL Martin » sont deux
     * affaires, deux « Prime nette » sont un même poste d'assiette écrit deux fois.
     *
     * @param Anomalie[] $anomalies
     */
    private function reconnu(string $entite, string $nom, Entreprise $entreprise, LigneLue $ligne, string $codeColonne, array &$anomalies): ?int
    {
        $renvoi = $this->resolveur->reconnaitreLePremier($entite, $nom, $entreprise);

        if ($renvoi->valeur === null) {
            $anomalies[] = $this->refus($ligne, $codeColonne, sprintf(
                'Votre cabinet n\'a rien qui s\'appelle « %s ». Créez-le d\'abord dans sa rubrique, '
                . 'puis redéposez ce fichier : un simple nom ne suffit pas ici, il faut aussi son '
                . 'taux et qui le doit — des informations que ce classeur ne transporte pas.',
                $nom,
            ));

            return null;
        }

        if ($this->resolveur->estAmbigu($entite, $nom, $entreprise)) {
            $anomalies[] = Anomalie::avertissement(
                Anomalie::VALEUR_INVALIDE,
                sprintf(
                    'Plusieurs éléments de votre configuration s\'appellent « %s » : le premier a été '
                    . 'retenu. Ils sont probablement en double — pensez à nettoyer la rubrique '
                    . 'correspondante, sans quoi le choix restera arbitraire.',
                    $nom,
                ),
                $ligne->feuille,
                $ligne->numero,
                $ligne->colonne($codeColonne),
            );
        }

        return (int) $renvoi->valeur;
    }

    /**
     * LES CHARGEMENTS D'UNE LIGNE — une colonne par FONCTION, et le prorata REMONTÉ.
     *
     * ⚠ LA COLONNE PORTE LA PART DE L'ÉCHÉANCE, LA COTATION PORTE LE TOUT. C'est le prix
     * d'une colonne totalisable : sans le prorata, une police à quatre échéances
     * répéterait quatre fois les mêmes montants et la ligne de totaux les compterait
     * quatre fois. On divise donc par la part pour retrouver ce que la cotation porte —
     * et les quatre lignes redonnent le même montant, que la convergence n'écrit qu'une
     * fois.
     *
     * ⚠ UN ZÉRO N'EST PAS UNE ABSENCE. Un chargement à 0 existe : c'est un poste ouvert et
     * non facturé. Mais l'export écritZÉRO dans les quatre colonnes, même celles qu'aucun
     * chargement n'alimente : les retenir toutes créerait quatre lignes là où le cabinet
     * n'en a saisi qu'une. On ne garde donc que les montants NON NULS — un poste à zéro ne
     * change ni la prime ni la commission.
     *
     * @return array<int, float> fonction => montant de la cotation
     */
    private function chargementsDeLaLigne(LigneLue $ligne): array
    {
        $facteur = $this->partDeLaLigne($ligne);
        $chargements = [];

        foreach (array_keys(CatalogueDesColonnes::FONCTIONS) as $fonction) {
            $brut = $ligne->valeur(CatalogueDesColonnes::codeDeFonction($fonction));
            if ($brut === null || trim((string) (is_scalar($brut) ? $brut : '')) === '') {
                continue;
            }

            $montant = $this->nombreBrut($brut) / $facteur;
            if (abs($montant) < 0.005) {
                continue;
            }

            $chargements[$fonction] = $montant;
        }

        return $chargements;
    }

    /**
     * UN TYPE DE CHARGEMENT DU CABINET pour cette fonction — le premier venu.
     *
     * ⚠ LE PREMIER SUFFIT, ET IL LE FAUT. Un cabinet porte plusieurs types pour une même
     * fonction — « Frais accessoires » et « Sneca » sont tous deux des frais —, et la
     * colonne les a additionnés : elle ne dit plus lequel. Les redistribuer serait
     * inventer une ventilation que le fichier ne porte pas. Le montant est donc rattaché à
     * un type de la bonne fonction, ce qui suffit au calcul de la prime — c'est la
     * FONCTION qui compte, non le nom.
     */
    private function typePourFonction(Entreprise $entreprise, int $fonction): ?int
    {
        $id = $this->em->createQueryBuilder()
            ->select('c.id')
            ->from(Chargement::class, 'c')
            ->andWhere('c.entreprise = :entreprise')
            ->andWhere('c.fonction = :fonction')
            ->setParameter('entreprise', $entreprise)
            ->setParameter('fonction', $fonction)
            ->orderBy('c.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $id === null ? null : (int) $id['id'];
    }

    /**
     * LA PART DE L'ÉCHÉANCE, en FRACTION — jamais zéro.
     *
     * Elle sert à remonter le prorata des chargements. Rendre zéro ferait une division
     * impossible ; rendre une part inventée ferait une prime fausse. En l'absence de
     * part, la ligne vaut pour la totalité.
     */
    private function partDeLaLigne(LigneLue $ligne): float
    {
        $part = $this->nombre($ligne, 'tranchePart');

        // ⚠ EN POINTS, comme partout : `Tranche::getFraction()` est la source unique du
        // /100, et l'export écrit bien 25 pour un quart.
        return $part === null || $part <= 0.0 ? 1.0 : $part / 100.0;
    }

    /** Un nombre de cellule, dont la typographie humaine est admise. */
    private function nombreBrut(mixed $brut): float
    {
        return is_numeric($brut)
            ? (float) $brut
            : \App\Services\Bordereau\BordereauLigneNormaliseur::nettoyerNombre((string) $brut);
    }

    /**
     * Les termes d'une cellule multi-valeurs, les refus de lecture étant remontés.
     *
     * @param Anomalie[] $anomalies
     *
     * @return array<string, array{valeur: float|null, estTaux: bool}>
     */
    private function termes(LigneLue $ligne, string $codeColonne, array &$anomalies): array
    {
        $refus = [];
        $termes = ValeursMultiples::lire($ligne->texte($codeColonne), $refus);

        foreach ($refus as $motif) {
            $anomalies[] = $this->refus($ligne, $codeColonne, $motif);
        }

        return $termes;
    }

    /**
     * LE NOM DE L'AFFAIRE — opportunité et proposition en portent un, obligatoire.
     *
     * Il n'a pas de colonne : le fichier décrit des échéances, pas des intitulés de
     * dossier. On le compose donc de ce que la ligne dit — l'assuré et son risque — plutôt
     * que de refuser une reprise pour un libellé que personne n'aurait à saisir.
     */
    private function nomDeLAffaire(LigneLue $ligne): string
    {
        $morceaux = array_filter([$ligne->texte('assure'), $ligne->texte('risque')]);

        return $morceaux === []
            ? (string) $ligne->texte(self::COLONNE_CLE)
            : implode(' — ', $morceaux);
    }

    /**
     * L'EXERCICE DE L'AFFAIRE : l'année de sa date d'effet.
     *
     * À défaut de date — un projet non encore lié —, l'année courante, qui est ce que
     * l'écran propose lui aussi. On ne laisse pas le champ vide : il est obligatoire, et
     * une question posée à l'utilisateur pour une valeur inscrite au calendrier est une
     * question de trop.
     */
    private function exercice(LigneLue $ligne): int
    {
        $date = $this->date($ligne, 'policeDateEffet', 'Avenant', 'startingAt');

        if ($date !== null) {
            $lue = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $date)
                ?: \DateTimeImmutable::createFromFormat('Y-m-d', substr($date, 0, 10));

            if ($lue !== false) {
                return (int) $lue->format('Y');
            }
        }

        return (int) date('Y');
    }

    /** L'identifiant de tranche porté par la ligne, s'il est utilisable. */
    private function identifiant(LigneLue $ligne): ?int
    {
        $brut = $ligne->texte(EtatDuPortefeuille::COLONNE_IDENTITE);

        return ctype_digit($brut) && (int) $brut > 0 ? (int) $brut : null;
    }

    /**
     * UNE DATE, AU FORMAT QUE LE FORMULAIRE DE SA CIBLE ATTEND.
     *
     * ⚠ LE FORMAT NE SE DEVINE PAS, IL SE DÉRIVE DU TYPE DOCTRINE. Toutes les propriétés
     * temporelles visées ici sont des `datetime_immutable` : leur widget attend
     * « aaaa-mm-jjThh:mm », et non « aaaa-mm-jj ». Avoir inventé le second a fait rejeter
     * les soixante-dix-neuf lignes d'un export réimporté, sur « Veuillez saisir une date
     * et une heure valides » — une erreur qui accuse la saisie alors que la faute était
     * dans la conversion.
     *
     * ⚠ ET LA NORMALISATION EST EMPRUNTÉE, JAMAIS RÉÉCRITE. `NormaliseurDeDates` est la
     * source unique du projet : il connaît les formats français, le pivot ISO et les
     * pièges de l'un et de l'autre. En redire une seconde version ici, ce serait
     * s'engager à la maintenir deux fois — et divergerait au premier cas limite.
     *
     * Une cellule Excel porte une date comme un NOMBRE : on la ramène d'abord à un texte
     * daté, que le normaliseur sait lire.
     */
    private function date(LigneLue $ligne, string $codeColonne, string $entite, string $propriete): ?string
    {
        $brut = $ligne->valeur($codeColonne);
        if ($brut === null || $brut === '') {
            return null;
        }

        $texte = (string) (is_scalar($brut) ? $brut : '');

        if (is_numeric($brut)) {
            try {
                $texte = DateExcel::excelToDateTimeObject((float) $brut)->format('d/m/Y H:i');
            } catch (\Throwable) {
                // Un nombre qui n'est pas une date : le normaliseur le refusera, et la
                // valeur restera vide plutôt que d'inventer un jour.
            }
        }

        $normalise = $this->dates->normaliser($texte, $this->typeTemporel($entite, $propriete));

        // Le normaliseur rend la valeur d'ORIGINE quand il ne reconnaît rien : c'est ce
        // qui nous dit de ne rien écrire, plutôt que de poser un texte dans un champ de
        // date et de laisser le formulaire s'en plaindre à notre place.
        return is_string($normalise) && $normalise !== $texte ? $normalise : ($this->estDeja($normalise) ? (string) $normalise : null);
    }

    /** Le type Doctrine d'une propriété temporelle — « date » ou « datetime ». */
    private function typeTemporel(string $entite, string $propriete): string
    {
        $fqcn = 'App' . chr(92) . 'Entity' . chr(92) . $entite;

        if (!class_exists($fqcn)) {
            return 'datetime';
        }

        $type = (string) $this->em->getClassMetadata($fqcn)->getTypeOfField($propriete);

        return str_starts_with($type, 'date') && !str_contains($type, 'time') ? 'date' : 'datetime';
    }

    /** La valeur est-elle DÉJÀ au format attendu — cas d'un fichier saisi à la main ? */
    private function estDeja(mixed $valeur): bool
    {
        if (!is_string($valeur)) {
            return false;
        }

        foreach (['Y-m-d\TH:i', 'Y-m-d\TH:i:s', 'Y-m-d'] as $format) {
            if (\DateTimeImmutable::createFromFormat($format, $valeur) !== false) {
                return true;
            }
        }

        return false;
    }

    private function nombre(LigneLue $ligne, string $codeColonne): ?float
    {
        $brut = $ligne->texte($codeColonne);

        return $brut === '' ? null : (float) str_replace(',', '.', $brut);
    }

    /**
     * ⚠ UN REPÈRE NE SE PRODUIT QU'UNE FOIS — c'est toute la convergence.
     *
     * Rend vrai la PREMIÈRE fois qu'on voit ce repère, et faux ensuite : la ligne suivante
     * qui parle de la même police s'y renvoie au lieu de la recréer.
     */
    private function neuf(string $repere): bool
    {
        if (isset($this->registre[$repere])) {
            return false;
        }

        $this->registre[$repere] = true;

        return true;
    }

    /**
     * Les champs dont la colonne EXISTE dans le fichier.
     *
     * @param array<string, ColonneEtat> $colonnes
     * @param array<string, string>      $correspondance code de colonne => nom de champ
     *
     * @return array<string, string>
     */
    private function presentes(array $colonnes, array $correspondance): array
    {
        $retenus = [];
        foreach ($correspondance as $code => $champ) {
            if (isset($colonnes[$code])) {
                $retenus[$champ] = $champ;
            }
        }

        return $retenus;
    }

    /**
     * ⚠ UN CHAMP VIDE N'EST PAS UN CHAMP. L'inclure demanderait au circuit d'écriture
     * d'effacer une valeur que personne n'a touchée — une police perdrait sa date d'effet
     * parce que la colonne n'était pas dans l'export.
     *
     * @param array<string, mixed> $champs
     *
     * @return array<string, mixed>
     */
    private function sansVide(array $champs): array
    {
        return array_filter(
            $champs,
            static fn ($valeur) => $valeur !== null && $valeur !== '',
        );
    }

    /**
     * LE TERME DE REVENU SE CONTREDIT-IL AVEC LA COMMISSION ENCAISSÉE DE LA MÊME LIGNE ?
     *
     * ⚠ UN NOMBRE NU EST UN MONTANT, ET C'EST LE PIÈGE. `ValeursMultiples` distingue le
     * taux du forfait au signe pourcent, et rien ne le rappelle à qui remplit le gabarit à
     * la main. L'export, lui, écrit toujours le pourcent quand il en va d'un taux : le
     * fichier ne se corrige donc jamais tout seul, et l'erreur se recopie d'un
     * aller-retour à l'autre.
     *
     * ⚠ ON NE DEVINE PAS : ON CONFRONTE. Basculer d'office un nombre nu en taux ferait
     * exactement la faute inverse le jour d'un vrai forfait. Ici, deux colonnes de la même
     * ligne se contredisent, et c'est CELA qu'on reproche : un forfait ne peut pas produire
     * beaucoup plus que lui-même — les taxes n'y ajoutent qu'une fraction —, si bien qu'une
     * commission encaissée qui dépasse le double du forfait ne peut pas en venir.
     *
     * ⚠ LA MARGE EST LARGE À DESSEIN. Mieux vaut laisser passer un cas tordu que refuser un
     * fichier juste : le reproche doit rester rare, sans quoi on apprend à l'ignorer.
     *
     * @param array{valeur: float|null, estTaux: bool} $terme
     */
    private function ambiguiteDuRevenu(LigneLue $ligne, string $nom, array $terme): ?Anomalie
    {
        if ($terme['estTaux'] || $terme['valeur'] === null || $terme['valeur'] <= 0.0) {
            return null;
        }

        $encaissee = $this->nombre($ligne, 'ouvertureCommissionEncaissee');
        if ($encaissee === null || $encaissee <= $terme['valeur'] * self::MARGE_DU_FORFAIT) {
            return null;
        }

        $valeur = $this->nombreLisible($terme['valeur']);

        return $this->refus($ligne, 'commissionRevenus', sprintf(
            'Vous avez écrit « %s: %s », ce qui se lit comme un MONTANT FIXE de %s. Or la '
            . 'même ligne annonce %s de commission déjà encaissée — un montant fixe de %s ne '
            . 'peut pas produire cela. S\'il s\'agit d\'un TAUX, ajoutez le signe pourcent : '
            . '« %s: %s%% ». Sinon, c\'est la commission encaissée qu\'il faut corriger.',
            $nom,
            $valeur,
            $valeur,
            $this->nombreLisible($encaissee),
            $valeur,
            $nom,
            $valeur,
        ));
    }

    /** Un nombre tel qu'on l'écrit dans une phrase : sans décimales inutiles. */
    private function nombreLisible(float $valeur): string
    {
        $texte = number_format($valeur, 2, ',', ' ');

        return str_ends_with($texte, ',00') ? substr($texte, 0, -3) : $texte;
    }

    private function refus(LigneLue $ligne, string $codeColonne, string $motif): Anomalie
    {
        return Anomalie::erreur(
            Anomalie::VALEUR_INVALIDE,
            $motif,
            $ligne->feuille,
            $ligne->numero,
            $ligne->colonne($codeColonne),
        );
    }
}
