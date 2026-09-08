/**
 * LES VALEURS D'UN GROUPE DE CHIPS DÉFILENT QUAND LA PLACE MANQUE.
 *
 * Une rubrique peut aligner dix chips — « Général, Police, Tranche, Prime, Commission,
 * Taxe courtier, Taxe assureur, Intermédiaire, Rétro intermédiaire, Rétro agent » —, et
 * l'écran des colonnes de l'Échange autant. Dans une colonne étroite, les derniers
 * sortaient du panneau et le DÉFORMAIENT : ni visibles, ni atteignables. Un filtre qu'on
 * ne peut pas voir est un filtre qui n'existe pas.
 *
 * Le retour à la ligne — le comportement d'origine — ne convenait pas : la pilule
 * devenait un pavé de deux ou trois rangées qui mangeait la liste par le haut, alors que
 * c'est justement la place qui manque.
 *
 * ── CE QUE CE MODULE POSE ───────────────────────────────────────────────────────────
 *     .jsb-preset-filters-wrap        ← C'EST ELLE, la pilule : fond, arrondi, marge
 *          .jsb-preset-filters__titre ← le nom du critère, HORS du défilement
 *          .jsb-preset-zone           ← référent des flèches, ne défile pas
 *               .jsb-preset-filters   ← la seule chose qui défile : les valeurs
 *               boutons ‹ et ›, EN SURCOUCHE
 *
 * Le défilement lui-même est en CSS : il marche donc au pavé tactile, au doigt et au
 * clavier sans une ligne de JavaScript. Trois choses ne s'obtiennent pas en CSS, et sans
 * elles le rendu est mauvais — on l'a vu à chaque étape :
 *
 * 1. LA MOLETTE. Une souris n'a qu'une roue, verticale, et la barre de défilement est
 *    masquée. Sans traduction, on voit une pilule qui déborde sans aucun geste naturel
 *    pour aller au bout.
 * 2. QUAND MONTRER LES FLÈCHES, ce que le CSS ne sait pas : il ignore où en est le
 *    défilement.
 * 3. LES FONDUS DE BORD, pour la même raison.
 *
 * ── DEUX ERREURS QUE CE FICHIER A FAITES, ET QU'IL NE DOIT PAS REFAIRE ──────────────
 * ⚠ LES FLÈCHES NE PRENNENT PAS DE PLACE. Posées dans le flux, elles occupaient une
 * cinquantaine de pixels — ceux-là mêmes qu'on mesurait pour décider s'il fallait
 * défiler. Une pilule qui tenait tout juste se mettait donc à défiler À CAUSE de ses
 * propres flèches, et n'en sortait plus : « Tout décocher » s'affichait amputé alors que
 * la place ne manquait pas. En surcouche, les afficher ne change aucune largeur.
 *
 * ⚠ ET IL N'Y A PLUS DE « MODE » À BASCULER. La mise en page est la même que le contenu
 * tienne ou non ; seules les flèches et les fondus s'allument. Une pilule qui tient est
 * donc rigoureusement identique à ce qu'elle était avant ce module — c'est ce qui permet
 * de l'appliquer aussi aux barres d'actions (« Exporter, Importer, Historique ») sans
 * rien leur changer.
 */
export class DefilementChips {
    /**
     * @param {HTMLElement} racine      - l'élément qui contient les rangées de chips.
     * @param {string}      [selecteur] - où les chercher sous cette racine.
     */
    constructor(racine, selecteur = ':scope > .jsb-preset-filters-bar .jsb-preset-filters') {
        this.racine = racine;
        this.selecteur = selecteur;
        this.zones = [];
        this.observateur = null;
        this.boundMolette = (e) => this._molette(e);
        this.boundDefilement = (e) => this._marquer(e.currentTarget.closest('.jsb-preset-zone'));
    }

    /**
     * Équipe chaque groupe et met son débordement sous surveillance.
     *
     * Idempotent : on peut le rappeler après chaque rendu sans empiler les enveloppes ni
     * les écouteurs.
     */
    brancher() {
        this.detruire();

        const pilules = Array.from(this.racine.querySelectorAll(this.selecteur));
        if (pilules.length === 0) return;

        this.zones = pilules.map((pilule) => this._equiper(pilule)).filter(Boolean);
        this.recalculer();

        if (typeof ResizeObserver === 'undefined') return;
        this.observateur = new ResizeObserver(() => this.recalculer());
        this.zones.forEach((zone) => this.observateur.observe(zone));
    }

    /** Rallume ou éteint flèches et fondus, groupe par groupe. */
    recalculer() {
        this.zones.forEach((zone) => this._marquer(zone));
    }

    detruire() {
        this.observateur?.disconnect();
        this.observateur = null;
        this.zones.forEach((zone) => {
            const pilule = zone.querySelector('.jsb-preset-filters');
            pilule?.removeEventListener('wheel', this.boundMolette);
            pilule?.removeEventListener('scroll', this.boundDefilement);
        });
        this.zones = [];
    }

    /**
     * Amène un chip dans la partie visible de son groupe.
     *
     * ⚠ CE N'EST PAS UN CONFORT, C'EST UNE CONDITION. Un chip actif hors champ, c'est une
     * liste filtrée sans que rien à l'écran ne dise par quoi (Nielsen 1). On le ramène
     * donc quand l'état des chips vient de changer.
     */
    montrer(chip) {
        const pilule = chip?.closest('.jsb-preset-zone > .jsb-preset-filters');
        if (!pilule || pilule.scrollWidth <= pilule.clientWidth) return;

        const bordPilule = pilule.getBoundingClientRect();
        const bordChip = chip.getBoundingClientRect();

        // `scrollIntoView` remonterait aussi les ancêtres — donc la liste entière. On ne
        // touche qu'au défilement du groupe. La marge de 26 px dégage le chip de la
        // flèche qui le survolerait.
        if (bordChip.left < bordPilule.left + 26) {
            pilule.scrollLeft -= bordPilule.left + 26 - bordChip.left;
        } else if (bordChip.right > bordPilule.right - 26) {
            pilule.scrollLeft += bordChip.right - bordPilule.right + 26;
        }
    }

