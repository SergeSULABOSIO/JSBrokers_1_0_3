# Rédaction de l'interface — textes et localisation

Les utilisateurs de Joseara sont des courtiers, pour la plupart sans culture informatique
(Serge, 2026-09-10). Un texte d'écran parle leur métier, nomme la cellule en cause et dit quoi
faire.

## 1. Règles

### Vocabulaire
1. **Le mot du métier, celui du glossaire** de `courtage-assurance` §1 — jamais un nom de
   propriété, un terme de base de données ou une formule de validateur.
2. **Un même mot partout** : chip, colonne, badge, sous-ligne, carte, toast et Ket. Le libellé
   vient d'UNE source, que tous lisent. Modèle : les étapes du pipe
   (`PisteTransformationScope::ETAPES`, :85, `singulier`/`pluriel` ; `libelle()`, :151), lues par
   le chip, le badge et la colonne de la vue Cartes (`PisteListCanvasProvider.php:30-33`, :86-87).
3. **Les libellés de champ** viennent de `ChampsObligatoiresInspector::libelleChamp` : c'est ce
   que renvoie le 422 (`ControllerUtilsTrait.php:1983-1991`) et ce que voit l'écran.

### Actions
4. **Verbe + objet, court** : « Ajouter une cotation », « Saisir la prime », « Souscrire »
   (`PisteListCanvasProvider.php:102-120`) ; le verbe suit le type quand le geste en dépend
   (`DefautsDeSouscription::VERBE`, :133-140 : « Proroger », « Résilier la police »,
   « Renouveler »).
5. **Libellé complet dans `title` et `aria-label`** quand l'écran n'affiche que l'icône (barre du
   workspace) ; une icône ne porte jamais seule le sens.
6. **Une action est libellée par la PROCHAINE action concrète** sur l'objet (une action par
   étape dans `vue_cartes.actions`).

### Erreurs, refus, toasts
7. **Ce qui s'est passé, puis quoi faire.** Modèle : « Cette fiche a été modifiée depuis son
   ouverture (champs). Rien n'a été enregistré : rechargez-la pour repartir de son état actuel. »
   (`ControllerUtilsTrait.php:1808-1811`).
8. **Une erreur sans champ s'affiche par son message**, jamais par « Veuillez corriger les
   erreurs ci-dessous » (`ControllerUtilsTrait.php:1994-1998`).
9. **Un refus renvoie toujours vers un geste réellement possible** — le toast peut porter ce
   geste (`action: {label, evenement}`, ex. « Retirer ce filtre », `fiche-hors-filtre.js:45`).
10. **Un toast dit la cause et la conséquence**, et se tait quand l'utilisateur n'a rien fait
    (`messageDEtape`, `vue-cartes-regles.js:85-96`).
11. **Ket parle comme l'écran** : son refus est `bloquant` (phrase du courtier), jamais `note`
    (`tests/Ai/MotifDeRefusTest.php`).

### Honnêteté des chiffres
12. **Ne jamais dire « rien » quand quelque chose reste dû** : « Rien de plus à facturer : ces
    commissions sont déjà portées par une note. Il reste X à encaisser — un règlement à obtenir,
    pas une nouvelle note à émettre. » (`_facturation_picker.html.twig:100-102`).
13. **Nommer la portée d'un chiffre** : « Total de la page », avec son explication
    (`_list_summary.html.twig:22-23`) ; un plafond le dit (« + de … »,
    `vue_cartes/_tableau.html.twig:28-29`).
14. **Une valeur absente s'affiche « — »**, jamais 0 (`_carte_piste.html.twig:40`).

### Localisation
15. **Nombres** : `format_nombre` et `format_montant` (`src/Twig/Extension/NombreExtension.php`),
    localisés selon la langue. `format_montant` n'a AUCUN symbole par défaut : un prix en dollars
    (tokens) passe `'$'`, un montant du cabinet porte le code de sa monnaie de travail.
