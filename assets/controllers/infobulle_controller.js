import { Controller } from '@hotwired/stimulus';
import { MoteurInfobulle } from './infobulle-moteur.js';

/**
 * @class InfobulleController
 * @description L'INFOBULLE UNIQUE DE L'APPLICATION, côté Stimulus : une coquille autour de
 * `infobulle-moteur.js`, qui porte tout le comportement (placement, WCAG 1.4.13, une seule bulle
 * à l'écran…) et sert aussi les pages sans Stimulus (`infobulles-autonomes.js`).
 *
 * Posé sur le <body> (base.html.twig) : une seule instance sert toute l'application ; une
 * instance plus proche (vue Cartes, barres d'actions) traite l'événement la première.
 *
 * Valeurs : `placement` ('curseur' | 'bouton' | 'droite'), `source` ('donnees' | 'titre'),
 * `delai` (délai d'intention au survol, ms ; 0 = immédiate).
 */
export default class extends Controller {
    static values = {
        placement: { type: String, default: 'curseur' },
        source: { type: String, default: 'donnees' },
        delai: { type: Number, default: 0 },
    };

    connect() {
        this.moteur = new MoteurInfobulle(this.element, {
            placement: this.placementValue,
            source: this.sourceValue,
            delaiSurvol: this.delaiValue,
        }).connecter();
    }

    disconnect() {
        if (this.moteur) this.moteur.deconnecter();
        this.moteur = null;
    }
}
