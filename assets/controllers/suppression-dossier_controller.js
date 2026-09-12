import PickerBaseController from './picker-base_controller.js';
import { lireFluxNdjson } from './flux-ndjson.js';
import {
    creerJournal, ouvrirLot, terminerLot, echouerLot, lignesVisibles, bilan, phraseDuBilan, lotsARejouer,
} from './journal-suppression.js';
import {
    construireArbre, fusionnerProjections, etatInitialDeLArbre, basculerLeNoeud, etatDe,
    compterLesObjets, lots, conserver, chemin, racineDe, DETACHABLE, VERROUILLE,
} from './arbre-suppression.js';

/**
 * SUPPRIMER UN DOSSIER : l'arbre montre l'état, le panneau écrit l'histoire.
 *
 * Le HTML (`_dossier_picker.html.twig`) est chargé et inséré par le cerveau ; ce contrôleur
 * s'auto-connecte. La coque — focus, fermeture ✕/backdrop/Échap, barre de progression, zone
 * d'erreur — vient du socle `picker-base`. Ne restent ici que trois choses :
 *
 *  1. PEINDRE l'arbre rendu par le serveur, et le rendre manœuvrable au clavier ;
 *  2. tenir l'état des cases — dont la règle vit dans `arbre-suppression.js`, module PUR
 *     et testé, parce que c'est elle qui décide ce qui sera détruit ;
 *  3. DIRE, à chaque geste, ce que la validation fera.
 *
 * ⚠ AUCUN NOM N'EST INJECTÉ EN HTML. Les libellés viennent du cabinet : un client dont la
 * raison sociale contient du balisage l'exécuterait. Tout passe par `textContent`.
 */
export default class extends PickerBaseController {
    static pickerName = 'SUPPRESSION-DOSSIER';

    static targets = [
        'arbre', 'volume', 'vide', 'panneau', 'panneauTitre', 'etapes',
        'executer', 'executerTexte', 'fermer', 'pied', 'jauge',
        'chipCocher', 'chipDecocher', 'chipDeplier', 'chipReplier',
    ];

    static values = { rubrique: String, ids: Array };

    connect() {
        super.connect();
        this.arbre = null;
        this.etat = null;
        this.deplies = new Set();
        this._chargerLArbre();
    }

    // ─────────────────────────────── Chargement ───────────────────────────────

    async _chargerLArbre() {
        this._progress(true);
        try {
            // Un arbre par élément sélectionné, puis une seule forêt. Les plans sont
            // indépendants : les demander en parallèle ne fait pas travailler deux
            // transactions sur la même donnée, puisqu'aucune n'écrit.
            const projections = await Promise.all(
                this.idsValue.map((id) => this._lireArbre(id)),
            );
            const projection = fusionnerProjections(projections.filter(Boolean));
            if (!projection.racine) {
                throw new Error("Ces éléments n'ont pas pu être analysés.");
            }

            this.arbre = construireArbre(projection);
            this.etat = etatInitialDeLArbre(this.arbre);
            this.iconesPliage = projection.iconesPliage ?? { ouvert: '', ferme: '' };
            this.conservations = projection.conservations ?? [];
            this.refus = projection.refus ?? [];
            this.tronque = projection.tronque === true;

            // ⚠ TOUT EST DÉPLIÉ À L'OUVERTURE, et le chip « Déplier » le dit. On ne demande
            // pas à quelqu'un de valider une suppression définitive en lui cachant des
            // branches derrière un pliage : ce qu'il n'a pas vu, il ne l'a pas accepté.
            for (const [cle] of this.arbre.parCle) {
                if ((this.arbre.enfants.get(cle) ?? []).length > 0) this.deplies.add(cle);
            }

            this._peindre();
            this._rafraichir();
        } catch (erreur) {
            this._showError(erreur.message || "Ce dossier n'a pas pu être analysé.");
        } finally {
            this._progress(false);
            if (this.hasArbreTarget) this.arbreTarget.setAttribute('aria-busy', 'false');
        }
    }

