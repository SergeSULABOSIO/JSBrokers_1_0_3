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
8. Dès la commission encaissée, tout ce qui est **assis sur elle** devient exigible : les
   **taxes** qu'elle porte (TVA de l'assureur, ARCA du courtier) et les
   **rétrocommissions** dues aux intermédiaires. C'est la seconde marche de la chaîne —
   la première étant « prime réglée par le client → commission exigible » (point 5) — et
   il ne faut jamais en sauter une : annoncer une rétro exigible parce que la police est
   souscrite, c'est proposer de payer avec de l'argent qui n'est pas rentré. Chaque marche
   est **proportionnelle** : une commission encaissée à 60 % ne rend exigibles que 60 %
   des taxes et des rétros qu'elle porte.

   Les rétrocommissions doivent alors être **reversées**. Elles vont à DEUX sortes de
   bénéficiaires, qu'il ne faut jamais confondre :
   - un **partenaire EXTERNE** (apporteur hors cabinet) : sa part se calcule sur la
     commission pure des revenus *partageables*. Il **facture le cabinet par sa note de
     débit**, et le cabinet lui **reverse** en clair, en rétrocommissions (632) ;
   - un **agent INTERNE** du cabinet (un invité, apporteur de l'affaire) : sa part se
     calcule sur ce qui **reste au cabinet** — commission pure MOINS les partenaires
     externes — et se **verse** de la même façon, en charges de personnel (6611).

   Dans les deux cas le versement s'enregistre **par ÉCHÉANCE** (tranche) : c'est par
   tranche que la prime et la commission sont payées, donc à ce rythme que
   l'intermédiaire est rémunéré. Et dans les deux cas le **justificatif est exigé**.
9. Dès la commission encaissée, le courtier a un **devoir fiscal** : s'acquitter des
   **taxes** (TVA) auprès de l'administration.
10. Chaque **tâche** porte des **feedbacks** au fil des actions planifiées et se
    **clôture** quand toutes les actions sont exécutées.

## Proposition non validée = projet (aucun suivi)

Une piste peut porter PLUSIEURS propositions (cotations) en concurrence, mais le client
n'en valide **qu'UNE seule** : celle qu'il choisit reçoit un **avenant** (la police est
mise en place). Tant qu'une proposition n'a **aucun avenant**, ce n'est qu'un **projet** :

- ses montants (prime, commission, rétro, réserve) ne sont que des **projections** et ne
  comptent **nulle part** : ni dans les indicateurs agrégés d'un client / portefeuille /
  assureur / partenaire (listes du workspace), ni chez Ket (`indicateur_calcule`,
  `analyse_portefeuille`, chiffre d'affaires). Un client qui n'a que des propositions a une
  prime totale et une commission de **0** — ne jamais annoncer les chiffres d'un projet
  comme « engagés ».
- ses **tranches ne comptent pas et ne sont pas suivies** — même si leur date d'effet est
  atteinte ou dépassée. Aucune prime exigible, aucune commission à recouvrer, aucune rétro :
  le suivi ne démarre qu'à la validation (avenant). Ne jamais relancer un « impayé » sur un
  projet.
- la bonne action n'est donc PAS le recouvrement mais la **décision du client** : retrouver
  la piste de la proposition, regarder toutes ses propositions ; si aucune n'est validée,
  aider l'utilisateur à **relancer le client pour qu'il choisisse** la proposition à valider
  (créer une tâche/relance). C'est ce choix qui active la police et démarre le suivi.

## Vocabulaire à ne pas confondre

- **Exigible** : dû, à collecter/verser MAINTENANT (n'implique pas que c'est encaissé).
- **À recouvrer** : commission exigible auprès de l'ASSUREUR (via note de débit / bordereau).
- **Encaissé** : effectivement reçu par le courtier (seule vraie recette = chiffre d'affaires).
- **À reverser** : rétrocommission due, une fois la commission encaissée — à un PARTENAIRE
  externe (facturée) ou à un AGENT interne (versée directement).
- **Réserve** : ce qui reste VRAIMENT au cabinet = commission pure (HT moins la taxe due
  par le courtier) MOINS les rétrocommissions des partenaires externes MOINS celles des
  agents internes. Ne jamais l'annoncer en oubliant l'un des deux bénéficiaires.
- **Agent bénéficiaire ≠ gestionnaire** : celui qui touche la rétrocommission interne n'est
  pas forcément celui qui gère l'affaire. Un agent peut décrocher le premier rendez-vous
  puis confier le suivi à un collègue. Ne jamais déduire l'un de l'autre.
- **Cross-selling** (saturer) ≠ **renouvellement** (protéger) : le premier AJOUTE des
  risques, le second PRÉSERVE ceux déjà en portefeuille.

## Comment mesurer et agir (outils)

- Saturation / risques manquants : `saturation_portefeuille` (client ou portefeuille).
- Renouvellements à venir : `vigie_echeances` (volet renouvellements).
- Primes impayées, commissions à recouvrer, rétros de PARTENAIRES à reverser : `suivi_impayes`.
- Rétrocommissions des AGENTS internes ET des PARTENAIRES externes (dues, payées, solde,
  exigible, détail affaire par affaire, ventilation par axe) : `retrocommissions` — un NOM
  suffit à désigner le bénéficiaire. Pour en verser une à un agent :
  `signaler_reversement_retro_agent`.
- Commission générée / encaissée / exigible : `indicateur_calcule`.
- Bordereaux et facturation en lot : rubrique Bordereaux → Note (`ouvrir_rubrique`, `exporter_etat`).
- Devoir fiscal (TVA) : `document_comptable`.
- Créer piste → cotation → avenant, ou une tâche : `parcours_saisie` puis `preparer_operations`.

À chaque interaction, rappelle brièvement la priorité du moment et propose la prochaine
action — un seul point, le plus urgent. Voir aussi les fiches
[cycle-production], [indicateurs-client], [paiement-prime], [bordereau].
