import { Controller } from '@hotwired/stimulus';

/**
 * @class ConsoleContextMenuController
 * @extends Controller
 * @description Menu contextuel (clic droit) générique pour toutes les listes de la
 * Console. Il remplace les colonnes « Actions » : chaque ligne conserve ses
 * boutons/formulaires d'action d'origine, mais rangés dans une cellule masquée
 * (`[data-cm-actions]`). Au clic droit sur une ligne (`[data-cm-row]`), on lit ces
 * éléments et on les reflète dans un menu sombre unique, puis on rejoue le clic natif
 * sur l'élément d'origine — de sorte que **toute la logique métier existante**
 * (routes, jetons CSRF, contrôleur `confirm-action`, `target=_blank`, etc.) reste
 * strictement inchangée.
 *
 * Un seul menu et un seul contrôleur pour toute la page (posé sur `<main.cs-page>`),
 * conformément aux principes DRY/KISS.
 *
 * Accessibilité (WCAG 2.1.1) : les lignes concernées sont rendues focusables et le
 * menu s'ouvre aussi au clavier (touche Menu / Maj+F10, ou Entrée/Espace sur la
 * ligne focalisée). Navigation flèches, activation Entrée, fermeture Échap avec
 * retour du focus sur la ligne déclencheuse.
 */
export default class extends Controller {
    static targets = ['menu'];

    connect() {
        this.originRow = null;

        this.boundOnContextMenu = this.onContextMenu.bind(this);
        this.boundOnRowKeydown = this.onRowKeydown.bind(this);
        this.boundOnDocPointer = this.onDocumentPointer.bind(this);
        this.boundOnMenuKeydown = this.onMenuKeydown.bind(this);

        // Dès que la souris est utilisée dans le menu, on retire le focus clavier
        // éventuel : seul l'item réellement survolé (:hover) reste surligné, ce qui
        // évite un double surlignage (1er item focalisé + item survolé).
        this.boundClearKeyboardFocus = () => {
            const active = document.activeElement;
            if (active && active.getAttribute?.('role') === 'menuitem' && this.menuTarget.contains(active)) {
                active.blur();
            }
        };

        this.element.addEventListener('contextmenu', this.boundOnContextMenu);
        this.element.addEventListener('keydown', this.boundOnRowKeydown);
        this.menuTarget.addEventListener('mousemove', this.boundClearKeyboardFocus);
        document.addEventListener('pointerdown', this.boundOnDocPointer, true);
        document.addEventListener('scroll', this.boundHideOnScroll = () => this.hide(), true);
        window.addEventListener('resize', this.boundHideOnResize = () => this.hide());

        this.prepareRows();
        this.hide();
    }

    disconnect() {
        this.element.removeEventListener('contextmenu', this.boundOnContextMenu);
        this.element.removeEventListener('keydown', this.boundOnRowKeydown);
        this.menuTarget.removeEventListener('mousemove', this.boundClearKeyboardFocus);
        document.removeEventListener('pointerdown', this.boundOnDocPointer, true);
        document.removeEventListener('scroll', this.boundHideOnScroll, true);
        window.removeEventListener('resize', this.boundHideOnResize);
    }

    /**
     * Rend chaque ligne actionnable focusable et découvrable (curseur + indice ARIA).
     * Fait par le contrôleur (et non dans les gabarits) pour rester DRY.
     */
    prepareRows() {
        this.element.querySelectorAll('[data-cm-row]').forEach((row) => {
            if (row.dataset.cmReady) return;
            row.dataset.cmReady = '1';
            row.classList.add('cs-cm-row');
            if (!row.hasAttribute('tabindex')) row.setAttribute('tabindex', '0');
            row.setAttribute('aria-haspopup', 'menu');
            if (!row.getAttribute('title')) {
                row.setAttribute('title', 'Clic droit (ou touche Menu) pour les actions');
            }
        });
    }

    onContextMenu(event) {
        const row = event.target.closest('[data-cm-row]');
        if (!row || !this.element.contains(row)) return;

        const items = this.collectActions(row);
        event.preventDefault();
        this.open(row, items, { x: event.clientX, y: event.clientY }, false);
    }

    onRowKeydown(event) {
        // Ouverture au clavier : Entrée / Espace sur la ligne focalisée.
        if (event.key !== 'Enter' && event.key !== ' ') return;
        const row = event.target.closest('[data-cm-row]');
        // On n'intercepte que lorsque la ligne elle-même a le focus (pas un lien interne).
        if (!row || document.activeElement !== row) return;

        event.preventDefault();
        const items = this.collectActions(row);
        const rect = row.getBoundingClientRect();
        this.open(row, items, { x: rect.left + 24, y: rect.top + rect.height - 4 }, true);
    }

