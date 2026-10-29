import { positionADroite, positionAuCurseur, positionCentreeSous, positionSousLElement } from './infobulle-position.js';
import { lireSections, normaliserSections, rangees } from './infobulle-contenu.js';

/**
 * LE MOTEUR DE L'INFOBULLE UNIQUE (chantier 3, décisions de Serge du 2026-10-23).
 *
 * Un module ordinaire, sans Stimulus : le contrôleur `infobulle` l'instancie dans l'application,
 * `infobulles-autonomes.js` sur les pages qui n'ont pas Stimulus (relevé de compte autonome et
 * PUBLIC). Une seule implémentation, donc un seul comportement partout.
 *
 * ⚠ ES2017 : le relevé public est ouvert par les clients, sur des navigateurs parfois anciens —
 * ni `?.`, ni `??`, ni `catch` sans variable (infobulle-es2017.test.mjs). Une syntaxe trop
 * récente ne se dégrade pas : le module entier échoue au chargement.
 *
 * Posé sur un conteneur, il sert tout descendant porteur d'un contenu — y compris ceux qui
 * arrivent après coup (cartes clonées, lignes rafraîchies, boutons injectés) :
 *   - `source: donnees` (défaut) : `[data-infobulle]`, contenu DÉCLARÉ par le serveur en JSON
 *     (infobulle-contenu.js) ;
 *   - `source: titre` : `[title]` (barres d'actions) — le `title` natif est mis de côté pendant
 *     l'affichage, pour ne pas doubler la bulle, puis rendu.
 * Le contenu est posé par `textContent` : une donnée n'est jamais du HTML.
 *
 * Placement : `curseur` (défaut, le contenu) — POSÉE au point d'entrée du pointeur puis IMMOBILE
 * tant qu'on reste sur l'élément (arbitrage du 2026-10-23 : une bulle qui suit le curseur recule
 * quand on va vers elle) ; `bouton` — centrée sous l'élément (barres d'actions). Au clavier,
 * toujours sous l'élément. Un élément peut choisir le sien : `data-infobulle-placement="bouton"`.
 *
 * `data-infobulle-si-replie` : la bulle se tait quand le <details> qui contient l'élément est
 * OUVERT — le détail est déjà à l'écran. Déclaratif, et pas une règle de tout <summary> : un
 * bouton « Voir tous… » dans l'en-tête d'un bloc ouvert garde sa bulle.
 *
 * `data-infobulle-taire` : posé sur un bouton DANS un élément porteur (le × d'une puce), il fait
 * taire la bulle de l'élément tant que le pointeur est dessus — on va retirer, pas lire.
 *
 * Zones imbriquées : l'instance la plus proche de l'élément traite l'événement la première et le
 * marque (`event.infobulleTraitee`) ; les autres l'ignorent.
 *
 * WCAG 1.4.13 :
 *   - SURVOLABLE : quitter l'élément laisse un court délai pour entrer dans la bulle ; elle reste
 *     tant que le pointeur est sur l'élément OU sur elle ;
 *   - REJETABLE : Échap la ferme sans bouger ni pointeur ni focus ;
 *   - PERSISTANTE : aucune minuterie ne la ferme pendant le survol ou le focus ;
 *   - reliée à son élément par `aria-describedby`.
 *
 * UNE SEULE BULLE À L'ÉCRAN : l'élément flottant (au <body>, hors des `overflow: hidden`) est
 * partagé par toutes les instances ; en montrer une cache la précédente.
 */

/** Délai pour passer de l'élément à la bulle (ms). */
const GRACE = 300;

const etat = {
    bulle: null,
    cible: null,
    proprietaire: null,
    minuterie: null,
    // La raison d'un refus, montrée au geste, se referme seule (expliquerUnRefus).
    minuterieRefus: null,
    parLeClavier: false,
    observateur: null,
    // Une ouverture EN ATTENTE (délai d'intention) : partagée comme la bulle, pour qu'un menu
    // qui s'ouvre (`fermerInfobulle`) l'annule aussi — sinon la bulle surgissait par-dessus lui.
    intention: null,
    cibleEnAttente: null,
};

