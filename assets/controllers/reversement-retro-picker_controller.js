import PickerBase from './picker-base_controller.js';
import { SelectionDeFichiers } from './attach-selection.js';

/**
 * @class ReversementRetroPickerController
 * @description Saisie d'un reversement de rétrocommission à un agent interne : une affaire,
 * ou plusieurs cochées d'un coup — auquel cas c'est UN SEUL virement qui est enregistré.
 *
 * Hérite du socle des pickers autonomes (overlay, fermeture ✕/backdrop/Échap, restitution
 * du focus, barre de progression, zone d'erreur inline, événements vers le cerveau) : il ne
 * reste ici que la logique propre au reversement.
 *
 * CE CONTRÔLEUR NE CALCULE AUCUN MONTANT MÉTIER. Les soldes exigibles sont posés par le
 * serveur dans le gabarit ; il ne fait qu'additionner ce que l'utilisateur a coché, pour
 * lui montrer le total avant qu'il valide. La référence de LOT, elle, est générée côté
 * serveur : la laisser au navigateur laisserait deux versements se mélanger.
 */
export default class extends PickerBase {
    static pickerName = 'REVERSEMENT-RETRO-PICKER';

    static targets = ['ligne', 'coche', 'montant', 'date', 'reference', 'compte', 'apercu', 'executer', 'zonePiece'];

    static values = {
        submitUrl: String,
        attacherUrl: String,
        rapportUrl: String,
        limites: Object,
        familles: Object,
    };

    connect() {
        super.connect();
        this.enCours = false;

        // La zone de dépôt est le socle partagé avec la boîte « Attacher des pièces » :
        // mêmes refus, mêmes icônes, même liste. Chaque changement rearme le bouton,
        // car sans pièce il n'y a pas de versement.
        if (this.hasZonePieceTarget) {
            this.selection = new SelectionDeFichiers({
                racine: this.zonePieceTarget,
                limites: this.limitesValue || {},
                familles: this.famillesValue || {},
                onChange: () => this.recalculer(),
            });
        }

        this.recalculer();
    }

    /** La case d'en-tête : coche ou décoche tout, puis recalcule une seule fois. */
    toutCocher(event) {
        const cocher = event.target.checked;
        this.cocheTargets.forEach((c) => { c.checked = cocher; });
        this.recalculer();
    }

    /**
     * Met à jour l'aperçu et l'état du bouton. C'est le seul retour immédiat dont dispose
     * l'utilisateur avant de valider un décaissement : il doit y lire le NOMBRE de lignes
     * et le TOTAL, pas seulement « prêt ».
     */
    recalculer() {
        const lignes = this._lignesCochees();
        const total = lignes.reduce((somme, l) => somme + l.montant, 0);

        // PAS DE VERSEMENT SANS PREUVE : la même règle que le serveur, appliquée ici
        // pour ne pas laisser cliquer sur un bouton qui refusera.
        const sansPiece = this.selection ? this.selection.estVide() : false;

        if (this.hasExecuterTarget) {
            this.executerTarget.disabled = this.enCours || lignes.length === 0 || total <= 0 || sansPiece;
        }

        if (!this.hasApercuTarget) return;

        if (lignes.length === 0) {
            this.apercuTarget.textContent = 'Aucune affaire cochée.';
            return;
        }

        const montant = total.toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        this.apercuTarget.textContent = lignes.length === 1
            ? `1 reversement de ${montant}.`
            : `${lignes.length} lignes, réglées par UN SEUL virement de ${montant} — une seule écriture comptable.`;
    }

