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
 * ── CE QUE L'AUDIT D'ERGONOMIE DU 2026-09-22 A CHANGÉ ────────────────────────
 *
 * DES COMMANDES NATIVES, PAS DES GLYPHES. La première version dessinait ☑ / ☐
 * dans un `<button>` : personne n'y reconnaissait une case à cocher, et un lecteur
 * d'écran annonçait « bouton ☑ » (WCAG 4.1.2, Bastien & Scapin > Signifiance).
 * On utilise donc les commandes du navigateur — `<input type="checkbox">` pour
 * l'activation, `<input type="radio">` pour le mode — avec le nom du fournisseur
 * en étiquette CLIQUABLE. Gratuit et non négociable : rôle correct, navigation
 * clavier, groupe de radios parcourable aux flèches, anneau de focus.
 *
 * LE MODE EST UN CHOIX EXCLUSIF, donc deux radios et non deux bascules.
 * `aria-pressed` sur une paire dont exactement une est active est un contresens :
 * le mapping naturel d'un choix exclusif, c'est le groupe de radios
 * (Bastien & Scapin > Compatibilité).
 *
 * L'ÉTIQUETTE N'EST PAS UN PLACEHOLDER. « Modèle » est écrit à côté du champ ;
 * le filigrane, lui, sert à autre chose : montrer le modèle RÉELLEMENT en vigueur
 * (WCAG 3.3.2 — un placeholder ne remplace jamais un label, et « modèle par
 * défaut » ne disait même pas LEQUEL).
 *
 * CHAQUE GESTE S'ANNONCE. Réordonner ou décocher ne changeait rien de perceptible
 * pour qui n'a pas la liste sous les yeux : une région `aria-live` dit désormais
 * ce qui vient de se passer (WCAG 4.1.3).
 *
 * DES FLÈCHES, PAS DU GLISSER-DÉPOSER. Une liste de deux à trois éléments se
 * réordonne plus vite avec deux boutons qu'avec un glisser-déposer, et les
 * flèches restent utilisables au clavier et sur un écran tactile — la console
 * s'ouvre aussi sur une tablette.
 */
export default class extends Controller {
    static targets = ['champ', 'liste', 'mode', 'aide', 'annonce', 'icone'];

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
            // UN TABLEAU N'EST PAS UN DICTIONNAIRE. `json_encode([])` rend `[]`, et
            // poser une propriété nommée sur un tableau JavaScript marche… jusqu'à
            // `JSON.stringify`, qui la jette EN SILENCE. Un modèle saisi repartait
            // donc vide. Le serveur force désormais un objet ; on s'en assure quand
            // même ici, parce qu'un défaut muet mérite deux gardes.
            reglages: (brut.reglages && typeof brut.reglages === 'object' && !Array.isArray(brut.reglages))
                ? brut.reglages
                : {},
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

    /**
     * Ce qui vient de se passer, dit à voix haute pour les lecteurs d'écran.
     * Rendre la liste ne suffit pas : un changement silencieux du DOM ne s'annonce
     * pas tout seul (WCAG 4.1.3 Messages d'état).
     */
    annoncer(phrase) {
        if (this.hasAnnonceTarget) {
            this.annonceTarget.textContent = phrase;
        }
    }

    render(annonce = '') {
        this.renderMode();
        this.renderListe();
        this.ecrire();
        if (annonce !== '') { this.annoncer(annonce); }
    }

    renderMode() {
        this.modeTarget.innerHTML = '';
        // Un vrai groupe de radios : le navigateur fournit le rôle, le parcours aux
        // flèches et l'exclusivité. Le `name` porte le préfixe `kf-` et reste donc
        // HORS de l'espace de noms `ket_fournisseurs[…]` du formulaire : Symfony
        // l'ignore, seul le champ caché compte.
        const groupe = `kf-mode-${this.familleValue}`;

        [['chaine', 'Chaîné'], ['epingle', 'Épinglé']].forEach(([valeur, libelle]) => {
            const label = document.createElement('label');
            label.className = 'kf-mode__choix';

            const radio = document.createElement('input');
            radio.type = 'radio';
            radio.name = groupe;
            radio.value = valeur;
            radio.checked = this.politique.mode === valeur;
            radio.addEventListener('change', () => {
                this.politique.mode = valeur;
                this.render(`Mode ${libelle} : ${this.phraseDuMode(valeur)}`);
            });

            const texte = document.createElement('span');
            texte.textContent = libelle;

            label.appendChild(radio);
            label.appendChild(texte);
            this.modeTarget.appendChild(label);
        });

        // Ce que le mode choisi VEUT DIRE, sous les deux pastilles : deux mots ne
        // suffisent pas à s'en souvenir d'un écran à l'autre (Nielsen 6,
        // reconnaissance plutôt que rappel).
        if (this.hasAideTarget) {
            this.aideTarget.textContent = this.phraseDuMode(this.politique.mode);
        }
    }

