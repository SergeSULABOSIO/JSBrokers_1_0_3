---
name: jsbrokers-dev
description: Règles de développement du dépôt JSBrokers (Joseara) — BIC Cristal, cloisonnement entre cabinets, source unique et parité écran ↔ Ket, « le serveur écrit, le navigateur clone », échappement, procédure de test, livraison, mode Plan. Invoquer avant tout plan, correction, fonctionnalité, revue, test, commit ou push dans ce dépôt.
---

# Skill — Développer dans JSBrokers

Ce que ce dépôt exige, et que le code ne dit pas à lui seul. Chaque règle vient d'un incident
ou d'une décision ; la mémoire du projet en garde le détail.

## 0. Règle d'usage — le métier d'abord

Tout plan qui touche les **cotations, avenants, tranches, notes, paiements, commissions ou
rétrocessions** consulte d'abord la skill **`courtage-assurance`** — et
**`reglementation-rdc-arca`** dès qu'une taxe, un état ou une mention réglementaire est en jeu —
puis **cite l'invariant** qu'il préserve (« à facturer ≠ à encaisser », « prime exigée selon le
type »…). Un plan qui ne cite aucun invariant sur ces sujets n'est pas prêt.

- Un invariant marqué **CIBLE** est une décision prise mais pas encore codée : le plan dit s'il
  le réalise, ou au minimum qu'il n'aggrave pas l'écart. Les chantiers décidés sont listés dans
  `courtage-assurance` (§7).
- **Une politique propre à un cabinet ne devient jamais une règle du produit** (ex. « toujours
  le taux maxima ») : le produit propose, borne et contrôle ; le cabinet choisit.
- **Nouvelle rubrique, nouvel écran ou nouvelle fonctionnalité → skill `jsb-construire-rubrique`**
  (fiche de rubrique, parcours en phases, composants, états, textes, icône mascotte).

## 1. BIC Cristal — le principe de conception

- **Une seule fonction, bien faite.** Un écran répond à UNE question ; ce qui n'y contribue pas sort.
- **L'état réel visible d'un coup d'œil.** Pas de chiffre faux, pas de bouton qui promet ce que le
  serveur refusera : on le cache, ou on dit pourquoi (ex. le « + » des Avenants qui explique).
- **Une couleur = un sens.** Couleurs de la charte uniquement (skill `charte-couleurs`) ; jetons CSS.
- **Rien ne bouge sans action de l'utilisateur.** Et rien du tout pour `prefers-reduced-motion`.
- **Pièces standard.** Réutiliser les composants `.jsb-*` d'`app.css`, l'infobulle du tableau de
  bord, la barre d'actions partagée… avant d'en écrire un. Zéro dépendance nouvelle sans raison.
- **Partout utilisable** : souris, clavier, tactile (skill `ergonomie-web`).

## 2. Cloisonnement entre cabinets

- **Tout identifiant venu de la requête** (URL, corps, `?cotation=`, `?ids=`, champ de Ket) passe
  par `App\Service\Workspace\AppartenanceAuCabinet` : `exigerLeCabinetOuvert()`,
  `estDuCabinetOuvert()`, `estDUnAutreCabinet()` (écritures de Ket, cabinet du périmètre).
  Aucun filtre Doctrine ne protège un `find()` nu.
- **Le refus d'un id étranger est un 404**, jamais un refus nommé : il confirmerait l'existence
  de ce qu'on protège. Un message d'erreur ne cite jamais le nom d'une donnée d'un autre cabinet.
- **UN SÉLECTEUR N'AFFICHE JAMAIS LES DONNÉES D'UN AUTRE CABINET** — c'est la règle (Serge,
  2026-10-15). Tout `EntityType` (liste, boutons radio, cases) porte un `query_builder` filtré par
  cabinet — `FormListenerFactory::setFiltreEntreprise()` —, comme les champs à autocomplétion.
  Refuser l'id étranger à l'écriture ne suffit pas : la liste, elle, montrait noms et
  descriptions. Deux sélecteurs l'avaient oublié (chargement cible d'un type de revenu, modèle
  de pièce de sinistre) ; test `SelecteursDuCabinetTest`. Seule exception : la console interne
  de Joseara, hors cabinet (ex. `CrmTicketType`).
- **« Mon portefeuille » est un confort de lecture, pas une frontière** : la frontière est le
  scoping entreprise, toujours appliqué.

