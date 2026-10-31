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
- **Pièces standard.** Réutiliser les composants `.jsb-*` d'`app.css`, l'INFOBULLE UNIQUE
  (`infobulle-moteur.js`), la COQUILLE DE MENU (`menu-contextuel.js`), la barre d'actions
  partagée… avant d'en écrire un. Zéro dépendance nouvelle sans raison. Une infobulle ou un menu
  fabriqué à côté fait échouer `ComposantsUniquesTest` (chantier 3).
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
- **La frontière est le cabinet ; exceptions à scope dédié : Congés (`CongeVisibiliteScope`) et
  Tâches (`TacheVisibiliteScope`). Mon portefeuille reste un confort de lecture.** (Serge,
  2026-10-21.)
  - **Tâches** (avec leurs retours et documents) : un invité voit celles qui lui sont assignées
    ou dont il est l'auteur, celles de son responsable (`Invite::manager`) et de ses assistants
    directs (un seul niveau, assignées ou créées par eux), et celles sans exécutant ;
    l'administrateur du cabinet (propriétaire ou délégué) voit tout. Posée par le SERVEUR à
    chaque porte : moteur de recherche (listes, comptage, onglets, Ket, plan du jour) juste
    après le cloisonnement par cabinet — critère qui traverse `taches` compris —,
    fiche/enregistrement/suppression/clôture (404, `exigerUneFicheVisible`), rattachement d'un
    retour ou d'une pièce (création ET déplacement), collections en mode `dialog` comme
    `generic` et leurs pastilles, tableau de bord, relevé de compte, et chez Ket `lire_fiche`,
    `statistiques`, `telecharger_documents` (`DescenteDesDocuments`), `attacher_fichier`,
    enfants de collection ; compteur `tachesEnCours` ; origine affichée d'un document
    (`DocumentFichier::parentDe`). Recalculée à chaque requête, aucun cache. Un document
    rattaché AUSSI à une autre fiche reste visible par elle
    (`TacheVisibiliteScope::AUTRES_RATTACHEMENTS_DU_DOCUMENT`, comparée aux métadonnées par
    test). API unique : `visiblePourLeLecteur`, `filtrer`, `conditionPourLeLecteur`,
    `cercleDuLecteur` — la conversion « lecteur → règle » n'est écrite que là.
  - **Le lecteur : `InviteCourant`, ÉCHEC FERMÉ.** Sans utilisateur, ou connecté sans
    invitation dans le cabinet : AUCUNE tâche (pas même celles sans exécutant). Le propriétaire
    sans fiche d'invitation reste administrateur. Ket dans son worker : l'invité est IMPOSÉ
    (`IdentiteDuTraitement::endosser`). Lire SANS frontière exige un **contexte système
    explicite et nommé** (`InviteCourant::enContexteSysteme`), réservé à la ligne de commande et
    au worker ; il REFUSE de s'ouvrir pendant une requête HTTP (ex. `app:echange:smoke`).
  - **Export** : `ExportateurJsbx` applique la frontière. Un export proposé un jour À L'ÉCRAN
    passera par la règle de l'invité, JAMAIS par le contexte système.
  - **Suppression** (`SuppressionEnCascade::planifier`, plan final — écran, aperçu, dossier
    lot par lot, Ket) : si le plan détruit ou DÉTACHE une tâche (retour, pièce) hors du
    cercle, un non-administrateur est refusé — message GÉNÉRIQUE, sans nombre, type ni
    niveau (« Cette piste contient des éléments que vous ne pouvez pas gérer. Seul un
    administrateur peut la supprimer. ») ; l'aperçu ne compte que le visible.
    L'administrateur garde la cascade complète. NB : supprimer un client DÉTACHE ses pistes
    (il ne les détruit pas) ; le ricochet réel est piste → cotation → tâche.
  - **L'auteur (`invite`) ne se réécrit jamais** : `OwnerEventSubscriber` ne le pose que s'il
    est vide, et seulement avec un invité du cabinet de la fiche.
- **« Mon portefeuille » est un confort de lecture, pas une frontière** : la frontière est le
  scoping entreprise, toujours appliqué. Confirmé par Serge le 2026-10-21 (option A) : le
  serveur n'impose pas le portefeuille, la chip est retirable par tous.
  - **CIBLE éventuelle** — cloisonnement entre chargés de compte : une OPTION par cabinet,
    chantier dédié, à n'ouvrir QUE sur demande d'un client. État actuel prouvé :
    `JSBDynamicSearchService` n'applique `__mon_portefeuille__` que s'il est reçu ;
    `WorkspaceAccessResolver` ne connaît aucun droit par portefeuille. Écart : tout invité qui
    lit une rubrique en lit toutes les fiches du cabinet.

## 3. Une source unique, et la parité écran ↔ Ket

- Une règle métier vit à UN endroit (ex. `DefautsDeSouscription`, `ResponsableDePiste`,
  `PisteTransformationScope`, `getCotationMontantCommissionTtc`) ; l'écran, Ket, la reprise et
  les mouvements l'appellent — jamais une copie.
- **UN ENFANT NE NAÎT PAS ORPHELIN** (Serge, 2026-10-30) : une entité qui n'a de sens que rattachée
  (tranche, avenant, revenu, proposition, pièce… — 12 au registre) se déclare dans
  `App\Service\Workspace\ParentObligatoire` ; l'écran, le serveur (`handleFormSubmission`, 422
  qui dit où créer) et Ket (`WorkspaceMutationService` : parent à demander dès l'analyse, blocage à l'écriture) lisent ce seul
  registre. Garde-fou `ParentObligatoireTest` : un enfant dont le formulaire ne laisse pas
  choisir le parent est déclaré, ou exempté avec son motif.
  **Une entité entre au registre des entités dépendantes quand son formulaire ne permet pas de
  choisir son parent ; si le formulaire impose le parent (obligatoire ET contrôlé par le
  serveur), la création depuis la liste principale reste permise** — elle va alors dans
  `ParentObligatoire::PARENT_CHOISI` (le serveur exige le parent, l'écran ne masque rien). Cas
  des rôles RolesEn* : sans collaborateur nommé, AuditableTrait posait l'invité connecté (rôle
  muet pour soi-même) ; un second rôle de la même famille est refusé
  (`RoleUniqueParFamille` : droits = union des rôles, un doublon rendait un retrait sans effet).
