# Référence — Barème ARCA des commissions de courtage (Règlement n° 005/24)

> Fichier de référence de la skill `reglementation-rdc-arca`. Il reproduit un texte
> réglementaire externe : **ce n'est pas une règle implémentée dans JSBrokers**.
> Il borne la fourchette légale dans laquelle **chaque cabinet soumis à l'ARCA choisit son taux**.
> Aucune politique de cabinet (par exemple « toujours le maxima ») n'en découle pour le produit.
>
> **À CONFIRMER (Serge)** : relecture contre le texte officiel avant tout usage dans le code.

## Source

- *Règlement n° 005/24 modifiant et complétant le règlement du 25 mai 2018*, Conseil
  d'Administration de l'ARCA, fait à Kinshasa le 20 décembre 2024.
- Transmis à l'ACAC le 24 mars 2025 (réf. ARCA/DG/0203/MMR/KNA/2025).
- Entrée en vigueur à la date de signature (Art. 9).

## Règles d'application

- **Base de calcul (Art. 2)** : les taux s'expriment en pourcentage de la **prime nette d'impôts
  et taxes** (prime hors taxes).
- **Réassurance supérieure à 75 % (Art. 3)**, sauf branches 4, 5, 6, 11 et 12 de l'article 402
  du Code des assurances :
  - part cédée à un réassureur **non établi en RDC** : commission de courtage comprise entre
    **25 % et 35 % de la commission de réassurance obtenue** (et non un pourcentage de la prime) ;
  - part non cédée : fourchette de l'Art. 1er (tables ci-dessous).
- **Contrats pluriannuels (Art. 4)** : le versement anticipé de la commission d'apport est
  plafonné à **3 fois la commission de la première année**.
- **Interdiction de surcharge (Art. 5)** : aucune sur-commission ni frais additionnel au-delà du
  maxima. Un taux effectif supérieur au maxima est une non-conformité.
- **Application dans le temps (Art. 6)** : contrats souscrits à partir de la signature, et
  contrats en cours **lors de leur renouvellement**. Un contrat en cours non encore renouvelé
  peut rester soumis à l'ancien barème du 25 mai 2018 : la date d'effet compte.
- **Abrogation (Art. 8)** : les dispositions antérieures contraires sont abrogées.
- **Sanctions (Art. 7)** : celles du Règlement n° 002/23.

## Art. 1er — Taux minima et maxima

### Assurances Vie

| Catégorie | Minima | Maxima |
|---|---:|---:|
| Individuelles – Contrat en cas de décès | 10,0 % | 15,0 % |
| Individuelles – Contrat en cas de vie | 10,0 % | 15,0 % |
| Individuelles – Mixtes | 10,0 % | 15,0 % |
| Individuelles – Épargne | 1,0 % | 5,0 % |
| Individuelles – Titres de capitalisation | 1,0 % | 5,0 % |
| Individuelles – Complémentaire | 1,0 % | 5,0 % |
| Individuelles – Autres opérations | 1,0 % | 5,0 % |
| Collectives – Contrat en cas de décès | 10,0 % | 15,0 % |
| Collectives – Contrat en cas de vie | 10,0 % | 15,0 % |
| Collectives – Mixtes | 1,0 % | 5,0 % |
| Collectives – Épargne | 1,0 % | 5,0 % |
| Collectives – Titres de capitalisation | 1,0 % | 5,0 % |
| Collectives – Complémentaire | 1,0 % | 5,0 % |
| Collectives – Autres opérations | 1,0 % | 5,0 % |

### Assurances Incendie, Accidents et Risques Divers (IARD)

| Catégorie | Minima | Maxima |
|---|---:|---:|
| Accidents corporels – Individuelle | 20,0 % | 25,0 % |
| Accidents corporels – Groupe | 15,0 % | 20,0 % |
| Maladies – Individuelle | 5,0 % | 10,0 % |
| Maladies – Groupe | 5,0 % | 8,0 % |
| RC véhicules terrestres à moteur – Individuelle | 10,0 % | 12,5 % |
| RC véhicules terrestres à moteur – Flotte | 9,0 % | 11,0 % |
| Dommages et autres risques véhicules terrestres – Individuelle | 10,0 % | 17,5 % |
| RC véhicules maritimes, lacustres et fluviaux | 10,0 % | 15,0 % |
| Dommages et autres risques véhicules maritimes, lacustres et fluviaux | 10,0 % | 15,0 % |
| RC résultant de l'exploitation des aéronefs | 7,5 % | 10,0 % |
| Dommages et autres risques des aéronefs | 7,5 % | 10,0 % |
| RC véhicules ferroviaires | 10,0 % | 15,0 % |
| Dommages et autres risques véhicules ferroviaires | 10,0 % | 15,0 % |
| Marchandises transportées | 10,0 % | 15,0 % |
| Incendie et éléments naturels – MRH | 20,0 % | 25,0 % |
| Incendie et éléments naturels – Autres | 10,0 % | 16,0 % |
| Responsabilité civile générale | 10,0 % | 15,0 % |
| Engineering | 12,0 % | 17,5 % |
| Crédit | 10,0 % | 15,0 % |
| Caution | 10,0 % | 15,0 % |
| Pertes pécuniaires diverses | 10,0 % | 15,0 % |
| Protection juridique | 15,0 % | 20,0 % |
| Assistance | 15,0 % | 20,0 % |
| Tous risques informatiques | 12,0 % | 17,5 % |
| Autres dommages aux biens | 10,0 % | 16,0 % |
| Risques et violences politiques | 10,0 % | 15,0 % |
| Risques agricoles | 7,5 % | 10,0 % |
| Risques miniers | 1,0 % | 5,0 % |
| Risques pétroliers | 1,0 % | 5,0 % |

## Notes de lecture

- « Risques pétroliers » figure deux fois, à l'identique, dans le texte original : doublon de
  saisie, une seule catégorie.
- Distinctions à ne pas confondre : Incendie *MRH* et *Autres* ; Accidents corporels et Maladies
  *Individuelle* et *Groupe* ; Automobile *RC* et *Dommages*, *Individuelle* et *Flotte*.
- Risques miniers et pétroliers : fourchette très basse (1 à 5 %). Ces risques sont souvent
  réassurés à plus de 75 % : vérifier d'abord le régime de l'Art. 3.
- Ce barème remplace celui du 25 mai 2018 pour les contrats nouveaux ou renouvelés après sa
  signature (Art. 6 et 8).
