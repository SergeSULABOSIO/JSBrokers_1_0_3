import { Controller } from '@hotwired/stimulus';

/**
 * Synchronise le PRÉFIXE du nom d'une piste avec le « Type d'Avenant » choisi.
 *
 * Cas d'usage : la piste dérivée est pré-remplie « Renouvellement — <nom de base> ».
 * Si l'utilisateur bascule le type sur « Prorogation », le préfixe doit suivre
 * (« Prorogation — <nom de base> ») pour rester fidèle au type réel.
 *
 * Détection sûre : on ne réécrit le préfixe QUE s'il correspond déjà à un libellé de
 * type connu suivi du séparateur « — ». Un nom personnalisé (sans ce motif) est laissé
 * intact — le contrôleur peut donc équiper tous les formulaires de piste sans risque.
 *
 * Les libellés (valeur → texte) proviennent de PisteType (source unique) via la valeur
 * Stimulus `labels`, ce qui évite toute duplication côté JS.
 */
export default class extends Controller {
    static values = { labels: Object };

    // Séparateur préfixe/nom : espace + tiret cadratin (U+2014) + espace, identique à
    // celui posé côté serveur (PisteController::getFormApi).
    static SEP = ' — ';

    connect() {
        this.nomInput = this.element.querySelector('[name="nom"]');
        this.radios = Array.from(this.element.querySelectorAll('input[name="typeAvenant"]'));
        if (!this.nomInput || this.radios.length === 0) return;

        this.knownLabels = Object.values(this.labelsValue || {});
        this.boundSync = this.sync.bind(this);
        this.radios.forEach((radio) => radio.addEventListener('change', this.boundSync));
    }

    disconnect() {
        if (this.radios) {
            this.radios.forEach((radio) => radio.removeEventListener('change', this.boundSync));
        }
    }

    sync(event) {
        const newLabel = (this.labelsValue || {})[event.target.value];
        if (!newLabel) return;

        const sep = this.constructor.SEP;
        const current = this.nomInput.value || '';
        const idx = current.indexOf(sep);
        if (idx === -1) return; // Pas de préfixe « <type> — … » : nom personnalisé, on n'y touche pas.

        const currentPrefix = current.slice(0, idx);
        if (!this.knownLabels.includes(currentPrefix)) return; // Le préfixe n'est pas un type connu.

        this.nomInput.value = newLabel + sep + current.slice(idx + sep.length);
    }
}
