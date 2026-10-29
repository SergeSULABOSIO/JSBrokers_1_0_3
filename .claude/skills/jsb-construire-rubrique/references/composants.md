# Composants — inventaire et réutilisation

Le catalogue maison n'est documenté nulle part ailleurs que dans le code (`assets/styles/app.css`,
`templates/components/`, `assets/controllers/`). Ce fichier en est l'inventaire. Un composant
ajouté ou modifié par un chantier est mis à jour ici **dans le même chantier**.

## 1. Règles

1. **Réutiliser avant de créer.** Réflexe d'ouverture : `grep -n "^\.jsb-" assets/styles/app.css`,
   puis `grep -rln "<classe>" templates/` pour trouver un gabarit qui s'en sert : c'est le modèle.
2. **Recopier le MONTAGE, pas seulement les classes.** Un composant est une arborescence
   (conteneur, modificateur, titre, enveloppe). Ex. chips : `.jsb-preset-filters-bar` >
   `.jsb-preset-filters.jsb-control-pill[role=group][aria-label]` >
   `span.jsb-preset-filters__titre` + `button.jsb-preset-chip > span`. Référence :
   `templates/components/echange/_colonnes.html.twig`.
3. **Ne jamais redimensionner un composant partagé** (police, marge, couleur) : ce qui s'adapte,
   c'est la boîte autour.
4. **Dans Joseara, les composants `.jsb-*` priment sur les classes Bootstrap** (arbitrage de
   Serge, 2026-10-16) : Bootstrap nu n'est utilisé que là où aucun `.jsb-*` n'existe.
5. **Toute interaction passe par le Cerveau** (`cerveau_controller.js`) : un composant émet
   `cerveau:event` (ou `notifyCerveau`), le Cerveau diffuse des `app:*`. Aucun composant n'en
   appelle un autre directement.
6. **Le serveur écrit, le navigateur clone** : HTML rendu par Twig, `<template>` cloné ;
   décisions JS testables dans des modules purs (`tests/js/*.test.mjs`).
7. **Tout contenu dynamique est échappé** (`jsbrokers-dev` §4) : `textContent`, jamais
   `innerHTML` d'une donnée saisie.
8. **Un composant nouveau** suit `SKILL.md` §5 : clé déclarative, statut local puis partagé au
   deuxième usage, CSS générique `.jsb-*` dans `app.css`.

## 2. Où regarder dans le code — le catalogue

Statut : **partagé** (≥ 2 rubriques ou surfaces) ou **local** (une seule).

### Liste : list-manager, list-row — partagé
- Gabarits : `templates/components/_list_manager.html.twig`, `_list_row.html.twig`,
  `_list_row_secondary_items.html.twig`, `_list_content.html.twig`.
- Contrôleurs : `list-manager_controller.js` (annonce `app:list.rendered`, :304),
  `list-row_controller.js`, `selecto.js` (nom affiché, charge utile de sélection).
- Pilotée par le canevas de liste (`colonne_principale`, `badges`, `textes_secondaires`,
  `colonnes_numeriques`) : `PisteListCanvasProvider.php:24-79`.
- ⚠ Code PLAT dans `textes_secondaires` : un chemin pointé fait tomber la rubrique entière
  (`PisteListCanvasProvider.php:46-48`).

### Chips de filtre rapide — partagé
- Déclaration : `filtres_predefinis` du canevas de liste (`PisteListCanvasProvider.php:123-133`).
- CSS : `.jsb-preset-filters` (`app.css:1838`), `.jsb-preset-chip` (:1945), `.jsb-control-pill` (:1313).
- JS : `chip-preset-etat.js`, `chips-defilement.js` (sort le titre dans `.jsb-preset-filters-wrap`).
- Un GESTE (« tout cocher ») prend `jsb-preset-chip--action`, sans `aria-pressed`. Un bouton qui
  n'est pas un filtre prend une autre classe (`.jsb-vue-choix`, :2099), sinon `_syncPresetChips`
  le resynchronise comme un chip.
- Tout chip survit au F5 (règle de Serge) : voir `etats-interface.md`.
- Dans le workspace, les groupes passent dans le RAIL DES CHIPS (refonte de la colonne 3, C3) :
  un bouton par groupe (`groupe.icone`, alias `filtre:*`, `RailDesChipsDiscernableTest`), la
  rangée devient son panneau (`rail-chips_controller.js`, règles `rail-chips-regles.js`). Aucun
  geste du pointeur n'épingle : sortie du pointeur ou du focus = fermé. Jamais en dialogue.

