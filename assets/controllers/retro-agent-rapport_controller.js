import { Controller } from '@hotwired/stimulus';

/**
 * @class RetroAgentRapportController
 * @description Ce qui reste du tableau de production quand la coquille de rubrique tient
 * le reste : la RECHERCHE RAPIDE sur les lignes affichées, et l'accès aux JUSTIFICATIFS
 * d'une affaire.
 *
 * ── CE QUI A DISPARU, ET POURQUOI ───────────────────────────────────────────────────
 * Ce contrôleur pilotait un écran à part — sa barre de commandes, son filtre par statut,
 * son bouton de reversement, son actualisation. Cet écran est devenu la rubrique
 * « Production intermédiaires » : les chips portent le statut, la barre d'outils porte le
 * reversement, et le socle porte l'actualisation. Tout cela vit désormais à UN endroit,
 * partagé avec les trente autres rubriques, et n'avait plus à être tenu en double ici.
 *
 * La recherche rapide, elle, ne fait que restreindre ce qui est DÉJÀ sous les yeux. Elle
 * masque des lignes, elle n'en recalcule aucune — et les TOTAUX restent donc ceux du
 * rapport complet. C'est délibéré : un total qui changerait au gré d'une frappe ne serait
 * plus vérifiable à la main, et le compteur dit alors explicitement combien de lignes sont
 * montrées sur combien.
 */
export default class extends Controller {
    static targets = ['recherche', 'compteur', 'sansResultat'];

    connect() {
        this.nomControleur = 'RETRO-AGENT-RAPPORT';
        this.lignes = Array.from(document.querySelectorAll('[data-rpa-ligne]'));

        // UN RAPPORT S'OUVRE SANS FILTRE.
        //
        // Au rechargement, le navigateur restitue de lui-même le dernier terme saisi :
        // le rapport rouvrait donc filtré, sans que personne l'ait demandé, et l'écran
        // n'annonçait qu'une partie des affaires. On repart du vide — le serveur vient
        // de rendre la liste complète, c'est elle qu'on montre — puis on aligne
        // l'affichage sur le champ, pour que les deux ne se contredisent jamais.
        if (this.hasRechercheTarget) {
            this.rechercheTarget.value = '';
        }
        this.chercher();

        // ── LE TABLEAU EST REMPLACÉ À CHAQUE RECHERCHE ───────────────────────────
        //
        // Le socle réécrit le contenu de sa cible `donnees` : les lignes que ce contrôleur
        // avait mémorisées pour sa recherche rapide n'existent plus. Lui vit sur le
        // conteneur — il survit, et doit donc se ressaisir.
        //
        // Il n'a plus rien d'autre à reprendre : le pied des totaux est devenu une rangée
        // du tableau, collante au bas de la zone. Son alignement est celui des colonnes et
        // son défilement celui du tableau — plus rien à mesurer, plus rien à reporter.
        this._auRendu = () => {
            requestAnimationFrame(() => {
                this.lignes = Array.from(this.element.querySelectorAll('[data-rpa-ligne]'));
            });
        };
        document.addEventListener('app:list.rendered', this._auRendu);
    }

    disconnect() {
        if (this._auRendu) {
            document.removeEventListener('app:list.rendered', this._auRendu);
        }
    }

    /**
     * LES JUSTIFICATIFS DES VERSEMENTS SUR UNE AFFAIRE, depuis sa ligne.
     *
     * Même boîte que celle d'une fiche : on émet `ui:documents.liste-request`, que le
     * cerveau traite déjà. Seule la source des lignes change — une affaire peut avoir été
     * soldée par plusieurs virements, chacun avec sa pièce.
     *
     * ⚠ L'URL VIENT DU BOUTON, entièrement formée par le serveur. Elle était fabriquée
     * ici à partir d'un gabarit porté par le CONTENEUR — lequel n'est rendu qu'une fois,
     * avant qu'un bénéficiaire soit choisi, tandis que les lignes le sont à chaque
     * recherche. Le gabarit restait donc vide et le bouton annonçait « 2 pièces » sans
     * rien ouvrir : ni erreur, ni message, rien qui puisse le laisser voir.
     */
    justificatifs(event) {
        event.preventDefault();
        const url = event.currentTarget?.dataset?.url;
        if (!url) return;

        this.element.dispatchEvent(new CustomEvent('cerveau:event', {
            bubbles: true,
            detail: {
                type: 'ui:documents.liste-request',
                source: this.nomControleur,
                payload: { url },
                timestamp: Date.now(),
            },
        }));
    }

    /**
     * Le champ n'est éditable qu'une fois cliqué : c'est ce qui empêche Chrome d'y
     * écrire de lui-même (il n'écrit jamais dans un champ en lecture seule). Même
     * parade que la barre de recherche des listes.
     */
    activerSaisie() {
        if (this.hasRechercheTarget) {
            this.rechercheTarget.removeAttribute('readonly');
        }
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