- **TOUT POINT D'ENTRÉE QUI CRÉE UNE FICHE (liste, état vide, menu contextuel, tableau de bord,
  Ket…) LIT LA MÊME DÉCISION SERVEUR ; AUCUN NE DÉCIDE SEUL** (Serge, 2026-10-30) :
  `DecisionDeCreation` (droit + mode de création + parent du contexte), posée dans
  `parametres.ajout` et appliquée par `handleFormSubmission` avec la même fonction. Détail dans
  `jsb-construire-rubrique/references/composants.md` ; garde-fou `CreationPariteTest`.
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
  HTML — infobulles comprises (infobulle unique : contenu DÉCLARÉ par le serveur,
  `data-infobulle="{{ sections|json_encode|e('html_attr') }}"`, posé par `textContent`).
  Incident (`300ed88a`) : les infobulles « pistes » et « renouvellements » du
  tableau de bord injectaient leurs valeurs en HTML (`dataset` décode l'attribut) ; un nom de
  piste piégé s'exécutait au survol, malgré le `|e` du gabarit (corrigé `300ed88a`, puis
  remplacé par l'infobulle unique, `b7a23d34`).
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
- **Le stockage du navigateur n'appartient qu'à son utilisateur** (chantier 15, 2026-10-21,
  décisions de Serge ; règles dans `assets/stockage-de-travail-regles.js`) :
  - clés `<famille>_<utilisateur>_<cabinet>` ; l'utilisateur se lit sur
    `<html data-jsb-utilisateur>` (identifiant NUMÉRIQUE seul, jamais nom ni e-mail) ;
  - **sessionStorage** = l'état complet de l'onglet du navigateur (F5 à l'identique) ;
    **localStorage** = disposition + filtres STRUCTURÉS (chips, comparaisons, dates, booléens),
    7 jours, jamais un texte saisi ni un nom (relations, bénéficiaires, aperçus, titres de
    conversation ; « Mon portefeuille » sous libellé neutre) ;
  - purge par préfixe, tous cabinets, à la connexion d'un autre utilisateur et à la
    déconnexion (`/login?deconnexion=1`) — module `assets/stockage-de-travail.js`, importé
    AVANT `bootstrap.js` ;
  - **une nouvelle clé qui porte du travail** : par utilisateur + cabinet, et son préfixe dans
    `PREFIXES_DE_TRAVAIL` ; **un nouveau chip qui porte un nom** : `PORTENT_UN_NOM_OU_UN_TEXTE`
    ou `LIBELLES_NEUTRES`.
- **Une réponse appartient à l'onglet qui l'a DEMANDÉE ; une recherche va à l'onglet qu'elle
  NOMME** (`assets/controllers/routage-onglet.js`). Jamais `workspaceTabId:
  this.currentWorkspaceTabId` dans ce qui part après un `fetch` ; un émetteur de
  `ui:search.submitted` passe son `workspaceTabId`. Un contexte publié pour un onglet en
  arrière-plan porte `arrierePlan: true` ; un contexte provisoire (`etatProvisoire`) ne se
  mémorise jamais (`ongletAMemoriser`). Garde-fou : `tests/js/routage-onglet.test.mjs`.
- **Échap dans les dialogues** (chantier 2 ; règles `assets/controllers/echap-dialogue.js`, un
  seul gestionnaire dans `dialog-manager`, en phase de CAPTURE, d'après la PILE des modales) :
  d'abord ce qui est ouvert dans le dialogue (autocomplétion, menu, picker, liste « +N » —
  repérés par `aria-haspopup`/`combobox` + `aria-expanded="true"`, `.jsb-picker-overlay`…),
  puis le dialogue du DESSUS seulement ; rien pendant une saisie Quill, un envoi en cours
  (Fermer verrouillé), une touche maintenue, ni dans un champ `[data-echap-local]`. Un
  formulaire se ferme par `dialog-instance#close()`, qui demande si l'EMPREINTE du formulaire
  (prise au premier geste, création comprise, Quill et fichiers compris) a changé ; la question
  se ferme par `ui:confirmation.close`. Le focus revient à l'ouvreur (`__ouvreur` posé à
  l'ouverture). Un nouveau dialogue garde `data-bs-keyboard="false"`.
  - **Saisie sans geste** (gestionnaire de mots de passe, dictée, extension) : sans empreinte,
    un `input`/`change` dans le dialogue suffit à poser la question. Donc **tout `input`/`change`
    émis par notre code passe par `evenementInterne()`** (`assets/controllers/evenement-interne.js`)
    — garde-fou `tests/js/dialogue-suivi.test.mjs`. Tom Select (recopie vers le champ qu'il gère,
    `champ.tomselect`) est exclu ; Quill n'émet `change` que sur un geste (menu de sa barre).
  - **Un seul gestionnaire de dialogues actif** (`gestionnaire-dialogues.js`) : un second
    `dialog-manager` (analyse du bordereau injectée) reste inerte. Unification : chantier 3.
  - Une fermeture AUTOMATIQUE se demande « si inactif » (`siInactif: true`, suite par `siFerme`) :
    refusée si l'utilisateur a repris la saisie.
  - Le pied d'un dialogue a deux zones dès l'ouverture : `feedbackAlerte` (`role="alert"`),
    `feedbackStatut` (`role="status"`). Jamais d'`aria-live` changé à la volée.
- **Messages** (chantier 2 ; `notification-regles.js`) : deux régions permanentes dans
  `base.html.twig` — `role="status"` pour l'information, `role="alert"` réservée aux ERREURS ;
  le toast n'a pas de rôle. Une erreur reste jusqu'à ce qu'on la ferme (au plus 3, pas de
  doublon) ; ne pas doubler d'un toast une erreur déjà affichée au pied d'un dialogue.
- **Le texte d'une erreur technique ne sort pas** (chantier 10, 2026-10-19). Un message
  d'exception va à l'écran (JSON, flash, Twig, NDJSON, rapport d'import) par
  `App\Service\Erreur\MessageDErreur::pourLEcran($e, $repli)` : relayé si l'exception porte
  le marqueur `App\Exception\MessagePourLUtilisateur` (ou est une `\DomainException`), sinon
  journalisé en ERROR (supervision) et remplacé par le repli métier du site. Un refus métier
  sans exception dédiée se lève en `App\Exception\RefusMetierException` — jamais en
  `\RuntimeException`/`\Exception` nue, que le tri masquerait. Garde-fou :
  `tests/Security/MessagesDErreurTest.php` (inventaire motivé des `getMessage()` de `src/`).
- Les cookies s'écrivent et se lisent par `assets/controllers/cookies.js`, et nulle part ailleurs.
- **Fichiers déposés** (chantier 18) — on les sert, on ne les publie jamais :
  - les PIÈCES vivent dans `app.dossier_des_pieces` (`var/uploads/documents`), HORS de la
    racine web ; le code n'y accède que par `DocumentFichier::chemin()` (aucun chemin écrit
    en dur — `TeleversementsInertesTest`). Les LOGOS seuls sont publics, dans
    `public/uploads/logos` (`.htaccess` : images seulement, `sandbox`, `nosniff`).
  - Tout fichier déposé est servi par `ReponseDePiece` : téléchargé, sauf affichage demandé
    ET type RÉEL (lu dans le contenu) PDF/PNG/JPEG/GIF/WebP ; `nosniff` + `sandbox` sur
    chaque réponse ; nom d'origine nettoyé et encodé RFC 5987. Jamais `DownloadHandler`.
  - Nouveau mapping Vich : hors de `public/` (`StockageDesPiecesTest` refuse le contraire,
    logos exceptés), nommeur `App\Service\Document\NomAleatoire` — qui doit rester PUBLIC
    dans `services.yaml`, sinon tout dépôt échoue.
  - **ÉCART ACCEPTÉ (Serge, 2026-10-22)** : le verrou des logos est une LISTE DE REFUS (types
    actifs refusés), pas « images seulement » — un « tout refuser puis autoriser les images »
    par `<FilesMatch>` n'est pas éprouvé sous LiteSpeed, et un échec y couperait tous les
    logos. Condition, tenue par `StockageDesPiecesTest` : le téléversement de logo (mapping
    `entreprises`, `NomAleatoire::pourUnLogo` — extension tirée du CONTENU, png/jpg/gif/webp)
    est le seul code qui ÉCRIT dans `public/uploads/logos` ; tout autre code qui y touche est
    motivé dans le test. Filet : `sandbox` + `nosniff` sur chaque réponse.
  - `public/uploads/` est ignoré par git EN BLOC (verrous et `.gitkeep` exceptés) : des pièces
    y avaient été commitées par le passé, dans un dépôt public — purgées de l'historique le
    2026-10-22 (réécriture `git filter-repo`, tous les SHA postérieurs au 2025-08-29 changés).
  - Un test qui dépose un fichier lit le vrai dossier : `tests/Support/DossierDesPieces`.
  - **Un test efface les fichiers de ses cabinets d'essai comme leurs lignes** : en TÊTE de son
    nettoyage, `FichiersDesCabinetsDeTest::effacer(static::getContainer(), self::ENT)` (même
    source que la suppression d'un cabinet : `ServiceSuppressionEntreprise::fichiersDuCabinet`).
    L'extension `FichiersLaissesParLesTests` (phpunit.xml.dist) désigne tout test qui laisse un
    fichier, dans `var/fichiers-laisses-par-les-tests.log` — ce fichier doit rester absent.
    (37 178 fichiers orphelins nettoyés sur le poste le 2026-10-22.) Elle surveille aussi le
    dossier temporaire du SYSTÈME (`[temp]/`, noms de nos `tempnam`) : `tempnam(…) . '.xlsx'`
    y laisse un fichier vide — écrire sur le chemin que rend `tempnam` (314 restes effacés le
    2026-10-23 ; sous Windows le préfixe est tronqué à 3 lettres : `ket52E5.tmp`).
  - **Un fichier de passage qui porte des données d'un cabinet ne dort JAMAIS hors de chez lui**
    (chantier 19 bis, 2026-10-23) :
    - une copie ou un rendu remis à Vich (qui le DÉPLACE au flush) naît dans
      `TemporairesDuCabinet::nouveau()` (`var/tmp/cabinets/<id>/`) : effacé s'il n'a pas été
      emporté (remise à zéro du service, destruction), parti avec le cabinet, purgé après une
      heure par `app:echange:purger` (PHP tué net) ;
    - un classeur déposé pour l'import, par l'écran OU par Ket : `DepotDeLEchange::nouveauChemin()` ;
    - un fichier produit pour UN téléchargement : `EnvoiPuisEffacement::reponse()` — effacé
      dans un `finally`, coupure ignorée, d'un seul tenant (jamais `readfile` puis `unlink` ;
      jamais `BinaryFileResponse`, qui sert des morceaux sur `Range`) ;
    - un temporaire de travail (`tempnam`, `FichierTemporaire::avec`) s'efface dans un `finally`
      de la requête qui l'écrit.
  - E2E sur les fichiers : une instance Apache qui LIT les `.htaccess` (`AllowOverride All`,
    `mod_rewrite`/`mod_headers`) — l'instance E2E habituelle les ignore et répondrait 200.
- Asset Mapper : aucune compilation. En dev, rien à builder ; en E2E, ne pas laisser `public/assets`.
- **CSP** (chantier 9, politique dans `App\Security\Csp\PolitiqueCsp`, nulle part ailleurs) :
  - tout `<script>` inline porte `nonce="{{ csp_nonce() }}"`, et `importmap()` reçoit
    `{nonce: csp_nonce()}` (test statique `PolitiqueCspTest`) ;
  - **une CSS ne s'importe jamais depuis le JS** : AssetMapper en ferait un module
    `data:application/javascript,` que `script-src` refuse. Les feuilles vont dans
    `templates/segments/_feuilles_de_l_application.html.twig` ; un `autoimport` CSS de
    `controllers.json` reste à `false`. N'ajoutez JAMAIS `data:` à `script-src` ;
  - le nonce n'entre jamais dans `style-src` (il y annulerait `'unsafe-inline'`) ;
  - les rapports (`/api/journal/csp`) passent par `App\Supervision\AdresseDuNavigateur` :
    adresses sans requête, fragment, jeton ni identifiant. Ils n'envoient jamais d'e-mail
    (`alerter: false`), car n'importe qui peut en fabriquer ;
  - **aucun site extérieur** : la politique est une liste blanche exacte, et aucune adresse
    absolue n'apparaît dans `templates/` ni dans `assets/` (hors `vendor`). Une bibliothèque se
    vendorise (`importmap:require`) ;
  - **aucun `on…=`**, ni dans un gabarit, ni dans le HTML que fabrique le JavaScript
    (`GestesSansCodeTest`). Gestes génériques : contrôleur `geste` ; fonctions `window.db…` du
    tableau de bord : contrôleur `tableau-de-bord` (liste fermée `FONCTIONS_AUTORISEES` ; une
    fonction nouvelle s'y ajoute, sinon le test échoue) ; pages sans Stimulus :
    `assets/gestes-autonomes.js`. Règles d'écran : skill `jsb-construire-rubrique` §3.

## 5. Procédure de test

- **PHPUnit, dossier par dossier** : `bin/phpunit-par-dossier.sh [Dossier…]` (un seul
  processus atteint 3 Go) ; verdicts dans `var/suite-par-dossier.log`. **UNE suite à la fois** :
  le verrou `var/suite-par-dossier.lock` garde le PID de la suite, refuse un second lancement et
  se reprend seul si ce PID est mort. Pour arrêter : `bin/phpunit-par-dossier.sh arreter` (tue la
  boucle ET le PHP) — **jamais** lever le verrou à la main (deux suites mêlées le 2026-10-13 et le
  2026-10-27). Ne modifier AUCUN fichier du projet pendant qu'elle tourne.
- **`mocksess`** (renommage de session refusé par Windows) : artefact connu — relancer le fichier
  seul, lire `X-Debug-Exception`, puis le dossier.
- **Node** : `node --test tests/js/` pour tout JS.
- **Code JS/CSS seul** : node, puis les dossiers `Frontend` et `Workspace`.
- **TDD** : le test d'abord, rouge sur le code actuel ; dates calculées, jamais figées.
- **E2E navigateur** : instance Apache séparée (`httpd-e2e.conf`, port 8901,
  **`APP_ENV=e2e`**), Edge headless piloté par CDP, attentes conditionnelles, cabinet
  temporaire semé puis purgé. Processus arrêtés **par PID uniquement**.
  - L'environnement **e2e** = celui de test (base `_test`, e-mails et IA neutralisés) mais avec
    de VRAIES sessions verrouillées (`var/sessions/e2e`) : l'env `test` (`mock_file`, sans
    verrou) perd la session dès que deux requêtes se croisent (F5).
  - **Poste de développement seulement** : `App\Kernel::exigerUnPosteDeDeveloppement` exige
    `.env.e2e.local` (ignoré par git, jamais déployé) et refuse toute requête non locale ou
    relayée (`X-Forwarded-For`) — `tests/Security/EnvironnementE2eTest.php`.
  - Un onglet du navigateur en arrière-plan ne reçoit aucune frame : `Page.bringToFront`
    avant d'y agir.
- Une migration se joue aussi en DEV : des tests verts ne prouvent rien sur la base dev.

## 6. Livraison

- **NOTE DE VERSION (règle durable, Serge 2026-10-30)** : toute évolution annoncée aux
  utilisateurs l'est par une note écrite POUR LES PRATICIENS DU COURTAGE (gestionnaires de
  comptes, chargés de production, comptables, directeurs de cabinet) — vocabulaire du métier
  (`courtage-assurance`), aucun jargon technique (commit, code, CSS, JavaScript, worker, cache,
  API, migration, serveur, base de données, test, noms de fichiers ou de composants), ce que
  l'utilisateur voit et fait, une page au plus, ce qui ne change pas, chaque surprise annoncée
  avec sa raison métier, captures avant/après annotées, et l'invitation au retour (Feedbacks,
  Support). Modèle et contrôle : `jsb-construire-rubrique/references/notes-de-version.md`.
  **Montrée à Serge avant toute publication ; le canal (dans Joseara, e-mail, les deux) est à
  confirmer par lui.**
