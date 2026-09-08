import { Controller } from '@hotwired/stimulus';

/**
 * LE GUIDE DE DÉMARRAGE — il n'ouvre que des dialogues.
 *
 * ── POURQUOI CE CONTRÔLEUR EST SI COURT ─────────────────────────────────────────────
 * Il ne construit aucun formulaire et ne connaît aucune entité. Le serveur lui a remis,
 * en un seul attribut, le CANEVAS de dialogue de chaque étape ; il se contente de le
 * tendre au gestionnaire de dialogues, qui ouvre exactement celui de la rubrique. Un
 * canevas suffit — ni liste, ni onglet actif —, et le contexte porte lui-même
 * l'entreprise et l'invité, dont le Cerveau se sert pour aller chercher le formulaire.
 *
 * ── UN SEUL CHEMIN POUR AJOUTER ET POUR MODIFIER ────────────────────────────────────
 * La présence d'un `id` dans les paramètres du clic suffit à basculer en édition : le
 * Cerveau suffixe alors `/{id}` sur l'URL du formulaire. Deux méthodes auraient fini par
 * diverger sur le contexte transmis.
 *
 * ⚠ ET C'EST POURQUOI ON N'EMPRUNTE PAS `ui:toolbar.add-request`. Ce raccourci semblait
 * tout indiqué — c'est celui du bouton « Ajouter » des rubriques — mais son gestionnaire
 * écrit `entity: {}` et `isCreationMode: true` EN DUR (cerveau_controller.js, l. 262-268).
 * Un clic sur une ligne existante y aurait ouvert un formulaire vide, sans la moindre
 * erreur : l'utilisateur aurait cru modifier et aurait créé un doublon. On émet donc
 * directement `app:boite-dialogue:init-request`, l'événement que le Cerveau diffuse
 * lui-même au bout de ce chemin (précédents : assets/app.js et bordereau-analysis).
 *
 * ── LE RAFRAÎCHISSEMENT ─────────────────────────────────────────────────────────────
 * ⚠ Il n'existe AUCUN événement DOM nommé `app:entity.saved`. Le dialogue émet un
 * `cerveau:event` dont le `detail.type` porte ce nom : c'est donc `cerveau:event` qu'on
 * écoute, en filtrant sur le type. On ne réagit qu'à nos propres dialogues, reconnus au
 * drapeau `_onboardingReload` (même patron que `_dashboardReload` et `_soaReload`). Le
 * préfixe `_` n'est pas décoratif : `dialog-instance` recopie TOUTES les clés du contexte
 * dans le FormData de la soumission, et le serveur ignore celles-là.
 */
export default class extends Controller {
    static values = {
        canvas: Object,
        contexte: Object,
        url: String,
    };

    connect() {
        this.boundSurEnregistrement = this.surEnregistrement.bind(this);
        document.addEventListener('cerveau:event', this.boundSurEnregistrement);
    }

    disconnect() {
        document.removeEventListener('cerveau:event', this.boundSurEnregistrement);
    }

    /**
     * Ouvre le dialogue de l'étape : création si aucun `id`, édition sinon.
     */
    ouvrirDialogue(event) {
        const cle = event.params?.cle;
        const id = Number.parseInt(event.params?.id, 10);

        const formCanvas = this.canvasValue?.[cle];
        if (!formCanvas || !formCanvas.parametres?.endpoint_form_url) {
            // Une étape dont le canevas n'est pas arrivé n'ouvre rien plutôt que d'ouvrir
            // un dialogue vide, que l'utilisateur croirait cassé sans savoir pourquoi.
            return;
        }

        const estCreation = !Number.isInteger(id) || id <= 0;

        document.dispatchEvent(new CustomEvent('app:loading.start'));
        document.dispatchEvent(new CustomEvent('app:boite-dialogue:init-request', {
            bubbles: true,
            detail: {
                entityFormCanvas: formCanvas,
                entity: estCreation ? {} : { id },
                isCreationMode: estCreation,
                context: {
                    originatorId: 'onboarding',
                    idEntreprise: this.contexteValue?.idEntreprise,
                    idInvite: this.contexteValue?.idInvite,
                    _onboardingReload: true,
                },
                // Aucun parent : une étape de configuration ne se crée pas depuis la
                // collection d'un autre objet, elle vaut pour tout le cabinet.
                parentContext: null,
            },
        }));
    }

    /**
     * Une création ou une modification vient d'aboutir dans un dialogue ouvert d'ici :
     * on redemande l'état au serveur plutôt que de le recalculer en JS. Le score, les
     * compteurs et les libellés des lignes sont des faits de base de données ; les
     * deviner côté client, c'est se préparer à afficher un chiffre faux.
     */
    async surEnregistrement(event) {
        if (event.detail?.type !== 'app:entity.saved') return;
        if (!event.detail.payload?.userContext?._onboardingReload) return;

        await this.rafraichir();
    }

    async rafraichir() {
        if (!this.hasUrlValue) return;

        try {
            const reponse = await fetch(this.urlValue, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!reponse.ok) return;

            const etat = await reponse.json();

            // Le panneau ET le voyant de la colonne 1 bougent ensemble. Les rafraîchir en
            // deux temps laisserait une fenêtre où l'un contredit l'autre.
            if (typeof etat.panneau === 'string') {
                this.element.outerHTML = etat.panneau;
            }
            if (typeof etat.voyant === 'string') {
                this.remplacerLeVoyant(etat.voyant);
            }
        } catch (e) {
            // Le guide reste affiché avec ses chiffres d'avant : périmé, mais lisible.
            // Le prochain enregistrement le remettra à jour.
        }
    }

    /**
     * Le voyant vit dans la colonne 1, hors de ce panneau : on le remplace à sa place,
     * ou on le retire quand la dette est soldée (le serveur ne rend alors rien).
     */
    remplacerLeVoyant(html) {
        const hote = document.querySelector('[data-onboarding-voyant]');
        if (hote) hote.innerHTML = html;
    }
}
