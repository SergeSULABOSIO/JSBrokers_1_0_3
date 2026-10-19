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
- **ÉCART CONNU** (relevé au chantier 1, C5) : environ 36 contrôles d'appartenance sont encore
  écrits à la main (`getEntreprise() !== …` puis 404) au lieu d'`AppartenanceAuCabinet`. Ils
  refusent bien en 404 ; tout code touché à proximité les y ramène.
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
- **Les sources uniques de l'échappement** (chantier 1, 2026-10-17) — on les appelle, on n'en
  écrit pas d'autre :
  - `assets/controllers/echapper.js` : la SEULE fonction d'échappement JS, pour un HTML qu'on
    compose soi-même. Garde-fou `tests/js/echappement-unique.test.mjs` (aucun
    `replace(/&/g` ailleurs).
  - **Toast = texte** (`textContent`). Confirmation : `corpsTexte` par défaut, `corpsHtml`
    seulement pour un HTML composé avec `echapper`.
  - Twig : `tests/Twig/RawSurDonneesTest.php` tient la liste blanche motivée des `|raw` ; un
    `|raw` nouveau la fait échouer. Une traduction balisée : `trans({':x': valeur|e})|raw`. Les
    macros d'e-mail gardent leur `|raw` : chaque donnée y entre par `|e`.
- **Texte riche** : tout `<textarea>` de dialogue est un éditeur Quill, qui stocke du HTML.
  - Registre `App\Service\TexteRiche\ChampsEnTexteRiche` : **un nouveau `<textarea>` s'y déclare**
    (`ChampsEnTexteRicheTest` refuse l'oubli).
  - À l'écriture, l'écouteur `AssainissementDuTexteRicheListener` (onFlush : écran, Ket, import)
    réduit le HTML à la barre d'outils (`symfony/html-sanitizer`, configuration `texte_riche`,
    `AttributsDeLaBarreQuill`). ⚠ `#[Target('texte_riche')]` obligatoire : sans lui,
    l'assainisseur PAR DÉFAUT arrive en silence. Un HTML qui déborde la colonne est refusé en
    422 (`ChampsObligatoiresInspector`), jamais tronqué.
  - À la lecture : filtre `texte_lisible` / `TexteRiche::lisible()` partout où le champ se lit
    comme du texte (listes, tableau de bord, SOA, Ket) — le HTML de l'éditeur devient du texte,
    un texte simple reste intact (`striptags` coupait « A<B »). Rendu en HTML : `texteRicheSur`
    (DOMPurify, même liste blanche) ; Quill reçoit `formats: FORMATS_DE_LA_BARRE`.
  - L'export `.jsbx` garde le HTML : format d'échange ré-importable, réassaini au flush.
  - **La recherche lit le TEXTE d'un champ riche** : fonction DQL `TEXTE_DU_HTML`
    (`App\Doctrine\Fonction\TexteDuHtml`), choisie par `JSBDynamicSearchService::colonneCherchable()`
    — seul point de décision, aussi pour les autocomplétions (`RechercheAutocomplete`).
    **`TEXTE_DU_HTML` et `TexteRiche::lisible()`/`enTexte()` sont liés par un test de parité
    (`TexteDuHtmlTest`) : modifier l'un impose de modifier l'autre.** Le même test vérifie que
    toute entité que le sanitizer écrit est décodée par la base. L'argument est converti en
    utf8mb4 d'abord (sinon « Illegal mix of collations » sous une connexion unicode_ci,
    incident du 2026-10-19).
- **LIKE** : un seul motif (`motifLike`, `ESCAPE '!'`) — `%`, `_`, `!` tapés sont du texte ; un
  terme réduit à rien ne trouve rien.
- **Ce qu'on relit du `localStorage` n'est pas sûr** : une icône passe par
  `icone-memorisee.js` (SVG inerte, sinon effacée), une URL d'onglet par `url-de-confiance.js`
  (même origine) avant tout `fetch` suivi d'un `innerHTML`.