/** Abandonne l'ouverture en attente, et rend à sa cible le `title` natif mis de côté. */
function renoncer() {
    clearTimeout(etat.intention);
    etat.intention = null;
    const cible = etat.cibleEnAttente;
    etat.cibleEnAttente = null;
    if (cible && cible !== etat.cible && cible.dataset.infobulleTitre !== undefined) {
        if (!cible.hasAttribute('title')) cible.setAttribute('title', cible.dataset.infobulleTitre);
        delete cible.dataset.infobulleTitre;
    }
}

/**
 * Une infobulle est-elle affichée ? Lu par le gestionnaire des dialogues : le premier Échap
 * ferme la bulle, le second le dialogue (echap-dialogue.js).
 */
export function infobulleOuverte() {
    return !!(etat.bulle && !etat.bulle.hidden && etat.cible);
}

function bulle() {
    if (etat.bulle && etat.bulle.isConnected) return etat.bulle;
    const b = document.createElement('div');
    b.className = 'jsb-ctx-tip est-survolable';
    b.setAttribute('role', 'tooltip');
    b.id = 'jsb-infobulle';
    b.hidden = true;
    b.addEventListener('mouseenter', () => clearTimeout(etat.minuterie));
    b.addEventListener('mouseleave', (e) => {
        if (etat.cible && e.relatedTarget && etat.cible.contains(e.relatedTarget)) return;
        differerLaFermeture();
    });
    document.body.appendChild(b);
    etat.bulle = b;

    return b;
}

function differerLaFermeture() {
    clearTimeout(etat.minuterie);
    etat.minuterie = setTimeout(fermer, GRACE);
}

function fermer() {
    clearTimeout(etat.minuterie);
    const cible = etat.cible;
    if (etat.observateur) etat.observateur.disconnect();
    etat.observateur = null;
    if (cible) {
        cible.removeAttribute('aria-describedby');
        if (cible.dataset.infobulleTitre !== undefined) {
            // Un `title` posé entre-temps par le code (bouton désactivé, raison du refus) a priorité.
            if (!cible.hasAttribute('title')) cible.setAttribute('title', cible.dataset.infobulleTitre);
            delete cible.dataset.infobulleTitre;
        }
    }
    etat.cible = null;
    etat.proprietaire = null;
    etat.parLeClavier = false;
    if (etat.bulle) etat.bulle.hidden = true;
}

/**
 * Les variantes de la bulle : compacte pour un texte seul (libellé d'un bouton) ; un contour
 * sur fond sombre quand l'élément vit dans une zone sombre qui le déclare
 * (`data-infobulle-sur-sombre`, ex. le chat de Ket en thème sombre) — l'ombre noire de la bulle
 * n'y délimite plus rien.
 */
function habiller(b, cible, sections, poseeParLeCode) {
    b.classList.toggle('est-courte', sections.length === 1 && !sections[0].titre && sections[0].lignes.length === 0);
    b.classList.toggle('is-sur-sombre', !!(cible && cible.closest('[data-infobulle-sur-sombre]')));
    // Posée par le code au point d'une courbe, la bulle peut tomber sous le pointeur : elle le
    // laisse passer, sinon le canevas perd la souris, la bulle se ferme, et tout recommence.
    b.classList.toggle('est-traversable', !!poseeParLeCode);
}

/**
 * Ferme la bulle affichée.
 *
 * @param {Element} [zone] sans zone, toute bulle (un menu qui s'ouvre ne la recouvre pas) ; avec,
 *   seulement celle d'un élément de cette zone, ou d'un élément disparu — la bulle qu'un voisin
 *   vient d'ouvrir reste (une zone re-rendue, un graphique quitté, ne la ferment pas).
 */
export function fermerInfobulle(zone) {
    if (!zone || !etat.cibleEnAttente || zone.contains(etat.cibleEnAttente)) renoncer();
    if (zone && etat.cible && etat.cible.isConnected && !zone.contains(etat.cible)) return;
    fermer();
}

