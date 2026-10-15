---
name: reglementation-rdc-arca
description: Ce que JSBrokers implémente de la réglementation de la RDC (ARCA, DGI, OHADA) — taux et taxes semés, mentions légales, comptabilité SYSCOHADA — avec leurs preuves, ce qui est configurable par cabinet, les règles RDC appliquées à tous les cabinets, et les référentiels externes pas encore implémentés. Invoquer dès qu'une taxe, un taux réglementaire, une mention légale, un état ou une règle propre à la RDC est en jeu.
trigger when: >
  Déclencher AUTOMATIQUEMENT dès qu'une tâche mentionne : ARCA, DGI, TVA, taxe, frais de
  surveillance, RDC, Congo, CDF, OHADA, SYSCOHADA, RCCM, Id.Nat, numéro d'impôt, agrément,
  licence, état prudentiel, bordereau réglementaire, barème de commission, taux de commission.
---

# Skill — Réglementation RDC / ARCA dans JSBrokers

**Règle cardinale : ces règles valent pour les cabinets soumis à l'ARCA. Jamais en dur pour
tous.** Une nouvelle règle RDC se pose dans la CONFIGURATION du cabinet (entités Taxe,
AutoriteFiscale, Chargement, Risque), pas dans le code commun. Ce qui n'est pas prouvé par le
code est marqué **À CONFIRMER**.

**Le produit définit des COMPORTEMENTS PAR DÉFAUT, jamais des valeurs imposées** (décision de
Serge du 2026-10-14) : taux de taxes, taux de commission, branches — tout ce qui est semé reste
**éditable par chaque cabinet**, qui l'ajuste selon son pays.

**Règle du taux de commission** (décision de Serge du 2026-10-14) : le taux de commission est
**choisi par chaque cabinet dans la fourchette légale**. Aucune politique propre à un cabinet
(par exemple « toujours le maxima ») ne devient une règle du produit : le code propose, borne
et contrôle — il ne choisit pas à la place du cabinet.

## 1. Ce que le code sème à la création d'un cabinet

`ServiceInitialisationEntreprise` (appelé par `ServiceProvisionEntreprise.php:66`) :

| Élément | Valeur | Preuve | Configurable ensuite ? |
|---|---|---|---|
| TVA (DGI), à charge de l'assureur | 16 % IARD / 0 % VIE — **pas de TVA sur la VIE, confirmé** (2026-10-14) | `ServiceInitialisationEntreprise.php:290-306` | oui (entité `Taxe` du cabinet) |
| Frais ARCA, à charge du courtier | 2 % IARD / 2 % VIE — **confirmé** (2026-10-14) | :308-318 | oui |
| Chargements « TVA », « ARCA » (frais de surveillance) | — | :343-351 | oui |
| Commissions par défaut | fronting 30 %, consultance 5 %, honoraire 2 % | :365-387 | oui |
| 43 risques et leur taux de commission « Maxima HT » (5 à 25 %) | `assets/data/risques_defaut.json` | :392-405 | oui — ce sont des **valeurs de départ**, pas une politique : le cabinet choisit son taux (règle ci-dessus). Ils égalent, un à un, les **maxima du barème ARCA 005/24** (constat du §6) ; le fichier ne cite toujours pas sa source. |
| Monnaie locale | déduite du pays, posée en **référence** (capital social, mentions légales, taux) ; la monnaie de **travail** par défaut est l'USD (décision du 2026-10-15) | `initialiserMonnaies` (`ServiceGeographie`) ; `MonnaieDeTravail` | oui — la monnaie de travail tant qu'aucun montant n'est saisi |

⚠ **Le pays n'est pas consulté pour les taxes** (`initialiserTaxes`, :294) : un cabinet hors RDC
reçoit aussi DGI / TVA 16 % / ARCA 2 %.

## 2. Le moteur de taxes

- Deux redevables seulement — l'assureur (TVA) et le courtier (ARCA), une taxe par redevable
  (`ServiceTaxes.php:71-113`, :96-98). Les TAUX viennent de la configuration du cabinet.
- Exonération : client exonéré OU risque non imposable → aucune taxe sur commission
  (`ServiceTaxes.php:139-179`, `CommissionExonereeTest`).
- Le suivi fiscal reverse à la DGI et à l'ARCA d'après les autorités du cabinet
  (`CourtierSuiviFiscalService.php:156`).

## 3. Mentions et identifiants légaux

- `rccm`, `idnat`, `numimpot` sur Entreprise (`Entreprise.php:44-47`), Client, Assureur,
  Partenaire ; RCCM seul sur Fournisseur.
- Pied de page de tous les PDF : licence, RCCM • Id.Nat • N° Impôt (`MyCustomTCPDF.php:47-54`) ;
  note de débit (`tcpdf_note.html.twig:139-194`) ; relevé de compte SOA (`_soa_footer.html.twig:10`).
- « Numéro d'agrément » = champ libre `licence` (`messages.fr.yaml:486-487`) : **aucun contrôle
  d'un agrément ARCA**.

## 4. Comptabilité OHADA / SYSCOHADA

- Plan comptable (classe 6) en constante (`Charge.php:29-31`, `PlanComptable.php:8-89`) ; états
  SYSCOHADA (journal, grand-livre, balance, TFR, bilan, TFT — `DocumentsComptablesBuilder.php`).
- Comptes fixés en dur : 6611, 632 (`RetroAgentController.php:344`, `AgentRetro.php:131`).
- OHADA couvre 17 pays ; **un cabinet hors OHADA n'a pas d'alternative**.