    phraseDuMode(mode) {
        return mode === 'epingle'
            ? 'seul le premier fournisseur coché est appelé — aucun repli s’il ne peut pas répondre.'
            : 'les fournisseurs cochés sont essayés de haut en bas ; le premier qui peut répondre répond.';
    }

    renderListe() {
        this.listeTarget.innerHTML = '';
        const rangs = this.rangs();
        const actifs = rangs.filter((r) => r.actif).length;

        rangs.forEach((rang, index) => {
            const etat = this.etatDe(rang.nom);
            const li = document.createElement('li');
            li.className = 'kf-item';
            li.dataset.actif = rang.actif ? '1' : '0';

            // TOUJOURS CINQ ENFANTS, quoi qu'il arrive : la ligne est une grille, et
            // c'est ce qui aligne les champs d'une ligne à l'autre. Un enfant en
            // moins décalerait toute la ligne d'une colonne.
            li.appendChild(this.position(rang, index));
            li.appendChild(this.bascule(rang, index));
            li.appendChild(this.reglage(rang.nom, etat));
            li.appendChild(this.etat(etat, rang.nom));
            li.appendChild(this.fleches(rang, index, actifs));

            this.listeTarget.appendChild(li);
        });
    }

    /**
     * LA CELLULE D'ÉTAT : le voyant, et l'action quand il y en a une.
     *
     * Le réarmement est une ACTION, pas un état — il sort donc de la pastille qui
     * décrit l'état, sans quoi on lisait « à sec jusqu’à 10:05 Réarmer » d'un seul
     * tenant et le bouton passait inaperçu.
     */
    etat(etat, nom) {
        const cellule = document.createElement('span');
        cellule.className = 'kf-item__etat';
        cellule.appendChild(this.voyant(etat, nom));
        if (etat.disponible && etat.epuise && etat.cle) {
            cellule.appendChild(this.rearmer(etat, nom));
        }

        return cellule;
    }

    /** Le rang d'appel, en clair : « chaîné » comme « épinglé » parlent d'ordre. */
    position(rang, index) {
        const span = document.createElement('span');
        span.className = 'kf-item__position';
        span.textContent = rang.actif ? String(index + 1) : '–';
        // Décoratif pour les lecteurs d'écran : le rang est déjà porté par l'ordre
        // de la liste et par l'annonce qui suit chaque déplacement.
        span.setAttribute('aria-hidden', 'true');
        span.title = rang.actif ? `Essayé en position ${index + 1}` : 'Hors liste : jamais appelé';

        return span;
    }

    /** La vraie case à cocher, avec le nom du fournisseur pour étiquette cliquable. */
    bascule(rang, index) {
        const label = document.createElement('label');
        label.className = 'kf-item__bascule';

        const caseACocher = document.createElement('input');
        caseACocher.type = 'checkbox';
        caseACocher.className = 'form-check-input';
        caseACocher.checked = rang.actif;
        caseACocher.addEventListener('change', () => {
            this.politique.ordre = rang.actif
                ? this.politique.ordre.filter((n) => n !== rang.nom)
                : [...this.politique.ordre, rang.nom];
            this.render(rang.actif
                ? `${rang.nom} est écarté : il ne sera plus appelé.`
                : `${rang.nom} est remis dans la liste.`);
        });
        label.appendChild(caseACocher);

        const nom = document.createElement('span');
        nom.className = 'kf-item__nom';
        nom.textContent = rang.nom;
        label.appendChild(nom);

        const etat = this.etatDe(rang.nom);

        // ⚠ LE REPLI NE SE DÉCOCHE PAS, et l'infobulle doit le dire. Le navigateur
        // prend la main dès que plus aucun fournisseur du serveur n'a de souffle, coché
        // ou non. Le cocher ne l'ACTIVE pas : cela le fait passer AVANT le serveur,
        // même quand celui-ci répond — un choix de latence contre timbre.
        label.title = etat.repli === true
            ? (rang.actif
                ? 'Le navigateur parle toujours en premier. Décochez pour rendre la main au serveur : il restera le repli automatique quand plus rien ne répond.'
                : 'Le navigateur prend déjà la main tout seul dès que plus aucun fournisseur du serveur n’a de souffle. Cochez-le pour qu’il passe AVANT le serveur, sans attendre.')
            : (rang.actif
                ? 'Décochez pour retirer ce fournisseur de la liste : il ne sera plus appelé.'
                : 'Cochez pour le remettre dans la liste.');

        const mention = this.mention(rang, index, etat);
        if (mention !== '') {
            const etiquette = document.createElement('span');
            etiquette.className = 'kf-item__mention';
            // LE REPLI PORTE UNE ICÔNE DE BASCULE, et une couleur qui le distingue d'un
            // fournisseur écarté : ce n'en est pas un, c'est la garantie de dernier
            // recours. Exigence de l'exploitant, 2026-09-23.
            if (etat.repli === true) {
                etiquette.classList.add('kf-item__mention--repli');
                if (etat.enService === true) { etiquette.classList.add('kf-item__mention--actif'); }
                if (this.hasIconeTarget) {
                    etiquette.appendChild(this.iconeTarget.content.cloneNode(true));
                }
            }
            etiquette.appendChild(document.createTextNode(mention));
            label.appendChild(etiquette);
        }

        return label;
    }

