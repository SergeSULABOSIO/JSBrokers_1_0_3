<?php

namespace App\Echange\Etat;

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
    /**
     * @param string $taxeCourtier nom de la taxe dont le COURTIER est redevable (ARCA…)
     * @param string $taxeAssureur nom de la taxe dont l'ASSUREUR est redevable (TVA…)
     *
     * @return array<string, ColonneEtat>
     */
    public static function pour(string $taxeCourtier, string $taxeAssureur): array
    {
        return [
            // ── Identité ────────────────────────────────────────────────────────────
            'id' => ColonneEtat::identifiant(
                'id',
                'Identifiant de la TRANCHE. C\'est elle, et non la police, qui fait la ligne : '
                . 'une police à quatre échéances occupe quatre lignes.',
            ),

            // ── La police ───────────────────────────────────────────────────────────
            'policeDateEffet' => ColonneEtat::date('Police · Date d\'effet', 'Début de la couverture.'),
            'policeEcheance' => ColonneEtat::date('Police · Échéance', 'Fin de la couverture.'),
            'policeReference' => ColonneEtat::texte('Police · Référence', 'Référence de la police chez l\'assureur.'),
            'policeNumeroAvenant' => ColonneEtat::texte('Police · N° avenant', 'Numéro de l\'avenant.'),
            'policeMoisEffet' => ColonneEtat::texte(
                "Police · Mois d'effet",
                // ⚠ NE PAS Y REMETTRE UN RANG NI UN JOUR : voir `EtatDuPortefeuille::moisDe()`,
                // un libellé lisible comme une date fait ressortir son mois à zéro en synthèse.
                "Mois de la date d'effet de la police (« Janvier », « Février »…). C'est l'axe "
                . "de la feuille SYNTHESE, qui range ses lignes dans l'ordre du calendrier.",
            ),

            // ── La tranche ──────────────────────────────────────────────────────────
            'trancheNom' => ColonneEtat::texte('Tranche · Nom', 'Libellé de l\'échéance de prime.'),
            'tranchePayableAt' => ColonneEtat::date(
                'Tranche · Payable à partir du',
                'Date à partir de laquelle la tranche peut être réglée.',
            ),
            'trancheEcheanceAt' => ColonneEtat::date(
                'Tranche · Échéance de paiement',
                'Date à laquelle la tranche doit être réglée.',
            ),

            // ── L'affaire ───────────────────────────────────────────────────────────
            'assure' => ColonneEtat::texte('Assuré', 'Le client couvert.'),
            'risque' => ColonneEtat::texte('Risque', 'Nature du risque couvert.'),
            'assureur' => ColonneEtat::texte('Assureur', 'La compagnie qui porte le risque.'),

            // ── La prime ────────────────────────────────────────────────────────────
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
            ),
            'intermediairePart' => ColonneEtat::pourcentage(
                'Intermédiaire · Part',
                'Taux de la condition de partage retenue, en POINTS. Vide s\'il n\'y a pas de condition unique : '
                . 'un taux qui ne s\'applique à personne induirait en erreur.',
            ),

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
        ];
    }
}
