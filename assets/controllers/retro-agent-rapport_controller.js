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
    static targets = ['recherche', 'compteur', 'sansResultat', 'scroll', 'tableau', 'pied', 'colonnesPied'];

    static values = {
        baseUrl: String,
        pickerUrl: String,
        agentId: Number,
        agentNom: String,
        justificatifsUrl: String,
    };

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

        // La barre des totaux vit HORS du tableau : c'est ce qui la garde au bas de la
        // page quelle que soit la longueur de la liste, et ce qui oblige à tenir son
        // alignement à la main.
        this._alignerLePied();
        this._suivreLeDefilement = () => this._reporterLeDefilement();
        if (this.hasScrollTarget) {
            this.scrollTarget.addEventListener('scroll', this._suivreLeDefilement, { passive: true });
        }
        // Les largeurs bougent avec la fenêtre, avec le repli des colonnes du
        // workspace, et à l'arrivée des polices de caractères.
        if (this.hasTableauTarget && typeof ResizeObserver !== 'undefined') {
            this._observateur = new ResizeObserver(() => this._alignerLePied());
            this._observateur.observe(this.tableauTarget);
        }
    }

    disconnect() {
        this._observateur?.disconnect();
        if (this.hasScrollTarget && this._suivreLeDefilement) {
            this.scrollTarget.removeEventListener('scroll', this._suivreLeDefilement);
        }
    }

    /**
     * Recopie sur la barre les largeurs MESURÉES du tableau.
     *
     * Vingt-cinq colonnes dont la largeur dépend du contenu : aucune valeur écrite
     * d'avance ne tiendrait: un nom de client plus long, et tout se décale. On lit donc
     * la rangée d'en-têtes de détail — celle qui porte une cellule par colonne — et on
     * la reporte sur les `col` de la barre, qui est en `table-layout: fixed` et les
     * respecte donc à la lettre.
     *
     * @private
     */
    _alignerLePied() {
        if (!this.hasTableauTarget || !this.hasColonnesPiedTarget) {
            return;
        }
        const enTetes = this.tableauTarget.querySelectorAll('thead tr:last-child th');
        const colonnes = this.colonnesPiedTarget.querySelectorAll('col');
        if (enTetes.length === 0 || enTetes.length !== colonnes.length) {
            return;
        }

        let total = 0;
        enTetes.forEach((th, i) => {
            const largeur = th.getBoundingClientRect().width;
            colonnes[i].style.width = `${largeur}px`;
            total += largeur;
        });
        // La barre doit être aussi large que le tableau, sans quoi son défilement
        // reporté n'aurait nulle part où aller.
        const tableauPied = this.piedTarget?.querySelector('table');
        if (tableauPied) {
            tableauPied.style.width = `${total}px`;
        }
        this._reporterLeDefilement();
    }

    /**
     * La barre suit le tableau quand il défile de côté : deux rangées de chiffres qui
     * ne parlent pas des mêmes colonnes seraient pires qu'un total absent.
     *
     * @private
     */
    _reporterLeDefilement() {
        if (this.hasScrollTarget && this.hasPiedTarget) {
            this.piedTarget.scrollLeft = this.scrollTarget.scrollLeft;
        }
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

    /**
     * LES JUSTIFICATIFS DES VERSEMENTS SUR UNE AFFAIRE, depuis sa ligne.
     *
     * Même boîte que celle d'une fiche : on émet `ui:documents.liste-request`, que le
     * cerveau traite déjà. Seule la source des lignes change — une affaire peut avoir
     * été soldée par plusieurs virements, chacun avec sa pièce.
     */
    justificatifs(event) {
        event.preventDefault();
        const avenantId = event.currentTarget?.dataset?.avenantId;
        if (!avenantId || !this.hasJustificatifsUrlValue) return;

        this.element.dispatchEvent(new CustomEvent('cerveau:event', {
            bubbles: true,
            detail: {
                type: 'ui:documents.liste-request',
                source: this.nomControleur,
                // Le gabarit d'URL porte un 0 en place de l'affaire : le serveur rend une
                // page pour tout le rapport, il ne peut pas y écrire chaque identifiant.
                payload: { url: this.justificatifsUrlValue.replace('/affaire/0/', `/affaire/${avenantId}/`) },
                timestamp: Date.now(),
            },
        }));
    }

    /**
     * OUVRIR LA RUBRIQUE DES REVERSEMENTS, filtrée sur cet agent.
     *
     * Il y avait ici un volet dédié : un second écran pour la même donnée, à maintenir en
     * double. La rubrique fait tout ce qu'il faisait, et davantage — recherche, tri, chips,
     * actions documentaires.
     *
     * ON EMPRUNTE L'ÉVÉNEMENT DE L'ASSISTANT, pas un nouveau. `app:workspace.open-rubrique`
     * est exactement ce que produit `ouvrir_rubrique` de Ket : écran et assistant ouvrent
     * donc la même liste, avec la même forme de critère, par le même chemin. La parité n'est
     * pas à construire, elle est structurelle. Et le critère apparaît en badge retirable dans
     * la barre de recherche, ce qu'un filtre maison n'aurait pas fait.
     */
    versements(event) {
        event.preventDefault();
        if (!this.hasAgentIdValue) return;

        document.dispatchEvent(new CustomEvent('app:workspace.open-rubrique', {
            bubbles: true,
            detail: {
                entityName: 'ReversementRetroAgent',
                criteres: {
                    agent: {
                        operator: '=',
                        value: this.agentIdValue,
                        label: this.hasAgentNomValue ? this.agentNomValue : `#${this.agentIdValue}`,
                    },
                },
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
