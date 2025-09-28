import { Controller } from '@hotwired/stimulus';

/**
 * @class SearchCriteriaProviderController
 * @extends Controller
 * @description Ce contrôleur agit comme un "configurateur" pour une rubrique spécifique.
 * Son rôle principal est de détenir la définition des critères de recherche
 * et de la fournir au Cerveau lorsque celui-ci en fait la demande.
 * Il ne gère pas la recherche elle-même, mais seulement sa configuration.
 */
export default class extends Controller {
    /**
     * @property {StringValue} nomValue - Le nom de la rubrique, utilisé pour l'identification.
     */
    static values = {
        nom: String,
    }

    /**
     * Méthode du cycle de vie de Stimulus.
     * S'exécute lorsque le contrôleur est connecté au DOM.
     * Met en place les écouteurs pour répondre aux demandes du Cerveau.
     */
    connect() {
        this.nomControleur = `SEARCH-CRITERIA-PROVIDER - ${this.nomValue}`;
        console.log(`${this.nomControleur} - Connecté.`);

        // --- CORRECTION : Lier la méthode une seule fois et stocker la référence ---
        this.boundProvideCriteria = this.provideCriteria.bind(this);
        // --- MODIFICATION : Écoute l'ordre du Cerveau, pas une demande directe ---
        document.addEventListener('app:search.provide-criteria', this.boundProvideCriteria);
    }

    /**
     * Méthode du cycle de vie de Stimulus.
     * Nettoie l'écouteur d'événement pour éviter les fuites de mémoire.
     */
    disconnect() {
        document.removeEventListener('app:search.provide-criteria', this.boundProvideCriteria);
    }

    /**
     * Gère la demande du Cerveau et lui fournit la structure des critères de recherche.
     * @param {CustomEvent} event - L'événement personnalisé reçu du Cerveau.
     * @fires cerveau:event
     */
    provideCriteria(event) {
        console.log(this.nomControleur + " - Request for criteria received. Providing data...", event.detail);
        const criteriaDefinition = [
            {
                Nom: 'descriptionDeFait',
                Display: "Description des faits",
                Type: 'Text',
                Valeur: '',
                isDefault: true
            },
            {
                Nom: 'notifiedAt', // Le nom de l'attribut sur lequel on filtre la plage
                Display: "Date de notification", // Libellé principal affiché à l'utilisateur
                Type: 'DateTimeRange', // <-- NOUVEAU TYPE DE CRITÈRE : 'DateTimeRange'
                Valeur: { from: '', to: '' }, // Valeurs par défaut pour la plage
                isDefault: false
            },
            {
                Nom: 'referenceSinistre',
                Display: "Référence du sinistre",
                Type: 'Text',
                Valeur: '',
                isDefault: false
            },
            {
                Nom: 'referencePolice',
                Display: "Référence de la police",
                Type: 'Text',
                Valeur: '',
                isDefault: false
            },
            {
                Nom: 'dommage',
                Display: "Dommage",
                Type: 'Number',
                Valeur: 0,
                isDefault: false
            },
            {
                Nom: 'assure.nom',
                Display: "Client (assuré)",
                Type: 'Text',
                Valeur: '',
                isDefault: false
            },
            {
                Nom: 'assureur.nom',
                Display: "Assureur",
                Type: 'Text',
                Valeur: "",
                isDefault: false
            },
            {
                Nom: 'risque.code',
                Display: "Risque (Couverture)",
                Type: 'Options',
                Valeur: {
                    "imr": "INCENDIE ET RISQUES DIVERS",
                    "motor": "RC AUTOMOBILE",
                    "vie": "VIE ET EPARGNE",
                    "git": "TRANSPORT DES FACULTES",
                },
                isDefault: false
            },
        ];

        // --- MODIFICATION : Notifie le Cerveau avec les critères définis ---
        this.notifyCerveau('ui:search.criteria-provided', { criteria: criteriaDefinition });
    }

    /**
     * Méthode centralisée pour envoyer un événement au Cerveau.
     * @param {string} type - Le type d'événement pour le Cerveau (ex: 'ui:rubrique.criteria-provided').
     * @param {object} payload - Données additionnelles à envoyer.
     */
    notifyCerveau(type, payload = {}) {
        const event = new CustomEvent('cerveau:event', {
            bubbles: true, detail: { type, source: this.nomControleur, payload, timestamp: Date.now() }
        });
        this.element.dispatchEvent(event);
    }
}