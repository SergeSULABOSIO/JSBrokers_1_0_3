<?php

namespace App\Echange\Reprise;

use App\Ai\Mutation\MutationOperation;
use App\Echange\Canevas\CanevasDEchange;
use App\Echange\Classeur\LigneLue;
use App\Echange\Etat\ColonneEtat;
use App\Echange\Etat\EtatDuPortefeuille;
use App\Echange\Service\Anomalie;
use App\Echange\Service\ResolveurDeRenvois;
use App\Entity\Entreprise;
use App\Entity\Note;

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

    public function __construct(private readonly ResolveurDeRenvois $resolveur)
    {
    }

    /** Le registre est propre entre deux contrôles — le service est partagé. */
    public function reinitialiser(): void
    {
        $this->registre = [];
        $this->revenuParProposition = [];
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
            $chargements = $this->termes($ligne, 'primeChargements', $anomalies);
            foreach ($chargements as $nom => $terme) {
                $collections['chargements'][] = new MutationOperation(
                    op: MutationOperation::OP_CREATE,
                    entityShortName: 'ChargementPourPrime',
                    fields: $this->sansVide([
                        'nom' => $nom,
                        'type' => $this->reconnu('Chargement', $nom, $entreprise, $ligne, 'primeChargements', $anomalies),
                        'montantFlatExceptionel' => $terme['valeur'],
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
                    'startingAt' => $ligne->texte('policeDateEffet'),
                    'endingAt' => $ligne->texte('policeEcheance'),
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

        return $operations;
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
                'paidAt' => $ligne->texte('ouverturePrimeLe') ?: $ligne->texte('policeDateEffet'),
                'reference' => self::REFERENCE_OUVERTURE,
                'description' => 'Situation reprise depuis un classeur de reprise.',
            ]),
        );
    }

    /**
     * L'ÉCRITURE D'OUVERTURE DE LA COMMISSION : une note soldée, et une seule.
     *
     * ── POURQUOI UNE NOTE PAR ÉCHÉANCE, ET NON UNE POUR TOUT L'IMPORT ──────────────
     * ⚠ C'EST UNE CONTRAINTE DU CALCUL, PAS UN CHOIX D'ÉCRITURE.
     * `IndicatorCalculationHelper::getTrancheMontantCommissionEncaissee()` applique la
     * proportion payée de la note ENTIÈRE à chacun de ses articles. Une note groupant
     * plusieurs échéances ne peut donc pas exprimer des taux d'encaissement différents :
     * une échéance soldée et une autre encaissée à 30 % y sont inexprimables.
     *
     * Avec UN article et UN règlement du même montant, la proportion vaut exactement ce
     * qu'on a versé — et la commission encaissée de l'échéance vaut le montant écrit.
     *
     * ── CE QU'ELLE EXIGE ──────────────────────────────────────────────────────────
     * ⚠ UN REVENU À FACTURER. `getArticleMontant()` rend 0 pour un article qui n'est pas
     * lié à un revenu ET à une tranche : sans lui, la note serait posée, le règlement
     * aussi, et la commission encaissée resterait à zéro — un travail invisible et faux.
     * On refuse donc, en nommant ce qui manque.
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

        if ($repereRevenu === null) {
            $anomalies[] = $this->refus($ligne, 'ouvertureCommissionEncaissee', sprintf(
                'Une commission encaissée de %s est indiquée, mais la colonne « Commission · '
                . 'Revenus » est vide : il n\'y a rien à facturer. Une note sans revenu vaudrait '
                . 'zéro, et l\'encaissement serait perdu sans que rien ne le signale.',
                number_format($montant, 2, ',', ' '),
            ));

            return;
        }

        $date = $ligne->texte('ouvertureCommissionLe') ?: $ligne->texte('policeDateEffet');

        $operations[] = new MutationOperation(
            op: MutationOperation::OP_CREATE,
            entityShortName: 'Note',
            fields: $this->sansVide([
                'nom' => 'Reprise — ' . ($ligne->texte('policeReference') ?: $this->nomDeLAffaire($ligne)),
                'reference' => self::REFERENCE_OUVERTURE,
                // Une note de DÉBIT adressée à l'ASSUREUR : c'est ce que le calcul de la
                // commission encaissée retient (avec le client), et rien d'autre.
                'type' => Note::TYPE_NOTE_DE_DEBIT,
                'addressedTo' => Note::TO_ASSUREUR,
                'assureur' => $assureur,
                'description' => 'Situation reprise depuis un classeur de reprise.',
            ]),
            collections: [
                'articles' => [new MutationOperation(
                    op: MutationOperation::OP_CREATE,
                    entityShortName: 'Article',
                    fields: [
                        // La quantité vaut 1 : l'article facture le revenu de cette
                        // échéance en entier. Ce qui module l'encaissement, c'est le
                        // RÈGLEMENT ci-dessous, non la quantité.
                        'quantite' => 1.0,
                        'tranche' => CleNaturelle::renvoiVers($repereTranche),
                        'revenuFacture' => CleNaturelle::renvoiVers($repereRevenu),
                    ],
                )],
                'paiements' => [new MutationOperation(
                    op: MutationOperation::OP_CREATE,
                    entityShortName: 'Paiement',
                    fields: $this->sansVide([
                        'montant' => $montant,
                        'paidAt' => $date,
                        'reference' => self::REFERENCE_OUVERTURE,
                    ]),
                )],
            ],
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
                'paidAt' => $ligne->texte('ouvertureRetroLe') ?: $ligne->texte('policeDateEffet'),
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
            'payableAt' => $ligne->texte('tranchePayableAt'),
            'echeanceAt' => $ligne->texte('trancheEcheanceAt'),
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

    /** L'identifiant de tranche porté par la ligne, s'il est utilisable. */
    private function identifiant(LigneLue $ligne): ?int
    {
        $brut = $ligne->texte(EtatDuPortefeuille::COLONNE_IDENTITE);

        return ctype_digit($brut) && (int) $brut > 0 ? (int) $brut : null;
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
