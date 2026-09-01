import PickerBaseController from './picker-base_controller.js';

/**
 * LA GRILLE DES COMPTEURS et les trois gestes qui s'y rattachent : journal, ajustement,
 * décompte de sortie.
 *
 * ── UN SEUL PANNEAU, PAS UNE PILE DE FENÊTRES ───────────────────────────────────────
 * Chaque geste REMPLACE le contenu du panneau par un fragment rendu par le serveur, puis
 * rend la main à la grille. Ouvrir une boîte par geste aurait empilé des fenêtres au
 * moment précis où l'on compare des lignes entre elles.
 *
 * ── IL NE CALCULE RIEN ──────────────────────────────────────────────────────────────
 * Soldes, prorata de sortie, seuils : tout vient du serveur, du même calcul que la liste,
 * la fiche et les e-mails. Un compteur recalculé dans le navigateur finirait par
 * contredire la fiche du collaborateur, et personne ne saurait lequel croire.
 */
export default class extends PickerBaseController {
    static pickerName = 'CONGE-COMPTEURS';

    static targets = ['contenu'];

    static values = {
        grilleUrl: String,
        journalUrl: String,
        sortieUrl: String,
        ajustementUrl: String,
        exportUrl: String,
        exercice: Number,
    };

    _onActionClick(event) {
        const cible = event.target;

        const exercice = cible.closest('[data-cpt-exercice]');
        if (exercice) return this._chargerLaGrille(exercice.dataset.cptExercice);

        const journal = cible.closest('[data-cpt-journal]');
        if (journal) return this._chargerFragment(this._url(this.journalUrlValue, journal.dataset.cptJournal));

        const sortie = cible.closest('[data-cpt-sortie]');
        if (sortie) return this._chargerFragment(this._url(this.sortieUrlValue, sortie.dataset.cptSortie));

        const ajuster = cible.closest('[data-cpt-ajuster]');
        if (ajuster) return this._ajuster(ajuster.dataset.cptAjuster, ajuster.dataset.cptAgent);

        const executer = cible.closest('[data-cpt-sortie-executer]');
        if (executer) return this._executerLaSortie(executer.dataset.cptSortieExecuter);

        if (cible.closest('[data-cpt-retour]')) return this._chargerLaGrille(this.exerciceValue);
        if (cible.closest('[data-cpt-export]')) return this._exporter();
        if (cible.closest('[data-cpt-imprimer]')) return window.print();

        return undefined;
    }

    /**
     * La date de fin de contrat se change SANS écrire : on regarde avant de solder.
     * Chaque changement redemande l'aperçu au serveur, qui seul sait proratiser.
     */
    _onChange(event) {
        const champ = event.target.closest('[data-cpt-date-fin]');
        if (!champ) return;

        const url = this._url(this.sortieUrlValue, champ.dataset.cptDateFin);
        url.searchParams.set('dateFin', champ.value);
        this._chargerFragment(url);
    }

    connect() {
        super.connect();
        // Le socle n'écoute que les clics : la saisie de date, elle, doit recalculer.
        this.boundChange = (event) => this._onChange(event);
        this.element.addEventListener('change', this.boundChange);
    }

    disconnect() {
        if (this.boundChange) this.element.removeEventListener('change', this.boundChange);
        super.disconnect();
    }

    /** Les routes portent un identifiant factice (0) qu'on remplace : pas de concaténation à la main. */
    _url(base, idAgent) {
        const url = new URL(base.replace(/\/0(\?|$)/, `/${idAgent}$1`), window.location.origin);
        url.searchParams.set('exercice', this.exerciceValue);

        return url;
    }

    async _chargerLaGrille(exercice) {
        const url = new URL(this.grilleUrlValue, window.location.origin);
        url.searchParams.set('exercice', exercice);
        url.searchParams.set('fragment', '1');

        const data = await this._demander(url);
        if (data && data.exercice) {
            this.exerciceValue = data.exercice;
            this._majTitre(data.exercice);
        }
    }

    async _chargerFragment(url) {
        await this._demander(url);
    }

