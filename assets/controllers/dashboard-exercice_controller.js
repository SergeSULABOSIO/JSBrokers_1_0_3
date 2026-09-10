import { Controller } from '@hotwired/stimulus';
import { DefilementChips } from './chips-defilement.js';
import { choixARestaurer, cleDuTableauDeBord } from './choix-persiste.js';
import { LOADED_EVENT, RELOAD_EVENT } from './lazy_block_controller.js';

/**
 * LES PASTILLES D'EXERCICE DU TABLEAU DE BORD.
 *
 * Un cabinet qui reprend trois ans d'historique ouvrait son tableau de bord sur l'exercice
 * en cours — vide — et lisait 0,00 partout. Ses données existaient, elles étaient justes,
 * et rien ne le lui disait : il concluait à une panne. Ces pastilles lui rendent ses
 * années, et le serveur ouvre désormais sur la dernière qui porte quelque chose.
 *
 * ⚠ LA BASCULE EST UNE OPÉRATION LOURDE — une quinzaine de blocs à recharger, des
 * agrégats à recalculer, trois graphiques à redessiner. Elle DOIT donc rendre compte
 * d'elle-même : sans retour visuel, on reclique, et on double le travail du serveur.
 */
export default class extends Controller {
    static targets = ['chip'];
    static values = { idEntreprise: Number, exercice: String };

    connect() {
        this.#brancherLeDefilement();

        this._finHandler = () => this.#unBlocDePlus();
        document.addEventListener(LOADED_EVENT, this._finHandler);

        this.#reprendreLExercice();
    }

    disconnect() {
        if (this._finHandler) {
            document.removeEventListener(LOADED_EVENT, this._finHandler);
            this._finHandler = null;
        }
        this._defilement?.detruire();
        this.#eteindre();
    }

    /**
     * L'utilisateur choisit un exercice.
     *
     * ⚠ STIMULUS ANALYSE SES PARAMÈTRES EN JSON. « 2026 » arrive donc en NOMBRE, quand
     * `dataset` rend toujours une chaîne : la comparaison stricte échouerait pour toutes les
     * années, et aucune pastille ne s'allumerait jamais. Le piège a déjà coûté un correctif
     * dans la rubrique Importation — on le désamorce du même geste.
     */
    choisir(event) {
        const choisi = String(event.params.exercice ?? '');
        if (choisi === '' || choisi === this.exerciceValue) {
            return;
        }

        this.exerciceValue = choisi;
        this.#allumer(choisi);
        this.#memoriser(choisi);
        this.#basculer(choisi);
    }

    /**
     * Au chargement : reposer l'exercice mémorisé s'il diffère de celui que le serveur a
     * rendu.
     *
     * ⚠ LA GARDE D'ÉGALITÉ EST CE QUI EMPÊCHE LA BOUCLE. Sans elle, chaque rechargement
     * redemanderait le même exercice, indéfiniment. C'est la leçon de `#reprendreLOnglet()`
     * dans la rubrique Importation, et elle vaut mot pour mot ici.
     */
    #reprendreLExercice() {
        const offerts = this.chipTargets.map((chip) => chip.dataset.dashboardExerciceExerciceParam);
        const retenu = choixARestaurer(this.#memorise(), offerts);
        if (retenu === null || retenu === this.exerciceValue) {
            return;
        }

        this.exerciceValue = retenu;
        this.#allumer(retenu);
        this.#basculer(retenu);
    }

    /** Recharge tous les blocs, en tenant la barre de progression du haut. */
    #basculer(exercice) {
        this._attendus = document.querySelectorAll('[data-controller~="lazy-block"]').length;
        this._rendus = 0;

        if (this._attendus === 0) {
            this.#redessinerLaProduction(exercice);
            return;
        }

        this.#publier(0, `Exercice ${exercice}`);
        document.dispatchEvent(new CustomEvent(RELOAD_EVENT, {
            detail: { params: { exercice } },
        }));
    }

    #unBlocDePlus() {
        if (!this._attendus) {
            return;
        }

        this._rendus += 1;
        const pct = Math.min(100, (this._rendus / this._attendus) * 100);
        this.#publier(pct, `Exercice ${this.exerciceValue}`);

        if (this._rendus < this._attendus) {
            return;
        }

        this._attendus = 0;
        this.#redessinerLaProduction(this.exerciceValue);
        this.#eteindre();
    }

    /**
     * ⚠ LES GRAPHIQUES NE SE REDESSINENT PAS TOUT SEULS. Le bloc production s'initialise sur
     * un observateur du DOM qui **se désarme définitivement** dès qu'il a vu le premier
     * rendu. Après un rechargement, les canevas resteraient vides — et l'ancien graphique,
     * accroché à un canevas détaché, ne serait jamais détruit.
     */
    #redessinerLaProduction(exercice) {
        if (typeof window.dbProdReinit === 'function') {
            window.dbProdReinit(exercice);
        }
    }

    /**
     * ⚠ PAS DE `app:loading.start` ICI, ET C'EST VOULU. Il REMET la barre en mode
     * indéterminé : émis avant chaque pas, il effacerait le pourcentage qu'on vient
     * d'afficher. `app:loading.progress` allume la barre à lui seul, directement en mode
     * chiffré.
     */
    #publier(pct, libelle) {
        document.dispatchEvent(new CustomEvent('app:loading.progress', {
            detail: { pct, libelle, restant: null },
        }));
    }

    /** L'extinction passe par le chemin normal, sur TOUS les cas de sortie. */
    #eteindre() {
        document.dispatchEvent(new CustomEvent('app:loading.stop'));
    }

    #allumer(choisi) {
        for (const chip of this.chipTargets) {
            const actif = chip.dataset.dashboardExerciceExerciceParam === choisi;
            chip.classList.toggle('is-active', actif);
            chip.setAttribute('aria-pressed', actif ? 'true' : 'false');
            if (actif) {
                this._defilement?.montrer(chip);
            }
        }
    }

    #memorise() {
        try {
            return window.localStorage.getItem(cleDuTableauDeBord(this.idEntrepriseValue, 'exercice'));
        } catch (error) {
            return null;
        }
    }

    #memoriser(valeur) {
        try {
            window.localStorage.setItem(cleDuTableauDeBord(this.idEntrepriseValue, 'exercice'), valeur);
        } catch (error) {
            // Navigation privée, quota atteint : le choix vaut pour cette session, et c'est
            // tout ce qu'on peut promettre. Rien à signaler à l'utilisateur.
        }
    }

    #brancherLeDefilement() {
        this._defilement = new DefilementChips(
            this.element,
            '.jsb-preset-filters-bar .jsb-preset-filters',
        );
        this._defilement.brancher();
    }
}
