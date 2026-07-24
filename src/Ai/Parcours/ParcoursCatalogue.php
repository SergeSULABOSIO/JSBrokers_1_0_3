<?php

namespace App\Ai\Parcours;

/**
 * TRAMES MÉTIER des parcours de saisie de Ket : l'ordre dans lequel un courtier
 * construit réellement un objet du métier, et les questions à lui poser à chaque
 * étape.
 *
 * Ce catalogue ne décrit QUE ce qui n'est pas déductible du code : la narration
 * (libellés, ordre, questions en français métier) et les CHAÎNAGES entre entités
 * qui ne sont pas des collections de formulaire (ex. « la piste DE ce client »).
 * Tout le reste — champs obligatoires/facultatifs/auto, collections réellement
 * éditables, droits — est dérivé à l'exécution par ParcoursBuilder à partir des
 * sources de vérité existantes (FormTreeInspector, inventaireChamps, resolver).
 * Une entité sans trame reçoit un parcours GÉNÉRIQUE construit de la même façon.
 *
 * Rattachement d'une étape (`via`) :
 *  - `socle`               : l'entité de tête du plan (une seule par parcours) ;
 *  - `collection:<nom>`    : élément d'une collection éditable de l'étape socle
 *                            (parité formulaire, ex. `chargements` d'une Cotation) ;
 *  - `reference:<champ>`   : opération de tête DISTINCTE qui renvoie à l'étape
 *                            socle par son étiquette (« @socle ») — c'est ce qui
 *                            permet de créer, en UNE validation, des entités
 *                            dépendantes que le formulaire du socle n'expose pas.
 */
final class ParcoursCatalogue
{
    public const ROLE_SOCLE      = 'socle';
    public const ROLE_RECOMMANDE = 'recommandé';
    public const ROLE_OPTIONNEL  = 'optionnel';

