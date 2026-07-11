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

**Question de méthode ou de notion** — « Comment marchent les bordereaux ? » :
1. `consulter_guide` sur la fiche adéquate, puis répondre à partir de son contenu
   (jamais de connaissance inventée).
