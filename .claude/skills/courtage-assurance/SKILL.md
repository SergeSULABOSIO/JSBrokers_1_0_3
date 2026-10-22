---
name: courtage-assurance
description: Le métier du courtage d'assurance dans JSBrokers — glossaire terme → entité, cycle piste → police → facturation → encaissement → rétrocession, règles par type d'avenant, invariants prouvés, chantiers décidés. Invoquer avant tout plan touchant piste, cotation, prime, chargement, commission, revenu, tranche, échéance, avenant, police, renouvellement, prorogation, annulation, résiliation, incorporation, note de débit ou de crédit, avoir, facturation, encaissement, paiement, rétrocommission, reversement, partenaire, taxe, sinistre ou monnaie.
---

# Skill — Le courtage d'assurance dans JSBrokers

Chaque affirmation porte sa preuve (fichier:ligne, test). Ce qui n'est pas prouvé par le code
est marqué **À CONFIRMER** : ne jamais l'appliquer comme une règle avant la réponse. Une
**décision** de Serge non encore codée est écrite comme un invariant CIBLE, avec l'état actuel
et l'écart — jamais présentée comme acquise.

## 1. Glossaire — le mot du métier, l'entité du code

| Métier | Entité | L'essentiel |
|---|---|---|
| Piste (opportunité) | `Piste` (`src/Entity/Piste.php:15`) | client, risque, type d'avenant, prime/commission potentielles ; `avenantDeBase` (:136) pour une piste dérivée |
| Proposition, offre | `Cotation` (`Cotation.php:14`) | assureur, durée (mois) ; chargements, revenus, tranches, avenants |
| Composante de prime | `ChargementPourPrime` (`ChargementPourPrime.php:17`) | type (`Chargement` : prime nette, fronting, frais admin, taxe — `Chargement.php:87-90`), montant |
| Commission du courtier | `RevenuPourCourtier` (`RevenuPourCourtier.php:13`) + `TypeRevenu` (`TypeRevenu.php:31`) | redevable (client, assureur, réassureur, partenaire — :68-71), mode pourcentage ou forfait (:47-48) |
| Échéance | `Tranche` (`Tranche.php:14`) | pourcentage, `payableAt`, régularisation ; articles, paiements de prime, reversements |
| Police, avenant | `Avenant` (`Avenant.php:15`) | période, référence de police, numéro, statut de renouvellement (:21-22, :167-171) |
| Note de débit / de crédit (avoir) | `Note` (`Note.php:14`) | type DÉBIT/CRÉDIT (:37-38), destinataire client/assureur/partenaire/autorité fiscale (:44-47), `validated` |
| Ligne de note | `Article` (`Article.php:18`) | tranche, revenu facturé |
| Encaissement d'une note | `Paiement` (`Paiement.php:14`) | note, montant, compte bancaire |
| Prime payée par l'assuré | `PaiementPrime` (`PaiementPrime.php:23`) | tranche, montant — **c'est ce paiement qui rend la commission exigible** |
| Rétrocommission | `ConditionPartage` (`ConditionPartage.php:15`) | partenaire OU agent, formule, seuil, taux, critère de risque |
| Reversement (rétro payée) | `ReversementRetroAgent` (`ReversementRetroAgent.php:56`) | hors Note/Article/Paiement (:27-29) |
| Intermédiaire | `Partenaire` (`Partenaire.php:15`) | part habituelle |
| Bordereau | `Bordereau` (`Bordereau.php:13`) | relevé de production d'un assureur, importé |
| Sinistre | `NotificationSinistre`, `OffreIndemnisationSinistre` | déclaration ; offre et paiement d'indemnité |
| Taxe, autorité fiscale | `Taxe` (`Taxe.php:35`), `AutoriteFiscale` | taux IARD / VIE, redevable assureur ou courtier |
| Monnaie | `Monnaie` (`Monnaie.php:58-61`) | fonction saisie / affichage / les deux |