    /**
     * Construit l'enveloppe, sort le titre, pose la zone et ses deux flèches.
     *
     * @returns {HTMLElement|null} la zone, référent de tout le reste.
     * @private
     */
    _equiper(pilule) {
        let zone = pilule.closest('.jsb-preset-zone');

        if (!zone) {
            const enveloppe = document.createElement('div');
            enveloppe.className = 'jsb-preset-filters-wrap';
            pilule.parentNode.insertBefore(enveloppe, pilule);

            // ⚠ LE TITRE SORT DE LA ZONE QUI DÉFILE. Laissé dedans, il fallait le coller à
            // gauche — et les valeurs lui passaient dessous, ce qui donnait un
            // chevauchement de deux textes, désagréable et illisible. Dehors, le
            // défilement COMMENCE après lui : plus rien ne peut passer sous rien.
            const titre = pilule.querySelector('.jsb-preset-filters__titre');
            if (titre) enveloppe.appendChild(titre);

            zone = document.createElement('div');
            zone.className = 'jsb-preset-zone';
            enveloppe.appendChild(zone);
            zone.appendChild(pilule);

            const nom = titre ? `« ${titre.textContent.trim()} »` : 'de filtre';
            zone.appendChild(this._fleche('prev', `Voir les options ${nom} précédentes`));
            zone.appendChild(this._fleche('next', `Voir les options ${nom} suivantes`));
        }

        pilule.addEventListener('wheel', this.boundMolette, { passive: false });
        pilule.addEventListener('scroll', this.boundDefilement, { passive: true });

        return zone;
    }

    /** @private */
    _fleche(sens, intitule) {
        const bouton = document.createElement('button');
        bouton.type = 'button';
        bouton.className = `jsb-preset-nav jsb-preset-nav--${sens}`;
        // Intitulé EXPLICITE, jamais un simple chevron : « ‹ » ne dit pas de quoi il est
        // la flèche quand quatre groupes se suivent (WCAG 2.4.4).
        bouton.setAttribute('aria-label', intitule);
        bouton.title = intitule;
        const points = sens === 'prev' ? '15 18 9 12 15 6' : '9 18 15 12 9 6';
        bouton.innerHTML =
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"'
            + ' stroke="currentColor" stroke-width="2.5" stroke-linecap="round"'
            + ` stroke-linejoin="round" aria-hidden="true"><polyline points="${points}"/></svg>`;
        bouton.addEventListener('click', (e) => this._avancer(e.currentTarget, sens));
        return bouton;
    }

    /**
     * Fait glisser d'une « page » — la largeur visible moins un chevauchement, pour qu'un
     * chip reste d'un bord à l'autre et qu'on ne perde pas le fil.
     * @private
     */
    _avancer(bouton, sens) {
        const pilule = bouton.closest('.jsb-preset-zone')?.querySelector('.jsb-preset-filters');
        if (!pilule) return;

        const pas = Math.max(80, pilule.clientWidth - 60);
        pilule.scrollBy({ left: sens === 'prev' ? -pas : pas, behavior: 'smooth' });
    }

    /**
     * Traduit la molette verticale en défilement horizontal, tant qu'il reste du chemin.
     * @private
     */
    _molette(event) {
        const pilule = event.currentTarget;
        if (pilule.scrollWidth <= pilule.clientWidth) return;

        // Un geste déjà horizontal (pavé tactile, souris à roue latérale) n'a pas besoin
        // de nous : le navigateur le fait mieux, et avec son inertie.
        if (Math.abs(event.deltaX) > Math.abs(event.deltaY)) return;

        const reste = event.deltaY < 0
            ? pilule.scrollLeft
            : pilule.scrollWidth - pilule.clientWidth - pilule.scrollLeft;

        // ⚠ AU BOUT, ON REND LA MAIN. Retenir la molette ferait de la pilule un piège : le
        // curseur passe dessus en descendant la page, et la page cesserait d'avancer.
        if (reste <= 1) return;

        event.preventDefault();
        pilule.scrollLeft += event.deltaY;
    }

    /**
     * Allume ce qu'il y a à atteindre, éteint le reste.
     *
     * ⚠ AUCUNE DE CES CLASSES NE CHANGE UNE LARGEUR. Flèches en surcouche, fondus en
     * pseudo-éléments absolus : la mesure qui les décide ne peut donc pas être faussée par
     * leur propre apparition. C'est ce qui rend ce calcul stable, là où la version
     * précédente s'auto-entretenait.
     * @private
     */
    _marquer(zone) {
        const pilule = zone?.querySelector('.jsb-preset-filters');
        if (!pilule) return;

        const debordement = pilule.scrollWidth - pilule.clientWidth;
        // 1 px de tolérance : les largeurs sont fractionnaires, et un « presque zéro »
        // allumerait une flèche en permanence au bout de la course.
        const reste = debordement - pilule.scrollLeft;

        zone.classList.toggle('is-defilable', debordement > 1);
        zone.classList.toggle('is-defile-gauche', pilule.scrollLeft > 1);
        zone.classList.toggle('is-defile-droite', reste > 1);

        const prev = zone.querySelector('.jsb-preset-nav--prev');
        const next = zone.querySelector('.jsb-preset-nav--next');
        if (prev) prev.disabled = pilule.scrollLeft <= 1;
        if (next) next.disabled = reste <= 1;
    }
}
