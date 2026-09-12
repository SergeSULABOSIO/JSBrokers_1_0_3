<?php

namespace App\Echange\Etat;

use App\Echange\Service\ResolveurDeRenvois;
use App\Entity\Chargement;

/**
 * LES COLONNES DE L'ÉTAT, DÉCLARÉES UNE FOIS.
 *
 * Un seul endroit décide de l'ordre, des libellés, des formats et des explications. Le
 * jour où l'on ajoute une colonne, elle apparaît d'elle-même dans l'en-tête, dans le
 * dictionnaire et dans le format de cellule — sans qu'on ait trois fichiers à retrouver.
 *
 * ⚠ UNE GRANDEUR = UNE COLONNE. Jamais deux notions dans une même case : elle ne se
 * totaliserait plus, ne se trierait plus, ne se comparerait plus d'une ligne à l'autre —
 * elle redeviendrait du commentaire. C'est la règle qui explique pourquoi ce catalogue
 * est long : « prime » y occupe trois colonnes, et non une case « 1 200 / 800 / 400 ».
 *
 * ⚠ LES CLÉS SONT CELLES DES LIGNES produites par EtatDuPortefeuille : le catalogue et
 * l'assembleur se répondent par ces codes, jamais par la position.
 */
final class CatalogueDesColonnes
{
    /** Préfixe du code d'une colonne de chargement. */
    public const PREFIXE_CHARGEMENT = 'chargement_';

    /**
     * LES QUATRE FONCTIONS D'UN CHARGEMENT, telles que l'écran de saisie les nomme.
     *
     * ⚠ LES LIBELLÉS SONT CEUX DE `ChargementType`, mot pour mot. Un fichier qui
     * appellerait « Frais admin » ce que l'écran appelle « Frais accessoires » obligerait
     * l'utilisateur à faire la traduction lui-même, à chaque lecture.
     */
    public const FONCTIONS = [
        Chargement::FONCTION_PRIME_NETTE => 'Prime nette',
        Chargement::FONCTION_FRONTING => 'Fronting',
        Chargement::FONCTION_FRAIS_ADMIN => 'Frais accessoires',
        Chargement::FONCTION_TAXE => 'Taxe',
    ];

    /** Préfixe du code d'une colonne de type de revenu. */
    public const PREFIXE_REVENU = 'revenu_';