## 2. Le cycle de vie

| Étape | Ce qui la fait naître | Écran | Ket |
|---|---|---|---|
| Piste « À coter » → « En cotation » → « Offres chiffrées » | `PisteTransformationScope::etapeDe` (:122-130) ; chiffrée = prime ≥ 0,005 (:55) | `/admin/piste`, vue Cartes, `api.action_pipe` | `saisir_proposition`, `modifier_composition_prime` |
| Premier avenant (souscription, incorporation) | `DefautsDeSouscription::pour` (:251) | dialogue Avenant sous la cotation | `souscrire_cotation` |
| Mouvement (renouvellement, prorogation, annulation, résiliation) | `MouvementAvenantBuilder::construire` (:121) — adopte la piste dérivée déjà montée | « Mouvements de la police », carte du pipe | `preparer_mouvement_avenant` |
| Échéancier | tranches de la cotation ; « Tranche unique » 100 % par défaut (`CotationController.php:96`, :147) | `/admin/tranche` | — |
| Facturation | Note + Articles ; numéro `ND-`/`NC-AAAA-NNNNN` pris à l'écriture (`NumerotationDesNotesListener`, §5) | `facturation_picker` (`NoteController.php:167`) | `preparer_facturation` |
| Créance | note **validée**, débit ou crédit, adressée au client ou à l'assureur → D 411 / C 706 + C 443 (`CourtierEcritureComptableService.php:33-34`, :193-209) | — | — |
| Encaissement | `Paiement` de la note → D 521/571 / C 411 (:35) ; prime de l'assuré : `PaiementPrime` | `/admin/paiement`, `/admin/paiementprime` | `signaler_paiement_prime` ; règlement d'une note : pas d'outil dédié, `preparer_operations` crée le `Paiement` (`CouvertureDesEcrans.php:81-84`, `ui:note.paiement-request`) |
| Rétrocession | `ConditionPartage` ; exigible à proportion de l'encaissé | `/admin/retro-agent`, `reversement_picker` | `retrocommissions`, `signaler_reversement_retro_agent` |

## 3. Le type de mouvement décide de tout

| Type (`Piste::AVENANT_*`, `Piste.php:21-26`) | Dates (règle vivante) | Chemin | Prime au premier avenant |
|---|---|---|---|
| Souscription (0) | effet aujourd'hui (ou dicté), fin = effet + durée − 1 jour (`DefautsDeSouscription::pour`) | formulaire d'avenant | **exigée** |
| Incorporation (1) | effet aujourd'hui, fin = échéance de la police de base ; sans base ou base échue : règle de souscription, annoncée | formulaire d'avenant | exemptée (décision du 2026-10-13 : santé groupe forfaitaire, flotte régularisée) |
| Prorogation (2) | lendemain de l'échéance ; durée dictée, sinon celle de la proposition adoptée (`MouvementAvenantBuilder::periode`) | mouvement de la police, en adoption | **exigée** |
| Annulation (3) | effet = fin = date demandée | mouvement, en adoption | exemptée |
| Résiliation (4) | effet = fin = date demandée | mouvement, en adoption | exemptée |
| Renouvellement (5) | lendemain de l'échéance ; durée de la proposition adoptée, sinon celle de la base ; période déjà échue → date demandée | mouvement, en adoption | **exigée** |

Effets d'un mouvement : statut de la base (`MouvementAvenant::statutDeLaBase`, :98-106),
échéances postérieures « à régulariser » pour annulation/résiliation
(`RegularisationDesEcheances`), tâche de suivi du paiement pour renouvellement/prorogation.
Un seul avenant par piste de mouvement (précondition `premierAvenant`, sous verrou).
L'ancienne `Constante::calculerPeriodeCouverture`, morte et contraire à ces règles, a été
supprimée (§7) : ces règles n'ont plus qu'un exemplaire.