- **AUCUN MESSAGE DE COMMIT N'EST JAMAIS PUBLIÉ HORS DE L'ÉQUIPE** (Serge, 2026-10-30, après une
  fuite) : un message de commit décrit la correction — faille comprise (cloisonnement, XSS,
  écriture sans droit…). Ni page, ni infobulle, ni e-mail, ni flux, ni réponse de Ket ne les
  montre à un utilisateur ; les utilisateurs ne voient que des NOTES DE VERSION. Le journal
  `/nouveautes` est réservé à l'équipe (ROLE_ADMIN, 404 sinon, `noindex`) ; seul le NUMÉRO de
  version s'affiche ailleurs. Garde-fou `tests/Nouveautes/NouveautesPageTest.php`.
- **LE DÉPÔT EST PRIVÉ ; AUCUN SECRET N'EST JAMAIS COMMITÉ ; TOUT SECRET EXPOSÉ EST RÉGÉNÉRÉ, PAS
  SEULEMENT RETIRÉ** (Serge, 2026-10-30). L'historique git garde tout ce qui y est entré : retirer
  une valeur d'un fichier ne la reprend à personne. Les secrets vivent sur le serveur seulement
  (`~/joseara/.env.local`, `chmod 600`, compilé en `.env.local.php` par `deploy.sh`) ; `.gitignore`
  exclut `.env.local`, `.env.*.local`, `.env.local.*`, les clés (`*.pem`, `*.key`, `*.p12`,
  `*.pfx`) et les dumps (`*.sql`, `*.sql.gz`, `*.dump`). Audit de tout l'historique le
  2026-10-30 : aucun secret actif (l'ancien `APP_SECRET` public, retiré le 2026-09-13, n'est plus
  en production). Le dépôt GitHub était PUBLIC au 2026-10-30 : Serge le passe en privé en fin de
  plan, après la mise en place d'une clé de déploiement en lecture seule (le serveur fait
  `git fetch origin`).
