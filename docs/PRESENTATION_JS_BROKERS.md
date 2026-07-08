# JS Brokers — Présentation du projet

> **Document interne** — support de présentation destiné aux futurs collaborateurs de la société JS Brokers.

---

## 1. JS Brokers en un coup d'œil

**JS Brokers** est une plateforme web de gestion complète destinée aux **cabinets de courtage d'assurance**. Sa promesse tient en une phrase, celle qui accueille chaque visiteur sur notre site :

> **« Votre courtage, plus rapide et plus simple »**
> *Un espace de travail digital, né de plus de 10 ans d'expérience en courtage d'assurance.*

### Le problème que nous résolvons

Aujourd'hui, la plupart des cabinets de courtage gèrent leur activité de manière éclatée : des classeurs Excel pour la production, du papier pour les sinistres, des outils disparates pour les finances, et beaucoup de mémoire humaine pour les renouvellements et les échéances. Résultat : perte de temps, erreurs de calcul de commissions, difficulté à produire les états exigés par les régulateurs, et une connaissance du portefeuille qui repose sur quelques personnes clés.

JS Brokers réunit **tout le cycle de vie du courtage** — de la prospection au règlement des sinistres, en passant par la production, la facturation et la comptabilité — dans **un seul espace de travail en ligne**, accessible depuis un navigateur, sans installation.

### Notre spécificité

La plateforme est née de plus de dix ans de pratique réelle du courtage d'assurance. Elle n'est pas un logiciel générique adapté à l'assurance : chaque rubrique correspond à un geste métier du courtier (bordereaux, avenants, rétro-commissions, partages entre intermédiaires…). Elle intègre nativement le **référentiel comptable OHADA**, ce qui la positionne d'emblée sur le marché africain francophone, où ce besoin est massif et très mal servi.

### L'architecture du produit : deux piliers

Le produit s'organise autour de deux espaces distincts, complétés par une vitrine publique :

| Espace | Pour qui ? | À quoi ça sert ? |
|---|---|---|
| **Le Workspace** | Nos clients : les courtiers et leurs équipes | Gérer toute l'activité de leur cabinet au quotidien |
| **La Console** | Nous : l'équipe JS Brokers | Piloter notre propre entreprise — ventes, clients, finances, RH |
| **La vitrine publique** | Les visiteurs et prospects | Découvrir le produit, s'inscrire et acheter en autonomie |

Le reste de ce document présente le modèle économique, puis chacun des deux piliers en détail.

---

## 2. Le modèle économique — un freemium à l'usage (les « tokens »)

JS Brokers ne vend pas d'abonnement mensuel classique. Le modèle est un **paiement à l'usage** basé sur des **tokens** : chaque action sur la plateforme (consulter une fiche, créer un client, enregistrer un avenant…) consomme un petit nombre de tokens.

### Le plan BASIC — gratuit

Tout courtier qui s'inscrit démarre gratuitement :

- **1 000 tokens offerts**, renouvelés automatiquement à intervalle régulier ;
- la **première entreprise (cabinet) offerte** ;
- accès au tableau de bord, envoi d'e-mails illimité, accès à toutes les fonctions ;
- les tokens gratuits ne sont pas cumulables : à l'épuisement, le compte est mis en pause jusqu'au prochain renouvellement.

### Les paquets prépayés

Quand l'activité du courtier grandit, il recharge son compte avec des **paquets de tokens prépayés** :

- tokens **cumulables et sans expiration** ;
- les tokens payés sont consommés en priorité sur le quota gratuit ;
- assistance technique personnalisée incluse ;
- **coupons de réduction** applicables à l'achat, avec promotions affichées publiquement sur la page tarifs ;
- paiement en ligne par carte, **facture PDF** générée automatiquement, numérotée séquentiellement, jointe par e-mail et téléchargeable à tout moment ;
- historique complet de consommation et des achats dans l'espace du courtier.