## 4. Invariants métier — à citer dans tout plan

| Invariant | Preuve | Test |
|---|---|---|
| **À facturer ≠ à encaisser.** Facturable = TTC − déjà facturé ; un avoir rend le revenu de nouveau facturable ; « rien à facturer » n'est pas « rien à encaisser » | `SourceDeFacturation.php:158-203`, :264-285, :392-413 | `SourceDeFacturationTest`, `FacturationEcritureTest::testFacturerUnePartLaisseLeResteFacturable` |
| **Prime exigée selon le type** (§3), au PREMIER avenant seulement ; type inconnu → exigée | `DefautsDeSouscription.php:41-48`, :101-118 | `AvenantPrimeExigeeTest`, `DefautsDeSouscriptionTest::testLaPrimeExigee` |
| **Dates selon le type** (§3) | `DefautsDeSouscription::pour`, `MouvementAvenantBuilder::periode` | `DefautsDeSouscriptionTest::testLaRegle`, `MouvementAvenantTest`, `AdoptionParLeMouvementTest` |
| **Le responsable d'une piste** = gestionnaire du portefeuille du client, sinon son créateur ; c'est lui qui la voit dans « Mon portefeuille » — **elle et tout ce qui en descend** : cotations, polices, tranches, paiements de prime, tâches, retours et documents rattachés à la piste (décision du 2026-10-14). Ce qui ne dépend que du CLIENT (le client, ses sinistres, un document du client) garde la règle du portefeuille client. La règle ne fait qu'ajouter : personne ne perd ce qu'il voyait. Liste, Ket, vigie (polices, tâches) et suivi des paiements de prime lisent le même registre | `ResponsableDePiste.php:22-38` ; `PortefeuilleScope::CHEMINS_VERS_LA_PISTE` | `ResponsableDePistePariteTest`, `PortefeuilleDescendantsDePisteTest` |
| **Une source unique par montant** : commission TTC = `getCotationMontantCommissionTtc` ; taxe via `ServiceTaxes`. Le TARIF d'une commission (taux du revenu → forfait du revenu → % du type → forfait du type → taux du risque, ordre facturé) vit dans `TarifDuRevenu`, seul lu par les écrans, la facturation, la fiche, l'export et l'annonce de Ket ; une taxe ne sert jamais d'assiette, et l'assiette est la SOMME des lignes du chargement cible (2026-10-15) | `IndicatorCalculationHelper.php:1001-1023` ; `src/Service/Commission/TarifDuRevenu.php` ; `IndicatorCalculationHelper::getCotationMontantChargementPrime` | `SigmaTransformeesPlafonneeTest`, `RetroCoherenceEcransTest`, `ReversementPariteEcranKetTest`, `RevenuCourtierPrescritPariteTest` (Ket ↔ écrans), `RevenuTauxExceptionnelTest`, `AssietteDeLaCommissionTest`, `AssietteSommeDesLignesTest` |
| **Les taux sont en POINTS** (16 = 16 %) ; le calcul passe par `getFraction()` | `Tranche.php:433-444`, `TypeRevenu.php:272-276`… | `TauxConventionPourcentageTest`, `ReferentielSemeEnPointsTest` |
| **La commission est exigible à proportion de la prime encaissée** | `getTrancheRetroExigible` (`IndicatorCalculationHelper.php:1853`) | `ExigibiliteProportionnelleTest` |
| **Une seule monnaie par cabinet** (décisions du 2026-10-14 et du 2026-10-15) : la monnaie de TRAVAIL (saisie = affichage = calcul, fonction `SAISIE_ET_AFFICHAGE`) est unique ; toute autre monnaie est une RÉFÉRENCE (fonction `AUCUNE`) — la monnaie du pays sert au capital social et aux mentions légales, avec son taux. **Aucun montant n'est jamais converti** : un montant ne porte pas sa monnaie, et l'écran affichait la monnaie d'affichage pendant la saisie — l'existant y était déjà (migration sans conversion). La monnaie de travail ne change plus, ni ne se supprime, une fois des montants saisis. Nouveau cabinet : USD de travail, monnaie du pays en référence. Écran et Ket | `MonnaieDeTravail::refus`, `::refusDeSuppression`, `::aDesMontants` ; `ChampsObligatoiresInspector::incoherencesMetier` ; `SuppressionEnCascade::planifier` ; `ServiceInitialisationEntreprise::initialiserMonnaies` ; migration `Version20261015090000` | `MonnaieDeTravailTest`, `MonnaieDuCabinetTest` |

