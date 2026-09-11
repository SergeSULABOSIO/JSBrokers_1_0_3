import { Controller } from '@hotwired/stimulus';
import { DefilementChips } from './chips-defilement.js';
// `choixARestaurer` sert aussi au tableau de bord : elle a déménagé dans un module
// générique, et on l'importe DE SA SOURCE. Passer par la ré-exportation du module voisin
// masquerait la dépendance réelle — et un jour où ce voisin cesserait de la ré-exporter,
// elle vaudrait `undefined` sans que rien ne l'ait annoncé.
import { choixARestaurer } from './choix-persiste.js';
import {
    cleDeLOnglet,
    cleDuChoix,
    cleDuPerimetre,
    exclusionsARestaurer,
    exclusionsDe,
    meriteMemorisation,
} from './echange-perimetre-persiste.js';
import { menerAuBout } from './echange-paliers.js';

/**
 * Contrôleur du composant « Importation / Exportation » (espace de travail).
 *
 * LA PROGRESSION EST RÉELLE, ET C'EST TOUT L'OBJET DE CE FICHIER.
 *
 * Une barre indéterminée dit « quelque chose se passe » et rien de plus : l'utilisateur
 * ne sait ni ce qui avance, ni combien il en reste, ni s'il a le temps d'aller chercher
 * un café. Le serveur, lui, SAIT : il a compté ses lignes avant de commencer.
 *
 * ⚠ DEUX MÉCANIQUES COEXISTENT, ET CE N'EST PAS UN DOUBLON.
 *
 * L'EXPORTATION diffuse son avancement DANS la requête qui travaille, une ligne JSON à la
 * fois — et non dans une seconde requête qui l'interrogerait, car le serveur de
 * développement n'a qu'un processus PHP et se bloquerait lui-même. C'est une opération
 * unique : elle n'a rien à reprendre, et le flux lui va très bien.
 *
 * L'IMPORTATION avance par PALIERS, chacun dans un processus neuf — c'est la seule
 * protection contre la mémoire que le contrôle à blanc retient sans la rendre. Une
 * requête qui diffuse est par construction un seul processus : elle ne pouvait donc pas
 * convenir. L'écran pousse ou observe, selon qu'un worker est en marche, et l'état vit en
 * base — un rafraîchissement retrouve l'import là où il en est.
 *
 * ⚠ RIEN N'EST INVENTÉ ICI. Le pourcentage vient du serveur ; le temps restant est
 * déduit du débit CONSTATÉ. Quand on ne sait pas, on n'affiche pas — c'est plus honnête
 * qu'un chiffre rassurant et faux.
 *
 * La barre s'éteint dans un `finally`, sur TOUS les chemins de sortie : succès, erreur
 * réseau, exception, refus de droits, solde épuisé, périmètre vide. Une barre restée
 * allumée laisse croire que l'application travaille encore.
 */
export default class extends Controller {
    static targets = [
        'boutonExport',
        'donnee',
        'module',
        'compteSelection',
        'rappelRestauration',
        'validite',
        'exercice',
        'noteValidite',
        'resumePerimetre',
        'fichier',
        'nomFichier',
        'suppressions',
        'boutonControle',
        'boutonConfirmation',
        'rapport',
        // Le bandeau du travail en cours : il était rendu une fois puis figé, alors que la
        // barre du haut avançait. Deux indicateurs du même travail qui se contredisent.
        'travailPhase',
        'travailCompte',
        'travailBarre',
        'travailJauge',
    ];

    static values = {
        url: String,
        onglet: String,
        exportUrl: String,
        importUrl: String,
        idEntreprise: Number,
        // L'import à reprendre à l'affichage, 0 s'il n'y en a pas. Cf. `#reprendre()`.
        reprendreRun: Number,
    };

    /**
     * Verrou de réentrance, commun à tous les gestes longs. Le bouton désarmé suffit à
     * l'utilisateur ; ce drapeau couvre le reste — une touche Entrée maintenue, un
     * appel programmatique. Deux exports simultanés, ce sont deux occurrences.
     */
    #occupe = false;

    /**
     * Le rappel « votre choix précédent a été repris » est-il affiché ?
     *
     * Il ne parle QUE d'un choix venu d'une session précédente. Dès que l'utilisateur
     * revient au périmètre complet, il n'a plus rien à dire — et une phrase qui décrit
     * un état révolu est pire qu'une phrase absente.
     */
    #rappelAffiche = false;

    /**
     * L'écran a-t-il fini de se poser ?
     *
     * ⚠ LE PÉRIMÈTRE D'OFFICE N'EST PAS UN CHOIX. L'export arrive avec la famille
     * Production cochée ; l'enregistrer au premier affichage reviendrait à figer une
     * proposition en décision — et le jour où ce défaut changerait, l'ancien
     * continuerait de s'appliquer à ceux qui n'ont jamais rien demandé.
     *
     * On n'écrit donc qu'après un geste.
     */
    #initialise = false;