- **Le texte d'une erreur technique ne sort pas** (chantier 10, 2026-10-19). Un message
  d'exception va à l'écran (JSON, flash, Twig, NDJSON, rapport d'import) par
  `App\Service\Erreur\MessageDErreur::pourLEcran($e, $repli)` : relayé si l'exception porte
  le marqueur `App\Exception\MessagePourLUtilisateur` (ou est une `\DomainException`), sinon
  journalisé en ERROR (supervision) et remplacé par le repli métier du site. Un refus métier
  sans exception dédiée se lève en `App\Exception\RefusMetierException` — jamais en
  `\RuntimeException`/`\Exception` nue, que le tri masquerait. Garde-fou :
  `tests/Security/MessagesDErreurTest.php` (inventaire motivé des `getMessage()` de `src/`).
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
  Les dépendances se réinstallent sur l'empreinte de `composer.lock`
  (`vendor/.composer-lock.sha1`). **Prévenir Serge de tout commit qui modifie `composer.lock`.**
- **`publier` = `cd ~/joseara && bash bin/deploy.sh`, SANS `git pull`** (2026-10-19) : le
  `.htaccess` du serveur porte toujours les blocs de l'hébergeur, et un `git pull` échoue dès
  qu'un commit touche ce fichier. `deploy.sh` récupère la version visée et se relance dans sa
  propre nouvelle version. Sur le serveur, ne JAMAIS `git checkout`/`git stash` le `.htaccess`
  (le bloc `alt-php82` partirait → 500). Tout commit qui touche `public/.htaccess` : le dire.
- **Hôtes** : `trusted_hosts` (prod) n'admet que joseara.com et www ; les sous-domaines que
  cPanel sert depuis le dossier du site (`mail.`…) sont renvoyés par `.htaccess`, les autres
  hôtes reçoivent un 400 journalisé en INFO (`HoteInconnuListener`) — pas d'alerte.
- **Montée de version de Symfony** : `cache:clear` ne suffit pas toujours — des fichiers du pool
  `cache.system` à l'ancien format ont survécu en local (500 sur toute icône en 7.4). `deploy.sh`
  vide ce pool entre `cache:clear` et `cache:warmup` (`CachesInternesAuDeploiementTest`). Avant
  de livrer une montée, rejouer la séquence de `deploy.sh` en `APP_ENV=prod APP_DEBUG=0` sur le
  conteneur prod compilé par l'ancienne version (les commandes d'avant l'étape 7 tournent dessus).
- **Tests et Symfony 7.4** : la page d'erreur de débogage lit les journaux de tout le processus ;
  `tests/Support/JournauxDeDebogageParRequete` (env test) les remet à zéro à chaque requête.
  `HttpKernelBrowser` capture une réponse dans son propre tampon : `FluxNdjson` ne ferme que les
  tampons de PHP.
- Vérifications faites en production (chantier 1) : requêtes REGEXP de détection des charges
  dans les champs texte (lecture seule, phpMyAdmin) — aucune trace, contre-épreuve valide ;
  journal du limiteur du formulaire de contact `var/log/contact-AAAA-MM-JJ.log` (IP retenue =
  `REMOTE_ADDR`, pas de proxy, `TRUSTED_PROXIES` vide).

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

1. ✅ **FAIT (2026-10-16/17) — audit de l'échappement**, en huit commits : C0 console Joseara
   (XSS stockée cabinet → console), C1 e-mails et `|raw`, C2 `echapper.js` + toast en texte,
   C3 formulaire de contact (limites, champ piège, accusé réduit), C4 tableau de bord /
   espace de travail / recherche, C5 cloisonnement de `getFormApi` et de ses huit frères (404),
   C6 texte riche assaini, C7 caches du `localStorage` et infobulle d'attribut. Les règles qui en
   restent sont au §4. Production vérifiée : aucune trace d'exploitation.
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
7. **Routes et code morts** : `/admin/piste/test` (`PisteController.php:65-69`) ;
   `/admin/demande_contact` répond 500 en GET (gabarit absent) — le POST, lui, passe par
   `GardeDuFormulaireDeContact` ; `RetroAgentController::assertPeutConsulter`, jamais appelée.
8. **Mascottes.** Partages (Paiement et PaiementPrime ; Chargement et ChargementPourPrime ;
   DepenseCourtier et ReversementRetroAgent ; Invite et ProductionIntermediaire) et alias
   d'action utilisés comme mascotte (cinq Roles* sur `action:role`, ParametresConge sur
   `action:settings`, PeriodeBlocage sur `action:resiliation`). Serge tranche cas par cas au
   premier chantier de rubrique, avec le test prescrit par `jsb-construire-rubrique` §6.

