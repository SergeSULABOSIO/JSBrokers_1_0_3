<?php

namespace App\Service\Onboarding;

use App\Entity\Assureur;
use App\Entity\ChargeCourtier;
use App\Entity\Classeur;
use App\Entity\CompteBancaire;
use App\Entity\ConditionPartage;
use App\Entity\Fournisseur;
use App\Entity\Invite;
use App\Entity\JourFerie;
use App\Entity\ModelePieceSinistre;
use App\Entity\Monnaie;
use App\Entity\ParametresConge;
use App\Entity\Partenaire;
use App\Entity\Portefeuille;
use App\Entity\RegimeTravail;

/**
 * LES ÉTAPES DU DÉMARRAGE D'UN CABINET — source unique.
 *
 * ── CE QUE CE CATALOGUE N'EST PAS ───────────────────────────────────────────────────
 * Il ne liste PAS tout ce qu'un cabinet possède. `ServiceInitialisationEntreprise` sème
 * déjà sept catalogues à la création (monnaies, taxes et autorités fiscales, chargements,
 * types de revenu, 43 risques, 10 groupes, 5 types d'absence) : les redemander à
 * l'utilisateur serait lui faire refaire un travail déjà fait, et le score n'avancerait
 * jamais pour une bonne raison. Ces entités sont donc ABSENTES d'ici, à une exception
 * près, expliquée sur l'étape `taux_change`.
 *
 * Il ne liste pas non plus les données MÉTIER — pistes, clients, cotations, tranches,
 * avenants. Celles-là naissent d'une affaire gagnée, pas d'un paramétrage ; elles n'ont
 * ni fin ni seuil, et n'ont donc rien à faire dans une jauge de complétude.
 *
 * ── AUCUNE PROSE ICI ────────────────────────────────────────────────────────────────
 * Le « pourquoi » affiché sur une carte n'est pas écrit dans ce fichier : c'est le premier
 * paragraphe de la `description_creation` du FormCanvasProvider de l'entité, celle-là même
 * qu'affiche la colonne gauche du dialogue de création. Un seul texte, deux surfaces —
 * deux textes auraient fini par se contredire.
 *
 * ── LES TROIS POIDS ─────────────────────────────────────────────────────────────────
 * Ils ne mesurent pas l'effort mais le BLOCAGE. Poids 3 : sans lui, la chaîne s'arrête
 * (on ne peut pas coter sans assureur, ni encaisser sans compte). Poids 2 : le cabinet
 * tourne, mais mal organisé. Poids 1 : du confort. C'est ce barème que la boussole de
 * l'assistant relit pour décider si la dette de configuration passe devant le devoir
 * fiscal ou non.
 */
final class OnboardingCatalogue
{
    /** Sans lui, la chaîne de production s'arrête. */
    public const POIDS_BLOQUANT = 3;
    /** Le cabinet tourne, mais mal organisé. */
    public const POIDS_STRUCTURANT = 2;
    /** Du confort, à faire quand il y aura le temps. */
    public const POIDS_CONFORT = 1;

