<?php

namespace App\Services\Canvas\Provider\List;

use App\Entity\ReversementRetroAgent;
use App\Services\Search\ReversementScope;
use App\Services\ServiceMonnaies;

/**
 * LA LISTE DES REVERSEMENTS — une ligne par versement, telle qu'elle est en base.
 *
 * C'est la surface canonique : recherche, tri, fiche, et surtout les deux actions
 * documentaires que FormCanvasProvider injecte sur toute entité portant une collection
 * de documents. Sans cette rubrique, un bordereau oublié au moment du virement n'avait
 * plus AUCUN endroit où être joint.
 *
 * ── UNE LIGNE = UN VIREMENT ────────────────────────────────────────────────────────
 * La base tient une ligne par ÉCHÉANCE réglée : un virement qui en solde six y occupe six
 * lignes, portant six fois la même date, la même référence et le même compte. C'est la
 * vérité de la base, ce n'est pas la façon dont on lit un relevé. La rubrique REPLIE donc
 * chaque lot sur son porteur (ReversementScope::estReplie), et chaque ligne annonce les
 * chiffres du VIREMENT — son total, le nombre d'échéances qu'il règle.
 *
 * Le détail par échéance n'est pas perdu : le chip « Virement » le rend sur demande, et la
 * fenêtre de reversement — ouverte par « Éditer » — montre les lignes du virement et
 * permet de les corriger.
 */
class ReversementRetroAgentListCanvasProvider implements ListCanvasProviderInterface
{
    public function __construct(private ServiceMonnaies $serviceMonnaies)
    {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === ReversementRetroAgent::class;
    }

