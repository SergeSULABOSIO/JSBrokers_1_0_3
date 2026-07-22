import { Controller } from '@hotwired/stimulus';
import { formatInstant } from '../datetime-format.js';

/*
 * Affiche un <time datetime="…ISO+offset"> dans l'horloge de RÉFÉRENCE de
 * l'application (cf. datetime-format.js), la même que celle utilisée par le
 * rendu serveur.
 *
 * Pourquoi reformater ce que le serveur a déjà rendu : c'est le seul formateur
 * côté client. Le texte initial et les rafraîchissements JS sortent donc de la
 * même fonction — ils ne peuvent plus diverger d'un format ni d'une heure. Sans
 * JS, le texte rendu par Twig reste affiché tel quel.
 */
export default class extends Controller {
    connect() {
        this.localize();
    }

    localize() {
        const iso = this.element.getAttribute('datetime');
        if (!iso) return;

        const texte = formatInstant(iso);
        if (texte) this.element.textContent = texte;
    }
}