    connect() {
        // ⚠ L'ONGLET D'ABORD, ET AVANT TOUT LE RESTE. Il décide de ce que le serveur a
        // rendu : reposer des chips ou brancher le défilement sur une vue qu'on s'apprête
        // à remplacer serait du travail jeté, et l'écran clignoterait deux fois.
        if (this.#reprendreLOnglet()) return;

        this.#restaurerLeChoix('validite', this.validiteTargets, 'echangeValiditeParam');
        this.#restaurerLeChoix('exercice', this.exerciceTargets, 'echangeExerciceParam');
        this.#rappelerLaValidite();
        this.#restaurerLePerimetre();
        this.#rafraichirSelection();
        this.#initialise = true;

        // ⚠ LES FAMILLES DE COLONNES DÉBORDAIENT L'ÉCRAN DE CHOIX. « Général, Police,
        // Tranche, Prime, Commission… » : la pilule s'élargissait au-delà du panneau et
        // le DÉFORMAIT, le dernier chip sortant par la droite. Elle défile désormais,
        // titre du groupe collé à gauche.
        //
        // ⚠ ET LA BARRE D'ACTIONS N'EN SOUFFRE PAS. « Exporter, Importer, Historique »
        // passe par le même module : il enveloppe TOUTES les pilules — c'est ainsi que le
        // titre sort du défilement — mais n'allume flèches et fondus que sur celles qui
        // débordent réellement. Celle-là tient toujours sur une ligne, et reste immobile.
        this._defilementDesChips = new DefilementChips(
            this.element,
            '.jsb-preset-filters-bar .jsb-preset-filters',
        );
        this._defilementDesChips.brancher();

        this.#reprendre();
    }

    disconnect() {
        this._defilementDesChips?.detruire();
    }

    /**
     * REPREND UN IMPORT QUI AVANCE ENCORE.
     *
     * ⚠ C'EST LA CONTREPARTIE DU TRAVAIL PAR PALIERS. Il ne vit plus dans la requête qui
     * l'a lancé : rafraîchir la page, changer d'onglet ou revenir le lendemain laissait
     * l'utilisateur devant un écran muet pendant que son portefeuille se reprenait. Il
     * redéposait alors son fichier par doute — c'est-à-dire au pire moment.
     *
     * Sans worker, c'est aussi ce qui fait REPARTIR le travail : l'écran est le pousseur,
     * et un import interrompu par une fermeture d'onglet reprend là où il s'était arrêté.
     */
    async #reprendre() {
        const idRun = this.hasReprendreRunValue ? this.reprendreRunValue : 0;
        if (!idRun || this.#occupe) return;

        this.#occupe = true;
        this.#demarrer();

        try {
            await this.#menerAuBout(this.#urlRun(idRun, 'etat'), { method: 'GET' });
            this.#reload();
        } catch (error) {
            console.error('[echange] Reprise de l’import :', error);
            this.#notifier('error', error.message || "L'importation en cours n'a pas pu reprendre.");
        } finally {
            this.#terminer();
            this.#occupe = false;
        }
    }

    /** Changement d'onglet (chip) : `data-echange-onglet-param`. */
    changeOnglet(event) {
        const onglet = event.params.onglet;
        if (!onglet || onglet === this.ongletValue) return;
        this.ongletValue = onglet;
        this.#memoriserLOnglet(onglet);
        this.#reload();
    }

    /**
     * REPOSE L'ONGLET RETENU au rechargement de la page.
     *
     * ⚠ TOUT CHIP DOIT SURVIVRE AU F5, celui-ci comme les autres — et il était le seul à
     * ne pas le faire, alors qu'il est le premier qu'on pose. Un cabinet en pleine reprise
     * retombait sur « Exporter » à chaque rafraîchissement, et devait recliquer avant de
     * retrouver son import.
     *
     * ⚠ L'ONGLET NE SE REPOSE PAS COMME LES AUTRES RÉGLAGES. Validité et exercice vivent
     * dans le DOM déjà rendu : les restaurer, c'est déplacer une classe. L'onglet, lui,
     * décide de ce que le SERVEUR a rendu — il faut donc redemander le composant. D'où un
     * aller-retour, et un seul : au retour, le mémorisé vaut l'onglet courant et rien ne
     * se redéclenche.
     *
     * ⚠ ET ON NE REPOSE QUE CE QUI EST ENCORE OFFERT. « Importer » disparaît de l'écran
     * quand le droit d'écriture est retiré : le reposer demanderait au serveur une vue
     * qu'il refuse, et l'utilisateur verrait un aller-retour pour rien.
     */
    #reprendreLOnglet() {
        const chips = this.element.querySelectorAll('[data-echange-onglet-param]');
        if (chips.length === 0) return false;

        let memorise = null;
        try {
            memorise = window.localStorage.getItem(cleDeLOnglet(this.idEntrepriseValue));
        } catch (error) {
            return false;
        }

        const offerts = Array.from(chips, (chip) => chip.dataset.echangeOngletParam);
        const retenu = choixARestaurer(memorise, offerts);
        if (retenu === null || retenu === this.ongletValue) return false;

        this.ongletValue = retenu;
        this.#reload();

        return true;
    }

    #memoriserLOnglet(onglet) {
        try {
            window.localStorage.setItem(cleDeLOnglet(this.idEntrepriseValue), onglet);
        } catch (error) {
            // Stockage refusé (navigation privée, quota) : l'écran reste utilisable, il
            // oublie seulement le choix. Rien à dire à l'utilisateur.
        }
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Choix des données à exporter
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * Coche ou décoche une donnée, en tirant ses dépendances avec elle.
     *
     * ⚠ COCHER TIRE, DÉCOCHER POUSSE. Une opportunité a besoin de son client : cocher
     * la première coche le second. Et décocher le client doit décocher l'opportunité,
     * sans quoi le fichier renverrait vers des lignes absentes — un classeur qu'on ne
     * pourrait pas réimporter.
     *
     * Le serveur refait cette fermeture de toute façon : ce qui se joue ici, c'est que
     * l'utilisateur la VOIE avant de cliquer, au lieu de la découvrir dans le fichier.
     */
    basculerDonnee(event) {
        const code = event.params.code;
        const coche = event.target.checked;
        if (!code) return;

        if (coche) {
            this.#cocherAvecDependances(code, new Set());
        } else {
            this.#decocherLesDependants(code, new Set());
        }

        this.#rafraichirSelection();
    }

    /**
     * QUELLES TRANCHES L'ÉTAT RETIENT : polices, projets, caduques — ou toutes.
     *
     * ⚠ UN SEUL CHIP À LA FOIS, et c'est le sens même de la question : la partition est
     * complète (souscrites ⊎ en attente ⊎ caduques = toutes), donc en cocher deux
     * reviendrait à en cocher un troisième qui existe déjà. Ce n'est pas un filtre
     * cumulatif comme les familles de colonnes, c'est un choix.
     */
    choisirValidite(event) {
        // Même précaution que pour l'exercice : on compare des chaînes à des chaînes.
        const choisie = String(event.params.validite ?? '');
        if (choisie === '') return;

        for (const chip of this.validiteTargets) {
            const actif = chip.dataset.echangeValiditeParam === choisie;
            chip.classList.toggle('is-active', actif);
            chip.setAttribute('aria-pressed', actif ? 'true' : 'false');
        }

        this.#memoriserLeChoix('validite', choisie);
        this.#rappelerLaValidite();
    }

