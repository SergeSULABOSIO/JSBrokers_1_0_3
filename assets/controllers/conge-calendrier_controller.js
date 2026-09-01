import PickerBaseController from './picker-base_controller.js';

/**
 * LE CALENDRIER D'ÉQUIPE — grille mensuelle, ouverte depuis la barre d'outils des congés.
 *
 * Le HTML (_calendrier.html.twig) est chargé et inséré par le cerveau
 * (handleCongeCalendrierRequest) ; ce contrôleur s'auto-connecte à l'insertion. La coque —
 * focus, fermeture ✕/backdrop/Échap, progression, zone d'erreur — vient du socle
 * picker-base.
 *
 * ── IL NE CALCULE RIEN ──────────────────────────────────────────────────────────────
 * Changer de mois, c'est redemander la grille au SERVEUR et substituer un fragment. Une
 * grille recalculée dans le navigateur finirait par ne plus dire la même chose que la
 * liste — jours fériés du cabinet, régimes de travail, absences approuvées : tout cela
 * vit côté serveur, et doit y rester.
 *
 * ── ON NE RECHARGE QUE LA GRILLE ────────────────────────────────────────────────────
 * Recharger la boîte entière ferait perdre le focus et rejouerait l'animation
 * d'ouverture à chaque clic de flèche.
 */
export default class extends PickerBaseController {
    static pickerName = 'CONGE-CALENDRIER';

    static targets = ['grille'];

    static values = {
        url: String,
    };

    /**
     * Les flèches de navigation portent leur cible en attributs de données : le mois
     * suivant et le précédent sont calculés par le serveur, qui seul sait ce que
     * « décembre + 1 » veut dire.
     */
    _onActionClick(event) {
        const bouton = event.target.closest('[data-cal-mois]');
        if (!bouton) return;

        this._charger(bouton.dataset.calAnnee, bouton.dataset.calMois);
    }

    async _charger(annee, mois) {
        if (!this.urlValue || this.enCours) return;

        this.enCours = true;
        this._progress(true);
        this._showError(null);
        try {
            const url = new URL(this.urlValue, window.location.origin);
            url.searchParams.set('annee', annee);
            url.searchParams.set('mois', mois);

            const response = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            const data = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(data.message || `Erreur serveur ${response.status}`);

            if (this.hasGrilleTarget) {
                this.grilleTarget.innerHTML = data.html || '';
            }
            this._majTitre(data.libelle);
        } catch (error) {
            console.error('[CONGE-CALENDRIER] chargement :', error);
            // La grille précédente reste affichée : on signale sans effacer le contexte.
            this._showError("Le mois demandé n'a pas pu être chargé.");
        } finally {
            this.enCours = false;
            this._progress(false);
        }
    }

    /** Le titre de la boîte suit le mois affiché, sinon il ment dès le premier clic. */
    _majTitre(libelle) {
        if (!libelle) return;

        const titre = this.element.querySelector('#jsb-cal-title');
        if (titre) titre.textContent = `Absences — ${libelle}`;
    }
}