Inscrits le 2026-10-17, à la clôture du chantier 1 (constats faits en route, prouvés) :

9. **CSP, d'abord en `Content-Security-Policy-Report-Only`**, avec un point de collecte des
   violations. Aucun en-tête CSP aujourd'hui (`public/.htaccess` ne pose que
   `Permissions-Policy`). Recensement à résorber avant d'appliquer : 91 gestionnaires `on…=`
   dans les gabarits et 4 dans des chaînes JS (dont les `onclick` des tableaux de production
   d'`assets/app.js`), 12 `<script>` inline, 1 610 attributs `style=` (qui imposeront
   `style-src 'unsafe-inline'` au début). Quill est chargé depuis jsDelivr
   (`rich-text_controller.js:2`, CSS :84) : le vendoriser par `importmap:require`, ou l'autoriser
   explicitement.
10. ✅ **FAIT (2026-10-19) — `$e->getMessage()` à l'écran.** 13 sites routés par
    `MessageDErreur::pourLEcran` (règle au §4) ; fuite prouvée et corrigée (aperçu de bordereau :
    chemin du serveur à l'écran). Reste hors chantier : `ControllerUtilsTrait::executerLaSuppression`
    rend un texte générique sans journaliser la panne (le trait n'a pas de logger).
11. ✅ **FAIT (2026-10-19) — la recherche lit le texte, pas le HTML** (règles au §4). Option A
    retenue (conversion à la lecture, pas de migration) sur mesures : prod ≤ 43 fiches par
    rubrique riche et par cabinet, coût ≈ 0,16 ms par fiche convertie, filtre cabinet par
    index (le coût suit le cabinet, pas la table). **CIBLE** : au-delà de ~2 000 fiches par
    rubrique riche et par cabinet, passer à l'option **B** (texte précalculé à l'écriture dans
    une table `texte_recherche`, écrit par l'écouteur d'assainissement ; une migration, une
    commande de remplissage) — seul `colonneCherchable()` change. Piste écartée : le
    **pré-filtre par mot** sur la colonne brute, qui n'est pas une condition nécessaire (un mot
    contenant & < > ou un pleine-chasse est stocké encodé ; une balise au milieu d'un mot —
    gras partiel — casse le mot). Écart connu, antérieur : la porte « champ simple » du service
    ne vérifie pas que le champ existe dans les métadonnées (`$actualField`) — à traiter à part
    (l'ignorer élargirait les résultats en silence).
12. ✅ **FAIT (2026-10-18/19) — dépendances : 60 avis → 0.** 12-A : correctifs sans changer de
    Symfony (dont le cookie « se souvenir de moi » et l'XSS d'ux-autocomplete ; options riches
    gardées par `AutocompleteLibelleHtmlExtension`). 12-B : Symfony 7.1 → **7.4 LTS** (maintenu
    jusqu'en 2029). Recettes Flex relues : `csrf.yaml` (CSRF sans état) NON retenu — un
    `recipes:update` le recréerait, à refuser ; `property_info.with_constructor_extractor: false`.
    Reste pour Symfony 8 (dépréciations du projet) : options de contraintes en tableau
    (`RegistrationFormType`, `ChangePasswordFormType`), `Utilisateur::eraseCredentials()`,
    `RateLimiterFactory` → `RateLimiterFactoryInterface` (`GardeDuFormulaireDeContact`), imports
    de routes XML (`config/routes/framework.yaml`, `web_profiler.yaml`), `UrlType` sans
    `default_protocol` (`AssureurType`, `EntrepriseType`), `#[TaggedIterator]` (5 fichiers de
    `src/Services/Canvas`), `ux-toggle-password` déprécié.
