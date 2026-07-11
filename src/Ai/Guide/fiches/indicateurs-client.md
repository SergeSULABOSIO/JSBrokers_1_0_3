# Indicateurs financiers d'un client

> Prime totale, commission nette, taux de sinistralité… : des valeurs CALCULÉES à la volée, à lire via l'outil indicateur_calcule.

Les indicateurs financiers d'un client ne sont PAS stockés en base : ils sont
calculés en temps réel à partir de ses avenants, paiements et sinistres. C'est
pourquoi ils se lisent exclusivement via l'outil `indicateur_calcule` (qui exécute
la stratégie d'indicateurs du client).

Indicateurs typiques :

- **Prime totale** : somme des primes des avenants du client.
- **Commission / rémunération** : ce que les affaires du client rapportent au
  courtier (brut, net des partages partenaires selon l'indicateur).
- **Solde de prime** : ce qui reste à encaisser sur les primes.
- **Taux de sinistralité** : rapport entre la charge des sinistres et les primes —
  l'indicateur clé de la rentabilité technique d'un client.

Recette « bilan client » :

1. Si l'utilisateur ne donne pas de nom exact : `rechercher_entites` (entite=Client,
   filtre=…) pour identifier le client.
2. Enchaîner `indicateur_calcule` sur les indicateurs demandés (prime totale,
   commission, sinistralité…).
3. Restituer en texte simple, avec les unités, et signaler qu'il s'agit de valeurs
   calculées à l'instant de la demande.