    /**
     * @param string   $taxeCourtier nom de la taxe dont le COURTIER est redevable (ARCA…)
     * @param string   $taxeAssureur nom de la taxe dont l'ASSUREUR est redevable (TVA…)
     * @param string[] $revenus      types de revenu du cabinet
     *
     * @return array<string, ColonneEtat>
     */
    public static function pour(
        string $taxeCourtier,
        string $taxeAssureur,
        array $revenus = [],
    ): array {
        $catalogue = [
            // ── Identité ────────────────────────────────────────────────────────────
            // ⚠ UNE SUPPRESSION NE SE DÉDUIT JAMAIS, ELLE S'ÉCRIT. Un identifiant effacé
            // par mégarde en triant le fichier ne doit pas pouvoir vider une échéance.
            // Même règle et mêmes mots que le classeur normalisé (CanevasDEchange::ACTION_*).
            '_action' => ColonneEtat::texte(
                '_action',
                'Laissez vide : la ligne est créée si « id » est vide, mise à jour sinon. '
                . 'Écrivez SUPPRIMER pour demander la suppression de cette tranche — cela ne '
                . 'se déduit jamais, il faut l\'écrire.',
            )->enSaisie('Tranche._action'),

            'id' => ColonneEtat::identifiant(
                'id',
                'Identifiant de la TRANCHE. C\'est elle, et non la police, qui fait la ligne : '
                . 'une police à quatre échéances occupe quatre lignes.',
            )->enSaisie('Tranche.id'),

            // ── La police ───────────────────────────────────────────────────────────
            'policeDateEffet' => ColonneEtat::date('Police · Date d\'effet', 'Début de la couverture.')->enSaisie('Avenant.startingAt'),
            'policeEcheance' => ColonneEtat::date('Police · Échéance', 'Fin de la couverture.')->enSaisie('Avenant.endingAt'),
            'policeReference' => ColonneEtat::texte('Police · Référence', 'Référence de la police chez l\'assureur.')->enSaisie('Avenant.referencePolice'),
            'policeNumeroAvenant' => ColonneEtat::texte('Police · N° avenant', 'Numéro de l\'avenant.')->enSaisie('Avenant.numero'),
            'policeMoisEffet' => ColonneEtat::texte(
                "Police · Mois d'effet",
                // ⚠ NE PAS Y REMETTRE UN RANG NI UN JOUR : voir `EtatDuPortefeuille::moisDe()`,
                // un libellé lisible comme une date fait ressortir son mois à zéro en synthèse.
                "Mois de la date d'effet de la police (« Janvier », « Février »…). C'est l'axe "
                . "de la feuille SYNTHESE, qui range ses lignes dans l'ordre du calendrier.",
            ),

            // ── La tranche ──────────────────────────────────────────────────────────
            'trancheNom' => ColonneEtat::texte('Tranche · Nom', 'Libellé de l\'échéance de prime.')->enSaisie('Tranche.nom'),
            'tranchePayableAt' => ColonneEtat::date(
                'Tranche · Payable à partir du',
                'Date à partir de laquelle la tranche peut être réglée.',
            )->enSaisie('Tranche.payableAt'),
            'trancheEcheanceAt' => ColonneEtat::date(
                'Tranche · Échéance de paiement',
                'Date à laquelle la tranche doit être réglée.',
            )->enSaisie('Tranche.echeanceAt'),

            // ── L'affaire ───────────────────────────────────────────────────────────
// ⚠ DEUX MANIÈRES DE DIRE LE POIDS D'UNE ÉCHÉANCE, ET UNE PRIORITÉ DÉJÀ ÉCRITE.
            // Une tranche pèse une PART de la prime, ou un MONTANT. La règle n'est pas à
            // inventer ici : `IndicatorCalculationHelper::getTrancheTauxFactor()` la porte —
            // la part l'emporte, le montant ne servant que si elle est absente. Mesuré sur
            // les données réelles, 71 tranches sur 80 renseignent LES DEUX : refuser ces
            // lignes aurait rejeté presque tout un portefeuille.
            'tranchePart' => ColonneEtat::pourcentage(
                'Tranche · Part (%)',
                'Part de la prime que porte cette échéance, EN POINTS (25 = 25 %) — convention '
                . 'de toute l\'application. Quatre échéances égales font quatre fois 25. '
                . '⚠ Renseignée, elle L\'EMPORTE sur le montant fixe.',
            )->enSaisie('Tranche.pourcentage'),
            'trancheMontantFlat' => ColonneEtat::montant(
                'Tranche · Montant fixe',
                'Montant de prime porté par cette échéance, pour qui préfère le dire en monnaie '
                . 'plutôt qu\'en part. ⚠ Il n\'est lu QUE si la part est vide : renseigner les '
                . 'deux n\'est pas une faute, mais le montant sera alors ignoré.',
            )->enSaisie('Tranche.montantFlat'),

            'assure' => ColonneEtat::texte('Assuré', 'Le client couvert.')->enSaisie('Client.nom'),
            'risque' => ColonneEtat::texte('Risque', 'Nature du risque couvert.')->enSaisie('Risque.nomComplet'),
            'assureur' => ColonneEtat::texte('Assureur', 'La compagnie qui porte le risque.')->enSaisie('Assureur.nom'),

            // ── La prime ────────────────────────────────────────────────────────────
            'portefeuille' => ColonneEtat::texte(
                'Portefeuille',
                'Portefeuille auquel appartient le client. ⚠ Il se pose sur le CLIENT et non '
                . 'sur la police : le changer déplace tout ce que ce client porte. Facultatif : '
                . 'laissée vide, elle reprend quand même le client — vous le rangerez ensuite '
                . 'depuis la rubrique « Clients ».',
            )->enSaisie('Client.portefeuille'),

            // ── La prime ────────────────────────────────────────────────────────────
            // ⚠ LA COMPOSITION A UNE COLONNE PAR CHARGEMENT, et elle est posée plus bas
            // par `colonnesDeChargement()` : ces colonnes dépendent du CATALOGUE DU
            // CABINET, qu'un tableau statique ne peut pas connaître.
            //
            // Elle tenait auparavant dans une seule cellule texte — « Prime nette = 59225 ;
            // Fronting = 8883.75 ». C'était une entorse à la règle qui ouvre ce fichier :
            // une case qui empile des montants ne se totalise plus, ne se trie plus, ne se
            // compare plus d'une ligne à l'autre. Elle redevenait du commentaire.
            'primeTotale' => ColonneEtat::montant('Prime · Totale', 'Prime due par le client sur cette tranche.'),
            'primePayee' => ColonneEtat::montant(
                'Prime · Payée',
                'Ce que le client a réglé, tel que déclaré. Déclaratif : ce n\'est pas la trésorerie du cabinet.',
            ),
            'primeSolde' => ColonneEtat::montant('Prime · Solde', 'Ce que le client doit encore.'),
            'primeDerniereLe' => ColonneEtat::date(
                'Prime · Dernier règlement le',
                'Date du DERNIER règlement reçu. Une tranche réglée en plusieurs fois n\'affiche que le plus récent.',
            ),
            'primeReferences' => ColonneEtat::texte(
                'Prime · Références des règlements',
                'TOUTES les références de règlement, séparées par « ; ».',
            ),

            // ── La commission ───────────────────────────────────────────────────────
            // ⚠ CETTE COLONNE DIT LE TAUX RÉEL, ET C'EST NOUVEAU. Elle n'écrivait que les
            // DÉROGATIONS : un revenu au taux du risque en sortait nu, et le courtier
            // exportait son portefeuille sans y lire aucun taux. Une colonne qui tait ce
            // qu'elle sait n'aide personne.
            //
            // ⚠ MAIS UN TAUX HÉRITÉ NE SE RECOPIE PAS. Réimporté tel quel, il deviendrait
            // une dérogation et cesserait de suivre sa source le jour où elle change. D'où
            // les marqueurs « (du risque) » et « (du type) » : la valeur est ÉCRITE pour
            // être lue, et MARQUÉE pour n'être pas reprise
            // (voir `App\Echange\Reprise\ValeursMultiples`).
            'commissionRevenus' => ColonneEtat::texte(
                'Commission · Revenus',
                'Ce que rapporte l\'affaire, revenu par revenu : « Commission = 17,5 ; Frais de '
                . 'gestion = 5 ». ⚠ LA VALEUR EST UN TAUX, EN POINTS — 17,5 vaut 17,5 %, et le '
                . 'signe pourcent est facultatif. Ce taux l\'emporte sur celui de vos réglages. '
                . 'Un nom sans valeur (« Commission ») laisse jouer le taux configuré. Un nom '
                . 'que votre cabinet ne connaît pas est conservé tel quel et rattaché à '
                . '« Commission Ordinaire ». ⚠ Laissée VIDE, la case vaut « Commission '
                . 'Ordinaire » : une proposition sans revenu compterait zéro partout. '
                . 'Écrivez « = 5000 (forfait) » pour un montant fixe ; les valeurs marquées '
                . '« (du risque) » ou « (du type) » sont là pour information — les redéposer '
                . 'ne fige rien.',
            )->enSaisie('Cotation.revenus'),

            'commissionTtc' => ColonneEtat::montant(
                'Commission · TTC',
                'Commission HT + taxe de l\'ASSUREUR SEULE. La taxe du courtier n\'y est PAS comprise.',
            ),
            'commissionHt' => ColonneEtat::montant(
                'Commission · HT',
                'Commission de courtage hors taxes. C\'est l\'assiette des deux taxes.',
            ),
            'commissionEncaissee' => ColonneEtat::montant(
                'Commission · Encaissée',
                'Ce que le cabinet a réellement perçu de l\'assureur sur cette tranche.',
            ),
            'commissionSolde' => ColonneEtat::montant('Commission · Solde', 'Ce que le cabinet attend encore.'),
            'commissionExigible' => ColonneEtat::montant(
                'Commission · Exigible',
                'Solde réclamable à l\'assureur. Vaut 0 tant que la prime n\'est pas INTÉGRALEMENT payée : '
                . 'une commission ne se proratise jamais sur un règlement partiel de prime.',
            ),
            'commissionDerniereLe' => ColonneEtat::date(
                'Commission · Dernier encaissement le',
                'Date du DERNIER encaissement.',
            ),
            'commissionReferences' => ColonneEtat::texte(
                'Commission · Références de facture',
                'Références des notes réglées, séparées par « ; ».',
            ),
            'commissionComptes' => ColonneEtat::texte(
                'Commission · Compte(s) bancaire(s)',
                'Comptes crédités par ces encaissements.',
            ),
            'commissionBordereaux' => ColonneEtat::texte(
                'Commission · Bordereau(x)',
                "Références des bordereaux de production qui ont fait rentrer de l'argent sur "
                . "cette tranche. Une commission s'encaisse par facture d'articles OU par "
                . "bordereau : c'est ici qu'on retrouve le second circuit.",
            ),

            // ── Les taxes SUR LA COMMISSION ─────────────────────────────────────────
            'taxeCourtierTaux' => ColonneEtat::pourcentage(
                sprintf('Taxe courtier · %s · Taux', $taxeCourtier),
                'Taux en POINTS (16 = 16 %), lu sur le paramétrage du cabinet — jamais déduit d\'une division.',
            ),
            'taxeCourtierMontant' => ColonneEtat::montant(
                sprintf('Taxe courtier · %s · Montant', $taxeCourtier),
                'Taxe due par le COURTIER sur sa commission. Assiette : la commission HT, jamais la prime.',
            ),
            'taxeCourtierPayee' => ColonneEtat::montant(
                sprintf('Taxe courtier · %s · Payée', $taxeCourtier),
                'Part déjà réglée à l\'autorité fiscale.',
            ),
            'taxeCourtierSolde' => ColonneEtat::montant(
                sprintf('Taxe courtier · %s · Solde', $taxeCourtier),
                'Reste dû à l\'autorité fiscale.',
            ),
            'taxeCourtierPayeeLe' => ColonneEtat::date(
                sprintf('Taxe courtier · %s · Payée le', $taxeCourtier),
                'Date du DERNIER règlement de cette taxe.',
            ),
            'taxeCourtierReferences' => ColonneEtat::texte(
                sprintf('Taxe courtier · %s · Références', $taxeCourtier),
                'Références des règlements, séparées par « ; ».',
            ),
            'taxeCourtierExigible' => ColonneEtat::montant(
                sprintf('Taxe courtier · %s · Exigible', $taxeCourtier),
                'Part devenue réclamable, au prorata de la commission ENCAISSÉE, moins ce qui est déjà payé. '
                . 'La taxe est due sur un revenu perçu : elle naît avec l\'encaissement et croît avec lui.',
            ),

            'taxeAssureurTaux' => ColonneEtat::pourcentage(
                sprintf('Taxe assureur · %s · Taux', $taxeAssureur),
                'Taux en POINTS (16 = 16 %), lu sur le paramétrage du cabinet.',
            ),
            'taxeAssureurMontant' => ColonneEtat::montant(
                sprintf('Taxe assureur · %s · Montant', $taxeAssureur),
                'Taxe due par l\'ASSUREUR, collectée puis reversée par le courtier. Assiette : la commission HT.',
            ),
            'taxeAssureurPayee' => ColonneEtat::montant(
                sprintf('Taxe assureur · %s · Payée', $taxeAssureur),
                'Part déjà reversée.',
            ),
            'taxeAssureurSolde' => ColonneEtat::montant(
                sprintf('Taxe assureur · %s · Solde', $taxeAssureur),
                'Reste à reverser.',
            ),
            'taxeAssureurPayeeLe' => ColonneEtat::date(
                sprintf('Taxe assureur · %s · Payée le', $taxeAssureur),
                'Date du DERNIER règlement de cette taxe.',
            ),
            'taxeAssureurReferences' => ColonneEtat::texte(
                sprintf('Taxe assureur · %s · Références', $taxeAssureur),
                'Références des règlements, séparées par « ; ».',
            ),
            'taxeAssureurExigible' => ColonneEtat::montant(
                sprintf('Taxe assureur · %s · Exigible', $taxeAssureur),
                'Part devenue réclamable, au prorata de la commission encaissée, moins ce qui est déjà payé.',
            ),

            // ── Ce qui reste au cabinet ─────────────────────────────────────────────
            'commissionPure' => ColonneEtat::montant(
                'Commission pure',
                'Commission HT moins la taxe dont le courtier est redevable. C\'est l\'assiette du partage.',
            ),
            'reserve' => ColonneEtat::montant(
                'Réserve du courtier',
                'Commission pure moins les rétrocommissions des partenaires ET des agents. '
                . 'Peut être négative : un cumul de taux mal paramétré fait reverser plus qu\'il ne reste.',
            ),

            // ── Les intermédiaires ──────────────────────────────────────────────────
            'intermediaire' => ColonneEtat::texte(
                'Intermédiaire · Nom',
                'Le partenaire EXTERNE apporteur de l\'affaire, s\'il y en a un.',
            )->enSaisie('Partenaire.nom'),
            'intermediairePart' => ColonneEtat::pourcentage(
                'Intermédiaire · Part',
                'Taux de la condition de partage retenue, en POINTS. Vide s\'il n\'y a pas de condition unique : '
                . 'un taux qui ne s\'applique à personne induirait en erreur.',
            )->enSaisie('ConditionPartage.taux'),

            'retroPartenaireDue' => ColonneEtat::montant(
                'Rétro intermédiaire · Due',
                'Ce que le cabinet doit au partenaire externe sur cette tranche.',
            ),
            'retroPartenairePayee' => ColonneEtat::montant('Rétro intermédiaire · Payée', 'Ce qui lui a été versé.'),
            'retroPartenaireSolde' => ColonneEtat::montant('Rétro intermédiaire · Solde', 'Ce qui lui reste dû.'),
            'retroPartenaireExigible' => ColonneEtat::montant(
                'Rétro intermédiaire · Exigible',
                'Part réclamable : la dette suit l\'encaissement de la commission qui la porte.',
            ),
            'retroPartenairePayeeLe' => ColonneEtat::date(
                'Rétro intermédiaire · Payée le',
                'Date du DERNIER versement au partenaire.',
            ),
            'retroPartenaireReferences' => ColonneEtat::texte(
                'Rétro intermédiaire · Références',
                'Références des virements au partenaire, séparées par « ; ». À défaut de '
                . 'référence propre, celle du lot de versement.',
            ),
            'retroPartenaireLots' => ColonneEtat::texte(
                'Rétro intermédiaire · Lot de versement',
                "Référence de l'ORDRE DE PAIEMENT qui regroupe ces virements — à ne pas confondre avec la référence d'un virement isolé.",
            ),
            'retroPartenaireComptes' => ColonneEtat::texte(
                'Rétro intermédiaire · Compte(s) bancaire(s)',
                'Comptes débités par ces versements.',
            ),

            'retroAgentBeneficiaire' => ColonneEtat::texte(
                'Rétro agent · Bénéficiaire',
                'L\'agent INTERNE à qui la rétrocommission est due. Lu sur la condition de '
                . 'partage qui lui donne droit, jamais sur les versements : un agent a droit '
                . 'dès la souscription, bien avant le premier virement.',
            ),
            'retroAgentDue' => ColonneEtat::montant(
                'Rétro agent · Due',
                'Ce que le cabinet doit à ses agents INTERNES sur cette tranche.',
            ),
            'retroAgentPayee' => ColonneEtat::montant('Rétro agent · Payée', 'Ce qui leur a été versé.'),
            'retroAgentSolde' => ColonneEtat::montant('Rétro agent · Solde', 'Ce qui leur reste dû.'),
            'retroAgentExigible' => ColonneEtat::montant(
                'Rétro agent · Exigible',
                'Part réclamable, au prorata de la commission encaissée.',
            ),
            'retroAgentPayeeLe' => ColonneEtat::date(
                'Rétro agent · Payée le',
                'Date du DERNIER versement à un agent. ⚠ Les deux familles vivent sur le même '
                . 'enregistrement : cette date ne compte QUE les versements aux agents.',
            ),
            'retroAgentReferences' => ColonneEtat::texte(
                'Rétro agent · Références',
                "Références des virements à l'agent, séparées par « ; ». Comme la date, elles "
                . 'ne comptent QUE les versements aux agents.',
            ),
            'retroAgentLots' => ColonneEtat::texte(
                'Rétro agent · Lot de versement',
                "Référence de l'ORDRE DE PAIEMENT qui regroupe ces virements.",
            ),
            'retroAgentComptes' => ColonneEtat::texte(
                'Rétro agent · Compte(s) bancaire(s)',
                'Comptes débités par ces versements.',
            ),
            // ── Les soldes d'OUVERTURE ──────────────────────────────────────────────
            // ⚠ CES SIX COLONNES NE SONT LUES QU'À LA CRÉATION D'UNE ÉCHÉANCE, et c'est
            // capital. Elles portent une SITUATION DE DÉPART : ce qui était déjà encaissé
            // le jour de la reprise. Les relire sur une échéance qui existe déjà
            // ajouterait un second règlement à chaque dépôt du même fichier — les
            // encaissements doubleraient à chaque aller-retour, sans que rien ne le dise.
            //
            // ⚠ ET ELLES NE REJOUENT PAS L'HISTORIQUE. Un solde de 8 000 devient UNE
            // écriture de 8 000, non les trois versements qui l'ont composé : une ligne
            // plate ne peut pas porter un journal. C'est la sémantique assumée d'une
            // reprise — on repart d'une situation juste, pas d'une comptabilité rejouée.
            'ouverturePrimeEncaissee' => ColonneEtat::montant(
                'Ouverture · Prime encaissée',
                'Prime DÉJÀ réglée par le client au jour de la reprise. Devient un paiement de '
                . 'prime unique, à la date ci-contre. ⚠ Lu SEULEMENT si la ligne crée une '
                . 'échéance : sur une échéance existante, il serait ajouté une seconde fois.',
            )->enSaisie('PaiementPrime.montant'),
            'ouverturePrimeLe' => ColonneEtat::date(
                'Ouverture · Prime encaissée le',
                'Date de l\'écriture d\'ouverture de la prime. À défaut, la date d\'effet de la police.',
            )->enSaisie('PaiementPrime.paidAt'),

            'ouvertureCommissionEncaissee' => ColonneEtat::montant(
                'Ouverture · Commission encaissée',
                'Commission DÉJÀ encaissée du cabinet au jour de la reprise. Devient une note de '
                . 'commission soldée par un règlement du même montant. Le revenu à facturer est '
                . 'celui de « Commission · Revenus » ; à défaut, celui que la proposition porte '
                . 'déjà, ou « Commission Ordinaire ».',
            )->enSaisie('Paiement.montant'),
            'ouvertureCommissionLe' => ColonneEtat::date(
                'Ouverture · Commission encaissée le',
                'Date de l\'écriture d\'ouverture de la commission.',
            )->enSaisie('Paiement.paidAt'),

            'ouvertureRetroReversee' => ColonneEtat::montant(
                'Ouverture · Rétro reversée',
                'Rétrocommission DÉJÀ versée à l\'intermédiaire au jour de la reprise. ⚠ Exige '
                . 'qu\'un intermédiaire soit renseigné : un reversement sans bénéficiaire n\'a pas '
                . 'de sens.',
            )->enSaisie('ReversementRetroAgent.montant'),
            'ouvertureRetroLe' => ColonneEtat::date(
                'Ouverture · Rétro reversée le',
                'Date de l\'écriture d\'ouverture du reversement.',
            )->enSaisie('ReversementRetroAgent.paidAt'),

        ];

        // ⚠ CHAQUE COLONNE DANS SON GROUPE, ET NON À LA FIN DU FICHIER. Ajoutées par une
        // simple union, les colonnes dynamiques atterrissaient en queue — à quatre-vingts
        // colonnes de « Prime · Totale », qu'elles décomposent pourtant. On les aurait
        // cherchées à l'autre bout de la feuille, et l'alternance des familles en tête
        // d'export aurait annoncé « Prime » deux fois, séparées par tout le reste.
        //
        // Elles précèdent le total qu'elles composent : on lit les termes, puis la somme.
        $catalogue = self::inserer($catalogue, 'primeTotale', self::colonnesDeChargement());

        return self::inserer($catalogue, 'commissionTtc', self::colonnesDeRevenu($revenus));
    }