## 5. Invariants comptables

| Invariant | Preuve | Test |
|---|---|---|
| **Sens** : débit validé = D 411 TTC / C 706 HT + C 443 taxe ; avoir = l'inverse (ventilé HT et taxe comme la note qu'il annule) ; règlement = D 521/571 / C 411. Seule une note VALIDÉE fait naître la créance, à sa **date d'émission** (`emiseLe`) | `CourtierEcritureComptableService.php:20-48`, :178-206 | `FacturationComptabiliteTest` |
| **Avoir plutôt que suppression** : on ne crédite que l'émis ; les échéances sans objet passent « à régulariser » sans changer de montant ; l'abandon d'un mouvement qui a vécu financièrement est refusé (409) | `SourceDeFacturation.php:198-203` ; `RegularisationDesEcheances.php:13-27` ; `LiensProteges.php:77-185` | `MouvementAvenantTest::testUneNoteDeCreditEmiseInterditLAbandon` |
| **Taxes et exonération** : taxe sur commission nulle si client exonéré OU risque non imposable — les deux taxes tombent ensemble | `ServiceTaxes.php:139-179` | `CommissionExonereeTest` |
| **Une note réglée ne se supprime pas et ne perd pas de ligne, elle s'annule par la note contraire** (avoir pour une note de débit, note de débit pour un avoir) — règle des **brouillons** et des **notes de reprise** (une note VALIDÉE, elle, ne se supprime jamais : invariant suivant) : toute suppression qui détruirait une note ayant reçu un paiement, ou lui retirerait une ligne, est refusée (409 qui nomme la note) — la note elle-même, ou emportée/amputée par la suppression d'une piste, d'une police ou d'un client ; épargner le règlement n'y change rien. Un PAIEMENT, lui, reste supprimable (correction d'une saisie erronée, reprise mal faite) : le refus le propose, et la note est ensuite libre. Écran, Ket et dossier (décisions du 2026-10-14) | `SuppressionEnCascade::notesRegleesTouchees` (contrôle du plan final) | `SuppressionEnChaineTest::testUneNoteRegleeNeSeSupprimePas`, `::testUneNoteRegleeAChevalNePerdPasDeLigne`, `::testEpargnerLeReglementNeRendPasLaFactureSupprimable`, `::testKetNeSupprimePasUneNoteReglee`, `::testUneNoteDeCreditRegleeSAnnuleParUneNoteDeDebit` |
| **Numérotation continue, attribuée à la validation** (pratique comptable OHADA, décision de Serge du 2026-10-15 ; remplace celle du 2026-10-14). Chaque note **validée** porte un numéro unique, chronologique et continu, sans trou ni doublon : `ND-AAAA-NNNNN` (débit) / `NC-AAAA-NNNNN` (crédit), une série par cabinet, par année d'émission et par type. Il est attribué **à la validation** — c'est elle qui fait naître la créance —, dans la même transaction : une validation qui échoue rend son numéro à la série ; deux validations simultanées s'attendent (verrou de ligne du compteur, jusqu'au commit). La date d'émission (`emiseLe`) est l'instant de la validation, prise après le verrou : jamais antérieure à la précédente de la série. Un **brouillon** porte une référence provisoire `BROUILLON-XXXXXXXX` et se supprime sans trou. La référence **ne se saisit plus**. Un numéro déjà émis se conserve (la note de prod `N1790767662318`, d'avant la règle) ; un numéro déjà porté est sauté, jamais bloquant. Seuls chemins : « Valider la note » (écran), picker de facturation, Ket (`validated: true`, appliqué après les lignes) — tous par `EmissionDeNote` | `EmissionDeNote::valider` ; `NumerotationDesNotesListener` ; `NumerotationDesNotes::prochaine` (UPDATE d'abord) ; migration `Version20261015170000` | `NumerotationDesNotesTest` (validation qui échoue après la prise du numéro, deux validations simultanées, brouillon supprimé, Ket, cabinet étranger) |
| **Intangibilité d'une note validée** (OHADA, 2026-10-15) : ses montants — HT, taxe, TTC, par ligne et au total — sont **figés à la validation** et ne se recalculent plus ; une note validée ne se modifie plus (en-tête, lignes, comptes, dévalidation) et ne se supprime pas, payée ou non, ni directement ni en cascade, ni par un détachement. Restent permis : ses paiements, ses documents, la date d'envoi. Toute correction passe par la note contraire, puis une nouvelle note. Un changement de prime, de taux ou de chargement après facturation ne touche jamais la note : il fait apparaître un écart « à facturer » ou « à créditer » sur la fiche du revenu, et l'avoir se mesure à ce qui a été ÉMIS (il reste possible si le dû tombe à zéro). Le journal, dérivé des montants figés et daté de l'émission, ne change plus. Note de **reprise** : hors série, figée, non modifiable, mais supprimable entière (décision du 2026-10-16) | `FigeageDeNote` ; `NoteIntangible` (écran 409, dialogue en lecture, Ket bloqué) ; `IntangibiliteDesNotesListener` (dernier rempart) ; `SuppressionEnCascade` ; `IndicatorCalculationHelper::getRevenuMontantFacture` ; commande `app:notes:figer` (l'existant) | `FigeageDesNotesTest`, `IntangibiliteDesNotesTest` |
| **Conservation dix ans** (Acte uniforme OHADA relatif au droit comptable ; décisions du 2026-10-15 et du 2026-10-16) : rien ne purge une pièce comptable. La **moindre pièce** — note émise (validée ou de reprise), paiement (de note ou d'indemnité), paiement de prime, dépense, reversement — interdit de supprimer le cabinet tant que son **archive comptable À JOUR** n'a pas été remise (téléchargée par le propriétaire ou la console) ; elle est datée du début de sa construction. NE sont PAS des pièces : un **brouillon** de note, et le **capital** déclaré sur la fiche (facultatif, vide par défaut) — il figure au journal de l'archive quand une autre pièce l'exige. L'archive réunit le PDF des notes, un CSV par nature, les **justificatifs joints** (`justificatifs/<nature>/<id>-<nom>`, renvoyés par la colonne du CSV) et le journal, avec l'empreinte SHA-256 de chaque fichier au `MANIFESTE.txt`. `app:cabinet:vider` refuse en production | `ArchiveComptableDuCabinet` ; `ConservationDesPieces` ; `ServiceSuppressionEntreprise::supprimer` | `ConservationDesPiecesTest` |

## 6. À CONFIRMER

- **Ristourne** (Serge) : aucun mouvement typé ni calcul. Une annulation ou une résiliation en
  cours de période entraîne normalement un remboursement de prime au prorata et une commission à
  reverser (note de crédit). Le code ne fait que SIGNALER les échéances déjà encaissées
  (`RegularisationDesEcheances::ristournePossible`, :66-98) ; « elle reste manuelle, par note de
  crédit » (:15). **Chantier ultérieur.**
- **Avoir sur un revenu à plusieurs échéances** : chantier connu, §7 point 7.

## 7. Chantiers décidés (ne rien coder sans plan validé)

0. ~~Assiette et source unique de la commission~~ — **fait** (décisions du 2026-10-15 ; §4,
   invariant acquis ; détail des trois écarts dans `reglementation-rdc-arca` §6). Une taxe ne
   sert jamais d'assiette (`5c6b2e3e`) ; une seule règle de tarif, `TarifDuRevenu`, dans
   l'ordre facturé (`b5dd6277`) ; l'assiette est la **somme** des lignes du chargement cible.
   Mesure en production : aucun cas sur les trois écarts, donc aucun historique à reprendre.
   (Un épinglage « forfait = montant facturé » a été écarté : un renouvellement recopie le
   forfait du revenu — `MouvementAvenantBuilder::revenusReconduits` — et l'aurait figé pour
   toutes les suites de la police.) Et **un
   type de commission neuf s'ouvre sans pourcentage, adossé au taux du risque** (décision du
   2026-10-15 : 10 % par défaut rendait le drapeau sans effet ; test
   `AssietteDeLaCommissionTest::testUnTypeNeufSAdosseAuTauxDuRisque`).
   La question de fond qui restait ouverte — le montant d'une note émise se recalculait — est
   réglée par le chantier 6.
1. ~~Numérotation unique et chronologique des notes~~ — **fait** (§5), puis remplacée par la
   numérotation continue attribuée à la validation (chantier 6).
2. ~~Refus de supprimer une note qui porte des paiements~~ — **fait** (§5, invariant acquis).
3. ~~« Mon portefeuille » étendu aux entités qui descendent d'une piste~~ — **fait** (§4, invariant acquis).
4. ~~Une seule monnaie par cabinet~~ — **fait** (§4, invariant acquis). La prémisse « convertir l'existant » est tombée : rien n'enregistrait la monnaie d'un montant, et l'écran montrait la monnaie d'affichage à la saisie (décision du 2026-10-15 : rien à convertir).
5. ~~Nettoyage de `Constante`~~ — **fait** (décisions du 2026-10-14). Le dernier grep a démenti
   la prémisse : la chaîne `getIdPoste()` n'était pas seulement morte, elle servait l'ancien état
   TCPDF (`EtatsController`), en 500 pour tout destinataire, et l'export `note_pdf` de Ket y
   menait. Ket ouvre désormais le PDF de la note (`admin.note.download_pdf`) ; l'ancien état,
   ses gabarits et tout le code de `Constante` que plus rien n'atteignait (325 méthodes sur
   380) sont supprimés. Et **une note ne se montre qu'à qui lit les notes**, sous aucune forme :
   PDF, aperçus, colonne de travail, ni depuis le bordereau qui la porte
   (`ControllerUtilsTrait::exigerLaLectureDesNotes`). Test : `ExporterEtatPdfTest`.
