# États de l'interface

Un écran n'est pas fini tant que chacun de ses états n'a pas un rendu **explicite**. La matrice
ci-dessous est remplie dans le plan (fiche de rubrique, case « États »), écran par écran.

## 1. Règles

1. **Chaque état a un rendu explicite** : jamais un écran blanc, un chiffre faux ou un silence.
2. **Un chargement s'éteint à la fin de CHAQUE chargement** (`app:loading.stop`), pas seulement
   à l'arrivée de nouvelles données.
3. **Aucun flash, aucun « 0 sur N » transitoire** : une valeur inconnue s'affiche « en
   chargement », jamais comme zéro (modèle : la pastille de compte posée « en chargement »,
   `compte-onglet.js:51`, puis le vrai compte, :57).
4. **Rien ne bouge sans action de l'utilisateur**, et rien du tout sous `prefers-reduced-motion`
   (`app.css:623`, :671, :3614).
5. **Un état restauré se DIT à l'écran** : celui qui reprend le poste doit voir qu'un filtre
   est actif.
6. **Tout chip survit au F5** (règle de Serge) — un chip est un état.
7. **Un refus nomme sa cause et offre un geste réellement possible** (voir
   `redaction-interface.md`).
8. **Échap ferme un dialogue ; s'il y a une saisie non enregistrée, une confirmation est
   demandée au lieu de fermer** (arbitrage de Serge, 2026-10-16). **CIBLE** : aujourd'hui le
   dialogue porte `data-bs-keyboard="false"` (`dialog-manager_controller.js:30`) et Échap ne le
   ferme jamais — chantier 2 de `jsbrokers-dev`.

## 2. Où regarder dans le code — la matrice

| État | Rendu attendu | Mécanisme existant |
|---|---|---|
| Chargement initial | Squelette ou zone `aria-busy` | `.workspace-skeleton` (`app.css:2461`) ; `app:loading.start` (`cerveau_controller.js:227`) |
| Rafraîchissement | Zone grisée `aria-busy`, contenu conservé | `vue-cartes_controller.js:250` (pose), :47 et :170 (retrait) ; `app:loading.stop` (`cerveau_controller.js:325`) |
| Vide — aucune donnée | Message + « Ajouter » si le droit le permet | `_empty_state.html.twig` ; `_list_manager.html.twig:400-430` |
| Vide — tout est filtré | Message qui nomme le filtre + « Réinitialiser » | ⚠ Aujourd'hui confondu avec le cas précédent (`_list_manager.html.twig:420-425`, « Aucun élément trouvé ») — chantier 6 |
| Erreur serveur ou réseau | Toast d'erreur, contenu précédent conservé | `app:error.api` → toast générique (`cerveau_controller.js:154-155`) ; 422 de validation (:351) |
| 404 | Fiche absente ou d'un autre cabinet : 404, jamais un refus nommé | `AppartenanceAuCabinet::exigerLeCabinetOuvert` (:88-91) ; rechargement d'une fiche disparue, `dialog-instance_controller.js:236-238` |
| 409 — donnée périmée | « Cette fiche a été modifiée depuis son ouverture (champs). Rien n'a été enregistré : rechargez-la » | `EmpreinteDeFiche`, `ControllerUtilsTrait.php:1795-1814` ; client `dialog-instance_controller.js:584-587` ; `app:fiche.modifiee` (`cerveau_controller.js:1082`) |
| 409 — règle métier | Le refus explique et propose le geste possible | `NoteIntangible` (`ControllerUtilsTrait.php:1780`) ; suppression refusée (mémoire « Suppression refusée = 409 qui explique ») |
| Sans droit | Lecture seule ; action ABSENTE (pas grisée sans explication) | `accessDeniedJson` (`ControllerUtilsTrait.php:277`, :1759) ; `_access_denied.html.twig` ; `droit` sur chaque `attribute_action` ; test `PisteVueCartesTest::testSansDroitDEcritureAucuneActionNEstProposee` |
| Solde de jetons épuisé | 402, rien n'est enregistré, la saisie reste possible | `tokensBlockedJson` (`ControllerUtilsTrait.php:536`) ; `dry_run` non facturé (:1922) |
| Plafonné | Le chiffre dit qu'il est plafonné (« + de USD 900 000 ») | `plafond_transformees` (`PisteListCanvasProvider.php:100`) ; infobulle `_tableau.html.twig:28-29` ; commit `93f949d6` |
| Élément masqué par un filtre | Après enregistrement : avertissement qui nomme le filtre + « Retirer ce filtre » | `verifierLaFicheEnregistree` (`ControllerUtilsTrait.php:1043`) ; `fiche-hors-filtre.js:45` ; `cerveau_controller.js:1256` ; test `fiche-hors-filtre.test.mjs` |
| Après enregistrement | Toast de succès ; aucun commentaire si rien n'a été enregistré | `messageDEtape` (`vue-cartes-regles.js:85-96`) : fiche fermée sans enregistrement → aucun message |
| Après F5 | Onglet, vue, chips, critères et sélection restaurés | cookie de vue `preparerLaVueCartes` (`ControllerUtilsTrait.php:1267`) ; `criteres-persistes.js` ; `choix-persiste.js` ; `echange-perimetre-persiste.js` ; tests `selection-persistee.test.mjs`, `criteres-persistes.test.mjs`, `premier-rendu.test.mjs` |

