import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = [
        // Les boutons de la barre d'outils
        'btquitter',
        'btsettings',
        'btrecharger',
        'btajouter',
        'btmodifier',
        'btsupprimer',
    ];

    connect() {
        this.init();
    }

    init() {
        this.listePrincipale = document.getElementById("liste");
        this.controleurDeLaListePrincipale = this.getControleurListePrincipale();
        //On défini les écouteurs ici
        this.initialiserBarreDoutils();
        this.initToolTips();
    }

    getControleurListePrincipale() {
        if (this.listePrincipale) {
            return this.application.getControllerForElementAndIdentifier(this.listePrincipale, "liste-principale");
        }
        return null;
    }

    initToolTips() {
        //On initialise le tooltips
        const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
        const tooltipList = [...tooltipTriggerList].map(tooltipTriggerEl => new bootstrap.Tooltip(tooltipTriggerEl));
    }

    initialiserBarreDoutils() {
        this.btquitterTarget.style.display = "block";
        this.btsettingsTarget.style.display = "block";
        this.btajouterTarget.style.display = "block";
        this.btrechargerTarget.style.display = "block";
    }

    /**
     * LES ACTIONS
     */
    action_quitte() {
        console.log("Action_Quitter");
        this.buildCustomEvent(
            "app:liste-principale:quitter",
            true,
            true,
            {}
        );
    }

    action_parametrer() {
        console.log("Action_Parametrer");
        this.buildCustomEvent(
            "app:liste-principale:parametrer",
            true,
            true,
            {}
        );
    }

    action_recharger() {
        console.log("Action_Recharger");
        this.buildCustomEvent(
            "app:liste-principale:recharger",
            true,
            true,
            {}
        );
    }

    action_ajouter() {
        console.log("Action_Ajouter");
        this.buildCustomEvent("app:liste-principale:ajouter", 
            true, 
            true,
            {
                titre: "Nouvelle notification",
            }
        );
    }

    action_modifier() {
        console.log("Action_Modifier");
        this.buildCustomEvent("app:liste-principale:modifier", 
            true, 
            true,
            {
                titre: "Modification de la notification",
            }
        );
    }

    action_supprimer() {
        console.log("Action_Supprimer");
        this.buildCustomEvent("app:liste-principale:supprimer", 
            true, 
            true,
            {
                titre: "Modification de la notification",
            }
        );
    }

    buildCustomEvent(nomEvent, canBubble, canCompose, detailTab) {
        const event = new CustomEvent(nomEvent, {
            bubbles: canBubble,
            composed: canCompose,
            detail: detailTab
        });
        this.listePrincipale.dispatchEvent(event);
    }
}