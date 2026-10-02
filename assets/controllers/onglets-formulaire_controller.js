import { Controller } from '@hotwired/stimulus';
import { DebordementOnglets } from './onglets-debordement.js';

/**
 * LES ONGLETS D'UN FORMULAIRE DE SAISIE.
 *
 * Chaque collection d'un dialogue est un onglet ; tout le reste forme « Principal ».
 * Le dessin vient du composant partagé `.jsb-onglet*` (app.css, section 3.1) et le repli
 * « + N » du module `DebordementOnglets` — les mêmes que les deux barres du workspace.
 * Rien n'est redessiné ici : ce contrôleur ne fait qu'orchestrer.
 *
 * Ce qu'il tient, au-delà du simple basculement :
 *  — le CHARGEMENT PARESSEUX : une liste ne part chercher ses données qu'à l'ouverture
 *    de son onglet (sept collections ouvraient sept requêtes pour n'en lire qu'une) ;
 *  — la COHÉRENCE avec la visibilité conditionnelle : un onglet dont la collection est
 *    masquée disparaît avec elle, et revient avec elle ;
 *  — les ERREURS : un onglet qui cache un champ fautif le dit, même replié derrière « + N ».
 */
export default class extends Controller {
    static targets = [
        'barre',
        'rangee',
        'onglet',
        'panneau',
        'boutonPlus',
        'compteurPlus',
        'panneauDebordement',
    ];

    connect() {
        // Les trois écoutes sont posées sur L'ÉLÉMENT, jamais sur `document` : deux
        // dialogues peuvent être ouverts en même temps (une piste, puis une cotation
        // ouverte depuis sa liste), et une écoute globale ferait répondre les onglets
        // du parent à ce qui se passe chez l'enfant.
        this.boundCompte = this._surCompte.bind(this);
        this.boundErreurs = this._rafraichirLesMarqueursDErreur.bind(this);
        this.boundReveler = this._surReveler.bind(this);
        this.element.addEventListener('app:collection.compte', this.boundCompte);
        this.element.addEventListener('app:formulaire-onglets.erreurs-changees', this.boundErreurs);
        this.element.addEventListener('app:formulaire-onglets.reveler', this.boundReveler);

        this._observerLaVisibilite();

        if (this.hasBarreTarget && typeof ResizeObserver !== 'undefined') {
            this._observateurTaille = new ResizeObserver(() => this._recalculerEtSignaler());
            // La BARRE, et non la rangée : celle-ci est en `overflow: hidden` et ne
            // change plus de taille une fois les onglets repliés.
            this._observateurTaille.observe(this.barreTarget);
        }

        // PASSE INITIALE, et non une simple attente d'événement : si aucune condition de
        // visibilité ne change jamais, aucune mutation ne surviendra — et un onglet que le
        // canevas a masqué dès l'ouverture resterait offert au clic. Le `rAF` laisse
        // `dialog-instance#checkFormVisibility` poser son premier verdict.
        requestAnimationFrame(() => {
            this._synchroniserLaVisibilite();
            this._rafraichirLesMarqueursDErreur();
        });
    }

    disconnect() {
        this.element.removeEventListener('app:collection.compte', this.boundCompte);
        this.element.removeEventListener('app:formulaire-onglets.erreurs-changees', this.boundErreurs);
        this.element.removeEventListener('app:formulaire-onglets.reveler', this.boundReveler);
        this._observateurTaille?.disconnect();
        (this._observateurs || []).forEach((o) => o.disconnect());
        this.debordement?.detruire();
    }

    // ───────────────────────────── Basculement ──────────────────────────────

    /** Action du clic sur un onglet. */
    switchTab(event) {
        this._activer(event.currentTarget);
    }

    /** Action du clic sur « + N ». */
    toggleDebordement(event) {
        this._debordement()?.basculer(event);
    }

    /**
     * Pattern ARIA Tabs : les flèches déplacent le FOCUS (l'activation reste au clic ou à
     * Entrée). Les onglets masqués sont sautés — une flèche ne doit pas poser le focus sur
     * ce que personne ne voit.
     */
    handleTabKeydown(event) {
        if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;

        const visibles = this.ongletTargets.filter((o) => !o.classList.contains('d-none'));
        if (visibles.length === 0) return;
        event.preventDefault();

        const courant = visibles.indexOf(document.activeElement);
        let cible;
        if (event.key === 'Home') {
            cible = 0;
        } else if (event.key === 'End') {
            cible = visibles.length - 1;
        } else {
            const pas = event.key === 'ArrowRight' ? 1 : -1;
            const depart = courant === -1 ? 0 : courant;
            cible = (depart + pas + visibles.length) % visibles.length;
        }
        visibles[cible].focus();
    }