### Pourquoi ce modèle ?

1. **Barrière d'entrée nulle** : le courtier essaie le produit complet, gratuitement, sans carte bancaire — décisif sur un marché où l'abonnement logiciel est encore peu ancré dans les habitudes.
2. **Le prix suit l'usage** : un petit cabinet paie peu, un gros cabinet paie proportionnellement à son activité. Le revenu de JS Brokers croît naturellement avec le succès de ses clients.
3. **Pilotage fin** : chaque token consommé est une donnée d'usage, ce qui nous donne une visibilité précise sur l'engagement de chaque client (et alimente directement notre CRM interne — voir la Console).

---

## 3. Pilier 1 — Le Workspace : l'espace de travail du courtier

Le Workspace est **le produit que nous vendons**. Chaque utilisateur peut y créer une ou plusieurs **entreprises** (ses cabinets) ; chaque entreprise dispose de son propre espace de travail avec un **tableau de bord** central et un menu organisé par domaines métier. La navigation se fait par onglets : le courtier ouvre en parallèle un client, une piste et un bordereau, comme il ouvrirait plusieurs dossiers sur son bureau.

Le menu du Workspace reflète les cinq grands domaines de l'activité d'un cabinet, plus la comptabilité du cabinet lui-même.

### 3.1 Marketing & CRM — de la piste au client

*« Accompagnez chaque prospect jusqu'au client satisfait. »*

- **Piste** : suivi des prospects et opportunités commerciales, jusqu'à leur transformation en affaires.
- **Tâches** : actions à mener, agendas et plans d'action de l'équipe commerciale.
- **Compte-rendus** : traçabilité des rendez-vous et des échanges avec les prospects et clients.

### 3.2 Production — le cœur du métier

*« Pilotez votre production au quotidien. »*