## 3. Une source unique, et la parité écran ↔ Ket

- Une règle métier vit à UN endroit (ex. `DefautsDeSouscription`, `ResponsableDePiste`,
  `PisteTransformationScope`, `getCotationMontantCommissionTtc`) ; l'écran, Ket, la reprise et
  les mouvements l'appellent — jamais une copie.
- **Le serveur garantit la règle** ; l'écran l'annonce (bouton masqué ou expliqué) mais ne la remplace pas.
- Tests de parité opposables : `PariteEcranKetTest` (manifeste `CouvertureDesEcrans`),
  `CoherenceChipsAssistantTest`, `ReversementPariteEcranKetTest`, `ResponsableDePistePariteTest`.
  Toute action d'écran nouvelle a son pendant Ket, ou une exclusion motivée.
- Un refus de Ket se lit dans `bloquant` (phrase du courtier) ; `note` ne s'adresse qu'au modèle
  et ne franchit jamais la frontière (`MotifDeRefusTest`).

## 4. Le serveur écrit, le navigateur clone

- Le HTML est rendu par le serveur ; le JS **clone** des `<template>` et pose des données — il ne
  fabrique ni libellé ni chiffre (ex. `<template data-vue-cartes-source>`, aperçu des mouvements).
- Les décisions JS testables vivent dans des modules purs (`vue-cartes-regles.js`, `cookies.js`,
  `compte-onglet.js`) couverts par `tests/js/*.test.mjs`.
- **Tout contenu dynamique est échappé.** Twig échappe par défaut : jamais `|raw` sur une donnée
  saisie. En JS, une donnée se pose par `textContent`, jamais par `innerHTML` ni par une chaîne
  HTML — infobulles comprises (modèle : `infobulle_controller.js`, contenu construit par
  `textContent`). Incident (`03e5f6b0`) : les infobulles « pistes » et « renouvellements » du
  tableau de bord injectaient leurs valeurs en HTML (`dataset` décode l'attribut) ; un nom de
  piste piégé s'exécutait au survol, malgré le `|e` du gabarit (corrigé `assets/app.js:1744`
  et `:1979`).
  ⚠ Le toast rend son texte en HTML (`notification-manager_controller.js:47`) : l'appelant
  échappe (`echapper`, `fiche-hors-filtre.js:14`).
- Les cookies s'écrivent et se lisent par `assets/controllers/cookies.js`, et nulle part ailleurs.
- Asset Mapper : aucune compilation. En dev, rien à builder ; en E2E, ne pas laisser `public/assets`.

## 5. Procédure de test

- **PHPUnit, dossier par dossier** (`tests/<Dossier>`) : un seul processus atteint 3 Go. **UNE
  suite à la fois** (verrou `var/suite-par-dossier.lock`) ; ne modifier AUCUN fichier du projet
  pendant qu'elle tourne. Rediriger la sortie dans un fichier et lire le verdict, pas un `tail`.
- **`mocksess`** (renommage de session refusé par Windows) : artefact connu — relancer le fichier
  seul, lire `X-Debug-Exception`, puis le dossier.
- **Node** : `node --test tests/js/` pour tout JS.
- **Code JS/CSS seul** : node, puis les dossiers `Frontend` et `Workspace`.
- **TDD** : le test d'abord, rouge sur le code actuel ; dates calculées, jamais figées.
- **E2E navigateur** : instance Apache séparée (`httpd-e2e.conf`, port 8901, `APP_ENV`
  explicite), puppeteer-core + Edge headless, attentes conditionnelles, cabinet temporaire semé
  puis purgé. Processus arrêtés **par PID uniquement**.
- Une migration se joue aussi en DEV : des tests verts ne prouvent rien sur la base dev.

## 6. Livraison

- **Un commit par sujet, chacun vert avant le suivant** : suite complète (ou node + Frontend +
  Workspace pour du JS seul), revue de sécurité et de code par un sous-agent, corrections.
