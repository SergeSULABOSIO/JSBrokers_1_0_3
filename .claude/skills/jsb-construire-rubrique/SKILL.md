---
name: jsb-construire-rubrique
description: Construire ou refondre une rubrique de JSBrokers (Joseara), du cadrage à la livraison — fiche de rubrique, parcours en phases, anatomie d'une rubrique (étalon Piste), icône mascotte, composants, états, textes. Invoquer pour une nouvelle rubrique, un nouvel écran, une nouvelle fonctionnalité, une nouvelle entité, une refonte d'écran, ou l'ajout d'une action, d'une liste, d'un dialogue, d'un composant, d'une icône ou d'un outil Ket.
---

# Skill — Construire une rubrique

Cette skill dit **dans quel ordre travailler** et où trouver la règle au bon moment. Elle ne
répète pas les autres skills, elle y renvoie :

- `jsbrokers-dev` : comment travailler dans le dépôt (BIC Cristal, cloisonnement, parité,
  échappement, tests, livraison, mode Plan) ;
- `courtage-assurance` et `reglementation-rdc-arca` : le métier ;
- `ergonomie-web` et `charte-couleurs` (skills de Serge) : ergonomie, accessibilité, couleurs.

Chaque règle ci-dessous porte sa preuve (fichier:ligne, test, commit). Ce qui n'en a pas est
marqué **À CONFIRMER (Serge)**. Une règle décidée mais pas encore codée est marquée **CIBLE**.

## 1. La fiche de rubrique — avant tout code

Elle est remplie dans le plan. **Une case vide = plan pas prêt.**

| Case | Ce qu'on y écrit |
|---|---|
| Question | La question UNIQUE à laquelle répond l'écran |
| Icône mascotte | Alias retenu, fichier source local, raison, deux alternatives classées (§6). À valider par Serge |
| Utilisateurs et droits | Qui lit, qui écrit ; module de droits (`WorkspaceAccessResolver.php:76` pour Piste) |
| Cycle métier | Étape du cycle et invariants cités (`courtage-assurance` §2, §4, §5), acquis ou CIBLE |
| Réglementation | Règle éventuelle (`reglementation-rdc-arca`) : configuration du cabinet, jamais en dur |
| Entités | Entités touchées ; source unique de chaque règle et de chaque montant |
| Actions | Libellé, droit, garde serveur — pour chacune |
| États | La matrice de `references/etats-interface.md`, remplie |
| Ket | Outils, entrées du manifeste `CouvertureDesEcrans` (couverte ou écran seulement, motif écrit) |
| Tests | TDD ; parité écran ↔ Ket ; cloisonnement à deux cabinets ; nombre de requêtes |
| Recette | Largeurs (1 366 px panneau ouvert et fermé, 768 px), poste lent, clavier seul |
| Spécificités et écarts | Ce qui sort du cadre, et pourquoi (§5) |

## 2. Le parcours en phases

On ne passe pas à la phase suivante sans la checklist de sortie de la précédente.

**0. Cadrage.** Sortie : fiche remplie ; invariants cités ; mascotte choisie dans le dépôt
local et proposée (§6) ; questions métier posées à Serge **avant** de coder.

**1. Modèle et règles.** Entités, migration (jouée aussi en DEV : des tests verts ne prouvent
rien sur la base dev), règles en source unique, tout identifiant venu de la requête par
`AppartenanceAuCabinet` (`exigerLeCabinetOuvert`, :88). Sortie : schéma validé, migration
jouée en test ET en dev, règle écrite une seule fois.

**2. Serveur.** Canevas d'entité, de liste, de formulaire ; stratégie d'indicateurs ;
contrôleur sur `ControllerUtilsTrait` ; gardes serveur ; valeurs de naissance et services
`Defauts*`. Sortie : l'ordre de `handleFormSubmission` (§3) respecté, sans contournement ;
chaque action a sa garde serveur ; chaque `EntityType` filtré par cabinet.