- **Clients** et **Groupe** (regroupements de clients, ex. filiales d'un même groupe) ;
- **Assureurs** et **Contacts** chez ces assureurs ;
- **Risques** : les couvertures d'assurance placées ;
- **Avenants** : les polices et tous leurs mouvements (souscription, renouvellement, prorogation, résiliation…). Point fort : lors d'un **renouvellement**, la plateforme reconduit automatiquement les partenaires et leurs **conditions de partage de commission** — l'un des calculs les plus sensibles et les plus chronophages du métier ;
- **Intermédiaires** : les partenaires et apporteurs d'affaires, avec leurs règles de rétro-commission ;
- **Proposition** : les cotations envoyées aux clients ;
- **Recherche avancée multicritères** sur l'ensemble du portefeuille.

### 3.3 Finances — primes, commissions, facturation

*« Gérez paiements, taxes et règlements. »*

- **Monnaies** (multi-devises avec taux) et **Comptes bancaires** ;
- **Taxes** et autorités fiscales et de régulation ;
- **Types des revenus**, **Tranches** et **Types des chargements** : le paramétrage fin des règles de calcul de commissions du cabinet ;
- **Bordereaux** : les états de production transmis par ou vers les assureurs, avec **facturation directe** — un bordereau validé génère la note correspondante en un clic ;
- **Notes** : factures et notes de débit ;
- **Paiements** : encaissements et décaissements, collecte des primes et commissions, preuves de paiement ;
- **Revenus** : la vision consolidée des revenus de courtage.

### 3.4 Sinistres — le service qui fidélise

*« Suivez vos sinistres et leurs règlements. »*

- **Déclarations** de sinistre, avec victimes, experts en évaluation et pièces du dossier ;
- **Reglements** des sinistres ;
- **Type de pièce** : modèles de pièces exigées par nature de sinistre, pour constituer des dossiers complets du premier coup.

### 3.5 Administration & Collaboration — travailler en équipe, chacun dans son périmètre

*« Invitez vos collaborateurs… chacun dans son périmètre. »*

- **Documents** et **Classeurs** : la gestion électronique des documents du cabinet ;
- **Invités** : le propriétaire du cabinet invite ses collaborateurs et attribue à chacun des **rôles et droits d'accès rubrique par rubrique** (lecture, écriture, suppression…). Le périmètre s'applique partout : menu, tableaux de bord, recherches — un collaborateur ne voit que ce qui le concerne ;
- **Délégation** : la gestion des invités peut elle-même être déléguée à un collaborateur de confiance ;
- **Portefeuilles** : chaque gestionnaire peut se voir affecter un portefeuille de clients, avec affectation, transfert et suivi des indicateurs par portefeuille ;
- chaque invité reçoit un **e-mail récapitulatif de son périmètre** dès qu'il est modifié.

### 3.6 Comptabilité & fiscalité OHADA — la comptabilité du cabinet, incluse

*« Tenez la comptabilité de votre cabinet. »*

C'est un différenciateur majeur : le Workspace inclut une comptabilité conforme au référentiel **OHADA**, générée automatiquement à partir de l'activité saisie :

- les **7 états comptables** : Journal, Grand livre, Balance, Compte de résultat, TFR, Bilan et Tableau des flux de trésorerie (TFT) — produits à la volée, toujours à jour ;
- **Charges et dépenses** du cabinet, avec fournisseurs et dossiers d'achat ;
- **Suivi fiscal** : TVA collectée et déductible, reversements ;
- **export Excel** de tous les états.

Le courtier n'a plus besoin d'un outil comptable séparé pour les obligations courantes de son cabinet.

### 3.7 L'expérience utilisateur

Le Workspace est une application web moderne : formulaires contextuels en dialogues superposables, champs à autocomplétion, sélecteurs dédiés (affectation de clients à un portefeuille en quelques clics), notifications, e-mails à l'identité visuelle JS Brokers, et interface **bilingue français / anglais**. L'ensemble respecte une charte graphique unifiée autour du bleu cobalt JS Brokers (détaillée en annexe, section 8).

---

## 4. Pilier 2 — La Console : le cockpit interne de JS Brokers

La Console est l'espace réservé à **l'équipe JS Brokers** — c'est-à-dire à vous, futurs collaborateurs. C'est l'outil avec lequel nous pilotons notre propre entreprise : nos ventes, nos clients, nos finances et notre organisation. Elle est structurée en six domaines, et **chaque collaborateur y accède selon son département** : un membre de l'équipe Finance voit les rubriques Finance, un membre du Support voit le CRM, la direction voit tout.

### 4.1 Tableau de bord

L'écran d'accueil de chaque collaborateur :

- un bandeau personnel (département, fonction, périmètre d'accès, **score d'évaluation sur 100**) ;
- les **indicateurs de la plateforme** : utilisateurs, entreprises, ventes, consommation ;
- le **revenu des ventes** en graphiques — par mois, par paquet, par pays ;
- l'activité récente : dernières ventes, dépenses, entreprises créées, clients, utilisateurs, coupons.

### 4.2 CRM — la relation avec nos clients courtiers

Un CRM interne complet, alimenté automatiquement par l'activité réelle des comptes :

- **Clients et Prospects** : vue à 360° de chaque client (identité, usage, achats, échanges) avec un **score de santé** calculé ;
- **Pipeline** : un Kanban commercial dont les étapes se déduisent automatiquement du comportement du compte (inscrit, actif, acheteur, à risque…) ;
- **Tâches** et **Campagnes** marketing ;
- **Support Client** : tickets, suivi des demandes et des résolutions ;
- **Customer Success** : suivi proactif de l'adoption et de la rétention ;
- **Notifications** internes à l'équipe.

### 4.3 Pilotage — les tableaux de bord de direction

- **CFO** : trésorerie cumulée, marge, coût d'acquisition client (CAC), rétention par ré-achat mensuel ;
- **CEO** : la synthèse stratégique de l'activité ;
- **Rapports** : exports Excel pour les analyses et les conseils d'administration.

### 4.4 Comptes & organisation interne

- **Collaborateurs** : les comptes de l'équipe JS Brokers ;
- **Départements & rôles** : l'organigramme qui détermine qui accède à quoi dans la Console ;
- **Évaluations** : objectifs **SMART** par collaborateur, fiche d'évaluation calculée, notification au concerné — la performance individuelle est suivie dans l'outil lui-même ;
- **Utilisateurs**, **Clients** et **Entreprises** : les registres globaux de tous les comptes de la plateforme.

### 4.5 Ventes & monétisation

- **Ventes** : toutes les ventes de tokens, filtrables, avec gestion des remboursements ;
- **Plan tarifaire** : les paquets de tokens et leurs prix, **éditables directement depuis la Console** — la page tarifs publique se met à jour instantanément ;
- **Coupons** : création de codes de réduction, ciblage par paquet, et mise en avant automatique des promotions sur la vitrine publique.

### 4.6 Finance & conformité de JS Brokers

Le même moteur comptable OHADA que celui offert aux courtiers, appliqué à notre propre société :

- **Dépenses** et **Charges**, avec un axe analytique qui alimente les indicateurs CFO (CAC, marge) ;
- **Documents comptables** : Journal, Grand livre, Balance, Résultat, TFR, Bilan, TFT de JS Brokers, générés à la volée, exportables en Excel ;
- **Fiscalité** : TVA collectée moins déductible, reversements, solde dû par exercice et par mois.

**En résumé** : la Console fait de JS Brokers une entreprise pilotée par la donnée dès le premier jour — chaque vente, chaque dépense, chaque interaction client est mesurée, et chaque collaborateur y trouve son poste de travail.

---

## 5. La vitrine publique — un parcours 100 % self-service

Le troisième maillon est le site public, conçu pour qu'un courtier passe de la découverte au premier achat **sans aucune intervention humaine** :

- une **page d'accueil** qui présente les fonctionnalités clés (*« Tout ce dont vous avez besoin, au même endroit. »*) et un guide de démarrage ;
- la **page tarifs** avec le plan gratuit, les paquets prépayés et les promotions en cours ;
- **inscription autonome** avec acceptation des **Conditions d'utilisation** (versionnées et tracées), vérification d'e-mail et récupération de mot de passe ;
- **achat de tokens en ligne**, application de coupons avec aperçu du prix en direct, facture PDF immédiate ;
- un **formulaire de contact** avec double e-mail : notification à l'équipe et accusé de réception au visiteur ;
- l'ensemble disponible en **français et en anglais**.

---

## 6. Ce qui différencie JS Brokers

1. **Le métier encodé dans le produit.** Dix ans de courtage réel se retrouvent dans chaque rubrique : bordereaux, partages de commissions, reconduction des partenaires au renouvellement — des besoins qu'aucun outil généraliste ne couvre.
2. **La conformité OHADA native.** Les 7 états comptables et le suivi TVA sont générés automatiquement, pour nos clients courtiers comme pour notre propre gestion. Sur le marché africain francophone, c'est un avantage décisif.
3. **Un freemium sans risque.** Le courtier essaie tout, gratuitement ; il ne paie que ce qu'il consomme. L'adoption ne demande ni engagement ni négociation commerciale.
4. **La collaboration à périmètre maîtrisé.** Un cabinet peut faire entrer toute son équipe dans l'outil en gardant un contrôle fin de qui voit et fait quoi — condition indispensable dans un métier où la confidentialité du portefeuille est stratégique.
5. **Une seule plateforme pour tout le cycle.** Prospection, production, sinistres, facturation, comptabilité, fiscalité : le courtier n'a plus à faire dialoguer cinq outils.
6. **Une entreprise pilotée par sa propre Console.** Nous appliquons à nous-mêmes ce que nous vendons : mesure, rigueur comptable et relation client outillée.

---

## 7. Annexe — glossaire rapide

Pour les collaborateurs qui ne viennent pas du monde de l'assurance :

| Terme | Définition |
|---|---|
| **Courtier** | Intermédiaire indépendant qui place les risques de ses clients auprès des assureurs et se rémunère par commission. |
| **Risque** | La couverture d'assurance placée (ex. flotte automobile, incendie, santé collective). |
| **Avenant** | Tout acte modifiant ou créant une police d'assurance : souscription, renouvellement, prorogation, résiliation… |
| **Piste** | Une opportunité commerciale (prospect ou affaire potentielle) suivie jusqu'à sa conclusion. |
| **Bordereau** | État récapitulatif des primes et commissions échangé entre le courtier et l'assureur, base de la facturation. |
| **Note (de débit)** | La facture émise par le cabinet pour réclamer primes ou commissions. |
| **Intermédiaire / apporteur** | Partenaire qui apporte une affaire au courtier et perçoit une part de la commission (rétro-commission). |
| **Partage / rétro-commission** | La répartition de la commission entre le cabinet et ses partenaires, selon des conditions négociées. |
| **Sinistre** | L'événement dommageable déclaré par le client, que le courtier accompagne jusqu'au règlement par l'assureur. |
| **OHADA** | Organisation pour l'Harmonisation en Afrique du Droit des Affaires — son référentiel comptable s'impose aux entreprises de 17 pays africains. |
| **TFR / TFT** | Tableau de Formation du Résultat / Tableau des Flux de Trésorerie — deux états comptables OHADA. |
| **Token** | L'unité de consommation de la plateforme JS Brokers : chaque action en consomme quelques-uns. |
| **Workspace** | L'espace de travail d'un cabinet de courtage sur JS Brokers. |
| **Console** | L'espace interne de pilotage réservé à l'équipe JS Brokers. |

---

## 8. Annexe — Charte graphique des couleurs JS Brokers

Tout support visuel produit au nom de JS Brokers (présentations, documents, interfaces) doit respecter la charte des couleurs officielle ci-dessous. **Aucune couleur hors de cette palette ne doit être introduite.**

### 8.1 Couleurs de marque

| Couleur | Code hex | Rôle |
|---|---|---|
| **Cobalt** | `#0047AB` | **Couleur principale de la marque JS Brokers.** Titres forts, actions primaires, accents de marque, icônes de marque. |
| Cobalt foncé | `#003380` | État survol (hover) des éléments cobalt. |
| Cobalt hover | `#0a58ca` | Variante hover des éléments bleu standard. |
| Bleu standard | `#0d6efd` | Interactions courantes : liens, boutons secondaires d'action, éléments de mise en évidence. |
| Cobalt très clair | `#e8f0fb` | Fond d'accent léger (bandeaux de nouveauté, encadrés à teinte de marque). |

### 8.2 Fonds et surfaces

| Couleur | Code hex | Rôle |
|---|---|---|
| Fond global | `#f3f3f3` | Arrière-plan général des pages. |
| Blanc | `#ffffff` | Cartes, panneaux, surfaces de contenu. |
| Gris très clair | `#f8f9fa` | Fonds de tableaux et zones secondaires claires. |
| Gris intermédiaire | `#f0f0f0` | Surfaces intermédiaires (corps de fenêtres). |
| Gris neutre | `#e9ecef` | Sélections, états désactivés, survols de lignes. |
| Vert d'eau | `#e0f9ee` | Fond spécial des lignes de revenus / entrées financières. |
| Sombre principal | `#212529` | Fonds sombres (menus contextuels, panneaux d'indicateurs). |
| Sombre secondaire | `#343a40` | Barres d'outils sombres, survols sur fond sombre. |
| Sombre survol | `#495057` | Survol et séparateurs sur fond sombre. |

### 8.3 Textes

| Couleur | Code hex | Rôle |
|---|---|---|
| Noir | `#000000` | Contenu saisi dans les champs de formulaire. |
| Texte principal | `#212529` | Texte courant, titres actifs. |
| Texte de titre | `#343a40` | Titres de champs et de sections. |
| Texte de corps | `#495057` | Texte des cellules de tableaux, contenu secondaire. |
| Texte atténué | `#6c757d` | Texte secondaire, éléments inactifs. |
| Labels | `#adb5bd` | Étiquettes de formulaire, placeholders. |
| Blanc | `#ffffff` | Texte sur fonds sombres ou colorés. |
| Cobalt texte | `#0047AB` | Texte accentué aux couleurs de la marque. |

### 8.4 Bordures et séparateurs

| Couleur | Code hex | Rôle |
|---|---|---|
| Bordure standard | `#dee2e6` | Tableaux, séparations courantes. |
| Bordure moyenne | `#ced4da` | Séparateurs de barres d'outils. |
| Bordure légère | `#e0e0e0` | Séparations discrètes. |
| Bordure sombre | `#495057` | Bordures sur fond sombre. |

### 8.5 États sémantiques

Réservés aux **retours utilisateur** (succès, erreur, avertissement) — jamais à la décoration.

| État | Couleur principale | Texte associé | Fond associé |
|---|---|---|---|
| **Succès** | `#198754` | `#0f5132` | `#d1e7dd` |
| **Danger / erreur** | `#dc3545` | `#842029` | `#f8d7da` |
| **Avertissement** | `#ffc107` (orange vif : `#e69500`) | `#664d03` | `#fff3cd` |

### 8.6 Règles d'application

**Priorité des couleurs :**
1. **Cobalt `#0047AB`** pour tout ce qui porte la marque JS Brokers (titres, actions primaires, accents forts) ;
2. **Bleu `#0d6efd`** pour les interactions standard (liens, boutons courants) ;
3. **Gris neutres** (`#212529`, `#495057`, `#6c757d`, `#adb5bd`) pour les textes ;
4. **Couleurs sémantiques** (vert / rouge / jaune) uniquement pour les retours d'état.

**Associations fond / texte obligatoires :**

| Fond | Texte autorisé |
|---|---|
| `#ffffff`, `#f8f9fa`, `#f3f3f3` | `#212529`, `#343a40`, `#495057`, `#000000` |
| `#e9ecef`, `#f0f0f0` | `#212529`, `#495057`, `#6c757d` |
| `#0047AB`, `#0d6efd` | `#ffffff` uniquement |
| `#212529`, `#343a40` | `#ffffff`, `#e9ecef`, `#adb5bd` |
| `#198754`, `#d1e7dd` | `#0f5132` ou `#ffffff` |
| `#dc3545`, `#f8d7da` | `#842029` ou `#ffffff` |
| `#fff3cd` | `#664d03` uniquement |

**Interdictions :**
- Jamais de couleur inventée hors de cette palette.
- Jamais de texte gris atténué (`#6c757d`, `#adb5bd`) sur fond sombre `#212529` (contraste insuffisant).
- Jamais de rouge `#dc3545` pour un élément décoratif : le rouge signale une erreur ou une suppression, rien d'autre.
- Jamais de cobalt `#0047AB` comme fond de texte courant : le cobalt accentue, il ne tapisse pas.

**États interactifs :**

| État | Transformation |
|---|---|
| Survol d'un élément cobalt | passer à `#003380` |
| Survol d'un élément bleu | passer à `#0a58ca` |
| Focus | bordure cobalt + halo `rgba(0, 71, 171, 0.35)` |
| Désactivé | opacité 65 % de la couleur de base |
| Sélectionné | fond `#e9ecef` sur surfaces claires, `#343a40` sur fonds sombres |

---

*Document rédigé pour les réunions de présentation aux futurs collaborateurs — JS Brokers.*
