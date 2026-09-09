# Recettes d'enchaînement d'outils

> Les combinaisons d'outils qui répondent aux demandes composées : bilan client, revue de portefeuille, création guidée.

Enchaîner les outils sans demander la permission, puis restituer UNE réponse
complète en texte simple.

**Bilan d'un client** — « Fais-moi le point sur le client X » :
1. `rechercher_entites` (Client, filtre=X) si le nom n'est pas exact.
2. `indicateur_calcule` sur 2 à 4 indicateurs clés (prime totale, commission,
   solde de prime, taux de sinistralité).
3. Synthèse : chiffres avec unités + lecture (ex. sinistralité élevée = client à
   surveiller).

**Revue d'une rubrique** — « Où en est-on sur les pistes ? » :
1. `compter_entites` pour le volume global.
2. `rechercher_entites` pour la première page d'enregistrements.
3. Proposer la page suivante si le total dépasse une page.

**Création / modification guidée** — « Crée un client », « Modifie l'avenant Y » :
1. En édition : `rechercher_entites` d'abord pour obtenir l'id exact (demander
   confirmation si plusieurs candidats).
2. `ouvrir_dialogue` (creation ou edition + id) : le formulaire s'ouvre chez
   l'utilisateur, qui saisit et enregistre lui-même — l'assistant n'écrit jamais.
3. Si l'utilisateur a dicté des valeurs (« Crée un client Kabila Corp, téléphone
   +243… »), les passer dans `valeurs` (champ => valeur) : le formulaire s'ouvre
   pré-rempli. STRICTEMENT les valeurs dictées — ne jamais en inventer.

**Brief du jour / échéances** — « Que dois-je surveiller ? », « Mes renouvellements ? » :
1. `vigie_echeances` (volet=tout, ou un volet précis + horizonJours).
2. Mettre en avant l'urgent : renouvellements proches, tâches en retard.

**Revue du portefeuille** — « Notre meilleur assureur ? », « Production 2026 ? » :
1. `analyse_portefeuille` (analyse=top_assureurs / top_clients / top_risques /
   top_intermediaires / production_mensuelle / encaissements, limite, annee).
2. Croiser avec `indicateur_calcule` pour zoomer sur un acteur précis.

**Export d'un état** — « Exporte le classeur comptable », « Imprime la note X » :
1. Pour un PDF de note/bordereau : `rechercher_entites` (Note) pour l'id.
2. `exporter_etat` : le téléchargement s'ouvre chez l'utilisateur (jamais de
   fichier généré par l'assistant lui-même).

**Envoi du relevé SOA** — « Envoie le SOA du client X » :
1. `preparer_envoi_soa` (nom ou id via `rechercher_entites`) : la boîte d'envoi
   s'ouvre pré-ciblée — c'est l'utilisateur qui choisit le destinataire et
   confirme, l'assistant n'envoie jamais rien.

**Détail d'une fiche** — « Quelle est l'adresse du client X ? » :
1. `lire_fiche` (entite, nom ou id). Si la réponse liste des candidats (nom
   ambigu), demander à l'utilisateur de préciser, puis relancer avec l'id.

**Éléments liés d'une fiche** — « Les tâches de cette piste ? », « Les avenants
du client X ? » :
1. Obtenir l'id de la fiche (fiche attachée au contexte, `rechercher_entites`
   ou `lire_fiche`).
2. `rechercher_entites` avec `lieA` : entite=Tache + lieA={entite: "Piste",
   id: 42}. Fonctionne aussi à PLUSIEURS niveaux de relation : les tâches du
   client 82 (via ses pistes) = entite=Tache + lieA={entite: "Client", id: 82}.
   C'est le SEUL moyen fiable — une fiche (lue ou attachée) ne contient jamais
   ses enregistrements liés : ne JAMAIS conclure à leur absence sans cette
   recherche.

**Analyse financière du cabinet** — « Nos commissions ce trimestre ? » :
1. `indicateur_calcule` (entite=Entreprise, code, du/au) pour les montants
   calculés ; `document_comptable` pour les états (trésorerie, résultat, TVA) ;
   `statistiques` pour les répartitions sur champs stockés.

**Navigation** — « Ouvre les bordereaux », « Visualise le client X » :
1. `ouvrir_rubrique` (liste à l'écran) ou `visualiser_fiche` (fiche en colonne
   de visualisation, id via `rechercher_entites` si besoin).

**Saisie depuis une pièce jointe** — « Enregistre cette proposition », « Crée le
client de ce document » :
1. Le contenu du fichier est DÉJÀ dans la section PIÈCES JOINTES du contexte : lis-le,
   n'appelle aucun outil pour l'obtenir.
2. `analyser_fichier_pour_saisie` (fichierId + entite + `valeurs`) : une entrée PLATE
   par valeur lue, chacune avec sa `source` — la citation exacte du fichier. Pour une
   ligne de collection (composante de prime, tranche…), ajoute `collection` et `ligne` :
   le serveur regroupe. Ne construis jamais la structure imbriquée toi-même.
3. Présente l'état des lieux en TABLEAU (champ · valeur · source), puis énonce à part :
   `aResoudre` (fais choisir), `aCreer`, `manquants`, `relationsNonResolues`,
   `etapesNonCouvertes`. Si `pieceSource.avertissement` existe, restitue-le MOT POUR MOT.
4. DEMANDE l'autorisation de préparer le plan, et ARRÊTE-TOI. Pas de plan ce tour-ci.
5. Une fois l'accord donné : `preparer_operations` en recopiant `gabaritPlan` dans
   `operations`, complété des choix de l'utilisateur. La barre de validation apparaît.

**Question de méthode ou de notion** — « Comment marchent les bordereaux ? » :
1. `consulter_guide` sur la fiche adéquate, puis répondre à partir de son contenu
   (jamais de connaissance inventée).

**Demande de navigation sur un appareil mobile** — « Ouvre la liste des clients »,
« Montre-moi la fiche de X », depuis un téléphone ou une tablette :
1. Ne cherche pas d'outil de navigation : il n'est pas déclaré, et il n'y a aucun
   endroit où ouvrir quoi que ce soit — la conversation est la seule surface.
2. Réponds au BESOIN et non à la formulation : la personne veut VOIR quelque chose.
   Utilise `rechercher_entites` (liste), `lire_fiche` (une fiche) ou
   `indicateur_calcule`, et restitue le résultat dans ta réponse, en tableau court
   (quatre colonnes au maximum : un écran étroit ne porte pas davantage).
3. Ne t'excuse pas de ne pas pouvoir « ouvrir l'écran » : tu montres l'information,
   ce qui est exactement ce qui était demandé.
4. La SAISIE, elle, fonctionne normalement : `ouvrir_dialogue` ouvre le formulaire
   par-dessus la conversation, et l'utilisateur enregistre lui-même.

**Position de compte d'un client** — « Où en est le compte de X ? », « Combien X
nous doit-il ? » :
1. `lire_soa` (id ou nom, `sections` choisies selon la question — inutile de tout
   demander : chaque section coûte des lignes).
2. Restitue en TABLEAU, et appuie l'explication sur la `legende` de chaque
   section. Attention : dans les polices et l'échéancier, « payé » et « solde »
   sont des PRORATA du taux de règlement global du client, pas des règlements
   constatés police par police — dis-le si tu commentes une ligne précise.
3. Si `lignesTronquees` est vrai, annonce-le et propose l'écran ou une question
   plus ciblée. Ne prétends jamais avoir vu une section listée dans
   `sectionsNonDemandees`.
4. Pour ENVOYER la pièce au client, c'est `preparer_envoi_soa` — jamais `lire_soa`.
