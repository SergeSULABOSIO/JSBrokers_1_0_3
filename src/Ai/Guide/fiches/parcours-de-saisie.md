# Parcours de saisie : un objet métier, un plan, une validation

> Comment l'assistant accompagne une saisie structurante de bout en bout — le chemin complet, l'étendue choisie par vous, un budget unique et une seule validation.

Un enregistrement du métier vit rarement seul. Une **cotation** appelle la
ventilation de sa prime, son échéancier, la rémunération du courtier, puis le
contrat. Un **client** appelle ses interlocuteurs, puis l'opportunité qui le
concerne. Saisir tout cela morceau par morceau, c'est risquer l'oubli — une prime
sans ventilation reste à zéro, une composante sans type de chargement ne produit
aucune commission.

## La méthode

1. **Le chemin d'abord.** L'assistant présente le parcours ENTIER, étapes
   numérotées, en indiquant pour chacune ce que vous devez fournir, ce qu'il
   remplit lui-même (votre entreprise, vous, votre portefeuille) et ce qui est
   facultatif.
2. **Vous fixez l'étendue.** Une seule question : jusqu'où voulez-vous aller, et
   de quelles informations disposez-vous maintenant ? Une étape sans information
   est laissée de côté — vous la reprendrez plus tard, sans rien casser.
3. **Un seul plan, un seul budget.** Tout ce que vous avez accepté part dans un
   plan unique : tableau des opérations, impacts, et coût en tokens ventilé par
   étape. Vous pouvez encore décocher une étape facultative avant d'exécuter.
4. **Une seule validation.** L'exécution est atomique : soit l'ensemble est
   enregistré, soit rien ne l'est. Une suppression demande en plus votre mot de
   passe.

## Un seul plan en attente à la fois

Ce n'est pas une consigne de bonne conduite : c'est un **verrou**. Tant qu'un plan
attend votre décision, l'assistant ne PEUT pas en préparer un second — l'outil le
lui refuse. Vous ne vous retrouverez donc jamais avec deux barres « Valider et
exécuter » à trancher l'une après l'autre.

Vous gardez la main dans tous les cas :

- **Valider** : le plan s'exécute, la voie est libre pour la suite.
- **Annuler** : rien n'est écrit, la voie est libre.
- **« Non, plutôt ceci »** : dites-le simplement. L'assistant annule le plan en
  attente et vous en présente un nouveau — jamais deux en concurrence.

## Deux façons de rattacher les étapes

- **Les collections du formulaire** : ce que l'écran permet déjà d'ajouter dans la
  fiche (composition de la prime, tranches, revenus, avenants, documents, tâches).
  Elles vivent dans la même opération que l'enregistrement de tête.
- **Le chaînage par référence** : une entité que le formulaire n'expose pas
  (la piste d'un client, par exemple). L'assistant étiquette la première création
  et y renvoie depuis la suivante — les deux sont créées dans le même plan, alors
  qu'aucun identifiant n'existait au moment de la validation.

## Points de vigilance métier

- **Composition de la prime** : chaque composante porte un nom, un montant et un
  TYPE de chargement. Sans le type, la commission ne peut pas se calculer.
- **Revenu du courtier** : le type de revenu est obligatoire — c'est lui qui porte
  le taux et la base de calcul.
- **Portefeuille** : un client sans portefeuille n'apparaît pas dans la vue « Mon
  portefeuille ». L'assistant l'y range automatiquement si vous n'en gérez qu'un,
  sinon il vous demande lequel.
- **Droits** : une étape que votre périmètre n'autorise pas n'est jamais proposée.
