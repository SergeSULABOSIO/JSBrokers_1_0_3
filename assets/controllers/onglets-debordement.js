/**
 * REPLI DES ONGLETS QUI NE TIENNENT PAS — mécanique partagée par les deux barres.
 *
 * Le workspace en a deux : celle des RUBRIQUES ouvertes (colonne 3) et celle des onglets
 * d'une rubrique (principal + collections de la ligne sélectionnée). Les deux débordent
 * pour la même raison et méritent la même réponse ; les écrire deux fois, c'était garantir
 * qu'elles finiraient par diverger.
 *
 * ── POURQUOI REPLIER PLUTÔT QUE FAIRE DÉFILER ───────────────────────────────────────
 * Le défilement horizontal ne montre rien : une barre de 3 px sous les onglets ne se voit
 * pas, et ce qu'elle cache est à la fois invisible ET introuvable — l'utilisateur doit
 * deviner qu'il reste des onglets, puis deviner comment y aller (Nielsen 6, reconnaissance
 * plutôt que rappel). Un bouton « + N » dit COMBIEN il en reste, et les donne d'un clic.
 *
 * ── LA RANGÉE NE BOUGE PAS ──────────────────────────────────────────────────────────
 * L'onglet actif n'est pas ramené de force dans la barre : l'y épingler ferait sauter les
 * onglets à chaque changement, et la barre ne ressemblerait jamais deux fois à elle-même.
 * On garde l'ordre, et on DIT où l'on se trouve — le bouton passe en cobalt quand l'onglet
 * courant est replié, et le panneau met sa ligne en évidence.
 */
export class DebordementOnglets {
    /**
     * @param {object} config
     * @param {HTMLElement} config.wrapper - conteneur positionné (référent du panneau).
     * @param {HTMLElement} config.rangee - l'élément qui porte les onglets.
     * @param {HTMLElement} config.bouton - le bouton « + N ».
     * @param {HTMLElement} config.compteur - l'élément qui affiche « +N ».
     * @param {HTMLElement} config.panneau - la surcouche listant les onglets repliés.
     * @param {string} config.selecteurOnglet - sélecteur CSS des onglets dans la rangée.
     * @param {string} [config.classeRepli] - classe posée sur un onglet replié.
     * @param {string} [config.classeActive] - classe marquant l'onglet actif.
     * @param {number} [config.largeurBouton] - place réservée au bouton, en pixels.
     * @param {(onglet: HTMLElement) => string} [config.libelle] - texte d'une entrée.
     * @param {(onglet: HTMLElement) => void} [config.activer] - action d'une entrée.
     * @param {(onglet: HTMLElement) => void} [config.fermer] - fermeture d'une entrée.
     *        Absent, aucune croix n'est posée : toutes les barres ne se ferment pas.
     */
    constructor(config) {
        this.cfg = {
            classeRepli: 'is-tab-replie',
            classeActive: 'active',
            largeurBouton: 76,
            libelle: (onglet) => onglet.textContent.trim(),
            activer: (onglet) => onglet.click(),
            ...config,
        };
        this.replies = [];
        this.boundFermeture = null;
    }

    /** Recalcule ce qui tient, ce qui se replie, et l'état du bouton. */
    recalculer() {
        const { wrapper, rangee, bouton, compteur, selecteurOnglet, classeRepli, classeActive } = this.cfg;
        if (!wrapper || !rangee || !bouton) return;

        const onglets = Array.from(rangee.querySelectorAll(selecteurOnglet));
        if (onglets.length === 0) {
            bouton.classList.add('d-none');
            this.replies = [];
            return;
        }

        // On repart d'une rangée ENTIÈREMENT dépliée avant de mesurer : mesurer l'état
        // replié du tour précédent empêcherait la barre de se redéployer quand la fenêtre
        // s'élargit — elle se replierait une fois pour toutes.
        onglets.forEach((o) => o.classList.remove(classeRepli));
        bouton.classList.add('d-none');

        // La place du bouton est réservée d'emblée, même s'il s'avère inutile : la
        // calculer après coup ferait osciller le dernier onglet entre visible et replié
        // à chaque pixel de redimensionnement.
        const disponible = wrapper.clientWidth - this.cfg.largeurBouton;
        const actif = onglets.find((o) => o.classList.contains(classeActive));

        let cumul = 0;
        const replies = [];
        for (const onglet of onglets) {
            const largeur = onglet.offsetWidth;
            if (cumul + largeur <= disponible) {
                cumul += largeur;
            } else {
                onglet.classList.add(classeRepli);
                replies.push(onglet);
            }
        }

        this.replies = replies;
        if (replies.length === 0) {
            bouton.classList.remove('has-current');
            this.fermer();
            return;
        }

        bouton.classList.remove('d-none');
        if (compteur) compteur.textContent = `+${replies.length}`;

        // L'onglet courant est-il parmi les repliés ? Si oui, le bouton le dit — sans quoi
        // l'utilisateur ne verrait NULLE PART où il se trouve (Nielsen 1).
        const courantReplie = actif !== undefined && replies.includes(actif);
        bouton.classList.toggle('has-current', courantReplie);
        const nom = courantReplie ? this.cfg.libelle(actif) : '';
        bouton.setAttribute(
            'aria-label',
            courantReplie
                ? `Onglet courant « ${nom} » replié — afficher les ${replies.length} onglets repliés`
                : `Afficher ${replies.length} onglet${replies.length > 1 ? 's' : ''} replié${replies.length > 1 ? 's' : ''}`,
        );
        bouton.setAttribute('title', courantReplie ? `Vous êtes sur « ${nom} »` : 'Onglets repliés');

        // Panneau déjà ouvert pendant une redimension : on le repeuple à chaud plutôt que
        // de le refermer sous le curseur.
        if (bouton.getAttribute('aria-expanded') === 'true') this._peupler();
    }