    /**
     * Le fragment vient du serveur et remplace le contenu. On ne rend jamais la main sur
     * une erreur : le contenu précédent reste, et le message s'affiche à côté.
     */
    async _demander(url) {
        if (this.enCours) return null;

        this.enCours = true;
        this._progress(true);
        this._showError(null);
        try {
            const response = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            const data = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(data.message || `Erreur serveur ${response.status}`);

            if (this.hasContenuTarget) this.contenuTarget.innerHTML = data.html || '';

            return data;
        } catch (error) {
            console.error('[CONGE-COMPTEURS] chargement :', error);
            this._showError(error.message || "Le contenu n'a pas pu être chargé.");

            return null;
        } finally {
            this.enCours = false;
            this._progress(false);
        }
    }

    /**
     * L'AJUSTEMENT EXIGE UN MOTIF, et le demande ici plutôt que de laisser le serveur
     * refuser : un aller-retour pour apprendre qu'il fallait écrire quelque chose est un
     * aller-retour de trop.
     */
    async _ajuster(idAgent, nomAgent) {
        const brut = window.prompt(
            `Ajustement du compteur de ${nomAgent}\n\nNombre de jours (négatif pour retirer) :`,
            '',
        );
        if (brut === null) return;

        const quantite = parseFloat(String(brut).replace(',', '.'));
        if (!Number.isFinite(quantite) || quantite === 0) {
            this._showError('Indiquez un nombre de jours différent de zéro.');

            return;
        }

        const motif = window.prompt(
            'Motif de l\'ajustement (obligatoire)\n\nIl restera au journal : un chiffre sans explication\nrend tout le reste douteux.',
            '',
        );
        if (motif === null || motif.trim() === '') {
            this._showError("Le motif est obligatoire : l'ajustement n'a pas été enregistré.");

            return;
        }

        await this._poster(
            this._url(this.ajustementUrlValue, idAgent),
            { quantite, motif, exercice: this.exerciceValue },
        );
    }

    async _executerLaSortie(idAgent) {
        const champ = this.element.querySelector('[data-cpt-date-fin]');
        await this._poster(
            this._url(this.sortieUrlValue, idAgent),
            { dateFin: champ ? champ.value : null },
            /* remplacerLeContenu */ true,
        );
    }

    async _poster(url, corps, remplacerLeContenu = false) {
        if (this.enCours) return;

        this.enCours = true;
        this._progress(true);
        this._showError(null);
        let aRafraichir = false;
        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify(corps),
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok || data.success === false) {
                throw new Error(data.message || `Erreur serveur ${response.status}`);
            }

            this._notifyCerveau('conge:compteur.modifie', { message: data.message || 'Compteur mis à jour.' });

            if (remplacerLeContenu && data.html && this.hasContenuTarget) {
                this.contenuTarget.innerHTML = data.html;
            } else {
                aRafraichir = true;
            }
        } catch (error) {
            console.error('[CONGE-COMPTEURS] écriture :', error);
            this._showError(error.message || "L'écriture n'a pas pu être enregistrée.");
        } finally {
            this.enCours = false;
            this._progress(false);
        }

        // LE RAFRAÎCHISSEMENT PART APRÈS LA LIBÉRATION DU VERROU, pas depuis le `try` :
        // `_demander()` refuse tant qu'un appel est en cours, et la grille serait restée
        // muette sur un ajustement pourtant enregistré.
        if (aRafraichir) await this._chargerLaGrille(this.exerciceValue);
    }

    /**
     * L'export part par une NAVIGATION, pas par fetch : c'est un fichier, et le navigateur
     * sait le recevoir. Le passer par fetch obligerait à reconstruire un téléchargement à
     * la main pour rien.
     */
    _exporter() {
        const url = new URL(this.exportUrlValue, window.location.origin);
        url.searchParams.set('exercice', this.exerciceValue);
        window.location.assign(url.toString());
    }

    _majTitre(exercice) {
        const titre = this.element.querySelector('#jsb-compteurs-title');
        if (titre) titre.textContent = `Compteurs de congés — exercice ${exercice}`;
    }
}
