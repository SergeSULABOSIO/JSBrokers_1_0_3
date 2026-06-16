import { Controller } from '@hotwired/stimulus';

/*
 * Contrôleur JS Brokers (Symfony 7.1) — formulaire Entreprise.
 *
 * Le champ « Pays » pilote dynamiquement :
 *   - le champ « Ville » : ses options sont rechargées via l'API
 *     /admin/entreprise/api/villes/{codePays} ;
 *   - la monnaie affichée du « Capital social » : le symbole rendu par MoneyType
 *     (thème Bootstrap 5 → <span class="input-group-text">) est remplacé par le
 *     code monnaie du pays.
 *
 * Cibles :
 *   - pays    : <select> du pays (déclenche `paysChange`) ;
 *   - ville   : <select> des villes (rempli dynamiquement) ;
 *   - capital : <input> du capital social (sert à localiser le badge monnaie).
 *
 * Valeur :
 *   - urlTemplate : URL de l'API avec le sentinel « 999999999 » à la place du code.
 */
export default class extends Controller {
    static targets = ['pays', 'ville', 'capital'];
    static values = { urlTemplate: String };

    connect() {
        // À l'ouverture (édition), si un pays est déjà sélectionné, on s'assure
        // que la monnaie affichée est cohérente. Les villes sont déjà rendues
        // côté serveur : inutile de les recharger.
        if (this.hasPaysTarget && this.paysTarget.value) {
            this.refresh(this.paysTarget.value, { keepVille: true });
        }
    }

    paysChange() {
        const code = this.hasPaysTarget ? this.paysTarget.value : '';
        this.refresh(code, { keepVille: false });
    }

    async refresh(code, { keepVille }) {
        // Pas de pays : on vide la ville et on ne touche pas au symbole monnaie.
        if (!code) {
            if (!keepVille) this.resetVille();
            return;
        }

        let data;
        try {
            const response = await fetch(this.urlTemplateValue.replace('999999999', encodeURIComponent(code)));
            if (!response.ok) return;
            data = await response.json();
        } catch (e) {
            console.error('[pays-dependances] Échec du chargement des villes :', e);
            return;
        }

        if (!keepVille) {
            this.fillVille(data.villes || []);
        }
        if (data.monnaie) {
            this.updateMonnaie(data.monnaie);
        }
    }

    fillVille(villes) {
        if (!this.hasVilleTarget) return;
        const select = this.villeTarget;
        const placeholder = select.querySelector('option[value=""]');

        select.innerHTML = '';
        if (placeholder) {
            select.appendChild(placeholder);
        } else {
            const opt = document.createElement('option');
            opt.value = '';
            opt.textContent = 'Sélectionnez une ville';
            select.appendChild(opt);
        }

        villes.forEach((ville) => {
            const opt = document.createElement('option');
            opt.value = ville.id;
            opt.textContent = ville.nom;
            select.appendChild(opt);
        });
        select.value = '';
    }

    resetVille() {
        this.fillVille([]);
    }

    updateMonnaie(code) {
        if (!this.hasCapitalTarget) return;
        const group = this.capitalTarget.closest('.input-group');
        if (!group) return;
        // Le thème Bootstrap 5 place le symbole monnaie dans .input-group-text.
        const badge = group.querySelector('.input-group-text');
        if (badge) {
            badge.textContent = code;
        }
    }
}
