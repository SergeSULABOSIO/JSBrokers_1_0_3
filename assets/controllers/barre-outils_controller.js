import { Controller } from '@hotwired/stimulus';
import { buildCustomEventForElement, EVEN_ACTION_AJOUTER, EVEN_ACTION_COCHER_TOUT, EVEN_ACTION_DEVELOPPER, EVEN_ACTION_MODIFIER, EVEN_ACTION_NOTIFIER_SELECTION, EVEN_ACTION_PARAMETRER, EVEN_ACTION_QUITTER, EVEN_ACTION_RECHARGER, EVEN_ACTION_SUPPRIMER, EVEN_BARRE_OUTILS_INIT_REQUEST, EVEN_BARRE_OUTILS_INITIALIZED, EVEN_BOITE_DIALOGUE_INIT_REQUEST, EVEN_CODE_ACTION_AJOUT, EVEN_CODE_ACTION_MODIFICATION, EVEN_CODE_ACTION_SUPPRESSION, EVEN_LISTE_ELEMENT_CHECK_REQUEST, EVEN_LISTE_ELEMENT_DELETE_REQUEST, EVEN_LISTE_ELEMENT_EXPAND_REQUEST, EVEN_LISTE_ELEMENT_MODIFY_REQUEST, EVEN_LISTE_PRINCIPALE_ADD_REQUEST, EVEN_LISTE_PRINCIPALE_ALL_CHECK_REQUEST, EVEN_LISTE_PRINCIPALE_CLOSE_REQUEST, EVEN_LISTE_PRINCIPALE_REFRESH_REQUEST, EVEN_LISTE_PRINCIPALE_SETTINGS_REQUEST } from './base_controller.js';

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
        document.addEventListener(EVEN_BARRE_OUTILS_INIT_REQUEST, this.handleInitRequest.bind(this));
        document.addEventListener(EVEN_BARRE_OUTILS_INITIALIZED, this.handleInitialized.bind(this));
        
        
        
        // this.listePrincipale.addEventListener(EVEN_ACTION_NOTIFIER_SELECTION, this.handleSelectedItems.bind(this));
    }

    disconnect() {
        console.log(this.nomControleur + " - Déconnecté - Suppression d'écouteurs.");
        document.removeEventListener(EVEN_BARRE_OUTILS_INIT_REQUEST, this.handleInitRequest.bind(this));
        document.removeEventListener(EVEN_BARRE_OUTILS_INITIALIZED, this.handleInitialized.bind(this));
        
        
        
        
        // this.listePrincipale.removeEventListener(EVEN_ACTION_NOTIFIER_SELECTION, this.handleSelectedItems.bind(this));
    }

    handleInitRequest(event){
        console.log(this.nomControleur + " - HandleInitRequest");
    }

    handleInitialized(event){
        console.log(this.nomControleur + " - HandleInitialized");
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
    }

    /**
     * LES ACTIONS
     */
    action_quitte() {
        console.log(this.nomControleur + " - Action_Quitter");
        buildCustomEventForElement(document, EVEN_LISTE_PRINCIPALE_CLOSE_REQUEST, true, true, {});
    }

    action_parametrer() {
        console.log(this.nomControleur + " - Action_Parametrer");
        buildCustomEventForElement(document, EVEN_LISTE_PRINCIPALE_SETTINGS_REQUEST, true, true, {});
    }

    action_tout_cocher() {
        console.log(this.nomControleur + " - Action_tout_cocher");
        buildCustomEventForElement(document, EVEN_LISTE_PRINCIPALE_ALL_CHECK_REQUEST, true, true, {});
    }

    action_developper() {
        console.log(this.nomControleur + " - Action Développer");
        buildCustomEventForElement(document, EVEN_LISTE_ELEMENT_EXPAND_REQUEST, true, true, {
            selecttedCheckBox: this.tabSelectedCheckBoxs[0],
        });
    }

    action_recharger() {
        console.log(this.nomControleur + " - Action_Recharger");
        buildCustomEventForElement(document, EVEN_LISTE_PRINCIPALE_REFRESH_REQUEST, true, true, {});
    }

    action_ajouter() {
        console.log(this.nomControleur + " - Action_Ajouter");
        buildCustomEventForElement(document, EVEN_LISTE_PRINCIPALE_ADD_REQUEST, true, true, {
            titre: "Ajout",
            action: EVEN_CODE_ACTION_AJOUT,
        });
    }

    action_modifier() {
        console.log(this.nomControleur + " - Action_Modifier");
        buildCustomEventForElement(document, EVEN_LISTE_ELEMENT_MODIFY_REQUEST, true, true, {
            titre: "Modification",
            action: EVEN_CODE_ACTION_MODIFICATION,
            selectedCheckBox: this.tabSelectedCheckBoxs,
        });
    }

    action_supprimer() {
        console.log(this.nomControleur + " - Action_Supprimer");
        buildCustomEventForElement(document, EVEN_LISTE_ELEMENT_DELETE_REQUEST, true, true, {
            titre: "Suppression",
            action: EVEN_CODE_ACTION_SUPPRESSION,
            selectedCheckBoxes: this.tabSelectedCheckBoxs,
        });
    }
}