16. **Monnaie** : celle de travail du cabinet, `ServiceMonnaies::getCodeMonnaieAffichage()`
    (:75) ; jamais un code écrit en dur (`courtage-assurance` §4, « une seule monnaie par cabinet »).
17. **Dates** : une seule horloge, `APP_TIMEZONE` ; en Twig `format_datetime`, côté client
    `app-datetime` et `formatInstant` (`assets/datetime-format.js:26`). Jamais `toLocaleString()`
    ni un fuseau implicite.
18. **Traduction — état réel** : `|trans` dans 22 gabarits sur 266, surtout les pages publiques
    et la gestion des cabinets ; l'espace de travail et les canevas sont en français écrit en
    dur. Faut-il traduire les nouveaux écrans ? **À CONFIRMER (Serge)**.

## 2. Où regarder dans le code

- Libellés métier partagés : `PisteTransformationScope.php:83-151`, `DefautsDeSouscription.php:133-140`.
- Libellés de champs : `ChampsObligatoiresInspector::libelleChamp`, `WorkspaceAccessResolver::libellesEntites`.
- Messages serveur : `ControllerUtilsTrait::handleFormSubmission` (`:1712-2002`).
- Toasts et gestes : `notification-manager_controller.js`, `fiche-hors-filtre.js`.
- Formats : `NombreExtension.php`, `CurrencyExtension.php` (`display_currency_code`),
  `assets/datetime-format.js`.

## 3. Anti-modèles vécus dans ce projet

- **« USD » en dur dans `PisteType`** sur les deux montants : mentait à tout cabinet qui compte
  dans une autre monnaie. Monnaie d'affichage du cabinet depuis `62d7ebde`
  (`PisteType.php:42-45`). Les derniers replis `'USD'` et le `'$'` par défaut de
  `format_montant` sont partis au chantier 5 (`e4d1ba09`).
- **Un défaut posé par l'option `data` d'un champ** (l'exercice d'une piste) : elle l'emporte
  sur la valeur de l'objet, et MODIFIER une piste de 2024 la ramenait à 2026. Le défaut d'une
  entité neuve se pose dans son constructeur (`Piste::__construct`, chantier 5, `8858cdd7`).
- **« Transformée (Souscrite) » contre « Transformées »** : la sous-ligne ne parlait pas la
  langue du chip. Un seul libellé, en badge coloré, depuis `2809442f`
  (`PisteListCanvasProvider.php:30-33`).
- **« Nouvelle » pour une piste de 200 jours** : commit introuvable dans l'historique,
  **À CONFIRMER (Serge)**. La carte affiche aujourd'hui l'ancienneté de la dernière activité
  (« N j », `_carte_piste.html.twig:68-71`).
- **« Rien à facturer » sans le reste à encaisser** : une énigme devant une échéance que la
  liste annonce exigible. Corrigé par `d67fbd91` (`_facturation_picker.html.twig:13-14`, :100-102).
- **« Total » à côté de « 1–20 sur 42 »** : c'était la somme de la page (`62d7ebde`).
- **Messages « mécaniques »** (« Relation obligatoire à préciser (identifiant) ») : nom de
  propriété et terme de stockage, sans la colonne (mémoire « Messages en langage du métier »).

## 4. Checklist

- [ ] Chaque libellé vient du glossaire et d'UNE source partagée (chip = colonne = carte = Ket).
- [ ] Chaque action : verbe + objet ; libellé complet en `title`/`aria-label` si icône seule.
- [ ] Chaque erreur : ce qui s'est passé + quoi faire ; chaque refus : un geste possible.
- [ ] Aucun « rien » quand quelque chose reste dû ; la portée de chaque chiffre est dite.
- [ ] Aucune monnaie, aucun format de nombre ou de date écrit en dur.
- [ ] Aucun toast quand l'utilisateur n'a rien fait.
- [ ] Le refus correspondant de Ket (`bloquant`) dit la même chose.
