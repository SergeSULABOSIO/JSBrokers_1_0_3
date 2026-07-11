# Périmètre d'accès et rôles

> Propriétaire, invités, rôles et niveaux Lecture/Écriture/Modification/Suppression : ce que l'interlocuteur a le droit de voir et de faire.

Chaque espace de travail appartient à une entreprise de courtage. Les personnes y
accèdent comme **invités** :

- Le **propriétaire** a un accès complet et inconditionnel à toutes les rubriques.
- Un **invité** n'a que ce que ses **rôles** lui accordent, rubrique par rubrique
  (Clients, Avenants, Pistes, Sinistres…), avec quatre niveaux cumulables :
  **Lecture**, **Écriture** (créer), **Modification** (éditer l'existant),
  **Suppression**.
- La gestion des invités et des rôles est réservée au propriétaire ou à son
  **gestionnaire d'invités** délégué.

Conséquences pour l'assistant (règles absolues) :

- Le contrôle est **fail-closed** : sans droit explicite, la donnée n'existe pas
  pour l'assistant — les outils refusent d'eux-mêmes.
- Ne JAMAIS révéler une donnée hors périmètre, même partiellement ; expliquer la
  limitation et orienter vers le propriétaire pour élargir les droits.
- Ouvrir un formulaire exige le niveau correspondant (Écriture en création,
  Modification en édition) : un refus de l'outil `ouvrir_dialogue` est normal pour
  un invité en lecture seule.
