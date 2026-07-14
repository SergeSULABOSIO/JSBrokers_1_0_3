# Recettes d'enchaînement d'outils

> Les combinaisons d'outils qui répondent aux demandes composées : bilan client, revue de portefeuille, création guidée.

Enchaîner les outils sans demander la permission, puis restituer UNE réponse
complète en texte simple.

**Bilan d'un client** — « Fais-moi le point sur le client X » :
1. `rechercher_entites` (Client, filtre=X) si le nom n'est pas exact.
2. `indicateur_calcule` sur 2 à 4 indicateurs clés (prime totale, commission,
   solde de prime, taux de sinistralité).
3. Synthèse : chiffres avec unités + lecture (ex. sinistralité élevée = client à
   surveiller).

**Revue d'une rubrique** — « Où en est-on sur les pistes ? » :
1. `compter_entites` pour le volume global.
2. `rechercher_entites` pour la première page d'enregistrements.
3. Proposer la page suivante si le total dépasse une page.

**Création / modification guidée** — « Crée un client », « Modifie l'avenant Y » :
1. En édition : `rechercher_entites` d'abord pour obtenir l'id exact (demander
   confirmation si plusieurs candidats).
2. `ouvrir_dialogue` (creation ou edition + id) : le formulaire s'ouvre chez
   l'utilisateur, qui saisit et enregistre lui-même — l'assistant n'écrit jamais.
3. Si l'utilisateur a dicté des valeurs (« Crée un client Kabila Corp, téléphone
   +243… »), les passer dans `valeurs` (champ => valeur) : le formulaire s'ouvre
   pré-rempli. STRICTEMENT les valeurs dictées — ne jamais en inventer.

**Brief du jour / échéances** — « Que dois-je surveiller ? », « Mes renouvellements ? » :
1. `vigie_echeances` (volet=tout, ou un volet précis + horizonJours).
2. Mettre en avant l'urgent : renouvellements proches, tâches en retard.

**Revue du portefeuille** — « Notre meilleur assureur ? », « Production 2026 ? » :
1. `analyse_portefeuille` (analyse=top_assureurs / top_clients / top_risques /
   top_intermediaires / production_mensuelle / encaissements, limite, annee).
2. Croiser avec `indicateur_calcule` pour zoomer sur un acteur précis.

**Export d'un état** — « Exporte le classeur comptable », « Imprime la note X » :
1. Pour un PDF de note/bordereau : `rechercher_entites` (Note) pour l'id.
2. `exporter_etat` : le téléchargement s'ouvre chez l'utilisateur (jamais de
   fichier généré par l'assistant lui-même).

**Envoi du relevé SOA** — « Envoie le SOA du client X » :
1. `preparer_envoi_soa` (nom ou id via `rechercher_entites`) : la boîte d'envoi
   s'ouvre pré-ciblée — c'est l'utilisateur qui choisit le destinataire et
   confirme, l'assistant n'envoie jamais rien.

**Détail d'une fiche** — « Quelle est l'adresse du client X ? » :
1. `lire_fiche` (entite, nom ou id). Si la réponse liste des candidats (nom
   ambigu), demander à l'utilisateur de préciser, puis relancer avec l'id.

**Analyse financière du cabinet** — « Nos commissions ce trimestre ? » :
1. `indicateur_calcule` (entite=Entreprise, code, du/au) pour les montants
   calculés ; `document_comptable` pour les états (trésorerie, résultat, TVA) ;
   `statistiques` pour les répartitions sur champs stockés.

**Navigation** — « Ouvre les bordereaux », « Visualise le client X » :
1. `ouvrir_rubrique` (liste à l'écran) ou `visualiser_fiche` (fiche en colonne
   de visualisation, id via `rechercher_entites` si besoin).

**Question de méthode ou de notion** — « Comment marchent les bordereaux ? » :
1. `consulter_guide` sur la fiche adéquate, puis répondre à partir de son contenu
   (jamais de connaissance inventée).
