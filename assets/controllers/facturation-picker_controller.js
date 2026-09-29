import PickerBase from './picker-base_controller.js';

/**
 * @class FacturationPickerController
 * @description Émission d'une note de débit réclamant une ou plusieurs commissions
 * devenues exigibles — puis ouverture du PDF que le courtier enverra à l'assureur.
 *
 * Hérite du socle des pickers autonomes (overlay, fermeture ✕/backdrop/Échap, restitution
 * du focus, barre de progression, zone d'erreur inline, événements vers le cerveau) : il ne
 * reste ici que la logique propre à la facturation.
 *
 * ── CE CONTRÔLEUR NE CALCULE AUCUN MONTANT ──────────────────────────────────────
 * Ce qu'il reste à facturer est posé par le serveur dans le gabarit (`data-montant`), par
 * la MÊME règle que l'assistant applique. Il ne fait qu'additionner ce que l'utilisateur a
 * coché, pour le lui montrer avant qu'il valide.
 *
 * ── ET IL NE DÉCIDE PAS DU DESTINATAIRE ─────────────────────────────────────────
 * Changer d'assureur pour client ne change pas un libellé : cela change les lignes, le
 * destinataire et l'objet. La fenêtre se RECHARGE donc côté serveur plutôt que de porter
 * une seconde fois la règle métier.
 *
 * ── LE GESTE N'EST PAS FINI À L'ENREGISTREMENT ──────────────────────────────────
 * Une note émise qu'on ne peut pas ouvrir oblige à la retrouver dans sa rubrique pour
 * l'envoyer. Le picker reste donc ouvert au succès, et son pied bascule : « Ouvrir la note
 * (PDF) », et « Facturer les suivantes » quand la sélection portait plusieurs assureurs.
 */
export default class extends PickerBase {
    static pickerName = 'FACTURATION-PICKER';

    static targets = [
        'ligne', 'coche', 'apercu', 'executer',
        'destinataire', 'objet', 'description', 'compte', 'signataire', 'titreSignataire',
        'footSucces', 'messageSucces', 'suivant',
    ];

    static values = {
        submitUrl: String,
        apercuUrl: String,
        destinataire: String,
        ids: String,
        suivants: Array,
    };

    connect() {
        super.connect();
        this.enCours = false;
        this.noteId = null;
        this.recalculer();
    }

    /**
     * TOUTE LA LIGNE COCHE. Une case fait seize pixels de côté, la ligne qui la porte en
     * fait soixante — et c'est elle que l'œil désigne. On laisse leur comportement propre
     * à ce qui est déjà interactif : sans cette garde, cliquer dans un champ décocherait
     * la ligne qu'on est en train de régler.
     */
    basculerLigne(event) {
        if (event.target.closest('input, select, textarea, label, button, a')) return;

        const coche = event.currentTarget.querySelector('input[type="checkbox"]');
        if (!coche) return;
        coche.checked = !coche.checked;
        this.recalculer();
    }

    toutCocher(event) {
        const coche = event.target.checked;
        this.cocheTargets.forEach((c) => { c.checked = coche; });
        this.recalculer();
    }

    /** Le total de ce qui est coché, et l'armement du bouton. */
    recalculer() {
        const lignes = this._lignesCochees();
        const total = lignes.reduce((somme, l) => somme + l.montant, 0);

        if (this.hasExecuterTarget) {
            this.executerTarget.disabled = this.enCours || lignes.length === 0 || total <= 0;
        }
        if (!this.hasApercuTarget) return;

        if (lignes.length === 0) {
            this.apercuTarget.textContent = 'Aucune commission cochée.';
            return;
        }

        const montant = total.toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        this.apercuTarget.textContent = lignes.length === 1
            ? `1 commission de ${montant}, sur une note de débit.`
            : `${lignes.length} commissions, réunies sur UNE SEULE note de débit de ${montant}.`;
    }

    /**
     * Le destinataire a changé : on redemande la fenêtre au serveur, avec les mêmes
     * échéances. C'est lui qui sait quelles commissions sont dues à un client plutôt
     * qu'à un assureur — la question n'a pas de réponse dans le navigateur.
     */
    changerDestinataire() {
        const choisi = this.hasDestinataireTarget ? this.destinataireTarget.value : 'assureur';
        this._notifyCerveau('ui:tranche.facturer-commission', {
            url: '/admin/note/facturation-picker?destinataire=' + encodeURIComponent(choisi),
            selection: (this.idsValue || '').split(',').filter(Boolean).map((id) => ({ id })),
        });
        this.close();
    }