    /** Envoie le lot au serveur, qui pose la référence de lot et écrit les lignes. */
    async _onActionClick(event) {
        // Retrait d'un fichier de la liste : c'est le socle qui sait le reconnaître.
        if (this.selection?.onClick(event)) return;

        if (!event.target.closest('[data-picker-executer]')) return;
        if (this.enCours) return;

        const lignes = this._lignesCochees();
        if (lignes.length === 0) {
            this._showError('Cochez au moins une affaire à régler.');
            return;
        }
        if (this.selection?.estVide()) {
            this._showError(
                'Un reversement ne s\'enregistre pas sans justificatif : déposez le bordereau '
                + 'de virement (ou le reçu signé) avant de valider.',
            );
            return;
        }

        this.enCours = true;
        this._showError(null);
        this._progress(true);
        if (this.hasExecuterTarget) this.executerTarget.disabled = true;

        try {
            const response = await fetch(this.submitUrlValue, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    lignes,
                    paidAt: this.hasDateTarget ? this.dateTarget.value : null,
                    reference: this.hasReferenceTarget ? this.referenceTarget.value : null,
                    compteBancaireId: this.hasCompteTarget ? this.compteTarget.value : null,
                    // La pièce ne peut pas partir dans cet envoi : sa cible n'existe pas
                    // encore. On ANNONCE donc qu'elle suit, et le serveur refuse le
                    // versement si elle n'est pas annoncée — la garde reste côté serveur.
                    avecPiece: !this.selection?.estVide(),
                }),
            });
            const result = await response.json();
            if (!response.ok) throw result;

            const pieces = await this._deposerLesPieces(result.porteurId);

            // Le cerveau notifie et rafraîchit la liste : les colonnes « payée » et
            // « solde » de l'agent tombent alors à leur nouvelle valeur.
            this._notifyCerveau('client:retroagent.reversement-enregistre', {
                message: [result.message, pieces].filter(Boolean).join(' '),
                agentNom: null,
                // Ce qu'il faut rafraîchir : le rapport, si c'est de là qu'on vient.
                // Sans lui, le cerveau cherchait une liste inexistante et le rapport
                // gardait ses montants d'avant le versement.
                rapportUrl: this.hasRapportUrlValue ? this.rapportUrlValue : null,
            });
            this.close();
        } catch (error) {
            this.enCours = false;
            this._progress(false);
            this._showError(error?.message || "Le reversement n'a pas pu être enregistré.");
            this.recalculer();
        }
    }

    /**
     * DÉPOSE LA PIÈCE SUR LE PORTEUR DU LOT, une fois les reversements écrits.
     *
     * En DEUX temps, et il ne peut pas en être autrement : la cible n'existe pas quand
     * l'utilisateur choisit son fichier. Le serveur renvoie donc l'identifiant du
     * PORTEUR — le membre du lot qui garde la pièce — et l'on poste sur la route
     * GÉNÉRIQUE d'attachement, celle des fiches : son métrage de jetons, son refus
     * nommé par fichier, sa réponse. Le fichier n'est écrit qu'UNE fois, même si le
     * virement solde dix affaires.
     *
     * ── UN ÉCHEC ICI N'EST PAS UN ÉCHEC DU VERSEMENT ────────────────────────────
     * Les reversements sont déjà enregistrés à ce stade. Faire remonter une exception
     * ferait croire que rien n'a été écrit, et l'utilisateur recommencerait — en
     * doublant le décaissement. On rend donc une phrase à ajouter à la notification,
     * et le volet des versements montrera « 0 pièce », ce qui dit la vérité.
     *
     * @returns {Promise<string>} ce qu'il faut dire des pièces, ou une chaîne vide
     * @private
     */
    async _deposerLesPieces(porteurId) {
        if (!this.selection || this.selection.estVide() || !this.hasAttacherUrlValue) return '';
        if (!porteurId) {
            return 'Le justificatif n\'a pas pu être joint : reprenez-le depuis « Versements enregistrés ».';
        }

        // Le gabarit d'URL porte un 0 à la place de l'identifiant, que le serveur ne
        // pouvait pas connaître au rendu.
        const url = this.attacherUrlValue.replace(/\/0(?=(\?|#|$))/, `/${porteurId}`);

        try {
            const reponse = await fetch(url, {
                method: 'POST',
                body: this.selection.versFormData(),
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            const data = await reponse.json().catch(() => ({}));
            const crees = (data.crees || []).length;
            const refuses = (data.refuses || []).length;

            if (!reponse.ok && crees === 0) {
                return `Le versement est enregistré, mais le justificatif n'a pas pu être joint${data.error ? ' : ' + data.error : ''}.`;
            }
            if (refuses > 0) {
                return `${crees} pièce(s) jointe(s), ${refuses} refusée(s).`;
            }

            return crees > 1 ? `${crees} pièces jointes.` : '1 pièce jointe.';
        } catch (e) {
            return 'Le versement est enregistré, mais le justificatif n\'a pas pu être joint.';
        }
    }

    /**
     * Les lignes cochées, avec leur montant. Une ligne cochée dont le montant a été mis à
     * zéro n'est PAS envoyée : le serveur la refuserait, autant ne pas l'inclure — mais on
     * ne la décoche pas d'autorité, l'utilisateur est peut-être en train de saisir.
     * @private
     */
    _lignesCochees() {
        const lignes = [];
        this.ligneTargets.forEach((ligne, index) => {
            const coche = this.cocheTargets[index];
            if (!coche || !coche.checked) return;

            const montant = parseFloat((this.montantTargets[index]?.value || '0').replace(',', '.'));
            if (!Number.isFinite(montant) || montant <= 0) return;

            lignes.push({
                // L'ÉCHÉANCE est la maille du règlement ; l'affaire l'accompagne pour dire
                // sur quoi il porte. Le serveur vérifie que les deux relèvent de la même
                // proposition avant d'écrire.
                trancheId: parseInt(ligne.dataset.trancheId, 10),
                avenantId: parseInt(ligne.dataset.avenantId, 10),
                montant: Math.round(montant * 100) / 100,
            });
        });

        return lignes;
    }
}
