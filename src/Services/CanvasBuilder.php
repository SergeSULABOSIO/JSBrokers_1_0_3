<?php

namespace App\Services;

use App\Entity\Note;
use App\Entity\Taxe;
use App\Entity\Piste;
use App\Entity\Tache;
use App\Entity\Client;
use App\Entity\Groupe;
use App\Entity\Invite;
use App\Entity\Risque;
use DateTimeImmutable;
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
use App\Entity\Police;
use App\Entity\Monnaie;
use App\Entity\TypeRevenu;
use App\Entity\Utilisateur;
use App\Constantes\Constante;
use App\Entity\PieceSinistre;
use App\Entity\CompteBancaire;
use App\Entity\AutoriteFiscale;
use App\Entity\ConditionPartage;
use App\Entity\ChargementPourPrime;
use App\Entity\NotificationSinistre;
use App\Entity\OffreIndemnisationSinistre;

class CanvasBuilder
{
    public function __construct(
        private ServiceMonnaies $serviceMonnaies,
        private Constante $constante,
        private ServiceDates $serviceDates,
    ) {
    }

    // ================================================================== //
    // == FONCTION PUBLIQUE PRINCIPALE (AIGUILLEUR)                    == //
    // ================================================================== //
    public function getEntityCanvas(string $entityClassName): array
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
            case Police::class:
                return [
                    "parametres" => [
                        "description" => "Police d'Assurance",
                        "icone" => "mdi:file-document-check-outline",
                        'background_image' => '/images/fitures/default.jpg',
                        'description_template' => [
                            "Police n°[[*numero]] souscrite par [[assure]] auprès de [[assureur]].",
                            " Période de couverture du [[startingAt]] au [[endingAt]]."
                        ]
                    ],
                    "liste" => [
                        ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                        ["code" => "numero", "intitule" => "Numéro de Police", "type" => "Texte"],
                        ["code" => "assure", "intitule" => "Assuré", "type" => "Relation", "targetEntity" => Client::class, "displayField" => "nom"],
                        ["code" => "assureur", "intitule" => "Assureur", "type" => "Relation", "targetEntity" => Assureur::class, "displayField" => "nom"],
                        ["code" => "startingAt", "intitule" => "Date d'effet", "type" => "Date"],
                        ["code" => "endingAt", "intitule" => "Date d'échéance", "type" => "Date"],
                        ["code" => "avenants", "intitule" => "Avenants", "type" => "Collection", "targetEntity" => Avenant::class, "displayField" => "numero"],
                        ["code" => "documents", "intitule" => "Documents", "type" => "Collection", "targetEntity" => Document::class, "displayField" => "nom"],
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

    /**
     * Construit le "canevas de recherche" pour une entité donnée.
     * Ce canevas définit les critères disponibles pour la recherche simple et avancée,
     * en s'inspirant de la structure utilisée par le contrôleur Stimulus `search-bar`.
     *
     * @param string $entityClassName Le FQCN (Fully Qualified Class Name) de l'entité.
     * @return array Un tableau de définitions de critères.
     */
    public function getSearchCanvas(string $entityClassName): array
    {
        $searchCriteria = [];
        $entityCanvas = $this->getEntityCanvas($entityClassName);

        // Si aucun canevas n'est défini pour cette entité, on ne peut rien faire.
        if (empty($entityCanvas) || !isset($entityCanvas['liste'])) {
            return [];
        }

        foreach ($entityCanvas['liste'] as $field) {
            // On ignore les collections car elles ne sont pas des champs de recherche directs.
            if ($field['type'] === 'Collection') {
                continue;
            }

            // NOUVEAU : On ignore le champ 'id' qui n'est pas un critère de recherche pertinent.
            if ($field['code'] === 'id') {
                continue;
            }

            $criterion = [
                'Nom' => $field['code'],
                'Display' => $field['intitule'],
                'isDefault' => false, // Par défaut, aucun n'est le critère simple.
            ];

            // Mappage des types PHP vers les types attendus par le JavaScript
            switch ($field['type']) {
                case 'Texte':
                    $criterion['Type'] = 'Text';
                    $criterion['Valeur'] = '';
                    break;
                case 'Relation': // Les relations sont souvent recherchées via un champ texte.
                    $criterion['Type'] = 'Text'; // Pour le frontend, c'est un champ texte.
                    $criterion['Valeur'] = '';
                    $criterion['targetField'] = $field['displayField'] ?? 'nom'; // On spécifie sur quel champ de la relation chercher.
                    break;

                case 'Nombre':
                case 'Entier':
                    $criterion['Type'] = 'Number';
                    $criterion['Valeur'] = 0;
                    break;

                case 'Date':
                    // Un champ de date unique est transformé en une plage de dates pour la recherche.
                    $criterion['Type'] = 'DateTimeRange';
                    $criterion['Valeur'] = ['from' => '', 'to' => ''];
                    break;

                case 'Booleen':
                    $criterion['Type'] = 'Options'; // Un booléen peut être représenté par des options "Oui/Non".
                    $criterion['Valeur'] = [
                        '1' => 'Oui',
                        '0' => 'Non',
                    ];
                    break;

                default:
                    continue 2; // On saute ce champ si son type n'est pas géré.
            }
            $searchCriteria[] = $criterion;
        }
        return $searchCriteria;
    }

    public function getListeCanvas(string $entityClassName): array
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


    public function getEntityFormCanvas($object, $idEntreprise): array
    {
        $isParentNew = ($object->getId() === null);
        $entityClassName = get_class($object);
        $layout = [];
        $parametres = [];

        switch ($entityClassName) {
            case NotificationSinistre::class:
                $notificationId = $object->getId() ?? 0;
                $parametres = [
                    "titre_creation" => "Nouvelle Notification de Sinistre",
                    "titre_modification" => "Modification de la Notification #%id%",
                    "endpoint_submit_url" => "/admin/notificationsinistre/api/submit",
                    "endpoint_delete_url" => "/admin/notificationsinistre/api/delete",
                    "endpoint_form_url" => "/admin/notificationsinistre/api/get-form",
                    "isCreationMode" => $isParentNew
                ];
                $layout = $this->buildNotificationSinistreLayout($notificationId, $isParentNew);
                break;

            case Contact::class:
                $contactId = $object->getId() ?? 0;
                $parametres = [
                    "titre_creation" => "Nouveau contact",
                    "titre_modification" => "Modification du contact #%id%",
                    "endpoint_submit_url" => "/admin/contact/api/submit",
                    "endpoint_delete_url" => "/admin/contact/api/delete",
                    "endpoint_form_url" => "/admin/contact/api/get-form",
                    "isCreationMode" => $isParentNew
                ];
                $layout = $this->buildContactLayout($contactId, $isParentNew);
                break;

            case Client::class:
                $clientId = $object->getId() ?? 0;
                $parametres = [
                    "titre_creation" => "Nouveau Client",
                    "titre_modification" => "Modification du Client #%id%",
                    "endpoint_submit_url" => "/admin/client/api/submit",
                    "endpoint_delete_url" => "/admin/client/api/delete",
                    "endpoint_form_url" => "/admin/client/api/get-form",
                    "isCreationMode" => $isParentNew
                ];
                $layout = $this->buildClientLayout($clientId, $isParentNew);
                break;

            case Assureur::class:
                $assureurId = $object->getId() ?? 0;
                $parametres = [
                    "titre_creation" => "Nouvel Assureur",
                    "titre_modification" => "Modification de l'Assureur #%id%",
                    "endpoint_submit_url" => "/admin/assureur/api/submit",
                    "endpoint_delete_url" => "/admin/assureur/api/delete",
                    "endpoint_form_url" => "/admin/assureur/api/get-form",
                    "isCreationMode" => $isParentNew
                ];
                $layout = $this->buildAssureurLayout($assureurId, $isParentNew);
                break;

            case Piste::class:
                $pisteId = $object->getId() ?? 0;
                $parametres = [
                    "titre_creation" => "Nouvelle Piste",
                    "titre_modification" => "Modification de la Piste #%id%",
                    "endpoint_submit_url" => "/admin/piste/api/submit",
                    "endpoint_delete_url" => "/admin/piste/api/delete",
                    "endpoint_form_url" => "/admin/piste/api/get-form",
                    "isCreationMode" => $isParentNew
                ];
                $layout = $this->buildPisteLayout($pisteId, $isParentNew);
                break;

            case Cotation::class:
                $cotationId = $object->getId() ?? 0;
                $parametres = [
                    "titre_creation" => "Nouvelle Cotation",
                    "titre_modification" => "Modification de la Cotation #%id%",
                    "endpoint_submit_url" => "/admin/cotation/api/submit",
                    "endpoint_delete_url" => "/admin/cotation/api/delete",
                    "endpoint_form_url" => "/admin/cotation/api/get-form",
                    "isCreationMode" => $isParentNew
                ];
                $layout = $this->buildCotationLayout($cotationId, $isParentNew);
                break;

            case Avenant::class:
                $avenantId = $object->getId() ?? 0;
                $parametres = [
                    "titre_creation" => "Nouvel Avenant",
                    "titre_modification" => "Modification de l'Avenant #%id%",
                    "endpoint_submit_url" => "/admin/avenant/api/submit",
                    "endpoint_delete_url" => "/admin/avenant/api/delete",
                    "endpoint_form_url" => "/admin/avenant/api/get-form",
                    "isCreationMode" => $isParentNew
                ];
                $layout = $this->buildAvenantLayout($avenantId, $isParentNew);
                break;

            case PieceSinistre::class:
                $pieceId = $object->getId() ?? 0;
                $parametres = [
                    "titre_creation" => "Nouvelle pièce",
                    "titre_modification" => "Modification de la pièce #%id%",
                    "endpoint_submit_url" => "/admin/piecesinistre/api/submit",
                    "endpoint_delete_url" => "/admin/piecesinistre/api/delete",
                    "endpoint_form_url" => "/admin/piecesinistre/api/get-form",
                    "isCreationMode" => $isParentNew
                ];
                $layout = $this->buildPieceSinistreLayout($pieceId, $isParentNew);
                break;

            case Document::class:
                $documentId = $object->getId() ?? 0;
                $parametres = [
                    "titre_creation" => "Nouveau Document",
                    "titre_modification" => "Modification du document #%id%",
                    "endpoint_submit_url" => "/admin/document/api/submit",
                    "endpoint_delete_url" => "/admin/document/api/delete",
                    "endpoint_form_url" => "/admin/document/api/get-form",
                    "isCreationMode" => $isParentNew
                ];
                $layout = $this->buildDocumentLayout($documentId, $isParentNew);
                break;

            case OffreIndemnisationSinistre::class:
                $offreId = $object->getId() ?? 0;
                $parametres = [
                    "titre_creation" => "Nouvelle offre d'indemnisation",
                    "titre_modification" => "Modification de l'offre #%id%",
                    "endpoint_submit_url" => "/admin/offreindemnisationsinistre/api/submit",
                    "endpoint_delete_url" => "/admin/offreindemnisationsinistre/api/delete",
                    "endpoint_form_url" => "/admin/offreindemnisationsinistre/api/get-form",
                    "isCreationMode" => $isParentNew
                ];
                $layout = $this->buildOffreIndemnisationLayout($offreId, $isParentNew);
                break;

            case Tache::class:
                $tacheId = $object->getId() ?? 0;
                $parametres = [
                    "titre_creation" => "Nouvelle tâche",
                    "titre_modification" => "Modification de la tâche #%id%",
                    "endpoint_submit_url" => "/admin/tache/api/submit",
                    "endpoint_delete_url" => "/admin/tache/api/delete",
                    "endpoint_form_url" => "/admin/tache/api/get-form",
                    "isCreationMode" => $isParentNew
                ];
                $layout = $this->buildTacheLayout($tacheId, $isParentNew);
                break;

            case Paiement::class:
                $paiementId = $object->getId() ?? 0;
                $parametres = [
                    "titre_creation" => "Nouveau Paiement",
                    "titre_modification" => "Modification du paiement #%id%",
                    "endpoint_submit_url" => "/admin/paiement/api/submit",
                    "endpoint_delete_url" => "/admin/paiement/api/delete",
                    "endpoint_form_url" => "/admin/paiement/api/get-form",
                    "isCreationMode" => $isParentNew
                ];
                $layout = $this->buildPaiementLayout($paiementId, $isParentNew);
                break;

            case Feedback::class:
                $feedbackId = $object->getId() ?? 0;
                $parametres = [
                    "titre_creation" => "Nouveau Feedback",
                    "titre_modification" => "Modification du feedback #%id%",
                    "endpoint_submit_url" => "/admin/feedback/api/submit",
                    "endpoint_delete_url" => "/admin/feedback/api/delete",
                    "endpoint_form_url" => "/admin/feedback/api/get-form",
                    "isCreationMode" => $isParentNew
                ];
                $layout = $this->buildFeedbackLayout($feedbackId, $isParentNew);
                break;

            default:
                return [];
        }

        // Si aucune configuration n'a été trouvée, on retourne un tableau vide.
        if (empty($parametres) && empty($layout)) {
            return [];
        }

        return [
            "parametres" => $parametres,
            "form_layout" => $layout,
            "fields_map" => $this->buildFieldsMap($layout) // Ajout de la carte des champs pour un accès optimisé
        ];
    }

    /**
     * Construit dynamiquement le layout du formulaire pour NotificationSinistre.
     *
     * @param integer $notificationId
     * @param boolean $isParentNew
     * @return array
     */
    private function buildNotificationSinistreLayout(int $notificationId, bool $isParentNew): array
    {
        $layout = [
            // Ligne 1 : 2 colonnes
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["assure"]], ["champs" => ["assureur"]]]],
            // Ligne 2 : 1 colonne
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["risque"]]]],
            // Ligne 3 : 2 colonnes
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["referencePolice"]], ["champs" => ["referenceSinistre"]]]],
            // Ligne 4 : 1 colonne
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["descriptionDeFait"]]]],
            // Ligne 5 : 3 colonnes
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["occuredAt"]], ["champs" => ["notifiedAt"]], ["champs" => ["lieu"]]]],
            // Ligne 6 : 1 colonne 
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["descriptionVictimes"]]]],
            // Ligne 7 : 2 colonnes
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["dommage"]], ["champs" => ["evaluationChiffree"]]]]
        ];

        // On ajoute toujours les lignes de collection. Leur état sera géré par le flag 'disabled'.
        $layout[] = [
            "couleur_fond" => "white",
            "colonnes" => [
                ["champs" => [$this->getCollectionWidgetConfig('contacts', 'contact', $notificationId, "Contact", "notificationSinistre", null, $isParentNew)]],
            ]
        ];
        $layout[] = [
            "couleur_fond" => "white",
            "colonnes" => [
                ["champs" => [$this->getCollectionWidgetConfig('pieces', 'piecesinistre', $notificationId, "Pièce Sinistre", "notificationSinistre", null, $isParentNew)]]
            ]
        ];
        $layout[] = [
            "couleur_fond" => "white",
            "colonnes" => [
                ["champs" => [$this->getCollectionWidgetConfig('offreIndemnisationSinistres', 'offreindemnisationsinistre', $notificationId, "Offre d'indemnisation", "notificationSinistre", null, $isParentNew)]]
            ]
        ];
        $layout[] = [
            "couleur_fond" => "white",
            "colonnes" => [
                ["champs" => [$this->getCollectionWidgetConfig('taches', 'tache', $notificationId, "Tâche", "notificationSinistre", null, $isParentNew)]]
            ]
        ];

        return $layout;
    }

    private function buildClientLayout(int $clientId, bool $isParentNew): array
    {
        $layout = [
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["nom"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["email"]], ["champs" => ["telephone"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["adresse"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["groupe"]]]],
        ];

        $layout[] = ["couleur_fond" => "white", "colonnes" => [["champs" => [$this->getCollectionWidgetConfig('contacts', 'contact', $clientId, "Contact", "client", null, $isParentNew)]]]];
        $layout[] = ["couleur_fond" => "white", "colonnes" => [["champs" => [$this->getCollectionWidgetConfig('pistes', 'piste', $clientId, "Piste", "client", null, $isParentNew)]]]];
        $layout[] = ["couleur_fond" => "white", "colonnes" => [["champs" => [$this->getCollectionWidgetConfig('notificationSinistres', 'notificationsinistre', $clientId, "Sinistre", "assure", null, $isParentNew)]]]];
        $layout[] = ["couleur_fond" => "white", "colonnes" => [["champs" => [$this->getCollectionWidgetConfig('documents', 'document', $clientId, "Document", "client", null, $isParentNew)]]]];
        $layout[] = ["couleur_fond" => "white", "colonnes" => [["champs" => [$this->getCollectionWidgetConfig('partenaires', 'partenaire', $clientId, "Partenaire", "client", null, $isParentNew)]]]];

        return $layout;
    }

    private function buildAssureurLayout(int $assureurId, bool $isParentNew): array
    {
        $layout = [
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["nom"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["email"]], ["champs" => ["telephone"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["adressePhysique"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["numimpot"]], ["champs" => ["idnat"]], ["champs" => ["rccm"]]]],
        ];

        $layout[] = ["couleur_fond" => "white", "colonnes" => [["champs" => [$this->getCollectionWidgetConfig('cotations', 'cotation', $assureurId, "Cotation", "assureur", null, $isParentNew)]]]];
        $layout[] = ["couleur_fond" => "white", "colonnes" => [["champs" => [$this->getCollectionWidgetConfig('bordereaus', 'bordereau', $assureurId, "Bordereau", "assureur", null, $isParentNew)]]]];
        $layout[] = ["couleur_fond" => "white", "colonnes" => [["champs" => [$this->getCollectionWidgetConfig('notificationSinistres', 'notificationsinistre', $assureurId, "Sinistre", "assureur", null, $isParentNew)]]]];

        return $layout;
    }

    private function buildPisteLayout(int $pisteId, bool $isParentNew): array
    {
        $layout = [
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["nom"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["client"]], ["champs" => ["risque"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["primePotentielle"]]]],
        ];

        $layout[] = ["couleur_fond" => "white", "colonnes" => [["champs" => [$this->getCollectionWidgetConfig('cotations', 'cotation', $pisteId, "Cotation", "piste", null, $isParentNew)]]]];
        $layout[] = ["couleur_fond" => "white", "colonnes" => [["champs" => [$this->getCollectionWidgetConfig('documents', 'document', $pisteId, "Document", "piste", null, $isParentNew)]]]];

        return $layout;
    }

    private function buildCotationLayout(int $cotationId, bool $isParentNew): array
    {
        $layout = [
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["nom"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["piste"]], ["champs" => ["assureur"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["duree"]]]],
        ];

        $layout[] = ["couleur_fond" => "white", "colonnes" => [["champs" => [$this->getCollectionWidgetConfig('avenants', 'avenant', $cotationId, "Avenant", "cotation", null, $isParentNew)]]]];
        $layout[] = ["couleur_fond" => "white", "colonnes" => [["champs" => [$this->getCollectionWidgetConfig('taches', 'tache', $cotationId, "Tâche", "cotation", null, $isParentNew)]]]];
        $layout[] = ["couleur_fond" => "white", "colonnes" => [["champs" => [$this->getCollectionWidgetConfig('documents', 'document', $cotationId, "Document", "cotation", null, $isParentNew)]]]];

        return $layout;
    }

    private function buildAvenantLayout(int $avenantId, bool $isParentNew): array
    {
        $layout = [
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["cotation"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["numero"]], ["champs" => ["referencePolice"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["description"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["startingAt"]], ["champs" => ["endingAt"]]]],
        ];

        $layout[] = [
            "couleur_fond" => "white",
            "colonnes" => [
                ["champs" => [$this->getCollectionWidgetConfig('documents', 'document', $avenantId, "Document", 'avenant', null, $isParentNew)]]
            ]
        ];

        return $layout;
    }

    private function buildPieceSinistreLayout(int $pieceId, bool $isParentNew): array
    {
        $layout = [
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["description"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["fourniPar"]], ["champs" => ["receivedAt"]], ["champs" => ["type"]]]],
        ];

        $layout[] = [
            "couleur_fond" => "white",
            "colonnes" => [
                ["champs" => [$this->getCollectionWidgetConfig('documents', 'document', $pieceId, "Document", 'pieceSinistre', null, $isParentNew)]]
            ]
        ];

        return $layout;
    }

    private function buildContactLayout(int $contactId, bool $isParentNew): array
    {
        $layout = [
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["nom"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["email"]], ["champs" => ["telephone"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["fonction"]], ["champs" => ["type"]]]],
        ];

        return $layout;
    }

    private function buildOffreIndemnisationLayout(int $offreId, bool $isParentNew): array
    {
        $layout = [
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["nom"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["beneficiaire"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["franchiseAppliquee"]], ["champs" => ["montantPayable"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["referenceBancaire"]]]],
        ];

        $layout[] = [
            "couleur_fond" => "white",
            "colonnes" => [
                ["champs" => [$this->getCollectionWidgetConfig('taches', 'tache', $offreId, "Tâche", "offreIndemnisationSinistre", null, $isParentNew)]],
            ]
        ];
        $layout[] = [
            "couleur_fond" => "white",
            "colonnes" => [
                ["champs" => [$this->getCollectionWidgetConfig('documents', 'document', $offreId, "Document", 'offreIndemnisationSinistre', null, $isParentNew)]],
            ]
        ];
        $layout[] = [
            "couleur_fond" => "white",
            "colonnes" => [
                ["champs" => [$this->getCollectionWidgetConfig('paiements', 'paiement', $offreId, "Paiement", "offreIndemnisationSinistre", ['source' => 'montantPayable', 'target' => 'montant'], $isParentNew)]],
            ]
        ];

        return $layout;
    }

    private function buildDocumentLayout(int $documentId, bool $isParentNew): array
    {
        $layout = [
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["nom"]], ["champs" => ["classeur"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["fichier"]]]],
        ];

        return $layout;
    }

    private function buildTacheLayout(int $tacheId, bool $isParentNew): array
    {
        $layout = [
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["description"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["toBeEndedAt"]], ["champs" => ["executor"]], ["champs" => ["closed"]]]],
        ];

        $layout[] = [
            "couleur_fond" => "white",
            "colonnes" => [
                ["champs" => [$this->getCollectionWidgetConfig('feedbacks', 'feedback', $tacheId, "Feedback", 'tache', null, $isParentNew)]],
            ]
        ];
        $layout[] = [
            "couleur_fond" => "white",
            "colonnes" => [
                ["champs" => [$this->getCollectionWidgetConfig('documents', 'document', $tacheId, "Document", 'tache', null, $isParentNew)]],
            ]
        ];

        return $layout;
    }

    private function buildPaiementLayout(int $paiementId, bool $isParentNew): array
    {
        $layout = [
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["montant"]], ["champs" => ["reference"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["description"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["paidAt"]], ["champs" => ["CompteBancaire"]]]],
        ];

        $layout[] = [
            "couleur_fond" => "white",
            "colonnes" => [
                ["champs" => [$this->getCollectionWidgetConfig('preuves', 'document', $paiementId, "Preuve", 'paiement', null, $isParentNew)]]
            ]
        ];

        return $layout;
    }

    private function buildFeedbackLayout(int $feedbackId, bool $isParentNew): array
    {
        $layout = [
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["description"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["hasNextAction"]], ["champs" => ["nextActionAt"]], ["champs" => ["type"]]]],
        ];

        // On ajoute toujours la ligne de collection.
        $layout[] = [
            "couleur_fond" => "white",
            "colonnes" => [
                ["champs" => ["nextAction"]],
                ["champs" => [$this->getCollectionWidgetConfig('documents', 'document', $feedbackId, "Document", 'feedback', null, $isParentNew)]]
            ]
        ];
        return $layout;
    }

    /**
     * MODIFIÉ : Accepte maintenant le nom de la route de l'entité en paramètre.
     *
     * @param string $fieldName Le nom de l'attribut dans l'entité parente (ex: 'contacts', 'pieces').
     * @param string $entityRouteName Le nom utilisé dans la route pour cette entité (ex: 'contact', 'piecesinistre').
     * @param integer $parentId L'ID de l'entité parente.
     * @return array
     */
    private function getCollectionWidgetConfig(string $fieldName, string $entityRouteName, int $parentId, string $formtitle, string $parentFieldName, ?array $defaultValueConfig = null, bool $isParentNew = false): array
    {
        // L'ancienne logique de mappage est supprimée. On utilise directement le paramètre.
        $config = [
            "field_code" => $fieldName,
            "widget" => "collection",
            "options" => [
                "listUrl"       => "/admin/" . strtolower($parentFieldName) . "/api/" . $parentId . "/" . $fieldName,
                "itemFormUrl"   => "/admin/" . $entityRouteName . "/api/get-form",
                "itemSubmitUrl" => "/admin/" . $entityRouteName . "/api/submit",
                "itemDeleteUrl" => "/admin/" . $entityRouteName . "/api/delete",
                "itemTitleCreate" => "Ajouter : " . $formtitle,
                "itemTitleEdit" => "Modifier : " . $formtitle . " #%id%",
                "parentEntityId" => $parentId,
                "parentFieldName" => $parentFieldName,
                "disabled" => $isParentNew, // Indique si le widget doit être désactivé
                // L'URL est maintenant correcte, le JS l'utilisera
                "url" => "/admin/" . strtolower($parentFieldName) . "/api/" . $parentId . "/" . $fieldName,
            ]
        ];

        // Si une configuration de valeur par défaut est fournie, on l'ajoute aux options
        if ($defaultValueConfig) {
            $config['options']['defaultValueConfig'] = json_encode($defaultValueConfig);
        }

        return $config;
    }

    /**
     * NOUVEAU : Construit une carte "aplatie" des champs du formulaire pour un accès direct.
     *
     * @param array $formLayout La structure hiérarchique du layout.
     * @return array Une carte où les clés sont les 'field_code' et les valeurs sont la configuration du champ.
     */
    private function buildFieldsMap(array $formLayout): array
    {
        $fieldsMap = [];
        if (empty($formLayout)) {
            return $fieldsMap;
        }

        foreach ($formLayout as $row) {
            if (!isset($row['colonnes']) || !is_array($row['colonnes'])) continue;

            foreach ($row['colonnes'] as $col) {
                // La colonne peut contenir directement un champ ou un tableau de champs
                $fields = $col['champs'] ?? (is_array($col) ? [$col] : []);

                foreach ($fields as $field) {
                    if (is_array($field) && isset($field['field_code'])) {
                        $fieldsMap[$field['field_code']] = $field;
                    }
                }
            }
        }
        return $fieldsMap;
    }


    public function getNumericAttributesAndValues($object): array
    {
        if ($object instanceof NotificationSinistre) {
            return [
                "dommageAvantEvaluation" => [
                    "description" => "Dommages (av. éval.)",
                    "value" => ($object->getDommage() ?? 0) * 100,
                ],
                'dommageApresEvaluation' => [
                    "description" => "Dommages (ap. éval.)",
                    "value" => ($object->getEvaluationChiffree() ?? 0) * 100,
                ],
                'franchise' => [
                    "description" => "Franchise",
                    "value" => ($this->constante->Notification_Sinistre_getFranchise($object) ?? 0) * 100,
                ],
                "compensationTotale" => [
                    "description" => "Compensation totale",
                    "value" => ($this->constante->Notification_Sinistre_getCompensation($object) ?? 0) * 100,
                ],
                "compensationVersee" => [
                    "description" => "Compensation versée",
                    "value" => ($this->constante->Notification_Sinistre_getCompensationVersee($object) ?? 0) * 100,
                ],
                "compensationDue" => [
                    "description" => "Compensation due",
                    "value" => ($this->constante->Notification_Sinistre_getSoldeAVerser($object) ?? 0) * 100,
                ],
            ];
        }

        // --- AJOUT : Logique pour Assureur ---
        if ($object instanceof Assureur) {
            return [
                "montant_commission_ttc" => [
                    "description" => "Commissions TTC",
                    "value" => ($this->constante->Assureur_getMontant_commission_ttc($object, -1, false) ?? 0) * 100,
                ],
                "montant_commission_ttc_solde" => [
                    "description" => "Solde Commissions",
                    "value" => ($this->constante->Assureur_getMontant_commission_ttc_solde($object, -1, false) ?? 0) * 100,
                ],
                "montant_prime_payable_par_client_solde" => [
                    "description" => "Solde Primes Clients",
                    "value" => ($this->constante->Assureur_getMontant_prime_payable_par_client_solde($object) ?? 0) * 100,
                ],
            ];
        }

        if ($object instanceof Avenant) {
            return [
                "primeTTC" => [
                    "description" => "Prime TTC",
                    "value" => ($this->constante->Avenant_getPrimeTTC($object) ?? 0) * 100,
                ],
                "commissionTTC" => [
                    "description" => "Commission TTC",
                    "value" => ($this->constante->Avenant_getCommissionTTC($object, -1, false) ?? 0) * 100,
                ],
            ];
        }

        if ($object instanceof Bordereau) {
            return [
                "montantTTC" => [
                    "description" => "Montant TTC",
                    "value" => ($object->getMontantTTC() ?? 0) * 100,
                ],
            ];
        }

        if ($object instanceof Client) {
            return [
                "montant_commission_ttc" => [
                    "description" => "Commissions TTC",
                    "value" => ($this->constante->Client_getMontant_commission_ttc($object, -1, false) ?? 0) * 100,
                ],
                "montant_commission_ttc_solde" => [
                    "description" => "Solde Commissions",
                    "value" => ($this->constante->Client_getMontant_commission_ttc_solde($object, -1, false) ?? 0) * 100,
                ],
                "montant_prime_payable_par_client_solde" => [
                    "description" => "Solde Primes",
                    "value" => ($this->constante->Client_getMontant_prime_payable_par_client_solde($object) ?? 0) * 100,
                ],
            ];
        }

        if ($object instanceof Cotation) {
            return [
                "primeTTC" => [
                    "description" => "Prime TTC",
                    "value" => ($this->constante->Cotation_getMontant_prime_payable_par_client($object) ?? 0) * 100,
                ],
                "commissionTTC" => [
                    "description" => "Commission TTC",
                    "value" => ($this->constante->Cotation_getMontant_commission_ttc($object, -1, false) ?? 0) * 100,
                ],
            ];
        }

        // --- AJOUT : Logique pour OffreIndemnisationSinistre ---
        if ($object instanceof OffreIndemnisationSinistre) {
            return [
                "montantPayable" => [
                    "description" => "Montant Payable",
                    "value" => ($object->getMontantPayable() ?? 0) * 100,
                ],
                "franchiseAppliquee" => [
                    "description" => "Franchise",
                    "value" => ($object->getFranchiseAppliquee() ?? 0) * 100,
                ],
                "compensationVersee" => [
                    "description" => "Comp. versée",
                    "value" => ($this->constante->Offre_Indemnisation_getCompensationVersee($object) ?? 0) * 100,
                ],
                "compensationAVersee" => [
                    "description" => "Solde à verser",
                    "value" => ($this->constante->Offre_Indemnisation_getSoldeAVerser($object) ?? 0) * 100,
                ],
            ];
        }

        if ($object instanceof ChargementPourPrime) {
            return [
                "montantFlatExceptionel" => [
                    "description" => "Montant",
                    "value" => ($object->getMontantFlatExceptionel() ?? 0) * 100,
                ],
            ];
        }


        if ($object instanceof Contact || $object instanceof PieceSinistre || $object instanceof Tache) {
            // Ces entités n'ont pas de valeurs numériques à totaliser.
            return [];
        }

        return [];
    }

    public function getNumericAttributesAndValuesForTotalsBar($data): array
    {
        $numericValues = [];
        // NOUVEAU : Si les données sont vides, on retourne un objet vide (et non un tableau)
        // pour éviter une erreur de type dans le contrôleur Stimulus `list-manager`.
        if (empty($data)) {
            return $numericValues; // On retourne un tableau vide pour respecter le type de retour "array".
        }

        foreach ($data as $entity) {
            $numericValues[$entity->getId()] = $this->getNumericAttributesAndValues($entity);
        }
        return $numericValues;
    }

    /**
     * Calcule le délai en jours entre la survenance et la notification d'un sinistre.
     */
    public function Notification_Sinistre_getDelaiDeclaration(NotificationSinistre $sinistre): string
    {
        if (!$sinistre->getOccuredAt() || !$sinistre->getNotifiedAt()) {
            return 'N/A';
        }
        $jours = $this->serviceDates->daysEntre($sinistre->getOccuredAt(), $sinistre->getNotifiedAt());
        return $jours . ' jour(s)';
    }

    /**
     * Calcule l'âge du dossier sinistre depuis sa création.
     */
    public function Notification_Sinistre_getAgeDossier(NotificationSinistre $sinistre): string
    {
        if (!$sinistre->getCreatedAt()) {
            return 'N/A';
        }
        $jours = $this->serviceDates->daysEntre($sinistre->getCreatedAt(), new DateTimeImmutable());
        return $jours . ' jour(s)';
    }

    /**
     * Calcule le pourcentage de pièces fournies par rapport aux pièces attendues.
     */
    public function Notification_Sinistre_getIndiceCompletude(NotificationSinistre $sinistre): string
    {
        $attendus = count($this->constante->getEnterprise()->getModelePieceSinistres());
        if ($attendus === 0) {
            return '100 %'; // S'il n'y a aucune pièce modèle, le dossier est complet.
        }
        $fournis = count($sinistre->getPieces());
        $pourcentage = ($fournis / $attendus) * 100;
        return round($pourcentage) . ' %';
    }

    /**
     * Calcule le pourcentage payé d'une offre d'indemnisation.
     */
    public function Offre_Indemnisation_getPourcentagePaye(OffreIndemnisationSinistre $offre): string
    {
        $montantPayable = $offre->getMontantPayable();
        if ($montantPayable == 0 || $montantPayable === null) {
            return '100 %'; // Si rien n'est à payer, c'est considéré comme payé.
        }
        $totalVerse = $this->constante->Offre_Indemnisation_getCompensationVersee($offre);
        $pourcentage = ($totalVerse / $montantPayable) * 100;
        return round($pourcentage) . ' %';
    }
}