    /**
     * Insère des colonnes JUSTE AVANT une autre, en gardant l'ordre du catalogue.
     *
     * Si le repère n'existe pas — l'utilisateur a restreint son export —, les colonnes
     * rejoignent la fin plutôt que de disparaître : mieux vaut une colonne mal placée
     * qu'une colonne absente.
     *
     * @param array<string, ColonneEtat> $catalogue
     * @param array<string, ColonneEtat> $ajouts
     *
     * @return array<string, ColonneEtat>
     */
    private static function inserer(array $catalogue, string $avant, array $ajouts): array
    {
        if ($ajouts === []) {
            return $catalogue;
        }

        if (!isset($catalogue[$avant])) {
            return $catalogue + $ajouts;
        }

        $sortie = [];
        foreach ($catalogue as $code => $colonne) {
            if ($code === $avant) {
                foreach ($ajouts as $codeAjout => $ajout) {
                    $sortie[$codeAjout] = $ajout;
                }
            }
            $sortie[$code] = $colonne;
        }

        return $sortie;
    }

    /**
     * LES QUATRE COLONNES DE LA PRIME — une par FONCTION de chargement.
     *
     * ⚠ PAR FONCTION, ET NON PAR NOM DE TYPE. C'est une correction : le catalogue d'un
     * cabinet porte des noms libres — « Sneca », « Tva pour prime », « Frais Arca », « ARCA »
     * —, mais chacun retombe dans l'une des QUATRE fonctions que `ChargementType` propose,
     * et qui sont les seules qui aient un sens métier. Une colonne par nom en donnait huit
     * sur un cabinet et cinq sur un autre : deux exports incomparables, pour une même
     * réalité comptable.
     *
     * ⚠ ET LEURS CODES NE DÉPENDENT PLUS DU CABINET. Un fichier exporté ici se relit là,
     * et les quatre colonnes existent même sur un cabinet qui n'a encore rien saisi — ce
     * qu'un gabarit vierge exige.
     *
     * ⚠ CES MONTANTS SONT AU PRORATA DE L'ÉCHÉANCE, jamais ceux de la police. Les
     * chargements vivent sur la cotation ; la ligne, elle, est une TRANCHE. Y écrire le
     * montant de la police ferait qu'une police à quatre échéances répète quatre fois les
     * mêmes montants — et la ligne de totaux compterait chaque chargement quatre fois. Le
     * chiffre resterait plausible, et faux.
     *
     * Au prorata, la somme des quatre colonnes égale « Prime · Totale » de la MÊME ligne.
     *
     * @return array<string, ColonneEtat>
     */
    private static function colonnesDeChargement(): array
    {
        $colonnes = [];

        foreach (self::FONCTIONS as $fonction => $libelle) {
            $colonnes[self::codeDeFonction($fonction)] = ColonneEtat::montant(
                'Prime · ' . $libelle,
                sprintf(
                    'Part de « %s » revenant à CETTE échéance, tous vos types de ce genre '
                    . 'confondus. La somme des quatre colonnes de prime fait « Prime · Totale ». '
                    . 'Corrigez-la pour reprendre une prime : c\'est d\'elles qu\'elle SORT.',
                    $libelle,
                ),
            )->enSaisie('Cotation.chargements');
        }

        return $colonnes;
    }

