# Paiement de la prime et exigibilité de la commission

> Le signalement du paiement d'une prime est une trace DÉCLARATIVE (l'assureur encaisse, jamais le cabinet) : c'est lui qui rend la commission de courtage exigible.

Sur le marché par défaut, **l'assureur facture et encaisse la prime** auprès de l'assuré.
Le courtier n'en voit jamais la trésorerie : il se contente de **tracer** l'information
(transmise par le client ou l'assureur) pour savoir quand sa propre commission devient
collectable. Cette trace s'appelle un **signalement de paiement de prime**, porté par une
**tranche** (une tranche = une échéance de règlement d'une affaire).

Un signalement porte une date de règlement, un montant (les paiements partiels sont
possibles), une référence, une description et d'éventuelles pièces justificatives (avis de
l'assureur, reçu…).

## Ne jamais confondre avec un « Paiement »

| | Signalement de paiement de prime | Paiement (rubrique Paiements) |
|---|---|---|
| Qui encaisse | l'**assureur** | le **cabinet** |
| Trésorerie du courtier | **aucun impact** | impactée |
| Comptabilité du courtier | hors compta | écriture réelle |
| Effet | rend la commission **exigible** | solde une note |

## Une prime « payée » a trois origines possibles

Le montant de prime réputé payé sur une tranche agrège :

1. les **notes client encaissées** (quand c'est le cabinet qui facture la prime) ;
2. les **signalements** de paiement de prime (le cas courant : l'assureur a encaissé) ;
3. l'**inférence bordereau** — commission assureur soldée, ou tranche couverte par une
   ligne de bordereau réconciliée : le bordereau atteste que l'assureur détient la prime.

D'où un écart possible et NORMAL entre la prime payée et la part signalée : l'expliquer
plutôt que de le contredire.

Dès que la prime est intégralement payée et que la commission n'est pas encore collectée,
celle-ci devient **exigible** auprès de l'assureur.

## Trois dettes, trois débiteurs — jamais une seule

Une tranche porte jusqu'à **trois dettes distinctes**, qui ne s'additionnent pas et ne se
compensent jamais :

| Dette | Qui doit | À qui | Solde lu dans |
|---|---|---|---|
| **Prime** | l'**assuré** | l'assureur | `soldePrime` |
| **Commission** | l'**assureur** | le cabinet | `soldeCommission` |
| **Rétrocommission** | le **cabinet** | le partenaire | `retroAPayer` |

D'où la règle : **un `soldePrime` à 0 signifie « prime soldée », jamais « rien à faire »** —
c'est même la situation normale d'une commission devenue exigible. Chaque ligne de
`suivi_impayes` porte le champ `dette`, qui nomme ce qui reste dû : lis-le.

## Filtrer : quatre axes complémentaires

La rubrique Tranches et l'assistant partagent **quatre axes indépendants**, cumulés en ET
(un groupe de chips par axe) : `prime`, `commission`, `retro` (`payee` / `impayee`) et
`echeance` (`echue` / `a_echoir`). Il n'existe **aucun filtre « impayé » global** : le mot
désignerait deux dettes à la fois.

| Besoin | Axes |
|---|---|
| primes encore dues par les clients | `{prime: impayee}` |
| commissions à collecter maintenant | `{prime: payee, commission: impayee}` |
| rétros à verser maintenant | `{retro: impayee, commission: payee}` |
| relances en retard | `{prime: impayee, echeance: echue}` |
| tout est soldé | `{prime: payee, commission: payee}` |

## Recette d'assistant

1. `paiements_prime` avec `trancheId` : le détail des signalements d'une tranche et son
   contexte (prime totale, part signalée, solde, commission exigible). Sans `trancheId` :
   la liste transversale, filtrable par client/cotation (`lieA`) et par période (`du`/`au`).
2. `suivi_impayes` avec les `axes` du tableau ci-dessus. Sans axe de dette, la sortie porte
   `repartition` : deux comptes nommés, qui se chevauchent et ne s'additionnent pas.
3. `signaler_paiement_prime` pour ENREGISTRER un règlement : le formulaire s'ouvre
   prérempli, l'utilisateur relit et enregistre lui-même — l'assistant n'écrit jamais.
