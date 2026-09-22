import { Controller } from '@hotwired/stimulus';

/**
 * @class FournisseursEditorController
 * @extends Controller
 * @description Édite la politique d'UNE famille de fournisseurs de Ket (moteur,
 * compréhension, dictée, voix, oreilles) dans la console.
 *
 * MÊME PATTERN QUE `weights-editor` ET `packs-editor` : le champ caché reste la
 * SOURCE DE VÉRITÉ soumise au serveur. À chaque geste — réordonner, activer,
 * épingler, changer un modèle — le contrôleur le re-sérialise, et le contrôleur
 * PHP le décode tel quel. Rien d'autre ne voyage.
 *
 * CE QUI EST ÉDITABLE, ET CE QUI NE L'EST PAS. L'ordre, l'activation, le mode et
 * les réglages par fournisseur, oui. Les clés d'API, non : elles ne sont pas dans
 * la politique, et les voyants ne font que refléter ce que le serveur a constaté
 * — clé présente, fournisseur à sec, et jusqu'à quand.
 *
 * DES FLÈCHES, PAS DU GLISSER-DÉPOSER. Une liste de deux à trois éléments se
 * réordonne plus vite avec deux boutons qu'avec un glisser-déposer, et les
 * flèches restent utilisables au clavier et sur un écran tactile — la console
 * s'ouvre aussi sur une tablette.
 */
export default class extends Controller {
    static targets = ['champ', 'liste', 'mode'];

    static values = { famille: String, etat: Array };

    connect() {
        this.politique = this.lire();
        this.render();
    }

    /** Le JSON du champ caché, avec des valeurs sûres si le serveur n'a rien mis. */
    lire() {
        let brut = {};
        try {
            brut = JSON.parse(this.champTarget.value || '{}');
        } catch (e) {
            brut = {};
        }

        return {
            mode: brut.mode === 'epingle' ? 'epingle' : 'chaine',
            ordre: Array.isArray(brut.ordre) ? brut.ordre.slice() : [],
            reglages: (brut.reglages && typeof brut.reglages === 'object') ? brut.reglages : {},
        };
    }

    /** Re-sérialise : c'est le seul moment où quelque chose part vers le serveur. */
    ecrire() {
        this.champTarget.value = JSON.stringify(this.politique, null, 2);
    }

    /**
     * Les fournisseurs à afficher : ceux de l'ordre d'abord, puis ceux que le
     * serveur connaît mais que la liste ne nomme pas — décochés. Sans cette
     * seconde partie, un fournisseur écarté disparaîtrait de l'écran et on ne
     * pourrait plus le remettre.
     */
    rangs() {
        const connus = this.etatValue.map((f) => f.nom);
        const dansLOrdre = this.politique.ordre.filter((n) => connus.includes(n));
        const absents = connus.filter((n) => !dansLOrdre.includes(n));

        return [
            ...dansLOrdre.map((nom) => ({ nom, actif: true })),
            ...absents.map((nom) => ({ nom, actif: false })),
        ];
    }

    etatDe(nom) {
        return this.etatValue.find((f) => f.nom === nom) || {};
    }

    render() {
        this.renderMode();
        this.renderListe();
        this.ecrire();
    }

    renderMode() {
        this.modeTarget.innerHTML = '';
        [['chaine', 'Chaîné'], ['epingle', 'Épinglé']].forEach(([valeur, libelle]) => {
            const bouton = document.createElement('button');
            bouton.type = 'button';
            bouton.textContent = libelle;
            bouton.setAttribute('aria-pressed', String(this.politique.mode === valeur));
            bouton.addEventListener('click', () => {
                this.politique.mode = valeur;
                this.render();
            });
            this.modeTarget.appendChild(bouton);
        });
    }

    renderListe() {
        this.listeTarget.innerHTML = '';
        const rangs = this.rangs();

        rangs.forEach((rang, index) => {
            const etat = this.etatDe(rang.nom);
            const li = document.createElement('li');
            li.className = 'kf-item';
            li.dataset.actif = rang.actif ? '1' : '0';

            li.appendChild(this.boutonActif(rang));
            li.appendChild(this.nom(rang, index));
            li.appendChild(this.reglage(rang.nom));
            li.appendChild(this.voyant(etat));
            li.appendChild(this.fleches(rang, index, rangs.length));

            this.listeTarget.appendChild(li);
        });
    }