**3. Interface.** Liste, fiche ou dialogue, actions, états, textes — composants réutilisés
(`references/composants.md`) ; le navigateur clone des `<template>`, il ne fabrique ni libellé
ni chiffre ; la mascotte validée est appliquée depuis sa source. Sortie : matrice des états
couverte ; textes relus (`references/redaction-interface.md`) ; aucun contenu dynamique non
échappé.

**4. Ket.** Outils, refus lisibles (`bloquant`, jamais `note`), manifeste de parité. Sortie :
`PariteEcranKetTest` vert ; toute action d'écran couverte ou exclue avec motif.

**5. Tests.** Rouges d'abord, sur le code actuel ; dates calculées. Sortie : parité, cloisonnement
à deux cabinets (`SemisDeDeuxCabinetsTrait`), N+1 compté (modèle
`PrechargementPisteTest::testLeCoutDUnePageNeDependPlusDuNombreDePistes`), node pour le JS.

**6. Recette et livraison.** Procédure de `jsbrokers-dev` §5-§6 ; commits séparés et verts ;
captures aux largeurs de référence, la mascotte y est visible.

**7. Mise à jour des skills.** Si le chantier change une règle, une pièce ou un composant cité
ici ou dans une skill sœur, la skill est corrigée **dans le même chantier**.

## 3. Anatomie d'une rubrique — étalon Piste

| Pièce | Rôle | Fichier de l'étalon | Test qui la garde |
|---|---|---|---|
| Entité | Données, constantes du métier | `src/Entity/Piste.php` (`AVENANT_*` :21-26, `avenantDeBase` :136) | — |
| Repository | Requêtes propres | `src/Repository/PisteRepository.php` | — |
| Canevas d'entité | **Mascotte** (`parametres.icone`), champs, indicateurs | `PisteEntityCanvasProvider.php:35-44` (icône :37), liste :45-67, indicateurs :70+ | `IconAliasVendorisationTest` |
| Canevas de liste | Colonne principale, badges, colonnes numériques, `vue_cartes`, chips | `PisteListCanvasProvider.php` : badges :34-39, colonnes :54-79, `vue_cartes` :95-122, chips :123-133 | `PisteVueCartesTest`, `CoherenceChipsAssistantTest` |
| Canevas de formulaire | `attribute_actions` (avec `droit`), `form_intro`, `field_icons`, onglets de collection, `visibility_conditions` | `PisteFormCanvasProvider.php` : actions :48-68, intro :83, icônes :88, collections :193-213 | `PariteEcranKetTest` |
| Stratégie d'indicateurs | Valeurs calculées par ligne | `PisteIndicatorStrategy.php:12`, tag `app.indicator_calculation_strategy` (`config/services.yaml:69-70`) | `PrechargementPisteTest` |
| Contrôleur + trait | Routes ; socle commun | `PisteController.php` : `index` :71, `getFormApi` :77, `submitApi` :182, `actionPipeApi` :256, `deleteApi` :372 ; `ControllerUtilsTrait` | `PisteVueCartesTest::testLeServeurDecideDeLaFicheAOuvrir` |
| Gabarits | Rendu serveur, `<template>` à cloner | `templates/components/vue_cartes/_carte_piste.html.twig`, `_source.html.twig`, `_tableau.html.twig` | `PisteVueCartesTest` |
| Contrôleurs JS | Clonent et posent des données | `vue-cartes_controller.js`, décisions pures dans `vue-cartes-regles.js` | `tests/js/vue-cartes-regles.test.mjs` |
| Alias d'icône | Nom sémantique → icône vendorisée | `IconCanvasProvider.php:10-171` (`piste` → `material-symbols:footprint`) | `IconAliasVendorisationTest`, `IconInvocationsVendoriseesTest` |
| Menu | Entrée de la rubrique | `config/packages/menu.yaml:65` | — |
| Événements du Cerveau | Bus unique entre composants | `cerveau_controller.js` : `app:loading.start/stop` :227, :325 ; `app:list.rendered` :491-513 ; `app:fiche.modifiee` :1082 | `tests/js/evenements-diffuses.test.mjs` |
| « Mon portefeuille » | Confort de lecture, pas une frontière | `PortefeuilleScope.php:121`, `ResponsableDePiste.php:19` | `ResponsableDePistePariteTest`, `PortefeuilleDescendantsDePisteTest` |
| Source unique du métier | Étapes du pipe | `PisteTransformationScope.php` : seuil :55, `ETAPES` :85, `etapeDe` :122 | `JSBDynamicSearchServicePisteEtapesTest`, `AgregatPistesParEtapeTest` |
| Outils Ket | Même règle, autre surface | `SaisirPropositionTool`, `SouscrireCotationTool`, `PreparerOperationsTool` ; manifeste `CouvertureDesEcrans.php:111-117` | `PariteEcranKetTest`, `KetPeripherePariteTest` |
| Cloisonnement | Tout id venu de la requête | `AppartenanceAuCabinet.php:64`, :88, :132 | `CloisonnementDesIdentifiantsTest` + `SemisDeDeuxCabinetsTrait` |
| Gestes sans code dans le HTML | Politique de sécurité du navigateur : aucun `on…=`, aucun `<script>` sans nonce | `geste_controller.js` (imprimer, fermer, arreter, soumettre, confirmer, naviguer, rechargerOnglet) ; `tableau-de-bord_controller.js` (liste fermée) ; `assets/gestes-autonomes.js` (pages sans Stimulus) | `GestesSansCodeTest`, `PolitiqueCspTest` |

