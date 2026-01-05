<?php

namespace App\Services\Canvas;

use App\Entity\Note;
use App\Entity\Taxe;
use App\Entity\Piste;
use App\Entity\Tache;
use App\Entity\Client;
use App\Entity\Groupe;
use App\Entity\Invite;
use App\Entity\Risque;
use App\Entity\Avenant;
use App\Entity\Contact;
use App\Entity\Tranche;
use App\Entity\Assureur;
use App\Entity\Classeur;
use App\Entity\Cotation;
use App\Entity\Document;
use App\Entity\Feedback;
use App\Entity\Paiement;
use App\Entity\Bordereau;
use App\Entity\Chargement;
use App\Entity\Entreprise;
use App\Entity\Partenaire;
use App\Entity\Monnaie;
use App\Entity\TypeRevenu;
use App\Entity\Utilisateur;
use App\Entity\PieceSinistre;
use App\Entity\CompteBancaire;
use App\Entity\AutoriteFiscale;
use App\Entity\ConditionPartage;
use App\Entity\ChargementPourPrime;
use App\Entity\NotificationSinistre;
use App\Entity\OffreIndemnisationSinistre;
use App\Services\ServiceMonnaies;

class EntityCanvasProvider
{
    public function __construct(
        private ServiceMonnaies $serviceMonnaies
    ) {
    }

