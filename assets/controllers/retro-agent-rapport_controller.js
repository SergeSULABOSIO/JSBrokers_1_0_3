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
        agentId: Number,
        agentNom: String,
        // La FAMILLE du bénéficiaire : « agent » ou « partenaire ». Elle décide de la
        // colonne filtrée dans la rubrique — les deux vivent sur le même enregistrement.
        agentType: String,
        justificatifsUrl: String,
        // Le statut AFFICHÉ. Il sert à l'actualisation : relire le rapport sans le
        // reporter reviendrait à retomber sur « Souscrites » à chaque clic, et donc à
        // perdre en silence le filtre que l'utilisateur venait de poser.
        statut: String,
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

    /** Statut : règle serveur, la page se redemande. */
    filtrer(event) {
        event.preventDefault();
        const statut = event.currentTarget?.dataset?.statut;
        if (!statut) return;

        this._redemanderLeRapport(statut);
    }

    /**
     * ACTUALISER : relire les mêmes affaires, avec les montants d'à présent.
     *
     * Un rapport de production est un document qu'on garde ouvert pendant qu'on travaille
     * ailleurs — on encaisse une note, on impute un bordereau, on rattache une condition de
     * partage. Chacun de ces gestes déplace l'exigible de CE rapport, qui continuait
     * pourtant d'afficher les montants d'avant sans rien en dire. Le versement, lui, était
     * déjà couvert (le picker fait redemander le rapport) ; tout le reste ne l'était pas.
     *
     * On redemande le rapport AU STATUT AFFICHÉ, par le chemin des chips — donc avec sa
     * barre de progression et son message d'erreur, plutôt qu'en rechargeant l'onglet à
     * l'aveugle : un échec y remplacerait le rapport par un message, et le travail en
     * cours de lecture serait perdu.
     */
    actualiser(event) {
        event?.preventDefault();

        this._redemanderLeRapport(this.hasStatutValue && this.statutValue ? this.statutValue : 'souscrites');
    }

    /**
     * Le chemin unique du rechargement — le filtre par statut et l'actualisation ne
     * diffèrent que par la valeur demandée.
     *
     * @param {string} statut
     * @private
     */
    _redemanderLeRapport(statut) {
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

        // LE CRITÈRE EST CELUI DE ReversementScope, préfixé de la famille : il visait la
        // colonne `agent` en clair, et le même bouton sur le rapport d'un PARTENAIRE
        // aurait ouvert la rubrique filtrée sur un agent inexistant — donc vide, sans
        // rien dire de l'erreur.
        const famille = this.hasAgentTypeValue && this.agentTypeValue ? this.agentTypeValue : 'agent';
        document.dispatchEvent(new CustomEvent('app:workspace.open-rubrique', {
            bubbles: true,
            detail: {
                entityName: 'ReversementRetroAgent',
                criteres: {
                    __beneficiaire_reversement__: {
                        operator: '=',
                        value: `${famille}:${this.agentIdValue}`,
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
