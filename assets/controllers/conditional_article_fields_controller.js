import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['revenu', 'tranche', 'commission', 'retrocommission', 'taxe'];

    connect() {
        console.log("🟢 [ArticleFields] Connecté. Initialisation de la surveillance...");
        
        // Stockage de la dernière valeur connue pour ne déclencher l'affichage que s'il y a un vrai changement
        this.lastRevenuValue = null;
        
        // Failsafe 1 : Écoute globale sur tout le formulaire (Event Delegation)
        this.element.addEventListener('change', () => this.checkAndUpdate());
        
        // Failsafe 2 : Vérification périodique légère (Polling). 
        // Utile car TomSelect/Symfony UX absorbe souvent les événements natifs 'change'.
        this.pollingInterval = setInterval(() => {
            this.checkAndUpdate();
        }, 500);

        // Première vérification au chargement
        setTimeout(() => this.checkAndUpdate(), 100);
    }

    disconnect() {
        // Nettoyage de l'intervalle lorsque le contrôleur/la modale est détruit
        if (this.pollingInterval) {
            clearInterval(this.pollingInterval);
        }
    }

    // --- Helpers robustes pour retrouver les champs même si Symfony UX a effacé les data-targets ---
    getRevenuElement() { 
        return this.hasRevenuTarget ? this.revenuTarget : this.element.querySelector('[name*="[typeRevenu]"]'); 
    }
    getTrancheElement() { 
        return this.hasTrancheTarget ? this.trancheTarget : this.element.querySelector('[name*="[tranche]"]'); 
    }
    getCommissionElement() { 
        return this.hasCommissionTarget ? this.commissionTarget : this.element.querySelector('[name*="[tauxCommission]"]'); 
    }
    getRetrocommissionElement() { 
        return this.hasRetrocommissionTarget ? this.retrocommissionTarget : this.element.querySelector('[name*="[tauxRetrocommission]"]'); 
    }
    getTaxeElement() { 
        return this.hasTaxeTarget ? this.taxeTarget : this.element.querySelector('[name*="[taxe]"]'); 
    }

    /**
     * Vérifie la valeur actuelle et met à jour l'UI si nécessaire
     */
    checkAndUpdate() {
        const revEl = this.getRevenuElement();
        if (!revEl) {
            // Silencieux car le champ peut ne pas encore être dans le DOM
            return;
        }

        // Si c'est un wrapper TomSelect, trouver le select/input sous-jacent
        let actualInput = (revEl.tagName === 'SELECT' || revEl.tagName === 'INPUT') 
            ? revEl 
            : revEl.querySelector('select, input') || revEl;
            
        let currentValue = actualInput.value;

        // Optimisation : On ne fait rien si la valeur est identique à la précédente
        if (this.lastRevenuValue === currentValue) {
            return;
        }
        
        this.lastRevenuValue = currentValue;

        // Validation de la présence du revenu
        const hasRevenu = currentValue !== undefined && currentValue !== null && String(currentValue).trim() !== "";
        console.log(`🔍 [ArticleFields] Changement détecté ! Nouveau Revenu: "${currentValue}" -> Afficher les champs conditionnels: ${hasRevenu}`);

        // Mise à jour de chaque champ
        this.toggleField('Tranche', this.getTrancheElement(), hasRevenu);
        this.toggleField('Commission', this.getCommissionElement(), hasRevenu);
        this.toggleField('Rétrocommission', this.getRetrocommissionElement(), hasRevenu);
        this.toggleField('Taxe', this.getTaxeElement(), hasRevenu);
    }

    /**
     * Utilitaire pour afficher/masquer un élément et son conteneur
     */
    toggleField(name, element, show) {
        if (!element) return;

        // Remonter de manière sécurisée au bon conteneur Bootstrap
        let container = element.closest('.mb-3') || element.closest('.form-group') || element.closest('.row') || element.parentElement;
        
        if (show) {
            if (container.classList.contains('d-none')) {
                console.log(`👁️ [ArticleFields] Apparition de : ${name}`);
                container.classList.remove('d-none');
                
                // CORRECTIF TomSelect: force le redessin car largeur bloquée à 0px quand caché
                setTimeout(() => window.dispatchEvent(new Event('resize')), 50);
            }
        } else {
            if (!container.classList.contains('d-none')) {
                console.log(`🙈 [ArticleFields] Masquage de : ${name}`);
                container.classList.add('d-none');
            }
        }
    }
}