### Barre du bas d'une liste — partagé (toutes les listes du workspace)
- `.jsb-list-controls-bar` (`_list_manager.html.twig`) : état + **taille de page** (une seule
  pastille, `.jsb-list-controls-bar__etat`), pagination, totaux ; une hauteur `--jsb-barre-bas-h` commune au rail ; paliers de réduction par
  `@container jsb-zone` (jamais un montant ni une monnaie tronqués, `BarreDuBasMontantsEntiersTest`).
- **TAILLE DE PAGE (Serge, 2026-10-29)** — l'utilisateur choisit combien de fiches par page :
  - choix PRÉVISIBLES (liste, pas une saisie) rendus par le serveur depuis
    `App\Service\Liste\TailleDePage::CHOIX` (10, 20, 50, 100) ; **20 par défaut** ;
  - le SERVEUR borne (`TailleDePage::depuis` dans la recherche dynamique) : chaque fiche chargée
    est décomptée en tokens ; hors de la liste = le défaut ; le total de la sélection relit la
    même page ;
  - ce n'est PAS une préférence enregistrée : retenue par l'onglet (`parPage` de l'état du
    Cerveau, rangée sur l'onglet par `memoriserParPage`), elle **survit au F5** (copie
    `sessionStorage`) mais pas à la fermeture de l'onglet du navigateur (`copieLocale` l'écarte) ;
  - **« Réinitialiser » la ramène à 20** (`reinitialiser: true` → `_handleSearchRequest`) ; un
    chip, un terme ou « Tout retirer » la gardent ;
  - elle FINIT LA PHRASE DE L'ÉTAT, dans la même pastille : « 20 affiché(s) sur 42 › 1
    sélection(s) · [20] par page » (Serge, 2026-10-29 : isolée et sans texte, elle n'était « pas
    du tout intuitive ») ; « par page » ne s'efface jamais ; le sélecteur SUIT la zone annoncée
    (`role=status`), il n'y entre pas — le cerveau la réécrit ;
  - masquée tant que la liste tient dans le plus petit choix. Tests : `TailleDePageTest`,
    `criteres-persistes.test.mjs`, `stockage-de-travail.test.mjs`.

### Vue Cartes (pipe en colonnes) — **local** (Pistes)
- Clé : `vue_cartes` du canevas de liste (`PisteListCanvasProvider.php:95-122`) : gabarit de
  carte, étapes, monnaie, plafond, une action par étape.
- Gabarits : `components/vue_cartes/_tableau.html.twig`, `_source.html.twig` (`<template
  data-vue-cartes-source>`), `_carte_piste.html.twig`.
- JS : `vue-cartes_controller.js` (écoute `app:list.rendered`, `app:loading.start/stop`, :41-48),
  règles pures `vue-cartes-regles.js`.
- CSS : `.jsb-pipe` (`app.css:7725`), `.jsb-carte` (:7863) ; couleurs `--etape-*` (`app.css:47-54`).
- Serveur : `PisteController::actionPipeApi` décide la fiche à ouvrir (:256).
- Quand l'utiliser : un flux à étapes calculées. Deuxième usage → la généraliser (gabarit de
  carte et étapes déjà déclaratifs).

### Totaliseur — partagé
- Gabarit `_list_summary.html.twig` (« Total de la page », :22-23), contrôleur
  `list-summary_controller.js`, CSS `.jsb-list-summary` (`app.css:2177`).
- Le SERVEUR calcule et formate tout (chantier 4 de `jsbrokers-dev`) : le contrôleur affiche
  des textes. Une rubrique nouvelle déclare ses grandeurs dans son `NumericCanvasProvider` par
  `GrandeurTotalisable::montant()` / `::nombre()` — jamais un taux, un indice ni une moyenne.
- Total de la sélection : demandé par le Cerveau avec la requête de la liste
  (`totauxDeLaSelection`) ; une liste servie par un contrôleur propre (comme la production des
  intermédiaires) y répond aussi.

### Barre d'actions et menu contextuel — partagé
- Déclaration : `attribute_actions` du canevas de formulaire, avec `droit` et `event`
  (`PisteFormCanvasProvider.php:48-68`).
