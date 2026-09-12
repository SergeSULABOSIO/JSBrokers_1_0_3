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
use App\Entity\Client;
use App\Entity\ConditionPartage;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Note;
use App\Entity\Partenaire;
use App\Entity\Portefeuille;
use App\Entity\Risque;
use App\Entity\TypeRevenu;
use App\Form\ConditionPartageType;
use App\Service\Partage\ConditionDOffice;
use App\Services\Canvas\Indicator\RevenuPourCourtierIndicatorStrategy;
use App\Services\ServiceTaxes;
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

    /**
     * Au-delà de ce nombre de POINTS, une valeur n'est plus un taux de commission.
     *
     * ⚠ CENT, PARCE QUE C'EST LA PRIME ELLE-MÊME. Un courtier commissionne entre cinq et
     * trente pour cent ; personne ne prend cent fois la prime. Ce plafond n'est pas là
     * pour juger les taux du cabinet, mais pour attraper les classeurs écrits sous
     * l'ANCIENNE convention, où « Commission = 5000 » désignait un forfait de cinq mille —
     * et vaudrait aujourd'hui cinq mille pour cent.
     */
    private const TAUX_PLAFOND = 100.0;

    /**
     * De combien la commission encaissée peut dépasser ce que le taux produit.
     *
     * ⚠ UN POUR CENT, ET PAS DAVANTAGE. Assez pour absorber une décimale perdue entre le
     * logiciel d'origine et le classeur ; trop peu pour laisser passer un taux faux, qui
     * se trompe toujours d'un ordre de grandeur et non d'un centime.
     */
    private const MARGE_D_ARRONDI = 0.01;

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

    /**
     * @var array<string, true> ce qui a déjà été dit dans ce palier
     *
     * ⚠ UN REPROCHE RÉPÉTÉ CENT FOIS N'EST PLUS UN REPROCHE. Trois constats de ce
     * service valent pour le FICHIER et non pour la ligne — « vos clients naissent sans
     * portefeuille », « "Commission" a été lu comme "Commission Ordinaire" », « aucun
     * revenu déclaré, la commission ordinaire a été posée ». Émis ligne à ligne, ils
     * remplissaient le rapport à eux seuls et poussaient les VRAIES erreurs au-delà de
     * la troncature ({@see \App\Echange\Service\RapportDeControle::anomaliesTronquees()}).
     *
     * Un par palier, donc : assez rare pour rester lisible, assez fréquent pour qu'on ne
     * puisse pas terminer une reprise sans l'avoir vu.
     */
    private array $ditUneFois = [];

    public function __construct(
        private readonly ResolveurDeRenvois $resolveur,
        private readonly NormaliseurDeDates $dates,
        private readonly EntityManagerInterface $em,
        private readonly DefautsContextuels $defauts,
        // Le registre ci-dessus ne connaît que le fichier en cours. Celui-ci connaît le
        // portefeuille : sans lui, deux dépôts successifs empilent deux fois la même police.
        private readonly ChaineExistante $chaine,
        // Une commission ENCAISSÉE est TTC ; le taux du classeur en donne le HT. Sans le
        // barème du cabinet, les deux ne sont pas comparables — et c'est cette comparaison
        // qui dit si le taux fourni tient debout ({@see commissionInsuffisante()}).
        private readonly ServiceTaxes $taxes,
    ) {
        $this->parts = PartsDesIntermediaires::aucune();
    }

    /**
     * LA PART DE CHAQUE INTERMÉDIAIRE QUE LE FICHIER CRÉE, lue sur TOUT le fichier.
     *
     * ⚠ PAS SUR LE PALIER : voir {@see PartsDesIntermediaires}. Un apporteur qui paraît sur
     * cent lignes naît une seule fois, et sa part ne doit pas dépendre du découpage.
     */
    private PartsDesIntermediaires $parts;

    /**
     * @var array{id: int, nom: string, portefeuille: ?array{id: int, nom: string}}|null|false
     *      le propriétaire du cabinet et son plus ancien portefeuille ; `false` = pas encore lu
     */
    private array|null|false $proprietaire = false;

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
    public function reinitialiser(?Invite $pourLeCompteDe = null, ?PartsDesIntermediaires $parts = null): void
    {
        $this->pourLeCompteDe = $pourLeCompteDe;
        $this->parts = $parts ?? PartsDesIntermediaires::aucune();
        // Relu à chaque palier : le précédent a peut-être créé le portefeuille du propriétaire.
        $this->proprietaire = false;
        $this->registre = [];
        $this->revenuParProposition = [];
        $this->redevableParProposition = [];
        $this->ditUneFois = [];
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

        // ⚠ LE RISQUE FAIT PARTIE DE L'IDENTITÉ DE L'AFFAIRE. Une même référence peut couvrir
        // plusieurs risques — incendie et pertes d'exploitation sous un seul contrat —, et
        // chacun est une affaire à part : sa prime, sa commission, sa part d'intermédiaire.
        // Converger sur la seule référence faisait perdre le second risque en entier
        // ({@see CleNaturelle::pourChaine()}).
        $libelleRisque = $ligne->texte('risque');

        if ($this->chaine->estAmbigue($reference, $numeroAvenant, $libelleRisque, $entreprise)) {
            $anomalies[] = $libelleRisque === ''
                ? $this->refus($ligne, 'risque', sprintf(
                    'Vous avez déjà plusieurs polices « %s », sur des risques différents. Remplissez '
                    . 'la colonne du risque pour dire à laquelle cette échéance appartient.',
                    $reference,
                ))
                : $this->refus($ligne, self::COLONNE_CLE, sprintf(
                    'Vous avez déjà plusieurs polices portant la référence « %s » sur le risque '
                    . '« %s ». On ne peut pas deviner à laquelle rattacher cette échéance. Ouvrez la '
                    . 'rubrique des polices, gardez-en une seule, puis redéposez ce fichier.',
                    $reference,
                    $libelleRisque,
                ));

            return [];
        }

        $existante = $this->chaine->pour($reference, $numeroAvenant, $libelleRisque, $entreprise);
        $idPiste = $existante['piste'] ?? null;
        $idCotation = $existante['cotation'] ?? null;
        $idAvenant = $existante['avenant'] ?? null;

        $operations = [];

        // ── Les niveaux nommés par un libellé ───────────────────────────────────────
        // ⚠ LE PORTEFEUILLE D'ABORD, PARCE QUE LE CLIENT S'Y RANGE. Il se pose sur le CLIENT,
        // à sa création : l'opération qui le crée doit donc précéder celle du client, un
        // renvoi ne partant jamais en avant. Rattaché après, comme il l'a été longtemps, il
        // naissait bien — mais aucun client n'y était jamais rangé.
        $portefeuille = $this->rattacher('Portefeuille', $ligne, 'portefeuille', CleNaturelle::PORTEFEUILLE, $entreprise, $anomalies, $operations, [
            'nom' => $ligne->texte('portefeuille'),
            // ⚠ SANS GESTIONNAIRE, LE PORTEFEUILLE NE PEUT PAS NAÎTRE — et le classeur ne
            // porte pas cette colonne. Celui qui dépose le fichier en répond ; cela se
            // change d'un clic à l'écran, alors qu'un refus incompréhensible bloquait tout.
            'gestionnaire' => $this->pourLeCompteDe?->getId(),
        ]);

        // ⚠ UN CLIENT SANS PORTEFEUILLE REJOINT CELUI DU PROPRIÉTAIRE DU CABINET. Laissé
        // sans portefeuille, il n'apparaissait dans aucune vue « Mon portefeuille » et
        // attendait qu'on le range à la main, client par client.
        //
        // Les champs sont une FONCTION, évaluée seulement si le client naît : un client déjà
        // en base garde son rangement, et le portefeuille par défaut n'est créé que s'il sert.
        $client = $this->rattacher(
            'Client',
            $ligne,
            'assure',
            CleNaturelle::CLIENT,
            $entreprise,
            $anomalies,
            $operations,
            function () use ($ligne, $portefeuille, $entreprise, &$operations, &$anomalies): array {
                return [
                    'nom' => $ligne->texte('assure'),
                    'portefeuille' => $portefeuille
                        ?? ($ligne->texte('portefeuille') === ''
                            ? $this->portefeuilleParDefaut($ligne, $entreprise, $operations, $anomalies)
                            : null),
                ];
            },
        );
        if ($client === null) {
            return [];
        }

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
            // ⚠ LA PART EST OBLIGATOIRE, ET LE FICHIER LA DONNE. Sans elle, chaque ligne
            // nommant un apporteur inconnu était refusée sur « Part du partenaire, que le
            // classeur de reprise ne transporte pas » — alors qu'il la transporte, colonne
            // « Intermédiaire · Part ». On retient la plus fréquente du fichier ; les écarts
            // deviennent des conditions propres à l'affaire ({@see conditionPropreALAffaire()}).
            'part' => $this->parts->partDeCreation($ligne->texte('intermediaire')),
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
        $piste = (string) CleNaturelle::pourChaine(CleNaturelle::PISTE, $reference, $libelleRisque);
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
            $collectionsDeLAffaire = [];
            if ($intermediaire !== null) {
                $champs['partenaire'] = $intermediaire;

                $condition = $this->conditionPropreALAffaire($ligne, $intermediaire, $risque, $anomalies);
                if ($condition !== null) {
                    $collectionsDeLAffaire['conditionsPartageExceptionnelles'] = [$condition];
                }
            }
            $operations[] = new MutationOperation(
                op: MutationOperation::OP_CREATE,
                entityShortName: 'Piste',
                fields: $this->sansVide($champs),
                collections: $collectionsDeLAffaire,
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
        $cotation = (string) CleNaturelle::pourChaine(CleNaturelle::COTATION, $reference, $libelleRisque);
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
            //
            // ⚠ ET UNE PROPOSITION SANS REVENU N'EN EST PAS UNE. La colonne laissée vide
            // produisait une proposition muette : chiffre d'affaires, part partenaire et
            // taxes sur commission à zéro — un portefeuille repris qui ne rapporte rien.
            // Or il n'existe pas d'affaire d'assurance sans commission de courtage : c'est
            // sa raison d'être. On pose donc la commission ordinaire d'office, exactement
            // comme l'assistant le fait pour toute proposition qu'il crée
            // ({@see \App\Ai\Proposition\RevenuCourtierPrescrit}) — sans taux, puisque
            // celui du risque se résout à la lecture.
            $termesRevenus = $this->termes($ligne, 'commissionRevenus', $anomalies);

            if ($termesRevenus === []) {
                $termesRevenus = [CommissionOrdinaire::NOM => ['valeur' => null, 'estTaux' => false]];
                $this->direUneFois(
                    'revenu-d-office',
                    $ligne,
                    'commissionRevenus',
                    sprintf(
                        'Des lignes de ce fichier ne disent pas ce que l\'affaire rapporte : elles '
                        . 'reçoivent « %s », dont le taux est celui du risque. Renseignez la colonne '
                        . '« Commission · Revenus » si une autre rémunération s\'applique.',
                        CommissionOrdinaire::NOM,
                    ),
                    $anomalies,
                );
            }

            // Ce que les taux de la ligne produisent vraiment, taxe de l'assureur
            // comprise : c'est cela qu'on confrontera à la commission déjà encaissée.
            $commissionTtc = 0.0;
            $toutEstTarife = true;

            foreach ($termesRevenus as $nom => $terme) {
                $revenu = $this->typeDeRevenu($nom, $entreprise, $ligne, $anomalies, $operations);
                if ($revenu === null) {
                    continue;
                }

                // ⚠ UN TAUX ABERRANT TRAHIT UN FORFAIT ÉCRIT À L'ANCIENNE, quand un nombre
                // nu valait un montant. « Commission = 5000 » vaudrait aujourd'hui cinq
                // mille pour cent : on le refuse en nommant « (forfait) ».
                $reproche = $this->ambiguiteDuRevenu($ligne, $nom, $terme);
                if ($reproche !== null) {
                    $anomalies[] = $reproche;
                    continue;
                }

                $rendement = $this->rendementTtc($revenu['id'], $terme, $ligne, $entreprise, $client, $risque);
                if ($rendement === null) {
                    $toutEstTarife = false;
                } else {
                    $commissionTtc += $rendement;
                }

                $champs = ['nom' => $revenu['nom'], 'typeRevenu' => $revenu['id']];

                // ⚠ UNE VALEUR HÉRITÉE NE SE RECOPIE PAS. L'export écrit désormais le taux
                // EFFECTIF de chaque revenu — sans quoi le courtier exportait son
                // portefeuille et n'y lisait aucun taux —, mais il MARQUE celui qui vient
                // du risque ou du type. Le réécrire en dérogation le figerait : la
                // commission cesserait de suivre le risque le jour où son taux change, et
                // un simple aller-retour aurait scellé tout un portefeuille.
                if ($terme['valeur'] !== null && !ValeursMultiples::estInformatif($terme)) {
                    $champs[$terme['estTaux'] ? 'tauxExceptionel' : 'montantFlatExceptionel'] = $terme['valeur'];
                } elseif ($terme['valeur'] === null) {
                    // Une valeur MARQUÉE, elle, prouve qu'un tarif est prescrit : il n'y a
                    // rien à reprocher. Seule une cellule muette laisse la question ouverte.
                    $this->signalerUnTauxNonPrescrit($revenu['id'], $risque, $ligne, $anomalies);
                }

                // ⚠ UN REPÈRE SUR L'ENFANT, ET IL SERT. L'écriture d'ouverture de la
                // commission a besoin de désigner CE revenu : un article sans revenu à
                // facturer vaut zéro par construction. Le circuit d'écriture déclare le
                // repère des enfants de collection avec leur identifiant, au dry-run comme
                // à l'exécution — c'est ce qui rend ce renvoi possible.
                $this->revenuParProposition[$cotation] ??= (string) CleNaturelle::pourLibelle(
                    'rev',
                    $cotation . ' ' . $revenu['nom'],
                );
                // Le payeur suit le repère : c'est lui qui dira à qui adresser la note de
                // reprise d'une commission déjà encaissée.
                $this->redevableParProposition[$cotation] ??= $revenu['redevable'];
                $repereRevenu = $this->revenuParProposition[$cotation];

                $collections['revenus'][] = new MutationOperation(
                    op: MutationOperation::OP_CREATE,
                    entityShortName: 'RevenuPourCourtier',
                    fields: $this->sansVide($champs),
                    ref: $repereRevenu,
                );
            }

            // ⚠ LA LIGNE SE CONTREDIT-ELLE ELLE-MÊME ? C'est ici, et nulle part ailleurs,
            // qu'on peut le savoir : la même ligne porte le TAUX et l'ENCAISSEMENT.
            $reproche = $this->commissionInsuffisante($ligne, $commissionTtc, $toutEstTarife);
            if ($reproche !== null) {
                $anomalies[] = $reproche;
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
                    // COLLECTION de la proposition — or la convergence imposé ici de le
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
        $avenant = (string) CleNaturelle::pourAvenant($reference, $numeroAvenant, $libelleRisque);
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
            // ⚠ LE REGISTRE LOCAL NE CONNAÎT QUE CETTE PASSE. Une échéance neuve sous une
            // police déjà reprise n'y trouve rien : son revenu est en base depuis le dépôt
            // précédent, et c'est là qu'il faut aller le chercher — sinon l'encaissement
            // était perdu à chaque dépôt complémentaire.
            $dejaEnBase = !isset($this->revenuParProposition[$cotation]) && $idCotation !== null
                ? $this->revenuExistant($idCotation)
                : null;

            $this->ouvrirLaCommission(
                $ligne,
                $repereTranche,
                $this->revenuParProposition[$cotation] ?? $dejaEnBase['id'] ?? null,
                $this->redevableParProposition[$cotation] ?? $dejaEnBase['redevable'] ?? null,
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
        int|string|null $revenuAFacturer,
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
        if ($revenuAFacturer === null) {
            $anomalies[] = Anomalie::avertissement(
                Anomalie::VALEUR_INVALIDE,
                sprintf(
                    'La commission de %s que vous avez déjà encaissée n\'a pas pu être '
                    . 'enregistrée : la proposition de cette police ne porte aucun revenu à '
                    . 'facturer. Ouvrez-la et ajoutez-lui un revenu — « %s », par exemple — '
                    . 'puis redéposez ce fichier.',
                    number_format($montant, 2, ',', ' '),
                    CommissionOrdinaire::NOM,
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
                        // Un entier est un revenu DÉJÀ en base (proposition reprise à un
                        // dépôt précédent) ; une chaîne est un repère de cette passe.
                        'revenuFacture' => is_int($revenuAFacturer)
                            ? $revenuAFacturer
                            : CleNaturelle::renvoiVers($revenuAFacturer),
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

    /**
     * LE PORTEFEUILLE OÙ RANGER UN CLIENT QUE LE FICHIER NE RANGE PAS : celui du propriétaire.
     *
     * ── TROIS SITUATIONS ─────────────────────────────────────────────────────────────
     *   1. le propriétaire gère un ou plusieurs portefeuilles → le PLUS ANCIEN, le seul
     *      choix qui ne dépende ni du nom ni de l'humeur du jour ;
     *   2. il n'en gère aucun → on lui en crée un, « Portefeuille de … », une fois ;
     *   3. le cabinet n'a pas de propriétaire désigné → le client reste sans portefeuille,
     *      et on le dit, comme avant.
     *
     * ⚠ UN CONSTAT PAR PALIER, JAMAIS PAR LIGNE : le rangement vaut pour le fichier entier.
     * Il nomme le portefeuille retenu, pour qu'on sache où chercher ses clients.
     *
     * @param array<int, MutationOperation> $operations
     * @param Anomalie[]                    $anomalies
     */
    private function portefeuilleParDefaut(LigneLue $ligne, Entreprise $entreprise, array &$operations, array &$anomalies): int|string|null
    {
        $proprietaire = $this->proprietaireDuCabinet($entreprise);

        if ($proprietaire === null) {
            $this->direUneFois(
                'client-sans-portefeuille',
                $ligne,
                'portefeuille',
                'Des clients de ce fichier ne portent aucun portefeuille : ils sont repris '
                . 'quand même, mais sans portefeuille. Ouvrez la rubrique « Clients » pour les '
                . 'y ranger — la colonne « Portefeuille » vous évitera ce geste au prochain dépôt.',
                $anomalies,
            );

            return null;
        }

        if ($proprietaire['portefeuille'] !== null) {
            $nom = $proprietaire['portefeuille']['nom'];
            $valeur = $proprietaire['portefeuille']['id'];
        } else {
            $nom = $proprietaire['nom'] === ''
                ? 'Portefeuille du propriétaire'
                : sprintf('Portefeuille de %s', $proprietaire['nom']);
            $repere = (string) CleNaturelle::pourLibelle(CleNaturelle::PORTEFEUILLE, $nom);

            if ($this->neuf($repere)) {
                $operations[] = new MutationOperation(
                    op: MutationOperation::OP_CREATE,
                    entityShortName: 'Portefeuille',
                    fields: ['nom' => $nom, 'gestionnaire' => $proprietaire['id']],
                    ref: $repere,
                );
            }

            $valeur = CleNaturelle::renvoiVers($repere);
        }

        $this->direUneFois(
            'portefeuille-par-defaut',
            $ligne,
            'portefeuille',
            sprintf(
                'Des clients de ce fichier ne nomment aucun portefeuille : ils ont été rangés dans '
                . '« %s », celui du propriétaire du cabinet. Renseignez la colonne « Portefeuille » '
                . 'pour en choisir un autre.',
                $nom,
            ),
            $anomalies,
        );

        return $valeur;
    }

    /**
     * Le propriétaire du cabinet et son plus ancien portefeuille — lus une fois par palier.
     *
     * ⚠ PAR REQUÊTE, PAS PAR `Invite::getPortefeuilles()`. La collection inverse, déjà
     * chargée, ne verrait pas le portefeuille qu'un palier précédent vient de créer : le
     * suivant en recréerait un second.
     *
     * @return array{id: int, nom: string, portefeuille: ?array{id: int, nom: string}}|null
     */
    private function proprietaireDuCabinet(Entreprise $entreprise): ?array
    {
        if ($this->proprietaire !== false) {
            return $this->proprietaire;
        }

        $invite = $this->em->getRepository(Invite::class)->findOneBy(
            ['entreprise' => $entreprise, 'proprietaire' => true],
            ['id' => 'ASC'],
        );
        if ($invite === null) {
            return $this->proprietaire = null;
        }

        $portefeuille = $this->em->getRepository(Portefeuille::class)->findOneBy(
            ['entreprise' => $entreprise, 'gestionnaire' => $invite],
            ['id' => 'ASC'],
        );

        return $this->proprietaire = [
            'id' => (int) $invite->getId(),
            'nom' => trim((string) $invite->getNom()),
            'portefeuille' => $portefeuille === null
                ? null
                : ['id' => (int) $portefeuille->getId(), 'nom' => (string) $portefeuille->getNom()],
        ];
    }

    /**
     * LA CONDITION PROPRE À L'AFFAIRE, quand la part écrite n'est pas celle qui paierait.
     *
     * ── LA RÈGLE ───────────────────────────────────────────────────────────────────────
     * La fiche d'un intermédiaire porte son taux habituel. Une ligne qui en écrit un autre
     * dit qu'EXCEPTIONNELLEMENT, pour cette affaire, c'est ce taux-là qui a été agréé : on
     * l'écrit en condition propre à l'affaire — l'étage que la cascade consulte en premier
     * ({@see RevenuPourCourtierIndicatorStrategy::conditionRetenue()}). La fiche n'est pas
     * touchée : les autres affaires du même apporteur continuent de suivre sa règle.
     *
     * ⚠ UNE CELLULE VIDE EST UN ARRANGEMENT À 0 %. La ligne nomme l'intermédiaire sans lui
     * donner de part : rien ne lui revient sur cette affaire. La condition à 0 % est écrite
     * même quand sa part habituelle est déjà nulle — c'est une décision propre au dossier,
     * et elle doit survivre à un changement de la fiche.
     *
     * ⚠ UNE COLONNE ABSENTE N'EST PAS UNE CELLULE VIDE. Un fichier exporté sans cette
     * colonne ne dit rien de la part : y lire zéro supprimerait la rétrocommission de tout
     * un portefeuille pour une colonne qu'on n'a simplement pas cochée.
     *
     * ⚠ À LA CRÉATION DE L'AFFAIRE SEULEMENT — l'appelant ne l'invoque que là. Une affaire
     * déjà en base ne se réécrit pas : un redépôt n'empile pas une seconde condition.
     *
     * @param int|string      $intermediaire identifiant, ou repère d'un partenaire que cette passe crée
     * @param int|string|null $risque        idem pour le risque de l'affaire
     * @param Anomalie[]      $anomalies
     */
    private function conditionPropreALAffaire(
        LigneLue $ligne,
        int|string $intermediaire,
        int|string|null $risque,
        array &$anomalies,
    ): ?MutationOperation {
        if ($ligne->colonne(PartsDesIntermediaires::COLONNE_PART) === null) {
            return null;
        }

        $vide = PartsDesIntermediaires::celluleVide($ligne);
        $part = PartsDesIntermediaires::partDeLaLigne($ligne);

        if (!$vide && ($part === null || $part < 0.0 || $part > self::TAUX_PLAFOND)) {
            $anomalies[] = $this->refus($ligne, PartsDesIntermediaires::COLONNE_PART, sprintf(
                'La part de l\'intermédiaire « %s » doit être un pourcentage entre 0 et 100 — par '
                . 'exemple 20 pour vingt pour cent. Vous avez écrit « %s ». Corrigez la colonne '
                . '« Intermédiaire · Part », ou videz-la si cette affaire ne lui rapporte rien.',
                $ligne->texte(PartsDesIntermediaires::COLONNE_NOM),
                $ligne->texte(PartsDesIntermediaires::COLONNE_PART),
            ));

            return null;
        }

        $taux = $part ?? 0.0;

        if (!$vide && ConditionDOffice::memeTaux($taux, $this->tauxHabituel($ligne, $intermediaire, $risque))) {
            return null;
        }

        return new MutationOperation(
            op: MutationOperation::OP_CREATE,
            entityShortName: 'ConditionPartage',
            fields: [
                'nom' => ConditionDOffice::nomPour($ligne->texte(PartsDesIntermediaires::COLONNE_NOM)) . ' (reprise)',
                'formule' => ConditionPartage::FORMULE_NE_SAPPLIQUE_PAS_SEUIL,
                'seuil' => 0,
                'taux' => $taux,
                'critereRisque' => ConditionPartage::CRITERE_PAS_RISQUES_CIBLES,
                'uniteMesure' => ConditionPartage::UNITE_SOMME_COMMISSION_PURE_RISQUE,
                'partenaire' => $intermediaire,
                // ⚠ LE BÉNÉFICIAIRE EST DIT, IL NE SE DÉDUIT PAS. Le formulaire d'une
                // condition choisit entre l'intermédiaire et un agent, et son défaut change
                // selon qu'il voit ou non l'affaire parente — au contrôle à blanc, il ne la
                // voit pas. Nommer l'intermédiaire rend le résultat identique aux deux passes.
                'beneficiaireType' => ConditionPartageType::BENEFICIAIRE_INTERMEDIAIRE,
            ],
        );
    }

    /**
     * LE TAUX QUI PAIERAIT DÉJÀ CET INTERMÉDIAIRE, sans condition propre à l'affaire.
     *
     * Un partenaire que cette passe crée prend la part du fichier. Un partenaire en base
     * suit la cascade : la première condition de sa FICHE qui vise ce risque, sinon sa
     * « Part % ». La règle est empruntée au calcul de l'argent, jamais redite.
     *
     * ⚠ LE SEUIL D'UNE CONDITION N'EST PAS JUGÉ ICI : il dépend de volumes que la reprise
     * n'a pas encore écrits. On compare le taux affiché, celui que le cabinet a négocié.
     *
     * @param int|string      $intermediaire
     * @param int|string|null $risque
     */
    private function tauxHabituel(LigneLue $ligne, int|string $intermediaire, int|string|null $risque): float
    {
        $partenaire = is_int($intermediaire) ? $this->em->find(Partenaire::class, $intermediaire) : null;
        if ($partenaire === null) {
            return $this->parts->partDeCreation($ligne->texte(PartsDesIntermediaires::COLONNE_NOM));
        }

        $condition = RevenuPourCourtierIndicatorStrategy::premiereConditionApplicable(
            RevenuPourCourtierIndicatorStrategy::conditionsDuPartenaire($partenaire),
            is_int($risque) ? $this->em->find(Risque::class, $risque) : null,
        );

        return $condition !== null
            ? (float) ($condition->getTaux() ?? 0.0)
            : (float) ($partenaire->getPart() ?? 0.0);
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
     * @param array<string, mixed>|\Closure $champsDeCreation une fonction n'est évaluée que
     *                                                     si l'entité naît — et ce qu'elle
     *                                                     empile précède alors la création
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
        array|\Closure $champsDeCreation,
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
            // ⚠ DANS UNE INSTRUCTION À PART, comme l'assiette de la commission : ce que la
            // fonction empile (un portefeuille à créer) doit précéder l'entité qui y renvoie.
            $champs = $champsDeCreation instanceof \Closure ? $champsDeCreation() : $champsDeCreation;

            $operations[] = new MutationOperation(
                op: MutationOperation::OP_CREATE,
                entityShortName: $entite,
                fields: $this->sansVide($champs),
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
    private function reconnu(
        string $entite,
        string $nom,
        Entreprise $entreprise,
        LigneLue $ligne,
        string $codeColonne,
        array &$anomalies,
    ): ?int {
        $renvoi = $this->resolveur->reconnaitreLePremier($entite, $nom, $entreprise);

        if ($renvoi->valeur === null) {
            // ⚠ INTROUVABLE N'EST PLUS UN REFUS, ET C'EST LE CŒUR DU CHANGEMENT. Le
            // catalogue du cabinet ne dicte plus ce qu'un classeur a le droit de nommer :
            // l'appelant replie sur la commission par défaut ({@see typeDeRevenu()}). Rien
            // n'est reproché ici, et rien n'est créé non plus.
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
     * UN REVENU QUE RIEN NE TARIFE VAUT ZÉRO — et il faut le dire AVANT de le découvrir
     * dans les totaux.
     *
     * ⚠ C'EST LA PROMESSE DE LA COMMISSION ORDINAIRE, ET ELLE A UNE CONDITION. Son taux
     * « vient du risque » : un risque qui n'en prescrit aucun la ramène à zéro. Or la
     * reprise CRÉE les risques qu'elle ne connaît pas, et un risque neuf n'a évidemment pas
     * de taux. La ligne passerait, la commission serait nulle, et une commission déjà
     * encaissée sur la même ligne afficherait un solde NÉGATIF — le symptôme exact que le
     * garde-fou du taux écrit sans pourcent combat par ailleurs.
     *
     * ⚠ ET ON NE DÉDUIT RIEN D'UNE DIVISION. Le classeur porte bien la commission HT et
     * l'assiette : leur rapport donnerait un taux plausible, et faux dès que la ligne porte
     * un arrondi ou couvre deux échéances. Un taux se LIT sur le paramétrage, il ne se
     * calcule pas — la même règle vaut pour les taxes. On nomme donc la case à remplir,
     * exactement comme l'assistant le fait
     * ({@see \App\Ai\Proposition\RevenuCourtierPrescrit::questionTaux()}).
     *
     * ⚠ UN AVERTISSEMENT, ET NON UN REFUS. Ce qui est encaissé est encaissé : le bloquer
     * ferait perdre une information juste pour une information manquante. L'utilisateur
     * pose le taux sur la fiche du risque, et tout le portefeuille repris suit d'un coup.
     *
     * @param int|string      $type   identifiant, ou repère d'un type créé par cette passe
     * @param int|string|null $risque idem pour le risque de l'affaire
     * @param Anomalie[]      $anomalies
     */
    private function signalerUnTauxNonPrescrit(
        int|string $type,
        int|string|null $risque,
        LigneLue $ligne,
        array &$anomalies,
    ): void {
        // Un type créé par cette passe est la commission ordinaire, et elle s'adosse au
        // risque par construction. Un type déjà en base, on le lit.
        if (is_int($type)) {
            $enBase = $this->em->find(TypeRevenu::class, $type);
            if ($enBase === null) {
                return;
            }

            if ($enBase->isAppliquerPourcentageDuRisque() !== true) {
                // Le type porte son propre tarif : rien à signaler s'il en a un.
                if ((float) ($enBase->getPourcentage() ?? 0.0) !== 0.0
                    || (float) ($enBase->getMontantflat() ?? 0.0) !== 0.0) {
                    return;
                }

                $this->direUneFois('revenu-sans-tarif', $ligne, 'commissionRevenus', sprintf(
                    'Le revenu « %s » ne porte ni taux ni montant forfaitaire : la commission '
                    . 'du courtier resterait à 0 sur les lignes qui le nomment. Renseignez-le '
                    . 'dans la rubrique « Types Revenus ».',
                    (string) $enBase->getNom(),
                ), $anomalies);

                return;
            }
        }

        // Le taux vient du risque : reste à savoir si ce risque en prescrit un. Un risque
        // que cette passe vient de créer n'en a aucun — c'est le cas le plus fréquent
        // d'une première reprise.
        $taux = is_int($risque)
            ? (float) ($this->em->find(Risque::class, $risque)?->getPourcentageCommissionSpecifiqueHT() ?? 0.0)
            : 0.0;

        if ($taux !== 0.0) {
            return;
        }

        $this->direUneFois('risque-sans-taux', $ligne, 'risque', sprintf(
            'Le risque « %s » ne prescrit aucun taux de commission, et la rémunération du '
            . 'courtier s\'y adosse : elle resterait à 0 sur toutes les lignes qui le portent. '
            . 'Ouvrez sa fiche et renseignez « %% commission spécifique HT » — ce que vous avez '
            . 'déjà encaissé, lui, est bien enregistré.',
            $ligne->texte('risque'),
        ), $anomalies);
    }

    /**
     * LE TYPE DE REVENU D'UN TERME DE LA COLONNE « Commission · Revenus ».
     *
     * ── DEUX ISSUES, ET PLUS AUCUN REFUS ───────────────────────────────────────────
     *   1. le cabinet a un type portant ce nom → on s'y rattache ;
     *   2. il n'en a pas → la COMMISSION PAR DÉFAUT ({@see CommissionOrdinaire}).
     *
     * ⚠ LA REPRISE NE CRÉE PAS DE TYPES DE REVENU, ELLE CRÉE DES REVENUS. C'est la
     * distinction qui débloque tout. Un TYPE porte un taux, un redevable, une assiette :
     * le fabriquer depuis un simple nom donnerait une configuration muette, et un
     * catalogue encombré d'entrées que personne n'a réglées. Un REVENU, lui, porte un nom
     * LIBRE, un type et son propre taux — et c'est exactement ce qu'une ligne décrit.
     *
     * D'où le repli universel : « Frais de gestion = 5 » ne fabrique pas un type « Frais
     * de gestion ». Il crée un revenu NOMMÉ « Frais de gestion », rattaché à la commission
     * par défaut, et facturé à 5 %. Le libellé du courtier est conservé, le calcul est
     * juste, et rien n'est ajouté à la configuration du cabinet.
     *
     * @param array<int, MutationOperation> $operations
     * @param Anomalie[]                    $anomalies
     *
     * @return array{id: int|string, nom: string, redevable: ?int}|null
     */
    private function typeDeRevenu(
        string $nom,
        Entreprise $entreprise,
        LigneLue $ligne,
        array &$anomalies,
        array &$operations,
    ): ?array {
        $connu = $this->reconnu('TypeRevenu', $nom, $entreprise, $ligne, 'commissionRevenus', $anomalies);

        if ($connu !== null) {
            return ['id' => $connu, 'nom' => $nom, 'redevable' => $this->redevableDuType($connu)];
        }

        $id = $this->commissionOrdinaire($entreprise, $ligne, $anomalies, $operations);
        if ($id === null) {
            return null;
        }

        // On le DIT quand le nom du classeur n'est pas celui d'un type du cabinet :
        // l'utilisateur doit pouvoir vérifier à quoi son revenu a été rattaché.
        if (ResolveurDeRenvois::normaliser($nom) !== ResolveurDeRenvois::normaliser(CommissionOrdinaire::NOM)) {
            $this->direUneFois(
                'repli-commission-ordinaire',
                $ligne,
                'commissionRevenus',
                sprintf(
                    'Votre cabinet n\'a aucun type de revenu nommé « %s » : ce revenu a été '
                    . 'rattaché à « %s », la commission par défaut — due par l\'assureur. Le nom '
                    . 'que vous avez écrit est conservé, et le taux de la ligne s\'applique. '
                    . 'Vérifiez le type dans la rubrique « Types Revenus » si une autre '
                    . 'rémunération devait s\'appliquer.',
                    $nom,
                    CommissionOrdinaire::NOM,
                ),
                $anomalies,
            );
        }

        // ⚠ LE NOM DU CLASSEUR EST CONSERVÉ, SEUL LE TYPE EST SUBSTITUÉ. Un revenu porte
        // un libellé LIBRE et un TYPE qui porte la configuration : les confondre — comme
        // on l'a fait un temps, en renommant le revenu « Commission Ordinaire » — faisait
        // perdre l'information que le classeur portait, et pour rien.
        return ['id' => $id, 'nom' => $nom, 'redevable' => TypeRevenu::REDEVABLE_ASSUREUR];
    }

    /**
     * LA COMMISSION ORDINAIRE DU CABINET — retrouvée, ou installée à l'identique du semis.
     *
     * ⚠ ELLE EXISTE PRESQUE TOUJOURS : `ServiceInitialisationEntreprise` la pose à la
     * naissance de chaque cabinet. Ce qui suit est le filet pour ceux qui l'ont renommée
     * ou supprimée — sans lui, la reprise leur opposerait « créez-la d'abord », alors
     * qu'on sait exactement ce qu'elle doit être.
     *
     * ⚠ ET AUCUN CIRCUIT D'ÉCRITURE NOUVEAU. C'est une `MutationOperation` comme les
     * autres : droits, formulaire, métrage et dry-run restent ceux de tout le monde. Un
     * déposant sans droit de création sur « Types Revenus » reçoit le refus « hors
     * périmètre » habituel, qui nomme le droit à demander.
     *
     * @param array<int, MutationOperation> $operations
     * @param Anomalie[]                    $anomalies
     *
     * @return int|string|null identifiant, repère local, ou null si le repère est illisible
     */
    private function commissionOrdinaire(
        Entreprise $entreprise,
        LigneLue $ligne,
        array &$anomalies,
        array &$operations,
    ): int|string|null {
        $existant = $this->resolveur->reconnaitreLePremier('TypeRevenu', CommissionOrdinaire::NOM, $entreprise);
        if ($existant->valeur !== null) {
            return (int) $existant->valeur;
        }

        $repere = CleNaturelle::pourLibelle('typrev', CommissionOrdinaire::NOM);
        if ($repere === null) {
            return null;
        }

        if ($this->neuf($repere)) {
            // ⚠ L'ASSIETTE S'EMPILE AVANT, ET DANS UNE INSTRUCTION À PART. Calculée au
            // milieu du tableau `fields`, elle dépendrait de l'ordre d'évaluation que PHP
            // choisit pour un `$operations[] = …` — et un renvoi qui part en avant n'est
            // jamais résolu.
            $assiette = $this->assietteDeCommission($entreprise, $operations);

            $operations[] = new MutationOperation(
                op: MutationOperation::OP_CREATE,
                entityShortName: 'TypeRevenu',
                // ⚠ MOT POUR MOT LE SEMIS OFFICIEL
                // ({@see \App\Services\ServiceInitialisationEntreprise::initialiserChargementsEtRevenus()}) :
                // un taux pris sur le risque, dû par l'assureur, partageable avec un
                // partenaire et réglable en plusieurs fois. Deux définitions pour un même
                // type, et le cabinet repris ne calculerait pas comme le cabinet neuf.
                fields: [
                    'nom' => CommissionOrdinaire::NOM,
                    'modeCalcul' => TypeRevenu::MODE_CALCUL_POURCENTAGE_CHARGEMENT,
                    'typeChargement' => $assiette,
                    'appliquerPourcentageDuRisque' => true,
                    'redevable' => TypeRevenu::REDEVABLE_ASSUREUR,
                    'shared' => true,
                    'multipayments' => true,
                ],
                ref: $repere,
            );

            $this->direUneFois(
                'creation-commission-ordinaire',
                $ligne,
                'commissionRevenus',
                sprintf(
                    'Votre cabinet n\'avait pas de « %s » : elle a été créée, due par l\'assureur '
                    . 'et au taux de commission du risque concerné. Vérifiez-la dans la rubrique '
                    . '« Types Revenus » avant de vous y fier.',
                    CommissionOrdinaire::NOM,
                ),
                $anomalies,
            );
        }

        return CleNaturelle::renvoiVers($repere);
    }

    /**
     * L'ASSIETTE DE LA COMMISSION : la prime nette du cabinet.
     *
     * ⚠ `TypeRevenuType` l'exige, et le calcul aussi : un type de revenu sans
     * chargement cible rend une commission nulle par construction —
     * `getCotationMontantChargementPrime()` apparie sur le TYPE, jamais sur le nom.
     * Absente, on la crée : c'est le même poste que le semis installe, et un cabinet sans
     * prime nette n'aurait de toute façon pas de prime à reprendre.
     *
     * ⚠ ET L'OPÉRATION PART AVANT CELLE DU TYPE. Cette méthode est appelée depuis la
     * construction des champs du `TypeRevenu`, donc AVANT que celui-ci ne soit empile :
     * un renvoi ne va jamais en avant, et l'ordre en dépend.
     *
     * @param array<int, MutationOperation> $operations
     */
    private function assietteDeCommission(Entreprise $entreprise, array &$operations): int|string
    {
        $id = $this->typePourFonction($entreprise, Chargement::FONCTION_PRIME_NETTE);
        if ($id !== null) {
            return $id;
        }

        $libelle = CatalogueDesColonnes::FONCTIONS[Chargement::FONCTION_PRIME_NETTE];
        $repere = (string) CleNaturelle::pourLibelle('chg', $libelle);

        if ($this->neuf($repere)) {
            $operations[] = new MutationOperation(
                op: MutationOperation::OP_CREATE,
                entityShortName: 'Chargement',
                fields: [
                    'nom' => $libelle,
                    'fonction' => Chargement::FONCTION_PRIME_NETTE,
                ],
                ref: $repere,
            );
        }

        return CleNaturelle::renvoiVers($repere);
    }

    /**
     * LE REVENU D'UNE PROPOSITION DÉJÀ EN BASE — celui que l'écriture d'ouverture facture.
     *
     * ⚠ SANS LUI, LE SECOND DÉPÔT PERDAIT LES COMMISSIONS. Le registre local ne connaît
     * que les revenus que CETTE passe a posés ; une échéance neuve sous une police déjà
     * reprise n'en a donc aucun, et l'encaissement était refusé — « cette ligne ne dit
     * pas de quelle commission il s'agit » — alors que la proposition en portait un
     * depuis le premier dépôt.
     *
     * La commission ordinaire est préférée quand la proposition en porte plusieurs : c'est
     * celle qu'une colonne laissée vide désigne, et celle que l'assureur précompte.
     *
     * @return array{id: int, redevable: ?int}|null
     */
    private function revenuExistant(int $idCotation): ?array
    {
        $cotation = $this->em->find(Cotation::class, $idCotation);
        if ($cotation === null) {
            return null;
        }

        $premier = null;

        foreach ($cotation->getRevenus() as $revenu) {
            $id = $revenu->getId();
            if ($id === null) {
                continue;
            }

            $type = $revenu->getTypeRevenu();
            $candidat = ['id' => (int) $id, 'redevable' => $type?->getRedevable()];
            $premier ??= $candidat;

            if ($type !== null
                && ResolveurDeRenvois::normaliser((string) $type->getNom())
                    === ResolveurDeRenvois::normaliser(CommissionOrdinaire::NOM)) {
                return $candidat;
            }
        }

        return $premier;
    }

    /**
     * UN CONSTAT QUI VAUT POUR LE FICHIER, dit UNE FOIS par palier.
     *
     * ⚠ VOIR {@see $ditUneFois} : répété ligne à ligne, un avertissement juste devient
     * du bruit, remplit le rapport et pousse les VRAIES erreurs au-delà de la troncature.
     * Il se pose sur la première cellule concernée, que le classeur annoté saura
     * surligner.
     *
     * @param Anomalie[] $anomalies
     */
    private function direUneFois(
        string $cle,
        LigneLue $ligne,
        string $codeColonne,
        string $message,
        array &$anomalies,
    ): void {
        if (isset($this->ditUneFois[$cle])) {
            return;
        }

        $this->ditUneFois[$cle] = true;

        $anomalies[] = Anomalie::avertissement(
            Anomalie::VALEUR_INVALIDE,
            $message,
            $ligne->feuille,
            $ligne->numero,
            $ligne->colonne($codeColonne),
        );
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
     * CE QUE CE REVENU RAPPORTE VRAIMENT SUR CETTE ÉCHÉANCE, TAXE COMPRISE.
     *
     * ⚠ UNE COMMISSION ENCAISSÉE EST TTC, UN TAUX DONNE DU HT. Les deux ne sont pas
     * comparables tels quels : l'assureur precompte sa taxe — seize pour cent au barème
     * courant — et c'est le montant TTC qui arrive sur le compte du cabinet. Confronter
     * 518,40 encaisses a un HT de 500 ferait crier a l'erreur sur une ligne juste.
     *
     * ⚠ LE BARÈME SE LIT, IL NE SE RECOPIE PAS. `ServiceTaxes` est la source unique du
     * projet ; en redire une seconde version ici, ce serait s'engager a la maintenir deux
     * fois, et à expliquer un jour pourquoi la reprise et l'ecran ne taxent pas pareil.
     *
     * ⚠ ET LE TAUX IARD N'EST PAS DEVINE : ON PREND LE PLUS GENEREUX. La branche du
     * risque décide du taux applicable, et un risque que cette passe vient de creer n'en a
     * aucune. Retenir le plus élevé des deux maximise le TTC admissible, donc MINIMISE les
     * reproches : mieux vaut laisser passer une ligne tordue que refuser une ligne juste.
     *
     * ⚠ ET TOUTE AFFAIRE N'EST PAS TAXÉE. Un client exonéré, un risque non imposable :
     * l'assureur ne précompte alors rien, et ce qui est encaissé EST le hors-taxes.
     * Ajouter seize pour cent au plafond laisserait passer un taux faux d'un sixième sur
     * précisément les affaires où la marge est nulle.
     *
     * @param int|string      $type   identifiant du type de revenu, ou repère de cette passe
     * @param int|string|null $client idem pour l'assuré ; un repère désigne un client que
     *                                cette passe crée, donc non exonéré (défaut de l'entité)
     * @param int|string|null $risque idem pour le risque, que la reprise crée IMPOSABLE
     * @param array{valeur: float|null, estTaux: bool, source?: string} $terme
     *
     * @return float|null null quand la ligne ne permet pas de conclure — assiette absente,
     *                    taux hérité, type pas encore en base
     */
    private function rendementTtc(
        int|string $type,
        array $terme,
        LigneLue $ligne,
        Entreprise $entreprise,
        int|string|null $client,
        int|string|null $risque,
    ): ?float {
        // Un taux hérité ou absent ne se confronte à rien : c'est le classeur qu'on juge,
        // pas la configuration du cabinet.
        if ($terme['valeur'] === null || ValeursMultiples::estInformatif($terme)) {
            return null;
        }

        $assiette = $this->assietteDeLaLigne($type, $ligne);
        if ($assiette === null) {
            return null;
        }

        $ht = $terme['estTaux']
            ? $assiette * ($terme['valeur'] / 100.0)
            : (float) $terme['valeur'];

        if (!$this->affaireTaxee($client, $risque)) {
            return $ht;
        }

        return $ht + max(
            $this->taxes->getMontantTaxe($ht, true, true, $entreprise),
            $this->taxes->getMontantTaxe($ht, false, true, $entreprise),
        );
    }

    /**
     * L'ASSUREUR PRÉCOMPTE-T-IL UNE TAXE SUR CETTE AFFAIRE ?
     *
     * Deux réglages l'excluent, et il suffit de l'un : un CLIENT exonéré de taxes, ou un
     * RISQUE déclaré non imposable. Dans ces cas, la commission encaissée ne contient
     * aucune taxe, et le plafond du contrôle est le hors-taxes tout sec.
     *
     * ⚠ UN REPÈRE VAUT « TAXÉE », ET CE N'EST PAS UN RACCOURCI. Une chaîne désigne une
     * entité que CETTE passe est en train de créer : le client naît non exonéré (défaut de
     * `Client::$exonere`) et le risque naît imposable (la reprise le pose explicitement).
     * Les interroger en base n'aurait aucun sens — ils n'y sont pas encore — et le cas est
     * donc tranché par construction, pas par défaut de mieux.
     *
     * ⚠ LA RÈGLE EST EMPRUNTÉE, PAS REDITE. `ServiceTaxes::commissionExoneree()` la porte
     * pour tout le projet — l'écran, les indicateurs, la comptabilité. En écrire ici une
     * seconde version, ce serait promettre que la reprise juge comme le moteur calcule, et
     * manquer à cette promesse au premier réglage retouché d'un seul côté.
     */
    private function affaireTaxee(int|string|null $client, int|string|null $risque): bool
    {
        return !$this->taxes->commissionExoneree(
            is_int($client) ? $this->em->find(Client::class, $client) : null,
            is_int($risque) ? $this->em->find(Risque::class, $risque) : null,
        );
    }

    /**
     * L'ASSIETTE SUR LAQUELLE CE REVENU SE CALCULE, TELLE QUE LA LIGNE LA PORTE.
     *
     * ⚠ LA COLONNE PORTE LA PART DE L'ECHEANCE, ET C'EST CE QU'ON VEUT ICI. Ailleurs on
     * la divise par la part pour remonter au total de la cotation
     * ({@see chargementsDeLaLigne()}) ; la commission encaissée, elle, est celle de
     * l'echeance. Les deux grandeurs doivent se comparer a la meme maille, sans quoi une
     * police à quatre échéances paraîtrait encaisser quatre fois trop.
     *
     * ⚠ ET L'ASSIETTE SUIT LE TYPE, PAS UNE HABITUDE. Une « Commission sur Fronting » se
     * calcule sur le fronting : la mesurer sur la prime nette sous-estimerait son
     * rendement, et ferait reprocher une ligne parfaitement juste. Un type que cette passe
     * vient de créer est la commission par défaut, assise sur la prime nette.
     *
     * @param int|string $type identifiant du type de revenu, ou repère de cette passe
     */
    private function assietteDeLaLigne(int|string $type, LigneLue $ligne): ?float
    {
        $fonction = Chargement::FONCTION_PRIME_NETTE;

        if (is_int($type)) {
            $cible = $this->em->find(TypeRevenu::class, $type)?->getTypeChargement()?->getFonction();
            if ($cible === null || !isset(CatalogueDesColonnes::FONCTIONS[$cible])) {
                return null;
            }
            $fonction = $cible;
        }

        $brut = $ligne->valeur(CatalogueDesColonnes::codeDeFonction($fonction));
        if ($brut === null || trim((string) (is_scalar($brut) ? $brut : '')) === '') {
            return null;
        }

        $montant = $this->nombreBrut($brut);

        return $montant > 0.0 ? $montant : null;
    }

    /**
     * LE CABINET A-T-IL ENCAISSÉ PLUS QUE CE QUE SON TAUX PEUT PRODUIRE ?
     *
     * ⚠ C'EST UN POINT DE BLOCAGE, ET IL DOIT LE RESTER. Toutes les autres deductions de
     * ce service arrangent la ligne : un nom inconnu se rattache, une colonne vide prend
     * un defaut. Celle-ci ne s'arrange pas. Si le taux fourni, une fois la taxe de
     * l'assureur ajoutee, ne suffit pas a produire ce que le cabinet dit avoir encaisse,
     * alors l'une des deux valeurs est fausse — et RIEN dans le fichier ne dit laquelle.
     *
     * Deviner ici coûterait cher dans les deux sens : retenir le taux ferait une note
     * éternellement en solde négatif, retenir l'encaissement inventerait un taux que le
     * cabinet n'a jamais pratique. On refuse donc, on montre les deux chiffres, et
     * l'utilisateur tranche dans SON classeur — l'erreur vient souvent de ses donnees
     * d'origine, et c'est la qu'il faut la corriger.
     *
     * ⚠ UNE MARGE, PARCE QUE LES ARRONDIS EXISTENT. Un pour cent : assez pour absorber
     * une décimale perdue en chemin, trop peu pour laisser passer un taux faux.
     */
    private function commissionInsuffisante(LigneLue $ligne, float $commissionTtc, bool $toutEstTarife): ?Anomalie
    {
        $encaissee = $this->nombre($ligne, 'ouvertureCommissionEncaissee');

        // Rien d'encaisse, ou un rendement qu'on ne sait pas calculer : il n'y a pas de
        // contradiction à montrer, et un reproche sans chiffres ne se corrige pas.
        if ($encaissee === null || $encaissee <= 0.0 || !$toutEstTarife || $commissionTtc <= 0.0) {
            return null;
        }

        if ($encaissee <= $commissionTtc * (1.0 + self::MARGE_D_ARRONDI)) {
            return null;
        }

        return $this->refus($ligne, 'ouvertureCommissionEncaissee', sprintf(
            'Cette ligne annonce %s de commission déjà encaissée, mais le taux qu\'elle donne '
            . 'ne peut en produire que %s au maximum — taxe de l\'assureur comprise. L\'un des '
            . 'deux est faux, et le fichier ne dit pas lequel : corrigez le taux dans '
            . '« Commission · Revenus », ou le montant dans « Ouverture · Commission '
            . 'encaissée ». Cette ligne ne sera pas reprise tant que les deux ne '
            . 's\'accordent pas.',
            $this->nombreLisible($encaissee),
            $this->nombreLisible($commissionTtc),
        ));
    }

    /**
     * LE TERME DE REVENU DIT-IL AUTRE CHOSE QUE CE QU'IL PARAÎT ?
     *
     * ⚠ DEUX PIÈGES, ET ILS SONT SYMÉTRIQUES.
     *
     * Le premier vient des classeurs d'AVANT : un nombre nu y valait un MONTANT, et
     * « Commission = 5000 » désignait un forfait. Relu sous la règle d'aujourd'hui, il
     * deviendrait un taux de cinq mille pour cent — une commission cinquante fois la
     * prime, écrite sans que rien ne bronche. Aucun taux de courtage n'atteint cent :
     * au-delà, ce n'est pas un taux, et on le dit.
     *
     * Le second est celui d'un FORFAIT déclaré qui se contredit avec la commission déjà
     * encaissée sur la même ligne. Un forfait ne produit pas beaucoup plus que lui-même —
     * les taxes n'y ajoutent qu'une fraction —, si bien qu'un encaissement qui dépasse le
     * double du forfait ne peut pas en venir.
     *
     * ⚠ ON NE DEVINE PAS : ON CONFRONTE. Basculer d'office la valeur d'une forme à l'autre
     * ferait la faute inverse le jour d'un cas légitime. On refuse en nommant la
     * correction — le signe pourcent, ou le marqueur « (forfait) » — et l'utilisateur
     * tranche.
     *
     * ⚠ LES SEUILS SONT LARGES À DESSEIN. Mieux vaut laisser passer un cas tordu que
     * refuser un fichier juste : le reproche doit rester rare, sans quoi on l'ignore.
     *
     * @param array{valeur: float|null, estTaux: bool, source?: string} $terme
     */
    private function ambiguiteDuRevenu(LigneLue $ligne, string $nom, array $terme): ?Anomalie
    {
        if ($terme['valeur'] === null || $terme['valeur'] <= 0.0 || ValeursMultiples::estInformatif($terme)) {
            return null;
        }

        if ($terme['estTaux']) {
            return $terme['valeur'] <= self::TAUX_PLAFOND
                ? null
                : $this->refus($ligne, 'commissionRevenus', sprintf(
                    'Vous avez écrit « %s = %s », ce qui se lit comme un TAUX de %s %% — soit '
                    . 'bien plus que la prime elle-même. Aucune commission de courtage n\'atteint '
                    . 'ce niveau. S\'il s\'agit d\'un MONTANT FIXE, écrivez « %s = %s (forfait) ». '
                    . 'Sinon, corrigez le taux.',
                    $nom,
                    $this->nombreLisible($terme['valeur']),
                    $this->nombreLisible($terme['valeur']),
                    $nom,
                    $this->nombreLisible($terme['valeur']),
                ));
        }

        $encaissee = $this->nombre($ligne, 'ouvertureCommissionEncaissee');
        if ($encaissee === null || $encaissee <= $terme['valeur'] * self::MARGE_DU_FORFAIT) {
            return null;
        }

        $valeur = $this->nombreLisible($terme['valeur']);

        return $this->refus($ligne, 'commissionRevenus', sprintf(
            'Vous avez écrit « %s = %s (forfait) », soit un MONTANT FIXE de %s. Or la même '
            . 'ligne annonce %s de commission déjà encaissée — un montant fixe de %s ne peut '
            . 'pas produire cela. S\'il s\'agit d\'un TAUX, retirez « (forfait) » : « %s = %s » '
            . 'vaut %s %%. Sinon, c\'est la commission encaissée qu\'il faut corriger.',
            $nom,
            $valeur,
            $valeur,
            $this->nombreLisible($encaissee),
            $valeur,
            $nom,
            $valeur,
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