/** Les gestes qui ferment une bulle posée par le code : aucune instance n'en est propriétaire. */
let gestesDuCodeEcoutes = false;
function ecouterLesGestesDuCode() {
    if (gestesDuCodeEcoutes) return;
    gestesDuCodeEcoutes = true;
    document.addEventListener('keydown', (e) => {
        if (etat.proprietaire !== null || !etat.cible) return;
        if (!etat.cible.isConnected) {
            fermer();
            return;
        }
        if (e.key === 'Escape' || e.key === 'Esc') {
            // Rejetable (WCAG 1.4.13) ; cet Échap n'a fermé que la bulle (le dialogue l'a laissé passer).
            fermer();
            e.preventDefault();
            e.stopPropagation();
        }
    });
    document.addEventListener('mousemove', () => {
        // Le graphique re-rendu sous le pointeur : son canevas n'existe plus.
        if (etat.proprietaire === null && etat.cible && !etat.cible.isConnected) fermer();
    }, { passive: true });
}

/**
 * Une bulle posée PAR LE CODE, pour ce qui n'est pas un élément survolé : le point d'une courbe
 * Chart.js (graphique de production). Mêmes styles, même élément, même contenu (sections, validées
 * comme celles du serveur) ; la position est un point de l'écran, la bulle s'y pose comme au curseur.
 *
 * @param {HTMLElement} cible l'élément décrit (ex. le canevas du graphique) — aria-describedby
 * @param {Array} sections le contenu (forme de infobulle-contenu.js)
 * @param {{x: number, y: number}} point en coordonnées d'écran
 */
export function afficherInfobulle(cible, sections, point) {
    const propres = normaliserSections(sections);
    if (propres.length === 0) {
        fermerInfobulle(cible);
        return;
    }
    ecouterLesGestesDuCode();
    if (etat.cible !== cible) fermer();
    // Une grâce lancée en quittant la bulle ne ferme pas celle du point suivant.
    clearTimeout(etat.minuterie);
    const b = bulle();
    construire(b, propres);
    habiller(b, cible, propres, true);
    b.hidden = false;
    etat.cible = cible;
    etat.proprietaire = null;
    cible.setAttribute('aria-describedby', b.id);
    const position = positionAuCurseur(point, { largeur: b.offsetWidth, hauteur: b.offsetHeight },
        { largeur: window.innerWidth, hauteur: window.innerHeight });
    b.style.left = `${position.left}px`;
    b.style.top = `${position.top}px`;
}

/**
 * UN BOUTON REFUSÉ DIT POURQUOI, AU GESTE (refonte de la colonne 3, C4). Au doigt, il n'y a pas
 * de survol : la raison d'un bouton inactif (chantier 20 : « Ajouter — vous n'avez pas le droit
 * de créer ») ne s'affichait jamais, et l'appui ne faisait rien, sans un mot. Le geste la montre
 * désormais dans LA bulle, à droite du bouton (comme au survol du rail), le temps de la lire.
 *
 * @param {HTMLElement} bouton porte sa raison dans `title` (ou `data-infobulle-titre` pendant un survol)
 * @param {number} [delaiDeLecture] ms avant de la refermer
 */
export function expliquerUnRefus(bouton, delaiDeLecture = 5000) {
    const titre = bouton.getAttribute('title');
    const texte = (titre !== null ? titre : (bouton.dataset.infobulleTitre || '')).trim();
    if (!texte) return;
    const r = bouton.getBoundingClientRect();
    afficherInfobulle(bouton, [{ titre: null, lignes: [], texte, classe: null }], { x: r.right, y: r.top });
    // À DROITE DU BOUTON, comme au survol du rail (la position « au curseur » la rabattait à gauche,
    // par-dessus le menu de la colonne 1).
    const b = bulle();
    const position = positionADroite(r, { largeur: b.offsetWidth, hauteur: b.offsetHeight },
        { largeur: window.innerWidth, hauteur: window.innerHeight });
    b.style.left = `${position.left}px`;
    b.style.top = `${position.top}px`;
    clearTimeout(etat.minuterieRefus);
    etat.minuterieRefus = setTimeout(() => fermerInfobulle(bouton), delaiDeLecture);
}

function construire(b, sections) {
    b.textContent = '';
    const table = document.createElement('table');
    for (const rangee of rangees(sections)) {
        const tr = document.createElement('tr');
        for (const cellule of rangee.cellules) {
            const td = document.createElement('td');
            td.textContent = cellule.texte;
            if (cellule.classe) td.className = cellule.classe;
            if (cellule.colspan > 1) td.colSpan = cellule.colspan;
            tr.appendChild(td);
        }
        table.appendChild(tr);
    }
    b.appendChild(table);
}

