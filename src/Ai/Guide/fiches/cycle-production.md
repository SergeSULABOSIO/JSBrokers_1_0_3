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

Vie du contrat :

- **Renouvellement / prorogation** : se fait par une piste DÉRIVÉE de l'avenant de
  base (l'avenant garde le lien vers sa piste de renouvellement). Les partenaires et
  leurs conditions de partage sont reconduits automatiquement sur la piste dérivée.
- Un avenant peut aussi être créé par **import de bordereau** (voir la fiche
  « bordereau »).

Conseils d'assistant : pour un état des lieux commercial, compter/lister les pistes
et les avenants ; pour la santé financière d'un client, utiliser les indicateurs
calculés (voir la fiche « indicateurs-client »).
