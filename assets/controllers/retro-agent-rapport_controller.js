import { Controller } from '@hotwired/stimulus';

/**
 * @class RetroAgentRapportController
 * @description Les deux commandes du rapport de production d'un agent : le STATUT des
 * affaires (Souscrites, En attente, Caduques) et la RECHERCHE RAPIDE.
 *
 * ── DEUX FILTRES, DEUX NATURES, DEUX CHEMINS ────────────────────────────────────────
 * Le statut est une règle SERVEUR (CotationSouscriptionScope) : les montants d'une
 * projection ne se déduisent pas de ceux d'une affaire souscrite, et rien ici ne saurait
 * les recalculer. Un changement de statut redemande donc la page, dans le MÊME onglet.
 *
 * La recherche, elle, ne fait que restreindre ce qui est DÉJÀ sous les yeux : un
 * aller-retour serveur pour retrouver un client dans une liste affichée serait une
 * attente sans contrepartie. Elle masque des lignes, elle n'en recalcule aucune — et les
 * TOTAUX restent donc ceux du rapport complet. C'est délibéré : un total qui changerait au
 * gré d'une frappe ne serait plus vérifiable à la main, et le compteur dit alors
 * explicitement combien de lignes sont montrées sur combien.
 */
export default class extends Controller {
    static targets = ['recherche', 'compteur', 'sansResultat'];

    static values = {
        baseUrl: String,
        pickerUrl: String,
    };

    connect() {
        this.nomControleur = 'RETRO-AGENT-RAPPORT';
        this.lignes = Array.from(document.querySelectorAll('[data-rpa-ligne]'));

        // L'AFFICHAGE SUIT TOUJOURS LE CHAMP.
        //
        // Au rechargement, le navigateur restitue de lui-même ce qui était saisi. Se
        // contenter d'afficher toutes les lignes laisserait alors un terme écrit dans la
        // barre au-dessus d'une liste complète : l'écran contredirait ce qu'on y lit.
        // On applique donc le filtre tel qu'il est — vide compris, auquel cas rien n'est
        // masqué et le compteur annonce le total.
        this.chercher();
    }

    /** Statut : règle serveur, la page se redemande. */
    filtrer(event) {
        event.preventDefault();
        const statut = event.currentTarget?.dataset?.statut;
        if (!statut) return;

        // Même événement que l'action de la barre d'outils : le cerveau réinjecte le HTML
        // dans l'onglet existant (tabKey identique), plutôt que d'en empiler un nouveau.
        this.element.dispatchEvent(new CustomEvent('cerveau:event', {
            bubbles: true,
            detail: {
                type: 'ui:retroagent.rapport-request',
                source: this.nomControleur,
                payload: { url: `${this.baseUrlValue}?statut=${encodeURIComponent(statut)}` },
                timestamp: Date.now(),
            },
        }));
    }

    /**
     * Verser, depuis le rapport qu'on est en train de lire.
     *
     * Même événement que l'action de la fiche de l'invité : le picker s'ouvre, ne
     * propose que les affaires dont la part est EXIGIBLE, et l'écriture reste la sienne.
     * Rien n'est dupliqué ici — seulement le point de départ, là où la décision se prend.
     */
    reverser(event) {
        event.preventDefault();
        if (!this.hasPickerUrlValue) return;

        this.element.dispatchEvent(new CustomEvent('cerveau:event', {
            bubbles: true,
            detail: {
                type: 'ui:retroagent.reversement-request',
                source: this.nomControleur,
                payload: { url: this.pickerUrlValue },
                timestamp: Date.now(),
            },
        }));
    }

    /** Recherche rapide : restreint l'affichage, ne touche à aucun montant. */
    chercher() {
        if (!this.hasRechercheTarget) {
            this._majCompteur(this.lignes.length);

            return;
        }

        const terme = this.rechercheTarget.value.trim().toLowerCase();
        let visibles = 0;

        this.lignes.forEach((ligne) => {
            const correspond = terme === '' || (ligne.dataset.rpaTexte || '').includes(terme);
            // `hidden` plutôt qu'une classe : l'attribut retire la ligne de l'arbre
            // d'accessibilité, là où un simple `display:none` de classe se contenterait de
            // la cacher à l'œil.
            ligne.hidden = !correspond;
            if (correspond) visibles += 1;
        });

        if (this.hasSansResultatTarget) {
            this.sansResultatTarget.classList.toggle('d-none', visibles > 0);
        }
        this._majCompteur(visibles);
    }

    /**
     * « 3 lignes » quand tout est montré, « 2 sur 6 » dès qu'un filtre restreint : sans
     * ce second cas, une liste écourtée se lit comme une liste complète.
     *
     * @private
     */
    _majCompteur(visibles) {
        if (!this.hasCompteurTarget) return;

        const total = this.lignes.length;
        this.compteurTarget.textContent = visibles === total
            ? `${total} ligne${total > 1 ? 's' : ''}`
            : `${visibles} ligne${visibles > 1 ? 's' : ''} sur ${total}`;
    }
}