    /** Ouvre ou ferme le panneau. */
    basculer(event) {
        event?.preventDefault();
        event?.stopPropagation();
        if (this.cfg.bouton.getAttribute('aria-expanded') === 'true') this.fermer();
        else this.ouvrir();
    }

    ouvrir() {
        const { bouton, panneau, wrapper } = this.cfg;
        if (!panneau) return;

        this._peupler();
        panneau.classList.remove('d-none');
        bouton.setAttribute('aria-expanded', 'true');

        // Sorties d'urgence (Nielsen 3) : Échap, ou un clic hors du composant.
        this.boundFermeture = (e) => {
            if (e.type === 'keydown' && e.key !== 'Escape') return;
            if (e.type === 'click' && wrapper.contains(e.target)) return;
            this.fermer();
            if (e.type === 'keydown') bouton.focus();
        };
        document.addEventListener('keydown', this.boundFermeture);
        document.addEventListener('click', this.boundFermeture);

        panneau.querySelector('button')?.focus();
    }

    fermer() {
        const { bouton, panneau } = this.cfg;
        if (!panneau) return;
        panneau.classList.add('d-none');
        bouton?.setAttribute('aria-expanded', 'false');
        if (this.boundFermeture) {
            document.removeEventListener('keydown', this.boundFermeture);
            document.removeEventListener('click', this.boundFermeture);
            this.boundFermeture = null;
        }
    }

    /** À appeler au disconnect du contrôleur hôte : rien ne doit survivre à la boîte. */
    detruire() {
        this.fermer();
    }

    /**
     * Reconstruit la liste depuis les onglets repliés.
     *
     * Chaque entrée est un RACCOURCI vers l'onglet réel, qui reste dans le DOM :
     * l'activation repasse par le même chemin que le clic direct — aucune seconde
     * logique d'activation à maintenir.
     * @private
     */
    _peupler() {
        const { panneau, classeActive } = this.cfg;
        panneau.innerHTML = '';

        this.replies.forEach((onglet) => {
            // UNE RANGÉE, PAS UN SEUL BOUTON.
            //
            // L'entrée était elle-même un `button` ; y glisser une croix aurait imbriqué
            // un bouton dans un bouton — HTML invalide, et comportement de clic
            // imprévisible. On sépare donc : une rangée qui porte le rôle de liste, un
            // bouton pour aller à l'onglet, un autre pour le fermer.
            const rangee = document.createElement('div');
            rangee.className = 'list-tabs-overflow-row';
            rangee.setAttribute('role', 'listitem');

            const entree = document.createElement('button');
            entree.type = 'button';
            entree.className = 'list-tabs-overflow-item';
            if (onglet.dataset.tabId) entree.dataset.tabId = onglet.dataset.tabId;

            // Rubrique COURANTE mise en évidence, comme dans le menu de navigation : gras,
            // cobalt, fond cobalt léger. `aria-current` porte la même information aux
            // lecteurs d'écran — l'état ne tient jamais à la seule couleur (WCAG 1.4.1).
            if (onglet.classList.contains(classeActive)) {
                entree.classList.add('is-current');
                entree.setAttribute('aria-current', 'true');
            }

            // L'icône de l'onglet est recopiée : le panneau se lit avec les mêmes repères
            // que la barre (Bastien & Scapin > Cohérence).
            const icone = onglet.querySelector('.list-tab-icon, .workspace-tab-icon');
            if (icone && icone.innerHTML.trim() !== '') {
                const copie = icone.cloneNode(true);
                copie.setAttribute('aria-hidden', 'true');
                entree.appendChild(copie);
            }
            const libelle = document.createElement('span');
            libelle.textContent = this.cfg.libelle(onglet);
            entree.appendChild(libelle);

            entree.addEventListener('click', (e) => {
                e.stopPropagation();
                this.fermer();
                this.cfg.activer(onglet);
            });
            rangee.appendChild(entree);

            if (typeof this.cfg.fermer === 'function') {
                rangee.appendChild(this._croix(onglet));
            }
            panneau.appendChild(rangee);
        });
    }

    /**
     * La croix de fermeture d'une entrée.
     *
     * Le panneau se REPEUPLE après coup, et se referme s'il ne reste rien à montrer :
     * laisser une liste vide ouverte sous le curseur donnerait à croire que le geste
     * n'a pas abouti.
     *
     * @private
     */
    _croix(onglet) {
        const croix = document.createElement('button');
        croix.type = 'button';
        croix.className = 'list-tabs-overflow-close';
        croix.setAttribute('aria-label', `Fermer l'onglet « ${this.cfg.libelle(onglet)} »`);
        croix.title = 'Fermer cet onglet';

        // L'icône de la croix est CLONÉE de l'onglet réel : les icônes du projet sont
        // résolues côté serveur, et en écrire une ici ferait un second jeu qui
        // cesserait de suivre le premier. À défaut, un « × » textuel — une croix
        // absente rendrait le bouton invisible.
        const source = onglet.querySelector('.workspace-tab-close, .list-tab-close');
        const svg = source?.querySelector('svg');
        if (svg) {
            const copie = svg.cloneNode(true);
            copie.setAttribute('aria-hidden', 'true');
            croix.appendChild(copie);
        } else {
            croix.textContent = '×';
        }

        croix.addEventListener('click', (e) => {
            e.stopPropagation();
            this.cfg.fermer(onglet);
            this.recalculer();
            if (this.replies.length === 0) {
                this.fermer();
            } else {
                this._peupler();
            }
        });

        return croix;
    }
}
