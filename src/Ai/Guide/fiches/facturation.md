# Facturer une commission (note de débit)

> De la prime encaissée à la commission recouvrée : quand une commission devient exigible, comment on l'émet en note, et ce que porte une ligne de note.

Une commission de courtage ne se réclame pas n'importe quand, et pas à n'importe qui.
Cette fiche dit les quatre stades, et ce qui fait passer d'un stade au suivant.

## 1. La commission devient EXIGIBLE quand la prime est payée

Sur le marché par défaut, c'est **l'assureur** qui encaisse la prime auprès de
l'assuré. Le courtier ne touche donc rien à ce moment-là : il acquiert un **droit à
commission** sur l'assureur.

Ce droit naît quand la prime de l'échéance est **intégralement payée**. Tant qu'elle
ne l'est pas, la commission de cette échéance n'est pas exigible — la réclamer serait
prématuré.

- Dans la rubrique **Tranches**, c'est le chip « Commission exigible — X ».
- En conversation, c'est `suivi_impayes` avec les axes `{prime: payee, commission: impayee}`.

## 2. On l'émet en NOTE DE DÉBIT adressée à l'assureur

Une **note de débit** réclame un paiement ; une **note de crédit** accorde un avoir.
Le **destinataire** dit qui doit l'argent : l'assureur pour une commission de
courtage, le client pour des frais qu'on lui facture directement.

Ces deux choix ne se devinent jamais — ce sont des discriminants comptables. Mais ils
se **déduisent** quand on part de ce qu'on facture : réclamer la commission d'une
échéance, c'est émettre une note de débit à l'assureur de sa police.

Deux chemins, une seule règle (`App\Services\Note\SourceDeFacturation`) :

- **à l'unité** — depuis une échéance : bouton « Facturer la commission » de la
  rubrique Tranches, ou `preparer_facturation` en conversation ;
- **en lot** — depuis un bordereau validé : le bouton « Facturer » de l'atelier de
  rapprochement. La note porte alors les montants du bordereau et n'a pas de lignes.

## 3. Une LIGNE de note, c'est un revenu × une échéance × une quantité

C'est la pièce qui portait le plus de confusion. Une ligne (`Article`) **ne porte
aucun montant** : elle rattache

- un **revenu** — la commission à facturer, telle que la proposition l'a chiffrée ;
- l'**échéance** concernée, quand on facture à cette maille ;
- une **quantité**, à 1 par défaut.

Le montant se **dérive** de ces trois-là, et le total de la note est la somme de ses
lignes. Facturer depuis les revenus plutôt qu'à la main garantit que ce qui est
réclamé est bien ce que l'affaire a produit.

**Seuls les revenus qui restent dus sont proposés** : un revenu déjà encaissé n'a plus
rien à réclamer, et l'émettre produirait une note à zéro.

## 4. Puis la note se valide, et enfin s'encaisse

Une note naît **non validée**. La validation est un geste posé ensuite, par quelqu'un :
c'est elle qui fait entrer la note dans le suivi du recouvrement.

Le paiement, lui, se rattache à la note. Tant qu'il ne couvre pas le total, le solde
reste dû et l'échéance concernée continue d'afficher sa commission comme non encaissée.

## Ne pas confondre les deux stades

- **Commission exigible** : la prime est payée, on PEUT facturer. Rien n'est encore émis.
- **Commission facturée non encaissée** : la note existe et son solde reste dû. C'est
  l'objet du suivi de recouvrement (`App\Services\Note\NoteRecouvrementService`),
  qui ne compte que les notes de débit **validées** adressées aux assureurs.

Annoncer l'un pour l'autre fait croire à un encaissement qui n'a pas eu lieu, ou à un
travail de facturation déjà fait.
