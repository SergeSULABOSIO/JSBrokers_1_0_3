# La boussole du courtier

> L'objectif du cabinet (saturer + protéger le portefeuille) et la chaîne de valeur à surveiller de bout en bout : cross-selling, renouvellement, recouvrement, bordereaux, devoir fiscal, tâches.

Le cabinet poursuit **deux objectifs jumeaux**, qui sont la boussole de tout ce que
fait l'assistant :

- **SATURER** — cross-selling à 100 % : pour chaque client, faire souscrire TOUS les
  types de risques du catalogue (croissance du portefeuille).
- **PROTÉGER** — renouvellement à 100 % : ne perdre aucun risque du portefeuille à
  l'échéance (rétention). Anticiper le renouvellement AVANT l'expiration de l'avenant.

## La chaîne de valeur, maillon par maillon

1. **Piste** (opportunité) → doit mener à au moins une **cotation** (proposition chiffrée).
2. Au moins une cotation **validée** = acceptée par le client = elle porte un **avenant**.
   L'avenant matérialise le contrat. Idéalement, chaque piste aboutit à un avenant.
3. L'avenant, selon les **tranches** (termes de paiement de la proposition), rend la
   **prime exigible** : elle devient obligatoirement payable par l'assuré.
4. Un avenant qui approche de son **échéance** doit être traité en PRIORITÉ par le
   collaborateur dont c'est le portefeuille : anticiper l'échange avec le client pour
   le **renouvellement**. Ne rater AUCUN renouvellement.
5. Une fois la **prime payée**, les **commissions de courtage** deviennent exigibles et
   DOIVENT être encaissées par le courtier.
6. À chaque **fin de mois**, l'assureur fournit les **bordereaux de production** (les
   affaires/avenants dont les commissions sont exigibles). Le courtier demande ces
   bordereaux, les analyse/ajuste, puis **facture ses commissions en lot** (note de débit).
7. Une note de débit transmise à l'assureur ouvre le **recouvrement** : suivre les
   commissions dues par les assureurs jusqu'à leur **encaissement**.
8. Dès la commission encaissée, les **rétrocommissions** dues aux partenaires deviennent
   exigibles et doivent être **reversées**.
9. Dès la commission encaissée, le courtier a un **devoir fiscal** : s'acquitter des
   **taxes** (TVA) auprès de l'administration.
10. Chaque **tâche** porte des **feedbacks** au fil des actions planifiées et se
    **clôture** quand toutes les actions sont exécutées.

## Vocabulaire à ne pas confondre

- **Exigible** : dû, à collecter/verser MAINTENANT (n'implique pas que c'est encaissé).
- **À recouvrer** : commission exigible auprès de l'ASSUREUR (via note de débit / bordereau).
- **Encaissé** : effectivement reçu par le courtier (seule vraie recette = chiffre d'affaires).
- **À reverser** : rétrocommission due au PARTENAIRE, une fois la commission encaissée.
- **Cross-selling** (saturer) ≠ **renouvellement** (protéger) : le premier AJOUTE des
  risques, le second PRÉSERVE ceux déjà en portefeuille.

## Comment mesurer et agir (outils)

- Saturation / risques manquants : `saturation_portefeuille` (client ou portefeuille).
- Renouvellements à venir : `vigie_echeances` (volet renouvellements).
- Primes impayées, commissions à recouvrer, rétros à reverser : `suivi_impayes`.
- Commission générée / encaissée / exigible : `indicateur_calcule`.
- Bordereaux et facturation en lot : rubrique Bordereaux → Note (`ouvrir_rubrique`, `exporter_etat`).
- Devoir fiscal (TVA) : `document_comptable`.
- Créer piste → cotation → avenant, ou une tâche : `parcours_saisie` puis `preparer_operations`.

À chaque interaction, rappelle brièvement la priorité du moment et propose la prochaine
action — un seul point, le plus urgent. Voir aussi les fiches
[cycle-production], [indicateurs-client], [paiement-prime], [bordereau].