    /**
     * Extrait les actions d'une ligne depuis sa cellule masquée `[data-cm-actions]`.
     * On cible les vrais éléments cliquables (`a[href]`, `button`) ; les cliquer rejoue
     * exactement le comportement d'origine.
     * @returns {Array<{el: HTMLElement, label: string, danger: boolean, svg: SVGElement|null}>}
     */
    collectActions(row) {
        const cell = row.querySelector('[data-cm-actions]');
        if (!cell) return [];

        const nodes = cell.querySelectorAll('a[href], button');
        const items = [];
        nodes.forEach((el) => {
            if (el.disabled) return;
            const label = (el.dataset.cmLabel
                || el.getAttribute('title')
                || el.textContent.trim()
                || 'Action').replace(/\s+/g, ' ');
            const danger = el.classList.contains('is-danger')
                || el.classList.contains('btn-outline-danger')
                || el.hasAttribute('data-cm-danger');
            items.push({ el, label, danger, svg: el.querySelector('svg') });
        });
        return items;
    }

    open(row, items, position, viaKeyboard = false) {
        this.originRow = row;
        const menu = this.menuTarget;
        menu.innerHTML = '';

        if (items.length === 0) {
            const empty = document.createElement('li');
            empty.className = 'cs-cm-empty';
            empty.setAttribute('aria-disabled', 'true');
            empty.textContent = 'Aucune action disponible';
            menu.appendChild(empty);
        } else {
            items.forEach((item) => menu.appendChild(this.buildItem(item)));
        }

        this.display(position);

        // Focus initial UNIQUEMENT à l'ouverture clavier. À la souris, on laisse
        // le seul :hover surligner l'item réellement sous le curseur.
        if (viaKeyboard) {
            const first = menu.querySelector('[role="menuitem"]');
            if (first) first.focus();
        }
    }

    buildItem(item) {
        const li = document.createElement('li');
        li.setAttribute('role', 'menuitem');
        li.setAttribute('tabindex', '-1');
        if (item.danger) li.classList.add('is-danger');

        const iconSpan = document.createElement('span');
        iconSpan.className = 'context-menu-icon';
        iconSpan.setAttribute('aria-hidden', 'true');
        if (item.svg) {
            const clone = item.svg.cloneNode(true);
            clone.style.color = 'inherit';
            clone.setAttribute('width', '18');
            clone.setAttribute('height', '18');
            iconSpan.appendChild(clone);
        }
        li.appendChild(iconSpan);

        const labelSpan = document.createElement('span');
        labelSpan.textContent = item.label;
        labelSpan.style.flex = '1 1 auto';
        li.appendChild(labelSpan);

        // Rejoue le clic natif sur l'élément d'origine (déclenche confirm-action,
        // top-progress, soumission de formulaire, navigation, etc.).
        li.addEventListener('click', (e) => {
            e.preventDefault();
            this.hide();
            item.el.click();
        });
        return li;
    }

    display(position) {
        const menu = this.menuTarget;
        menu.style.visibility = 'hidden';
        menu.style.display = 'block';

        const w = menu.offsetWidth;
        const h = menu.offsetHeight;
        const vw = window.innerWidth;
        const vh = window.innerHeight;
        const left = position.x + w > vw ? Math.max(5, vw - w - 5) : position.x;
        const top = position.y + h > vh ? Math.max(5, vh - h - 5) : position.y;

        menu.style.left = `${left}px`;
        menu.style.top = `${top}px`;
        menu.style.visibility = 'visible';

        document.addEventListener('keydown', this.boundOnMenuKeydown, true);
    }

    hide() {
        if (this.hasMenuTarget) {
            this.menuTarget.style.display = 'none';
        }
        document.removeEventListener('keydown', this.boundOnMenuKeydown, true);
    }

    onDocumentPointer(event) {
        if (this.hasMenuTarget
            && this.menuTarget.style.display === 'block'
            && !this.menuTarget.contains(event.target)) {
            this.hide();
        }
    }

    onMenuKeydown(event) {
        if (this.menuTarget.style.display !== 'block') return;
        const items = Array.from(this.menuTarget.querySelectorAll('[role="menuitem"]'));
        if (items.length === 0 && event.key !== 'Escape') return;
        const current = items.indexOf(document.activeElement);

        switch (event.key) {
            case 'Escape':
                event.preventDefault();
                this.hide();
                if (this.originRow) this.originRow.focus();
                break;
            case 'ArrowDown':
                event.preventDefault();
                items[(current + 1) % items.length]?.focus();
                break;
            case 'ArrowUp':
                event.preventDefault();
                items[(current - 1 + items.length) % items.length]?.focus();
                break;
            case 'Home':
                event.preventDefault();
                items[0]?.focus();
                break;
            case 'End':
                event.preventDefault();
                items[items.length - 1]?.focus();
                break;
            case 'Enter':
            case ' ':
                event.preventDefault();
                document.activeElement?.click();
                break;
        }
    }
}
