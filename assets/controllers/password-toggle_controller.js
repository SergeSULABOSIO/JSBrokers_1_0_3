import { Controller } from '@hotwired/stimulus';

/**
 * @class PasswordToggleController
 * @extends Controller
 * @description Affiche / masque le contenu d'un champ mot de passe.
 *
 * Ergonomie : permet à l'utilisateur de vérifier sa saisie (réduit les erreurs
 * de frappe) sans compromettre la sécurité — le champ revient masqué par défaut.
 * Accessibilité : le bouton expose son état via `aria-pressed` et un `aria-label`
 * mis à jour à chaque bascule (WCAG 4.1.2 — Nom, rôle et valeur).
 *
 * Markup attendu :
 *   <div data-controller="password-toggle">
 *     <input type="password" data-password-toggle-target="input">
 *     <button type="button"
 *             data-action="password-toggle#toggle"
 *             data-password-toggle-target="button">…</button>
 *   </div>
 *
 * Les deux icônes (œil ouvert / œil barré) sont placées dans le bouton et
 * basculées via la classe `is-hidden`.
 */
export default class extends Controller {
    static targets = ['input', 'button'];

    toggle() {
        const isPassword = this.inputTarget.type === 'password';
        this.inputTarget.type = isPassword ? 'text' : 'password';

        if (this.hasButtonTarget) {
            this.buttonTarget.setAttribute('aria-pressed', isPassword ? 'true' : 'false');
            this.buttonTarget.setAttribute(
                'aria-label',
                isPassword ? 'Masquer le mot de passe' : 'Afficher le mot de passe'
            );
            // Bascule l'affichage des deux icônes (œil / œil barré).
            this.buttonTarget
                .querySelectorAll('[data-password-icon]')
                .forEach((icon) => icon.classList.toggle('is-hidden'));
        }

        // On garde le focus sur le champ pour ne pas casser le flux de saisie.
        this.inputTarget.focus();
    }
}
