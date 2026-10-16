---
name: reglementation-rdc-arca
description: Ce que JSBrokers implémente de la réglementation RDC (ARCA, DGI, OHADA) — taxes et taux semés, mentions légales, SYSCOHADA, ce qui est configurable par cabinet, référentiels pas encore implémentés. Invoquer dès qu'il est question d'ARCA, DGI, TVA, taxe, frais de surveillance, RDC, Congo, CDF, OHADA, SYSCOHADA, RCCM, Id.Nat, numéro d'impôt, agrément, licence, état prudentiel, bordereau réglementaire, barème ou taux de commission, ou d'une mention légale.
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
- **Appliqué depuis le 2026-10-16** (Acte uniforme OHADA relatif au droit comptable, SYSCOHADA
  révisé ; décisions de Serge du 2026-10-15) — détail et preuves dans `courtage-assurance` §5 :
  - **pièce justificative intangible** : une note validée a ses montants figés, ne se modifie
    plus et ne se supprime pas ; la correction passe par un avoir ;
  - **numérotation continue** : numéro attribué à la validation, sans trou ni doublon, par
    série (cabinet, année d'émission, débit/crédit), date d'émission jamais antérieure à la
    précédente ;
  - **conservation dix ans** : aucune purge d'une note émise sans remise d'une archive
    comptable à jour (PDF, lignes, paiements, journal, empreintes).
- **Homologation DGI des factures** : chantier DÉCIDÉ, ultérieur (§6, chantier 5). ⚠ **Règle
  d'ici là** : tout changement qui touche la VALIDATION d'une note ou son PDF doit rester
  compatible avec une homologation AU MOMENT DE LA VALIDATION et avec l'ajout ultérieur, sur la
  pièce, du QR code et des références fiscales.

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

### Constat : l'assiette de la commission face à l'Art. 2 (2026-10-15, lecture seule, rien corrigé)

L'Art. 2 exprime les taux en pourcentage de la **prime nette d'impôts et taxes** (hors TVA, hors
frais ARCA). Épreuve en mémoire sur une prime de 10 000 de prime nette + 1 000 de fronting + 500
de frais accessoires + 1 840 de TVA + 230 d'ARCA, risque à 15 %.

- **L'assiette est UN SEUL chargement : le « chargement cible » du type de revenu**, pas une
  somme de composantes (`IndicatorCalculationHelper::getRevenuMontantHt`, :1072-1099 →
  `getCotationMontantChargementPrime`, :1102-1115). Le taux s'y applique tel quel
  (`× getFraction()`), qu'il vienne du revenu, du type ou du risque
  (`RevenuCourtierPrescrit.php:295` ne fait qu'ANNONCER ce dernier).
- **Le type semé « Commission » (taux du risque) vise « Prime nette »**
  (`ServiceInitialisationEntreprise::initialiserChargementsEtRevenus`, :358-364) : sont
  **exclus** le fronting, les frais accessoires, la TVA et l'ARCA. Épreuve : 10 000 × 15 % =
  **1 500**. Consultance (5 %) et honoraire (2 %) visent aussi la prime nette ; la
  « Commission sur Fronting » (30 %) vise le seul fronting.
- **ACQUIS — lecture étroite de l'Art. 2 retenue (décision de Serge du 2026-10-15).** La base
  est la **prime nette seule**, hors fronting, hors frais accessoires et hors taxes, conforme à
  la pratique du marché : le réglage par défaut (« Commission » → « Prime nette ») est donc
  **conforme**. *À confirmer auprès de l'ARCA en cas de contrôle.* (La lecture large — toute la
  prime hors taxes — aurait donné 11 500 × 15 % = 1 725, soit 225 de plus dans l'épreuve ; elle
  n'est PAS retenue.)
- ✅ **Écart 1 RÉSOLU** (`310a724f`) : le sélecteur n'offre plus de taxe (ni de chargement
  d'un autre cabinet) et une garde serveur refuse, écran comme Ket (`AssietteDeLaCommissionTest`).
  Constat d'origine : **un chargement de TAXE pouvait servir d'assiette.** Le champ « Chargement cible »
  (`TypeRevenuType.php:56-62`) n'est filtré sur aucune fonction : un type à 10 % visant la TVA
  facture **184** de commission sur une taxe — contraire à l'Art. 2 dans toutes les lectures.
- ✅ **Écart 2 RÉSOLU** : une seule règle, `TarifDuRevenu`, dans l'ordre des écrans (celui qui
  a été facturé) ; la cascade de `Constante` est supprimée, Ket et l'export s'y alignent
  (`RevenuCourtierPrescritPariteTest` compare désormais Ket aux ÉCRANS). Constat d'origine :
  **deux cascades n'avaient pas le même ordre.** Les écrans et la facturation
  (`IndicatorCalculationHelper`, :1084-1096) appliquent le pourcentage du TYPE avant le taux du
  RISQUE ; `Constante::Revenu_getMontant_ht` (:913, :949-970) et l'annonce de Ket
  (`RevenuCourtierPrescrit::prescription`, :292-309) font l'inverse, comme le promet le
  formulaire (« Privilégier le taux de commission configuré pour le risque »). Un type qui
  porte les DEUX (rien ne l'interdit) : écrans **1 000** (10 %), Ket **1 500** (15 %).
  `RevenuCourtierPrescritPariteTest` compare Ket à `Constante`, jamais à
  `IndicatorCalculationHelper` : l'écart lui échappe. Le type semé n'a pas de pourcentage, d'où
  l'absence de symptôme par défaut. (Touche aussi l'invariant « une source unique par montant »
  de `courtage-assurance`.)
- ✅ **Écart 3 RÉSOLU** : l'assiette est la SOMME des lignes du chargement cible
  (`IndicatorCalculationHelper::getCotationMontantChargementPrime`) ; Ket additionnait déjà
  (`SaisirPropositionTool::assiettes`). Mesure en production du 2026-10-15 : **aucun cas**
  (Q1 à Q3 vides, aucun cabinet n'y travaille encore) — aucun historique à reprendre, dossier
  clos. Test :
  `AssietteSommeDesLignesTest`. Constat d'origine : deux lignes « prime nette » 10 000 + 4 000
  donnaient **1 500** à l'écran (première ligne seule), 2 100 attendus.

### Chantiers ultérieurs (CIBLE ou à décider — sans plan détaillé, rien à coder sans plan validé)

1. **Contrôle de fourchette, en AVERTISSEMENT et non en blocage.** Pour un cabinet soumis à
   l'ARCA, signaler un taux saisi hors de [minima ; maxima] de sa catégorie — le cabinet reste
   libre de son taux (§1). Exception : le régime de l'Art. 3, dont la commission ne se lit pas
   en pourcentage de la prime. Suppose les minima (point 4) et un rattachement fiable
   risque → catégorie du barème.
2. **Art. 3 — réassurance au-delà de 75 %** : commission de 25 à 35 % de la COMMISSION DE
   RÉASSURANCE obtenue sur la part cédée à un réassureur non établi en RDC (fourchette de
   l'Art. 1er sur la part non cédée). **Prioritaire pour les risques miniers et pétroliers**
   (barème à 1-5 %, souvent réassurés au-delà de 75 %). Rien ne modélise aujourd'hui la part
   cédée ni la commission de réassurance.
3. **Art. 4 et Art. 6.** Plafond du versement anticipé de la commission d'un contrat
   pluriannuel (3 fois la commission de la première année) ; ancien barème du 25 mai 2018 pour
   les contrats en cours non encore renouvelés (la date d'effet décide — texte de 2018 à
   fournir).
4. **Semer les minima** à côté des maxima (`risques_defaut.json` ne porte que le maxima).
5. **Intégration de l'API DGI : homologation des factures** — chantier DÉCIDÉ (Serge,
   2026-10-16), ULTÉRIEUR, sans plan. La DGI permet de raccorder un module de facturation à sa
   plateforme pour HOMOLOGUER chaque facture : elle rend un QR code et des références fiscales
   à porter sur la pièce. Remplace la question « facture normalisée : à confirmer ».
   - *Prérequis déjà acquis* (`courtage-assurance` §5) :
     - **numéro attribué à la validation**, continu, dans une transaction
       (`EmissionDeNote::valider`) — le point unique où brancher l'appel d'homologation ;
     - **montants figés** à la validation (HT, taxe, TTC, par ligne et au total —
       `FigeageDeNote`) : ce qui est homologué ne bouge plus ;
     - **intangibilité** d'une note validée (`NoteIntangible`, dernier rempart au flush) :
       une pièce homologuée ne se modifie ni ne se supprime ;
     - **un seul rendu du PDF** (`NotePdf`), partagé par le téléchargement et l'archive : le
       QR code et les références n'auront qu'un endroit où s'imprimer.
   - *Prérequis EN PARTIE acquis* : **avoirs liés**. Un avoir se mesure aux lignes figées des
     notes de débit émises sur le même revenu et la même échéance, et se ventile comme elles
     (`IndicatorCalculationHelper::getDebitsFigesDuRevenu`). Mais il ne porte AUCUNE
     référence explicite au numéro de la note qu'il annule : si la DGI exige qu'un avoir cite
     la facture d'origine, ce lien est à créer.
   - *À établir au plan* : le contrat de l'API (authentification, champs, délais) ; le
     comportement si la plateforme ne répond pas (valider quand même et homologuer ensuite,
     ou refuser la validation) — question métier, à trancher par Serge ; les colonnes qui
     garderont la réponse (QR code, références, date d'homologation), figées comme le reste ;
     les notes déjà émises (homologation rétroactive ou non) ; le périmètre (notes de débit
     portant de la TVA seulement, ou toutes).

Les écarts 1 à 3 du constat d'assiette sont devenus un chantier **prioritaire** de
`courtage-assurance` (« Assiette et source unique de la commission »), décision du 2026-10-15.

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
