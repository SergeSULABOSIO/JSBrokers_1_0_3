<?php

namespace App\Services\Canvas;

use App\Entity\Avenant;
use App\Entity\Assureur;
use App\Entity\Classeur;
use App\Entity\Client;
use App\Entity\CompteBancaire;
use App\Entity\ConditionPartage;
use App\Entity\Contact;
use App\Entity\Cotation;
use App\Entity\Document;
use App\Entity\NotificationSinistre;
use App\Entity\OffreIndemnisationSinistre;
use App\Entity\PieceSinistre;
use App\Entity\Piste;
use App\Entity\Tache;
use App\Entity\AutoriteFiscale;
use App\Services\ServiceMonnaies;

class ListCanvasProvider
{
    public function __construct(private ServiceMonnaies $serviceMonnaies)
    {
    }

    public function getCanvas(string $entityClassName): array
    {
        switch ($entityClassName) {
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

            case Classeur::class:
                return [
                    "colonne_principale" => [
                        "titre_colonne" => "Classeurs",
                        "texte_principal" => ["attribut_code" => "nom", "icone" => "mdi:folder-multiple"],
                        "textes_secondaires" => [["attribut_code" => "description", "attribut_taille_max" => 50]],
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
                    "colonnes_numeriques" => [
                        [
                            "titre_colonne" => "Commissions TTC",
                            "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                            "attribut_code" => "montant_commission_ttc",
                            "attribut_type" => "nombre",
                        ],
                        [
                            "titre_colonne" => "Solde Primes",
                            "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                            "attribut_code" => "montant_prime_payable_par_client_solde",
                            "attribut_type" => "nombre",
                        ],
                    ],
                ];

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
                    "colonnes_numeriques" => [
                        [
                            "titre_colonne" => "Commissions TTC",
                            "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                            "attribut_code" => "montant_commission_ttc",
                            "attribut_type" => "nombre",
                        ],
                        [
                            "titre_colonne" => "Solde Commissions",
                            "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                            "attribut_code" => "montant_commission_ttc_solde",
                            "attribut_type" => "nombre",
                        ],
                    ],
                ];

            case Piste::class:
                return [
                    "colonne_principale" => [
                        "titre_colonne" => "Pistes",
                        "texte_principal" => ["attribut_code" => "nom", "icone" => "mdi:road-variant"],
                        "textes_secondaires" => [["attribut_code" => "client"]],
                    ],
                    "colonnes_numeriques" => [],
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

            case ConditionPartage::class:
                return [
                    "colonne_principale" => [
                        "titre_colonne" => "Conditions de Partage",
                        "texte_principal" => ["attribut_code" => "nom", "icone" => "mdi:share-variant"],
                        "textes_secondaires" => [
                            ["attribut_prefixe" => "Taux: ", "attribut_code" => "taux", "attribut_type" => "pourcentage"],
                        ],
                    ],
                    "colonnes_numeriques" => [],
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
                    "colonnes_numeriques" => [
                        [
                            "titre_colonne" => "Prime TTC",
                            "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                            "attribut_code" => "primeTTC",
                            "attribut_type" => "nombre",
                        ],
                        [
                            "titre_colonne" => "Comm. TTC",
                            "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                            "attribut_code" => "commissionTTC",
                            "attribut_type" => "nombre",
                        ],
                    ],
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
                    "colonnes_numeriques" => [
                        [
                            "titre_colonne" => "Prime TTC",
                            "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                            "attribut_code" => "primeTTC",
                            "attribut_type" => "nombre",
                        ],
                        [
                            "titre_colonne" => "Comm. TTC",
                            "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                            "attribut_code" => "commissionTTC",
                            "attribut_type" => "nombre",
                        ],
                    ],
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
                    "colonnes_numeriques" => [],
                ];

            case Contact::class:
                return [
                    "colonne_principale" => [
                        "titre_colonne" => "Contacts",
                        "texte_principal" => ["attribut_code" => "nom", "icone" => "mdi:account-box"],
                        "textes_secondaires" => [["attribut_code" => "fonction"], ["attribut_code" => "email"]],
                    ],
                    "colonnes_numeriques" => [],
                ];

            case OffreIndemnisationSinistre::class:
                return [
                    "colonne_principale" => [
                        "titre_colonne" => "Offres d'indemnisation",
                        "texte_principal" => ["attribut_code" => "nom", "icone" => "icon-park-outline:funds"],
                        "textes_secondaires" => [["attribut_code" => "beneficiaire"]],
                    ],
                    "colonnes_numeriques" => [
                        ["titre_colonne" => "Montant Payable", "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(), "attribut_code" => "montantPayable", "attribut_type" => "nombre"],
                        ["titre_colonne" => "Comp. versée", "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(), "attribut_code" => "compensationVersee", "attribut_type" => "nombre"],
                        ["titre_colonne" => "Solde à verser", "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(), "attribut_code" => "compensationAVersee", "attribut_type" => "nombre"],
                    ],
                ];

            case PieceSinistre::class:
                return [
                    "colonne_principale" => [
                        "titre_colonne" => "Pièces de Sinistre",
                        "texte_principal" => ["attribut_code" => "description", "icone" => "codex:file"],
                        "textes_secondaires" => [["attribut_prefixe" => "Reçu le: ", "attribut_code" => "receivedAt", "attribut_type" => "date"]],
                    ],
                    "colonnes_numeriques" => [],
                ];

                // ... Ajoutez d'autres `case` ici pour chaque entité que vous souhaitez afficher en liste
        }
        return [];
    }
}