    /**
     * ⚠ TOUT CHIP SURVIT AU F5. C'est la règle de la maison, et elle vaut pour ces deux-là
     * comme pour la sélection des colonnes : un chip est un CHOIX, et refaire un choix à
     * chaque rechargement finit par dissuader d'en faire.
     *
     * Un seul chemin sert la validité et l'exercice — ils ne diffèrent que par leur nom et
     * leur famille de boutons. En écrire deux, c'était se condamner à ne corriger qu'un
     * des deux le jour venu.
     */
    #memoriserLeChoix(nom, valeur) {
        if (!this.#initialise) return;

        try {
            window.localStorage.setItem(cleDuChoix(this.idEntrepriseValue, this.ongletValue || 'exporter', nom), valeur);
        } catch (error) {
            // Navigation privée, quota atteint : le choix vaut pour cette session, ce qui
            // était l'ancien comportement. Une commodité perdue ne casse pas l'écran.
        }
    }

    /** Repose un chip mémorisé, s'il est encore proposé. */
    #restaurerLeChoix(nom, chips, attribut) {
        if (chips.length === 0) return;

        let memorise = null;
        try {
            memorise = window.localStorage.getItem(cleDuChoix(this.idEntrepriseValue, this.ongletValue || 'exporter', nom));
        } catch (error) {
            return;
        }

        const offertes = chips.map((c) => c.dataset[attribut]);
        const retenu = choixARestaurer(memorise, offertes);
        if (retenu === null) return;

