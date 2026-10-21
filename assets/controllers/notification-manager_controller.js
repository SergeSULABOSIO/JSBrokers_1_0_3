// assets/controllers/notification-manager_controller.js
import { Controller } from '@hotwired/stimulus';
import { Toast } from 'bootstrap'; // Important: importer Toast
import { regionDuMessage, delaiDuMessage, messageEnDouble, erreursARetirer } from './notification-regles.js';

export default class extends Controller {
    // Les deux régions d'annonce, présentes dès le chargement (base.html.twig).
    static targets = ['statut', 'alerte'];

    connect() {
        this.nomControleur = "NOTIFICATION-MANAGER";
        // --- CORRECTION : Lier la méthode une seule fois et écouter l'événement diffusé par le cerveau ---
        this.boundShow = this.show.bind(this);
        document.addEventListener('app:notification.show', this.boundShow);
    }

    disconnect() {
        // --- CORRECTION : Nettoyer le bon écouteur ---
        document.removeEventListener('app:notification.show', this.boundShow);
    }

    show(event) {
        console.log(this.nomControleur + " - Show", event.detail);
        const { text, type = 'info' } = event.detail;

        // Map pour associer un type à une couleur (contextuelle), une icône et
        // un titre d'en-tête. La structure (en-tête + corps) est commune à tous
        // les toasts (cf. .jsb-toast) ; seule la COULEUR varie selon le type.
        const options = {
            success: { bg: 'bg-success', text: 'text-white', icon: '✅', title: 'Succès' },
            error: { bg: 'bg-danger', text: 'text-white', icon: '❌', title: 'Erreur' },
            info: { bg: 'bg-info', text: 'text-dark', icon: 'ℹ️', title: 'Information' },
            warning: { bg: 'bg-warning', text: 'text-dark', icon: '⚠️', title: 'Avertissement' }
        };

        const config = options[type] || options.info;

        // DÉJÀ À L'ÉCRAN, À L'IDENTIQUE : on ne l'empile pas une seconde fois (une même panne
        // répétée, réseau coupé, ne doit pas couvrir l'écran du même texte).
        const affiches = [...this.element.querySelectorAll('.toast.show, .toast.showing')].map((t) => ({
            type: t.dataset.type, texte: t.querySelector('.toast-body')?.firstChild?.textContent ?? '',
        }));
        if (messageEnDouble({ type, texte: text ?? '' }, affiches)) return;

        // AU PLUS TROIS ERREURS : elles ne partent plus seules — la plus ancienne cède la place.
        if (type === 'error' && this.hasAlerteTarget) {
            const erreurs = [...this.alerteTarget.querySelectorAll('.toast')];
            erreurs.slice(0, erreursARetirer(erreurs.length)).forEach((t) => t.remove());
        }

        // Détermine la classe du bouton de fermeture en fonction du fond
        const closeButtonClass = (config.text === 'text-white') ? 'btn-close-white' : '';

        // Création dynamique de l'HTML du toast — même structure que le toast
        // « Paramètres de l'espace de travail » : en-tête (icône + titre) + corps.
        const toastHTML = `
            <div class="toast jsb-toast ${config.bg} ${config.text} bg-opacity-75" aria-atomic="true" data-type="${type}">
                <div class="toast-header">
                    <span aria-hidden="true">${config.icon}</span>
                    <strong class="ms-2 me-auto">${config.title}</strong>
                    <button type="button" class="btn-close ${closeButtonClass}" data-bs-dismiss="toast" aria-label="Fermer"></button>
                </div>
                <div class="toast-body"></div>
            </div>
        `;

        // Inséré dans SA région d'annonce : `alert` pour une erreur, `status` sinon. Le toast
        // n'est plus lui-même une région (ils étaient imbriqués, et tout sonnait l'alarme).
        const region = regionDuMessage(type) === 'alerte'
            ? (this.hasAlerteTarget ? this.alerteTarget : this.element)
            : (this.hasStatutTarget ? this.statutTarget : this.element);
        region.insertAdjacentHTML('beforeend', toastHTML);

        const newToastEl = region.lastElementChild;

        // LE MESSAGE EST DU TEXTE, posé par textContent — jamais interpolé dans le HTML
        // ci-dessus. Il cite des noms saisis (client, échéance, fichier, référence de
        // police…) et des refus du serveur : en HTML, un nom piégé s'exécutait au
        // premier toast. Aucun appelant ne compte sur une balise (audit du 2026-10-16).
        newToastEl.querySelector('.toast-body').textContent = text ?? '';

        // UN GESTE, FACULTATIF : `action = { label, evenement: { type, payload } }`. Le bouton
        // renvoie l'événement au cerveau — le toast ne fait rien lui-même. Construit par le
        // DOM, jamais par chaîne : le libellé n'est pas du HTML.
        const action = event.detail.action;
        if (action?.label && action.evenement?.type) {
            const bouton = document.createElement('button');
            bouton.type = 'button';
            bouton.className = `btn btn-sm ${config.text === 'text-white' ? 'btn-light' : 'btn-dark'} mt-2 d-block`;
            bouton.textContent = action.label;
            bouton.addEventListener('click', () => {
                bouton.dispatchEvent(new CustomEvent('cerveau:event', {
                    bubbles: true,
                    detail: { type: action.evenement.type, source: this.nomControleur, payload: action.evenement.payload || {}, timestamp: Date.now() },
                }));
                Toast.getOrCreateInstance(newToastEl).hide();
            });
            newToastEl.querySelector('.toast-body').appendChild(bouton);
        }

        // Une ERREUR reste jusqu'à ce qu'on la ferme ; les autres 10 s, 20 s avec un geste à
        // faire. Le minuteur s'arrête au survol et au focus (Toast de Bootstrap).
        const delai = delaiDuMessage(type, !!action);
        const toast = new Toast(newToastEl, delai === null ? { autohide: false } : { delay: delai });

        // Supprimer le toast du DOM une fois qu'il est caché pour garder le HTML propre
        newToastEl.addEventListener('hidden.bs.toast', () => {
            newToastEl.remove();
        });

        toast.show();
    }
}