- JS : `barre-actions.js` (workspace ET dialogue), `toolbar_controller.js`,
  `actions-groupees.js` (`actionsVisibles` :39, `grouperActions` :72, `urlAction` :132),
  `circuit-icones.js` (`CircuitIcones` :25), `context-menu_controller.js` (écoute
  `app:context.changed`).
- Le menu contextuel d'un écran nouveau : son `<nav class="context-menu">` rendu par le serveur
  (`role="menu"`, entrées `role="menuitem"`, refus en `aria-disabled`), ouvert par la COQUILLE
  `menu-contextuel.js` : `new MenuContextuel(menu).ouvrir(gesteDOuverture(event, ligne))`. La
  ligne se focalise (touche Menu, Maj+F10). Rien d'autre à écrire : placement, clavier,
  fermetures, un seul menu ouvert. Le déclarer dans `ComposantsUniquesTest::MENUS`.
- CSS : `.context-menu` (`app.css`).
- Workspace : 4 actions puis « Autres actions », icônes seules ; dialogue : libellés.
- Toute action d'écran a son entrée au manifeste `CouvertureDesEcrans` (`PariteEcranKetTest`).

### Onglets — partagé
- CSS `.jsb-onglet*` (`app.css:520-590`), écrit une seule fois.
- JS : `onglets-formulaire_controller.js`, `onglets-uniques.js`, `onglets-debordement.js`,
  `compte-onglet.js` (pastille de compte : `marquerEnChargement` :51, `poserLeCompte` :57).
- Une collection = un onglet (`ongletTitre` dans le canevas de formulaire,
  `PisteFormCanvasProvider.php:211-213`).

### Dialogue et collections — partagé
- `dialog-manager_controller.js` (coquille, barre d'actions), `dialog-instance_controller.js`
  (une fiche ; écoute `app:fiche.modifiee`, :84 ; 409 d'empreinte, :584-587),
  `collection_controller.js`, `collection-tampon.js` (collections d'une fiche pas encore créée).
- Canevas : `form_intro`, `field_icons`, `facts_labels`, `visibility_conditions`.
- Champ de picker : `.jsb-picker-field` (`app.css:5887`) ; carte de champ `.dlg-field-card` (:3147).

### Confirmation — partagé
- `confirmation-dialog_controller.js`, ouvert par `confirm-action_controller.js` (gabarits) ou
  par événement. Titre toujours en texte. Corps : `corpsTexte` (le défaut, pour toute donnée) ;
  `corpsHtml` seulement pour un HTML composé soi-même avec `echapper` (décision pure :
  `confirmation-corps.js`). `data-confirm-action-body-value` est du TEXTE, jamais du HTML.

### Éditeur riche (Quill) — partagé
- Tout `<textarea>` d'un dialogue devient Quill (`_form_row.html.twig`,
  `rich-text_controller.js`) : le champ stocke du HTML. **Le déclarer** au registre
  `ChampsEnTexteRiche` (sinon `ChampsEnTexteRicheTest` échoue) : il est alors assaini au flush
  et contrôlé en longueur.
- Le lire comme du texte : filtre Twig `texte_lisible` (liste, tableau de bord, e-mail) ou
  `TexteRiche::lisible()` (PHP, Ket). Le rendre en HTML : `texteRicheSur` (JS, DOMPurify).
  Jamais `|striptags`, jamais `|raw`.

### Échappement JS — partagé
- `assets/controllers/echapper.js` : `echapper(valeur)`, les cinq caractères. Seule porte
  quand on compose une chaîne HTML ; sinon `textContent`.

### Badges et compteurs — partagé
- `.jsb-state-badge` (`app.css:1796`), modificateurs `--etape-*` posés par la stratégie
  d'indicateurs (`PisteIndicatorStrategy.php:114`).
- ⚠ Écart : `--critique` et `--elevee` en hexadécimal (`app.css:1809-1810`) — chantier 5.

### Toasts — partagé
- `notification-manager_controller.js`, écoute `app:notification.show` `{text, type, action?}` ;
  `action = {label, evenement}` ajoute un bouton construit par le DOM (:57-74) ; durée 10 s,
  20 s avec un geste (:78). Relais : `flash-toast_controller.js` (messages flash),
  `api-response-notifier_controller.js`.
- Le texte est posé par `textContent` (depuis le chantier 1, C2) : l'appelant passe le texte
  BRUT, sans l'échapper (sinon il s'afficherait en entités).
- ⚠ Rôle **CIBLE** (arbitrage de Serge, 2026-10-16) : `role="status"` pour une information,
  `role="alert"` réservé aux erreurs. Aujourd'hui `role="alert"` partout (:40) — chantier 2.

### Infobulle — partagé
- L'infobulle UNIQUE : moteur `infobulle-moteur.js` (posé sur `<body>` par
  `infobulle_controller.js`), styles `styles/infobulle.css`, placement `infobulle-position.js`.