    async _lireArbre(id) {
        const reponse = await fetch(`/admin/suppression/arbre/${this.rubriqueValue}/${id}`, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        });
        if (!reponse.ok) {
            const corps = await reponse.json().catch(() => ({}));
            throw new Error(corps.message || "Ces éléments n'ont pas pu être analysés.");
        }

        return reponse.json();
    }

    // ──────────────────────────────── Peinture ────────────────────────────────

    _peindre() {
        const racine = this.arbre?.racine;
        this.arbreTarget.replaceChildren();
        if (!racine) {
            if (this.hasVideTarget) this.videTarget.hidden = false;

            return;
        }

        // ⚠ LA RACINE EST DANS L'ARBRE, PAS SEULEMENT DANS LE TITRE. Elle était comptée au
        // panneau (« 1 × Pistes ») sans apparaître nulle part : l'inventaire annonçait donc
        // un objet que l'arbre ne montrait pas. Et c'est elle qu'on décoche pour tout
        // épargner d'un geste.
        this.arbreTarget.appendChild(this._ligne(racine, 1, 1, 1));
        this._premierFocusable();
        this._chargerLesIcones();
    }

    /**
     * Demande au cerveau les icônes des rubriques présentes — UNE par type, pas une par
     * ligne. Le circuit les met en cache pour toute la session, et même d'une session à
     * l'autre : un arbre de trois cents nœuds ne coûte que ses quelques types.
     */
    _chargerLesIcones() {
        const aCharger = new Set(
            [...this.arbreTarget.querySelectorAll('[data-icone]')].map((n) => n.dataset.icone),
        );
        if (aCharger.size === 0) return;

        if (!this._ecouteIcones) {
            this._ecouteIcones = (evenement) => {
                const { iconName, html } = evenement.detail || {};
                if (!iconName || !html) return;
                for (const hote of this.arbreTarget.querySelectorAll(`[data-icone="${CSS.escape(iconName)}"]`)) {
                    // L'unique `innerHTML` de ce contrôleur, et il porte un SVG rendu par
                    // NOTRE serveur d'icônes — jamais une donnée du cabinet.
                    hote.innerHTML = html;
                }
                this._icones.set(iconName, html);
            };
            this._icones = new Map();
            document.addEventListener('app:icon.loaded', this._ecouteIcones);
        }

        for (const nom of aCharger) {
            const dejaVue = this._icones.get(nom);
            if (dejaVue) {
                for (const hote of this.arbreTarget.querySelectorAll(`[data-icone="${CSS.escape(nom)}"]`)) {
                    hote.innerHTML = dejaVue;
                }
                continue;
            }
            this._notifyCerveau('ui:icon.request', { iconName: nom, iconSize: 16 });
        }
    }

    disconnect() {
        if (this._ecouteIcones) {
            document.removeEventListener('app:icon.loaded', this._ecouteIcones);
        }
        super.disconnect?.();
    }

    /** Une ligne d'arbre : `li[role=treeitem]`, sa case, son nom, son volume, et ses enfants. */
    _ligne(cle, niveau, rang, fratrie) {
        const noeud = this.arbre.parCle.get(cle);
        const fils = this.arbre.enfants.get(cle) ?? [];
        const verrouille = noeud.geste === VERROUILLE;

        const li = document.createElement('li');
        li.className = 'jsb-purge-noeud';
        li.setAttribute('role', 'treeitem');
        li.setAttribute('aria-level', String(niveau));
        li.setAttribute('aria-posinset', String(rang));
        li.setAttribute('aria-setsize', String(fratrie));
        li.dataset.cle = cle;
        li.tabIndex = -1;
        if (verrouille) li.setAttribute('aria-disabled', 'true');

        // ⚠ LA LIGNE ENTIÈRE EST LA CIBLE, pas la case de 16 px : le minimum de 24 px
        // (WCAG 2.5.8) ne se tient pas autrement, et au doigt il en faut 44.
        const rangee = document.createElement('div');
        rangee.className = 'jsb-purge-rangee';
        if (verrouille) rangee.classList.add('is-verrouille');
        // Les GUIDES D'INDENTATION : un filet par niveau traversé. Sans eux, la profondeur
        // ne se lit qu'au décalage, et sur cinq niveaux l'œil perd la branche qu'il suit.
        rangee.style.setProperty('--purge-niveau', String(niveau - 1));
        if (!verrouille) {
            rangee.addEventListener('click', () => this._basculer(cle));
        }

        if (fils.length > 0) {
            const ouvert = this.deplies.has(cle);
            // ⚠ UN DOSSIER, PAS UN CHEVRON. Une flèche n'indique qu'un sens ; un dossier
            // fermé dit qu'il RESTE quelque chose à voir là-dedans — ce qui, dans un arbre
            // de suppression, est exactement l'information qui compte.
            const pliage = document.createElement('button');
            pliage.type = 'button';
            pliage.className = 'jsb-purge-fleche';
            pliage.setAttribute('aria-label', ouvert ? 'Replier cette branche' : 'Déplier cette branche');
            pliage.dataset.icone = ouvert ? this.iconesPliage.ouvert : this.iconesPliage.ferme;
            pliage.addEventListener('click', (evenement) => {
                evenement.stopPropagation();
                this._deplier(cle, !ouvert);
            });
            rangee.appendChild(pliage);
            li.setAttribute('aria-expanded', ouvert ? 'true' : 'false');
        } else {
            // Une feuille n'a rien à plier : la place est réservée pour que les noms
            // restent alignés d'un niveau à l'autre.
            const creux = document.createElement('span');
            creux.className = 'jsb-purge-fleche jsb-purge-fleche--vide';
            creux.setAttribute('aria-hidden', 'true');
            rangee.appendChild(creux);
        }

        const case_ = document.createElement('span');
        case_.className = 'jsb-purge-case';
        case_.setAttribute('aria-hidden', 'true');
        rangee.appendChild(case_);

        // L'icône de la rubrique, chargée par le circuit d'icônes du cerveau (mis en cache
        // pour toute la session). Purement décorative : le type est déjà écrit à côté.
        if (noeud.icone) {
            const icone = document.createElement('span');
            icone.className = 'jsb-purge-icone';
            icone.dataset.icone = noeud.icone;
            icone.setAttribute('aria-hidden', 'true');
            rangee.appendChild(icone);
        }

        const nom = document.createElement('span');
        nom.className = 'jsb-purge-nom';
        nom.textContent = noeud.nom || `${noeud.libelle} · ${this._nombre(noeud.elements ?? 0)} éléments`;
        rangee.appendChild(nom);

        // Le type suit le nom comme une étiquette, au lieu de flotter en colonne : il
        // qualifie CETTE ligne, il ne la compare pas aux autres.
        const type = document.createElement('span');
        type.className = 'jsb-purge-type';
        type.textContent = noeud.libelle;
        rangee.appendChild(type);

        if (verrouille) {
            // ⚠ JAMAIS LA COULEUR SEULE : le rouge dit qu'il se passe quelque chose, le
            // motif dit quoi, et c'est lui que le lecteur d'écran annonce.
            const motif = document.createElement('span');
            motif.className = 'jsb-purge-motif';
            motif.textContent = noeud.verrou || 'Cet élément ne peut pas être supprimé.';
            rangee.appendChild(motif);
            li.setAttribute('aria-describedby', `${this._idDe(cle)}-motif`);
            motif.id = `${this._idDe(cle)}-motif`;
        } else if (noeud.total > 1) {
            // ⚠ « 1 OBJET » SUR CHAQUE FEUILLE EST DU BRUIT. Répété vingt fois, il noie les
            // deux ou trois branches qui, elles, en emportent des dizaines — c'est-à-dire
            // la seule information que ce chiffre avait à donner.
            const volume = document.createElement('span');
            volume.className = 'jsb-purge-volume-noeud';
            volume.textContent = `${this._nombre(noeud.total)} objets`;
            volume.title = 'Cette branche emporte ce nombre d’objets, elle-même comprise.';
            rangee.appendChild(volume);
        }

        li.appendChild(rangee);

        if (fils.length > 0 && this.deplies.has(cle)) {
            const groupe = document.createElement('ul');
            groupe.className = 'jsb-purge-groupe';
            groupe.setAttribute('role', 'group');
            // Le filet vertical du niveau : il se pose sur le GROUPE, pas sur chaque ligne,
            // pour rester continu du premier enfant au dernier.
            groupe.style.setProperty('--purge-guide', String(niveau - 1));
            fils.forEach((enfant, index) => {
                groupe.appendChild(this._ligne(enfant, niveau + 1, index + 1, fils.length));
            });
            li.appendChild(groupe);
        }

        return li;
    }

    // ───────────────────────────────── Gestes ─────────────────────────────────

    _basculer(cle) {
        if (this.gele) return; // l'arbre est un témoin pendant l'exécution, pas un formulaire
        const etatActuel = etatDe(this.arbre, this.etat, cle);
        basculerLeNoeud(this.arbre, this.etat, cle, etatActuel !== 'true');
        this._peindre();
        this._rafraichir();
        this._focusSur(cle);
    }

    _deplier(cle, ouvrir) {
        if (ouvrir) this.deplies.add(cle); else this.deplies.delete(cle);
        this._peindre();
        this._rafraichir();
        this._focusSur(cle);
    }

    /** « Tout cocher » / « Tout décocher » : le geste de masse, depuis la racine. */
    toutCocher() {
        if (this.gele || !this.arbre?.racine) return;
        basculerLeNoeud(this.arbre, this.etat, this.arbre.racine, true);
        this._peindre();
        this._rafraichir();
    }

    toutDecocher() {
        if (this.gele || !this.arbre?.racine) return;
        basculerLeNoeud(this.arbre, this.etat, this.arbre.racine, false);
        this._peindre();
        this._rafraichir();
    }

    /** Déplie ou replie l'arbre entier — pour survoler, puis pour se concentrer. */
    toutDeplier() {
        for (const [cle] of this.arbre?.parCle ?? []) {
            if ((this.arbre.enfants.get(cle) ?? []).length > 0) this.deplies.add(cle);
        }
        this._peindre();
        this._rafraichir();
    }

    toutReplier() {
        this.deplies.clear();
        if (this.arbre?.racine) this.deplies.add(this.arbre.racine);
        this._peindre();
        this._rafraichir();
    }

    /** Navigation clavier complète : sans elle, l'arbre n'existe que pour la souris. */
    auClavier(evenement) {
        const courant = evenement.target.closest('[role="treeitem"]');
        if (!courant) return;
        const cle = courant.dataset.cle;
        const visibles = [...this.arbreTarget.querySelectorAll('[role="treeitem"]')];
        const index = visibles.indexOf(courant);

        const actions = {
            ArrowDown: () => visibles[index + 1]?.focus(),
            ArrowUp: () => visibles[index - 1]?.focus(),
            Home: () => visibles[0]?.focus(),
            End: () => visibles[visibles.length - 1]?.focus(),
            ArrowRight: () => {
                if ((this.arbre.enfants.get(cle) ?? []).length === 0) return;
                if (this.deplies.has(cle)) visibles[index + 1]?.focus();
                else this._deplier(cle, true);
            },
            ArrowLeft: () => {
                if (this.deplies.has(cle)) this._deplier(cle, false);
                else this._focusSur(this.arbre.parCle.get(cle)?.parent);
            },
            ' ': () => this._basculer(cle),
            Enter: () => this._basculer(cle),
        };

        const action = actions[evenement.key];
        if (action) {
            evenement.preventDefault();
            action();
        }
    }

    // ────────────────────────────── Rafraîchissement ──────────────────────────

    _rafraichir() {
        // Le tri-état est DÉRIVÉ à chaque rendu, jamais mémorisé : un état stocké finit
        // toujours par afficher « partiel » sur une branche entièrement cochée.
        for (const li of this.arbreTarget.querySelectorAll('[role="treeitem"]')) {
            const cle = li.dataset.cle;
            const valeur = this.arbre.parCle.get(cle)?.geste === VERROUILLE ? 'false' : etatDe(this.arbre, this.etat, cle);
            li.setAttribute('aria-checked', valeur);
            li.classList.toggle('est-coche', valeur === 'true');
            li.classList.toggle('est-partiel', valeur === 'mixed');
            li.classList.toggle('est-conserve', valeur === 'false');
        }

        const { retenus, conserves, total, verrous } = compterLesObjets(this.arbre, this.etat);
        const morceaux = [`${this._nombre(retenus)} objets seront supprimés`];
        if (conserves > 0) morceaux.push(`${this._nombre(conserves)} conservés`);
        if (verrous > 0) morceaux.push(`${this._nombre(verrous)} impossibles à supprimer`);
        this.volumeTarget.textContent = morceaux.join(' · ');

        this._rafraichirLesChips();
        this._peindreLePlan(retenus, conserves, total);

        const aFaire = lots(this.arbre, this.etat);
        this.executerTarget.disabled = aFaire.length === 0;
        this.executerTexteTarget.textContent = retenus > 0
            ? `Supprimer ${this._nombre(retenus)} objets`
            : 'Supprimer';
    }

    /**
     * LES CHIPS DISENT L'ÉTAT, ILS NE FONT PAS QUE LE CHANGER.
     *
     * ⚠ UN CHIP ÉTEINT ALORS QUE TOUT EST COCHÉ EST UN MENSONGE sur l'état du système
     * (Nielsen 1) — et il tombe au moment précis où l'utilisateur décide d'effacer. « Tout
     * coché » et « rien coché » sont deux états qui se lisent ; entre les deux, aucun des
     * deux chips ne s'allume, parce que c'est le tri-état de l'arbre qui répond.
     */
    _rafraichirLesChips() {
        const racine = this.arbre?.racine;
        if (!racine) return;

        const selection = etatDe(this.arbre, this.etat, racine);
        this._allumer(this.hasChipCocherTarget ? this.chipCocherTarget : null, selection === 'true');
        this._allumer(this.hasChipDecocherTarget ? this.chipDecocherTarget : null, selection === 'false');

        const pliables = [...this.arbre.parCle.keys()]
            .filter((cle) => (this.arbre.enfants.get(cle) ?? []).length > 0);
        const ouverts = pliables.filter((cle) => this.deplies.has(cle)).length;
        this._allumer(this.hasChipDeplierTarget ? this.chipDeplierTarget : null,
            pliables.length > 0 && ouverts === pliables.length);
        // « Replier » est atteint quand seule la racine reste ouverte : la refermer aussi
        // masquerait le dossier entier, ce qui n'est pas un état utile.
        this._allumer(this.hasChipReplierTarget ? this.chipReplierTarget : null,
            pliables.length > 0 && ouverts <= 1);
    }

    _allumer(chip, actif) {
        if (!chip) return;
        chip.classList.toggle('is-active', actif);
        chip.setAttribute('aria-pressed', actif ? 'true' : 'false');
    }

    /** Le panneau « 01 Plan » : l'inventaire de ce qui part, recalculé à chaque décochage. */
    _peindreLePlan(retenus, conserves, total) {
        const panneau = this.panneauTarget;
        panneau.replaceChildren();
        this.panneauTitreTarget.textContent = retenus > 0
            ? `${this._nombre(retenus)} objets vont partir`
            : 'Rien ne partira';

        const parNature = new Map();
        for (const [cle, noeud] of this.arbre.parCle) {
            if (this.etat.get(cle) !== true || noeud.geste === VERROUILLE) continue;
            const poids = this._poids(cle);
            if (poids <= 0) continue;
            parNature.set(noeud.libelle, (parNature.get(noeud.libelle) ?? 0) + poids);
        }

        panneau.appendChild(this._liste([...parNature].map(
            ([libelle, nombre]) => `${this._nombre(nombre)} × ${libelle}`,
        )));

        if (conserves > 0) {
            panneau.appendChild(this._titre('Conservé'));
            const gardees = [];
            for (const [cle, noeud] of this.arbre.parCle) {
                if (this.etat.get(cle) === true || noeud.geste === VERROUILLE) continue;
                if (noeud.nature === DETACHABLE) gardees.push(chemin(this.arbre, cle));
            }
            panneau.appendChild(this._liste(gardees.length > 0
                ? gardees
                : [`${this._nombre(conserves)} objets épargnés par vos décochages.`]));
        }

        if (this.conservations.length > 0) {
            panneau.appendChild(this._titre('Pièces partagées'));
            panneau.appendChild(this._liste(this.conservations));
        }
        if (this.refus.length > 0) {
            panneau.appendChild(this._titre('Ce qui résiste'));
            panneau.appendChild(this._liste(this.refus, 'est-refus'));
        }
        if (this.tronque) {
            panneau.appendChild(this._titre('Affichage'));
            panneau.appendChild(this._liste([
                'Le dossier est trop vaste pour être détaillé ligne à ligne : les groupes les plus nombreux sont regroupés.',
            ]));
        }

        this.piedTarget.textContent = total > 0 && retenus === total
            ? 'Cette action est définitive : la totalité du dossier sera supprimée.'
            : 'Cette action est définitive.';
    }

    // ──────────────────────────────── Outillage ───────────────────────────────

    _poids(cle) {
        const noeud = this.arbre.parCle.get(cle);
        let desEnfants = 0;
        for (const enfant of this.arbre.enfants.get(cle) ?? []) {
            desEnfants += this.arbre.parCle.get(enfant)?.total ?? 0;
        }

        return Math.max(0, (noeud?.total ?? 0) - desEnfants);
    }

    _titre(texte) {
        const h = document.createElement('h6');
        h.className = 'jsb-purge-panneau-section';
        h.textContent = texte;

        return h;
    }

    _liste(lignes, classe = '') {
        const ul = document.createElement('ul');
        ul.className = `jsb-purge-panneau-liste ${classe}`.trim();
        for (const ligne of lignes) {
            const li = document.createElement('li');
            li.textContent = ligne; // jamais d'innerHTML : ces textes viennent du cabinet
            ul.appendChild(li);
        }

        return ul;
    }

    _nombre(valeur) {
        return new Intl.NumberFormat('fr-FR').format(valeur);
    }

    _idDe(cle) {
        return `purge-${String(cle).replace(/[^a-z0-9]+/gi, '-')}`;
    }

    _focusSur(cle) {
        if (!cle) return;
        // ⚠ `CSS.escape` : une clé vaut « Cotation#88 », et le croisillon ouvrirait un
        // sélecteur d'identifiant au milieu de l'attribut.
        this.arbreTarget.querySelector(`[data-cle="${CSS.escape(cle)}"]`)?.focus();
    }

    _premierFocusable() {
        const premier = this.arbreTarget.querySelector('[role="treeitem"]');
        if (premier) premier.tabIndex = 0;
    }

    // ──────────────────────────────── Exécution ──────────────────────────────

    /**
     * Lance la suppression, lot par lot, en écrivant le journal au fil de l'eau.
     *
     * ⚠ IL N'Y A PAS DE SECONDE CONFIRMATION, et c'est délibéré : l'arbre qu'on vient de
     * trier EST le récapitulatif. Redemander « êtes-vous sûr ? » après un arbitrage branche
     * par branche n'ajouterait aucune information — seulement un clic de plus, et l'habitude
     * de le donner sans lire.
     */
    async executer() {
        if (this.enCours) return;
        await this._lancer(lots(this.arbre, this.etat));
    }

    /** Reprise : la MÊME route, avec la liste réduite aux lots qui ont échoué. */
    async rejouer() {
        if (this.enCours || !this.journal) return;
        const aRejouer = lotsARejouer(this.journal);
        if (aRejouer.length === 0) return;

        // On repart d'un journal nettoyé de ses échecs : les succès déjà acquis le restent,
        // et un motif périmé ne doit pas survivre à sa correction.
        this.journal.entrees = this.journal.entrees.filter((entree) => entree.etat !== 'echec');
        await this._lancer(aRejouer);
    }

    async _lancer(aFaire) {
        if (aFaire.length === 0) return;

        this.enCours = true;
        this.journal = this.journal ?? creerJournal();
        this._etape('execution');
        this._gelerLArbre(true);
        this._progress(true);

        // ⚠ CHAQUE ÉLÉMENT SÉLECTIONNÉ A SA PROPRE ROUTE, donc sa propre garde
        // d'appartenance : le serveur replanifie CET élément et refuse tout lot qui n'en
        // fait pas partie. Grouper les lots par racine est ce qui rend cette garde
        // possible — une route unique devrait faire confiance au navigateur.
        const parRacine = new Map();
        for (const lot of aFaire) {
            const racine = racineDe(this.arbre, lot) ?? lot;
            const id = Number(String(racine).split('#')[1]);
            if (!Number.isFinite(id)) continue;
            if (!parRacine.has(id)) parRacine.set(id, []);
            parRacine.get(id).push(lot);
        }

        const aConserver = conserver(this.arbre, this.etat);
        try {
            // EN SÉRIE, jamais en parallèle : deux dossiers peuvent partager une facture,
            // et deux transactions sur la même ligne s'attendraient jusqu'au verrou mort.
            for (const [id, lots_] of parRacine) {
                await lireFluxNdjson(`/admin/suppression/executer/${this.rubriqueValue}/${id}`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/x-ndjson' },
                    body: JSON.stringify({ lots: lots_, conserver: aConserver }),
                }, (ligne) => this._consommer(ligne));
            }
        } catch (erreur) {
            this._showError(erreur.message || "La suppression s'est interrompue.");
        } finally {
            this.enCours = false;
            this._progress(false);
            this._conclure();
        }
    }

    /** Une ligne du flux : pulsation, issue d'un lot, refus, ou résultat final. */
    _consommer(ligne) {
        if (ligne.type === 'progres') {
            this._jauger(ligne.pct);
            this._peindreLeJournal(ligne.libelle);

            return;
        }
        if (ligne.type === 'lot') {
            if (ligne.etat === 'en-cours') ouvrirLot(this.journal, ligne.cle, ligne.nom);
            if (ligne.etat === 'fait') terminerLot(this.journal, ligne.cle, ligne);
            if (ligne.etat === 'echec') echouerLot(this.journal, ligne.cle, ligne.nom, ligne.motif);
            this._peindreLeJournal();

            return;
        }
        if (ligne.type === 'erreur') {
            // ⚠ LE FLUX A DÉJÀ ENVOYÉ SON 200 : lever ici est la seule façon d'arrêter la
            // lecture. Sans cela, la boucle sortirait en silence sur une suppression
            // incomplète, et l'écran annoncerait « terminé ».
            throw new Error(ligne.message || "La suppression s'est interrompue.");
        }
        if (ligne.type === 'resultat') {
            this.restants = ligne.restants ?? [];
        }
    }

    /** Fin de course : soit il reste des lots (budget atteint), soit on dresse le bilan. */
    _conclure() {
        if ((this.restants ?? []).length > 0 && this.journal) {
            // Le serveur a rendu la main sur son budget de temps. On repart sans rien
            // demander : ce n'est pas la décision de l'utilisateur, c'est notre découpage.
            const suite = this.restants;
            this.restants = [];
            this._lancer(suite);

            return;
        }

        this._etape('rapport');
        this._gelerLArbre(false);
        this._peindreLeJournal();

        const journal = this.journal ?? creerJournal();
        const b = bilan(journal);
        this.panneauTitreTarget.textContent = b.succes
            ? 'Terminé'
            : `${b.echecs.length} partie(s) ont résisté`;
        this.piedTarget.textContent = phraseDuBilan(journal);

        // ⚠ LE BOUTON CHANGE DE MÉTIER, IL NE DISPARAÎT PAS. Après un échec partiel, la seule
        // action utile est de réessayer ce qui a résisté ; après un succès, c'est de fermer.
        // Laisser « Supprimer » armé relancerait une suppression déjà faite.
        if (b.succes) {
            this.executerTarget.disabled = true;
            this.executerTexteTarget.textContent = 'Supprimé';
        } else {
            this.executerTexteTarget.textContent = `Réessayer ${b.echecs.length} partie(s)`;
            this.executerTarget.disabled = false;
            this.executerTarget.dataset.action = 'click->suppression-dossier#rejouer';
        }

        this._notifyCerveau('suppression:dossier.termine', {
            rubrique: this.rubriqueValue,
            ids: this.idsValue,
            detruits: b.detruits,
            detaches: b.detaches,
            echecs: b.echecs.length,
        });
    }

    // ───────────────────────── Rendu pendant l'exécution ──────────────────────

    _peindreLeJournal(etapeEnCours = null) {
        if (!this.journal) return;
        const panneau = this.panneauTarget;
        panneau.replaceChildren();

        const { lignes, caches } = lignesVisibles(this.journal);
        const liste = document.createElement('ol');
        liste.className = 'jsb-purge-panneau-liste';
        liste.setAttribute('role', 'log');
        liste.setAttribute('aria-live', 'polite');
        liste.setAttribute('aria-relevant', 'additions');

        for (const entree of lignes) {
            const li = document.createElement('li');
            li.classList.add(`est-${entree.etat}`);
            // ⚠ UN ÉTAT SE LIT : la marque et le mot portent l'information, la teinte ne
            // fait que la souligner (WCAG 1.4.1).
            const marque = { 'en-cours': '…', fait: '✓', echec: '✗' }[entree.etat] || '';
            li.textContent = entree.etat === 'echec'
                ? `${marque} ${entree.nom} — Échec : ${entree.motif}`
                : `${marque} ${entree.nom}${entree.detruits ? ` — ${this._nombre(entree.detruits)} objets` : ''}`;
            liste.appendChild(li);
        }
        panneau.appendChild(liste);

        if (caches > 0) {
            const reste = document.createElement('p');
            reste.setAttribute('role', 'status');
            reste.className = 'jsb-purge-panneau-section';
            reste.textContent = `… et ${this._nombre(caches)} autres lignes non affichées.`;
            panneau.appendChild(reste);
        }
        if (etapeEnCours) {
            this.panneauTitreTarget.textContent = etapeEnCours;
        }
    }

    _etape(nom) {
        const ordre = ['plan', 'execution', 'rapport'];
        const rang = ordre.indexOf(nom);
        for (const li of this.etapesTarget.querySelectorAll('[data-etape]')) {
            const index = ordre.indexOf(li.dataset.etape);
            li.classList.toggle('is-active', index === rang);
            li.classList.toggle('is-faite', index < rang);
        }
    }

    /** Pendant l'exécution, l'arbre devient un TÉMOIN : plus aucune case ne bouge. */
    _gelerLArbre(gele) {
        this.gele = gele;
        this.arbreTarget.setAttribute('aria-disabled', gele ? 'true' : 'false');
        this.arbreTarget.classList.toggle('est-gele', gele);
    }

    _jauger(pct) {
        if (!this.hasJaugeTarget) return;
        const borne = Math.max(0, Math.min(100, Number(pct) || 0));
        this.jaugeTarget.style.width = `${borne}%`;
        this.element.querySelector('[data-picker-progress]')
            ?.setAttribute('aria-valuenow', String(Math.round(borne)));
    }
}