    /**
     * Les étapes, dans l'ordre où elles se présentent au courtier.
     *
     * - `entite`    : la classe comptée, et celle dont on ouvre le dialogue.
     * - `seuil`     : le nombre d'objets à partir duquel l'étape est faite. Les étapes à
     *                 `seuil` null ont une règle propre, écrite dans OnboardingCompletude.
     * - `singleton` : un réglage unique et non une accumulation — pas de bouton « Ajouter »,
     *                 seulement sa ligne à ouvrir.
     * - `bloc`      : le groupe du menu où la rubrique se trouve. Il range les cartes, et
     *                 dit au courtier où retrouver plus tard ce qu'il vient de créer.
     *
     * @var array<int, array{cle: string, libelle: string, icone: string, entite: class-string, seuil: int|null, singleton: bool, bloc: string, poids: int}>
     */
    private const ETAPES = [
        // ── POIDS 3 : sans eux, on ne produit pas ───────────────────────────────────
        [
            'cle' => 'assureurs', 'libelle' => 'Assureurs', 'icone' => 'assureur',
            'entite' => Assureur::class, 'seuil' => 1, 'singleton' => false,
            'bloc' => 'Production', 'poids' => self::POIDS_BLOQUANT,
        ],
        [
            'cle' => 'portefeuilles', 'libelle' => 'Portefeuilles', 'icone' => 'portefeuille',
            'entite' => Portefeuille::class, 'seuil' => 1, 'singleton' => false,
            'bloc' => 'Production', 'poids' => self::POIDS_BLOQUANT,
        ],
        [
            'cle' => 'comptes_bancaires', 'libelle' => 'Comptes bancaires', 'icone' => 'compte-bancaire',
            'entite' => CompteBancaire::class, 'seuil' => 1, 'singleton' => false,
            'bloc' => 'Finances', 'poids' => self::POIDS_BLOQUANT,
        ],
        [
            // LA SEULE ENTITÉ SEMÉE QUI FIGURE ICI. Le semis pose la monnaie du pays avec
            // un taux de 1.00 — un placeholder, faux dès que le cabinet ne travaille pas
            // en dollars. Rien ne le signale, et tous les montants convertis sont faux en
            // silence. C'est le contraire d'un paramètre « déjà fait ».
            //
            // CE N'EST PAS UN RÉGLAGE UNIQUE POUR AUTANT : un cabinet encaisse souvent
            // dans plusieurs devises, et la carte doit donc laisser en ajouter, comme
            // celle des conditions de partage. Ce qui est unique, c'est le CRITÈRE
            // d'achèvement — le taux de la monnaie locale —, pas le nombre de lignes.
            'cle' => 'taux_change', 'libelle' => 'Monnaies et taux de change', 'icone' => 'monnaie',
            'entite' => Monnaie::class, 'seuil' => null, 'singleton' => false,
            'bloc' => 'Finances', 'poids' => self::POIDS_BLOQUANT,
        ],
        [
            'cle' => 'conditions_partage', 'libelle' => 'Conditions de partage', 'icone' => 'condition',
            'entite' => ConditionPartage::class, 'seuil' => 1, 'singleton' => false,
            'bloc' => 'Production', 'poids' => self::POIDS_BLOQUANT,
        ],

        // ── POIDS 2 : structurant ───────────────────────────────────────────────────
        [
            'cle' => 'intermediaires', 'libelle' => 'Intermédiaires', 'icone' => 'partenaire',
            'entite' => Partenaire::class, 'seuil' => 1, 'singleton' => false,
            'bloc' => 'Production', 'poids' => self::POIDS_STRUCTURANT,
        ],
        [
            // UNE SEULE ÉTAPE POUR LES INVITÉS ET LEURS DROITS. Attribuer un droit n'est
            // pas une création mais l'édition d'un invité précis — et les rôles sont des
            // collections du dialogue d'invitation lui-même. Inviter quelqu'un et lui
            // donner son périmètre est le même geste ; en faire deux étapes aurait forcé
            // un détour par la rubrique.
            'cle' => 'collaborateurs', 'libelle' => 'Collaborateurs et droits', 'icone' => 'invite',
            'entite' => Invite::class, 'seuil' => null, 'singleton' => false,
            'bloc' => 'Administration', 'poids' => self::POIDS_STRUCTURANT,
        ],
        [
            'cle' => 'classeurs', 'libelle' => 'Classeurs', 'icone' => 'classeur',
            'entite' => Classeur::class, 'seuil' => 1, 'singleton' => false,
            'bloc' => 'Administration', 'poids' => self::POIDS_STRUCTURANT,
        ],
        [
            // ChargeCourtier, et surtout PAS Charge : cette dernière est l'axe analytique
            // de la console Joseara (l'éditeur), sans scoping d'entreprise.
            'cle' => 'types_charges', 'libelle' => 'Types de charges', 'icone' => 'charge',
            'entite' => ChargeCourtier::class, 'seuil' => 1, 'singleton' => false,
            'bloc' => 'Finances', 'poids' => self::POIDS_STRUCTURANT,
        ],

        // ── POIDS 1 : confort ───────────────────────────────────────────────────────
        [
            'cle' => 'fournisseurs', 'libelle' => 'Fournisseurs', 'icone' => 'fournisseur',
            'entite' => Fournisseur::class, 'seuil' => 1, 'singleton' => false,
            'bloc' => 'Finances', 'poids' => self::POIDS_CONFORT,
        ],
        [
            'cle' => 'types_pieces', 'libelle' => 'Types de pièces sinistre', 'icone' => 'modele-piece',
            'entite' => ModelePieceSinistre::class, 'seuil' => 1, 'singleton' => false,
            'bloc' => 'Sinistre', 'poids' => self::POIDS_CONFORT,
        ],
        [
            'cle' => 'jours_feries', 'libelle' => 'Jours fériés', 'icone' => 'jour-ferie',
            'entite' => JourFerie::class, 'seuil' => 1, 'singleton' => false,
            'bloc' => 'Administration', 'poids' => self::POIDS_CONFORT,
        ],
        [
            'cle' => 'parametres_conges', 'libelle' => 'Paramètres congés', 'icone' => 'action:settings',
            'entite' => ParametresConge::class, 'seuil' => null, 'singleton' => true,
            'bloc' => 'Administration', 'poids' => self::POIDS_CONFORT,
        ],
        [
            'cle' => 'regimes_travail', 'libelle' => 'Régimes de travail', 'icone' => 'regime-travail',
            'entite' => RegimeTravail::class, 'seuil' => 1, 'singleton' => false,
            'bloc' => 'Administration', 'poids' => self::POIDS_CONFORT,
        ],
    ];

