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
- ⚠ Écart : le navigateur additionne (:148-154) et formate en `fr-FR` (:230). Chantier 4 de
  `jsbrokers-dev` : le serveur calcule et formate.

### Barre d'actions et menu contextuel — partagé
- Déclaration : `attribute_actions` du canevas de formulaire, avec `droit` et `event`
  (`PisteFormCanvasProvider.php:48-68`).
- JS : `barre-actions.js` (workspace ET dialogue), `toolbar_controller.js`,
  `actions-groupees.js` (`actionsVisibles` :39, `grouperActions` :72, `urlAction` :132),
  `circuit-icones.js` (`CircuitIcones` :25), `context-menu_controller.js` (écoute
  `app:context.changed`, :58).
- CSS : `.context-menu` (`app.css:1139`).
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

### Badges et compteurs — partagé
- `.jsb-state-badge` (`app.css:1796`), modificateurs `--etape-*` posés par la stratégie
  d'indicateurs (`PisteIndicatorStrategy.php:114`).
- ⚠ Écart : `--critique` et `--elevee` en hexadécimal (`app.css:1809-1810`) — chantier 5.

### Toasts — partagé
- `notification-manager_controller.js`, écoute `app:notification.show` `{text, type, action?}` ;
  `action = {label, evenement}` ajoute un bouton construit par le DOM (:57-74) ; durée 10 s,
  20 s avec un geste (:78). Relais : `flash-toast_controller.js` (messages flash),
  `api-response-notifier_controller.js`.
- ⚠ Le texte est inséré en HTML (:47) : l'appelant échappe (`echapper`, `fiche-hors-filtre.js:14`).
- ⚠ Rôle **CIBLE** (arbitrage de Serge, 2026-10-16) : `role="status"` pour une information,
  `role="alert"` réservé aux erreurs. Aujourd'hui `role="alert"` partout (:40) — chantier 2.

### Infobulle — partagé
- Contrôleur générique `infobulle_controller.js`, style `.jsb-ctx-tip` (`app.css:5398`),
  placement `infobulle-position.js` (toujours dans l'écran) ; contenu JSON `data-infobulle`
  construit par `textContent`. Clavier : focus et Échap.
- C'est la SEULE à utiliser dans un écran nouveau (voir les doublons au §3).

### États vides — partagé
- `templates/components/_empty_state.html.twig`, CSS `.empty-state-*` (`app.css:2515-2548`),
  paramètres posés par `_list_manager.html.twig:400-430`.

### Icônes — partagé
- Alias : `IconCanvasProvider.php:10-171` ; Twig : `ux_icon(resolve_icon_name('alias'))` ou
  `<twig:UX:Icon name="{{ resolve_icon_name(...) }}">`. Mascotte : `SKILL.md` §6.
- Côté client : circuit `ui:icon.request` → `app:icon.loaded`, jamais de réponse synchrone.

### Cookies — partagé
- `assets/controllers/cookies.js` : `cookieAEcrire` (:28), `lireCookie` (:42). Seule porte.

## 3. Anti-modèles vécus dans ce projet

- **`saveCookie` dupliqué et faux** : recopié dans `base_controller.js` et `app.js`, il
  préfixait la valeur et posait une expiration invalide. Une seule porte depuis `1131705c`
  (`cookies.js:4-14`).
- **Infobulles du tableau de bord non échappées** : valeurs injectées en HTML, un nom de piste
  piégé s'exécutait au survol (`03e5f6b0`).
- **Composants dupliqués encore présents** (chantier 3 de `jsbrokers-dev`) : quatre infobulles
  (`infobulle` / `.jsb-ctx-tip` ; `#db-meta-tip` et `data-renew-tip` dans `assets/app.js` ;
  `soa-section-tip` dans `soa-context-menu_controller.js`) et quatre menus contextuels
  (`context-menu`, `console-context-menu`, `entreprise-context-menu`, `soa-context-menu`).
  Treize échappements écrits à la main (`assets/app.js:1681`, :1745, :1828, :1980,
  `fiche-hors-filtre.js:14`, et sept contrôleurs) — chantier 1.
- **Chips qui ne suivaient pas le design existant**, boutons maison au lieu de la pilule,
  classes sans leur montage, police réduite « un cran plus compact » : trois allers-retours
  sur le même composant (mémoire « Réutiliser le pattern UI existant »).

## 4. Checklist

- [ ] J'ai cherché le composant dans `app.css` et ouvert un gabarit qui s'en sert.
- [ ] J'ai recopié le montage complet, sans redimensionner le composant.
- [ ] Échelon de l'échelle de décision justifié dans le plan (`SKILL.md` §5).
- [ ] Les interactions passent par le Cerveau ; les événements sont nommés `ui:*` / `app:*`.
- [ ] Aucun contenu dynamique posé par `innerHTML` ; le texte d'un toast est échappé.
- [ ] Toute action d'écran est au manifeste `CouvertureDesEcrans`.
- [ ] Un composant nouveau ou modifié est décrit ici, avec son statut.