**Gestes et scripts d'un écran neuf** (chantier 9, politique dans `App\Security\Csp\PolitiqueCsp`) :

- **Jamais** de `onclick=`, `onchange=`, `oninput=`… : le navigateur les refusera. Un geste générique
  s'écrit `data-action="click->geste#imprimer"` (le contrôleur `geste` est sur `<body>`) ; un
  comportement propre à l'écran va dans **son** contrôleur Stimulus (`data-action="click->ma-rubrique#faire"`).
  Retenir un clic : option `:stop` ; empêcher le comportement natif : `:prevent`.
- **Jamais** de `<script>` inline dans un fragment (onglet, panneau, dialogue) : il ne s'exécuterait
  pas, et la politique le refuserait. Dans une page complète, `<script nonce="{{ csp_nonce() }}">`.
- **Jamais** de ressource d'un autre site (CDN, police distante) : `importmap:require`, puis `asset()`.
  Une feuille de style se pose en `<link>`, **jamais** par un `import '….css'` dans le JS.
- Une page autonome sans `importmap()` (aperçu imprimable) : ses boutons « Imprimer » et « Fermer »
  gardent le même balisage et chargent `assets/gestes-autonomes.js`.
- Les tests de rendu qui lisent une balise par expression régulière sautent les valeurs entre
  guillemets (`(?:[^>"]|"[^"]*")*`) : `click->x#y` contient un « > ».

**Clavier dans un dialogue** (chantier 2, WCAG 2.1.2 et 2.4.3) :

- **Aucun piège clavier.** Tab et Maj+Tab sortent TOUJOURS d'un champ vers le suivant ou le
  précédent — éditeur riche et listes compris. Quill est créé sans ses raccourcis Tab
  (`SANS_TABULATION`, `texte-riche-regles.js`) ; une indentation de liste ne passerait que par
  un bouton de la barre d'outils (il n'y en a pas aujourd'hui). Garde-fou :
  `tests/js/texte-riche-tabulation.test.mjs`, qui relit la copie de Quill du dépôt.
- Échap ferme d'abord ce qui est ouvert, puis le dialogue du dessus, avec « Abandonner ? » si
  la saisie n'est pas enregistrée ; le focus revient à l'ouvreur (`jsbrokers-dev` §4).
- Un composant qui modifie une valeur par script émet `input`/`change` par
  `evenementInterne()`, jamais `new Event(...)`.

**L'ordre réel de `handleFormSubmission`** (`ControllerUtilsTrait.php:1712-2002`) — un
contrôleur ne le contourne pas, il passe ses ajouts par `beforePersist` :