    boutonActif(rang) {
        const bouton = document.createElement('button');
        bouton.type = 'button';
        bouton.className = 'kf-actif';
        bouton.textContent = rang.actif ? '☑' : '☐';
        bouton.title = rang.actif ? 'Retirer de la liste : il ne sera plus appelé' : 'Remettre dans la liste';
        bouton.addEventListener('click', () => {
            if (rang.actif) {
                this.politique.ordre = this.politique.ordre.filter((n) => n !== rang.nom);
            } else {
                this.politique.ordre.push(rang.nom);
            }
            this.render();
        });

        return bouton;
    }

    nom(rang, index) {
        const span = document.createElement('span');
        span.className = 'kf-item__nom';
        // En mode épinglé, seul le premier ACTIF répond : le dire ici évite de
        // laisser croire que les suivants servent de repli.
        const epingle = this.politique.mode === 'epingle' && rang.actif && index === 0;
        span.textContent = rang.nom + (epingle ? ' — seul appelé' : '');

        return span;
    }

    reglage(nom) {
        const conteneur = document.createElement('span');
        conteneur.className = 'kf-item__reglage';
        const input = document.createElement('input');
        input.type = 'text';
        input.placeholder = 'modèle par défaut';
        input.value = (this.politique.reglages[nom] && this.politique.reglages[nom].modele) || '';
        input.setAttribute('aria-label', `Modèle de ${nom}`);
        input.addEventListener('input', () => {
            const valeur = input.value.trim();
            if (valeur === '') {
                // Vider le champ REND le défaut du serveur : on retire la clé plutôt
                // que d'enregistrer une chaîne vide, qui serait un modèle inexistant.
                //
                // ET ON RETIRE LE FOURNISSEUR S'IL NE RESTE RIEN. Sans cela, chaque
                // champ ouvert puis vidé laissait un `{"anthropic":{}}` derrière lui :
                // sans effet, mais enregistré en base et relu par le prochain agent,
                // qui y chercherait un sens. Constaté au harnais du 2026-09-22.
                if (this.politique.reglages[nom]) {
                    delete this.politique.reglages[nom].modele;
                    if (Object.keys(this.politique.reglages[nom]).length === 0) {
                        delete this.politique.reglages[nom];
                    }
                }
            } else {
                this.politique.reglages[nom] = this.politique.reglages[nom] || {};
                this.politique.reglages[nom].modele = valeur;
            }
            this.ecrire();
        });
        conteneur.appendChild(input);

        return conteneur;
    }

    voyant(etat) {
        const span = document.createElement('span');
        if (!etat.disponible) {
            span.className = 'kf-voyant kf-voyant--absent';
            span.textContent = 'clé absente';

            return span;
        }
        if (etat.epuise) {
            span.className = 'kf-voyant kf-voyant--sec';
            span.textContent = etat.echeance ? `à sec jusqu’à ${this.heure(etat.echeance)}` : 'à sec';
            span.appendChild(document.createTextNode(' '));
            span.appendChild(this.rearmer(etat));

            return span;
        }
        span.className = 'kf-voyant kf-voyant--ok';
        span.textContent = 'prêt';

        return span;
    }

    rearmer(etat) {
        const bouton = document.createElement('button');
        bouton.type = 'button';
        bouton.className = 'kf-rearmer';
        bouton.textContent = 'Réarmer';
        bouton.title = 'Rendre ce fournisseur interrogeable tout de suite';
        bouton.addEventListener('click', () => {
            const formulaire = document.getElementById('kf-rearmer-form');
            const cle = document.getElementById('kf-rearmer-cle');
            if (formulaire && cle && etat.cle) {
                cle.value = etat.cle;
                formulaire.submit();
            }
        });

        return bouton;
    }

    /** L'heure locale du navigateur : l'agent lit la sienne, pas celle du serveur. */
    heure(iso) {
        const date = new Date(iso);

        return Number.isNaN(date.getTime())
            ? '—'
            : date.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
    }

    fleches(rang, index, total) {
        const conteneur = document.createElement('span');
        conteneur.className = 'kf-fleches';
        if (!rang.actif) { return conteneur; }

        [['▲', -1, 'Monter'], ['▼', 1, 'Descendre']].forEach(([glyphe, pas, titre]) => {
            const bouton = document.createElement('button');
            bouton.type = 'button';
            bouton.textContent = glyphe;
            bouton.title = titre;
            bouton.disabled = (pas < 0 && index === 0) || (pas > 0 && index >= total - 1);
            bouton.addEventListener('click', () => {
                const position = this.politique.ordre.indexOf(rang.nom);
                const cible = position + pas;
                if (position < 0 || cible < 0 || cible >= this.politique.ordre.length) { return; }
                const ordre = this.politique.ordre;
                [ordre[position], ordre[cible]] = [ordre[cible], ordre[position]];
                this.render();
            });
            conteneur.appendChild(bouton);
        });

        return conteneur;
    }
}
