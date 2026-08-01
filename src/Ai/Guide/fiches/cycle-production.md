# Cycle de production du courtier

> Comment une affaire naît et vit dans JS Brokers : piste, cotations, avenant, renouvellement et prorogation.

Le cycle de production suit l'affaire de la prospection au contrat :

- **Piste** : l'opportunité commerciale. Elle porte un client (ou prospect), un risque
  (la branche d'assurance concernée) et d'éventuels partenaires avec leurs conditions
  de partage de la rémunération.
- **Cotation** : une proposition chiffrée obtenue d'un assureur pour une piste. Une
  piste peut avoir plusieurs cotations en concurrence.
- **Avenant** : le contrat concrétisé. L'avenant porte les données contractuelles
  (période de couverture, primes, rémunération du courtier) et alimente les
  indicateurs financiers du client.

Vie du contrat — les quatre MOUVEMENTS :

Un mouvement fait évoluer une police existante. Tous suivent le même mécanisme : une
piste DÉRIVÉE de l'avenant de base, typée par son type d'avenant, reliée à la police
par un double lien. C'est ce lien qui fait basculer le statut affiché de la police
(« Renouvelé », « Prorogé », « Résilié ») et qui la sort de la vigie des échéances.

| Mouvement | Ce qu'il fait | Ce que l'utilisateur doit fournir |
|---|---|---|
| **Renouvellement** | Reconduit la police à l'identique sur la période suivante | **rien** |
| **Prorogation** | Prolonge la couverture en cours | la durée (ou la date de fin) |
| **Annulation** | Met fin à la police | la date d'effet |
| **Résiliation** | Met fin à la police | la date d'effet |

## Une police est-elle renouvelée ?

La question se tranche sur la **chaîne complète**, jamais sur un maillon isolé :

```
police (avenant de base) → opportunité dérivée → propositions → avenants issus
```

Deux champs de la fiche d'une police portent la réponse, et ils en sont la **seule
autorité** : `statutRenouvellement` (« Renouvelée », « Renouvellement en cours »,
« Prorogée », « Résiliée / annulée », « Non renouvelée », « En cours de couverture »,
« Temporaire ») et `suiteDeLaPolice`, qui **nomme** l'avenant successeur — son numéro, sa
référence, sa période — ou affirme l'absence de suite comme une vérification.

Ce qui ne répond PAS à la question :

- la **date d'échéance** : une police expirée depuis six mois peut être parfaitement
  renouvelée ; l'expiration ne dit rien de la suite ;
- `hasPisteDerivee` : il dit qu'un mouvement **existe**, pas qu'il a **abouti** ;
- l'**absence d'information** : ne jamais conclure « pas renouvelée » parce qu'on ne voit
  rien. Une opportunité dérivée **sans** avenant vaut « renouvellement en cours » — la
  police n'est pas encore reconduite. Une opportunité dérivée **avec** un avenant vaut
  « renouvelée » — et cet avenant doit être nommé.

Quand la réalité de la chaîne contredit l'intention enregistrée (une police marquée
« temporaire non renouvelable » qui a pourtant été reconduite), **c'est la chaîne qui
fait foi** : la police est renouvelée.

Défauts appliqués (à annoncer, jamais à demander) :

- **Renouvellement** : nouvelle période = lendemain de l'échéance, même durée ; même
  assureur, même référence de police, numéro d'avenant incrémenté ; prime et sa
  composition, échéancier (dates décalées d'autant) et rémunération du courtier
  reconduits à l'identique.
- **Prorogation** : prime recalculée **au prorata des jours** sur chaque composante ;
  échéancier réduit à une tranche unique exigible à la prise d'effet.
- **Annulation / résiliation** : l'avenant enregistre l'acte à sa date d'effet, **sans
  prime** — une éventuelle ristourne se traite séparément. La police de base passe au
  statut « Annulé / résilié » et sort des polices actives.

Dans tous les cas, les partenaires et leurs conditions de partage sont reconduits sur
la piste dérivée. Les **tâches et comptes-rendus** de la police de base, eux, ne sont
jamais repris : ils appartiennent à l'exercice écoulé. À la place, un renouvellement ou
une prorogation ajoute **une tâche de suivi du paiement de la prime** auprès de
l'assuré — c'est ce paiement qui rend la commission du courtier exigible.

Outil : `preparer_mouvement_avenant` prépare le mouvement entier en un plan unique à
valider. Ne passe pas par `parcours_saisie` pour un mouvement.

- Un avenant peut aussi être créé par **import de bordereau** (voir la fiche
  « bordereau »).

Conseils d'assistant : pour un état des lieux commercial, compter/lister les pistes
et les avenants ; pour la santé financière d'un client, utiliser les indicateurs
calculés (voir la fiche « indicateurs-client »).
