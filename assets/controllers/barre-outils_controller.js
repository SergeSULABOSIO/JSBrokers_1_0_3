import { Controller } from '@hotwired/stimulus';
import { EVEN_ACTION_AJOUTER, EVEN_ACTION_COCHER, EVEN_ACTION_COCHER_TOUT, EVEN_ACTION_DEVELOPPER, EVEN_ACTION_MODIFIER, EVEN_ACTION_NOTIFIER_SELECTION, EVEN_ACTION_PARAMETRER, EVEN_ACTION_QUITTER, EVEN_ACTION_RECHARGER, EVEN_ACTION_SUPPRIMER } from './base_controller.js';

export default class extends Controller {
    static targets = [
        'btquitter',
        'btparametres',
        'btrecharger',
        'btajouter',
        'btmodifier',
        'btsupprimer',
        'bttoutcocher',
        'btdevelopper',
    ];

    connect() {
        this.nomControleur = "BARRE-OUTILS";
        console.log(this.nomControleur + " - Connecté");
        this.init();
    }

    init() {
        this.tabSelectedCheckBoxs = [];
        this.listePrincipale = document.getElementById("liste");
        this.initialiserBarreDoutils();
        this.initToolTips();
        this.ecouteurs();
    }

    ecouteurs() {
        console.log(this.nomControleur + " - Activation des écouteurs d'évènements");
        this.listePrincipale.addEventListener(EVEN_ACTION_NOTIFIER_SELECTION, this.handleSelectedItems.bind(this));
    }

    disconnect() {
        console.log(this.nomControleur + " - Déconnecté - Suppression d'écouteurs.");
        this.listePrincipale.removeEventListener(EVEN_ACTION_NOTIFIER_SELECTION, this.handleSelectedItems.bind(this));
    }

    /**
     * @description Gère l'événement de modification.
     * @param {CustomEvent} event L'événement personnalisé déclenché.
     */
    handleSelectedItems(event) {
        const { selection } = event.detail; // Récupère les données de l'événement
        console.log(this.nomControleur + " - EVENEMENT RECU: LISTE DES CHECKBOX SELECTIONNEES.", selection);
        if (selection.length >= 1) {
            if (selection.length == 1) {
                this.btmodifierTarget.style.display = "block";
                this.btdevelopperTarget.style.display = "block";
            } else {
                this.btmodifierTarget.style.display = "none";
                this.btdevelopperTarget.style.display = "none";
            }
            this.btsupprimerTarget.style.display = "block";
        } else {
            this.btmodifierTarget.style.display = "none";
            this.btsupprimerTarget.style.display = "none";
        }
        this.tabSelectedCheckBoxs = selection;
        // Tu peux aussi prévenir la propagation de l'événement si nécessaire
        event.stopPropagation();
    }

    initToolTips() {
        //On initialise le tooltips
        const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
        const tooltipList = [...tooltipTriggerList].map(tooltipTriggerEl => new bootstrap.Tooltip(tooltipTriggerEl));
    }

    initialiserBarreDoutils() {
        this.btquitterTarget.style.display = "block";
        this.btparametresTarget.style.display = "block";
        this.btajouterTarget.style.display = "block";
        this.btrechargerTarget.style.display = "block";
        this.bttoutcocherTarget.style.display = "block";
        // this.btdevelopperTarget.style.display = "block";
    }

    /**
     * LES ACTIONS
     */
    action_quitte() {
        console.log(this.nomControleur + " - Action_Quitter");
        this.buildCustomEvent(EVEN_ACTION_QUITTER, true, true, {});
    }

    action_tout_cocher() {
        console.log(this.nomControleur + " - Action_tout_cocher");
        this.buildCustomEvent(EVEN_ACTION_COCHER_TOUT, true, true, {});
    }

    action_developper() {
        console.log(this.nomControleur + " - Action Développer");
        let listElement = document.getElementById("liste_row_" + this.tabSelectedCheckBoxs[0].split("check_")[1]);
        this.buildCustomEventForElement(listElement, EVEN_ACTION_DEVELOPPER, true, true, {});
    }

    action_parametrer() {
        console.log(this.nomControleur + " - Action_Parametrer");
        this.buildCustomEvent(EVEN_ACTION_PARAMETRER, true, true, {});
    }

    action_recharger() {
        console.log(this.nomControleur + " - Action_Recharger");
        this.buildCustomEvent(EVEN_ACTION_RECHARGER, true, true, {});
    }

    action_ajouter() {
        console.log(this.nomControleur + " - Action_Ajouter");
        this.buildCustomEvent(EVEN_ACTION_AJOUTER, true, true, {titre: "Nouvelle notification"});
    }

    action_modifier() {
        console.log(this.nomControleur + " - Action_Modifier");
        this.buildCustomEvent(EVEN_ACTION_MODIFIER, true, true, {itre: "Modification de la notification"});
    }

    action_supprimer() {
        console.log(this.nomControleur + " - Action_Supprimer");
        this.buildCustomEvent(EVEN_ACTION_SUPPRIMER, true, true, {titre: "Suppression "});
    }

    buildCustomEvent(nomEvent, canBubble, canCompose, detailTab) {
        const event = new CustomEvent(nomEvent, {
            bubbles: canBubble, composed: canCompose, detail: detailTab
        });
        this.listePrincipale.dispatchEvent(event);
    }

    buildCustomEventForElement(htmlElement, nomEvent, canBubble, canCompose, detailTab) {
        const event = new CustomEvent(nomEvent, {
            bubbles: canBubble, composed: canCompose, detail: detailTab
        });
        htmlElement.dispatchEvent(event);
    }
}