    /**
     * Révèle l'onglet qui contient cet élément. Appelé par le dialogue quand une erreur de
     * validation frappe un champ hors de l'onglet ouvert : sans cela, l'utilisateur verrait
     * un refus d'enregistrer sans jamais voir ce qui le cause.
     */
    revelerLElement(element) {
        const panneau = element?.closest?.('.jsb-onglets-panneau');
        if (!panneau || !this.element.contains(panneau)) return;
        const onglet = this._ongletDe(panneau.dataset.tabId);
        if (onglet) this._activer(onglet);
    }

    // ───────────────────────────── Interne ──────────────────────────────────

    _activer(onglet) {
        if (!onglet || onglet.classList.contains('active')) return;

        this.ongletTargets.forEach((o) => {
            const estCelui = o === onglet;
            o.classList.toggle('active', estCelui);
            o.setAttribute('aria-selected', estCelui ? 'true' : 'false');
            // `tabindex` tournant : un seul onglet dans l'ordre de tabulation, les flèches
            // font le reste du parcours.
            o.tabIndex = estCelui ? 0 : -1;
        });

        this.panneauTargets.forEach((p) => {
            p.classList.toggle('est-cache', p.dataset.tabId !== onglet.dataset.tabId);
        });

        this._reveillerLesCollections(onglet.dataset.tabId);
        this._recalculerEtSignaler();
    }

    /**
     * CHARGEMENT PARESSEUX, par deux voies conjointes.
     *
     * L'attribut est posé AVANT l'événement, et il est le seul des deux à survivre : un
     * contrôleur `collection` qui se branche APRÈS l'ouverture de son onglet n'aurait
     * jamais entendu l'événement, et sa liste serait restée vide pour toujours.
     */
    _reveillerLesCollections(tabId) {
        const panneau = this.panneauTargets.find((p) => p.dataset.tabId === tabId);
        if (!panneau) return;
        panneau.querySelectorAll('.collection-manager-accordion').forEach((el) => {
            el.dataset.chargeDemandee = 'oui';
            el.dispatchEvent(new CustomEvent('app:collection.charger'));
        });
    }

    _ongletDe(tabId) {
        return this.ongletTargets.find((o) => o.dataset.tabId === tabId) || null;
    }

    /** La rangée du canevas portée par un panneau de collection (il n'en a qu'une). */
    _rangeeDe(panneau) {
        return panneau.querySelector(':scope > .row');
    }

    _debordement() {
        if (this.debordement) return this.debordement;
        if (!this.hasBarreTarget || !this.hasRangeeTarget || !this.hasBoutonPlusTarget) return null;

        this.debordement = new DebordementOnglets({
            wrapper: this.barreTarget,
            rangee: this.rangeeTarget,
            bouton: this.boutonPlusTarget,
            compteur: this.hasCompteurPlusTarget ? this.compteurPlusTarget : null,
            panneau: this.hasPanneauDebordementTarget ? this.panneauDebordementTarget : null,
            selecteurOnglet: '.jsb-onglet',
            // Pas de `fermer` : un onglet de formulaire ne se ferme pas, donc aucune croix.
        });
        return this.debordement;
    }

    // ──────────────────── Cohérence avec la visibilité ──────────────────────

    /**
     * Un `MutationObserver` plutôt qu'un événement à inventer : la synchronisation suit
     * alors la vérité du DOM, dans les deux sens, sans dépendre de qui pense à prévenir.
     * `dialog-instance#checkFormVisibility` pose et retire `d-none` sur la rangée ; on
     * n'a qu'à la regarder.
     */
    _observerLaVisibilite() {
        this._observateurs = [];
        if (typeof MutationObserver === 'undefined') return;

        this.panneauTargets.forEach((panneau) => {
            if (panneau.dataset.tabId === 'principal') return;
            const rangee = this._rangeeDe(panneau);
            if (!rangee) return;
            const observateur = new MutationObserver(() => this._synchroniserLaVisibilite());
            observateur.observe(rangee, { attributes: true, attributeFilter: ['class'] });
            this._observateurs.push(observateur);
        });
    }

