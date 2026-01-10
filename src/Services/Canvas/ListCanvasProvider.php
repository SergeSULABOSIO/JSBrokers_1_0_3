<?php

namespace App\Services\Canvas;

use App\Entity\Assureur;
use App\Entity\AutoriteFiscale;
use App\Entity\Avenant;
use App\Entity\Bordereau;
use App\Entity\Chargement;
use App\Entity\ChargementPourPrime;
use App\Entity\Classeur;
use App\Entity\Client;
use App\Entity\CompteBancaire;
use App\Entity\ConditionPartage;
use App\Entity\Contact;
use App\Entity\Cotation;
use App\Entity\Document;
use App\Entity\Entreprise;
use App\Entity\Feedback;
use App\Entity\Groupe;
use App\Entity\Invite;
use App\Entity\ModelePieceSinistre;
use App\Entity\Monnaie;
use App\Entity\Note;
use App\Entity\NotificationSinistre;
use App\Entity\OffreIndemnisationSinistre;
use App\Entity\Paiement;
use App\Entity\Partenaire;
use App\Entity\PieceSinistre;
use App\Entity\Piste;
use App\Entity\RevenuPourCourtier;
use App\Entity\Risque;
use App\Entity\Tache;
use App\Entity\Taxe;
use App\Entity\Tranche;
use App\Entity\TypeRevenu;
use App\Services\ServiceMonnaies;

class ListCanvasProvider
{
    public function __construct(private ServiceMonnaies $serviceMonnaies) {}