1. entité trouvée, sinon 404 (:1723-1727) ;
2. en édition, cabinet ouvert exigé (:1735-1737) ;
3. droit d'écriture (création) ou de modification (édition) (:1757-1760) ;
4. en création, cabinet et invité posés, parents soumis pré-hydratés (:1762-1776) ;
5. note intangible → 409 (:1780) ;
6. empreinte de fiche périmée → 409 qui nomme les champs (:1795-1814) ;
7. `submit`, et une TypeError devient un 422 de champ (:1831-1844) ;
8. **valeurs de naissance AVANT le contrôle** (:1871-1873), puis champs obligatoires (:1874) ;
9. doublons (:1882), `beforePersist` (:1893), parents (:1902) ;
10. `dry_run` : validation seule, la ligne rendue par le même gabarit (:1922-1939) ;
11. `commitWrite` (tokens, 402) (:1946) ;
12. valeurs calculées et entité en JSON (:1963-1969) — sinon 422 avec `labels` lisibles (:1993-2001).

**Meilleurs étalons pour certaines pièces :**

- **Cotation** pour les collections et onglets : 6 onglets, et `actions_de_ligne`
  « Souscrire » (`CotationFormCanvasProvider`) — Piste n'en a que 2.
- **DemandeConge** et **ReversementRetroAgent** pour `facts_labels`
  (`DemandeCongeFormCanvasProvider.php:56`, `ReversementRetroAgentFormCanvasProvider.php:125`) —
  Piste n'en a pas.
- **Note** pour le refus 409 qui explique (`NoteIntangible`).
- ⚠ Piste n'est pas un modèle partout : `getFormApi` compare l'entreprise à la main
  (`PisteController.php:94-101`) au lieu d'appeler `AppartenanceAuCabinet` ; `date('Y')` en
  dur (:108, :177). Chantiers inscrits dans `jsbrokers-dev`.

## 4. Table de routage

| Phase | Si tu touches… | Lis |
|---|---|---|
| 0 | une étape du cycle, un montant, une taxe | `courtage-assurance`, `reglementation-rdc-arca` |
| 0 | l'icône de la rubrique | §6 ci-dessous |
| 1-2 | un identifiant venu de la requête, un sélecteur | `jsbrokers-dev` §2 |
| 2 | un formulaire, une valeur par défaut | §3 (ordre de `handleFormSubmission`) ; `formulaires.md` (lot 2, pas encore écrit) |
| 2-3 | un indicateur, un total, un compteur | `references/redaction-interface.md` (honnêteté des chiffres) ; `donnees-indicateurs.md` (lot 2) |
| 3 | une liste, des chips, une barre, un dialogue, un toast, une infobulle | `references/composants.md` |
| 3 | un chargement, un vide, une erreur, un filtre, un F5 | `references/etats-interface.md` |
| 3 | un libellé, un message, un format de nombre ou de date | `references/redaction-interface.md` |
| 3 | une couleur, un jeton | `charte-couleurs` ; `systeme-design.md` (lot 2) |
| 3 | le clavier, le focus, ARIA, la taille d'une cible | `ergonomie-web` ; `accessibilite.md` (lot 2) |
| 3 | un écran étroit, le tactile | `responsive-tactile.md` (lot 2) |
| 4 | une action d'écran | `jsbrokers-dev` §3 ; `CouvertureDesEcrans` |
| 5-6 | les tests, la recette, le commit | `jsbrokers-dev` §5-§6 ; `recette-visuelle.md` (lot 2) |

Un fichier du lot 2 qui n'existe pas encore ne s'invente pas : on applique la skill sœur citée
et on signale le manque.

## 5. Quand la rubrique sort du cadre

**Échelle de décision**, justifiée échelon par échelon dans le plan :

1. réutiliser un composant tel quel ;
2. le configurer (option ou variante existante) ;
3. l'étendre, sans changement pour ses usages actuels (tests de non-régression) ;
4. créer un composant nouveau.

**Un composant nouveau :**

- a les exigences des composants partagés : états, accessibilité, textes, rendu cloné,
  Cerveau, échappement, tests ;