    /**
     * @var array<string, array{libelle: string, resume: string, socle: string, etapes: array<int, array>}>
     */
    private const TRAMES = [
        'proposition' => [
            'libelle' => 'Enregistrer une proposition (cotation) complète',
            'resume'  => 'De la proposition chiffrée de l’assureur jusqu’au contrat : la cotation, '
                . 'la ventilation de sa prime, son échéancier, la rémunération du courtier, puis le contrat.',
            'socle'   => 'Cotation',
            'etapes'  => [
                [
                    'cle' => 'cotation', 'libelle' => 'La proposition (cotation)',
                    'entite' => 'Cotation', 'via' => 'socle', 'role' => self::ROLE_SOCLE,
                    'questions' => [
                        'Quel est l’objet de la proposition (son nom) ?',
                        'Quel assureur la propose ?',
                        'Sur quelle piste (opportunité) porte-t-elle ?',
                        'Quelle est la durée de couverture, en mois ?',
                    ],
                ],
                [
                    'cle' => 'composition-prime', 'libelle' => 'La composition de la prime',
                    'entite' => 'ChargementPourPrime', 'via' => 'collection:chargements', 'role' => self::ROLE_RECOMMANDE,
                    'referentiel' => 'Chargement',
                    'questions' => [
                        'Quel est le montant de la prime nette ?',
                        'Y a-t-il des frais accessoires ? Pour quel montant ?',
                        'Quelles taxes s’appliquent (TVA, frais ARCA…) et pour quels montants ?',
                    ],
                    'note' => 'Chaque composante porte « nom », « montantFlatExceptionel » (le montant) et '
                        . '« type » (id d’un Chargement du référentiel ci-dessous). SANS le type, la '
                        . 'commission du courtier ne peut pas se calculer et reste à 0.',
                ],
                [
                    'cle' => 'echeancier', 'libelle' => 'L’échéancier (tranches de paiement)',
                    'entite' => 'Tranche', 'via' => 'collection:tranches', 'role' => self::ROLE_RECOMMANDE,
                    'questions' => [
                        'La prime est-elle payable en une fois ou en plusieurs tranches ?',
                        'Pour chaque tranche : son libellé, sa date d’exigibilité, et son montant '
                            . '(ou son pourcentage de la prime) ?',
                    ],
                ],
                [
                    'cle' => 'revenu-courtier', 'libelle' => 'Le revenu du courtier (commission)',
                    'entite' => 'RevenuPourCourtier', 'via' => 'collection:revenus', 'role' => self::ROLE_OPTIONNEL,
                    'referentiel' => 'TypeRevenu',
                    'questions' => [
                        'La rémunération suit-elle le taux habituel du type de revenu, ou un taux exceptionnel ?',
                        'S’agit-il d’un montant forfaitaire plutôt que d’un taux ?',
                    ],
                    'note' => 'Le type de revenu (« typeRevenu ») est OBLIGATOIRE : c’est lui qui porte le '
                        . 'taux et la base de calcul. À défaut de taux exceptionnel, le taux du type s’applique.',
                ],
                [
                    'cle' => 'contrat', 'libelle' => 'Le contrat (avenant)',
                    'entite' => 'Avenant', 'via' => 'collection:avenants', 'role' => self::ROLE_OPTIONNEL,
                    'questions' => [
                        'La proposition est-elle déjà acceptée (donc un contrat à enregistrer) ?',
                        'Quelle référence de police et quel numéro d’avenant ?',
                        'Quelles dates de début et de fin de couverture ?',
                    ],
                ],
                [
                    'cle' => 'suivi', 'libelle' => 'Les tâches de suivi',
                    'entite' => 'Tache', 'via' => 'collection:taches', 'role' => self::ROLE_OPTIONNEL,
                    'questions' => ['Souhaitez-vous programmer une relance ou une tâche de suivi ?'],
                ],
            ],
        ],

        'client' => [
            'libelle' => 'Enregistrer un nouveau client et son opportunité',
            'resume'  => 'Le client, ses interlocuteurs, puis — si l’affaire est déjà identifiée — '
                . 'l’opportunité commerciale (piste) qui lui est rattachée.',
            'socle'   => 'Client',
            'etapes'  => [
                [
                    'cle' => 'client', 'libelle' => 'Le client',
                    'entite' => 'Client', 'via' => 'socle', 'role' => self::ROLE_SOCLE,
                    'questions' => [
                        'Quel est le nom du client ?',
                        'Est-il exonéré de taxes ?',
                        'Ses coordonnées (e-mail, téléphone, adresse) ?',
                    ],
                ],
                [
                    'cle' => 'contacts', 'libelle' => 'Les interlocuteurs',
                    'entite' => 'Contact', 'via' => 'collection:contacts', 'role' => self::ROLE_OPTIONNEL,
                    'questions' => ['Qui sont vos interlocuteurs chez ce client (nom, fonction, coordonnées) ?'],
                ],
                [
                    'cle' => 'opportunite', 'libelle' => 'L’opportunité (piste)',
                    'entite' => 'Piste', 'via' => 'reference:client', 'role' => self::ROLE_OPTIONNEL,
                    'questions' => [
                        'Une affaire est-elle déjà identifiée pour ce client ?',
                        'Sur quel risque (branche d’assurance) porte-t-elle ?',
                        'Quelle prime potentielle estimez-vous ?',
                        'Des partenaires interviennent-ils sur cette affaire ?',
                    ],
                ],
            ],
        ],

        'contrat' => [
            'libelle' => 'Enregistrer un contrat (avenant) et son suivi',
            'resume'  => 'Le contrat concrétisé : ses données contractuelles, ses pièces, ses tâches de suivi.',
            'socle'   => 'Avenant',
            'etapes'  => [
                [
                    'cle' => 'avenant', 'libelle' => 'Le contrat (avenant)',
                    'entite' => 'Avenant', 'via' => 'socle', 'role' => self::ROLE_SOCLE,
                    'questions' => [
                        'De quelle cotation le contrat découle-t-il ?',
                        'Quelle référence de police et quel numéro d’avenant ?',
                        'Quelles dates de début et de fin de couverture ?',
                    ],
                ],
                [
                    'cle' => 'pieces', 'libelle' => 'Les pièces du contrat',
                    'entite' => 'Document', 'via' => 'collection:documents', 'role' => self::ROLE_OPTIONNEL,
                    'questions' => ['Des pièces (police, conditions particulières) sont-elles à référencer ?'],
                ],
            ],
        ],

        'sinistre' => [
            'libelle' => 'Déclarer un sinistre et instruire le dossier',
            'resume'  => 'La déclaration du sinistre, les pièces du dossier, le règlement proposé '
                . 'et les tâches d’instruction.',
            'socle'   => 'NotificationSinistre',
            'etapes'  => [
                [
                    'cle' => 'declaration', 'libelle' => 'La déclaration de sinistre',
                    'entite' => 'NotificationSinistre', 'via' => 'socle', 'role' => self::ROLE_SOCLE,
                    'questions' => [
                        'Qui est l’assuré concerné et quel est l’assureur ?',
                        'Quelle référence de police et quelle référence de sinistre ?',
                        'Quand le sinistre est-il survenu, et quand a-t-il été notifié ?',
                        'Où, et quels sont les faits et les dommages ?',
                    ],
                ],
                [
                    'cle' => 'pieces-sinistre', 'libelle' => 'Les pièces du dossier',
                    'entite' => 'PieceSinistre', 'via' => 'collection:pieces', 'role' => self::ROLE_OPTIONNEL,
                    'questions' => ['Quelles pièces justificatives sont déjà réunies ?'],
                ],
                [
                    'cle' => 'reglement', 'libelle' => 'L’offre d’indemnisation',
                    'entite' => 'OffreIndemnisationSinistre', 'via' => 'collection:offreIndemnisationSinistres',
                    'role' => self::ROLE_OPTIONNEL,
                    'questions' => ['Une offre de règlement a-t-elle été formulée ? Pour quel montant ?'],
                ],
                [
                    'cle' => 'instruction', 'libelle' => 'Les tâches d’instruction',
                    'entite' => 'Tache', 'via' => 'collection:taches', 'role' => self::ROLE_OPTIONNEL,
                    'questions' => ['Des relances ou des tâches d’instruction sont-elles à programmer ?'],
                ],
            ],
        ],
    ];

    /** Entité de tête => slug de la trame (résolution d'un parcours par l'entité visée). */
    private const PAR_ENTITE = [
        'Cotation'             => 'proposition',
        'Client'               => 'client',
        'Avenant'              => 'contrat',
        'NotificationSinistre' => 'sinistre',
    ];

    /** @return string[] slugs des parcours rédigés */
    public static function slugs(): array
    {
        return array_keys(self::TRAMES);
    }

    /** @return array<string, string> slug => libellé (catalogue du prompt/outil) */
    public static function catalogue(): array
    {
        return array_map(static fn (array $t) => $t['libelle'], self::TRAMES);
    }

    /**
     * Trame d'un parcours désigné par son slug OU par son entité de tête
     * (« Cotation » => trame « proposition »). null si aucune trame rédigée.
     *
     * @return array{libelle: string, resume: string, socle: string, etapes: array<int, array>}|null
     */
    public static function trame(string $sujet): ?array
    {
        if (isset(self::TRAMES[$sujet])) {
            return self::TRAMES[$sujet];
        }
        $slug = self::PAR_ENTITE[$sujet] ?? null;

        return $slug !== null ? self::TRAMES[$slug] : null;
    }
}
