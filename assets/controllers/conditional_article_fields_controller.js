import { Controller } from '@hotwired/stimulus';

/*
 * Contrôleur Stimulus pour afficher/masquer dynamiquement 
 * le champ Tranche dans ArticleType.php en fonction du Revenu
 */
export default class extends Controller {
    
    connect() {
        // Exécuter au chargement pour masquer la tranche si le revenu est vide
        // Un léger délai pour laisser le temps à TomSelect de se construire
        setTimeout(() => this.toggle(), 100);

        // CORRECTION : Utilisation de name*="[revenuFacture]" pour contourner le nommage des collections (note[articles][0][revenuFacture])
        const revenuSelect = this.element.querySelector('select[name*="[revenuFacture]"]');
        if (revenuSelect) {
            // On écoute l'événement 'change' déclenché par TomSelect
            revenuSelect.addEventListener('change', () => this.toggle());
        }
    }

    toggle() {
        const revenuSelect = this.element.querySelector('select[name*="[revenuFacture]"]');
        if (!revenuSelect) return;

        // On vérifie si l'utilisateur a sélectionné quelque chose
        const hasRevenu = revenuSelect.value && revenuSelect.value !== '';

        // CORRECTION : On ne cible que la tranche (les montants et taxes doivent rester accessibles même sans revenu)
        const dependentFields = ['tranche'];

        dependentFields.forEach(fieldName => {
            // Sélecteur adapté aux collections
            const fieldInput = this.element.querySelector(`[name*="[${fieldName}]"]`);
            
            if (fieldInput) {
                // On remonte à la balise de grille (div col-) générée par le système Canvas 
                const container = fieldInput.closest('div[class*="col-"]');
                if (container) {
                    // Affiche ou masque la ligne complète dynamiquement
                    container.style.display = hasRevenu ? 'block' : 'none';
                }
            }
        });
    }
}