    _synchroniserLaVisibilite() {
        let actifDevenuInvisible = false;

        this.panneauTargets.forEach((panneau) => {
            if (panneau.dataset.tabId === 'principal') return;
            const rangee = this._rangeeDe(panneau);
            const onglet = this._ongletDe(panneau.dataset.tabId);
            if (!rangee || !onglet) return;

            const masque = rangee.classList.contains('d-none');
            onglet.classList.toggle('d-none', masque);
            if (masque && onglet.classList.contains('active')) actifDevenuInvisible = true;
        });

        // L'onglet ouvert vient de disparaître : rester dessus laisserait le formulaire
        // sur un panneau vide, sans onglet actif dans la barre.
        if (actifDevenuInvisible) {
            const repli = this.ongletTargets.find((o) => !o.classList.contains('d-none'));
            if (repli) this._activer(repli);
        }

        this._recalculerEtSignaler();
    }

    // ───────────────────────── Compte et erreurs ────────────────────────────

    /**
     * Le compte vient du serveur au rendu (pastille déjà présente), ou du navigateur quand
     * la collection vit en mémoire — EN CRÉATION, le parent n'ayant pas d'id, le serveur ne
     * compte rien. La pastille est donc CRÉÉE à la volée, sans quoi une cotation mise en
     * attente dans le tampon n'aurait nulle part où s'afficher.
     */
    _surCompte(event) {
        const panneau = event.target?.closest?.('.jsb-onglets-panneau');
        if (!panneau) return;
        const onglet = this._ongletDe(panneau.dataset.tabId);
        if (!onglet) return;
        this._poserLeCompte(onglet, Number(event.detail?.count ?? 0));
    }

    _poserLeCompte(onglet, compte) {
        let pastille = onglet.querySelector('.jsb-onglet-compte');

        if (compte <= 0) {
            pastille?.remove();
            return;
        }

        if (!pastille) {
            pastille = document.createElement('span');
            pastille.className = 'jsb-onglet-compte';
            // Avant le marqueur d'erreur s'il existe : le nombre appartient au titre,
            // l'alerte vient après.
            onglet.insertBefore(pastille, onglet.querySelector('.jsb-onglet-alerte'));
        }

        pastille.textContent = String(compte);
        const mot = document.createElement('span');
        mot.className = 'visually-hidden';
        // Accordé : « 1 élément », jamais « 1 éléments ».
        mot.textContent = compte > 1 ? ' éléments' : ' élément';
        pastille.appendChild(mot);
    }

    /**
     * Idempotente : elle pose le marqueur sur TOUS les onglets fautifs et le retire de tous
     * les autres. C'est ce qui évite qu'un marqueur survive à la correction qui l'a résolu.
     */
    _rafraichirLesMarqueursDErreur() {
        this.panneauTargets.forEach((panneau) => {
            const onglet = this._ongletDe(panneau.dataset.tabId);
            if (!onglet) return;

            const fautif = panneau.querySelector('.is-invalid') !== null;
            const marqueur = onglet.querySelector('.jsb-onglet-alerte');

            if (fautif && !marqueur) {
                const signe = document.createElement('span');
                signe.className = 'jsb-onglet-alerte';
                signe.setAttribute('aria-hidden', 'true');
                signe.textContent = '!';
                const texte = document.createElement('span');
                texte.className = 'visually-hidden';
                texte.dataset.pourAlerte = '1';
                texte.textContent = ' contient des erreurs';
                onglet.append(signe, texte);
            } else if (!fautif && marqueur) {
                marqueur.remove();
                onglet.querySelectorAll('[data-pour-alerte="1"]').forEach((e) => e.remove());
            }
        });

        this._recalculerEtSignaler();
    }

    /**
     * Le repli, puis ce que le repli CACHE. Un onglet en erreur poussé derrière « + N »
     * emporterait son marqueur hors de vue : le bouton le reprend à son compte, exactement
     * comme il reprend déjà l'onglet courant (`has-current`).
     */
    _recalculerEtSignaler() {
        this._debordement()?.recalculer();
        if (!this.hasBoutonPlusTarget) return;

        const replieEnErreur = this.ongletTargets.some(
            (o) => o.classList.contains('is-tab-replie') && o.querySelector('.jsb-onglet-alerte'),
        );
        this.boutonPlusTarget.classList.toggle('a-une-erreur', replieEnErreur);

        const note = this.boutonPlusTarget.querySelector('[data-pour-alerte="1"]');
        if (replieEnErreur && !note) {
            const texte = document.createElement('span');
            texte.className = 'visually-hidden';
            texte.dataset.pourAlerte = '1';
            texte.textContent = ' dont certains contiennent des erreurs';
            this.boutonPlusTarget.appendChild(texte);
        } else if (!replieEnErreur && note) {
            note.remove();
        }
    }

    _surReveler(event) {
        if (event.detail?.element) this.revelerLElement(event.detail.element);
    }
}