function focusClavier(cible) {
    try {
        return cible.matches(':focus-visible');
    } catch (e) {
        return true; // navigateur sans :focus-visible — on montre plutôt que de cacher
    }
}

export class MoteurInfobulle {
    /**
     * @param {HTMLElement} element le conteneur servi
     * @param {{placement?: 'curseur'|'bouton'|'droite', source?: 'donnees'|'titre', delaiSurvol?: number}} [options]
     *   `droite` : à droite de l'élément (le rail d'outils de la colonne 3) ; `delaiSurvol` : un
     *   DÉLAI D'INTENTION au survol (ms) — le pointeur qui ne fait que traverser ne fait rien
     *   apparaître ; au focus clavier, la bulle reste immédiate. 0 par défaut.
     */
    constructor(element, options) {
        const o = options || {};
        this.element = element;
        this.placement = o.placement || 'curseur';
        this.source = o.source || 'donnees';
        this.delaiSurvol = Math.max(0, Number(o.delaiSurvol) || 0);
        // Un clic dans le conteneur pendant le délai : on agit, on ne lit plus — rien ne surgit.
        // Un TOUCHER est noté : le `mouseover` de compatibilité qui le suit n'arme pas de bulle
        // (au doigt, `:hover` reste collé, et la bulle surgissait 300 ms après le tap).
        this._dernierToucher = 0;
        this._surPression = (e) => {
            if (e.pointerType === 'touch' || e.pointerType === 'pen') this._dernierToucher = Date.now();
            if (etat.cibleEnAttente && this.element.contains(etat.cibleEnAttente)) renoncer();
        };

        this._surEntree = (e) => this._entrer(e);
        this._surSortie = (e) => this._sortir(e);
        this._surFocus = (e) => this._focus(e);
        this._surPerteDeFocus = (e) => this._perteDeFocus(e);
        this._surTouche = (e) => {
            // Échap PENDANT le délai d'intention : la bulle est rejetée avant même d'apparaître.
            // Rien d'autre n'est retenu — cet Échap ferme aussi ce qu'il devait fermer.
            if ((e.key === 'Escape' || e.key === 'Esc') && etat.cibleEnAttente && this.element.contains(etat.cibleEnAttente)) {
                renoncer();
            }
            if (etat.proprietaire !== this) return;
            // L'élément a disparu sous un utilisateur au clavier (liste rafraîchie) : aucun
            // `focusout` fiable, aucune souris pour le voir — la touche suivante ferme la bulle.
            if (etat.cible && !etat.cible.isConnected) {
                fermer();
                return;
            }
            if (e.key === 'Escape' || e.key === 'Esc') {
                // Rejetable (WCAG 1.4.13) — et cet Échap s'arrête là : il n'a fermé QUE la bulle.
                fermer();
                e.preventDefault();
                e.stopPropagation();
            }
        };
        this._surBascule = (e) => {
            // On déplie la ligne dont la bulle se tait une fois dépliée.
            if (etat.proprietaire === this && etat.cible && etat.cible.hasAttribute('data-infobulle-si-replie')
                && e.target.open && e.target.contains(etat.cible)) fermer();
        };
        this._surMouvement = () => {
            // La liste a pu se rafraîchir sous le pointeur : l'élément n'existe plus, aucun
            // `mouseout` ne viendra.
            if (etat.proprietaire === this && etat.cible && !etat.cible.isConnected) fermer();
        };
    }

    connecter() {
        this.element.addEventListener('mouseover', this._surEntree);
        this.element.addEventListener('pointerdown', this._surPression, true);
        this.element.addEventListener('mouseout', this._surSortie);
        this.element.addEventListener('focusin', this._surFocus);
        this.element.addEventListener('focusout', this._surPerteDeFocus);
        this.element.addEventListener('toggle', this._surBascule, true); // `toggle` ne remonte pas
        document.addEventListener('keydown', this._surTouche);
        document.addEventListener('mousemove', this._surMouvement, { passive: true });

        return this;
    }

