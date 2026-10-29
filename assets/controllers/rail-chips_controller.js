import { Controller } from '@hotwired/stimulus';
import { etatDuGroupe, transitionDuPanneau, PANNEAU_FERME, APERCU } from './rail-chips-regles.js';
import { sommetDuFlyout } from './workspace-col2.js';
import { indexVise } from './barre-clavier.js';

/**
 * LE RAIL DES CHIPS (refonte de la colonne 3, C3) — la coquille : elle lit le DOM rendu par le
 * serveur et applique ce que décide `rail-chips-regles.js`.
 *
 * Chaque groupe de chips est un bouton à icône, à droite de la liste ; la rangée des chips
 * (`.jsb-preset-filters-bar`, inchangée : mêmes chips, mêmes `data-action`, même
 * synchronisation par list-manager) devient son PANNEAU en surimpression, qui ne montre que
 * le groupe visé. Survol stable (`delai`, 300 ms comme le rail d'outils) ou clic = aperçu, qui
 * se ferme dès que le pointeur quitte le rail et le panneau ; au clavier, il reste tant que le
 * focus y est. Échap, un clic dehors ou la perte du focus le ferment.
 *
 * La pastille et le nom accessible de chaque bouton suivent les chips par un
 * MutationObserver : list-manager n'a rien à savoir du rail.
 */
export default class extends Controller {
    static targets = ['rail', 'bouton', 'panneau'];
    static values = { delai: { type: Number, default: 0 } };

    connect() {
        this.etat = { etat: PANNEAU_FERME, groupe: null };
        this.minuteur = null;
        this.boundPointeur = (e) => this._pointeurDehors(e);
        this.boundClavier = (e) => this._clavier(e);
        this.boundFocus = (e) => this._focusDehors(e);
        this.boundRecaler = () => { if (this.etat.etat !== PANNEAU_FERME) this._positionner(); };
        this.boundSurvolDehors = (e) => this._survolDehors(e);
        document.addEventListener('pointerdown', this.boundPointeur, true);
        document.addEventListener('pointerover', this.boundSurvolDehors, true);
        document.addEventListener('keydown', this.boundClavier);
        this.element.addEventListener('focusout', this.boundFocus);
        // Le panneau suit son bouton : rail qui défile, fenêtre redimensionnée.
        this.railTarget.addEventListener('scroll', this.boundRecaler);
        window.addEventListener('resize', this.boundRecaler);

        this.observateur = new MutationObserver(() => this._planifierAnnonce());
        this.observateur.observe(this.panneauTarget, {
            subtree: true, attributes: true, attributeFilter: ['aria-pressed', 'class'], characterData: true, childList: true,
        });
        this._annoncer();
        this._arretDeTabulation(this.boutonTargets.find((b) => b.getClientRects().length) || null);
    }

    disconnect() {
        clearTimeout(this.minuteur);
        cancelAnimationFrame(this.trame);
        this.observateur?.disconnect();
        document.removeEventListener('pointerdown', this.boundPointeur, true);
        document.removeEventListener('pointerover', this.boundSurvolDehors, true);
        document.removeEventListener('keydown', this.boundClavier);
        this.element.removeEventListener('focusout', this.boundFocus);
        this.railTarget.removeEventListener('scroll', this.boundRecaler);
        window.removeEventListener('resize', this.boundRecaler);
    }

    /**
     * LE CLAVIER DANS LE RAIL (C4) : ↑/↓ d'un bouton à l'autre, Début et Fin aux extrémités —
     * la règle du rail d'outils (barre-clavier.js). Un seul arrêt de tabulation : le bouton
     * courant ; Tab sort du rail.
     */
    parcourir(event) {
        const boutons = this.boutonTargets.filter((b) => b.getClientRects().length);
        const index = boutons.indexOf(event.target.closest?.('button'));
        const vise = indexVise(event.key, index, boutons.length, true);
        if (vise === null) return;
        event.preventDefault();
        this._arretDeTabulation(boutons[vise]);
        boutons[vise].focus();
    }

    /** @private */
    _arretDeTabulation(courant) {
        this.boutonTargets.forEach((b) => b.setAttribute('tabindex', b === courant ? '0' : '-1'));
    }

    /** Survol d'un bouton : l'aperçu ne s'ouvre qu'après un survol STABLE. */
    survoler(event) {
        const groupe = event.currentTarget.dataset.groupe;
        clearTimeout(this.minuteur);
        this.minuteur = setTimeout(() => this._jouer({ type: 'survol', groupe }), this.delaiValue);
    }