    public function getCanvas(string $entityClassName): array
    {
        switch ($entityClassName) {
            case Assureur::class:
                return [
                    "colonne_principale" => [
                        "titre_colonne" => "Assureurs",
                        "texte_principal" => ["attribut_code" => "nom", "icone" => "mdi:shield-check"],
                        "textes_secondaires_separateurs" => " • ",
                        "textes_secondaires" => [
                            ["attribut_code" => "email"],
                            ["attribut_code" => "telephone"],
                        ],
                    ],
                    "colonnes_numeriques" => $this->getSharedNumericColumns(),
                ];

            case AutoriteFiscale::class:
                return [
                    "colonne_principale" => [
                        "titre_colonne" => "Autorités Fiscales",
                        "texte_principal" => ["attribut_code" => "nom", "icone" => "mdi:bank"],
                        "textes_secondaires_separateurs" => " • ",
                        "textes_secondaires" => [["attribut_code" => "abreviation"]],
                    ],
                    "colonnes_numeriques" => [],
                ];

            case Avenant::class:
                return [
                    "colonne_principale" => [
                        "titre_colonne" => "Avenants",
                        "texte_principal" => [
                            "attribut_code" => "referencePolice",
                            "icone" => "mdi:file-document-edit",
                        ],
                        "textes_secondaires_separateurs" => " • ",
                        "textes_secondaires" => [
                            ["attribut_prefixe" => "Avt n°", "attribut_code" => "numero"],
                            ["attribut_prefixe" => "Effet: ", "attribut_code" => "startingAt", "attribut_type" => "date"],
                        ],
                    ],
                    "colonnes_numeriques" => $this->getSharedNumericColumns(),
                ];

            case Bordereau::class:
                return [
                    "colonne_principale" => [
                        "titre_colonne" => "Bordereaux",
                        "texte_principal" => ["attribut_code" => "nom", "icone" => "mdi:file-table-box-multiple"],
                        "textes_secondaires_separateurs" => " • ",
                        "textes_secondaires" => [
                            ["attribut_code" => "assureur"],
                            ["attribut_prefixe" => "Reçu le: ", "attribut_code" => "receivedAt", "attribut_type" => "date"],
                        ],
                    ],
                    "colonnes_numeriques" => array_merge([
                        [
                            "titre_colonne" => "Montant TTC",
                            "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                            "attribut_code" => "montantTTC",
                            "attribut_type" => "nombre",
                        ],
                    ]),
                ];

            case Document::class:
                return [
                    "colonne_principale" => [
                        "titre_colonne" => "Documents",
                        "texte_principal" => [
                            "attribut_prefixe" => "",
                            "attribut_code" => "nom",
                            "attribut_type" => "text",
                            "attribut_taille_max" => 50,
                            "icone" => "mdi:file-document",
                            "icone_taille" => "19px",
                        ],
                        "textes_secondaires_separateurs" => " • ",
                        "textes_secondaires" => [
                            [
                                "attribut_prefixe" => "Créé le: ",
                                "attribut_code" => "createdAt",
                                "attribut_type" => "date",
                                "attribut_taille_max" => null,
                                "icone" => "fluent-mdl2:date-time-mirrored",
                                "icone_taille" => "16px",
                            ],
                        ],
                    ],
                    "colonnes_numeriques" => [],
                ];

            case Chargement::class:
                return [
                    "colonne_principale" => [
                        "titre_colonne" => "Types de Chargement",
                        "texte_principal" => ["attribut_code" => "nom", "icone" => "mdi:cog-transfer"],
                        "textes_secondaires" => [
                            ["attribut_code" => "description", "attribut_taille_max" => 50],
                        ],
                    ],
                    "colonnes_numeriques" => $this->getSharedNumericColumns(),
                ];

            case ChargementPourPrime::class:
                return [
                    "colonne_principale" => [
                        "titre_colonne" => "Chargements sur Primes",
                        "texte_principal" => ["attribut_code" => "nom", "icone" => "mdi:cash-plus"],
                        "textes_secondaires" => [
                            ["attribut_prefixe" => "Cotation: ", "attribut_code" => "cotation"],
                        ],
                    ],
                    "colonnes_numeriques" => $this->getSharedNumericColumns(),
                ];

            case Classeur::class:
                return [
                    "colonne_principale" => [
                        "titre_colonne" => "Classeurs",
                        "texte_principal" => ["attribut_code" => "nom", "icone" => "mdi:folder-multiple"],
                        "textes_secondaires" => [["attribut_code" => "description", "attribut_taille_max" => 50]],
                    ],
                    "colonnes_numeriques" => [],
                ];

            case Client::class:
                return [
                    "colonne_principale" => [
                        "titre_colonne" => "Clients",
                        "texte_principal" => ["attribut_code" => "nom", "icone" => "mdi:account-group"],
                        "textes_secondaires_separateurs" => " • ",
                        "textes_secondaires" => [
                            ["attribut_code" => "email"],
                            ["attribut_code" => "telephone"],
                        ],
                    ],
                    "colonnes_numeriques" => $this->getSharedNumericColumns(),
                ];

            case CompteBancaire::class:
                return [
                    "colonne_principale" => [
                        "titre_colonne" => "Comptes Bancaires",
                        "texte_principal" => ["attribut_code" => "nom", "icone" => "mdi:bank"],
                        "textes_secondaires_separateurs" => " • ",
                        "textes_secondaires" => [
                            ["attribut_code" => "intitule"],
                            ["attribut_prefixe" => "N° ", "attribut_code" => "numero"],
                        ],
                    ],
                    "colonnes_numeriques" => [],
                ];

            case NotificationSinistre::class:
                return [
                    "colonne_principale" => [
                        "titre_colonne" => "Sinistres",
                        "texte_principal" => ["attribut_code" => "referenceSinistre", "icone" => "emojione-monotone:fire"],
                        "textes_secondaires_separateurs" => " • ",
                        "textes_secondaires" => [
                            ["attribut_code" => "assure"],
                            ["attribut_code" => "assureur"],
                            ["attribut_prefixe" => "Survenu le: ", "attribut_code" => "occuredAt", "attribut_type" => "date"],
                        ],
                    ],
                    "colonnes_numeriques" => [
                        [
                            "titre_colonne" => "Dommage (av. éval.)",
                            "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                            "attribut_code" => "dommageAvantEvaluation",
                            "attribut_type" => "nombre",
                        ],
                        [
                            "titre_colonne" => "Dommage (ap. éval.)",
                            "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                            "attribut_code" => "dommageApresEvaluation",
                            "attribut_type" => "nombre",
                        ],
                        [
                            "titre_colonne" => "Compensation Due",
                            "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                            "attribut_code" => "compensationDue",
                            "attribut_type" => "nombre",
                        ],
                    ],
                ];

            case ConditionPartage::class:
                return [
                    "colonne_principale" => [
                        "titre_colonne" => "Conditions de Partage",
                        "texte_principal" => ["attribut_code" => "nom", "icone" => "mdi:share-variant"],
                        "textes_secondaires" => [
                            ["attribut_prefixe" => "Taux: ", "attribut_code" => "taux", "attribut_type" => "pourcentage"],
                        ],
                    ],
                    "colonnes_numeriques" => $this->getSharedNumericColumns(),
                ];

            case Contact::class:
                return [
                    "colonne_principale" => [
                        "titre_colonne" => "Contacts",
                        "texte_principal" => ["attribut_code" => "nom", "icone" => "mdi:account-box"],
                        "textes_secondaires" => [
                            ["attribut_code" => "fonction"],
                            ["attribut_code" => "email"]
                        ],
                    ],
                    "colonnes_numeriques" => $this->getSharedNumericColumns(),
                ];

            case Cotation::class:
                return [
                    "colonne_principale" => [
                        "titre_colonne" => "Cotations",
                        "texte_principal" => ["attribut_code" => "nom", "icone" => "mdi:file-chart"],
                        "textes_secondaires_separateurs" => " • ",
                        "textes_secondaires" => [
                            ["attribut_code" => "assureur"],
                            ["attribut_prefixe" => "Piste: ", "attribut_code" => "piste"],
                        ],
                    ],
                    "colonnes_numeriques" => $this->getSharedNumericColumns(),
                ];

            case Entreprise::class:
                return [
                    "colonne_principale" => [
                        "titre_colonne" => "Entreprises",
                        "texte_principal" => ["attribut_code" => "nom", "icone" => "mdi:office-building"],
                        "textes_secondaires" => [
                            ["attribut_prefixe" => "Licence: ", "attribut_code" => "licence"],
                        ],
                    ],
                    "colonnes_numeriques" => $this->getSharedNumericColumns(),
                ];

            case Feedback::class:
                return [
                    "colonne_principale" => [
                        "titre_colonne" => "Feedbacks",
                        "texte_principal" => ["attribut_code" => "description", "icone" => "mdi:message-reply-text", "attribut_taille_max" => 50],
                        "textes_secondaires" => [
                            ["attribut_prefixe" => "Créé le: ", "attribut_code" => "createdAt", "attribut_type" => "date"],
                        ],
                    ],
                    "colonnes_numeriques" => $this->getSharedNumericColumns(),
                ];

            case Groupe::class:
                return [
                    "colonne_principale" => [
                        "titre_colonne" => "Groupes de clients",
                        "texte_principal" => ["attribut_code" => "nom", "icone" => "mdi:account-multiple"],
                        "textes_secondaires" => [
                            ["attribut_code" => "description", "attribut_taille_max" => 50],
                        ],
                    ],
                    "colonnes_numeriques" => $this->getSharedNumericColumns(),
                ];

            case Invite::class:
                return [
                    "colonne_principale" => [
                        "titre_colonne" => "Invitations",
                        "texte_principal" => ["attribut_code" => "email", "icone" => "mdi:email-plus"],
                        "textes_secondaires_separateurs" => " • ",
                        "textes_secondaires" => [
                            ["attribut_code" => "nom"],
                            ["attribut_prefixe" => "Statut: ", "attribut_code" => "status_string"],
                        ],
                    ],
                    "colonnes_numeriques" => $this->getSharedNumericColumns(),
                ];

            case ModelePieceSinistre::class:
                return [
                    "colonne_principale" => [
                        "titre_colonne" => "Modèles de Pièces",
                        "texte_principal" => ["attribut_code" => "nom", "icone" => "mdi:file-check-outline"],
                        "textes_secondaires" => [
                            ["attribut_code" => "description", "attribut_taille_max" => 50],
                        ],
                    ],
                    "colonnes_numeriques" => $this->getSharedNumericColumns(),
                ];

            case Monnaie::class:
                return [
                    "colonne_principale" => [
                        "titre_colonne" => "Monnaies",
                        "texte_principal" => ["attribut_code" => "nom", "icone" => "mdi:currency-usd"],
                        "textes_secondaires_separateurs" => " • ",
                        "textes_secondaires" => [
                            ["attribut_code" => "code"],
                            ["attribut_prefixe" => "Symbole: ", "attribut_code" => "symbole"],
                        ],
                    ],
                    "colonnes_numeriques" => [],
                ];

            case Note::class:
                return [
                    "colonne_principale" => [
                        "titre_colonne" => "Notes",
                        "texte_principal" => ["attribut_code" => "nom", "icone" => "mdi:note-text"],
                        "textes_secondaires_separateurs" => " • ",
                        "textes_secondaires" => [
                            ["attribut_prefixe" => "Réf: ", "attribut_code" => "reference"],
                            ["attribut_prefixe" => "Statut: ", "attribut_code" => "status_string"],
                        ],
                    ],
                    "colonnes_numeriques" => [
                        [
                            "titre_colonne" => "Montant Payable",
                            "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                            "attribut_code" => "montant_payable",
                            "attribut_type" => "nombre",
                        ],
                        [
                            "titre_colonne" => "Montant Payé",
                            "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                            "attribut_code" => "montant_paye",
                            "attribut_type" => "nombre",
                        ],
                        [
                            "titre_colonne" => "Solde",
                            "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                            "attribut_code" => "montant_solde",
                            "attribut_type" => "nombre",
                        ],
                    ],
                ];

            case Paiement::class:
                return [
                    "colonne_principale" => [
                        "titre_colonne" => "Paiements",
                        "texte_principal" => ["attribut_code" => "reference", "icone" => "mdi:cash-multiple"],
                        "textes_secondaires" => [
                            ["attribut_code" => "description", "attribut_taille_max" => 50],
                        ],
                    ],
                    "colonnes_numeriques" => array_merge([
                        [
                            "titre_colonne" => "Montant",
                            "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                            "attribut_code" => "montant",
                            "attribut_type" => "nombre",
                        ],
                    ], $this->getSharedNumericColumns()),
                ];

            case Partenaire::class:
                return [
                    "colonne_principale" => [
                        "titre_colonne" => "Partenaires",
                        "texte_principal" => ["attribut_code" => "nom", "icone" => "mdi:handshake"],
                        "textes_secondaires" => [
                            ["attribut_code" => "email"],
                        ],
                    ],
                    "colonnes_numeriques" => array_merge([
                        [
                            "titre_colonne" => "Part (%)",
                            "attribut_unité" => "%",
                            "attribut_code" => "part",
                            "attribut_type" => "nombre",
                        ],
                    ], $this->getSharedNumericColumns()),
                ];

            case OffreIndemnisationSinistre::class:
                return [
                    "colonne_principale" => [
                        "titre_colonne" => "Offres d'indemnisation",
                        "texte_principal" => ["attribut_code" => "nom", "icone" => "icon-park-outline:funds"],
                        "textes_secondaires" => [["attribut_code" => "beneficiaire"]],
                    ],
                    "colonnes_numeriques" => array_merge([
                        ["titre_colonne" => "Montant Payable", "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(), "attribut_code" => "montantPayable", "attribut_type" => "nombre"],
                        ["titre_colonne" => "Comp. versée", "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(), "attribut_code" => "compensationVersee", "attribut_type" => "nombre"],
                        ["titre_colonne" => "Solde à verser", "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(), "attribut_code" => "soldeAVerser", "attribut_type" => "nombre"],
                    ], $this->getSharedNumericColumns()),
                ];

            case PieceSinistre::class:
                return [
                    "colonne_principale" => [
                        "titre_colonne" => "Pièces de Sinistre",
                        "texte_principal" => ["attribut_code" => "description", "icone" => "codex:file"],
                        "textes_secondaires" => [["attribut_prefixe" => "Reçu le: ", "attribut_code" => "receivedAt", "attribut_type" => "date"]],
                    ],
                    "colonnes_numeriques" => $this->getSharedNumericColumns(),
                ];

            case Piste::class:
                return [
                    "colonne_principale" => [
                        "titre_colonne" => "Pistes",
                        "texte_principal" => ["attribut_code" => "nom", "icone" => "mdi:road-variant"],
                        "textes_secondaires" => [["attribut_code" => "client"]],
                    ],
                    "colonnes_numeriques" => $this->getSharedNumericColumns(),
                ];

            case RevenuPourCourtier::class:
                return [
                    "colonne_principale" => [
                        "titre_colonne" => "Revenus Courtier",
                        "texte_principal" => ["attribut_code" => "nom", "icone" => "mdi:cash-sync"],
                        "textes_secondaires_separateurs" => " • ",
                        "textes_secondaires" => [
                            ["attribut_prefixe" => "Type: ", "attribut_code" => "typeRevenu"],
                            ["attribut_prefixe" => "Cotation: ", "attribut_code" => "cotation"],
                        ],
                    ],
                    "colonnes_numeriques" => array_merge([
                        [
                            "titre_colonne" => "Montant",
                            "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                            "attribut_code" => "montantFlatExceptionel",
                            "attribut_type" => "nombre",
                        ],
                        [
                            "titre_colonne" => "Taux",
                            "attribut_unité" => "%",
                            "attribut_code" => "tauxExceptionel",
                            "attribut_type" => "nombre",
                        ],
                    ], $this->getSharedNumericColumns()),
                ];

            case Risque::class:
                return [
                    "colonne_principale" => [
                        "titre_colonne" => "Risques",
                        "texte_principal" => ["attribut_code" => "nomComplet", "icone" => "mdi:hazard-lights"],
                        "textes_secondaires_separateurs" => " • ",
                        "textes_secondaires" => [
                            ["attribut_prefixe" => "Code: ", "attribut_code" => "code"],
                            ["attribut_prefixe" => "Branche: ", "attribut_code" => "branche_string"],
                        ],
                    ],
                    "colonnes_numeriques" => $this->getSharedNumericColumns(),
                ];

            case Tache::class:
                return [
                    "colonne_principale" => [
                        "titre_colonne" => "Tâches",
                        "texte_principal" => ["attribut_code" => "description", "icone" => "mdi:checkbox-marked-circle-outline"],
                        "textes_secondaires" => [
                            ["attribut_prefixe" => "Pour: ", "attribut_code" => "executor"],
                            ["attribut_prefixe" => "Échéance: ", "attribut_code" => "toBeEndedAt", "attribut_type" => "date"],
                        ],
                    ],
                    "colonnes_numeriques" => $this->getSharedNumericColumns(),
                ];

            case Taxe::class:
                return [
                    "colonne_principale" => [
                        "titre_colonne" => "Taxes",
                        "texte_principal" => ["attribut_code" => "code", "icone" => "mdi:percent-box"],
                        "textes_secondaires" => [
                            ["attribut_code" => "description", "attribut_taille_max" => 50],
                        ],
                    ],
                    "colonnes_numeriques" => [
                        [
                            "titre_colonne" => "Taux IARD",
                            "attribut_unité" => "%",
                            "attribut_code" => "tauxIARD",
                            "attribut_type" => "nombre",
                        ],
                        [
                            "titre_colonne" => "Taux VIE",
                            "attribut_unité" => "%",
                            "attribut_code" => "tauxVIE",
                            "attribut_type" => "nombre",
                        ],
                    ],
                ];

            case Tranche::class:
                return [
                    "colonne_principale" => [
                        "titre_colonne" => "Tranches",
                        "texte_principal" => ["attribut_code" => "nom", "icone" => "mdi:chart-pie"],
                        "textes_secondaires_separateurs" => " • ",
                        "textes_secondaires" => [
                            ["attribut_prefixe" => "Cotation: ", "attribut_code" => "cotation"],
                            ["attribut_prefixe" => "Payable le: ", "attribut_code" => "payableAt", "attribut_type" => "date"],
                        ],
                    ],
                    "colonnes_numeriques" => array_merge([
                        [
                            "titre_colonne" => "Montant",
                            "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                            "attribut_code" => "montantFlat",
                            "attribut_type" => "nombre",
                        ],
                        [
                            "titre_colonne" => "Pourcentage",
                            "attribut_unité" => "%",
                            "attribut_code" => "pourcentage",
                            "attribut_type" => "nombre",
                        ],
                    ], $this->getSharedNumericColumns()),
                ];

            case TypeRevenu::class:
                return [
                    "colonne_principale" => [
                        "titre_colonne" => "Types de Revenu",
                        "texte_principal" => ["attribut_code" => "nom", "icone" => "mdi:cash-register"],
                        "textes_secondaires_separateurs" => " • ",
                        "textes_secondaires" => [
                            ["attribut_prefixe" => "Redevable: ", "attribut_code" => "redevable_string"],
                            ["attribut_prefixe" => "Partagé: ", "attribut_code" => "shared_string"],
                        ],
                    ],
                    "colonnes_numeriques" => $this->getSharedNumericColumns(),
                ];
        }
        return [];
    }

    private function getSharedNumericColumns(): array
    {
        return [
            [
                "titre_colonne" => "Prime Nette",
                "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                "attribut_code" => "prime_nette",
                "attribut_type" => "nombre",
            ],
            [
                "titre_colonne" => "Prime Totale",
                "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                "attribut_code" => "prime_totale",
                "attribut_type" => "nombre",
            ],
            // [
            //     "titre_colonne" => "Comm. Pure",
            //     "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
            //     "attribut_code" => "commission_pure",
            //     "attribut_type" => "nombre",
            // ],
            [
                "titre_colonne" => "Comm. Nette",
                "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                "attribut_code" => "commission_nette",
                "attribut_type" => "nombre",
            ],
            [
                "titre_colonne" => "Comm. Totale",
                "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                "attribut_code" => "commission_totale",
                "attribut_type" => "nombre",
            ],
            [
                "titre_colonne" => "Rétro-comm.",
                "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                "attribut_code" => "retro_commission_partenaire",
                "attribut_type" => "nombre",
            ],
        ];
    }
}