    public function getCanvas(): array
    {
        return [
            "colonne_principale" => [
                "titre_colonne" => "Rétros intermédiaires",
                "texte_principal" => ["attribut_code" => "reference", "icone" => "depense"],
                "textes_secondaires_separateurs" => " • ",
                // DES CODES PLATS, JAMAIS DE CHEMIN POINTÉ. Le rendu d'une ligne fait
                // `attribute(entity, code)` : « agent.nom » n'y est pas un chemin mais un nom
                // de propriété, et la rubrique tombait en erreur dès la première ligne — ce
                // qu'une liste vide ne pouvait pas révéler. Les données de relation passent
                // donc par ReversementRetroAgentIndicatorStrategy.
                "textes_secondaires" => [
                    ["attribut_prefixe" => "Bénéficiaire : ", "attribut_code" => "beneficiaireNom", "attribut_type" => "text"],
                    // LE JUSTIFICATIF EN DEUXIÈME POSITION, et non en queue.
                    //
                    // La ligne secondaire est un flex d'UNE seule ligne : ce qui dépasse est
                    // écrasé à 1 px et remplacé par « … ». Placé en dernier, l'indicateur de
                    // pièce était donc INVISIBLE dans la vue par défaut — constaté au
                    // navigateur, pas déduit — alors que c'est la question qui motive cette
                    // rubrique : qu'ai-je versé sans preuve ? La référence de police et le
                    // compte débité, eux, supportent d'être les premiers escamotés.
                    //
                    // Compté SUR LE VIREMENT : un bordereau couvre tout un lot, et n'annoncer
                    // que les pièces de la ligne ferait passer pour nues deux lignes sur
                    // trois d'un virement pourtant justifié.
                    ["attribut_code" => "justificatifLibelle", "icone" => "document"],
                    // Un virement groupé se dit : sans cela, trois lignes d'un même
                    // décaissement se liraient comme trois virements distincts.
                    ["attribut_code" => "virementGroupe", "attribut_type" => "text"],
                    ["attribut_prefixe" => "Versé le : ", "attribut_code" => "paidAt", "attribut_type" => "date"],
                    // LES DEUX MAILLES SONT DÉCLARÉES, ET C'EST L'INDICATEUR QUI TRANCHE.
                    // Une ligne repliée en couvre plusieurs : nommer la police et l'échéance
                    // du seul porteur aurait désigné une affaire sur six comme si c'était la
                    // seule réglée — elles rendent donc null, et le nombre d'échéances prend
                    // leur place. Sur la vue détaillée, c'est l'inverse. Un canevas ne
                    // pouvant pas connaître le mode, il annonce les deux et laisse le
                    // silence faire le tri.
                    ["attribut_code" => "echeancesDuVirement", "attribut_type" => "text", "icone" => "action:count"],
                    ["attribut_prefixe" => "Police : ", "attribut_code" => "policeReference", "attribut_type" => "text"],
                    ["attribut_prefixe" => "Échéance : ", "attribut_code" => "echeanceLibelle", "attribut_type" => "text"],
                    ["attribut_prefixe" => "Débité de : ", "attribut_code" => "compteLibelle", "attribut_type" => "text"],
                ],
            ],
            // ── FILTRES RAPIDES ───────────────────────────────────────────────────
            //
            // Trois questions reviennent sur cette liste — qu'est-ce qui n'est pas
            // justifié, qu'ai-je versé ce mois-ci, quels virements soldent plusieurs
            // affaires — et une quatrième, le bénéficiaire, qui n'est pas un statut mais
            // une RELATION : d'où le chip-sélecteur, qui va chercher les agents là où
            // ils vivent plutôt que de figer une option par agent dans un canevas que
            // toutes les entreprises partagent.
            //
            // Les valeurs viennent de ReversementScope : la chip, la traduction SQL et le
            // paramètre de `ouvrir_rubrique` de l'assistant lisent le MÊME vocabulaire.
            "filtres_predefinis" => [
                [
                    // La rubrique porte les DEUX familles : c'est le seul moyen de lire
                    // l'une sans l'autre, et elles n'ont ni la même dette ni le même
                    // compte comptable (6611 pour un salarié, 632 pour un externe).
                    "critere" => ReversementScope::CLE_TYPE,
                    "libelle" => "Type",
                    "options" => ReversementScope::optionsChips(
                        ReversementScope::CLE_TYPE,
                        [
                            ReversementScope::TYPE_AGENT => 'invite',
                            ReversementScope::TYPE_PARTENAIRE => 'partenaire',
                        ],
                        'Tous',
                    ),
                ],
                [
                    "critere" => ReversementScope::CLE_JUSTIFICATIF,
                    "libelle" => "Justificatif",
                    "options" => ReversementScope::optionsChips(
                        ReversementScope::CLE_JUSTIFICATIF,
                        [
                            ReversementScope::AVEC_PIECE => 'document',
                            ReversementScope::SANS_PIECE => 'action:alert',
                        ],
                        'Tous',
                    ),
                ],
                [
                    "critere" => ReversementScope::CLE_PERIODE,
                    "libelle" => "Période",
                    "options" => ReversementScope::optionsChips(
                        ReversementScope::CLE_PERIODE,
                        [
                            ReversementScope::CE_MOIS => 'action:calendar',
                            ReversementScope::TRENTE_JOURS => 'action:renew',
                            ReversementScope::EXERCICE => 'action:count',
                        ],
                        'Toutes',
                    ),
                ],
                [
                    // ── LA MAILLE DE LECTURE, ET RIEN D'AUTRE ────────────────────────
                    //
                    // Deux options, et deux seulement : le versement tel qu'il a été fait
                    // au bénéficiaire, ou sa ventilation par échéance. Les deux portent le
                    // même argent — la barre des totaux annonce donc le même montant dans
                    // l'un et l'autre mode.
                    //
                    // « GROUPÉ » PORTE LA VALEUR VIDE : c'est la maille naturelle de la
                    // rubrique, il n'y a pas de critère à poser pour l'obtenir. Il est donc
                    // aussi l'option qui RETIRE le filtre, ce qui évite un « Tous » de plus
                    // disant la même chose que lui.
                    //
                    // Il y avait ici deux valeurs de plus — « Groupé » au sens « virements
                    // couvrant plusieurs échéances » et « Isolé » —, qui filtraient au lieu
                    // de choisir une maille. Deux notions dans un même chip se lisaient
                    // comme une seule.
                    "critere" => ReversementScope::CLE_VIREMENT,
                    "libelle" => "Virement",
                    "options" => [
                        ["value" => "", "label" => "Groupé", "icon" => "depense"],
                        [
                            "value" => ReversementScope::DETAIL,
                            "label" => ReversementScope::libelle(
                                ReversementScope::CLE_VIREMENT,
                                ReversementScope::DETAIL,
                            ),
                            "icon" => "action:view",
                        ],
                    ],
                ],
                [
                    // LE CRITÈRE EST SYNTHÉTIQUE, et il le fallait : le bénéficiaire vit
                    // tantôt dans `agent`, tantôt dans `partenaire`. Un critère posé sur la
                    // colonne `agent` ne pouvait filtrer qu'une famille sur deux, alors que
                    // la rubrique les porte toutes les deux.
                    "critere" => ReversementScope::CLE_BENEFICIAIRE,
                    "libelle" => "Bénéficiaire",
                    "options" => [
                        // Deux options qui ne portent pas de valeur mais un SÉLECTEUR : au
                        // clic, la liste est demandée à l'autocomplétion générique — déjà
                        // scopée au cabinet, et déjà gardée par les droits de son entité.
                        // Le `prefixe` dit de quelle famille vient le choix : c'est lui qui
                        // décide de la colonne filtrée, et qui empêche les deux chips de
                        // s'allumer ensemble sur un identifiant homonyme.
                        //
                        // ET ILS S'ALIGNENT SUR LE CHIP « TYPE ». Deux chips racontaient la
                        // même chose de deux façons, sans se parler : « Type : Agent » avec
                        // « Bénéficiaire : un partenaire » décrit un ensemble VIDE — agent
                        // IS NOT NULL et partenaire = 5 est impossible — et rien à l'écran
                        // n'en disait la cause. Le pire des deux, car l'utilisateur en
                        // conclut que la rubrique est vide.
                        //
                        //   `visibility_conditions` : l'option ne paraît que si le Type la
                        //       permet (R1). Elle n'est jamais retirée par surprise : un
                        //       filtre actif garde son chip visible, cf. `chipVisible`.
                        //   `implique` : choisir un bénéficiaire ALIGNE le Type sur sa
                        //       famille, du même geste et en une seule recherche (R3).
                        //
                        // Les deux viennent de ReversementScope, comme la traduction SQL et
                        // le schéma de Ket : une règle, un seul endroit.
                        [
                            "selecteur" => [
                                "entite" => "Invite",
                                "displayField" => "nom",
                                "prefixe" => ReversementScope::TYPE_AGENT,
                            ],
                            "label" => "Choisir un agent…",
                            "icon" => "invite",
                            "visibility_conditions" => ReversementScope::conditionsVisibiliteSelecteur(
                                ReversementScope::TYPE_AGENT,
                            ),
                            "implique" => ReversementScope::implicationDuSelecteur(
                                ReversementScope::TYPE_AGENT,
                            ),
                        ],
                        [
                            "selecteur" => [
                                "entite" => "Partenaire",
                                "displayField" => "nom",
                                "prefixe" => ReversementScope::TYPE_PARTENAIRE,
                            ],
                            "label" => "Choisir un partenaire…",
                            "icon" => "partenaire",
                            "visibility_conditions" => ReversementScope::conditionsVisibiliteSelecteur(
                                ReversementScope::TYPE_PARTENAIRE,
                            ),
                            "implique" => ReversementScope::implicationDuSelecteur(
                                ReversementScope::TYPE_PARTENAIRE,
                            ),
                        ],
                        // « TOUS » NE DÉCLARE RIEN, et c'est délibéré : c'est le seul moyen
                        // de retirer le filtre, il ne peut donc jamais disparaître de
                        // l'écran — quelle que soit la valeur du chip « Type ».
                        ["value" => "", "label" => "Tous", "icon" => "action:filter"],
                    ],
                ],
            ],
            "colonnes_numeriques" => [
                [
                    "titre_colonne" => "Montant versé",
                    "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                    // CE QUE LA LIGNE REPRÉSENTE : le virement entier sur la vue repliée,
                    // la seule échéance sur la vue détaillée. La barre des totaux lit le
                    // MÊME indicateur — c'est la seule façon qu'elles ne divergent jamais.
                    "attribut_code" => "montantAffiche",
                    "attribut_type" => "nombre",
                ],
            ],
        ];
    }
}