    /**
     * Le pointeur quitte un bouton, le RAIL ou le panneau. Aller de l'un à l'autre (marges du rail
     * comprises) ne ferme rien ; en sortir ferme — même un panneau ouvert d'un clic.
     */
    quitter(event) {
        clearTimeout(this.minuteur);
        if (this._dedans(event.relatedTarget)) return;
        this._jouer({ type: 'sortie' });
    }

    basculer(event) {
        clearTimeout(this.minuteur);
        const groupe = event.currentTarget.dataset.groupe;
        // Un clic SANS pointeur (Entrée, Espace) : ouvert au clavier.
        const clavier = event.detail === 0;
        this._jouer({ type: 'clicGroupe', groupe, clavier });
        // Au clavier, le focus entre dans le panneau, sur le chip actif — sinon il faudrait
        // remonter tout l'ordre de tabulation pour l'atteindre.
        if (clavier && this.etat.etat !== PANNEAU_FERME) {
            (this._chipActif(groupe) || this._focusables()[0])?.focus();
        }
    }

    /**
     * TAB DANS LE PANNEAU : il est AVANT le rail dans le document, l'ordre naturel sauterait donc
     * ailleurs. Aux bords du groupe, Maj+Tab revient au bouton qui l'a ouvert, Tab va au bouton
     * suivant du rail ; le panneau se ferme (WCAG 2.4.3).
     */
    tabulation(event) {
        if (event.key !== 'Tab' || this.etat.etat === PANNEAU_FERME) return;
        const focusables = this._focusables();
        const premier = focusables[0];
        const dernier = focusables[focusables.length - 1];
        const boutons = this.boutonTargets.filter((b) => b.getClientRects().length);
        const ouvreur = boutons.find((b) => b.dataset.groupe === this.etat.groupe);
        let cible = null;
        if (event.shiftKey && document.activeElement === premier) cible = ouvreur;
        if (!event.shiftKey && document.activeElement === dernier) cible = boutons[boutons.indexOf(ouvreur) + 1] || ouvreur;
        if (!cible) return;
        event.preventDefault();
        this._jouer({ type: 'echap' });
        if (this.boutonTargets.includes(cible)) this._arretDeTabulation(cible);
        cible.focus();
    }

    /** @private */
    _jouer(action) {
        const avant = this.etat;
        this.etat = transitionDuPanneau(this.etat, action);
        if (this.etat.etat === avant.etat && this.etat.groupe === avant.groupe) return;
        this._rendre(avant.groupe !== this.etat.groupe);
    }

    /** @private */
    _rendre(nouveauGroupe) {
        const ouvert = this.etat.etat !== PANNEAU_FERME;
        this.boutonTargets.forEach((bouton) => {
            const vise = ouvert && bouton.dataset.groupe === this.etat.groupe;
            bouton.setAttribute('aria-expanded', vise ? 'true' : 'false');
            bouton.classList.toggle('is-ouvert', vise);
        });
        if (ouvert) {
            this._groupes().forEach(({ cle, element }) => { element.hidden = cle !== this.etat.groupe; });
        }
        this.panneauTarget.classList.toggle('is-ouvert', ouvert);
        if (!ouvert) return;
        this._positionner();
        // LE CHIP ACTIF EST RAMENÉ DANS LE CHAMP : list-manager l'y amène à chaque synchronisation,
        // mais le groupe était alors masqué (largeur nulle) — ce geste-là n'a rien pu faire.
        if (nouveauGroupe) this._chipActif(this.etat.groupe)?.scrollIntoView({ block: 'nearest', inline: 'nearest' });
    }

    /** Le panneau, à hauteur du bouton de son groupe, sans sortir de la liste. @private */
    _positionner() {
        const bouton = this.boutonTargets.find((b) => b.dataset.groupe === this.etat.groupe);
        if (!bouton) return;
        const liste = this.element.getBoundingClientRect();
        // La hauteur utile : la liste moins la barre du bas, que le panneau ne recouvre pas.
        const barreDuBas = this.element.querySelector(':scope > .jsb-list-controls-bar')?.offsetHeight || 0;
        this.panneauTarget.style.top = `${sommetDuFlyout({
            ancreTop: bouton.getBoundingClientRect().top - liste.top,
            hauteurPanneau: this.panneauTarget.offsetHeight,
            hauteurViewport: liste.height - barreDuBas,
            marge: 4,
        })}px`;
    }

