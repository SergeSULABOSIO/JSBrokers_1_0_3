<?php

namespace App\Token;

use App\Entity\AssistantMessage;
use App\Entity\Avenant;
use App\Entity\ChargeCourtier;
use App\Entity\Cotation;
use App\Entity\DemandeConge;
use App\Entity\DepenseCourtier;
use App\Entity\Entreprise;
use App\Entity\Feedback;
use App\Entity\Fournisseur;
use App\Entity\Piste;
use App\Entity\Portefeuille;
use App\Entity\Tache;

/**
 * @file Référence du modèle de facturation à base de TOKENS de JS Brokers.
 * @description Source de vérité unique pour le métrage et la tarification :
 *  - poids des entités en écriture (entrée) et en lecture (sortie) ;
 *  - allocation gratuite renouvelable ;
 *  - paquets prépayés cumulables ;
 *  - taux de conversion token → USD (informatif).
 *
 * Classe dédiée et volontairement minimale (même esprit que App\Legal\Cgu) :
 * on n'alourdit pas la god-class App\Constantes\Constante.
 */
final class TokenPricing
{
    /**
     * Poids en tokens d'une entité lors d'une ÉCRITURE (création ou édition).
     * Toute entité non listée ici vaut DEFAULT_WRITE_WEIGHT.
     */
    public const WRITE_WEIGHTS = [
        Entreprise::class      => 200,
        Avenant::class         => 100,
        Cotation::class        => 50,
        Piste::class           => 20,
        Tache::class           => 10,
        Feedback::class        => 8,
        // Portefeuille client (regroupement de clients par gestionnaire de compte) :
        // entrée explicite pour figurer dans le plan tarifaire éditable, au poids standard.
        Portefeuille::class    => 5,
        // Comptabilité du courtier (workspace) : entrées explicites pour figurer
        // dans le plan tarifaire éditable (console), au poids standard.
        ChargeCourtier::class  => 5,
        DepenseCourtier::class => 5,
        Fournisseur::class     => 5,
        // Assistant IA : chaque message envoyé à l'assistant est métré comme une
        // écriture (≈ 2 écritures standard — le traitement IA coûte plus qu'un
        // simple enregistrement). Paramétrable en console comme les autres poids.
        AssistantMessage::class => 10,
        // Demande de congé : la rubrique se facture À L'ACTE, sans abonnement ni
        // montant par agent — un cabinet qui ne pose pas de congés ne paie rien pour
        // elle. Le poids est celui d'une proposition, l'acte administratif de
        // référence du groupe. Paramétrable en console comme les autres.
        //
        // Le métrage lui-même est GÉNÉRIQUE : rien à écrire de plus, la demande est
        // débitée par le même point de passage que toutes les autres écritures
        // (WorkspaceMutationService::commitWrite). Les lignes dérivées d'une décision
        // — mouvement de compteur, historique — restent au poids standard : elles ne
        // sont pas un acte de l'utilisateur, mais la conséquence du sien.
        DemandeConge::class     => 50,
    ];

    /** Poids par défaut en écriture pour toute entité non explicitement listée. */
    public const DEFAULT_WRITE_WEIGHT = 5;

    /**
     * Assistant IA : coût d'un objet attaché au contexte d'une conversation,
     * exprimé en RATIO du poids d'un message (décision produit : 80 %). Facturé
     * une seule fois, à l'attache ; suit dynamiquement le poids message
     * paramétré en console.
     */
    public const CONTEXTE_IA_RATIO = 0.8;

    /**
     * Assistant IA : coût d'un FICHIER attaché au contexte d'une conversation,
     * exprimé en RATIO du poids d'un message (décision produit : 100 % — un
     * fichier extrait et injecté pèse davantage qu'un simple objet). Facturé une
     * seule fois, à l'attache ; suit le poids message paramétré en console.
     */
    public const FICHIER_IA_RATIO = 1.0;

    /**
     * Assistant IA — DOCUMENT téléchargeable produit par Ket (Word, Excel, PDF,
     * Markdown, texte, HTML). Coût FIXE d'une production, indépendant de la
     * longueur : il couvre l'assemblage, la mise en page, le rendu et le stockage.
     *
     * Contrairement aux poids d'écriture, ce barème n'est pas indexé par entité :
     * un document n'est pas un enregistrement, c'est un livrable qui sort du
     * logiciel. Il a donc ses propres paramètres, éditables en console.
     */
    public const DOCUMENT_BASE = 60;

    /** Part variable : coût d'une page facturée, en tokens. */
    public const DOCUMENT_PAR_PAGE = 30;