    public function getCanvas(string $entityClassName): array
    {
        // Cet "aiguilleur" garde le code principal propre et lisible.
        switch ($entityClassName) {
            case NotificationSinistre::class:
                return [
                    "parametres" => [
                        "description" => "Notification Sinistre",
                        'icone' => 'emojione-monotone:fire',
                        'background_image' => '/images/fitures/notification_sinistre.jpg',
                        'description_template' => [
                            "Ce dossier concerne le sinistre référencé [[*referenceSinistre]]",
                            ", survenu le [[occuredAt]]",
                            " et notifié le [[notifiedAt]].",
                            " Il est lié à la police d'assurance [[*referencePolice]] souscrite par [[assure]] auprès de l'assureur [[assureur]].",
                            " Le risque couvert est : [[risque]].",
                            " Circonstances : <em>« [[descriptionDeFait]] »</em>.",
                            " Dommage initialement estimé à [[dommage]]",
                            ", réévalué à [[evaluationChiffree]]."
                        ]
                    ],
                    "liste" => [
                        ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                        ["code" => "referencePolice", "intitule" => "Réf. Police", "type" => "Texte"],
                        ["code" => "referenceSinistre", "intitule" => "Réf. Sinistre", "type" => "Texte"],
                        ["code" => "descriptionDeFait", "intitule" => "Description des faits", "type" => "Texte", "description" => "Détails sur les circonstances du sinistre."],
                        ["code" => "descriptionVictimes", "intitule" => "Détails Victimes", "type" => "Texte", "description" => "Informations sur les victimes et les dommages corporels/matériels."],
                        ["code" => "assure", "intitule" => "Assuré", "type" => "Relation", "targetEntity" => Client::class, "displayField" => "nom"],
                        ["code" => "assureur", "intitule" => "Assureur", "type" => "Relation", "targetEntity" => Assureur::class, "displayField" => "nom"],
                        ["code" => "risque", "intitule" => "Risque", "type" => "Relation", "targetEntity" => Risque::class, "displayField" => "nomComplet"],
                        ["code" => "occuredAt", "intitule" => "Date de survenance", "type" => "Date"],
                        ["code" => "notifiedAt", "intitule" => "Date de notification", "type" => "Date"],
                        ["code" => "dommage", "intitule" => "Dommage estimé", "type" => "Nombre", "unite" => "$"],
                        ["code" => "evaluationChiffree", "intitule" => "Dommage évalué", "type" => "Nombre", "unite" => "$"],
                        ["code" => "offreIndemnisationSinistres", "intitule" => "Offres", "type" => "Collection", "targetEntity" => OffreIndemnisationSinistre::class, "displayField" => "nom"],
                        ["code" => "pieces", "intitule" => "Pièces", "type" => "Collection", "targetEntity" => PieceSinistre::class, "displayField" => "description"],
                        ["code" => "contacts", "intitule" => "Contacts", "type" => "Collection", "targetEntity" => Contact::class, "displayField" => "nom"],
                        ["code" => "taches", "intitule" => "Tâches", "type" => "Collection", "targetEntity" => Tache::class, "displayField" => "description"],
                        [
                            "code" => "delaiDeclaration",
                            "intitule" => "Délai Déclaration",
                            "type" => "Calcul",
                            "unite" => "",
                            "format" => "Texte",
                            "fonction" => "Notification_Sinistre_getDelaiDeclaration",
                            "description" => "⏱️ Mesure la réactivité de l'assuré à déclarer son sinistre (entre la date de survenance et la date de notification)."
                        ],
                        [
                            "code" => "compensation",
                            "intitule" => "Compensation",
                            "type" => "Calcul", // On utilise ce type pour déclencher la logique dans le contrôleur
                            "unite" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                            "format" => "Nombre",
                            "fonction" => "Notification_Sinistre_getCompensation",
                            "description" => "📊 Montant total de l'indemnisation convenue pour ce sinistre." // MODIFICATION: Ajout
                        ],
                        [
                            "code" => "compensationVersee",
                            "intitule" => "Comp. versée",
                            "type" => "Calcul", // On utilise ce type pour déclencher la logique dans le contrôleur
                            "unite" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                            "format" => "Nombre",
                            "fonction" => "Notification_Sinistre_getCompensationVersee",
                            "description" => "📊 Montant cumulé des paiements déjà effectués pour cette indemnisation." // MODIFICATION: Ajout
                        ],
                        [
                            "code" => "compensationSoldeAverser",
                            "intitule" => "Solde à verser",
                            "type" => "Calcul", // On utilise ce type pour déclencher la logique dans le contrôleur
                            "unite" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                            "format" => "Nombre",
                            "fonction" => "Notification_Sinistre_getSoldeAVerser",
                            "description" => "📊 Montant restant à payer pour solder complètement ce dossier sinistre." // MODIFICATION: Ajout
                        ],
                        [
                            "code" => "compensationFranchise",
                            "intitule" => "Franchise appliquée",
                            "type" => "Calcul", // On utilise ce type pour déclencher la logique dans le contrôleur
                            "unite" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                            "format" => "Nombre",
                            "fonction" => "Notification_Sinistre_getFranchise",
                            "description" => "📊 Montant de la franchise qui a été appliquée conformément aux termes de la police." // MODIFICATION: Ajout
                        ],
                        [
                            "code" => "statusDocumentsAttendus",
                            "intitule" => "Status - pièces",
                            "type" => "Calcul", // On utilise ce type pour déclencher la logique dans le contrôleur
                            "unite" => "",
                            "format" => "ArrayAssoc",
                            "fonction" => "Notification_Sinistre_getStatusDocumentsAttendusNumbers",
                            "description" => "⏳ Suivi des pièces justificatives attendues, fournies et manquantes pour le dossier." // MODIFICATION: Ajout
                        ],
                        [
                            "code" => "indiceCompletude",
                            "intitule" => "Complétude Pièces",
                            "type" => "Calcul",
                            "unite" => "",
                            "format" => "Texte",
                            "fonction" => "Notification_Sinistre_getIndiceCompletude",
                            "description" => "📊 Pourcentage des pièces requises qui ont été effectivement fournies pour ce dossier."
                        ],
                        [
                            "code" => "dureeReglement",
                            "intitule" => "Vitesse de règlement",
                            "type" => "Calcul", // On utilise ce type pour déclencher la logique dans le contrôleur
                            "unite" => "",
                            "format" => "Texte",
                            "fonction" => "Notification_Sinistre_getDureeReglement",
                            "description" => "⏱️ Durée totale en jours entre la notification du sinistre et le dernier paiement de règlement." // MODIFICATION: Ajout
                        ],
                        [
                            "code" => "dateDernierReglement",
                            "intitule" => "Dernier règlement",
                            "type" => "Calcul", // On utilise ce type pour déclencher la logique dans le contrôleur
                            "unite" => "",
                            "format" => "Date",
                            "fonction" => "Notification_Sinistre_getDateDernierRgelement",
                            "description" => "⏱️ Date à laquelle le tout dernier paiement a été effectué pour ce sinistre." // MODIFICATION: Ajout
                        ],
                        [
                            "code" => "ageDossier",
                            "intitule" => "Âge du Dossier",
                            "type" => "Calcul",
                            "unite" => "",
                            "format" => "Texte",
                            "fonction" => "Notification_Sinistre_getAgeDossier",
                            "description" => "⏳ Indique depuis combien de temps le dossier est ouvert. Crucial pour prioriser les cas anciens."
                        ],
                    ],
                ];

            case OffreIndemnisationSinistre::class:
                return [
                    "parametres" => [
                        "description" => "Offre d'Indemnisation",
                        "icone" => "icon-park-outline:funds",
                        'background_image' => '/images/fitures/default.jpg',
                        'description_template' => [
                            "Offre d'indemnisation: [[*nom]] pour le bénéficiaire [[beneficiaire]].",
                            " Montant payable de [[montantPayable]] avec une franchise de [[franchiseAppliquee]]."
                        ]
                    ],
                    "liste" => [
                        ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                        ["code" => "nom", "intitule" => "Description", "type" => "Texte"],
                        ["code" => "beneficiaire", "intitule" => "Bénéficiaire", "type" => "Texte"],
                        ["code" => "montantPayable", "intitule" => "Montant Payable", "type" => "Nombre", "unite" => "$"],
                        ["code" => "franchiseAppliquee", "intitule" => "Franchise", "type" => "Nombre", "unite" => "$"],
                        ["code" => "notificationSinistre", "intitule" => "Notification Sinistre", "type" => "Relation", "targetEntity" => NotificationSinistre::class, "displayField" => "referenceSinistre"],
                        ["code" => "paiements", "intitule" => "Paiements", "type" => "Collection", "targetEntity" => Paiement::class, "displayField" => "reference"],
                        ["code" => "documents", "intitule" => "Documents", "type" => "Collection", "targetEntity" => Document::class, "displayField" => "nom"],
                        ["code" => "taches", "intitule" => "Tâches", "type" => "Collection", "targetEntity" => Tache::class, "displayField" => "description"],
                        [
                            "code" => "compensationVersee",
                            "intitule" => "Comp. versée",
                            "type" => "Calcul", // On utilise ce type pour déclencher la logique dans le contrôleur
                            "unite" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                            "format" => "Nombre",
                            "fonction" => "Offre_Indemnisation_getCompensationVersee",
                            "description" => "📊 Montant cumulé des paiements déjà effectués pour cette offre." // MODIFICATION: Ajout
                        ],
                        [
                            "code" => "compensationAVersee",
                            "intitule" => "Solde à verser",
                            "type" => "Calcul", // On utilise ce type pour déclencher la logique dans le contrôleur
                            "unite" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                            "format" => "Nombre",
                            "fonction" => "Offre_Indemnisation_getSoldeAVerser",
                            "description" => "Montant restant à payer pour solder cette offre." // MODIFICATION: Ajout
                        ],
                        [
                            "code" => "pourcentagePaye",
                            "intitule" => "Pourcentage Payé",
                            "type" => "Calcul",
                            "unite" => "",
                            "format" => "Texte",
                            "fonction" => "Offre_Indemnisation_getPourcentagePaye",
                            "description" => "🟩 Fournit un indicateur visuel de l'état d'avancement du paiement de l'offre."
                        ]
                    ]
                ];

            case PieceSinistre::class:
                return [
                    "parametres" => [
                        "description" => "Pièce Sinistre",
                        "icone" => "codex:file",
                        'background_image' => '/images/fitures/default.jpg',
                        'description_template' => [
                            "Pièce de sinistre: [[*description]] fournie par [[fourniPar]] le [[receivedAt]]."
                        ]
                    ],
                    "liste" => [
                        ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                        ["code" => "description", "intitule" => "Description", "type" => "Texte"],
                        ["code" => "fourniPar", "intitule" => "Fourni par", "type" => "Texte"],
                        ["code" => "receivedAt", "intitule" => "Date de réception", "type" => "Date"],
                        ["code" => "notificationSinistre", "intitule" => "Notification Sinistre", "type" => "Relation", "targetEntity" => NotificationSinistre::class, "displayField" => "referenceSinistre"],
                        ["code" => "documents", "intitule" => "Documents", "type" => "Collection", "targetEntity" => Document::class, "displayField" => "nom"],
                    ]
                ];
            case Avenant::class:
                return [
                    "parametres" => [
                        "description" => "Avenant",
                        "icone" => "mdi:file-document-edit",
                        'background_image' => '/images/fitures/default.jpg',
                        'description_template' => [
                            "Avenant n°[[*numero]] de la police [[referencePolice]].",
                            " Période de couverture du [[startingAt]] au [[endingAt]]."
                        ]
                    ],
                    "liste" => [
                        ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                        ["code" => "referencePolice", "intitule" => "Réf. Police", "type" => "Texte"],
                        ["code" => "numero", "intitule" => "Numéro", "type" => "Texte"],
                        ["code" => "description", "intitule" => "Description", "type" => "Texte"],
                        ["code" => "startingAt", "intitule" => "Date d'effet", "type" => "Date"],
                        ["code" => "endingAt", "intitule" => "Date d'échéance", "type" => "Date"],
                        ["code" => "cotation", "intitule" => "Cotation", "type" => "Relation", "targetEntity" => Cotation::class, "displayField" => "nom"],
                        ["code" => "documents", "intitule" => "Documents", "type" => "Collection", "targetEntity" => Document::class, "displayField" => "nom"],
                        [
                            "code" => "primeTTC",
                            "intitule" => "Prime TTC",
                            "type" => "Calcul",
                            "unite" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                            "format" => "Nombre",
                            "fonction" => "Avenant_getPrimeTTC",
                            "description" => "Montant total de la prime TTC."
                        ],
                        [
                            "code" => "commissionTTC",
                            "intitule" => "Commission TTC",
                            "type" => "Calcul",
                            "unite" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                            "format" => "Nombre",
                            "fonction" => "Avenant_getCommissionTTC",
                            "description" => "Montant total de la commission TTC."
                        ],
                    ]
                ];
            case Assureur::class:
                return [
                    "parametres" => [
                        "description" => "Assureur",
                        "icone" => "mdi:shield-check",
                        'background_image' => '/images/fitures/default.jpg',
                        'description_template' => [
                            "L'assureur [[*nom]] est une entité clé de notre portefeuille.",
                            " Contactable par email à l'adresse [[email]], par téléphone au [[telephone]] et physiquement à [[adressePhysique]].",
                            " Les informations légales sont : N° Impôt [[numimpot]], ID.NAT [[idnat]], et RCCM [[rccm]]."
                        ]
                    ],
                    "liste" => [
                        ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                        ["code" => "nom", "intitule" => "Nom", "type" => "Texte"],
                        ["code" => "email", "intitule" => "Email", "type" => "Texte"],
                        ["code" => "telephone", "intitule" => "Téléphone", "type" => "Texte"],
                        ["code" => "url", "intitule" => "Site Web", "type" => "Texte"],
                        ["code" => "adressePhysique", "intitule" => "Adresse", "type" => "Texte"],
                        ["code" => "numimpot", "intitule" => "N° Impôt", "type" => "Texte"],
                        ["code" => "idnat", "intitule" => "ID.NAT", "type" => "Texte"],
                        ["code" => "rccm", "intitule" => "RCCM", "type" => "Texte"],
                        ["code" => "cotations", "intitule" => "Cotations", "type" => "Collection", "targetEntity" => Cotation::class, "displayField" => "nom"],
                        ["code" => "bordereaus", "intitule" => "Bordereaux", "type" => "Collection", "targetEntity" => Bordereau::class, "displayField" => "nom"],
                        ["code" => "notificationSinistres", "intitule" => "Sinistres", "type" => "Collection", "targetEntity" => NotificationSinistre::class, "displayField" => "referenceSinistre"],
                        [
                            "code" => "montant_commission_ttc",
                            "intitule" => "Commissions TTC",
                            "type" => "Calcul",
                            "unite" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                            "format" => "Nombre",
                            "fonction" => "Assureur_getMontant_commission_ttc",
                            "description" => "Montant total des commissions (Toutes Taxes Comprises) générées par cet assureur."
                        ],
                        [
                            "code" => "montant_commission_ttc_solde",
                            "intitule" => "Solde Commissions",
                            "type" => "Calcul",
                            "unite" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                            "format" => "Nombre",
                            "fonction" => "Assureur_getMontant_commission_ttc_solde",
                            "description" => "Montant des commissions TTC restant à percevoir de cet assureur."
                        ],
                        [
                            "code" => "montant_prime_payable_par_client_solde",
                            "intitule" => "Solde Primes Clients",
                            "type" => "Calcul",
                            "unite" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                            "format" => "Nombre",
                            "fonction" => "Assureur_getMontant_prime_payable_par_client_solde",
                            "description" => "Montant des primes que les clients doivent encore payer pour les polices de cet assureur."
                        ]
                    ]
                ];
            
            case Monnaie::class:
                return [
                    "parametres" => [
                        "description" => "Monnaie",
                        "icone" => "mdi:currency-usd",
                        'background_image' => '/images/fitures/default.jpg',
                        'description_template' => [
                            "Monnaie: [[*nom]] ([[code]]) - Symbole: [[symbole]]."
                        ]
                    ],
                    "liste" => [
                        ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                        ["code" => "nom", "intitule" => "Nom", "type" => "Texte"],
                        ["code" => "code", "intitule" => "Code ISO", "type" => "Texte"],
                        ["code" => "symbole", "intitule" => "Symbole", "type" => "Texte"],
                    ]
                ];

            case Client::class:
                return [
                    "parametres" => [
                        "description" => "Client",
                        "icone" => "mdi:account-group",
                        'background_image' => '/images/fitures/default.jpg',
                        'description_template' => [
                            "Client [[*nom]].",
                            " Contact: [[email]] / [[telephone]].",
                            " Adresse: [[adresse]]."
                        ]
                    ],
                    "liste" => [
                        ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                        ["code" => "nom", "intitule" => "Nom", "type" => "Texte"],
                        ["code" => "email", "intitule" => "Email", "type" => "Texte"],
                        ["code" => "telephone", "intitule" => "Téléphone", "type" => "Texte"],
                        ["code" => "adresse", "intitule" => "Adresse", "type" => "Texte"],
                        ["code" => "groupe", "intitule" => "Groupe", "type" => "Relation", "targetEntity" => Groupe::class, "displayField" => "nom"],
                        ["code" => "contacts", "intitule" => "Contacts", "type" => "Collection", "targetEntity" => Contact::class, "displayField" => "nom"],
                        ["code" => "pistes", "intitule" => "Pistes", "type" => "Collection", "targetEntity" => Piste::class, "displayField" => "nom"],
                        ["code" => "notificationSinistres", "intitule" => "Sinistres", "type" => "Collection", "targetEntity" => NotificationSinistre::class, "displayField" => "referenceSinistre"],
                        ["code" => "documents", "intitule" => "Documents", "type" => "Collection", "targetEntity" => Document::class, "displayField" => "nom"],
                        ["code" => "partenaires", "intitule" => "Partenaires", "type" => "Collection", "targetEntity" => Partenaire::class, "displayField" => "nom"],
                        [
                            "code" => "montant_commission_ttc",
                            "intitule" => "Commissions TTC",
                            "type" => "Calcul",
                            "unite" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                            "format" => "Nombre",
                            "fonction" => "Client_getMontant_commission_ttc",
                            "description" => "Montant total des commissions TTC générées par ce client."
                        ],
                        [
                            "code" => "montant_prime_payable_par_client_solde",
                            "intitule" => "Solde Primes",
                            "type" => "Calcul",
                            "unite" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                            "format" => "Nombre",
                            "fonction" => "Client_getMontant_prime_payable_par_client_solde",
                            "description" => "Montant des primes que ce client doit encore payer."
                        ]
                    ]
                ];
            case ConditionPartage::class:
                return [
                    "parametres" => [
                        "description" => "Condition de Partage",
                        "icone" => "mdi:share-variant",
                        'background_image' => '/images/fitures/default.jpg',
                        'description_template' => [
                            "Condition de partage: [[*nom]] pour le partenaire [[partenaire]].",
                            " Taux de [[taux]]% appliqué sur [[unite_mesure_string]]",
                            " avec la formule: [[formule_string]]."
                        ]
                    ],
                    "liste" => [
                        ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                        ["code" => "nom", "intitule" => "Nom", "type" => "Texte"],
                        ["code" => "partenaire", "intitule" => "Partenaire", "type" => "Relation", "targetEntity" => Partenaire::class, "displayField" => "nom"],
                        ["code" => "piste", "intitule" => "Piste (Exception)", "type" => "Relation", "targetEntity" => Piste::class, "displayField" => "nom"],
                        ["code" => "taux", "intitule" => "Taux", "type" => "Nombre", "unite" => "%"],
                        ["code" => "seuil", "intitule" => "Seuil", "type" => "Nombre", "unite" => $this->serviceMonnaies->getCodeMonnaieAffichage()],
                        [
                            "code" => "formule_string",
                            "intitule" => "Formule",
                            "type" => "Calcul",
                            "format" => "Texte",
                            "fonction" => "ConditionPartage_getFormuleString",
                        ],
                        [
                            "code" => "unite_mesure_string",
                            "intitule" => "Unité de Mesure",
                            "type" => "Calcul",
                            "format" => "Texte",
                            "fonction" => "ConditionPartage_getUniteMesureString",
                        ],
                        [
                            "code" => "critere_risque_string",
                            "intitule" => "Critère Risque",
                            "type" => "Calcul",
                            "format" => "Texte",
                            "fonction" => "ConditionPartage_getCritereRisqueString",
                        ],
                        ["code" => "produits", "intitule" => "Risques Ciblés", "type" => "Collection", "targetEntity" => Risque::class, "displayField" => "nomComplet"],
                    ]
                ];

            case Contact::class:
                return [
                    "parametres" => [
                        "description" => "Contact",
                        "icone" => "mdi:account-box",
                        'background_image' => '/images/fitures/default.jpg',
                        'description_template' => [
                            "Contact: [[*nom]] ([[fonction]]).",
                            " Email: [[email]] / Tél: [[telephone]]."
                        ]
                    ],
                    "liste" => [
                        ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                        ["code" => "nom", "intitule" => "Nom", "type" => "Texte"],
                        ["code" => "fonction", "intitule" => "Fonction", "type" => "Texte"],
                        ["code" => "email", "intitule" => "Email", "type" => "Texte"],
                        ["code" => "telephone", "intitule" => "Téléphone", "type" => "Texte"],
                        ["code" => "client", "intitule" => "Client", "type" => "Relation", "targetEntity" => Client::class, "displayField" => "nom"],
                        [
                            "code" => "type_string",
                            "intitule" => "Type",
                            "type" => "Calcul",
                            "format" => "Texte",
                            "fonction" => "Contact_getTypeString",
                            "description" => "Le type de contact (Production, Sinistre, etc.)."
                        ],
                    ]
                ];

            case Cotation::class:
                return [
                    "parametres" => [
                        "description" => "Cotation",
                        "icone" => "mdi:file-chart",
                        'background_image' => '/images/fitures/default.jpg',
                        'description_template' => [
                            "Cotation: [[*nom]] pour la piste [[piste]].",
                            " Assureur: [[assureur]]. Durée: [[duree]] mois."
                        ]
                    ],
                    "liste" => [
                        ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                        ["code" => "nom", "intitule" => "Nom", "type" => "Texte"],
                        ["code" => "assureur", "intitule" => "Assureur", "type" => "Relation", "targetEntity" => Assureur::class, "displayField" => "nom"],
                        ["code" => "piste", "intitule" => "Piste", "type" => "Relation", "targetEntity" => Piste::class, "displayField" => "nom"],
                        ["code" => "createdAt", "intitule" => "Créé le", "type" => "Date"],
                        ["code" => "avenants", "intitule" => "Avenants", "type" => "Collection", "targetEntity" => Avenant::class],
                        ["code" => "taches", "intitule" => "Tâches", "type" => "Collection", "targetEntity" => Tache::class],
                        ["code" => "documents", "intitule" => "Documents", "type" => "Collection", "targetEntity" => Document::class],
                        [
                            "code" => "primeTTC",
                            "intitule" => "Prime TTC",
                            "type" => "Calcul",
                            "unite" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                            "format" => "Nombre",
                            "fonction" => "Cotation_getMontant_prime_payable_par_client",
                            "description" => "Montant total de la prime TTC."
                        ],
                        [
                            "code" => "commissionTTC",
                            "intitule" => "Commission TTC",
                            "type" => "Calcul",
                            "unite" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                            "format" => "Nombre",
                            "fonction" => "Cotation_getCommissionTTC",
                            "description" => "Montant total de la commission TTC."
                        ],
                    ]
                ];

            case Groupe::class:
                return [
                    "parametres" => [
                        "description" => "Groupe de clients",
                        "icone" => "mdi:account-multiple",
                        'background_image' => '/images/fitures/default.jpg',
                        'description_template' => [
                            "Groupe de clients: [[*nom]].",
                            " Description: <em>« [[description]] »</em>."
                        ]
                    ],
                    "liste" => [
                        ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                        ["code" => "nom", "intitule" => "Nom du groupe", "type" => "Texte", "col_principale" => true, "textes_secondaires" => [
                            ["attribut_code" => "description"]
                        ]],
                        ["code" => "clients", "intitule" => "Clients", "type" => "Collection", "targetEntity" => Client::class],
                    ]
                ];

            case Partenaire::class:
                return [
                    "parametres" => [
                        "description" => "Partenaire",
                        "icone" => "mdi:handshake",
                        'background_image' => '/images/fitures/default.jpg',
                        'description_template' => [
                            "Partenaire: [[*nom]]. Part de commission: [[part]]%.",
                            " Contact: [[email]] / [[telephone]]."
                        ]
                    ],
                    "liste" => [
                        ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                        ["code" => "nom", "intitule" => "Nom", "type" => "Texte", "col_principale" => true, "textes_secondaires" => [
                            ["attribut_code" => "email"]
                        ]],
                        ["code" => "part", "intitule" => "Part (%)", "type" => "Nombre", "unite" => "%"],
                        ["code" => "documents", "intitule" => "Documents", "type" => "Collection", "targetEntity" => Document::class],
                        ["code" => "clients", "intitule" => "Clients", "type" => "Collection", "targetEntity" => Client::class],
                    ]
                ];

            case Risque::class:
                return [
                    "parametres" => [
                        "description" => "Risque",
                        "icone" => "mdi:hazard-lights",
                        'background_image' => '/images/fitures/default.jpg',
                        'description_template' => [
                            "Risque: [[*nomComplet]] (Code: [[code]]).",
                            " Branche: [[branche_string]]."
                        ]
                    ],
                    "liste" => [
                        ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                        ["code" => "nomComplet", "intitule" => "Nom complet", "type" => "Texte", "col_principale" => true, "textes_secondaires" => [
                            ["attribut_code" => "code", "attribut_prefixe" => "Code: "]
                        ]],
                        ["code" => "imposable", "intitule" => "Imposable", "type" => "Booleen"],
                        ["code" => "pistes", "intitule" => "Pistes", "type" => "Collection", "targetEntity" => Piste::class],
                        ["code" => "notificationSinistres", "intitule" => "Sinistres", "type" => "Collection", "targetEntity" => NotificationSinistre::class],
                    ]
                ];
            case Feedback::class:
                return [
                    "parametres" => [
                        "description" => "Feedback",
                        "icone" => "mdi:message-reply-text",
                        'background_image' => '/images/fitures/default.jpg',
                        'description_template' => [
                            "Feedback du [[createdAt]].",
                            " Action suivante: [[nextAction]] le [[nextActionAt]]."
                        ]
                    ],
                    "liste" => [
                        ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                        ["code" => "description", "intitule" => "Description", "type" => "Texte"],
                        ["code" => "createdAt", "intitule" => "Date", "type" => "Date"],
                        ["code" => "documents", "intitule" => "Documents", "type" => "Collection", "targetEntity" => Document::class, "displayField" => "nom"],
                    ]
                ];
            case Piste::class:
                return [
                    "parametres" => [
                        "description" => "Piste Commerciale",
                        "icone" => "mdi:road-variant",
                        'background_image' => '/images/fitures/default.jpg',
                        'description_template' => [
                            "Piste commerciale: [[*nom]] pour le client [[client]].",
                            " Risque: [[risque]]. Prime potentielle: [[primePotentielle]]."
                        ]
                    ],
                    "liste" => [
                        ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                        ["code" => "nom", "intitule" => "Nom", "type" => "Texte", "col_principale" => true, "textes_secondaires" => [
                            ["attribut_code" => "client"]
                        ]],
                        ["code" => "risque", "intitule" => "Risque", "type" => "Relation", "targetEntity" => Risque::class, "displayField" => "nomComplet"],
                        ["code" => "primePotentielle", "intitule" => "Prime potentielle", "type" => "Nombre", "unite" => "$"],
                        ["code" => "cotations", "intitule" => "Cotations", "type" => "Collection", "targetEntity" => Cotation::class],
                        ["code" => "documents", "intitule" => "Documents", "type" => "Collection", "targetEntity" => Document::class],
                    ]
                ];

            case Tache::class:
                return [
                    "parametres" => [
                        "description" => "Tâche",
                        "icone" => "mdi:checkbox-marked-circle-outline",
                        'background_image' => '/images/fitures/default.jpg',
                        'description_template' => [
                            "Tâche: [[*description]].",
                            " À exécuter par [[executor]] avant le [[toBeEndedAt]]."
                        ]
                    ],
                    "liste" => [
                        ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                        ["code" => "description", "intitule" => "Description", "type" => "Texte", "col_principale" => true, "textes_secondaires" => [
                            ["attribut_code" => "executor", "attribut_prefixe" => "Pour: "]
                        ]],
                        ["code" => "toBeEndedAt", "intitule" => "Échéance", "type" => "Date"],
                        ["code" => "closed", "intitule" => "Clôturée", "type" => "Booleen"],
                        ["code" => "feedbacks", "intitule" => "Feedbacks", "type" => "Collection", "targetEntity" => Feedback::class],
                        ["code" => "documents", "intitule" => "Documents", "type" => "Collection", "targetEntity" => Document::class],
                    ]
                ];
            case Bordereau::class:
                return [
                    "parametres" => [
                        "description" => "Bordereau",
                        "icone" => "mdi:file-table-box-multiple",
                        'background_image' => '/images/fitures/default.jpg',
                        'description_template' => [
                            "Bordereau [[*nom]] de l'assureur [[assureur]]",
                            ", reçu le [[receivedAt]]",
                            " pour un montant total de [[montantTTC]]."
                        ]
                    ],
                    "liste" => [
                        ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                        ["code" => "nom", "intitule" => "Nom", "type" => "Texte"],
                        ["code" => "assureur", "intitule" => "Assureur", "type" => "Relation", "targetEntity" => Assureur::class, "displayField" => "nom"],
                        ["code" => "montantTTC", "intitule" => "Montant TTC", "type" => "Nombre", "unite" => $this->serviceMonnaies->getCodeMonnaieAffichage()],
                        ["code" => "receivedAt", "intitule" => "Reçu le", "type" => "Date"],
                        ["code" => "documents", "intitule" => "Documents", "type" => "Collection", "targetEntity" => Document::class, "displayField" => "nom"],
                    ]
                ];
            case Chargement::class:
                return [
                    "parametres" => [
                        "description" => "Type de chargement",
                        "icone" => "mdi:cog-transfer",
                        'background_image' => '/images/fitures/default.jpg',
                        'description_template' => [
                            "Type de chargement : [[*nom]].",
                            " Description : <em>« [[description]] »</em>."
                        ]
                    ],
                    "liste" => [
                        ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                        ["code" => "nom", "intitule" => "Nom", "type" => "Texte"],
                        ["code" => "description", "intitule" => "Description", "type" => "Texte"],
                        ["code" => "fonction", "intitule" => "Fonction", "type" => "Texte"], // Maybe a calculated field to get the text? For now, it's just the int.
                        ["code" => "chargementPourPrimes", "intitule" => "Utilisations (Primes)", "type" => "Collection", "targetEntity" => ChargementPourPrime::class, "displayField" => "nom"],
                        ["code" => "typeRevenus", "intitule" => "Utilisations (Revenus)", "type" => "Collection", "targetEntity" => TypeRevenu::class, "displayField" => "nom"],
                    ]
                ];
            case AutoriteFiscale::class:
                return [
                    "parametres" => [
                        "description" => "Autorité Fiscale",
                        "icone" => "mdi:bank",
                        'background_image' => '/images/fitures/default.jpg',
                        'description_template' => [
                            "L'autorité fiscale [[*nom]] ([[abreviation]]) est responsable de la collecte des taxes."
                        ]
                    ],
                    "liste" => [
                        ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                        ["code" => "nom", "intitule" => "Nom", "type" => "Texte", "col_principale" => true, "textes_secondaires" => [
                            ["attribut_code" => "description"]
                        ]],
                    ]
                ];
            case ChargementPourPrime::class:
                return [
                    "parametres" => [
                        "description" => "Chargement sur Prime",
                        "icone" => "mdi:cash-plus",
                        'background_image' => '/images/fitures/default.jpg',
                        'description_template' => [
                            "Chargement [[*nom]] d'un montant de [[montantFlatExceptionel]]",
                            " sur la cotation [[cotation]]."
                        ]
                    ],
                    "liste" => [
                        ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                        ["code" => "nom", "intitule" => "Nom", "type" => "Texte"],
                        ["code" => "type", "intitule" => "Type de chargement", "type" => "Relation", "targetEntity" => Chargement::class, "displayField" => "nom"],
                        ["code" => "cotation", "intitule" => "Cotation", "type" => "Relation", "targetEntity" => Cotation::class, "displayField" => "nom"],
                        ["code" => "montantFlatExceptionel", "intitule" => "Montant", "type" => "Nombre", "unite" => $this->serviceMonnaies->getCodeMonnaieAffichage()],
                        ["code" => "createdAt", "intitule" => "Créé le", "type" => "Date"],
                    ]
                ];
            case CompteBancaire::class:
                return [
                    "parametres" => [
                        "description" => "Compte Bancaire",
                        "icone" => "mdi:bank",
                        'background_image' => '/images/fitures/default.jpg',
                        'description_template' => [
                            "Compte [[*nom]] - [[banque]].",
                            " N° [[numero]] / [[intitule]]."
                        ]
                    ],
                    "liste" => [
                        ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                        ["code" => "nom", "intitule" => "Nom", "type" => "Texte"],
                        ["code" => "intitule", "intitule" => "Intitulé", "type" => "Texte"],
                        ["code" => "numero", "intitule" => "Numéro", "type" => "Texte"],
                        ["code" => "banque", "intitule" => "Banque", "type" => "Texte"],
                        ["code" => "codeSwift", "intitule" => "Code Swift", "type" => "Texte"],
                        ["code" => "documents", "intitule" => "Documents", "type" => "Collection", "targetEntity" => Document::class, "displayField" => "nom"],
                        ["code" => "paiements", "intitule" => "Paiements", "type" => "Collection", "targetEntity" => Paiement::class, "displayField" => "reference"],
                    ]
                ];
            case Note::class:
                return [
                    "parametres" => [
                        "description" => "Note (Débit/Crédit)",
                        "icone" => "mdi:note-text",
                        'background_image' => '/images/fitures/default.jpg',
                        'description_template' => [
                            "Note: [[*nom]] - Réf: [[reference]].",
                            " Adressée à [[addressed_to_string]].",
                            " Statut: [[status_string]]."
                        ]
                    ],
                    "liste" => [
                        ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                        ["code" => "nom", "intitule" => "Nom", "type" => "Texte", "col_principale" => true, "textes_secondaires" => [
                            ["attribut_code" => "reference"]
                        ]],
                        ["code" => "validated", "intitule" => "Validée", "type" => "Booleen"],
                        ["code" => "createdAt", "intitule" => "Créée le", "type" => "Date"],
                        ["code" => "paiements", "intitule" => "Paiements", "type" => "Collection", "targetEntity" => Paiement::class],
                    ]
                ];
            case Paiement::class:
                return [
                    "parametres" => [
                        "description" => "Paiement",
                        "icone" => "mdi:cash-multiple",
                        'background_image' => '/images/fitures/default.jpg',
                        'description_template' => [
                            "Paiement Réf: [[*reference]] d'un montant de [[montant]].",
                            " Payé le [[paidAt]]."
                        ]
                    ],
                    "liste" => [
                        ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                        ["code" => "reference", "intitule" => "Référence", "type" => "Texte", "col_principale" => true, "textes_secondaires" => [
                            ["attribut_code" => "description"]
                        ]],
                        ["code" => "offreIndemnisationSinistre", "intitule" => "Offre", "type" => "Relation", "targetEntity" => OffreIndemnisationSinistre::class, "displayField" => "nom"],
                        ["code" => "montant", "intitule" => "Montant", "type" => "Nombre", "unite" => "$"],
                        ["code" => "paidAt", "intitule" => "Payé le", "type" => "Date"],
                        ["code" => "preuves", "intitule" => "Preuves (Documents)", "type" => "Collection", "targetEntity" => Document::class],
                    ]
                ];
            case Taxe::class:
                return [
                    "parametres" => [
                        "description" => "Taxe",
                        "icone" => "mdi:percent-box",
                        'background_image' => '/images/fitures/default.jpg',
                        'description_template' => [
                            "Taxe: [[*code]] - [[description]].",
                            " Taux IARD: [[tauxIARD]]%, Taux VIE: [[tauxVIE]]%."
                        ]
                    ],
                    "liste" => [
                        ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                        ["code" => "code", "intitule" => "Code", "type" => "Texte", "col_principale" => true, "textes_secondaires" => [
                            ["attribut_code" => "description"]
                        ]],
                        ["code" => "tauxIARD", "intitule" => "Taux IARD", "type" => "Nombre", "unite" => "%"],
                        ["code" => "tauxVIE", "intitule" => "Taux VIE", "type" => "Nombre", "unite" => "%"],
                    ]
                ];
            case Tranche::class:
                return [
                    "parametres" => [
                        "description" => "Tranche",
                        "icone" => "mdi:chart-pie",
                        'background_image' => '/images/fitures/default.jpg',
                        'description_template' => [
                            "Tranche: [[*nom]] représentant [[pourcentage]]% de la cotation [[cotation]],",
                            " payable le [[payableAt]]."
                        ]
                    ],
                    "liste" => [
                        ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                        ["code" => "nom", "intitule" => "Nom", "type" => "Texte", "col_principale" => true, "textes_secondaires" => [
                            ["attribut_code" => "cotation", "attribut_prefixe" => "Cotation: "]
                        ]],
                        ["code" => "montantFlat", "intitule" => "Montant", "type" => "Nombre", "unite" => "$"],
                        ["code" => "pourcentage", "intitule" => "Pourcentage", "type" => "Nombre", "unite" => "%"],
                        ["code" => "payableAt", "intitule" => "Payable le", "type" => "Date"],
                    ]
                ];
            case TypeRevenu::class:
                return [
                    "parametres" => [
                        "description" => "Type de Revenu",
                        "icone" => "mdi:cash-register",
                        'background_image' => '/images/fitures/default.jpg',
                        'description_template' => [
                            "Type de revenu: [[*nom]].",
                            " Redevable: [[redevable_string]]. Partagé: [[shared_string]]."
                        ]
                    ],
                    "liste" => [
                        ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                        ["code" => "nom", "intitule" => "Nom", "type" => "Texte", "col_principale" => true],
                        ["code" => "pourcentage", "intitule" => "Pourcentage", "type" => "Nombre", "unite" => "%"],
                        ["code" => "montantflat", "intitule" => "Montant", "type" => "Nombre", "unite" => "$"],
                        ["code" => "shared", "intitule" => "Partagé", "type" => "Booleen"],
                    ]
                ];
            case Classeur::class:
                return [
                    "parametres" => [
                        "description" => "Classeur",
                        "icone" => "mdi:folder-multiple",
                        'background_image' => '/images/fitures/default.jpg',
                        'description_template' => [
                            "Classeur: [[*nom]].",
                            " <em>« [[description]] »</em>"
                        ]
                    ],
                    "liste" => [
                        ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                        ["code" => "nom", "intitule" => "Nom", "type" => "Texte"],
                        ["code" => "description", "intitule" => "Description", "type" => "Texte"],
                        ["code" => "documents", "intitule" => "Documents", "type" => "Collection", "targetEntity" => Document::class, "displayField" => "nom"],
                    ]
                ];
            case Document::class:
                return [
                    "parametres" => [
                        "description" => "Document",
                        "icone" => "mdi:file-document",
                        'background_image' => '/images/fitures/default.jpg',
                        'description_template' => [
                            "Document: [[*nom]].",
                            " Fichier: <em>[[nomFichierStocke]]</em>"
                        ]
                    ],
                    "liste" => [
                        ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                        ["code" => "nom", "intitule" => "Nom", "type" => "Texte"],
                        ["code" => "nomFichierStocke", "intitule" => "Fichier", "type" => "Texte"],
                        [
                            "code" => "parent_string",
                            "intitule" => "Associé à",
                            "type" => "Calcul",
                            "format" => "Texte",
                            "fonction" => "Document_getParentAsString",
                            "description" => "L'entité parente à laquelle ce document est attaché."
                        ],
                        ["code" => "createdAt", "intitule" => "Créé le", "type" => "Date"],
                    ]
                ];
            case Entreprise::class:
                return [
                    "parametres" => [
                        "description" => "Entreprise",
                        "icone" => "mdi:office-building",
                        'background_image' => '/images/fitures/default.jpg',
                        'description_template' => [
                            "Entreprise: [[*nom]].",
                            " Licence: [[licence]]."
                        ]
                    ],
                    "liste" => [
                        ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                        ["code" => "nom", "intitule" => "Nom", "type" => "Texte", "col_principale" => true, "textes_secondaires" => [
                            ["attribut_code" => "licence", "attribut_prefixe" => "Licence: "]
                        ]],
                        ["code" => "createdAt", "intitule" => "Créé le", "type" => "Date"],
                    ]
                ];
            case Invite::class:
                return [
                    "parametres" => [
                        "description" => "Invitation",
                        "icone" => "mdi:email-plus",
                        'background_image' => '/images/fitures/default.jpg',
                        'description_template' => [
                            "Invitation pour [[*email]] ([[nom]]). Statut: [[status_string]]."
                        ]
                    ],
                    "liste" => [
                        ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                        ["code" => "email", "intitule" => "Email", "type" => "Texte", "col_principale" => true],
                        ["code" => "createdAt", "intitule" => "Invité le", "type" => "Date"],
                        ["code" => "isVerified", "intitule" => "Acceptée", "type" => "Booleen"],
                    ]
                ];
            case Utilisateur::class:
                return [
                    "parametres" => [
                        "description" => "Utilisateur",
                        "icone" => "mdi:account-key",
                        'background_image' => '/images/fitures/default.jpg',
                        'description_template' => [
                            "Utilisateur: [[*nom]] ([[email]]). Statut: [[status_string]]."
                        ]
                    ],
                    "liste" => [
                        ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                        ["code" => "nom", "intitule" => "Nom", "type" => "Texte", "col_principale" => true],
                        ["code" => "email", "intitule" => "Email", "type" => "Texte"],
                        ["code" => "isVerified", "intitule" => "Vérifié", "type" => "Booleen"],
                    ]
                ];
            default:
                return [];
        }
    }
}