    deconnecter() {
        this.element.removeEventListener('mouseover', this._surEntree);
        this.element.removeEventListener('pointerdown', this._surPression, true);
        this.element.removeEventListener('mouseout', this._surSortie);
        this.element.removeEventListener('focusin', this._surFocus);
        this.element.removeEventListener('focusout', this._surPerteDeFocus);
        this.element.removeEventListener('toggle', this._surBascule, true);
        document.removeEventListener('keydown', this._surTouche);
        document.removeEventListener('mousemove', this._surMouvement);
        if (etat.cibleEnAttente && this.element.contains(etat.cibleEnAttente)) renoncer();
        if (etat.proprietaire === this) fermer();
    }

    /** Où poser la bulle : `point` est celui du pointeur (null au clavier). */
    _position(cible, point) {
        const cadre = cible.getBoundingClientRect();
        const placement = this._placement(cible);
        if (placement === 'droite') return positionADroite(cadre, this._taille(), this._ecran());
        if (placement === 'bouton') return positionCentreeSous(cadre, this._taille(), this._ecran());

        return point ? positionAuCurseur(point, this._taille(), this._ecran()) : positionSousLElement(cadre, this._taille(), this._ecran());
    }

    // ── Qui porte une infobulle ───────────────────────────────────────────────

    _selecteur() {
        return this.source === 'titre' ? '[title], [data-infobulle-titre]' : '[data-infobulle]';
    }

    _cibleDe(noeud) {
        const cible = noeud && noeud.closest ? noeud.closest(this._selecteur()) : null;
        return cible && this.element.contains(cible) ? cible : null;
    }

    _placement(cible) {
        return cible.dataset.infobullePlacement || this.placement;
    }

    _sections(cible) {
        if (this.source !== 'titre') return lireSections(cible.dataset.infobulle);
        const vivant = cible.getAttribute('title');
        const libelle = vivant !== null ? vivant : (cible.dataset.infobulleTitre || '');
        return libelle.trim() === '' ? [] : [{ titre: null, lignes: [], texte: libelle, classe: null }];
    }

    // ── Gestes ────────────────────────────────────────────────────────────────

    _entrer(event) {
        if (event.infobulleTraitee) return;
        const cible = this._cibleDe(event.target);
        if (!cible) return;
        event.infobulleTraitee = true;
        const taire = event.target.closest('[data-infobulle-taire]');
        if (taire && cible.contains(taire)) {
            if (etat.proprietaire === this && etat.cible === cible) fermer();
            return;
        }
        if (cible === etat.cible) {
            clearTimeout(etat.minuterie); // retour sur l'élément pendant la grâce
            return;
        }
        // Le menu de cet élément est ouvert : sa bulle le recouvrirait.
        if (cible.getAttribute('aria-expanded') === 'true') return;
        const point = { x: event.clientX, y: event.clientY };
        if (this.delaiSurvol > 0) {
            // Le pointeur passe sur un ENFANT (l'icône) de l'élément déjà en attente : le délai
            // continue — le réarmer reculait l'ouverture à chaque pixel.
            if (cible === etat.cibleEnAttente) return;
            if (Date.now() - this._dernierToucher < 800) return;
            // LE DÉLAI D'INTENTION : la bulle n'apparaît que si le pointeur S'ARRÊTE sur
            // l'élément ; le traverser en passant ne fait rien surgir.
            renoncer();
            etat.cibleEnAttente = cible;
            // Le `title` natif est mis de côté DÈS L'ATTENTE : sinon la bulle du navigateur
            // pouvait surgir pendant le délai, puis la nôtre par-dessus.
            if (this.source === 'titre' && cible.hasAttribute('title')) {
                cible.dataset.infobulleTitre = cible.getAttribute('title');
                cible.removeAttribute('title');
            }
            etat.intention = setTimeout(() => {
                etat.intention = null;
                const pret = cible.isConnected && cible.matches(':hover') && cible.getAttribute('aria-expanded') !== 'true';
                // `_montrer` peut refuser (rien à dire) : l'attente n'est levée qu'après, pour que
                // `renoncer` rende alors le `title` mis de côté.
                if (!pret || !this._montrer(cible)) {
                    renoncer();
                    return;
                }
                etat.cibleEnAttente = null;
                this._placer(this._position(cible, point));
            }, this.delaiSurvol);
            return;
        }
        if (!this._montrer(cible)) return;
        this._placer(this._position(cible, point));
    }

