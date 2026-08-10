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

## Ce que vous lisez est ce qui sera écrit

Sous chaque plan, l'interface affiche — à partir des données du serveur, et non
du texte rédigé par l'assistant — deux listes :

- **« Ce plan va enregistrer »** : chaque enregistrement qui sera créé, modifié ou
  supprimé, avec le décompte par rubrique.
- **« Rien ne sera enregistré pour… »** : ce que le plan ne couvre pas. Une rubrique
  que vous croyiez incluse et qui apparaît dans cette seconde liste n'est PAS dans le
  plan — dites-le avant de valider. Le revenu de courtage, lui, ne devrait plus jamais
  y figurer : il est ajouté d'office (voir ci-dessous). S'il y figure, c'est le signe
  d'une configuration incomplète, et l'assistant vous dira laquelle.

Après exécution, le journal énumère ligne par ligne ce qui a réellement été écrit.
C'est la seule source de vérité : rien n'est jamais « généré automatiquement » en
plus du plan. Si l'assistant affirme le contraire, faites-lui vérifier — il a
l'obligation de s'appuyer sur ce journal, pas sur un raisonnement.

## Quand le parcours part d'un document

Si vous attachez la pièce au chat — une proposition, une facture — vous n'avez rien à
dicter : l'assistant lit le document et remplit le parcours lui-même. Il s'arrête alors
une fois de plus qu'à l'ordinaire, et c'est voulu. Avant tout plan, il vous montre un
**état des lieux** : chaque valeur qu'il a retenue, avec la phrase du document dont elle
vient ; ce qui est ambigu, à trancher par vous ; ce qui manque ; ce qui sera créé au
passage ; ce que le document ne couvre pas. Vous autorisez, puis le parcours reprend son
cours normal — plan, budget, étendue, validation.

La pièce elle-même suit la donnée : elle est classée dans les « Documents » de
l'enregistrement créé. Sur une rubrique qui n'a pas de collection Documents, l'assistant
vous prévient avant la validation que le fichier ne sera pas conservé en base.

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
- **Revenu du courtier : ajouté d'office.** Aucune proposition ne se place sans
  commission, donc l'assistant ne vous la demande plus. Il retient les types de revenu
  **dus par l'assureur** dont le chargement de base figure dans la prime que vous avez
  dictée — la « Commission Ordinaire » sur la prime nette, et la « Commission sur
  Fronting » seulement si vous avez dicté une ligne de fronting. Les frais payés par
  le **client** (consultance, honoraires de gestion) se négocient : ils restent sur
  demande explicite.
  - **Le taux vient de la configuration du risque.** Rien n'est recopié dans le revenu :
    le taux prescrit sur la fiche du risque (« % commission spécifique HT ») s'applique
    à la lecture, et la commission suivra ce taux s'il change demain. À défaut, c'est
    celui du type de revenu. L'assistant vous annonce lequel il a appliqué, et le montant.
  - **Un taux jamais inventé.** Si le risque ne prescrit aucun taux et que le type n'en
    porte pas, l'assistant ne présente PAS de plan : il vous demande le taux, en nommant
    le risque concerné. Une commission écrite à zéro se lirait plus tard comme une
    affaire sans rémunération.
- **Échéancier : payable en une fois.** Sauf mention contraire, la prime fait une seule
  tranche à 100 % à la date de prise d'effet. Le fractionnement est l'exception : dites
  combien de tranches, et l'assistant les répartit sans perdre un centime sur l'arrondi.
- **Taux en points** : un taux s'écrit et se stocke en POINTS — 15 pour 15 %, jamais
  0,15. C'est la même valeur à l'écran, dans la fiche et dans le plan : l'assistant
  reprend celle que vous dites, sans conversion.
- **Champs à liste fermée** : beaucoup de champs n'acceptent qu'une valeur parmi
  quelques-unes — le type d'un avenant, le type d'une note et son destinataire, la
  fonction d'un chargement, le redevable d'une taxe. L'assistant connaît la liste et
  son sens : il vous propose les options en clair plutôt que de poser une question
  ouverte, et il ne laisse plus ces champs vides.
  - Quand une valeur va de soi, elle est déjà posée et l'assistant vous l'annonce
    (« Statut de la police : En cours »). Vous n'avez qu'à la changer si besoin.
  - Quand le choix ne peut appartenir qu'à vous, il est DEMANDÉ, jamais deviné :
    débit ou crédit, souscription ou renouvellement, taxe due par le courtier ou par
    l'assureur. Une valeur plausible mais fausse coûte plus cher qu'une question.
- **Valeurs lues dans un document** : si une pièce jointe indique « Renouvellement »
  là où la base attend un code, l'assistant fait la correspondance. S'il ne la trouve
  pas, ou si elle est ambiguë, il vous montre ce qu'il a lu et les valeurs possibles —
  il ne tranche pas à votre place.
- **Portefeuille** : un client sans portefeuille n'apparaît pas dans la vue « Mon
  portefeuille ». L'assistant l'y range automatiquement si vous n'en gérez qu'un,
  sinon il vous demande lequel.
- **Droits** : une étape que votre périmètre n'autorise pas n'est jamais proposée.
