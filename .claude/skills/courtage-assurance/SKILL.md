---
name: courtage-assurance
description: Le métier du courtage d'assurance tel que JSBrokers l'implémente — glossaire (terme → entité), cycle de vie piste → police → facturation → encaissement → rétrocession, règles par type d'avenant, invariants métier et comptables avec leurs preuves et leurs tests, décisions prises et chantiers décidés. Invoquer avant tout plan touchant cotations, avenants, tranches, notes, paiements, commissions ou rétrocessions.
trigger when: >
  Déclencher AUTOMATIQUEMENT dès qu'une tâche touche : piste, cotation, prime, chargement,
  commission, revenu, tranche, échéance, avenant, police, renouvellement, prorogation,
  annulation, résiliation, incorporation, note de débit/crédit, avoir, facturation,
  encaissement, paiement, rétrocommission, reversement, partenaire, taxe, sinistre, monnaie.
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
| Facturation | Note + Articles ; référence posée à la naissance (`ValeursDeNaissance.php:102`) | `facturation_picker` (`NoteController.php:167`) | `preparer_facturation` |
| Créance | note **validée**, débit ou crédit, adressée au client ou à l'assureur → D 411 / C 706 + C 443 (`CourtierEcritureComptableService.php:33-34`, :193-209) | — | — |
| Encaissement | `Paiement` de la note → D 521/571 / C 411 (:35) ; prime de l'assuré : `PaiementPrime` | `/admin/paiement`, `/admin/paiementprime` | `signaler_paiement_prime` ; **aucun outil Ket pour l'encaissement d'une note** |
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
`Constante::calculerPeriodeCouverture` est MORTE et contredit ces règles : ne pas l'utiliser —
sa suppression est décidée (§7).

## 4. Invariants métier — à citer dans tout plan

| Invariant | Preuve | Test |
|---|---|---|
| **À facturer ≠ à encaisser.** Facturable = TTC − déjà facturé ; un avoir rend le revenu de nouveau facturable ; « rien à facturer » n'est pas « rien à encaisser » | `SourceDeFacturation.php:158-203`, :264-285, :392-413 | `SourceDeFacturationTest`, `FacturationEcritureTest::testFacturerUnePartLaisseLeResteFacturable` |
| **Prime exigée selon le type** (§3), au PREMIER avenant seulement ; type inconnu → exigée | `DefautsDeSouscription.php:41-48`, :101-118 | `AvenantPrimeExigeeTest`, `DefautsDeSouscriptionTest::testLaPrimeExigee` |
| **Dates selon le type** (§3) | `DefautsDeSouscription::pour`, `MouvementAvenantBuilder::periode` | `DefautsDeSouscriptionTest::testLaRegle`, `MouvementAvenantTest`, `AdoptionParLeMouvementTest` |
| **Le responsable d'une piste** = gestionnaire du portefeuille du client, sinon son créateur ; c'est lui qui la voit dans « Mon portefeuille » | `ResponsableDePiste.php:22-38` | `ResponsableDePistePariteTest` |
| **Une source unique par montant** : commission TTC = `getCotationMontantCommissionTtc` ; taxe via `ServiceTaxes` | `IndicatorCalculationHelper.php:1001-1023` | `SigmaTransformeesPlafonneeTest`, `RetroCoherenceEcransTest`, `ReversementPariteEcranKetTest` |
| **Les taux sont en POINTS** (16 = 16 %) ; le calcul passe par `getFraction()` | `Tranche.php:433-444`, `TypeRevenu.php:272-276`… | `TauxConventionPourcentageTest`, `ReferentielSemeEnPointsTest` |
| **La commission est exigible à proportion de la prime encaissée** | `getTrancheRetroExigible` (`IndicatorCalculationHelper.php:1853`) | `ExigibiliteProportionnelleTest` |

### Invariants CIBLES (décidés, pas encore codés)

**Une seule monnaie par cabinet** — décision de Serge du 2026-10-14.
- *Cible* : une seule monnaie par cabinet, **saisie = affichage**, tant qu'aucune entité ne porte
  sa propre monnaie.
- *État actuel* : rien ne l'impose. `Monnaie::FONCTION_*` (`Monnaie.php:58-61`) sans contrainte
  d'unicité ; l'affichage prend la PREMIÈRE monnaie « affichage » trouvée, la locale retombe sur
  USD (`ServiceMonnaies.php:42-79`) ; les montants saisis ne sont jamais convertis. Ket ne
  convertit jamais et parle la monnaie du cabinet (`AiContextBuilder.php:1373-1401`,
  `MonnaieDuCabinetTest`).
- *Écart constaté* : en DEV, des cabinets saisissent en **CDF** et affichent en **USD** — les
  montants saisis en CDF s'affichent avec l'étiquette USD, sans conversion.
- *Reprise de l'existant* (décision du 2026-10-14) : les montants saisis dans une autre monnaie
  sont **convertis dans la monnaie d'affichage** du cabinet, qui devient sa monnaie unique.
- *Chantier* : ultérieur (§7). D'ici là, ne rien coder qui suppose deux monnaies.