- **Un commit par sujet, chacun vert avant le suivant** : suite complète (ou node + Frontend +
  Workspace pour du JS seul), revue de sécurité et de code par un sous-agent, corrections.
- `git fetch` puis commit ; une modification non commitée qui n'est pas la vôtre se MONTRE à
  Serge (diff), qui décide — rien ne reste en suspens dans le dossier de travail. Push par
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
- **WORKER DE KET (2026-10-29)** — sans lui, une question à Ket en file (`ASSISTANT_ASYNC=1`)
  n'obtient jamais de réponse. Le worker de `symfony serve` (`--time-limit=3600`) s'arrêtait au
  bout d'une heure et n'était JAMAIS relancé (mesuré : 10 h 44 min 13 s → 11 h 44, aucun
  successeur) ; aucun script n'appelle `messenger:stop-workers` hors déploiement.
  - **Une seule commande, partout** : `app:ket:worker` (`WorkerDeKetCommand`) — verrou de
    fichier sans attente (`flock`, `var/worker/worker-<env>.lock`) ; tenu par un autre, elle sort
    aussitôt ; libre, elle lance `messenger:consume async --time-limit --memory-limit`. Lancée
    CHAQUE MINUTE : jamais deux workers, et un worker arrêté est remplacé dans la minute.
  - **Production** : cron cPanel chaque minute, ligne exacte et vérification dans
    `deploiement/README.md` (« Worker de Ket ») ; `bin/deploy.sh` envoie `messenger:stop-workers`
    après la réouverture (le cron relance sur le nouveau code).
  - **Développement** : `powershell -ExecutionPolicy Bypass -File bin\worker-ket-dev.ps1` installe
    une tâche planifiée Windows (php-win.exe, aucune fenêtre, `--time-limit=900`) — plus besoin de
    terminal ouvert ; `-Retirer` l'enlève. `symfony serve` lance la même commande (même verrou).
    Dépannage immédiat : `php bin/console app:ket:worker -v` (dit « tourne déjà » s'il y en a un).
  - **Le signal** : le worker BAT toutes les 30 s (`BattementDuWorkerSubscriber` →
    `var/worker/pouls-<env>`) ; `PoulsDuWorker` est la SOURCE UNIQUE de l'état (vivant ≤ 120 s).
    Absent, le chat affiche « Ket est momentanément indisponible, réessayez dans quelques
    instants » (`ketDisponible` du point d'état) et la console (Tableau de bord → Supervision)
    « Worker de Ket : ABSENT » — en dev comme en prod. Tests : `WorkerDeKetTest`,
    `assistant-etapes.test.mjs`.
- Vérifications faites en production : détection des textes piégés dans TOUTES les colonnes de
  texte (291 colonnes, 85 tables ; requête générée depuis le schéma, lecture seule, phpMyAdmin),
  passée par Serge le **2026-10-30** — contre-épreuve 1/1/0, aucune ligne (la mention antérieure
  « chantier 1 » n'avait pas de trace) ; file `async` vide, `ASSISTANT_ASYNC=0`, `IMPORT_ASYNC=0` ;
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
2. ✅ **FAIT (2026-10-21) — Dialogues et toasts.** Règles au §4 (« Échap » et « Messages »).
3. ✅ **FAIT (2026-10-25) — Une infobulle, une coquille de menu** (commits `872357f5` moteur,
   `b7a23d34` tableau de bord, `454e2d0a` barres d'actions, `695af5b7` relevé public, `9322ee36`
   chat de Ket et graphique, `b6ebbb88` coquille de menu, `411b4da6` menus du tableau de bord
   et clavier, puis le garde-fou). Les règles :
   - **Infobulle** : `infobulle-moteur.js` (ES2017 : le relevé PUBLIC charge le même module par
     `infobulles-autonomes.js`), styles `styles/infobulle.css`. Le serveur DÉCLARE le contenu
     (`data-infobulle`, sections `{titre, lignes:[[libellé, valeur, 'tip-montant']], texte}`,
     classes `tip-*` seules) ; une barre d'actions pose un `title` (source « titre »). Options
     déclaratives : `data-infobulle-placement="bouton"`, `data-infobulle-si-replie`,
     `data-infobulle-taire` (× d'une puce), `data-infobulle-sur-sombre` (zone sombre). Une bulle
     posée PAR LE CODE (infobulle « externe » de Chart.js, graphique de production) :
     `afficherInfobulle()` ; `fermerInfobulle(zone)` ne ferme que celle de sa zone. WCAG 1.4.13 :
     survolable, Échap, persistante, `aria-describedby`.
   - **Exception** : l'infobulle NATIVE de Chart.js, DESSINÉE dans le canevas (aucun élément,
     aucun HTML) — graphiques des groupes et des risques (`assets/app.js`), graphiques du chat
     (`assistant-chart-render.js`). Hors du chantier 3 : une bulle externe en DOM y passerait par
     `afficherInfobulle()`.
   - **Frontière** : une infobulle qui montre des tâches ou leur nombre lit les mêmes requêtes
     filtrées que les listes (`TacheVisibiliteScope`).
   - **Menu contextuel** : `menu-contextuel.js` (classe `MenuContextuel`) ouvre au curseur ou sous
     la ligne, gère flèches, Début/Fin, Entrée/Espace, Échap (focus rendu), Tab, clic dehors,
     défilement, et UN SEUL menu ouvert. L'écran garde son menu (rendu serveur, `role="menu"` /
     `menuitem`, une entrée refusée en `aria-disabled`) et ses actions.
   - **Clavier** : toute ligne qui porte un menu se focalise ; touche Menu et Maj+F10 l'ouvrent
     sous la ligne, sur la première entrée (`gesteDOuverture(event, ligne)` — Chromium marque ce
     `contextmenu` `button = -1`).
   - **Garde-fou** `tests/Frontend/ComposantsUniquesTest.php` : seul le moteur fabrique une bulle ;
     tout clic droit ouvre par la coquille (ou se déclare relais motivé) ; tout `role="menu"` se
     déclare. Plus fins : `InfobulleUniqueTest`, `MenuContextuelUniqueTest`,
     `MenusDuTableauDeBordTest`. Une bulle « maison » (élément en `position: fixed`) échappe à un
     test statique : à voir en revue. Restent hors coquille, motivés : menus-boutons de `barre-actions.js` (ils ne
     participent pas encore à « un seul menu ouvert ») et menu d'entête de Ket (plein écran).
4. ✅ **FAIT (2026-10-25/26) — Le totaliseur, calculé et formaté par le serveur** (commits
   `e484d5f0`, `a76b4bbd`, `378eebbe` ; décisions de Serge : taux retirés, sélection calculée par
   le serveur, portée page + sélection). Les règles :
   - **Nature de chaque grandeur** (`GrandeurTotalisable`) : `montant` (centimes entiers) ou
     `nombre` (compte, entier). Une grandeur NON ADDITIVE (taux, indice, cours, moyenne) ne se
     déclare pas : une somme de taux n'est pas un taux. `TotaliseurNatureDesGrandeursTest` le
     tient pour toutes les rubriques.
   - **Total de la page** : `BarreDesTotaux` (via `CanvasBuilder::getBarreDesTotaux`) rend la
     barre — options, totaux DÉJÀ FORMATÉS comme une cellule (code de la monnaie de travail,
     nombre dans la langue active), valeurs par ligne — sous la clé `numericAttributesAndValues`.
   - **Total de la sélection** : la requête de la liste porte `totauxDeLaSelection` ; le serveur
     refait la MÊME recherche et ne totalise que les cochées qui en font partie (cloisonnement par
     construction, `TotalDeLaSelectionCloisonneTest`), dit un total partiel (« (1 sur 3) »).
   - Le navigateur n'additionne ni ne formate (`tests/js/totaliseur-serveur.test.mjs`).
   - ⚠ `ListColumnsNumericCanvasBuilder` déclare toute colonne non-% en montant : une future
     colonne de COMPTE y serait ×100.
5. ✅ **FAIT (2026-10-26) — Valeurs en dur** (`e4d1ba09` monnaie, `761b8a96` couleurs,
   `8858cdd7` exercice ; décisions de Serge du 2026-10-26). Ce qui en reste comme règle :
   - **Aucun symbole monétaire par défaut.** `format_montant` (Twig, `ServiceNombres`,
     `number-format.js`) rend le nombre seul ; un prix en dollars (tokens) passe `'$'`
     explicitement. Un montant du cabinet porte le CODE de sa monnaie de travail, jamais un
     « $ » d'office ni un repli « USD » (`TrancheType`). Tableaux de Ket : « 3 195,16 CDF »,
     monnaie lue UNE fois par tableau, jamais retenue dans un service (le worker sert plusieurs
     cabinets) ; les exemples du prompt portent `[MONNAIE]`, remplacé par le code du cabinet.
     L'export Excel lit l'unité devant ou derrière le nombre.
   - **La monnaie d'un cabinet qui n'en a configuré aucune : USD, écrit UNE fois**
     (`ServiceMonnaies::MONNAIE_PAR_DEFAUT`, décision de Serge du 2026-10-26).
     `getCodeMonnaieAffichage()` rend toujours une chaîne ; aucun appelant ne réécrit
     `?? 'USD'` (garde `MonnaieParDefautTest`). Le cabinet connu (SOA, PDF, outils de Ket),
     on lit SA monnaie (`getMonnaieAffichagePourEntreprise`), jamais celle de la session.
   - **Couleurs : la charte, par ses jetons** (skill `charte-couleurs`). `#0d2440` → cobalt
     foncé ; titres de l'infobulle `#adb5bd` ; texte foncé sur l'orange `#e69500`. ⚠ Un gabarit
     rendu SANS app.css (e-mails, export DomPDF des messages de Ket, picker des documents du SOA
     servi aussi par la page publique) écrit la VALEUR (`#003380`), jamais `var(--…)` : le jeton
     n'y vaut rien. Garde `CouleursHorsChartesTest`.
   - **Un défaut de formulaire ne passe JAMAIS par l'option `data`** : elle l'emporte sur la
     valeur de l'objet, et une modification réécrit le champ (l'exercice d'une piste de 2024
     revenait à 2026). Le défaut d'une entité NEUVE se pose dans son constructeur
     (`Piste::__construct`, année de l'horloge de référence) : l'écran, Ket (source `entite`) et
     l'import le lisent au même endroit ; le dictionnaire `.jsbx` le tient pour facultatif.
   - Une date affichée passe par `format_datetime(..., locale: app.user.locale)`, jamais `|date('d/m/Y')`.
   - Le champ « Devise » des dépenses est celui de JOSEARA (Console, `Depense`), pas d'un cabinet
     (les dépenses d'un cabinet sont dans sa monnaie de travail) : **USD fixe, non modifiable**
     (décision de Serge, 2026-10-26 — la comptabilité de Joseara est en dollars, comme ses tokens).
6. ✅ **FAIT (2026-10-21) — État vide.** Le SERVEUR dit pourquoi une liste est vide
   (`App\Service\Liste\CauseDeListeVide`, `ControllerUtilsTrait::etatVideParametres`) et rend
   l'état vide, au premier affichage comme à chaque recherche (`etatVide` de la réponse) :
   « Aucune donnée » ; « Aucune fiche dans votre portefeuille » + « Afficher tout le cabinet »
   (proposé à tous) ; « Aucune fiche ne correspond à vos filtres » + « Réinitialiser » (critères
   par défaut, seulement s'il changerait quelque chose) + « Tout retirer » (ex-« Effacer les
   filtres », 2026-10-29 — vocabulaire : `jsb-construire-rubrique/references/redaction-interface.md`),
   qui ne retire « Mon portefeuille » que pour un administrateur du cabinet
   (`WorkspaceAccessResolver::estAdministrateurDuCabinet` : le propriétaire ou le DÉLÉGUÉ
   « gestionnaire des invités » — décision de Serge ; seul le propriétaire supprime le cabinet
   et achète des tokens). Les boutons portent les clés à retirer
   (`button_2_cles`) ; le navigateur ne décide rien. Test : `ListeVideExpliqueeTest`.
7. ✅ **FAIT (2026-10-26) — Routes et code morts.** `/admin/piste/test`, `/admin/article/test`
   et `/admin/demande_contact` supprimées (gabarit absent : 500 ; la vitrine reste la seule porte
   du contact) ; `RetroAgentController::assertPeutConsulter`, orpheline depuis `e80694c2`,
   supprimée — **décision de Serge : le RÔLE « Rétros intermédiaires » est la frontière**, pas
   « chacun ses rétros ». Gardes : `RoutesMortesTest`, `GabaritsRendusExistentTest` (tout
   gabarit nommé en toutes lettres dans `render`/`renderView`/`htmlTemplate` existe).
   ⚠ L'IDE dit `ArticleController::checkForDuplicates` morte : FAUX, elle est appelée par
   `method_exists` (`ControllerUtilsTrait`) — prouver la mort avant de supprimer.
8. ✅ **FAIT (2026-10-26) — Mascottes.** Dix cas tranchés par Serge un par un : PaiementPrime
   (main tendant une pièce), ChargementPourPrime (argent « + »), Rétros intermédiaires (virement),
   Invités (collaborateur en cravate), Production intermédiaires (podium), les cinq Rôles (une
   mascotte de famille, personne + engrenage), Paramètres congés (calendrier validé), Périodes de
   blocage (calendrier + alerte), Article (facture à lignes) ; exceptions motivées : Classeurs
   (= `action:replier`) et Échange (= `fichier:excel`). Alias nommés comme l'entité en kebab-case.
   Garde `MascottesUniquesTest` (règles dans `jsb-construire-rubrique` §6). ⚠ Un alias d'ENTITÉ ne
   sert jamais à dire une action : un e-mail se dit `action:send-email`, pas `invite`.

Inscrits le 2026-10-17, à la clôture du chantier 1 (constats faits en route, prouvés) :

9. ✅ **FAIT (2026-10-19/20) — CSP APPLIQUÉE avant l'ouverture aux
   clients.**
   - **Commit 1 FAIT** : Report-Only, nonce, collecte `/api/journal/csp` (règles au §4).
   - **Commit 2 FAIT** : plus aucun site extérieur. Quill, Bootstrap 5.3.6 et es-module-shims
     sont vendorisés. Garde-fous : liste blanche exacte de la politique, aucune adresse
     absolue dans `templates/` et `assets/`.
   - **Commit 3 FAIT** : 47 gestes génériques → contrôleur `geste` (sur `<body>`) et
     `assets/gestes-autonomes.js` (3 pages sans Stimulus). Garde-fou `GestesSansCodeTest`.
   - **Commit 4 FAIT** : 63 appels `window.db*` et 3 lignes fabriquées dans `app.js` → contrôleur
     `tableau-de-bord` (liste fermée ; un refus lève, donc vu par la supervision). Plus aucun
     `on…=` nulle part (`GestesSansCodeTest`).
   - **Commit 5 FAIT** : garde-fous déjà posés aux commits 1 à 4 ; règles inscrites ici (§4) et dans
     `jsb-construire-rubrique` §3.
   - **Commit 6 FAIT (2026-10-20)** : liste de contrôle parcourue par Serge en production (supervision
     propre), puis bascule `PolitiqueCsp::APPLIQUEE = true` ; cinq E2E rejoués SOUS la politique
     appliquée (blocage réel prouvé). Secours : repasser `APPLIQUEE` à false, la collecte continue.
   - **CIBLE** : retirer `style-src 'unsafe-inline'` quand les ~1 600 `style=` auront migré.
   - **Points d'attention depuis la bascule** :
     - `form-action 'self'` bloquera la redirection vers un prestataire de paiement réel
       (`TokenController.php:169`) : déclarer son hôte ;
     - un `on…=` n'est signalé qu'au clic, il faut donc le cliquer pour le voir ;
     - sur un navigateur sans importmap (Safari < 16.4), le polyfill es-module-shims fabrique
       des modules `blob:`, que `script-src` refuse. Ces navigateurs ne chargeront plus
       l'application : à accepter ou à traiter avant la bascule.
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
    gras partiel — casse le mot). **Écart traité le 2026-10-20** : un critère inconnu de
    l'entité (premier segment ni champ ni relation) est écarté, comme un « Tous », et
    JOURNALISÉ en avertissement. Vu en production : le chip d'échéance des Avenants
    (`__echeance_avenant__`) dans une requête Tranche → 500. Test
    `RechercheTexteRicheTest::testUnCritereInconnuDeLaRubriqueEstIgnore`. La fuite du chip
    d'un onglet à l'autre côté navigateur n'est pas encore reproduite.
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

Inscrit le 2026-10-19 (revue du chantier 9, décision de Serge) :

13. ✅ **FAIT (2026-10-20) — un fichier déposé ne s'exécute jamais sur joseara.com.**
    - `public/uploads/documents/.htaccess` : `Require all denied` — aucun accès par URL ; toutes les
      pièces passent par les contrôleurs (droits, cabinet, pièce jointe). Versionné malgré
      l'ignore du dossier (exception `.gitignore`). L'aperçu des PDF n'était pas concerné.
    - `public/images/entreprises/.htaccess` (logos) : types ACTIFS refusés (svg, html, xml, js…)
      et `Header always set Content-Security-Policy "default-src 'none'; … sandbox"` + nosniff.
      **Pas** « tout refuser puis accorder les images » : la levée d'un refus par `<FilesMatch>`
      n'est pas éprouvée sous LiteSpeed, et un échec couperait tous les logos et le favicon.
    - `Entreprise::$thumbnailFile` : `Assert\Image` limité à PNG, JPEG, GIF, WebP (le défaut
      acceptait le SVG, qui porte du script) ; message traduit `entreprise.logo.type`.
    - Test `TeleversementsInertesTest` ; effet réel vérifié sous Apache. **Reste** : vérifier sous
      LiteSpeed après déploiement (`curl -I` du favicon : 200 + sandbox ; d'un document : 403).
    - ⚠ La voie cPanel « Deploy HEAD Commit » (`.cpanel.yml`) exclut ces dossiers et ne livre pas
      ces `.htaccess` : publier par `publier` (SSH, `deploy.sh`).

Inscrits le 2026-10-20 (décisions de Serge) :

14. ✅ **FAIT (2026-10-20) — icônes figées dans le dépôt.** 31 icônes nommées dans le CODE (canevas,
    `IconExtension`, `IconController`) manquaient sur le disque : téléchargées d'Iconify au rendu,
    elles faisaient tomber la page en 500 dès qu'Iconify ne répondait pas. Importées
    (`ux:icons:import`), garde-fou `IconesEcritesDansLeCodeVendoriseesTest` (src/ + assets/ ;
    `IconInvocationsVendoriseesTest` couvre les gabarits). **Règle** : une icône nouvelle s'importe
    et se versionne dans `assets/icons/` ; le test donne la commande exacte.
15. ✅ **FAIT (2026-10-21, `d6dedf8e`) — Restauration des onglets à l'identique** (règle de Serge : « après plantage, au moment de
    la restauration, tout doit être restauré à l'identique »). Chaque onglet restauré retrouve SES
    critères, chips, page et tri. Constat : `ui:search.submitted` ne porte que `{criteria}`, et le
    cerveau l'applique à l'onglet ACTIF (`_handleSearchRequest` → `_getActiveTabState()`) ; le chip
    d'échéance des Avenants a été vu dans une requête Tranche (500 en production, corrigé côté
    serveur, fuite non encore reproduite au navigateur).
16. ✅ **FAIT (2026-10-21) — champs de recherche protégés du remplissage automatique.** Chrome/Edge
    collaient l'e-mail de l'utilisateur dans les filtres du tableau de bord. 29 champs (28 gabarits,
    1 JS). **Règle** : tout champ de recherche/filtre passe par `{{ attributs_champ_recherche(nom?) }}`
    (JS : `attributsChampRecherche()`), et `ChampsDeRechercheProtegesTest` le refuse sinon.
    - avec un nom → `form="jsb-hors-formulaire"` : jamais envoyé avec un formulaire (un champ en
      trop ferait refuser un dialogue) ;
    - sans nom → protections seules : champ d'un `<form method="get">` (doit rester envoyé — test)
      ou champ lu en direct dans un <form> (le détacher change l'envoi implicite par Entrée) ;
    - filet `assets/champs-de-recherche.js` : vide UNIQUEMENT ce que le navigateur a rempli
      (`:autofill`), jamais une valeur posée par l'application (contrainte du chantier 15).

Inscrits le 2026-10-22 (décisions de Serge) :

17. ✅ **FAIT (2026-10-22, `38fec8ca`) — Tâches : frontière de visibilité.** Règles au §2.
18. ✅ **FAIT (2026-10-22) — Fichiers déposés HORS de `public/`.** `040f7293` (code mort
    `public/pdfs`), `67fe84a3` (emplacement à source unique), `3311e0c0` (réponse durcie),
    `fd0d2aa9` (pièces dans `var/uploads/documents`, logos dans `public/uploads/logos`, noms
    aléatoires, `app:documents:deplacer` au déploiement sous maintenance), `3fd1d382` (case
    « Partagée avec le client », décochée par défaut : le relevé public `/soa/{token}` ne liste et
    ne sert QUE les pièces partagées ; le courtier voit tout). Production : application dans
    `/home/josearac/joseara` (compte `josearac`) ; `public/pdfs` n'y a jamais existé.
19. ✅ **FAIT (2026-10-22) — Supprimer un cabinet efface TOUT ce qui est à lui.**
    `ServiceSuppressionEntreprise` : sur le disque, les 7 emplacements (logo, pièces, fichiers
    joints à Ket, documents produits par Ket, dépôts et exports de l'échange `<id>/`, cache de
    la voix `<id>/`) — chacun déclaré UNE fois en paramètre (`app.dossier_*`) que lisent Vich,
    le code qui écrit et la purge ; en base, les tables rattachées INDIRECTEMENT (par une
    relation vers une table du cabinet, de proche en proche — `cartographierDependantes`,
    découvertes dans le modèle, jamais listées), effacées avant leurs parents. `utilisateur`
    est seulement détaché. **Règle** : un nouvel emplacement de fichiers par cabinet s'y
    ajoute ; `SuppressionDUnCabinetEffaceSesFichiersTest` (bout en bout) le prouve.
    **19 bis (2026-10-23)** : les TEMPORAIRES — copie d'import de Ket rangée dans le dépôt du
    cabinet, copie de pièce jointe et rapport de Ket dans `var/tmp/cabinets/<id>/` (8ᵉ
    emplacement), export envoyé puis effacé même coupé, purge par l'âge des exports et des
    fichiers de passage. Règles au §4 (« fichier de passage »).
20. ✅ **FAIT (2026-10-26) — Un bouton désactivé de la barre d'outils dit pourquoi.**
    `toolbar_controller.js::setButtonDisabled` posait `disabled` (ni survol ni focus, sauté par
    les flèches de la barre) et `.is-disabled` coupait les gestes du pointeur : la raison ne
    s'affichait jamais ; réactivé, le bouton perdait même son `title` — son libellé. Désormais,
    comme `barre-actions.js` : `aria-disabled`, libellé mémorisé (`data-libelle`) et raison
    ajoutée (« Ajouter — … »), geste refusé par `toolbar#notify` et `context-menu#notify`, bouton
    survolable. Règle : **jamais `disabled` sur un bouton qui doit expliquer son refus.** Garde-fou
    `tests/js/barre-outils-desactivee.test.mjs` ; E2E souris + clavier (Conditions de partage).
    ⚠ **« Ajouter » fait exception depuis le 2026-10-30** : là où la création n'a pas de sens, il
    est ABSENT, pas grisé (`ajout-regles.js`, §3 « un enfant ne naît pas orphelin »).
21. ✅ **FAIT (2026-10-26) — Plus de redirection ouverte.** `/translate/{locale}/{currentURL}`
    redirigeait vers toute adresse reçue (trois variantes prouvées : absolue, `//` sans schéma,
    encodée), après avoir écrit la langue de l'utilisateur connecté. La route ne servait plus
    (son seul gabarit n'était inclus nulle part ; la langue se choisit par `?lang=`) : supprimée,
    avec ce gabarit. Garde-fou : `tests/Security/RedirectionOuverteTest.php` (aucune réponse vers
    un autre hôte, langue intacte). Revue : aucune autre redirection ouverte — toute redirection
    se fait vers une route nommée, une URL du serveur, ou un chemin filtré (`/console/supervision`).
22. **À DÉCIDER — Convergence de la disposition des listes (dialogues et Échange).** La refonte
    de la colonne 3 (2026-10-27 → 10-31 : rail d'outils, rail des chips, ligne des filtres
    actifs, barre du bas pleine largeur, titre et onglets sur une rangée) ne vaut que pour les
    listes du WORKSPACE. État actuel, écart assumé : les collections EN DIALOGUE gardent leur
    barre horizontale, leurs chips en rangée et leur total au pied (place étroite,
    `CollectionRangeeDialogueTest`) ; l'Échange garde ses propres chips et barres
    (`echange_controller.js`). Rien à coder avant une décision de Serge sur ce qui doit converger.
