import { Controller } from '@hotwired/stimulus';

/**
 * L'INFOBULLE DU VOYANT DE DÉMARRAGE.
 *
 * ── POURQUOI LE PANNEAU EST DÉPLACÉ SOUS <BODY> ─────────────────────────────────────
 * La colonne 1 mesure 130 px et défile (`overflow-y: auto`) : un panneau rendu à
 * l'intérieur y serait rogné, et sur écran étroit la colonne devient un tiroir en
 * `position: fixed` qui le clipperait tout autant. On le sort donc du flux et on le
 * positionne en `fixed`, comme le fait déjà le panneau flottant de la colonne 2.
 *
 * ── POURQUOI LA FERMETURE EST DIFFÉRÉE ──────────────────────────────────────────────
 * Ce panneau porte un BOUTON. Une fermeture immédiate au `mouseleave` le rendrait
 * inatteignable : le pointeur doit traverser le vide entre l'item et le panneau. Le délai
 * est annulé dès que le pointeur entre dans le panneau — c'est l'idiome
 * `annulerFermetureFlyout` de la colonne 2.
 *
 * Le contenu, lui, est rendu par le SERVEUR dans un <template> : aucune requête au survol,
 * aucun risque de voir l'infobulle annoncer un chiffre différent de celui du voyant.
 */
export default class extends Controller {
    static targets = ['modele'];
    static values = { autoOuvrir: Boolean };

    /** Laisse le temps de traverser le vide entre l'item et le panneau. */
    static DELAI_FERMETURE = 220;

    connect() {
        this.panneau = null;
        this.minuterie = null;

        // WCAG 1.4.13 « Content on Hover or Focus » exige qu'un contenu apparu au survol
        // soit DISMISSIBLE sans déplacer le pointeur. Les deux autres conditions étaient
        // déjà remplies — il est survolable (le survol annule sa fermeture) et persistant
        // (il ne disparaît pas tout seul) — celle-ci manquait.
        this.boundEchap = (e) => {
            if (e.key === 'Escape' && this.panneau) {
                this.retirer();
            }
        };
        document.addEventListener('keydown', this.boundEchap);

        // Le lien du courriel de synthèse porte `?onboarding=1` : on ouvre alors le guide
        // de nous-mêmes, par le MÊME clic que l'utilisateur aurait fait. Rendre une seconde
        // fois les cartes ailleurs dans la page aurait dupliqué tout le panneau pour un
        // seul cas d'entrée.
        //
        // Différé d'un tour de boucle : `workspace-manager` doit être connecté pour
        // recevoir l'action, et Stimulus ne garantit pas l'ordre de connexion.
        if (this.autoOuvrirValue) {
            window.setTimeout(() => this.element.click(), 0);
        }
    }

    disconnect() {
        document.removeEventListener('keydown', this.boundEchap);
        this.retirer();
    }

    ouvrir() {
        this.annulerFermeture();
        if (this.panneau || !this.hasModeleTarget) return;

        const fragment = this.modeleTarget.content.cloneNode(true);
        this.panneau = fragment.firstElementChild;
        if (!this.panneau) return;

        // Le survol du panneau annule sa propre fermeture : sans cela, on ne pourrait
        // jamais atteindre son bouton.
        this.panneau.addEventListener('mouseenter', () => this.annulerFermeture());
        this.panneau.addEventListener('mouseleave', () => this.fermerDiffere());

        // Le panneau vit sous <body>, hors de toute portée Stimulus : son bouton ne peut
        // donc pas porter de `data-action`. On rejoue le clic sur le voyant lui-même, qui
        // est bien dans la portée du workspace et sait ouvrir le guide — une seule
        // définition de « ouvrir le guide », pas deux.
        const cta = this.panneau.querySelector('[data-onboarding-cta]');
        if (cta) {
            cta.addEventListener('click', () => {
                this.retirer();
                this.element.click();
            });
        }

        document.body.appendChild(this.panneau);
        this.positionner();
    }

    fermerDiffere() {
        this.annulerFermeture();
        this.minuterie = window.setTimeout(() => this.retirer(), this.constructor.DELAI_FERMETURE);
    }

    annulerFermeture() {
        if (this.minuterie !== null) {
            window.clearTimeout(this.minuterie);
            this.minuterie = null;
        }
    }

    retirer() {
        this.annulerFermeture();
        if (this.panneau) {
            this.panneau.remove();
            this.panneau = null;
        }
    }

    /**
     * Ancré sur l'item, à sa droite, et remonté juste ce qu'il faut pour ne pas déborder
     * en bas de l'écran — le voyant siège au pied de la colonne.
     */
    positionner() {
        const ancre = this.element.getBoundingClientRect();
        const marge = 8;

        this.panneau.style.position = 'fixed';
        this.panneau.style.left = `${ancre.right + marge}px`;
        this.panneau.style.visibility = 'hidden';
        this.panneau.style.top = '0px';

        // Mesuré une fois posé : la hauteur dépend du nombre d'étapes.
        const hauteur = this.panneau.getBoundingClientRect().height;
        const haut = Math.max(marge, Math.min(ancre.bottom - hauteur, window.innerHeight - hauteur - marge));

        this.panneau.style.top = `${haut}px`;
        this.panneau.style.visibility = '';
    }
}