- Le serveur DÉCLARE le contenu : `data-infobulle="{{ sections|json_encode|e('html_attr') }}"`,
  sections `{titre, lignes: [[libellé, valeur, 'tip-montant tip-fort']], texte}` (classes `tip-*`
  seules). Style « Pistes » : titres de section, montants à droite, états `tip-ok/attente/alerte/info`.
- Posée au curseur, immobile ; `data-infobulle-placement="bouton"` sous un bouton. Survolable,
  Échap, persistante au focus clavier (WCAG 1.4.13).
- C'est la SEULE : `ComposantsUniquesTest` refuse toute autre bulle.

### États vides — partagé
- `templates/components/_empty_state.html.twig`, CSS `.empty-state-*` (`app.css:2515-2548`),
  paramètres posés par `_list_manager.html.twig:400-430`.

### Icônes — partagé
- Alias : `IconCanvasProvider.php:10-171` ; Twig : `ux_icon(resolve_icon_name('alias'))` ou
  `<twig:UX:Icon name="{{ resolve_icon_name(...) }}">`. Mascotte : `SKILL.md` §6.
- Côté client : circuit `ui:icon.request` → `app:icon.loaded`, jamais de réponse synchrone.
  Cache persistant par `icone-memorisee.js` uniquement (un SVG inerte, sinon redemandé).

### Cookies — partagé
- `assets/controllers/cookies.js` : `cookieAEcrire` (:28), `lireCookie` (:42). Seule porte.

## 3. Anti-modèles vécus dans ce projet

- **`saveCookie` dupliqué et faux** : recopié dans `base_controller.js` et `app.js`, il
  préfixait la valeur et posait une expiration invalide. Une seule porte depuis `97a9c2a0`
  (`cookies.js:4-14`).
- **Infobulles du tableau de bord non échappées** : valeurs injectées en HTML, un nom de piste
  piégé s'exécutait au survol (`300ed88a`).
- **Composants dupliqués** (chantier 3 de `jsbrokers-dev`, FAIT 2026-10-25) : dix-neuf
  infobulles et treize menus contextuels écrits chacun à sa façon, dont les 8 du tableau de bord
  inaccessibles au clavier. Une infobulle, une coquille de menu ; `ComposantsUniquesTest` refuse
  leur retour.
- **Treize échappements écrits à la main**, dont certains oubliaient `"` et `'` : fusionnés
  dans `echapper.js` (chantier 1, C2/C4) ; un garde-fou node refuse leur retour.
- **Texte riche réaffiché tel quel** : `<img onerror>` collé dans une description s'exécutait
  à l'ouverture de la fiche (Quill) et dans le panneau d'attributs ; assaini au flush et à
  l'affichage depuis le chantier 1 (C6).
- **Chips qui ne suivaient pas le design existant**, boutons maison au lieu de la pilule,
  classes sans leur montage, police réduite « un cran plus compact » : trois allers-retours
  sur le même composant (mémoire « Réutiliser le pattern UI existant »).

## 4. Checklist

- [ ] J'ai cherché le composant dans `app.css` et ouvert un gabarit qui s'en sert.
- [ ] J'ai recopié le montage complet, sans redimensionner le composant.
- [ ] Échelon de l'échelle de décision justifié dans le plan (`SKILL.md` §5).
- [ ] Les interactions passent par le Cerveau ; les événements sont nommés `ui:*` / `app:*`.
- [ ] Aucun contenu dynamique posé par `innerHTML` ; une chaîne HTML composée passe par
      `echapper` ; un toast reçoit du texte brut.
- [ ] Un champ riche (`<textarea>` de dialogue) est déclaré au registre `ChampsEnTexteRiche`,
      et se lit partout par `texte_lisible`.
- [ ] Toute action d'écran est au manifeste `CouvertureDesEcrans`.
- [ ] Un composant nouveau ou modifié est décrit ici, avec son statut.