    /**
     * LES TROIS SECTIONS DU GUIDE, dans l'ordre où on les présente.
     *
     * Elles vivent ici et non dans le gabarit : le poids qui les définit est déclaré
     * juste au-dessus, et deux endroits qui décident du même découpage finissent
     * toujours par ne plus dire la même chose.
     *
     * @return array<int, array{poids: int, titre: string, intro: string}>
     */
    public function sections(): array
    {
        return [
            [
                'poids' => self::POIDS_BLOQUANT,
                'titre' => 'Indispensable pour produire',
                'intro' => "Sans ces éléments, la chaîne s'arrête : on ne peut ni coter, ni encaisser, ni rétrocéder.",
            ],
            [
                'poids' => self::POIDS_STRUCTURANT,
                'titre' => 'Pour vous organiser',
                'intro' => "Le cabinet tourne sans eux, mais vous les regretterez vite.",
            ],
            [
                'poids' => self::POIDS_CONFORT,
                'titre' => 'Quand vous aurez le temps',
                'intro' => "Du confort. Rien ici ne vous bloquera.",
            ],
        ];
    }

    /**
     * @return array<int, array{cle: string, libelle: string, icone: string, entite: class-string, seuil: int|null, singleton: bool, bloc: string, poids: int}>
     */
    public function etapes(): array
    {
        return self::ETAPES;
    }

    /** Le total des poids : le dénominateur du score. */
    public function poidsTotal(): int
    {
        return array_sum(array_column(self::ETAPES, 'poids'));
    }

    /**
     * L'étape portant cette clé, ou null. Sert au panneau, qui reçoit une clé du DOM et
     * ne doit jamais faire confiance à ce qui en vient.
     *
     * @return array<string, mixed>|null
     */
    public function etape(string $cle): ?array
    {
        foreach (self::ETAPES as $etape) {
            if ($etape['cle'] === $cle) {
                return $etape;
            }
        }

        return null;
    }
}