    _sortir(event) {
        // Le pointeur quitte l'élément avant la fin du délai d'intention : rien n'apparaîtra.
        const enAttente = etat.cibleEnAttente;
        if (enAttente && enAttente.contains(event.target) && !(event.relatedTarget && enAttente.contains(event.relatedTarget))) {
            renoncer();
        }
        if (etat.proprietaire !== this || !etat.cible) return;
        // Seule la sortie de l'ÉLÉMENT compte : la souris qui bouge ailleurs dans le conteneur
        // ne ferme pas une bulle ouverte par le clavier (persistante, WCAG 1.4.13).
        if (!etat.cible.contains(event.target)) return;
        const vers = event.relatedTarget;
        if (vers && (etat.cible.contains(vers) || (etat.bulle && etat.bulle.contains(vers)))) return;
        // Tant que l'élément garde le focus clavier, la bulle reste.
        if (etat.parLeClavier && document.activeElement === etat.cible) return;
        differerLaFermeture();
    }

    _focus(event) {
        if (event.infobulleTraitee) return;
        const cible = this._cibleDe(event.target);
        // Seulement l'élément LUI-MÊME : le focus d'un bouton DANS une carte n'appelle pas la fiche.
        if (!cible || cible !== event.target) return;
        event.infobulleTraitee = true;
        // Un focus du CLAVIER seulement : un clic sur une carte focalisable, ou le focus rendu à
        // la fermeture d'un dialogue, ne fait pas sauter la bulle sous l'élément.
        if (!focusClavier(cible)) return;
        renoncer(); // au clavier, immédiate
        if (cible === etat.cible) {
            etat.parLeClavier = true; // ouverte à la souris, désormais tenue par le focus
            return;
        }
        if (this._montrer(cible)) {
            etat.parLeClavier = true;
            this._placer(this._position(cible, null));
        }
    }

    _perteDeFocus(event) {
        if (etat.proprietaire !== this || event.target !== etat.cible) return;
        fermer();
    }

    // ── Affichage ─────────────────────────────────────────────────────────────

    /** @returns {boolean} vrai si une infobulle est affichée */
    _montrer(cible) {
        const replie = cible.closest('details');
        if (cible.hasAttribute('data-infobulle-si-replie') && replie && replie.open) return false;
        const sections = this._sections(cible);
        if (sections.length === 0) return false;

        fermer();
        const b = bulle();
        construire(b, sections);
        // Un texte seul (libellé d'un bouton) : bulle compacte.
        habiller(b, cible, sections);
        if (this.source === 'titre') {
            if (cible.hasAttribute('title')) {
                cible.dataset.infobulleTitre = cible.getAttribute('title');
                cible.removeAttribute('title');
            }
            // Le code peut changer le `title` PENDANT l'affichage (un bouton se désactive dès qu'on
            // tape dans le formulaire : « X — Enregistrez d'abord vos modifications. ») : la bulle
            // suit, et le `title` natif reste mis de côté — jamais deux infobulles à la fois.
            etat.observateur = new MutationObserver(() => {
                const nouveau = cible.getAttribute('title');
                if (nouveau === null) return;
                cible.dataset.infobulleTitre = nouveau;
                cible.removeAttribute('title');
                const aJour = this._sections(cible);
                if (aJour.length) construire(b, aJour);
            });
            etat.observateur.observe(cible, { attributes: true, attributeFilter: ['title'] });
        }
        b.hidden = false;
        etat.cible = cible;
        etat.proprietaire = this;
        cible.setAttribute('aria-describedby', b.id);

        return true;
    }

    _taille() {
        return { largeur: etat.bulle.offsetWidth, hauteur: etat.bulle.offsetHeight };
    }

    _ecran() {
        return { largeur: window.innerWidth, hauteur: window.innerHeight };
    }

    _placer(position) {
        etat.bulle.style.left = `${position.left}px`;
        etat.bulle.style.top = `${position.top}px`;
    }
}