6. ~~Émission d'une note : numéro et montants figés~~ — **fait** (règle OHADA, décisions du
   2026-10-15 et du 2026-10-16 ; §5, trois invariants acquis). Numéro à la validation
   (`f7b164c3`), montants figés (`378d3a9c`), intangibilité et écart à facturer / à créditer
   (`c98e2749`), conservation et archive avant purge (`b0878a28`). Mesure en production avant
   livraison : une seule note validée, aucun trou, aucun doublon. Après le déploiement du
   2026-10-16, `app:notes:figer --confirmer` a figé `N1790767662318` à 11,60 (contrôle 0,00,
   entièrement réglée).
7. **Avoir sur une police à plusieurs échéances** — chantier CONNU, rien à coder pour l'instant
   (décision de Serge du 2026-10-16). L'écart « à créditer » se mesure au niveau du REVENU
   (`SourceDeFacturation::montantFacturable`, `IndicatorCalculationHelper::getRevenuMontantFacture`),
   pas échéance par échéance. Exemple : commission de 1 000 en deux échéances, la première
   facturée 500 (figée), puis le dû passe à 900 — écart de +400 au niveau du revenu, aucun
   excédent détecté ; un avoir sur la première échéance propose alors tout son facturé (500)
   au lieu de 50. Le picker et Ket proposent le montant, le courtier peut le corriger ; rien
   n'est faux à l'émission, mais la proposition peut l'être. **Mesure à relancer
   régulièrement** (lecture seule ; Serge, phpMyAdmin, base de production) — validée sur la base
   DEV le 2026-10-16 (0 ligne) :
   ```sql
   SELECT e.id AS cabinet_id, e.nom AS cabinet,
          COUNT(DISTINCT n.id) AS avoirs_emis,
          COUNT(DISTINCT co.id) AS polices,
          ROUND(SUM(COALESCE(a.montant_ttc_fige, 0)), 2) AS montant_credite_ttc
   FROM note n
   JOIN entreprise e ON e.id = n.entreprise_id
   JOIN article a ON a.note_id = n.id
   JOIN revenu_pour_courtier r ON r.id = a.revenu_facture_id
   JOIN cotation co ON co.id = r.cotation_id
   WHERE n.type = 1 AND n.validated = 1 AND n.addressed_to IN (0, 1)
     AND (SELECT COUNT(*) FROM tranche t WHERE t.cotation_id = co.id) > 1
   GROUP BY e.id, e.nom
   ORDER BY e.id;
   ```
   Elle compte les avoirs VALIDÉS (`type` 1), au client ou à l'assureur, dont une ligne porte un
   revenu d'une police à plus d'une échéance. Dès qu'elle rend des lignes, le chantier mérite un
   plan : écart mesuré par échéance (dû de l'échéance − facturé figé de l'échéance).