    /**
     * Ce qui arrivera vraiment à cette ligne, compte tenu du mode et du rang.
     *
     * LE NAVIGATEUR N'EST JAMAIS « ÉCARTÉ ». Il reprend la main tout seul dès que plus
     * aucun fournisseur du serveur ne peut répondre — c'est une règle du produit, pas
     * un réglage. Le mot « écarté » à côté de lui laissait croire qu'on pouvait la
     * désactiver ; on ne peut pas.
     */
    mention(rang, index, etat = {}) {
        if (etat.repli === true) {
            // L'ÉCRAN DIT LA SITUATION, PAS LE DISPOSITIF. Écrire « repli automatique »
            // pendant que le repli PARLE, c'est décrire un mécanisme au lieu de décrire
            // ce qui se passe : l'agent qui ouvre la console un jour de quota épuisé
            // doit lire que c'est le navigateur qui répond.
            if (etat.enService === true) {
                return rang.actif ? 'en service' : 'en service — le serveur est à sec';
            }

            return rang.actif ? 'toujours appelé en premier' : 'repli automatique';
        }
        if (!rang.actif) { return 'écarté'; }
        if (this.politique.mode !== 'epingle') { return ''; }

        return index === 0 ? 'seul appelé' : 'jamais atteint';
    }

    reglage(nom, etat) {
        const conteneur = document.createElement('span');
        conteneur.className = 'kf-item__reglage';

        const champ = document.createElement('input');
        champ.type = 'text';
        champ.id = `kf-modele-${this.familleValue}-${nom}`;

        // UNE ÉTIQUETTE VISIBLE, liée au champ. Un filigrane n'est pas une
        // étiquette : il disparaît à la saisie et n'est pas toujours restitué
        // (WCAG 3.3.2, Bastien & Scapin > Guidage).
        const intitule = document.createElement('label');
        intitule.className = 'kf-item__reglage-nom';
        intitule.textContent = 'Modèle';
        intitule.setAttribute('for', champ.id);
        conteneur.appendChild(intitule);

        // Le filigrane sert alors à ce qu'il sait faire de mieux : montrer la valeur
        // RÉELLEMENT en vigueur, celle qu'on garde en laissant le champ vide.
        champ.placeholder = etat.modele || 'modèle du serveur';
        champ.value = (this.politique.reglages[nom] && this.politique.reglages[nom].modele) || '';
        champ.setAttribute('aria-label', `Modèle de ${nom}`);
        champ.setAttribute('aria-describedby', `kf-aide-modele-${this.familleValue}`);
        champ.title = etat.modele
            ? `Laissez vide pour garder le modèle du serveur (${etat.modele}).`
            : 'Laissez vide pour garder le modèle du serveur.';
        champ.addEventListener('input', () => {
            const valeur = champ.value.trim();
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
        conteneur.appendChild(champ);

        // ── LA VÉRITÉ SUR LE MODÈLE QUI RÉPOND ────────────────────────────────
        // Ce moteur ne s'arrête pas à son modèle principal : un 503 ou un quota
        // atteint le fait basculer sur le suivant, et il continue de répondre. Tant
        // que l'écran n'affichait que le premier, il nommait la mauvaise chose : on
        // cherchait la cause d'une réponse lente, chère ou médiocre du côté d'un
        // modèle qui n'avait pas parlé.
        //
        // On montre donc la CHAÎNE entière, chaque maillon marqué s'il est à sec, et
        // l'on DÉSIGNE celui auquel Ket est réellement branchée à cet instant.
        const chaine = Array.isArray(etat.chaine) ? etat.chaine : [];
        if (chaine.length > 1) {
            const bloc = document.createElement('p');
            bloc.className = 'kf-chaine';

            const titre = document.createElement('span');
            titre.className = 'kf-chaine__titre';
            titre.textContent = 'Modèles tentés, dans l’ordre :';
            bloc.appendChild(titre);

            chaine.forEach((maillon, rang) => {
                const puce = document.createElement('span');
                const repond = maillon.nom === etat.repondAvec;
                puce.className = `kf-chaine__modele${repond ? ' is-actif' : ''}${maillon.epuise ? ' is-epuise' : ''}`;
                // L'état n'est JAMAIS porté par la seule couleur (WCAG 1.4.1) : il
                // est écrit, entre parenthèses, à côté du nom.
                puce.textContent = maillon.nom
                    + (repond ? ' (répond)' : (maillon.epuise ? ' (à sec)' : ''));
                puce.title = maillon.principal ? 'Modèle principal' : `Secours n° ${rang}`;
                bloc.appendChild(puce);
            });

            if (!etat.repondAvec) {
                const alerte = document.createElement('span');
                alerte.className = 'kf-chaine__alerte';
                // Le dire en toutes lettres : « aucun modèle disponible » n'est pas
                // une absence d'information, c'en est une — et elle explique tout.
                alerte.textContent = 'Toute la chaîne est à sec : ce fournisseur ne répond plus.';
                bloc.appendChild(alerte);
            }

            conteneur.appendChild(bloc);
        }

        return conteneur;
    }

    /**
     * L'état constaté par le serveur. Le MOT porte l'information, jamais la seule
     * couleur (WCAG 1.4.1) ; la pastille ne fait que la redire plus vite.
     */
    voyant(etat, nom) {
        const span = document.createElement('span');
        if (!etat.disponible) {
            span.className = 'kf-voyant kf-voyant--absent';
            // « non configuré » et non « clé absente » : la disponibilité ne tient pas
            // qu'à la clé. Une voix ElevenLabs sans identifiant de voix, une oreille
            // Gemini sans modèle, un moteur forcé sur le simulé sont tout aussi
            // indisponibles — et « clé absente » aurait envoyé chercher au mauvais
            // endroit. Vérifié dans les cinq `estDisponible()` le 2026-09-22.
            span.textContent = 'non configuré';
            span.title = `${nom} n’est pas configuré sur ce serveur (clé d’API, voix ou modèle manquant) : cela se règle dans la configuration du serveur, pas ici.`;

            return span;
        }
        if (etat.epuise) {
            span.className = 'kf-voyant kf-voyant--sec';
            span.textContent = etat.echeance ? `à sec jusqu’à ${this.heure(etat.echeance)}` : 'à sec';
            span.title = `${nom} s’est déclaré épuisé : il n’est plus interrogé jusqu’à cette heure.`;

            return span;
        }
        span.className = 'kf-voyant kf-voyant--ok';
        span.textContent = etat.repli === true && etat.enService === true ? 'en service' : 'prêt';
        span.title = `${nom} a sa clé et aucun quota épuisé : il peut répondre.`;

        return span;
    }

    rearmer(etat, nom) {
        const bouton = document.createElement('button');
        bouton.type = 'button';
        bouton.className = 'kf-rearmer';
        bouton.textContent = 'Réarmer';
        // L'intitulé visible reste court, mais hors contexte « Réarmer » ne dit pas
        // QUOI : le nom du fournisseur est donné au lecteur d'écran (WCAG 2.4.4).
        bouton.setAttribute('aria-label', `Réarmer ${nom} maintenant`);
        bouton.title = `Effacer la marque d’épuisement de ${nom} et le rendre interrogeable tout de suite`;
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

    /**
     * `total` compte les ACTIFS, pas les lignes : les décochés sont rendus après
     * eux et n'ont pas de flèches. Le compter sur les lignes laissait la flèche du
     * bas cliquable sur le dernier actif — pour un geste sans effet.
     */
    fleches(rang, index, total) {
        const conteneur = document.createElement('span');
        conteneur.className = 'kf-fleches';
        if (!rang.actif) { return conteneur; }

        [['▲', -1, 'Monter'], ['▼', 1, 'Descendre']].forEach(([glyphe, pas, titre]) => {
            const bouton = document.createElement('button');
            bouton.type = 'button';
            bouton.textContent = glyphe;
            bouton.setAttribute('aria-label', `${titre} ${rang.nom}`);
            bouton.title = `${titre} ${rang.nom}`;
            bouton.disabled = (pas < 0 && index === 0) || (pas > 0 && index >= total - 1);
            bouton.addEventListener('click', () => {
                const position = this.politique.ordre.indexOf(rang.nom);
                const cible = position + pas;
                if (position < 0 || cible < 0 || cible >= this.politique.ordre.length) { return; }
                const ordre = this.politique.ordre;
                [ordre[position], ordre[cible]] = [ordre[cible], ordre[position]];
                this.render(`${rang.nom} passe en position ${cible + 1}.`);
            });
            conteneur.appendChild(bouton);
        });

        return conteneur;
    }
}