    /**
     * UNE COLONNE PAR TYPE DE REVENU — ce que chacun rapporte sur cette échéance.
     *
     * ⚠ CE SONT DES RÉSULTATS, et la dissymétrie avec les chargements est voulue. Un
     * revenu n'a pas de montant écrit : il se calcule d'un taux, lui-même le plus souvent
     * hérité du risque de l'affaire. Un montant produit ne permet pas de retrouver ce
     * taux — c'est « Commission · Revenus » qui dit quels types s'appliquent, et elle
     * reste la colonne de saisie.
     *
     * Au prorata de l'échéance, comme les chargements : leur somme égale
     * « Commission · Ttc » de la même ligne.
     *
     * @param string[] $types
     *
     * @return array<string, ColonneEtat>
     */
    private static function colonnesDeRevenu(array $types): array
    {
        $colonnes = [];

        foreach ($types as $nom) {
            $code = self::codeDynamique(self::PREFIXE_REVENU, $nom);
            if ($code === null || isset($colonnes[$code])) {
                continue;
            }

            $colonnes[$code] = ColonneEtat::montant(
                'Commission · ' . $nom,
                sprintf(
                    'Commission TTC que « %s » rapporte sur cette échéance. Calculé : la somme '
                    . 'des colonnes de revenu fait « Commission · Ttc ». Pour qu\'un type '
                    . 's\'applique, nommez-le dans « Commission · Revenus ».',
                    $nom,
                ),
            );
        }

        return $colonnes;
    }

