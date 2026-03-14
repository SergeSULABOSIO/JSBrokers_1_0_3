import { Controller } from '@hotwired/stimulus';

/*
 * Contrôleur infaillible pour la dépendance Revenu -> Tranche
 * Gère les modales, les collections Symfony, et le cache TomSelect.
 */
export default class extends Controller {
    static targets = ["formRow"];

    connect() {
        // 1. ASTUCE MAGIQUE : Trouver le champ Revenu EXACT de cette ligne de collection
        // Si le nom est "note[articles][0][tranche]", on cherche "note[articles][0][revenuFacture]"
        const revenuName = this.element.name.replace(/tranche/, 'revenuFacture');
        
        // On remonte au formulaire parent (qui est dans la modale) pour chercher le champ
        this.revenuSelect = this.element.closest('form').querySelector(`select[name="${revenuName}"]`);

        if (this.revenuSelect) {
            // On écoute le changement du Revenu
            this.revenuSelect.addEventListener('change', () => this.handleRevenuChange());
            
            // On exécute immédiatement (avec un micro-délai pour que TomSelect soit prêt dans la modale)
            setTimeout(() => this.handleRevenuChange(), 50);
        }

        // Sécurité : Mettre à jour l'URL quand on interagit avec la Tranche
        this.element.addEventListener('focus', () => this.updateUrl());
        this.element.addEventListener('mouseenter', () => this.updateUrl());
    }

    handleRevenuChange() {
        if (!this.revenuSelect) return;

        const revenuId = this.revenuSelect.value;
        const hasRevenu = (revenuId && revenuId !== '');

        // 1. GESTION DE L'AFFICHAGE (Masquer si aucun revenu)
        // CORRECTION : On utilise la cible explicite, c'est 100% fiable.
        if (this.hasFormRowTarget) {
            this.formRowTarget.classList.toggle('d-none', !hasRevenu);
        }

        // 2. VIDAGE DU CHAMP TRANCHE
        // Si on change/retire le revenu, il faut obligatoirement vider la tranche
        if (this.element.tomselect) {
            this.element.tomselect.clear(true); // true = silencieux
            // On vide aussi le cache de TomSelect pour l'obliger à refaire une requête AJAX !
            this.element.tomselect.clearOptions(); 
        } else {
            this.element.value = '';
        }

        // 3. MISE À JOUR DE L'URL AJAX
        this.updateUrl();
    }

    updateUrl() {
        if (!this.revenuSelect) return;
        
        const revenuId = this.revenuSelect.value;

        // Détection de l'attribut contenant l'URL AJAX généré par Symfony UX
        let urlAttr = this.element.hasAttribute('data-symfony--ux-autocomplete--autocomplete-url-value') 
            ? 'data-symfony--ux-autocomplete--autocomplete-url-value' 
            : (this.element.hasAttribute('data-autocomplete-url-value') ? 'data-autocomplete-url-value' : null);

        if (!urlAttr) return;

        // On sauvegarde l'URL originale la première fois
        if (!this.baseUrl) {
            this.baseUrl = this.element.getAttribute(urlAttr);
        }

        try {
            const url = new URL(this.baseUrl, window.location.origin);
            
            if (revenuId && revenuId !== '') {
                url.searchParams.set('live_revenu_id', revenuId);
            } else {
                url.searchParams.delete('live_revenu_id');
            }
            
            // On injecte la nouvelle URL dans le DOM (TomSelect l'utilisera pour sa prochaine requête)
            this.element.setAttribute(urlAttr, url.toString());
            
        } catch (e) {
            console.error("[TrancheAutocomplete DEBUG] Erreur de mise à jour de l'URL:", e);
        }
    }
}