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
use App\Services\Canvas\CalculationProvider;

class EntityCanvasProvider
{
    public function __construct(
        private ServiceMonnaies $serviceMonnaies,
        private CalculationProvider $calculationProvider
    ) {
    }

    private function getGlobalIndicatorsCanvas(string $entityName): array
    {
        $indicators = [
            ['code' => 'prime_totale', 'intitule' => 'Prime Totale', 'description' => "Montant total de la prime brute due, toutes taxes et frais de chargement inclus, avant toute déduction.", 'is_percentage' => false],
            ['code' => 'prime_totale_payee', 'intitule' => 'Prime Payée', 'description' => "Cumul des paiements de prime déjà effectués par le client. Reflète l'état des encaissements.", 'is_percentage' => false],
            ['code' => 'prime_totale_solde', 'intitule' => 'Solde Prime', 'description' => "Montant de la prime totale qui reste à payer par le client. Indicateur clé du recouvrement.", 'is_percentage' => false],
            ['code' => 'commission_totale', 'intitule' => 'Commission Totale', 'description' => "Montant total de la commission TTC due au courtier, incluant toutes les taxes applicables sur la commission.", 'is_percentage' => false],
            ['code' => 'commission_totale_encaissee', 'intitule' => 'Commission Encaissée', 'description' => "Montant total de la commission que le courtier a effectivement déjà perçu, que ce soit de l'assureur ou du client.", 'is_percentage' => false],
            ['code' => 'commission_totale_solde', 'intitule' => 'Solde Commission', 'description' => "Montant de la commission totale qui reste à encaisser par le courtier. Essentiel pour la trésorerie.", 'is_percentage' => false],
            ['code' => 'commission_nette', 'intitule' => 'Commission Nette', 'description' => "Montant de la commission avant l'application des taxes (HT). C'est la base de calcul pour les impôts.", 'is_percentage' => false],
            ['code' => 'commission_pure', 'intitule' => 'Commission Pure', 'description' => "Commission nette après déduction des taxes à la charge du courtier. Représente le revenu brut du courtier.", 'is_percentage' => false],
            ['code' => 'commission_partageable', 'intitule' => 'Assiette Partageable', 'description' => "Part de la commission pure qui sert de base au calcul de la rétrocession due aux partenaires d'affaires.", 'is_percentage' => false],
            ['code' => 'prime_nette', 'intitule' => 'Prime Nette', 'description' => "Base de la prime utilisée pour le calcul des commissions. Exclut généralement les taxes et certains frais.", 'is_percentage' => false],
            ['code' => 'reserve', 'intitule' => 'Réserve Courtier', 'description' => "Bénéfice final revenant au courtier après paiement des taxes et des rétrocessions aux partenaires.", 'is_percentage' => false],
            ['code' => 'retro_commission_partenaire', 'intitule' => 'Rétrocommission Partenaire', 'description' => "Montant total de la commission à reverser aux partenaires d'affaires, calculé sur l'assiette partageable.", 'is_percentage' => false],
            ['code' => 'retro_commission_partenaire_payee', 'intitule' => 'Rétrocommission Payée', 'description' => "Montant de la rétrocommission qui a déjà été effectivement payé aux partenaires.", 'is_percentage' => false],
            ['code' => 'retro_commission_partenaire_solde', 'intitule' => 'Solde Rétrocommission', 'description' => "Montant de la rétrocommission qui reste à payer aux partenaires. Suivi des dettes envers les apporteurs.", 'is_percentage' => false],
            ['code' => 'taxe_courtier', 'intitule' => 'Taxe Courtier', 'description' => "Montant total des taxes dues par le courtier sur les commissions perçues. Une charge fiscale directe.", 'is_percentage' => false],
            ['code' => 'taxe_courtier_payee', 'intitule' => 'Taxe Courtier Payée', 'description' => "Montant des taxes sur commission que le courtier a déjà versées à l'autorité fiscale.", 'is_percentage' => false],
            ['code' => 'taxe_courtier_solde', 'intitule' => 'Solde Taxe Courtier', 'description' => "Montant des taxes sur commission restant à payer par le courtier à l'autorité fiscale.", 'is_percentage' => false],
            ['code' => 'taxe_assureur', 'intitule' => 'Taxe Assureur', 'description' => "Montant total des taxes dues par l'assureur sur les commissions. Le courtier agit souvent comme collecteur.", 'is_percentage' => false],
            ['code' => 'taxe_assureur_payee', 'intitule' => 'Taxe Assureur Payée', 'description' => "Montant des taxes sur commission que le courtier a déjà reversées à l'assureur (ou payées pour son compte).", 'is_percentage' => false],
            ['code' => 'taxe_assureur_solde', 'intitule' => 'Solde Taxe Assureur', 'description' => "Montant des taxes sur commission collectées par le courtier et restant à reverser à l'assureur.", 'is_percentage' => false],
            ['code' => 'sinistre_payable', 'intitule' => 'Sinistre Payable', 'description' => "Montant total des indemnisations convenues pour les sinistres survenus, avant tout paiement.", 'is_percentage' => false],
            ['code' => 'sinistre_paye', 'intitule' => 'Sinistre Payé', 'description' => "Montant total des indemnisations déjà versées aux assurés ou bénéficiaires pour les sinistres.", 'is_percentage' => false],
            ['code' => 'sinistre_solde', 'intitule' => 'Solde Sinistre', 'description' => "Montant des indemnisations qui reste à payer pour solder entièrement les dossiers sinistres.", 'is_percentage' => false],
            ['code' => 'taux_sinistralite', 'intitule' => 'Taux de Sinistralité', 'description' => "Rapport sinistres/primes (S/P). Évalue la qualité technique d'un risque ou d'un portefeuille.", 'is_percentage' => true],
            ['code' => 'taux_de_commission', 'intitule' => 'Taux de Commission', 'description' => "Rapport entre la commission nette et la prime nette. Mesure la rentabilité brute d'une affaire.", 'is_percentage' => true],
            ['code' => 'taux_de_retrocommission_effectif', 'intitule' => 'Taux Rétro. Effectif', 'description' => "Rapport entre la rétrocommission et l'assiette partageable. Mesure le coût réel du partenariat.", 'is_percentage' => true],
            ['code' => 'taux_de_paiement_prime', 'intitule' => 'Taux Paiement Prime', 'description' => "Pourcentage de la prime totale qui a été effectivement payé par le client. Indicateur de recouvrement.", 'is_percentage' => true],
            ['code' => 'taux_de_paiement_commission', 'intitule' => 'Taux Encaissement Comm.', 'description' => "Pourcentage de la commission totale qui a été effectivement encaissée par le courtier.", 'is_percentage' => true],
            ['code' => 'taux_de_paiement_retro_commission', 'intitule' => 'Taux Paiement Rétro.', 'description' => "Pourcentage de la rétrocommission due qui a été effectivement payée aux partenaires.", 'is_percentage' => true],
            ['code' => 'taux_de_paiement_taxe_courtier', 'intitule' => 'Taux Paiement Taxe Courtier', 'description' => "Pourcentage de la taxe courtier due qui a été effectivement payée à l'autorité fiscale.", 'is_percentage' => true],
            ['code' => 'taux_de_paiement_taxe_assureur', 'intitule' => 'Taux Paiement Taxe Assureur', 'description' => "Pourcentage de la taxe assureur due qui a été effectivement payée.", 'is_percentage' => true],
            ['code' => 'taux_de_paiement_sinistre', 'intitule' => 'Taux Paiement Sinistre', 'description' => "Pourcentage de l'indemnisation totale payable qui a déjà été versée aux sinistrés.", 'is_percentage' => true],
        ];

        $canvas = [];
        foreach ($indicators as $indicator) {
            $isPercentage = $indicator['is_percentage'];
            $camelCaseCode = str_replace('_', '', ucwords($indicator['code'], '_'));
            $canvas[] = [
                "code" => $indicator['code'],
                "intitule" => $indicator['intitule'],
                "type" => "Calcul",
                "format" => "Nombre",
                "unite" => $isPercentage ? "%" : $this->serviceMonnaies->getCodeMonnaieAffichage(),
                "fonction" => $entityName . '_get' . $camelCaseCode,
                "description" => $indicator['description']
            ];
        }
        return $canvas;
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
                    "liste" => array_merge([
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
                            "intitule" => "Délai de déclaration",
                            "type" => "Texte",
                            "format" => "Texte",
                            "description" => "⏱️ Mesure la réactivité de l'assuré à déclarer son sinistre (entre la date de survenance et la date de notification)."
                        ],
                        [
                            "code" => "franchise",
                            "intitule" => "Franchise appliquée",
                            "type" => "Nombre",
                            "unite" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                            "format" => "Nombre",
                            "description" => "Montant de la franchise qui a été appliquée conformément aux termes de la police."
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
                            "type" => "Texte",
                            "format" => "Texte",
                            "description" => "📊 Pourcentage des pièces requises qui ont été effectivement fournies pour ce dossier."
                        ],
                        [
                            "code" => "dureeReglement",
                            "intitule" => "Vitesse de règlement",
                            "type" => "Calcul", // On utilise ce type pour déclencher la logique dans le contrôleur
                            "unite" => "",
                            "format" => "Texte",
                            "fonction" => "getNotificationSinistreDureeReglement",
                            "description" => "⏱️ Durée totale en jours entre la notification du sinistre et le dernier paiement de règlement." // MODIFICATION: Ajout
                        ],
                        [
                            "code" => "dateDernierReglement",
                            "intitule" => "Dernier règlement",
                            "type" => "Calcul", // On utilise ce type pour déclencher la logique dans le contrôleur
                            "unite" => "",
                            "format" => "Date",
                            "fonction" => "getNotificationSinistreDateDernierReglement",
                            "description" => "⏱️ Date à laquelle le tout dernier paiement a été effectué pour ce sinistre." // MODIFICATION: Ajout
                        ],
                        [
                            "code" => "ageDossier",
                            "intitule" => "Âge du Dossier",
                            "type" => "Texte",
                            "format" => "Texte",
                            "description" => "⏳ Indique depuis combien de temps le dossier est ouvert. Crucial pour prioriser les cas anciens."
                        ],
                        [
                            "code" => "tauxIndemnisation",
                            "intitule" => "Taux d'indemnisation",
                            "type" => "Nombre",
                            "unite" => "%",
                            "format" => "Nombre",
                            "description" => "Rapport entre le montant total des offres et l'évaluation du dommage. Mesure la couverture réelle proposée."
                        ],
                        [
                            "code" => "nombreOffres",
                            "intitule" => "Nombre d'offres",
                            "type" => "Entier",
                            "format" => "Nombre",
                            "description" => "Nombre total d'offres d'indemnisation. Un nombre élevé peut indiquer un dossier complexe."
                        ],
                        [
                            "code" => "nombrePaiements",
                            "intitule" => "Nombre de paiements",
                            "type" => "Entier",
                            "format" => "Nombre",
                            "description" => "Nombre total de versements effectués pour ce sinistre."
                        ],
                        [
                            "code" => "montantMoyenParPaiement",
                            "intitule" => "Paiement moyen",
                            "type" => "Nombre",
                            "unite" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                            "format" => "Nombre",
                            "description" => "Montant moyen versé par paiement. Utile pour analyser les flux de trésorerie."
                        ],
                        [
                            "code" => "delaiTraitementInitial",
                            "intitule" => "Délai de traitement initial",
                            "type" => "Texte",
                            "format" => "Texte",
                            "description" => "Temps écoulé entre la création du dossier et sa notification formelle. Mesure l'efficacité administrative."
                        ],
                        [
                            "code" => "ratioPaiementsEvaluation",
                            "intitule" => "Ratio Paiements / Évaluation",
                            "type" => "Nombre",
                            "unite" => "%",
                            "format" => "Nombre",
                            "description" => "Progression du règlement effectif par rapport à l'estimation du dommage."
                        ],
                    ], $this->getGlobalIndicatorsCanvas("NotificationSinistre"))
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
                    "liste" => array_merge(
                        // 1. Attributs directs de l'entité
                        ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                        ["code" => "nom", "intitule" => "Description", "type" => "Texte"],
                        ["code" => "beneficiaire", "intitule" => "Bénéficiaire", "type" => "Texte"],
                        ["code" => "montantPayable", "intitule" => "Montant Payable", "type" => "Nombre", "unite" => $this->serviceMonnaies->getCodeMonnaieAffichage()],
                        ["code" => "franchiseAppliquee", "intitule" => "Franchise", "type" => "Nombre", "unite" => $this->serviceMonnaies->getCodeMonnaieAffichage()],
                        ["code" => "notificationSinistre", "intitule" => "Notification Sinistre", "type" => "Relation", "targetEntity" => NotificationSinistre::class, "displayField" => "referenceSinistre"],
                        ["code" => "paiements", "intitule" => "Paiements", "type" => "Collection", "targetEntity" => Paiement::class, "displayField" => "reference"],
                        ["code" => "documents", "intitule" => "Documents", "type" => "Collection", "targetEntity" => Document::class, "displayField" => "nom"],
                        ["code" => "taches", "intitule" => "Tâches", "type" => "Collection", "targetEntity" => Tache::class, "displayField" => "description"],
                        // 2. Indicateurs spécifiques à l'offre
                        $this->calculationProvider->getSpecificIndicatorsCanvas(OffreIndemnisationSinistre::class),
                        // 3. Indicateurs globaux partagés
                        $this->getGlobalIndicatorsCanvas("OffreIndemnisationSinistre")
                    )
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
                    "liste" => array_merge([
                        ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                        ["code" => "description", "intitule" => "Description", "type" => "Texte"],
                        ["code" => "fourniPar", "intitule" => "Fourni par", "type" => "Texte"],
                        ["code" => "receivedAt", "intitule" => "Date de réception", "type" => "Date"],
                        ["code" => "notificationSinistre", "intitule" => "Notification Sinistre", "type" => "Relation", "targetEntity" => NotificationSinistre::class, "displayField" => "referenceSinistre"],
                        ["code" => "documents", "intitule" => "Documents", "type" => "Collection", "targetEntity" => Document::class, "displayField" => "nom"],
                    ], $this->getGlobalIndicatorsCanvas("PieceSinistre"))
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
                    "liste" => array_merge([
                        ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                        ["code" => "referencePolice", "intitule" => "Réf. Police", "type" => "Texte"],
                        ["code" => "numero", "intitule" => "Numéro", "type" => "Texte"],
                        ["code" => "description", "intitule" => "Description", "type" => "Texte"],
                        ["code" => "startingAt", "intitule" => "Date d'effet", "type" => "Date"],
                        ["code" => "endingAt", "intitule" => "Date d'échéance", "type" => "Date"],
                        ["code" => "cotation", "intitule" => "Cotation", "type" => "Relation", "targetEntity" => Cotation::class, "displayField" => "nom"],
                        ["code" => "documents", "intitule" => "Documents", "type" => "Collection", "targetEntity" => Document::class, "displayField" => "nom"],
                    ], $this->getGlobalIndicatorsCanvas("Avenant"))
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
                    "liste" => array_merge([
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
                    ], $this->getGlobalIndicatorsCanvas("Assureur"))
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
                    "liste" => array_merge([
                        ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                        ["code" => "nom", "intitule" => "Nom", "type" => "Texte"],
                        ["code" => "code", "intitule" => "Code ISO", "type" => "Texte"],
                        ["code" => "symbole", "intitule" => "Symbole", "type" => "Texte"],
                    ], $this->getGlobalIndicatorsCanvas("Monnaie"))
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
                    "liste" => array_merge([
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
                    ], $this->getGlobalIndicatorsCanvas("Client"))
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
                    "liste" => array_merge([
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
                    ], $this->getGlobalIndicatorsCanvas("ConditionPartage"))
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
                    "liste" => array_merge([
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
                    ], $this->getGlobalIndicatorsCanvas("Contact"))
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
                    "liste" => array_merge([
                        ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                        ["code" => "nom", "intitule" => "Nom", "type" => "Texte"],
                        ["code" => "assureur", "intitule" => "Assureur", "type" => "Relation", "targetEntity" => Assureur::class, "displayField" => "nom"],
                        ["code" => "piste", "intitule" => "Piste", "type" => "Relation", "targetEntity" => Piste::class, "displayField" => "nom"],
                        ["code" => "createdAt", "intitule" => "Créé le", "type" => "Date"],
                        ["code" => "avenants", "intitule" => "Avenants", "type" => "Collection", "targetEntity" => Avenant::class],
                        ["code" => "taches", "intitule" => "Tâches", "type" => "Collection", "targetEntity" => Tache::class],
                        ["code" => "documents", "intitule" => "Documents", "type" => "Collection", "targetEntity" => Document::class],
                    ], $this->getGlobalIndicatorsCanvas("Cotation"))
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
                    "liste" => array_merge([
                        ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                        ["code" => "nom", "intitule" => "Nom du groupe", "type" => "Texte", "col_principale" => true, "textes_secondaires" => [
                            ["attribut_code" => "description"]
                        ]],
                        ["code" => "clients", "intitule" => "Clients", "type" => "Collection", "targetEntity" => Client::class],
                    ], $this->getGlobalIndicatorsCanvas("Groupe"))
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
                    "liste" => array_merge([
                        ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                        ["code" => "nom", "intitule" => "Nom", "type" => "Texte", "col_principale" => true, "textes_secondaires" => [
                            ["attribut_code" => "email"]
                        ]],
                        ["code" => "part", "intitule" => "Part (%)", "type" => "Nombre", "unite" => "%"],
                        ["code" => "documents", "intitule" => "Documents", "type" => "Collection", "targetEntity" => Document::class],
                        ["code" => "clients", "intitule" => "Clients", "type" => "Collection", "targetEntity" => Client::class],
                    ], $this->getGlobalIndicatorsCanvas("Partenaire"))
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
                    "liste" => array_merge([
                        ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                        ["code" => "nomComplet", "intitule" => "Nom complet", "type" => "Texte", "col_principale" => true, "textes_secondaires" => [
                            ["attribut_code" => "code", "attribut_prefixe" => "Code: "]
                        ]],
                        ["code" => "imposable", "intitule" => "Imposable", "type" => "Booleen"],
                        ["code" => "pistes", "intitule" => "Pistes", "type" => "Collection", "targetEntity" => Piste::class],
                        ["code" => "notificationSinistres", "intitule" => "Sinistres", "type" => "Collection", "targetEntity" => NotificationSinistre::class],
                    ], $this->getGlobalIndicatorsCanvas("Risque"))
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
                    "liste" => array_merge([
                        ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                        ["code" => "description", "intitule" => "Description", "type" => "Texte"],
                        ["code" => "createdAt", "intitule" => "Date", "type" => "Date"],
                        ["code" => "documents", "intitule" => "Documents", "type" => "Collection", "targetEntity" => Document::class, "displayField" => "nom"],
                    ], $this->getGlobalIndicatorsCanvas("Feedback"))
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
                    "liste" => array_merge([
                        ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                        ["code" => "nom", "intitule" => "Nom", "type" => "Texte", "col_principale" => true, "textes_secondaires" => [
                            ["attribut_code" => "client"]
                        ]],
                        ["code" => "risque", "intitule" => "Risque", "type" => "Relation", "targetEntity" => Risque::class, "displayField" => "nomComplet"],
                        ["code" => "primePotentielle", "intitule" => "Prime potentielle", "type" => "Nombre", "unite" => "$"],
                        ["code" => "cotations", "intitule" => "Cotations", "type" => "Collection", "targetEntity" => Cotation::class],
                        ["code" => "documents", "intitule" => "Documents", "type" => "Collection", "targetEntity" => Document::class],
                    ], $this->getGlobalIndicatorsCanvas("Piste"))
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
                    "liste" => array_merge([
                        ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                        ["code" => "description", "intitule" => "Description", "type" => "Texte", "col_principale" => true, "textes_secondaires" => [
                            ["attribut_code" => "executor", "attribut_prefixe" => "Pour: "]
                        ]],
                        ["code" => "toBeEndedAt", "intitule" => "Échéance", "type" => "Date"],
                        ["code" => "closed", "intitule" => "Clôturée", "type" => "Booleen"],
                        ["code" => "feedbacks", "intitule" => "Feedbacks", "type" => "Collection", "targetEntity" => Feedback::class],
                        ["code" => "documents", "intitule" => "Documents", "type" => "Collection", "targetEntity" => Document::class],
                    ], $this->getGlobalIndicatorsCanvas("Tache"))
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
                    "liste" => array_merge([
                        ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                        ["code" => "nom", "intitule" => "Nom", "type" => "Texte"],
                        ["code" => "assureur", "intitule" => "Assureur", "type" => "Relation", "targetEntity" => Assureur::class, "displayField" => "nom"],
                        ["code" => "montantTTC", "intitule" => "Montant TTC", "type" => "Nombre", "unite" => $this->serviceMonnaies->getCodeMonnaieAffichage()],
                        ["code" => "receivedAt", "intitule" => "Reçu le", "type" => "Date"],
                        ["code" => "documents", "intitule" => "Documents", "type" => "Collection", "targetEntity" => Document::class, "displayField" => "nom"],
                    ], $this->getGlobalIndicatorsCanvas("Bordereau"))
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
                    "liste" => array_merge([
                        ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                        ["code" => "nom", "intitule" => "Nom", "type" => "Texte"],
                        ["code" => "description", "intitule" => "Description", "type" => "Texte"],
                        ["code" => "fonction", "intitule" => "Fonction", "type" => "Texte"], // Maybe a calculated field to get the text? For now, it's just the int.
                        ["code" => "chargementPourPrimes", "intitule" => "Utilisations (Primes)", "type" => "Collection", "targetEntity" => ChargementPourPrime::class, "displayField" => "nom"],
                        ["code" => "typeRevenus", "intitule" => "Utilisations (Revenus)", "type" => "Collection", "targetEntity" => TypeRevenu::class, "displayField" => "nom"],
                    ], $this->getGlobalIndicatorsCanvas("Chargement"))
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
                    "liste" => array_merge([
                        ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                        ["code" => "nom", "intitule" => "Nom", "type" => "Texte", "col_principale" => true, "textes_secondaires" => [
                            ["attribut_code" => "description"]
                        ]],
                    ], $this->getGlobalIndicatorsCanvas("AutoriteFiscale"))
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
                    "liste" => array_merge([
                        ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                        ["code" => "nom", "intitule" => "Nom", "type" => "Texte"],
                        ["code" => "type", "intitule" => "Type de chargement", "type" => "Relation", "targetEntity" => Chargement::class, "displayField" => "nom"],
                        ["code" => "cotation", "intitule" => "Cotation", "type" => "Relation", "targetEntity" => Cotation::class, "displayField" => "nom"],
                        ["code" => "montantFlatExceptionel", "intitule" => "Montant", "type" => "Nombre", "unite" => $this->serviceMonnaies->getCodeMonnaieAffichage()],
                        ["code" => "createdAt", "intitule" => "Créé le", "type" => "Date"],
                    ], $this->getGlobalIndicatorsCanvas("ChargementPourPrime"))
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
                    "liste" => array_merge([
                        ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                        ["code" => "nom", "intitule" => "Nom", "type" => "Texte"],
                        ["code" => "intitule", "intitule" => "Intitulé", "type" => "Texte"],
                        ["code" => "numero", "intitule" => "Numéro", "type" => "Texte"],
                        ["code" => "banque", "intitule" => "Banque", "type" => "Texte"],
                        ["code" => "codeSwift", "intitule" => "Code Swift", "type" => "Texte"],
                        ["code" => "documents", "intitule" => "Documents", "type" => "Collection", "targetEntity" => Document::class, "displayField" => "nom"],
                        ["code" => "paiements", "intitule" => "Paiements", "type" => "Collection", "targetEntity" => Paiement::class, "displayField" => "reference"],
                    ], $this->getGlobalIndicatorsCanvas("CompteBancaire"))
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
                    "liste" => array_merge([
                        ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                        ["code" => "nom", "intitule" => "Nom", "type" => "Texte", "col_principale" => true, "textes_secondaires" => [
                            ["attribut_code" => "reference"]
                        ]],
                        ["code" => "validated", "intitule" => "Validée", "type" => "Booleen"],
                        ["code" => "createdAt", "intitule" => "Créée le", "type" => "Date"],
                        ["code" => "paiements", "intitule" => "Paiements", "type" => "Collection", "targetEntity" => Paiement::class],
                    ], $this->getGlobalIndicatorsCanvas("Note"))
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
                    "liste" => array_merge([
                        ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                        ["code" => "reference", "intitule" => "Référence", "type" => "Texte", "col_principale" => true, "textes_secondaires" => [
                            ["attribut_code" => "description"]
                        ]],
                        ["code" => "offreIndemnisationSinistre", "intitule" => "Offre", "type" => "Relation", "targetEntity" => OffreIndemnisationSinistre::class, "displayField" => "nom"],
                        ["code" => "montant", "intitule" => "Montant", "type" => "Nombre", "unite" => "$"],
                        ["code" => "paidAt", "intitule" => "Payé le", "type" => "Date"],
                        ["code" => "preuves", "intitule" => "Preuves (Documents)", "type" => "Collection", "targetEntity" => Document::class],
                    ], $this->getGlobalIndicatorsCanvas("Paiement"))
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
                    "liste" => array_merge([
                        ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                        ["code" => "code", "intitule" => "Code", "type" => "Texte", "col_principale" => true, "textes_secondaires" => [
                            ["attribut_code" => "description"]
                        ]],
                        ["code" => "tauxIARD", "intitule" => "Taux IARD", "type" => "Nombre", "unite" => "%"],
                        ["code" => "tauxVIE", "intitule" => "Taux VIE", "type" => "Nombre", "unite" => "%"],
                    ], $this->getGlobalIndicatorsCanvas("Taxe"))
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
                    "liste" => array_merge([
                        ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                        ["code" => "nom", "intitule" => "Nom", "type" => "Texte", "col_principale" => true, "textes_secondaires" => [
                            ["attribut_code" => "cotation", "attribut_prefixe" => "Cotation: "]
                        ]],
                        ["code" => "montantFlat", "intitule" => "Montant", "type" => "Nombre", "unite" => "$"],
                        ["code" => "pourcentage", "intitule" => "Pourcentage", "type" => "Nombre", "unite" => "%"],
                        ["code" => "payableAt", "intitule" => "Payable le", "type" => "Date"],
                    ], $this->getGlobalIndicatorsCanvas("Tranche"))
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
                    "liste" => array_merge([
                        ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                        ["code" => "nom", "intitule" => "Nom", "type" => "Texte", "col_principale" => true],
                        ["code" => "pourcentage", "intitule" => "Pourcentage", "type" => "Nombre", "unite" => "%"],
                        ["code" => "montantflat", "intitule" => "Montant", "type" => "Nombre", "unite" => "$"],
                        ["code" => "shared", "intitule" => "Partagé", "type" => "Booleen"],
                    ], $this->getGlobalIndicatorsCanvas("TypeRevenu"))
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
                    "liste" => array_merge([
                        ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                        ["code" => "nom", "intitule" => "Nom", "type" => "Texte"],
                        ["code" => "description", "intitule" => "Description", "type" => "Texte"],
                        ["code" => "documents", "intitule" => "Documents", "type" => "Collection", "targetEntity" => Document::class, "displayField" => "nom"],
                    ], $this->getGlobalIndicatorsCanvas("Classeur"))
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
                    "liste" => array_merge([
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
                    ], $this->getGlobalIndicatorsCanvas("Document"))
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
                    "liste" => array_merge([
                        ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                        ["code" => "nom", "intitule" => "Nom", "type" => "Texte", "col_principale" => true, "textes_secondaires" => [
                            ["attribut_code" => "licence", "attribut_prefixe" => "Licence: "]
                        ]],
                        ["code" => "createdAt", "intitule" => "Créé le", "type" => "Date"],
                    ], $this->getGlobalIndicatorsCanvas("Entreprise"))
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
                    "liste" => array_merge([
                        ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                        ["code" => "email", "intitule" => "Email", "type" => "Texte", "col_principale" => true],
                        ["code" => "createdAt", "intitule" => "Invité le", "type" => "Date"],
                        ["code" => "isVerified", "intitule" => "Acceptée", "type" => "Booleen"],
                    ], $this->getGlobalIndicatorsCanvas("Invite"))
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