        for (const chip of chips) {
            const actif = chip.dataset[attribut] === retenu;
            chip.classList.toggle('is-active', actif);
            chip.setAttribute('aria-pressed', actif ? 'true' : 'false');
        }
    }

    /**
     * QUEL EXERCICE : l'année de la date d'effet des polices.
     *
     * ⚠ UN SEUL À LA FOIS, comme la validité : ce n'est pas un filtre cumulatif mais un
     * découpage. « Tous » n'est pas la somme des années — il garde aussi les tranches
     * sans police, qui n'ont pas d'exercice.
     */
    choisirExercice(event) {
        // ⚠ STIMULUS ANALYSE SES PARAMÈTRES EN JSON. « 2026 » arrive donc en NOMBRE, quand
        // `dataset` rend toujours une chaîne : la comparaison stricte échouait pour toutes
        // les années, et aucun chip d'exercice ne s'allumait jamais. Le défaut ne touchait
        // pas la validité, dont les valeurs ne ressemblent pas à des nombres — c'est
        // exactement ce qui le rendait difficile à voir.
        const choisi = String(event.params.exercice ?? '');
        if (choisi === '') return;

        for (const chip of this.exerciceTargets) {
            const actif = chip.dataset.echangeExerciceParam === choisi;
            chip.classList.toggle('is-active', actif);
            chip.setAttribute('aria-pressed', actif ? 'true' : 'false');
        }

        this.#memoriserLeChoix('exercice', choisi);
        this.#rappelerLaValidite();
    }

    /** L'exercice retenu, ou « tous » à défaut. */
    #exerciceChoisi() {
        const actif = this.exerciceTargets.find((c) => c.classList.contains('is-active'));

        return actif?.dataset.echangeExerciceParam || 'tous';
    }

    /** La validité retenue, ou « toutes » à défaut. */
    #validiteChoisie() {
        const actif = this.validiteTargets.find((c) => c.classList.contains('is-active'));

        return actif?.dataset.echangeValiditeParam || 'toutes';
    }

    /**
     * ⚠ UN ÉTAT RESTREINT DOIT LE DIRE. Un fichier des seuls PROJETS ressemble trait pour
     * trait à un état de polices — mêmes colonnes, montants de même allure. Le confondre
     * avec le portefeuille réel, c'est annoncer un chiffre d'affaires qu'on n'a pas.
     */
    #rappelerLaValidite() {
        if (!this.hasNoteValiditeTarget) return;

        const restrictions = [];

        const validite = this.#validiteChoisie();
        if (validite !== 'toutes') {
            const chip = this.validiteTargets.find((c) => c.dataset.echangeValiditeParam === validite);
            restrictions.push(chip?.textContent.trim() ?? validite);
        }

        const exercice = this.#exerciceChoisi();
        if (exercice !== 'tous') {
            restrictions.push(`exercice ${exercice}`);
        }

        this.noteValiditeTarget.textContent = restrictions.length === 0
            ? ''
            : `L'état ne portera que : ${restrictions.join(', ')}.`;
    }

    toutCocher() {
        this.donneeTargets.forEach((c) => { c.checked = true; });
        this.#rafraichirSelection();
    }

    toutDecocher() {
        this.donneeTargets.forEach((c) => { c.checked = false; });
        this.#rafraichirSelection();
    }

    /**
     * Coche ou décoche tout un module d'un geste — « ma production », « mes finances ».
     *
     * ⚠ LE MODULE NE COURT-CIRCUITE PAS LES DÉPENDANCES. Cocher « Production » passe par
     * le même chemin qu'un clic ligne à ligne, et tire donc les clients dont les polices
     * ont besoin, fussent-ils rangés dans un autre module. Sans cela, un geste de
     * confort produirait un fichier renvoyant vers des lignes absentes — exactement ce
     * que la fermeture par dépendances existe pour empêcher.
     *
     * La case du module peut donc finir dans un état que l'utilisateur n'a pas demandé :
     * c'est #rafraichirSelection qui la remet en accord avec ses lignes, jamais l'inverse.
     */
    basculerModule(event) {
        const module = event.params.module;
        if (!module) return;

        // ⚠ L'ÉTAT DU CHIP N'EST PAS DANS LE CHIP. C'est un bouton, pas une case : rien
        // ne le « coche ». Ce qu'il vaut se lit sur ses lignes, à l'instant du clic —
        // sans quoi un chip devenu partiel par une dépendance tirée d'ailleurs
        // renverrait l'état d'avant, et le clic ferait l'inverse de ce qu'on voit.
        const lignes = this.#casesDuModule(module);
        const entier = lignes.length > 0 && lignes.every((c) => c.checked);
        const coche = !entier;

        for (const case_ of lignes) {
            const code = case_.dataset.echangeCodeParam;
            if (coche) {
                this.#cocherAvecDependances(code, new Set());
            } else {
                this.#decocherLesDependants(code, new Set());
            }
        }

        this.#rafraichirSelection();
    }

    /** Les cases d'un module donné. */
    #casesDuModule(module) {
        return this.donneeTargets.filter((c) => c.dataset.echangeModuleParam === module);
    }

    /** Coche une donnée et, de proche en proche, tout ce dont elle a besoin. */
    #cocherAvecDependances(code, vus) {
        if (vus.has(code)) return;
        vus.add(code);

        const case_ = this.#caseDe(code);
        if (!case_) return;
        case_.checked = true;

        for (const dep of this.#dependancesDe(case_)) {
            this.#cocherAvecDependances(dep, vus);
        }
    }

    /** Décoche une donnée et tout ce qui en dépend : l'inverse exact. */
    #decocherLesDependants(code, vus) {
        if (vus.has(code)) return;
        vus.add(code);

        const case_ = this.#caseDe(code);
        if (case_) case_.checked = false;

        for (const autre of this.donneeTargets) {
            if (autre.checked && this.#dependancesDe(autre).includes(code)) {
                this.#decocherLesDependants(autre.dataset.echangeCodeParam, vus);
            }
        }
    }

    #caseDe(code) {
        return this.donneeTargets.find((c) => c.dataset.echangeCodeParam === code) || null;
    }

    #dependancesDe(case_) {
        return (case_.dataset.echangeDependancesParam || '')
            .split(',')
            .map((d) => d.trim())
            .filter(Boolean);
    }

    /** Codes actuellement retenus. Vide = tout, comme l'attend le serveur. */
    #selection() {
        return this.donneeTargets.filter((c) => c.checked).map((c) => c.dataset.echangeCodeParam);
    }

    /**
     * Grise les lignes exclues, marque celles qui n'ont été cochées que parce qu'une
     * autre en dépend, et tient le compteur à jour. Le bouton se désarme quand il n'y a
     * plus rien à exporter — proposer de générer un fichier vide n'aide personne.
     */
    #rafraichirSelection() {
        if (!this.hasDonneeTarget) return;

        const retenus = new Set(this.#selection());

        // ⚠ « REQUISE » N'A DE SENS QUE SUR UNE SÉLECTION PARTIELLE.
        //
        // Quand tout est coché, tout est requis par quelque chose : le mot apparaissait
        // alors sur presque chaque ligne et ne distinguait plus rien — du bruit, là où on
        // voulait une explication. Il ne sert qu'à répondre à une question précise :
        // « pourquoi cette donnée reste-t-elle cochée alors que je ne l'ai pas demandée ? »
        const partielle = retenus.size < this.donneeTargets.length;

        const requises = new Set();
        if (partielle) {
            for (const case_ of this.donneeTargets) {
                if (!case_.checked) continue;
                for (const dep of this.#dependancesDe(case_)) requises.add(dep);
            }
        }

        for (const case_ of this.donneeTargets) {
            const ligne = case_.closest('tr');
            if (!ligne) continue;

            const code = case_.dataset.echangeCodeParam;
            ligne.classList.toggle('is-exclue', !case_.checked);
            ligne.classList.toggle('is-requise', case_.checked && requises.has(code));

            // L'état est aussi porté à la case elle-même : un lecteur d'écran annonce
            // « exclue » ou « requise » sans avoir à deviner depuis un style.
            const label = ligne.querySelector('.ech-res-nom');
            if (label) {
                const etat = !case_.checked ? 'exclue de l’export'
                    : (requises.has(code) ? 'requise par une autre donnée' : 'incluse dans l’export');
                case_.setAttribute('aria-label', `${label.textContent.trim()} — ${etat}`);
            }
        }

        // ── L'ÉTAT DES MODULES EST DÉRIVÉ, JAMAIS SAISI ────────────────────────────
        //
        // La case d'un module n'est pas une donnée : c'est un RÉSUMÉ de ses lignes. Une
        // dépendance tirée depuis un autre groupe peut recocher une ligne sans que
        // personne n'ait touché à l'en-tête ; le laisser afficher « décoché » alors que
        // deux de ses données sortiront serait un mensonge d'écran.
        //
        // `indeterminate` existe précisément pour cela : ni tout, ni rien.
        for (const chip of this.moduleTargets) {
            const module = chip.dataset.echangeModuleParam;
            const lignes = this.#casesDuModule(module);
            const retenues = lignes.filter((c) => c.checked).length;
            const entier = retenues === lignes.length;

            // TROIS ÉTATS. `mixed` n'est pas un raffinement : sans lui, un chip éteint
            // et un chip à moitié retenu se ressembleraient, et l'utilisateur croirait
            // avoir écarté une famille dont deux données sortiront quand même.
            chip.setAttribute('aria-pressed', retenues === 0 ? 'false' : (entier ? 'true' : 'mixed'));
            chip.classList.toggle('is-active', entier);

            // Le compte porte l'état EN TOUTES LETTRES : « 4 / 10 » se lit sans couleur,
            // et survit à l'impression comme au daltonisme (WCAG 1.4.1).
            const badge = chip.querySelector('[data-echange-compte-module]');
            if (badge) {
                badge.textContent = entier ? `${lignes.length}` : `${retenues} / ${lignes.length}`;
            }

            chip.setAttribute(
                'aria-label',
                `${module} — ${retenues} donnée${retenues > 1 ? 's' : ''} retenue${retenues > 1 ? 's' : ''} sur ${lignes.length}`,
            );

            // Le détail répété en tête de groupe : on descend dans la liste sans avoir
            // à remonter aux chips pour savoir où l'on en est.
            const detail = this.element.querySelector(`[data-echange-detail-module="${CSS.escape(module)}"]`);
            if (detail) {
                detail.textContent = entier ? `${lignes.length}` : `${retenues} sur ${lignes.length}`;
                detail.classList.toggle('is-partiel', retenues > 0 && !entier);
                detail.classList.toggle('is-vide', retenues === 0);
            }
        }

        if (this.hasCompteSelectionTarget) {
            const total = this.donneeTargets.length;
            this.compteSelectionTarget.textContent = retenus.size === total
                ? `${total} donnée${total > 1 ? 's' : ''} sélectionnée${total > 1 ? 's' : ''}`
                : `${retenus.size} donnée${retenus.size > 1 ? 's' : ''} sur ${total}`;
        }

        this.#armer(this.hasBoutonExportTarget ? this.boutonExportTarget : null, retenus.size > 0);
        this.#armerControle();
        this.#resumerLePerimetre(retenus.size);
        this.#memoriserLePerimetre();

        // Le rappel parle de l'état À L'ARRIVÉE. Dès que l'utilisateur touche une case,
        // l'état affiché est le sien : la phrase décrirait un passé, ce qui est pire
        // qu'une phrase absente.
        if (this.#rappelAffiche && this.#initialise) {
            this.rappelRestaurationTarget.hidden = true;
            this.#rappelAffiche = false;
        }
    }

    /**
     * Le décompte porté par le résumé du volet d'import.
     *
     * C'est lui qui rend la restriction lisible SANS OUVRIR LE VOLET. Sans ce chiffre, il
     * faudrait ouvrir le panneau d'office à chaque visite pour ne pas mentir — et il fait
     * deux écrans de haut.
     */
    #resumerLePerimetre(retenues) {
        if (!this.hasResumePerimetreTarget) return;

        const total = this.hasDonneeTarget ? this.donneeTargets.length : 0;
        this.resumePerimetreTarget.textContent = retenues === total
            ? `les ${total} données du fichier`
            : `${retenues} donnée${retenues > 1 ? 's' : ''} sur ${total}`;
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Le périmètre survit au rechargement
    // ─────────────────────────────────────────────────────────────────────────────

    /** Clé de rangement de CE cabinet et de CET onglet. */
    #cle() {
        return cleDuPerimetre(this.idEntrepriseValue, this.ongletValue || 'exporter');
    }

    /**
     * Repose les exclusions mémorisées, PAR LE MÊME CHEMIN qu'un clic.
     *
     * ⚠ On ne repose pas les cases une à une. Décocher pousse : écarter les clients
     * écarte les polices qui les nomment. Court-circuiter cette fermeture rendrait un
     * état que l'utilisateur n'aurait pas pu produire à la main — et un fichier
     * renvoyant vers des lignes absentes.
     */
    #restaurerLePerimetre() {
        if (!this.hasDonneeTarget) return;

        let memorise = null;
        try {
            memorise = JSON.parse(window.localStorage.getItem(this.#cle()) || 'null');
        } catch (error) {
            // Navigation privée, stockage refusé, valeur corrompue : on repart de
            // « tout est coché ». Une commodité perdue ne doit jamais casser l'écran.
            return;
        }

        const codes = this.donneeTargets.map((c) => c.dataset.echangeCodeParam);
        const aReposer = exclusionsARestaurer(memorise, codes);
        for (const code of aReposer) {
            this.#decocherLesDependants(code, new Set());
        }

        // ⚠ UN ÉCRAN QUI RESTREINT SANS LE DIRE EST TROMPEUR.
        //
        // Le choix est visible — les chips et le compteur le montrent — mais il a été
        // fait dans une session précédente, et rien ne signale qu'il vient d'ailleurs.
        // Quelqu'un qui reprend le poste le lendemain exporterait une partie de son
        // cabinet en croyant tout exporter. On le DIT, une fois, à l'endroit du geste
        // (Nielsen 1 : visibilité de l'état du système).
        if (aReposer.length > 0 && this.hasRappelRestaurationTarget) {
            this.rappelRestaurationTarget.hidden = false;
            this.#rappelAffiche = true;

            // ⚠ ON N'OUVRE PLUS LE VOLET, ET C'EST DÉSORMAIS SANS DANGER.
            //
            // Il s'ouvrait pour ne pas cacher une restauration silencieuse. Depuis, son
            // RÉSUMÉ porte le décompte — « 12 données sur 42 » se lit volet fermé — et
            // c'est lui qui empêche l'écran de mentir. Forcer l'ouverture par-dessus ne
            // protégeait plus de rien : cela déroulait trois écrans de réglage à chaque
            // visite, pour redire ce que la ligne repliée disait déjà.
        }
    }

    /** Range les exclusions courantes, ou efface l'entrée quand tout est retenu. */
    #memoriserLePerimetre() {
        if (!this.hasDonneeTarget || !this.#initialise) return;

        const exclusions = exclusionsDe(this.donneeTargets.map((c) => ({
            code: c.dataset.echangeCodeParam,
            retenu: c.checked,
        })));

        try {
            if (meriteMemorisation(exclusions)) {
                window.localStorage.setItem(this.#cle(), JSON.stringify(exclusions));
            } else {
                window.localStorage.removeItem(this.#cle());
            }
        } catch (error) {
            // Quota atteint ou stockage refusé : le périmètre reste juste pour cette
            // session, ce qui est exactement l'ancien comportement.
        }
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Exportation
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * Génère l'export en suivant sa progression, puis déclenche le téléchargement.
     *
     * Deux temps, et c'est délibéré : on ne peut pas mêler des octets binaires à un flux
     * de lignes JSON sans les encoder, et encoder un classeur de plusieurs mégaoctets
     * coûterait plus cher que de l'écrire. Le serveur prépare donc le fichier en
     * racontant ce qu'il fait, puis remet un jeton ; le téléchargement suit.
     */
    async exporter() {
        if (this.#occupe) return;
        this.#occupe = true;
        this.#armer(this.hasBoutonExportTarget ? this.boutonExportTarget : null, false);
        this.#demarrer();

        // Graine stable pour ce clic : si la requête est rejouée (retry réseau), le
        // serveur reconnaît la même opération et ne facture pas deux fois.
        const graine = `${Date.now()}-${Math.random().toString(36).slice(2, 10)}`;

        try {
            const corps = new FormData();
            corps.append('op', graine);

            // LES COLONNES RETENUES. On n'envoie rien quand toutes le sont : le serveur
            // lit alors « tout le catalogue », ce qui reste juste même si une colonne y
            // est ajoutée entre l'affichage de l'écran et le clic.
            //
            // ⚠ Ce sont bien des COLONNES, pas des familles de données : l'état a une
            // maille fixe — une ligne par tranche — et rien à filtrer de ce côté-là.
            const retenues = this.#selection();
            if (this.hasDonneeTarget && retenues.length < this.donneeTargets.length) {
                corps.append('colonnes', retenues.join(','));
            }

            // Quelles tranches : polices, projets, caduques. « toutes » est le défaut du
            // serveur : ne rien envoyer revient au même, mais l'envoyer rend l'intention
            // lisible dans le journal réseau quand on cherche pourquoi un fichier est vide.
            corps.append('validite', this.#validiteChoisie());
            corps.append('exercice', this.#exerciceChoisi());

            const final = await this.#lireFlux(this.exportUrlValue, { method: 'POST', body: corps });

            if (final?.type === 'erreur') {
                throw new Error(final.message);
            }
            if (final?.type !== 'pret' || !final.jeton) {
                throw new Error("L'export n'a pas abouti.");
            }

            this.#telecharger(final.jeton, final.nom);
            this.#notifier('success', 'Export généré. Le téléchargement a démarré.');

            // L'opération vient de consommer une occurrence : le bandeau de facturation
            // et l'historique affichent des chiffres désormais faux.
            //
            // ⚠ ON L'ATTEND. Sans `await`, le `finally` enchaînait aussitôt son
            // `app:loading.stop` et ÉTEIGNAIT la barre que le rechargement venait de
            // rallumer : l'écran se figeait, sans barre, pendant qu'il se reconstruisait.
            await this.#reload();
        } catch (error) {
            console.error('[echange] Échec de l’export :', error);
            this.#notifier('error', error.message || "L'export n'a pas pu être généré.");
        } finally {
            this.#terminer();
            this.#armer(this.hasBoutonExportTarget ? this.boutonExportTarget : null, true);
            this.#occupe = false;
        }
    }

    /**
     * Ouvre le téléchargement du fichier préparé.
     *
     * Un lien plutôt qu'un `fetch` : le fichier est déjà sur le serveur, le navigateur
     * sait le récupérer seul, et le charger une seconde fois en mémoire pour le rendre
     * aussitôt ne servirait à rien.
     */
    #telecharger(jeton, nom) {
        const base = this.urlValue.replace(/\/workspace\/\d+$/, '');
        const url = `${base}/telecharger/${this.idEntrepriseValue}/${encodeURIComponent(jeton)}`;

        const lien = document.createElement('a');
        lien.href = url;
        if (nom) lien.download = nom;
        document.body.appendChild(lien);
        lien.click();
        lien.remove();
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Importation
    // ─────────────────────────────────────────────────────────────────────────────

    /** Un fichier vient d'être choisi : on l'annonce et on arme le contrôle. */
    fichierChoisi() {
        const fichier = this.hasFichierTarget ? this.fichierTarget.files[0] : null;

        if (this.hasNomFichierTarget) {
            this.nomFichierTarget.textContent = fichier ? fichier.name : 'Choisir un classeur .xlsx';
        }
        this.#armerControle();
    }

    /**
     * Le bouton de contrôle dépend de DEUX conditions — un fichier, et au moins une
     * donnée retenue. Il n'a pas de sens de contrôler un dépôt dont on a tout écarté.
     */
    #armerControle() {
        if (!this.hasBoutonControleTarget) return;

        const fichier = this.hasFichierTarget ? this.fichierTarget.files[0] : null;
        const quelqueChose = !this.hasDonneeTarget || this.#selection().length > 0;
        this.#armer(this.boutonControleTarget, Boolean(fichier) && quelqueChose);
    }

    /**
     * Dépose le fichier et lance le contrôle à blanc, en suivant sa progression.
     *
     * Gratuit et sans écriture : on peut le relancer autant de fois qu'il faut pour
     * corriger le fichier. Le bouton se réarme donc systématiquement.
     */
    async controler() {
        const fichier = this.hasFichierTarget ? this.fichierTarget.files[0] : null;
        if (!fichier || this.#occupe) return;

        this.#occupe = true;
        this.#armer(this.hasBoutonControleTarget ? this.boutonControleTarget : null, false);
        this.#demarrer();

        try {
            const corps = new FormData();
            corps.append('fichier', fichier);
            if (this.hasSuppressionsTarget && this.suppressionsTarget.checked) {
                corps.append('suppressions', '1');
            }

            // ⚠ IL N'Y A PLUS DE PÉRIMÈTRE À ENVOYER. Ce réglage écartait des FEUILLES,
            // notion propre au classeur normalisé qui n'est plus importé : le classeur de
            // reprise n'en a qu'une. Le serveur ne le lit plus ; l'envoyer encore
            // laisserait croire à un filtre là où il n'y a rien à filtrer.

            const final = await this.#menerAuBout(this.importUrlValue, { method: 'POST', body: corps });

            // ⚠ UN TOAST QUI CONSTATE NE SERT À RIEN. « Le fichier comporte des anomalies »
            // laissait l'utilisateur devant un refus sans marche à suivre : combien ?
            // lesquelles ? où ? On dit le nombre, et l'on renvoie au rapport qui apparaît
            // juste dessous — c'est lui qui porte le détail situé et le classeur annoté.
            const erreurs = final?.rapport?.nb_erreurs ?? 0;

            this.#notifier(
                final?.confirmable ? 'success' : 'warning',
                final?.confirmable
                    ? 'Contrôle terminé : rien n’a encore été écrit.'
                    : `${erreurs} erreur(s) à corriger, détaillées ci-dessous. Rien n’a été écrit.`,
            );

            // Le rapport est rendu par le serveur : on recharge l'onglet plutôt que de
            // le reconstruire en JavaScript, ce qui ferait un second gabarit à tenir.
            // ⚠ ATTENDU, pour que le `finally` n'éteigne pas la barre en plein rechargement.
            await this.#reload();
        } catch (error) {
            console.error('[echange] Échec du contrôle :', error);
            this.#notifier('error', error.message || "Le contrôle n'a pas pu être effectué.");
        } finally {
            this.#terminer();
            this.#armer(this.hasBoutonControleTarget ? this.boutonControleTarget : null, true);
            this.#occupe = false;
        }
    }

    /**
     * CONFIRME l'importation — le seul geste de cet écran qui écrive en base.
     *
     * Il n'existe aucun autre chemin : ni l'assistant, ni le contrôle, ni le dépôt
     * n'écrivent quoi que ce soit. Ce clic-ci, posé par l'utilisateur, est la frontière.
     */
    async confirmer(event) {
        const idRun = event.params.run;
        if (!idRun || this.#occupe) return;

        this.#occupe = true;
        this.#armer(this.hasBoutonConfirmationTarget ? this.boutonConfirmationTarget : null, false);
        this.#demarrer();

        try {
            const final = await this.#menerAuBout(this.#urlRun(idRun, 'confirmer'), { method: 'POST' });
            const abouti = final?.statut === 'TERMINE';

            this.#notifier(
                abouti ? 'success' : 'error',
                abouti
                    ? 'Importation terminée.'
                    : "L'importation n'a pas abouti : consultez le rapport pour savoir où elle s'est arrêtée.",
            );

            // Que l'import ait abouti ou échoué, l'écran affiche des chiffres périmés.
            // ⚠ ATTENDU, pour que le `finally` n'éteigne pas la barre en plein rechargement.
            await this.#reload();
        } catch (error) {
            console.error('[echange] Échec de la confirmation :', error);
            this.#notifier('error', error.message || "L'importation n'a pas pu être lancée.");
        } finally {
            this.#terminer();
            this.#armer(this.hasBoutonConfirmationTarget ? this.boutonConfirmationTarget : null, true);
            this.#occupe = false;
        }
    }

    /** Abandonne le contrôle en attente. Gratuit, sans effet sur les données. */
    async annuler(event) {
        const idRun = event.params.run;
        if (!idRun || this.#occupe) return;

        this.#occupe = true;
        this.#demarrer();

        try {
            await fetch(this.#urlRun(idRun, 'annuler'), {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            this.#reload();
        } catch (error) {
            console.error('[echange] Échec de l’annulation :', error);
        } finally {
            this.#terminer();
            this.#occupe = false;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Paliers d'importation
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * MÈNE UN TRAVAIL D'IMPORT JUSQU'À SON TERME, palier par palier.
     *
     * ⚠ POURQUOI L'IMPORT NE SE DIFFUSE PLUS, ALORS QUE L'EXPORT LE FAIT ENCORE.
     *
     * Un export est une opération unique : elle réussit ou elle échoue, et la seule chose
     * à dire pendant qu'elle travaille est où elle en est — d'où le flux NDJSON, qui reste
     * en place et sert très bien.
     *
     * Un import, lui, se compte en paliers, parce que le contrôle à blanc retient
     * plusieurs mégaoctets par ligne sans les rendre. Chaque palier doit repartir d'un
     * PROCESSUS NEUF, sans quoi la mémoire s'accumule jusqu'à ce que PHP meure au milieu.
     * Une requête qui diffuse est, par construction, un seul processus.
     *
     * Et l'état vit désormais en base : un rafraîchissement, un changement d'onglet, une
     * soirée — l'écran retrouve l'import là où il en est. Un flux, lui, meurt avec sa page.
     *
     * ── DEUX FAÇONS D'AVANCER, ET LE SERVEUR DIT LAQUELLE ───────────────────────────
     *   `async: false` — c'est NOUS qui poussons : une requête par palier.
     *   `async: true`  — un worker travaille ; on se contente de regarder.
     */
    async #menerAuBout(url, options) {
        return menerAuBout(await this.#json(url, options), {
            // ⚠ LE PALIER SE LIT EN FLUX, PAS EN UNE RÉPONSE. Le palier EST la requête :
            // en attendre l'objet JSON, c'était n'apprendre où l'on en est qu'une fois ses
            // trente lignes écrites — donc laisser l'écran immobile plusieurs secondes
            // d'affilée, ce que rien ne distingue d'une panne. Le serveur diffuse
            // désormais une pulsation par ligne, et la dernière ligne porte l'état.
            //
            // L'en-tête `Accept` est ce qui le demande : sans lui, la route répond comme
            // avant — c'est ce qui laisse l'assistant, les commandes et les tests intacts.
            avancer: (etat) => this.#lireFlux(this.#urlRun(etat.idRun, 'avancer'), {
                method: 'POST',
                headers: { Accept: 'application/x-ndjson' },
            }),
            lire: (etat) => this.#json(this.#urlRun(etat.idRun, 'etat'), { method: 'GET' }),
            publier: (etat) => this.#publierEtat(etat),
        });
    }

    /** Une requête JSON dont l'échec porte le message du serveur, pas un code nu. */
    async #json(url, options) {
        const reponse = await fetch(url, {
            ...options,
            headers: { 'X-Requested-With': 'XMLHttpRequest', ...(options.headers || {}) },
        });

        const charge = await reponse.json().catch(() => null);
        if (!reponse.ok) {
            throw new Error(charge?.message || `Le serveur a répondu ${reponse.status}.`);
        }

        return charge;
    }

    /**
     * Publie l'avancement sur la barre globale.
     *
     * ⚠ AUCUN TEMPS RESTANT N'EST ANNONCÉ. Le serveur ne mesure pas de débit d'un palier à
     * l'autre — ils vivent dans des processus différents —, et l'inventer ici reviendrait
     * à promettre une échéance qu'on ne connaît pas. Un pourcentage mesuré vaut mieux
     * qu'une estimation fausse.
     */
    #publierEtat(etat) {
        if (!etat) return;

        document.dispatchEvent(new CustomEvent('app:loading.progress', {
            detail: { pct: etat.pct ?? 0, libelle: etat.libelle || '', restant: null },
        }));

        this.#rafraichirLeBandeau(etat);
    }

    /**
     * Le bandeau du travail suit le même rythme que la barre du haut.
     *
     * ⚠ IL NE SE RECONSTRUIT PAS, IL SE MET À JOUR. Recharger la rubrique à chaque palier
     * ferait clignoter tout l'écran plusieurs fois par minute, et le rapport déjà affiché
     * disparaîtrait sous les yeux de celui qui le lit.
     */
    #rafraichirLeBandeau(etat) {
        if (this.hasTravailPhaseTarget && etat.libelle) {
            this.travailPhaseTarget.textContent = etat.libelle;
        }

        const total = Number(etat.total) || 0;
        // `curseur` vient de l'état du run, `fait` de la progression diffusée pendant un
        // palier : c'est la même grandeur, sous deux noms, et le bandeau sert les deux.
        const fait = Number(etat.curseur ?? etat.fait) || 0;

        if (this.hasTravailCompteTarget) {
            this.travailCompteTarget.textContent = total > 0 ? `${fait} / ${total} lignes` : '';
        }

        const pct = Math.max(0, Math.min(100, Number(etat.pct) || 0));
        if (this.hasTravailJaugeTarget) {
            this.travailJaugeTarget.style.width = `${pct}%`;
        }
        if (this.hasTravailBarreTarget) {
            this.travailBarreTarget.setAttribute('aria-valuenow', String(Math.round(pct)));
        }
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Flux de progression (exportation)
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * Lit un flux NDJSON, publie chaque progression et rend la DERNIÈRE ligne.
     *
     * Le serveur envoie une ligne par étape, puis une ligne de résultat. On lit au fur
     * et à mesure : un `await response.json()` attendrait la fin, et ferait exactement
     * ce qu'on cherche à éviter.
     */
    async #lireFlux(url, options) {
        const response = await fetch(url, {
            ...options,
            headers: { 'X-Requested-With': 'XMLHttpRequest', ...(options.headers || {}) },
        });

        if (!response.ok) {
            // Un refus survient AVANT le flux (droits, format) : le corps est alors du
            // JSON ordinaire, et son message vaut mieux qu'un code HTTP nu.
            const texte = (await response.text()).trim();
            let message = texte;
            try {
                message = JSON.parse(texte).message || texte;
            } catch {
                // Corps non JSON : on relaie le texte brut.
            }
            throw new Error(message || `HTTP ${response.status}`);
        }

        const lecteur = response.body.getReader();
        const decodeur = new TextDecoder();
        let tampon = '';
        let dernier = null;

        for (;;) {
            const { done, value } = await lecteur.read();
            if (done) break;

            tampon += decodeur.decode(value, { stream: true });

            // Une ligne peut arriver coupée en deux paquets : on ne traite que celles
            // qui sont complètes, et on garde le reste pour le tour suivant.
            const lignes = tampon.split('\n');
            tampon = lignes.pop() ?? '';

            for (const ligne of lignes) {
                this.#consommer(ligne, (charge) => { dernier = charge; });
            }
        }

        // Dernière ligne éventuellement restée dans le tampon.
        this.#consommer(tampon, (charge) => { dernier = charge; });

        return dernier;
    }

    /** Traite une ligne du flux : progression publiée, résultat mémorisé. */
    #consommer(ligne, garderResultat) {
        const texte = (ligne || '').trim();
        if (!texte) return;

        let charge;
        try {
            charge = JSON.parse(texte);
        } catch {
            return; // Ligne illisible : on ne casse pas le flux pour autant.
        }

        if (charge.type === 'progres') {
            this.#publierProgression(charge);
            // La progression d'un palier nomme `fait` ce que l'état du run nomme
            // `curseur` : le bandeau accepte les deux, et suit donc les deux sources.
            this.#rafraichirLeBandeau(charge);

            return;
        }

        // ⚠ UNE ERREUR DIFFUSÉE DOIT INTERROMPRE. Rendue comme un résultat ordinaire, elle
        // ferait sortir la boucle de paliers en silence : le travail s'arrêterait sans que
        // personne ne sache pourquoi.
        if (charge.type === 'erreur') {
            throw new Error(charge.message || "Le palier n'a pas abouti.");
        }

        garderResultat(charge);
    }

    /** Bascule la barre globale en mode chiffré. */
    #publierProgression(charge) {
        document.dispatchEvent(new CustomEvent('app:loading.progress', {
            detail: {
                pct: charge.pct,
                libelle: charge.libelle,
                restant: charge.restant,
            },
        }));
    }

    #demarrer() {
        document.dispatchEvent(new CustomEvent('app:loading.start'));
    }

    /** Extinction — sur TOUS les chemins de sortie, sans exception. */
    #terminer() {
        document.dispatchEvent(new CustomEvent('app:loading.stop'));
    }

    #notifier(type, text) {
        document.dispatchEvent(new CustomEvent('app:notification.show', { detail: { type, text } }));
    }

    /** URL d'une action sur un contrôle, construite depuis celle du dépôt. */
    #urlRun(idRun, action) {
        return `${this.importUrlValue}/${encodeURIComponent(idRun)}/${action}`;
    }

    #armer(bouton, actif) {
        if (bouton) bouton.disabled = !actif;
    }

    async #reload() {
        this.#demarrer();

        try {
            const url = `${this.urlValue}?onglet=${encodeURIComponent(this.ongletValue)}`;
            const response = await fetch(url, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            this.element.outerHTML = await response.text();
        } catch (error) {
            console.error('[echange] Échec du rechargement :', error);
            this.#notifier('error', "Impossible de charger cet onglet. Veuillez réessayer.");
        } finally {
            this.#terminer();
        }
    }
}