**« Mon portefeuille » des entités qui descendent d'une piste** — décision de Serge du 2026-10-14.
- *Cible* : cotations, polices, tranches, tâches et documents rattachés à une piste suivent
  `ResponsableDePiste`, comme la piste elle-même. Ce qui ne dépend que du CLIENT garde la règle du
  portefeuille client.
- *État actuel* : ces entités suivent `piste.client.portefeuille.gestionnaire`
  (`PortefeuilleScope.php:55-99`). Une piste créée pour un client sans portefeuille est dans
  « Mon portefeuille » de son créateur, mais sa cotation et sa police n'y sont pas.
- *Chantier* : ultérieur (§7), avec tests de parité.

## 5. Invariants comptables

| Invariant | Preuve | Test |
|---|---|---|
| **Sens** : débit validé = D 411 TTC / C 706 HT + C 443 taxe ; avoir = l'inverse ; règlement = D 521/571 / C 411. Seule une note VALIDÉE fait naître la créance | `CourtierEcritureComptableService.php:20-48`, :178-206 | `FacturationComptabiliteTest` |
| **Avoir plutôt que suppression** : on ne crédite que l'émis ; les échéances sans objet passent « à régulariser » sans changer de montant ; l'abandon d'un mouvement qui a vécu financièrement est refusé (409) | `SourceDeFacturation.php:198-203` ; `RegularisationDesEcheances.php:13-27` ; `LiensProteges.php:77-185` | `MouvementAvenantTest::testUneNoteDeCreditEmiseInterditLAbandon` |
| **Taxes et exonération** : taxe sur commission nulle si client exonéré OU risque non imposable — les deux taxes tombent ensemble | `ServiceTaxes.php:139-179` | `CommissionExonereeTest` |

### Invariants CIBLES (décidés, pas encore codés)

**Numérotation des notes : unique et chronologique par cabinet** — décision de Serge du
2026-10-14. **CHANTIER PRIORITAIRE.**
- *Cible* : chaque note d'un cabinet reçoit une référence **générée**, **unique** et
  **chronologique** (format choisi par le produit, décision du 2026-10-14). Le champ reste
  **éditable** : la référence générée est une proposition que le cabinet peut remplacer — une
  référence saisie reste soumise à l'unicité dans le cabinet.
- *État actuel* : `'N' . time() . random_int(100, 999)` (`ValeursDeNaissance.php:102`),
  `'N' . time()` (`NoteController.php:151`), `'FACT-' . référence du bordereau`
  (`NoteController.php:133`) ; colonne NOT NULL **sans contrainte d'unicité**
  (`Note.php:77-79`).
- *Écart* : doublons possibles (deux notes dans la même seconde, ou du même bordereau), aucune
  continuité, aucune chronologie garantie ; les tests ne vérifient que le format
  (`NoteCreationDialogueTest`).

**Une note émise ne se supprime pas, elle s'annule par avoir** — décision de Serge du 2026-10-14.
- *Cible* : la suppression d'une note qui porte des paiements est refusée (**409** qui explique,
  cf. « Suppression refusée = 409 qui explique ») ; l'annulation passe par une note de crédit.
- *État actuel* : rien ne l'empêche — `Note::paiements` est en `cascade: remove` +
  `orphanRemoval` (`Note.php:74`) : supprimer la note emporte ses règlements. Aucun test.
- *Chantier* : ultérieur (§7).

## 6. À CONFIRMER

- **Ristourne** (Serge) : aucun mouvement typé ni calcul. Une annulation ou une résiliation en
  cours de période entraîne normalement un remboursement de prime au prorata et une commission à
  reverser (note de crédit). Le code ne fait que SIGNALER les échéances déjà encaissées
  (`RegularisationDesEcheances::ristournePossible`, :66-98) ; « elle reste manuelle, par note de
  crédit » (:15). **Chantier ultérieur.**

## 7. Chantiers décidés (ne rien coder sans plan validé)

1. **Numérotation unique et chronologique des notes** — prioritaire (§5).
2. **Refus de supprimer une note qui porte des paiements** — 409, annulation par avoir (§5).
3. **« Mon portefeuille » étendu** aux entités qui descendent d'une piste, via `ResponsableDePiste` (§4).
4. **Une seule monnaie par cabinet**, saisie = affichage (§4).
5. **Nettoyage** : supprimer `Constante::calculerPeriodeCouverture` et la chaîne `getIdPoste()` de
   `Constante` (7 méthodes, appelées seulement par elles-mêmes ou par deux gabarits inclus nulle
   part), après un dernier grep.

## 8. Réflexes avant de coder

1. Quelle **étape du cycle** est touchée (§2) ? Quel **type de mouvement** (§3) ?
2. Quel **invariant** est en jeu (§4, §5) ? Le citer dans le plan — et dire s'il est acquis ou CIBLE.
3. Quel **test de parité** doit suivre (écran ↔ Ket, liste ↔ carte ↔ vigie) ?
4. Un montant : d'où vient-il ? Jamais un second calcul — la source unique.
5. Le geste touche-t-il un **chantier décidé** (§7) ? Ne pas aggraver l'écart ; le signaler.
