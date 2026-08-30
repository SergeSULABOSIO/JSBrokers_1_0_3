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
        limites: Object,
        familles: Object,
        // UN VIREMENT ROUVERT A DÉJÀ SA PREUVE. Sans ce drapeau, la garde de
        // justificatif — juste à la création — aurait laissé le bouton inerte tant
        // qu'on n'aurait pas redéposé le même bordereau, et corriger une date serait
        // devenu impossible.
        pieceDeja: Boolean,
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

    /**
     * TOUTE LA LIGNE COCHE.
     *
     * Une case à cocher fait seize pixels de côté ; la ligne qui la porte en fait
     * soixante, et c'est elle que l'œil désigne. Viser la case était un geste de
     * précision sans contrepartie — d'autant que la ligne entière est déjà l'unité de
     * décision (une échéance, un montant).
     *
     * ON NE TOUCHE À RIEN DE CE QUI EST DÉJÀ INTERACTIF : le champ du montant, la case
     * elle-même, un libellé ou un bouton gardent leur comportement propre. Sans cette
     * garde, cliquer dans le champ pour corriger un montant décochait la ligne qu'on
     * était précisément en train de régler.
     */
    basculerLigne(event) {
        if (event.target.closest('input, select, textarea, label, button, a')) return;

        const ligne = event.currentTarget;
        const coche = ligne.querySelector('input[type="checkbox"]');
        if (!coche) return;

        coche.checked = !coche.checked;
        this.recalculer();
    }

    /**
     * RETIRER — ou RÉTABLIR — UNE PIÈCE DÉJÀ DÉPOSÉE.
     *
     * Le retrait est DIFFÉRÉ : la pièce est marquée, et c'est l'enregistrement qui
     * tranche. Détruire au clic aurait effacé un bordereau même si l'utilisateur
     * annulait ensuite la correction — sur la preuve d'un décaissement, ce n'est pas
     * une nuance.
     */
    basculerPiece(event) {
        event.preventDefault();
        const ligne = event.currentTarget.closest('[data-piece-id]');
        if (!ligne) return;

        const retiree = ligne.classList.toggle('is-refuse');
        const libelle = ligne.querySelector('[data-libelle]');
        if (libelle) libelle.textContent = retiree ? 'Rétablir' : 'Retirer';

        // LE LECTEUR D'ÉCRAN ENTEND LE MÊME CHANGEMENT QUE L'ŒIL. Un `aria-label` figé
        // sur « Retirer » aurait annoncé l'inverse de ce que le bouton fait désormais.
        const nom = ligne.querySelector('[data-libelle]')?.closest('button');
        const fichier = ligne.querySelector('.jsb-pieces-deja__nom')?.textContent?.trim() ?? '';
        if (nom) {
            nom.setAttribute(
                'aria-label',
                retiree ? `Rétablir ${fichier} sur ce virement` : `Retirer ${fichier} de ce virement`,
            );
        }

        this.recalculer();
    }

    /**
     * Les pièces marquées pour le retrait — ce que l'enregistrement supprimera.
     *
     * @returns {number[]}
     * @private
     */
    _piecesRetirees() {
        return Array.from(this.element.querySelectorAll('[data-piece-id].is-refuse'))
            .map((li) => parseInt(li.dataset.pieceId, 10))
            .filter((id) => !Number.isNaN(id));
    }

    /**
     * Reste-t-il une preuve à ce virement ? Les pièces déjà là, MOINS celles qu'on
     * vient de marquer. Retirer la dernière sans en déposer une autre doit refermer
     * la garde — sinon on enregistrerait un décaissement nu.
     *
     * @private
     */
    _preuveRestante() {
        const total = this.element.querySelectorAll('[data-piece-id]').length;

        return total > 0 && this._piecesRetirees().length < total;
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
        // pour ne pas laisser cliquer sur un bouton qui refusera. Un virement rouvert,
        // lui, l'a déjà — le serveur fait la même lecture.
        const sansPiece = this._preuveRestante() ? false : (this.selection ? this.selection.estVide() : false);

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
        if (!this._preuveRestante() && this.selection?.estVide()) {
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
                    // CE QUI DOIT DISPARAÎTRE. Le serveur supprime ces pièces AVANT de
                    // vérifier qu'il en reste une : retirer la dernière sans en déposer
                    // une autre doit être refusé, pas passé sous silence.
                    piecesRetirees: this._piecesRetirees(),
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