    /**
     * Définition de la PAGE facturée. Mesurée en CARACTÈRES (`mb_strlen`, jamais
     * `strlen`) : un texte accentué — donc tout document français — coûterait le
     * double si on comptait des octets. Arrondi supérieur, minimum une page.
     */
    public const DOCUMENT_CARACTERES_PAR_PAGE = 2500;

    /**
     * Multiplicateur par format de sortie : un rendu bureautique paginé coûte plus
     * qu'un fichier texte. Clé = extension servie.
     */
    public const DOCUMENT_FORMATS = [
        'txt'  => 1.0,
        'md'   => 1.0,
        'html' => 1.2,
        'xlsx' => 1.4,
        'docx' => 1.5,
        'pdf'  => 1.8,
    ];

    /** Format retenu quand l'utilisateur n'en précise aucun (décision produit). */
    public const DOCUMENT_FORMAT_DEFAUT = 'docx';

    /**
     * Repli NEUTRE pour un format absent de la carte. Ne peut normalement pas
     * survenir (la route et l'enum contraignent déjà les formats servis) ; protège
     * le cas où un agent retire un format de la console alors qu'un plan l'attend.
     */
    public const DOCUMENT_MULTIPLICATEUR_DEFAUT = 1.0;

    /** Poids en tokens d'une entité envoyée vers le frontend (LECTURE / sortie). */
    public const READ_WEIGHT = 2;

    /**
     * ÉCHANGE DE DONNÉES (rubrique « Importation / Exportation »).
     *
     * Occurrences offertes à VIE et par cabinet — pas par fenêtre : ce quota n'est pas
     * une allocation qui se renouvelle, c'est une mise en bouche. Passé ce seuil, chaque
     * EXPORT coûte ECHANGE_COUT_OCCURRENCE.
     *
     * ⚠ L'IMPORT N'EST PAS FACTURÉ ICI, et ce n'est pas un oubli. Il écrit des
     * enregistrements, et chacun est déjà métré à son poids ordinaire par
     * WorkspaceMutationService — exactement comme s'il avait été saisi à l'écran. Lui
     * ajouter un forfait le ferait payer deux fois pour un seul geste.
     */
    public const ECHANGE_QUOTA_GRATUIT = 3;

    /** Coût en tokens d'une exportation au-delà du quota gratuit. */
    public const ECHANGE_COUT_OCCURRENCE = 600;

    /**
     * Plafond de lignes d'un fichier d'échange, toutes feuilles confondues. L'écriture
     * passe par le formulaire de chaque entité, ce qui est lent mais garantit qu'un
     * import respecte exactement les mêmes règles qu'une saisie. Le plafond est ce qui
     * tient cette promesse dans une requête synchrone, plutôt que d'introduire une
     * infrastructure asynchrone pour cette seule rubrique.
     */
    public const ECHANGE_PLAFOND_LIGNES = 2000;

    /** Allocation gratuite offerte à chaque utilisateur, par fenêtre. */
    public const FREE_ALLOWANCE = 1000;

    /** Durée de validité (en heures) de l'allocation gratuite avant renouvellement. */
    public const FREE_WINDOW_HOURS = 8;

    /**
     * Paquets prépayés cumulables. Clé = identifiant technique (stable) du paquet.
     *  - label  : nom d'affichage du paquet (repli ucfirst(clé) si absent) ;
     *  - tokens : nombre de tokens crédités ;
     *  - price  : prix de vente TTC en USD.
     */
    public const PACKS = [
        'intermediaire' => ['label' => 'Intermédiaire', 'tokens' => 10000, 'price' => 10],
        'professionnel' => ['label' => 'Professionnel', 'tokens' => 50000, 'price' => 40],
    ];

    /**
     * Taux de référence (configurable) pour estimer le coût en USD d'une
     * consommation de tokens. Défaut = taux du paquet Intermédiaire
     * (10 $ / 10 000 tokens = 0,001 $/token). Modifier ici suffit : le coût
     * n'est jamais stocké, il est toujours recalculé à l'affichage.
     */
    public const USD_PER_TOKEN = 0.001;

    /**
     * Retourne le poids en écriture d'une entité (par son FQCN), avec repli
     * sur le poids par défaut.
     */
    public static function weightFor(string $fqcn): int
    {
        return self::WRITE_WEIGHTS[$fqcn] ?? self::DEFAULT_WRITE_WEIGHT;
    }

    /** Convertit un nombre de tokens consommés en coût USD selon le taux de référence. */
    public static function costUsd(int $tokens): float
    {
        return $tokens * self::USD_PER_TOKEN;
    }

    /** Retourne la définition d'un paquet ou null s'il n'existe pas. */
    public static function pack(string $key): ?array
    {
        return self::PACKS[$key] ?? null;
    }
}