    /**
     * LE CODE D'UNE COLONNE DYNAMIQUE, stable et sans collision.
     *
     * ⚠ LA FORME COMPARABLE EST EMPRUNTÉE, JAMAIS RÉÉCRITE. `ResolveurDeRenvois` en est la
     * source unique dans tout le projet ; un second découpage — un accent, un espace de
     * plus — ferait deux colonnes là où l'utilisateur n'en voit qu'une.
     *
     * ⚠ ET C'EST ELLE QUI DÉDUPLIQUE. Le catalogue réel porte « Prime nette » SIX fois,
     * séquelle d'une initialisation rejouée : sans cette normalisation, six colonnes
     * identiques côte à côte.
     */
    /**
     * LE CODE DE LA COLONNE D'UNE FONCTION DE CHARGEMENT — stable partout.
     *
     * ⚠ IL NE DÉRIVE PAS DU NOM DU TYPE, mais de la fonction elle-même : c'est ce qui rend
     * un fichier lisible d'un cabinet à l'autre, et qui permet aux quatre colonnes
     * d'exister sur un cabinet qui n'a encore rien saisi.
     */
    public static function codeDeFonction(int $fonction): string
    {
        return self::PREFIXE_CHARGEMENT . str_replace(
            ' ',
            '_',
            ResolveurDeRenvois::normaliser(self::FONCTIONS[$fonction] ?? (string) $fonction),
        );
    }

    public static function codeDynamique(string $prefixe, ?string $nom): ?string
    {
        $forme = ResolveurDeRenvois::normaliser((string) $nom);

        return $forme === '' ? null : $prefixe . str_replace(' ', '_', $forme);
    }
}