- s'active par une **clé déclarative du canevas**, jamais par une condition sur le nom d'une
  rubrique. Modèle : `vue_cartes` (`PisteListCanvasProvider.php:95`), spécifique aux Pistes
  mais réutilisable par simple déclaration ; test `PisteVueCartesTest::testUneAutreRubriqueNAPasDeVueCartes` ;
- reste **local** tant qu'une seule rubrique l'utilise, puis est généralisé et versé au
  catalogue partagé au **deuxième usage** ;
- est documenté dans `references/composants.md` dans le même chantier, avec son statut ;
- son CSS va dans `app.css` sous une classe générique `.jsb-*`, pas dans un `<style>` du gabarit.

**Règle métier propre à la rubrique** : source unique, parité Ket, inscription dans
`courtage-assurance` (acquise ou CIBLE).

**Contradiction avec une skill** : arrêt et question à Serge. Soit la skill est corrigée dans le
même chantier, soit la conception change. Aucune dérogation silencieuse.

## 6. L'icône mascotte

**Règle.** Chaque rubrique a sa mascotte, unique, qui la représente partout : menu, onglet,
en-tête de liste, ligne, dialogue, état vide.

**Source.** Uniquement le dépôt local : les alias de
`src/Services/Canvas/Provider/Icon/IconCanvasProvider.php` (:10-171) et les icônes vendorisées
de `assets/icons/<set>/`. Jamais de recherche ni d'importation depuis un dépôt distant pendant
la construction.

**Choix (phase 0).** Parcourir les alias d'entité (avant `fichier:*` et `action:*`) et les SVG
locaux ; retenir le dessin le plus juste pour le sens métier (glossaire de `courtage-assurance`).

**Unicité.** L'icône retenue n'est la mascotte d'aucune autre rubrique, ni un alias `action:*`
ou `fichier:*`, ni une icône d'étape ou de statut. Comparer aussi le SVG **résolu** : deux alias
peuvent pointer vers le même dessin (`invite` et `action:send-email` → `hugeicons:mail-send-01`).

**Proposition dans le plan, pour validation.** Alias retenu, fichier source, raison, et deux
alternatives classées, chacune avec sa raison. Rien n'est appliqué avant la réponse de Serge.

**Aucune icône locale ne convient.** Proposer la meilleure disponible, dire pourquoi elle est
imparfaite, demander à Serge s'il veut une importation. Une importation est une exception
validée : `php bin/console ux:icons:lock` (jamais à la main), puis les tests d'icônes verts.

**Source unique — CIBLE.** La mascotte se déclare une fois, dans le canevas d'entité
(`parametres.icone`), et tout le reste la lit : ligne `_list_row.html.twig:81`, onglet
`_view_manager.html.twig:27`, :70, dialogue `_form_content.html.twig:19`, :118, :196, attributs
`_calculated_attributes.html.twig:33`, :53, état vide `_list_manager.html.twig:407-424`.
**Écart actuel** : le menu la déclare une seconde fois (`menu.yaml`, clé `icone`, lue par
`_interactive_menu.html.twig:601-604`). Les deux concordent au 2026-10-16 pour toutes les
rubriques. Les rubriques « outil » sans canevas (DocumentComptable, Echange, AssistantIa,
AssistantParametres) n'ont que le menu. D'ici l'unification : **les deux déclarations sont
identiques**, et aucune icône n'est écrite en dur dans un gabarit.

**Anti-modèle.** `texte_principal.icone = mdi:road-variant` sur la liste des Pistes : lue par
personne, retirée (`9520f6f9` ; `PisteListCanvasProvider.php:26-28`).

**Tests.** `IconAliasVendorisationTest` et `IconInvocationsVendoriseesTest` restent verts.
**Test prescrit, pas encore écrit** (décision de Serge, 2026-10-16) : au premier chantier de
rubrique, un test vérifie (a) qu'aucune mascotte n'est partagée hors exceptions motivées,
(b) qu'aucune mascotte n'est un alias `action:*`, (c) que `menu.yaml` et le canevas concordent.
Les partages existants sont tranchés cas par cas par Serge à ce moment-là (chantier 8 de
`jsbrokers-dev`).
