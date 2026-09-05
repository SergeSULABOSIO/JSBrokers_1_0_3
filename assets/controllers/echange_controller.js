import { Controller } from '@hotwired/stimulus';

/**
 * Contrôleur du composant « Importation / Exportation » (espace de travail).
 *
 * LA PROGRESSION EST RÉELLE, ET C'EST TOUT L'OBJET DE CE FICHIER.
 *
 * Une barre indéterminée dit « quelque chose se passe » et rien de plus : l'utilisateur
 * ne sait ni ce qui avance, ni combien il en reste, ni s'il a le temps d'aller chercher
 * un café. Le serveur, lui, SAIT : il a compté ses lignes avant de commencer. Il envoie
 * donc son avancement au fil de l'eau, une ligne JSON à la fois, DANS la requête qui
 * travaille — et non dans une seconde requête qui l'interrogerait, car le serveur de
 * développement n'a qu'un processus PHP et se bloquerait lui-même.
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
        'fichier',
        'nomFichier',
        'suppressions',
        'boutonControle',
        'boutonConfirmation',
        'rapport',
    ];

    static values = {
        url: String,
        onglet: String,
        exportUrl: String,
        importUrl: String,
        idEntreprise: Number,
    };

    /**
     * Verrou de réentrance, commun à tous les gestes longs. Le bouton désarmé suffit à
     * l'utilisateur ; ce drapeau couvre le reste — une touche Entrée maintenue, un
     * appel programmatique. Deux exports simultanés, ce sont deux occurrences.
     */
    #occupe = false;

    connect() {
        this.#rafraichirSelection();
    }

    /** Changement d'onglet (chip) : `data-echange-onglet-param`. */
    changeOnglet(event) {
        const onglet = event.params.onglet;
        if (!onglet || onglet === this.ongletValue) return;
        this.ongletValue = onglet;
        this.#reload();
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
        const coche = event.target.checked;
        if (!module) return;

        for (const case_ of this.#casesDuModule(module)) {
            const code = case_.dataset.echangeCodeParam;
            if (coche) {
                this.#cocherAvecDependances(code, new Set());
            } else {
                this.#decocherLesDependants(code, new Set());
            }
        }

        this.#rafraichirSelection();
    }

    /**
     * Empêche un clic sur la case d'un module de replier ce module.
     *
     * Le <summary> bascule le repli pour TOUT clic qu'il reçoit, y compris celui d'une
     * case posée à l'intérieur. Cocher « Finances » refermait donc le groupe qu'on
     * venait d'ouvrir pour le vérifier.
     */
    arreter(event) {
        event.stopPropagation();
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
        for (const enTete of this.moduleTargets) {
            const module = enTete.dataset.echangeModuleParam;
            const lignes = this.#casesDuModule(module);
            const cochees = lignes.filter((c) => c.checked).length;

            enTete.checked = cochees > 0;
            enTete.indeterminate = cochees > 0 && cochees < lignes.length;
            enTete.setAttribute(
                'aria-label',
                `${module} — ${cochees} donnée${cochees > 1 ? 's' : ''} sur ${lignes.length}`,
            );

            // Le compte est écrit EN TOUTES LETTRES à côté du titre : la couleur du
            // badge ne fait que doubler ce que le texte dit déjà (WCAG 1.4.1).
            const badge = this.element.querySelector(`[data-echange-compte-module="${CSS.escape(module)}"]`);
            if (badge) {
                badge.textContent = cochees === lignes.length ? `${lignes.length}` : `${cochees} sur ${lignes.length}`;
                badge.classList.toggle('is-partiel', cochees > 0 && cochees < lignes.length);
                badge.classList.toggle('is-vide', cochees === 0);
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

            // Périmètre choisi. On n'envoie rien quand TOUT est coché : le serveur lit
            // alors « tout ce que cet utilisateur peut lire », ce qui reste juste même
            // si ses droits changent entre l'affichage de l'écran et le clic.
            const choisies = this.#selection();
            if (this.hasDonneeTarget && choisies.length < this.donneeTargets.length) {
                corps.append('donnees', choisies.join(','));
            }

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
            this.#reload();
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

            // Périmètre retenu. Rien n'est envoyé quand TOUT est coché : le serveur lit
            // alors « toutes les feuilles du fichier », ce qui reste juste même si le
            // classeur en contient une que cet écran ne connaît pas.
            //
            // ⚠ Ce choix est ENREGISTRÉ SUR LE CONTRÔLE, pas seulement appliqué ici : la
            // confirmation relit le fichier entier, et sans cette mémoire elle
            // réécrirait les feuilles qu'on vient d'écarter.
            const retenues = this.#selection();
            if (this.hasDonneeTarget && retenues.length < this.donneeTargets.length) {
                corps.append('donnees', retenues.join(','));
            }

            const final = await this.#lireFlux(this.importUrlValue, { method: 'POST', body: corps });

            if (final?.type === 'erreur') {
                throw new Error(final.message);
            }

            this.#notifier(
                final?.confirmable ? 'success' : 'warning',
                final?.confirmable
                    ? 'Contrôle terminé : rien n’a encore été écrit.'
                    : 'Le fichier comporte des anomalies à corriger. Rien n’a été écrit.',
            );

            // Le rapport est rendu par le serveur : on recharge l'onglet plutôt que de
            // le reconstruire en JavaScript, ce qui ferait un second gabarit à tenir.
            this.#reload();
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
            const final = await this.#lireFlux(this.#urlRun(idRun, 'confirmer'), { method: 'POST' });

            if (final?.type === 'erreur') {
                throw new Error(final.message);
            }

            this.#notifier(
                final?.success ? 'success' : 'error',
                final?.message || (final?.success ? 'Importation terminée.' : "L'importation n'a pas abouti."),
            );

            // Que l'import ait abouti ou échoué, l'écran affiche des chiffres périmés.
            this.#reload();
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
    // Flux de progression
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

            return;
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