- `git fetch` puis commit **sans** `tests/Console/SupervisionEcranTest.php` ; push par
  `git push JSBrokers_1_0_3 master` (pas d'`origin`).
- L'utilisateur commite aussi depuis son poste : toujours `fetch` avant de commiter.
- Mise en production : `bin/deploy.sh` + `bin/publier.ps1` ; jamais `git clean` ni `rm -rf var/`.

## 7. Mode Plan

- Chaque constat est **prouvé** : fichier:ligne, test, ou mesure. Ce qui ne l'est pas est marqué.
- Chaque version du plan ouvre sur une section **« Changements »** ; les réponses de
  l'utilisateur sont réintégrées dans le corps du plan, pas laissées en chapeau.
- **Deux règles du code se contredisent, ou une question métier imprévue surgit : on s'arrête
  et on demande.** On ne tranche pas un choix métier à la place de l'utilisateur.
- Une décision de l'utilisateur pas encore codée s'écrit dans les skills comme un invariant
  **CIBLE** : la cible, l'état actuel prouvé, l'écart — jamais comme un fait acquis.
- Outillage honnête : ne citer que les skills, agents et outils réellement disponibles.
- Faire le point régulièrement quand l'utilisateur le demande ; un rapport final reprend ce qui
  a été fait, mesuré, écarté, et ce qui reste ouvert.

## 8. Chantiers décidés (développement) — rien à coder sans plan validé

Inscrits le 2026-10-16 (décisions de Serge), après l'inventaire fait pour la skill
`jsb-construire-rubrique`. Le détail des preuves est dans ses fichiers de référence.

1. **PRIORITAIRE — audit de l'échappement.** Chaque site audité un par un : le toast qui rend
   son texte en HTML (`notification-manager_controller.js:47`), `innerHTML` dans 46 contrôleurs,
   18 `|raw` dans les gabarits. Une seule fonction d'échappement partagée au lieu des treize
   écrites à la main (`assets/app.js:1681`, :1745, :1828, :1980, `fiche-hors-filtre.js:14`, et
   sept contrôleurs). Dans le même chantier : `PisteController::getFormApi` compare l'entreprise
   à la main (`PisteController.php:94-101`) — à faire passer par `AppartenanceAuCabinet`.
2. **Dialogues et toasts.** Échap ferme un dialogue, avec une confirmation s'il y a une saisie
   non enregistrée (aujourd'hui `data-bs-keyboard="false"`, `dialog-manager_controller.js:30`).
   Toasts : `role="status"` pour une information, `role="alert"` réservé aux erreurs
   (aujourd'hui `role="alert"` partout, `notification-manager_controller.js:40`).
3. **Composants dupliqués.** Quatre infobulles (`infobulle`/`.jsb-ctx-tip`, `#db-meta-tip` et
   `data-renew-tip` dans `assets/app.js`, `soa-section-tip`) et quatre menus contextuels
   (`context-menu`, `console-context-menu`, `entreprise-context-menu`, `soa-context-menu`) :
   un seul de chaque.
4. **Totaliseur.** Le chiffre est calculé et formaté par le serveur, plus par le navigateur
   (`list-summary_controller.js:148-154`, `Intl.NumberFormat('fr-FR')` :230).
5. **Valeurs en dur.** Replis `'USD'` (`TrancheType.php:82`, `_list_summary.html.twig:3`,
   placeholder `DepenseType.php:48`) ; `format_montant` à `'$'` par défaut
   (`NombreExtension.php`) ; couleurs hexadécimales des badges (`app.css:1809-1810`, contraste
   du blanc sur `#e69500` à mesurer) ; `date('Y')` dans `PisteController.php:108`, :177 ;
   `color:#0d2440` hors charte, en style inline, sur 11 titres et libellés du picker de
   facturation (`_facturation_picker.html.twig:110` à :351) ; format de date `d/m/Y` écrit en
   dur sur la carte de piste (`_carte_piste.html.twig:40`), au lieu de `format_datetime`.
6. **État vide.** Distinguer « aucune donnée » de « tout est filtré »
   (`_list_manager.html.twig:420-425`).
7. **Route morte** `/admin/piste/test` (`PisteController.php:65-69`).
8. **Mascottes.** Partages (Paiement et PaiementPrime ; Chargement et ChargementPourPrime ;
   DepenseCourtier et ReversementRetroAgent ; Invite et ProductionIntermediaire) et alias
   d'action utilisés comme mascotte (cinq Roles* sur `action:role`, ParametresConge sur
   `action:settings`, PeriodeBlocage sur `action:resiliation`). Serge tranche cas par cas au
   premier chantier de rubrique, avec le test prescrit par `jsb-construire-rubrique` §6.