### Chantiers ultérieurs (signalés le 2026-10-15, non décidés — plan à valider avant tout code)

6. **Badge « Mon portefeuille » incomplet.** Quand l'invité gère au moins un portefeuille, le
   libellé du périmètre est le nom de ce portefeuille (`PortefeuilleCritereFactory::libelle`,
   :70), alors que la liste montre AUSSI les affaires dont il est responsable pour des clients
   sans gestionnaire (§4). Ket annonce donc « dans votre portefeuille X » un ensemble plus large.
7. **Trois chemins de documents manquants sous « Mon portefeuille ».** `PortefeuilleScope`
   ('Document', :94 et :131) n'a ni `feedback.tache.cotation.piste`, ni
   `feedback.tache.notificationSinistre.assure…`, ni `tache.offreIndemnisationSinistre…`. Ces
   documents ne sont pas orphelins (leur retour ou leur tâche est renseigné) : sous « Mon
   portefeuille », ils sont invisibles pour TOUT le monde. ⚠ Document joint déjà 55 tables par
   requête ; le plafond de MariaDB est 61 : tout ajout se mesure.
8. **Plan Ket en deux temps refusé à l'analyse.** Un plan « passer la monnaie de travail en
   référence, puis promouvoir une autre » est bloqué dès le contrôle à blanc : chaque opération
   est jugée sur une copie face à la BASE (`WorkspaceMutationService.php:280`), qui voit encore
   la première monnaie de travail — alors que l'exécution, séquentielle, passerait. Même cause
   possible pour toute règle « une seule X par cabinet » jugée à l'analyse.

## 8. Réflexes avant de coder

1. Quelle **étape du cycle** est touchée (§2) ? Quel **type de mouvement** (§3) ?
2. Quel **invariant** est en jeu (§4, §5) ? Le citer dans le plan — et dire s'il est acquis ou CIBLE.
3. Quel **test de parité** doit suivre (écran ↔ Ket, liste ↔ carte ↔ vigie) ?
4. Un montant : d'où vient-il ? Jamais un second calcul — la source unique.
5. Le geste touche-t-il un **chantier décidé** (§7) ? Ne pas aggraver l'écart ; le signaler.