    /** Les groupes du panneau : la pilule ENTIÈRE (son enveloppe de défilement) de chaque critère. @private */
    _groupes() {
        return [...this.panneauTarget.querySelectorAll('[data-preset-criterion], .jsb-vue-bascule')].map((groupe) => ({
            cle: groupe.dataset.presetCriterion || '__vue__',
            element: groupe.closest('.jsb-preset-filters-wrap') || groupe,
            groupe,
        }));
    }

    /** @private */
    _groupe(cle) {
        return this._groupes().find((g) => g.cle === cle)?.groupe || null;
    }

    /** @private */
    _chipActif(cle) {
        return this._groupe(cle)?.querySelector('[aria-pressed="true"]:not(.d-none)') || null;
    }

    /** Ce qui reçoit le focus dans le groupe affiché (chips visibles, flèches de défilement actives). @private */
    _focusables() {
        const enveloppe = this._groupes().find((g) => g.cle === this.etat.groupe)?.element;
        return enveloppe ? [...enveloppe.querySelectorAll('button:not(:disabled)')].filter((b) => b.getClientRects().length) : [];
    }

    /** @private */
    _planifierAnnonce() {
        cancelAnimationFrame(this.trame);
        this.trame = requestAnimationFrame(() => this._annoncer());
    }

    /** La pastille et le nom de chaque bouton, d'après les chips de son groupe. @private */
    _annoncer() {
        this.boutonTargets.forEach((bouton) => {
            const groupe = this._groupe(bouton.dataset.groupe);
            if (!groupe) return;
            const chips = [...groupe.querySelectorAll('.jsb-preset-chip:not(.d-none), .jsb-vue-choix')].map((chip) => ({
                valeur: chip.dataset.criterionValue ?? chip.dataset.vue ?? null,
                libelle: (chip.querySelector(':scope > span:not(.jsb-preset-chip__icon)')?.textContent || chip.textContent).trim(),
                actif: chip.getAttribute('aria-pressed') === 'true',
            }));
            const { filtre, nom } = etatDuGroupe({ titre: bouton.dataset.titre, presentation: bouton.dataset.presentation === 'true', chips });
            if (bouton.getAttribute('aria-label') !== nom) bouton.setAttribute('aria-label', nom);
            const pastille = bouton.querySelector('[data-rail-chips-pastille]');
            if (pastille) pastille.hidden = !filtre;
        });
    }

    /**
     * Dans le rail, dans le panneau — ou dans le menu d'un chip-sélecteur, posé dans le <body>
     * (list-manager#_ouvrirSelecteur) : choisir un agent ne referme pas le panneau. @private
     */
    _dedans(cible) {
        return !!cible && (this.railTarget.contains(cible)
            || this.panneauTarget.contains(cible)
            || !!cible.closest?.('.jsb-preset-selecteur'));
    }

    /** @private */
    _pointeurDehors(event) {
        if (this.etat.etat !== PANNEAU_FERME && !this._dedans(event.target)) this._jouer({ type: 'exterieur' });
    }

    /**
     * LE FILET DE L'APERÇU : le pointeur arrive sur un élément hors du rail et du panneau. Il
     * rattrape les sorties qu'aucun `mouseleave` ne dit — le menu d'un chip-sélecteur qui
     * disparaît sous le pointeur après un choix. Un panneau ouvert au clavier n'en dépend pas. @private
     */
    _survolDehors(event) {
        if (this.etat.etat === APERCU && !this._dedans(event.target)) this._jouer({ type: 'sortie' });
    }

    /** La perte du focus ferme le panneau (Serge, 2026-10-29). @private */
    _focusDehors(event) {
        if (this.etat.etat !== PANNEAU_FERME && event.relatedTarget && !this._dedans(event.relatedTarget)) {
            this._jouer({ type: 'sortie' });
        }
    }

    /** Échap ferme, et rend le focus au bouton du groupe s'il était dans le panneau. @private */
    _clavier(event) {
        if (event.key !== 'Escape' || this.etat.etat === PANNEAU_FERME) return;
        // Le menu d'un chip-sélecteur se ferme d'abord : c'est lui qui est au-dessus.
        if (document.querySelector('.jsb-preset-selecteur')) return;
        const groupe = this.etat.groupe;
        const focusDedans = this._dedans(document.activeElement);
        this._jouer({ type: 'echap' });
        const bouton = this.boutonTargets.find((b) => b.dataset.groupe === groupe);
        if (focusDedans && bouton) {
            this._arretDeTabulation(bouton);
            bouton.focus();
        }
    }
}