## 5. Règles RDC appliquées à TOUS les cabinets (constat, non corrigé)

1. TVA 16 % / DGI et ARCA 2 % semées sans regarder le pays (`ServiceInitialisationEntreprise.php:294-351`).
2. Libellés « Taxe Courtier (ARCA) » / « Taxe Assureur (TVA) » figés dans dix canevas d'indicateurs
   (ex. `CotationEntityCanvasProvider.php:102-104`).
3. « 16 % » et la liste TVA 0,16 / ARCA 0,02 de l'éditeur de facture
   (`editor.html.twig:207`, `invoice_editor_controller.js:80`).
4. Consignes de Ket « TVA 16 % / ARCA 2 % par défaut » (`AiContextBuilder.php:1550-1565`, :864-871).
5. Plan comptable SYSCOHADA imposé (`Charge.php:31`).
6. Mentions RCCM / Id.Nat / N° Impôt imposées sur les PDF et le SOA.
7. Modèle à deux redevables, une taxe chacun (`ServiceTaxes.php:96`).

Toute évolution qui touche ces sept points doit les rendre configurables, pas les étendre.

## 6. Référentiels externes — PAS ENCORE IMPLÉMENTÉS

Ces textes existent hors du code ; JSBrokers ne les implémente pas aujourd'hui. Ne jamais
présenter une valeur du code comme leur application.

| Référentiel | État dans le code | Suite |
|---|---|---|
| **Barème ARCA des commissions** (Règlement n° 005/24) | non implémenté : seuls les MAXIMA sont semés comme taux par défaut (`risques_defaut.json`, sans citer sa source) ; ni minima, ni contrôle de fourchette, ni régimes des Art. 3 à 6 | **Fichier de référence : [`references/bareme-arca-005-24.md`](references/bareme-arca-005-24.md)** — transcription **à relire contre le texte officiel** (À CONFIRMER, Serge) avant tout usage dans le code. Il borne la fourchette légale dans laquelle chaque cabinet choisit son taux |
| **États prudentiels** à remettre à l'ARCA | absents (les bordereaux existants sont commerciaux) | non planifié |
| **Loi n° 15/005** (Code des assurances), statut de « courtier agréé » | absents | non planifié |

### Constat : les 43 risques semés face au barème 005/24 (2026-10-15, rien corrigé)

Comparaison ligne à ligne de `assets/data/risques_defaut.json` au fichier de référence (14
catégories Vie + 29 IARD = 43, « Risques pétroliers » en doublon dans le texte comptée une fois).

- **Ce sont les maxima, tous les 43.** Chaque taux égale le maxima de sa catégorie (5 à 25 %),
  et aucun n'est hors de la fourchette [minima, maxima]. Le fichier suit l'ordre des deux tableaux
  de l'Art. 1er. Les minima ne sont semés nulle part.
- **Barème de 2024, sans indice d'un barème antérieur.** Les 43 valeurs coïncident avec le
  005/24, y compris ses distinctions fines (Incendie *MRH* 25 % / *Autres* 16 %, Accidents
  corporels et Maladies *Individuelle* / *Groupe*, Automobile *RC* / *Dommages* et *Flotte*
  11 %). Le fichier est né le 2026-06-16 (`0eb7b06d`), après la signature du règlement
  (2024-12-20). ⚠ Le texte du 25 mai 2018 n'étant pas en référence, on ne peut pas exclure que
  certaines valeurs y soient identiques : la coïncidence prouve l'accord avec 2024, pas
  l'absence de reprise de 2018.
- **Aucun risque sans catégorie, aucune catégorie sans risque** : correspondance 1 pour 1. Deux
  écarts de LIBELLÉ seulement, sans effet sur le taux : `IARD-ASS-VOY` « Assistance - Voyage »
  (le barème dit « Assistance », sans restriction au voyage — le libellé semé est plus étroit
  que la catégorie) ; `IARD-AGRI` « Risque Agricole » (barème : « Risques agricoles »). Les
  autres différences ne tiennent qu'aux articles (« des Véhicules… »).
- **Hors de ce constat, à vérifier séparément** : l'assiette (Art. 2 : prime nette d'impôts et
  taxes) à laquelle le taux du risque est appliqué (`RevenuCourtierPrescrit.php:295`), et les
  régimes que le semis ignore — réassurance > 75 % (Art. 3), plafond pluriannuel (Art. 4),
  date d'effet et ancien barème pour les contrats non renouvelés (Art. 6).

## 7. Défauts confirmés pour la branche VIE (2026-10-14)

- Les frais **ARCA de 2 % s'appliquent aussi à la VIE** ; il n'y a **pas de TVA sur la VIE**.
- Ce sont des **défauts**, déjà semés ainsi (§1) : chaque cabinet peut les modifier.

## 8. Réflexes

1. La règle est-elle RDC/ARCA, ou générale ? Si RDC : configuration du cabinet, jamais code commun.
2. Le taux vient-il de l'entité `Taxe` du cabinet ? Jamais un 16 % ou un 2 % écrit en dur.
3. Un taux de commission : le cabinet le choisit, dans la fourchette légale — le code ne choisit pas pour lui.
4. Une mention légale nouvelle : par cabinet, et visible là où les autres le sont (PDF, SOA).
5. Une référence à un texte (005/24, 15/005, états prudentiels) : vérifier qu'il est implémenté
   avant de l'invoquer — aujourd'hui, il ne l'est pas.
