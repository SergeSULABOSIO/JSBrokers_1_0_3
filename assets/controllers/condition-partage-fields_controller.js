import { Controller } from '@hotwired/stimulus';
import {
    SELECTEUR_CRITERE_COCHE,
    estChampCritereRisque,
    risquesCiblesVisibles,
} from './condition-partage-champs.js';

/**
 * @class ConditionPartageFieldsController
 * @description Montre ou cache les « Risques ciblés » selon le critère choisi.
 *
 * Le critère sur le risque a trois valeurs : n'en cibler aucun, ne partager QUE sur
 * certains, ou ne PAS partager sur certains. Dans le premier cas, la liste des risques
 * n'a aucun objet — la laisser à l'écran demande à l'utilisateur de comprendre seul
 * qu'elle ne sert à rien ici (Bastien & Scapin > Charge de travail), et l'expose à
 * cocher des risques qui ne seront jamais lus.
 *
 * ── CE QU'IL NE FAIT PAS ────────────────────────────────────────────────────────────
 * Il ne VIDE jamais la collection. Un utilisateur qui bascule sur « aucun risque ciblé »
 * puis revient sur son choix retrouve sa sélection intacte : masquer n'est pas effacer.
 * C'est aussi ce qui rend l'action réversible sans confirmation (Nielsen 3).
 *
 * Aucun sondage périodique ici : le critère est un groupe de boutons radio natifs, dont
 * l'événement `change` est fiable — contrairement aux champs pilotés par TomSelect.
 */
export default class extends Controller {
    connect() {
        this.nomControleur = 'CONDITION-PARTAGE-FIELDS';

        // Délégation sur le formulaire : les radios sont rendues par Symfony et peuvent
        // être remplacées à chaud (rechargement du formulaire dans le dialogue).
        this.boundChange = (event) => {
            if (estChampCritereRisque(event.target?.name)) this.appliquer();
        };
        this.element.addEventListener('change', this.boundChange);

        // Le formulaire est inséré dans le dialogue puis peuplé : on laisse le DOM se
        // poser avant la première évaluation, sinon la collection n'existe pas encore.
        this.timer = setTimeout(() => this.appliquer(), 50);
    }

    disconnect() {
        this.element.removeEventListener('change', this.boundChange);
        if (this.timer) clearTimeout(this.timer);
    }

    /** Aligne la visibilité des risques ciblés sur le critère coché. */
    appliquer() {
        const bloc = this._blocRisques();
        if (!bloc) return;

        const coche = this.element.querySelector(SELECTEUR_CRITERE_COCHE);
        const cible = risquesCiblesVisibles(coche ? coche.value : null);

        bloc.classList.toggle('d-none', !cible);
        // `aria-hidden` suit l'état visuel : un champ masqué ne doit pas rester dans le
        // flux d'un lecteur d'écran ni dans l'ordre de tabulation.
        bloc.setAttribute('aria-hidden', cible ? 'false' : 'true');
        bloc.querySelectorAll('button, input, select, textarea, a').forEach((el) => {
            el.tabIndex = cible ? 0 : -1;
        });
    }

    /**
     * La carte qui porte la collection « produits » (les risques ciblés).
     *
     * On remonte depuis le champ jusqu'à sa carte pour masquer le bloc ENTIER — titre,
     * pastille et accordéon —, et non le seul widget, qui laisserait un intitulé orphelin.
     * @private
     */
    _blocRisques() {
        if (this.blocRisques && this.element.contains(this.blocRisques)) return this.blocRisques;

        // `data-field-code` est posé par le gabarit sur CHAQUE carte de champ : une
        // accroche déterministe, là où deviner l'identifiant généré par Symfony aurait
        // échoué en silence — et un champ qu'on ne trouve pas reste affiché sans que rien
        // ne le signale.
        this.blocRisques = this.element.querySelector('.dlg-field-card[data-field-code="produits"]');

        return this.blocRisques;
    }
}