    /** Ouvre la note qui bloque une échéance — pour la corriger, ou l'annuler. */
    ouvrirLaNote(event) {
        event.preventDefault();
        const id = event.currentTarget?.dataset?.facturationPickerNoteId;
        if (!id) return;

        this._notifyCerveau('ui:note.preview-request', { url: this._urlApercu(id) });
    }

    /** Ouvre le PDF de la note qu'on vient d'émettre, dans un onglet. */
    ouvrirLePdf() {
        if (!this.noteId) return;

        // Le cerveau connaît déjà cette branche : sur une URL « download », il ouvre le
        // PDF dans un onglet. Aucun code d'impression ici.
        this._notifyCerveau('ui:note.preview-request', { url: this._urlApercu(this.noteId) + '?download=1' });
    }

    /** Rouvre la fenêtre sur le destinataire suivant, avec SES échéances. */
    facturerLesSuivantes() {
        const suivant = (this.suivantsValue || [])[0];
        if (!suivant || !suivant.ids?.length) return;

        this._notifyCerveau('ui:tranche.facturer-commission', {
            url: '/admin/note/facturation-picker?destinataire=' + encodeURIComponent(this.destinataireValue || 'assureur'),
            selection: suivant.ids.map((id) => ({ id })),
        });
        this.close();
    }

    async _onActionClick(event) {
        if (!event.target.closest('[data-picker-executer]')) return;
        if (this.enCours) return;

        const lignes = this._lignesCochees();
        if (lignes.length === 0) {
            this._showError('Cochez au moins une commission à facturer.');
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
                    destinataire: this.hasDestinataireTarget ? this.destinataireTarget.value : 'assureur',
                    objet: this.hasObjetTarget ? this.objetTarget.value : null,
                    description: this.hasDescriptionTarget ? this.descriptionTarget.value : null,
                    comptes: this.compteTargets.filter((c) => c.checked).map((c) => parseInt(c.value, 10)),
                    signataire: this.hasSignataireTarget ? this.signataireTarget.value : null,
                    titreSignataire: this.hasTitreSignataireTarget ? this.titreSignataireTarget.value : null,
                }),
            });
            const result = await response.json();
            if (!response.ok) throw result;

            this.noteId = result.noteId;

            // Le cerveau notifie et rafraîchit la liste : les échéances facturées y
            // passent d'« exigible » à « facturée ».
            this._notifyCerveau('client:note.facturation-enregistree', { message: result.message });
            this._basculerVersLeSucces(result.message);
        } catch (error) {
            this.enCours = false;
            this._progress(false);
            this._showError(error?.message || "La note n'a pas pu être émise.");
            this.recalculer();
        }
    }

    /**
     * Le pied bascule : on ne ferme pas, on propose la suite. Fermer ici obligerait à
     * retrouver la note dans sa rubrique pour l'ouvrir — le geste serait à moitié fait.
     */
    _basculerVersLeSucces(message) {
        this._progress(false);

        this.element.querySelectorAll('.jsb-picker-foot').forEach((pied) => {
            if (!this.hasFootSuccesTarget || pied !== this.footSuccesTarget) {
                pied.hidden = true;
            }
        });
        if (this.hasFootSuccesTarget) this.footSuccesTarget.hidden = false;
        if (this.hasMessageSuccesTarget) this.messageSuccesTarget.textContent = message || 'Note émise.';

        const resteDesSuivants = (this.suivantsValue || []).length > 0;
        if (this.hasSuivantTarget) this.suivantTarget.hidden = !resteDesSuivants;
    }

    /** @return {Array<{trancheId: number, revenuId: number, montant: number}>} */
    _lignesCochees() {
        const lignes = [];
        this.ligneTargets.forEach((ligne, index) => {
            const coche = this.cocheTargets[index];
            if (!coche || !coche.checked) return;

            const montant = parseFloat(ligne.dataset.montant || '0');
            if (!Number.isFinite(montant) || montant <= 0) return;

            lignes.push({
                trancheId: parseInt(ligne.dataset.trancheId, 10),
                revenuId: parseInt(ligne.dataset.revenuId, 10),
                montant: Math.round(montant * 100) / 100,
            });
        });

        return lignes;
    }

    /** Le gabarit d'URL porte un 0 à la place de l'identifiant de la note. */
    _urlApercu(noteId) {
        return (this.apercuUrlValue || '').replace(/\/0(?=(\?|#|$))/, `/${noteId}`);
    }
}