Un état sans mécanisme existant s'écrit « aucun mécanisme : à concevoir » dans le plan, avec
l'échelon de `SKILL.md` §5.

## 3. Anti-modèles vécus dans ce projet

- **Cartes restées grisées** : `aria-busy` retiré seulement à l'arrivée de nouvelles cartes ;
  le pipe restait gris après une bascule Liste → Cartes. Il s'éteint désormais avec
  `app:loading.stop` (`300ed88a` ; `vue-cartes_controller.js:47-48`).
- **Piste créée invisible sous « Mon portefeuille »** : le toast disait « réussi », la liste
  rafraîchie (sous le filtre) ne la montrait pas. Le serveur dit désormais quel filtre l'écarte
  (`300ed88a`). Réflexe : lire le SQL du journal (`var/log/dev.log`) avant de soupçonner le JS.
- **Carte décochée après F5** : la sélection reposée par programme ne déclenche pas `change` ;
  la carte se recale sur `app:context.changed` (`300ed88a`). Même famille : la sélection ne
  revenait pas après F5 sur la liste (`62d7ebde`).
- **Toast de reproche après une simple annulation** : une fiche fermée sans rien enregistrer ne
  commente rien (`vue-cartes-regles.js:78-79`, :86-88). Commit d'origine : **À CONFIRMER
  (Serge)** — la règle est née avec la vue Cartes (`2efcce3c`).
- **Chips vidés en silence dans tous les cabinets** par un `NOT IN` qui recevait un NULL
  (`62d7ebde`) : un état « vide » faux est pire qu'une erreur.
- **Onglet contextuel vide jusqu'à « Réinitialiser »** : il appliquait les filtres par défaut
  de la rubrique à la fiche ouverte (`300ed88a`).

## 4. Checklist

- [ ] La matrice est remplie dans le plan pour chaque écran touché.
- [ ] « Aucune donnée » et « tout est filtré » ont deux rendus distincts.
- [ ] `aria-busy` posé au début et retiré à `app:loading.stop`, y compris en cas d'erreur.
- [ ] Aucune valeur affichée à zéro pendant un chargement.
- [ ] Un refus serveur (403, 404, 409, 422, 402) a un rendu lisible côté écran ET côté Ket.
- [ ] Une fiche enregistrée mais masquée par un filtre est annoncée, avec le geste qui la montre.
- [ ] Chips, vue et sélection survivent au F5, et l'état restauré se voit.
- [ ] Échap : comportement conforme à la règle 8 (ou écart signalé tant que le chantier 2 est ouvert).
- [ ] `prefers-reduced-motion` : aucune animation.
