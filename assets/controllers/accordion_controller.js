import { Controller } from '@hotwired/stimulus';

/**
 * @class AccordionController
 * @extends Controller
 * @description Gère un composant accordéon, y compris le filtrage et le basculement des sections.
 */
export default class extends Controller {
    static targets = ["item", "searchInput", "noResultsMessage"];

    connect() {
        this.nomControleur = "Accordion";
    }

    /**
     * Filtre les éléments de l'accordéon en fonction de la saisie de l'utilisateur.
     * Applique un surlignage sur le terme recherché.
     * @param {InputEvent} event
     */
    filter(event) {
        const input = event.currentTarget;
        const searchTerm = input.value.trim().toLowerCase();
        let visibleCount = 0;

        this.itemTargets.forEach(item => {
            const titleElement = item.querySelector('.accordion-title');
            if (!titleElement) return;

            const toggleIcon = titleElement.querySelector('.accordion-toggle');
            const iconHtml = toggleIcon ? toggleIcon.outerHTML : '';

            // Stocke le titre original une seule fois pour la performance
            if (!titleElement.dataset.originalTitle) {
                const tempClone = titleElement.cloneNode(true);
                tempClone.querySelector('.accordion-toggle')?.remove();
                titleElement.dataset.originalTitle = tempClone.textContent.trim();
            }

            const originalTitleText = titleElement.dataset.originalTitle;

            if (originalTitleText.toLowerCase().includes(searchTerm)) {
                item.style.display = '';
                visibleCount++;

                if (searchTerm) {
                    const regex = new RegExp(searchTerm.replace(/[-\/\\^$*+?.()|[\]{}]/g, '\\$&'), 'gi');
                    const highlightedText = originalTitleText.replace(regex, `<strong class="search-highlight">$&</strong>`);
                    titleElement.innerHTML = `${iconHtml} ${highlightedText}`;
                } else {
                    titleElement.innerHTML = `${iconHtml} ${originalTitleText}`;
                }
            } else {
                item.style.display = 'none';
            }
        });

        if (this.hasNoResultsMessageTarget) {
            this.noResultsMessageTarget.style.display = visibleCount === 0 ? 'block' : 'none';
        }
    }

    /**
     * Réinitialise le champ de recherche et le filtre de l'accordéon.
     */
    resetFilter() {
        if (this.hasSearchInputTarget) {
            this.searchInputTarget.value = '';
            this.searchInputTarget.dispatchEvent(new Event('input'));
        }
    }

    /**
     * Bascule l'affichage du contenu d'un item d'accordéon (ouvert/fermé).
     * @param {MouseEvent} event
     */
    toggle(event) {
        const title = event.currentTarget;
        const content = title.nextElementSibling;
        const toggleIcon = title.querySelector('.accordion-toggle');

        if (!content || !toggleIcon) return;

        const isOpen = content.classList.contains('open');

        if (isOpen) {
            content.classList.remove('open');
            toggleIcon.textContent = '+';
        } else {
            content.classList.add('open');
            toggleIcon.textContent = '-';
        }
    }
}