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
use App\Entity\Entreprise;
use App\Entity\Note;
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

    public function __construct(
        private readonly ResolveurDeRenvois $resolveur,
        private readonly NormaliseurDeDates $dates,
        private readonly EntityManagerInterface $em,
        private readonly DefautsContextuels $defauts,
    ) {
    }

    /** Le registre est propre entre deux contrôles — le service est partagé. */
    public function reinitialiser(): void
    {
        $this->registre = [];
        $this->revenuParProposition = [];
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
                'Cette ligne n\'a ni identifiant de tranche ni référence de police : rien ne permet '
                . 'de savoir si elle décrit une affaire nouvelle ou une échéance d\'une affaire déjà '
                . 'présente. Renseignez la référence de la police, ou conservez l\'identifiant tel '
                . 'qu\'il a été exporté.',
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
        ]);

        $risque = $this->rattacher('Risque', $ligne, 'risque', CleNaturelle::RISQUE, $entreprise, $anomalies, $operations, [
            'nomComplet' => $ligne->texte('risque'),
            'code' => $ligne->texte('risque'),
        ]);

        $assureur = $this->rattacher('Assureur', $ligne, 'assureur', CleNaturelle::ASSUREUR, $entreprise, $anomalies, $operations, [
            'nom' => $ligne->texte('assureur'),
        ]);

        $intermediaire = $this->rattacher('Partenaire', $ligne, 'intermediaire', CleNaturelle::PARTENAIRE, $entreprise, $anomalies, $operations, [
            'nom' => $ligne->texte('intermediaire'),
        ]);

        // ── L'opportunité ───────────────────────────────────────────────────────────
        $piste = (string) CleNaturelle::pourChaine(CleNaturelle::PISTE, $reference);
        if ($this->neuf($piste)) {
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

        // ── La proposition, et avec elle la PRIME et la RÉMUNÉRATION ────────────────
        $cotation = (string) CleNaturelle::pourChaine(CleNaturelle::COTATION, $reference);
        if ($this->neuf($cotation)) {
            $collections = [];

            // ⚠ C'EST ICI QUE LA PRIME REVIENT. `ChargementPourPrime` n'a pas de feuille
            // dans le format normalisé — elle est absente du périmètre d'échange —, si
            // bien qu'une reprise par ce format rend des propositions SANS PRIME. On
            // l'écrit donc en COLLECTION IMBRIQUÉE de la proposition, ce que le circuit
            // d'écriture sait déjà faire.
            foreach ($this->chargementsDeLaLigne($ligne, $colonnes) as $codeColonne => [$nom, $montant]) {
                $collections['chargements'][] = new MutationOperation(
                    op: MutationOperation::OP_CREATE,
                    entityShortName: 'ChargementPourPrime',
                    fields: $this->sansVide([
                        'nom' => $nom,
                        'type' => $this->reconnu('Chargement', $nom, $entreprise, $ligne, $codeColonne, $anomalies),
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
                    'piste' => CleNaturelle::renvoiVers($piste),
                    'assureur' => $assureur,
                    // ⚠ LA DURÉE SE LIT SUR LA PÉRIODE, elle ne se suppose pas : un contrat
                    // de vingt-deux jours n'est pas une police annuelle. `DefautsContextuels`
                    // sait la déduire, mais en allant la chercher sur l'avenant EN
                    // COLLECTION de la proposition — or la convergence impose ici de le
                    // garder en opération distincte, une police et son avenant n° 2
                    // partageant la même proposition. On emprunte donc la formule, sans la
                    // réécrire.
                    'duree' => $this->defauts->dureeEnMois(
                        $this->date($ligne, 'policeDateEffet', 'Avenant', 'startingAt'),
                        $this->date($ligne, 'policeEcheance', 'Avenant', 'endingAt'),
                    ),
                ]),
                collections: $collections,
                ref: $cotation,
            );
        }

        // ── La police ───────────────────────────────────────────────────────────────
        $avenant = (string) CleNaturelle::pourAvenant($reference, $ligne->texte('policeNumeroAvenant'));
        if ($this->neuf($avenant)) {
            $operations[] = new MutationOperation(
                op: MutationOperation::OP_CREATE,
                entityShortName: 'Avenant',
                fields: $this->sansVide([
                    'referencePolice' => $reference,
                    'numero' => $ligne->texte('policeNumeroAvenant'),
                    'startingAt' => $this->date($ligne, 'policeDateEffet', 'Avenant', 'startingAt'),
                    'endingAt' => $this->date($ligne, 'policeEcheance', 'Avenant', 'endingAt'),
                    'cotation' => CleNaturelle::renvoiVers($cotation),
                ]),
                ref: $avenant,
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

        $tranche = $this->tranche($ligne, $colonnes, null, CleNaturelle::renvoiVers($cotation));
        if ($tranche === null) {
            return $operations;
        }

        // La prime déjà réglée devient UNE écriture, imbriquée sous l'échéance.
        $paiement = $this->ouverturePrime($ligne);
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

        $this->ouvrirLaCommission(
            $ligne,
            $repereTranche,
            $this->revenuParProposition[$cotation] ?? null,
            $assureur,
            $operations,
            $anomalies,
        );
        $this->ouvrirLaRetro($ligne, $repereTranche, $intermediaire, $operations, $anomalies);

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
     * L'ENCAISSEMENT DE COMMISSION N'EST PAS REPRIS — et le fichier le dit.
     *
     * ── POURQUOI, ET CE N'EST PAS UN DÉFAUT DE LA REPRISE ──────────────────────────
     * ⚠ UNE NOTE NE PEUT PAS ÊTRE CRÉÉE PAR LE CIRCUIT COMMUN. `Note::$validated` et
     * `Note::$signature` sont NON NULLES en base et ABSENTES de `NoteType` : le contrôle à
     * blanc les réclame — elles sont obligatoires — sans qu'aucun champ ne permette de les
     * fournir, un formulaire ignorant ce qu'il ne déclare pas. Constaté le 08/09/2026 sur
     * un portefeuille réel : CINQUANTE erreurs bloquantes, une par échéance portant une
     * commission encaissée, rendant toute la reprise inutilisable.
     *
     * ⚠ ET ON NE FOURNIT PAS CES CHAMPS « POUR FAIRE PASSER » LE DRY-RUN. Le contrôle
     * cesserait de se plaindre et l'écriture échouerait en SQL sur une contrainte NOT
     * NULL — précisément le piège que `champsRequisManquants()` existe pour éviter :
     * « que Ket les demande plutôt que de provoquer une erreur SQL à l'exécution ».
     *
     * Ce qu'une note de REPRISE doit porter — est-elle validée ? signée par qui ? — est
     * une question métier, pas une valeur qu'on invente.
     *
     * ── CE QUE LE JOUR VENU IL FAUDRA REFAIRE ─────────────────────────────────────
     * ⚠ UNE NOTE PAR ÉCHÉANCE, ET C'EST UNE CONTRAINTE DU CALCUL.
     * `getTrancheMontantCommissionEncaissee()` applique la proportion payée de la note
     * ENTIÈRE à chacun de ses articles : une note groupant plusieurs échéances ne peut pas
     * exprimer des taux d'encaissement différents. Avec UN article — lié à la fois à
     * l'échéance et au revenu, sans quoi `getArticleMontant()` rend zéro — et UN règlement
     * du même montant, la commission encaissée vaut exactement ce qu'on a versé.
     *
     * ⚠ PERDRE UN CHIFFRE EN SILENCE SERAIT PIRE QUE DE NE PAS LE REPRENDRE : d'où
     * l'avertissement, qui nomme le montant laissé de côté et où le saisir.
     *
     * @param array<int, MutationOperation> $operations
     * @param Anomalie[]                    $anomalies
     */
    private function ouvrirLaCommission(
        LigneLue $ligne,
        string $repereTranche,
        ?string $repereRevenu,
        int|string|null $assureur,
        array &$operations,
        array &$anomalies,
    ): void {
        $montant = $this->nombre($ligne, 'ouvertureCommissionEncaissee');
        if ($montant === null || $montant <= 0.0) {
            return;
        }

        $anomalies[] = Anomalie::avertissement(
            Anomalie::VALEUR_INVALIDE,
            sprintf(
                'La commission encaissée (%s) n\'a pas été reprise : une note de commission '
                . 'exige une validation et une signature que le formulaire de saisie ne '
                . 'propose pas encore. Tout le reste de la ligne est repris ; enregistrez cet '
                . 'encaissement depuis la rubrique Notes.',
                number_format($montant, 2, ',', ' '),
            ),
            $ligne->feuille,
            $ligne->numero,
            $ligne->colonne('ouvertureCommissionEncaissee'),
        );
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
                'Une rétrocommission reversée de %s est indiquée, mais aucun intermédiaire n\'est '
                . 'nommé : un reversement sans bénéficiaire ne peut pas être écrit.',
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
                '« %s » ne correspond à aucun élément de votre configuration. Créez-le d\'abord dans '
                . 'la rubrique correspondante : un type porte un taux, un redevable et une assiette, '
                . 'qu\'un simple nom ne suffit pas à définir.',
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
     * LES CHARGEMENTS D'UNE LIGNE — une colonne par type, et le prorata REMONTÉ.
     *
     * ⚠ LA COLONNE PORTE LA PART DE L'ÉCHÉANCE, LA COTATION PORTE LE TOUT. C'est le prix
     * d'une colonne totalisable : sans le prorata, une police à quatre échéances
     * répéterait quatre fois les mêmes montants et la ligne de totaux les compterait
     * quatre fois. On divise donc par la part pour retrouver ce que la cotation porte —
     * et les quatre lignes redonnent le même montant, que la convergence n'écrit qu'une
     * fois.
     *
     * ⚠ PART ABSENTE = LA LIGNE VAUT POUR TOUT. Une échéance sans part est une échéance
     * unique : le montant lu EST celui de la cotation. Supposer autre chose diviserait par
     * zéro, ou pire, par un nombre inventé. Sur les données réelles, les quatre-vingts
     * tranches portent une part — le cas est théorique, il doit être écrit.
     *
     * ⚠ UN ZÉRO N'EST PAS UNE ABSENCE. Un chargement à 0 existe : c'est un poste ouvert et
     * non facturé. On ne retient que les cellules VIDES, pour ne pas créer des lignes que
     * personne n'a écrites.
     *
     * @param array<string, ColonneEtat> $colonnes
     *
     * @return array<string, array{0: string, 1: float}> code de colonne => [nom du type, montant]
     */
    private function chargementsDeLaLigne(LigneLue $ligne, array $colonnes): array
    {
        $facteur = $this->partDeLaLigne($ligne);
        $chargements = [];

        foreach ($colonnes as $code => $colonne) {
            if (!str_starts_with($code, CatalogueDesColonnes::PREFIXE_CHARGEMENT)) {
                continue;
            }

            $brut = $ligne->valeur($code);
            if ($brut === null || trim((string) (is_scalar($brut) ? $brut : '')) === '') {
                continue;
            }

            // Le libellé porte le nom du type : « Prime · Prime nette ».
            $nom = str_contains($colonne->libelle, ' · ')
                ? trim(explode(' · ', $colonne->libelle, 2)[1])
                : $colonne->libelle;

            $chargements[$code] = [$nom, (float) $this->nombreBrut($brut) / $facteur];
        }

        return $chargements;
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
