<?php

namespace App\Ai\Action;

/**
 * SOURCE UNIQUE des actions d'interface que Ket peut demander au navigateur.
 *
 * POURQUOI CE REGISTRE EXISTE. Les types vivaient en chaînes littérales dispersées
 * dans les outils, et le navigateur les interprétait dans un `switch` sans `default`.
 * Deux bouts qui se font des promesses sans contrat : le jour où l'un change, l'autre
 * ne s'en aperçoit pas. Ce n'est pas une crainte théorique — au 2026-08-10, le chat
 * traitait encore « signaler-paiement-prime » alors qu'aucun code serveur ne l'émettait
 * plus, et une action d'un type inconnu était **ignorée en silence** : l'utilisateur ne
 * voyait rien, rien n'était journalisé, et personne n'apprenait que le serveur avait
 * demandé quelque chose qui n'arriverait jamais.
 *
 * C'est la même famille de défaut que le plan fantôme et l'outil fantôme : une promesse
 * faite à l'utilisateur que rien ne garantit. Le remède est le même — dériver du code,
 * et faire échouer un test quand les deux bouts divergent (ContratDesActionsTest).
 *
 * CE QUI N'EST PAS UNIFIÉ, ET POURQUOI. Seuls les TYPES sont déclarés ici, pas la forme
 * des charges utiles. Ouvrir un formulaire, naviguer, télécharger un fichier et valider
 * un plan d'écriture n'ont ni les mêmes données ni les mêmes conséquences : seul le plan
 * porte une cible, un budget, une irréversibilité et un mot de passe. Les forcer dans une
 * structure commune produirait un « payload » fourre-tout que chaque cas devrait de toute
 * façon re-valider.
 *
 * Les champs déclarés dans {@see champsRequis()} sont ceux SANS LESQUELS LE NAVIGATEUR NE
 * FAIT RIEN — relevés dans les handlers eux-mêmes, jamais supposés.
 */
enum TypeAction: string
{
    /** Barre de décision d'un plan d'écriture (« Valider et exécuter » / « Annuler »). */
    case PLAN_A_VALIDER = 'ket-mutation.review';

    /** Avertissement autoritaire : la prose annonce un plan qu'aucun outil n'a préparé. */
    case PLAN_ABSENT = 'ket-mutation.absent';

    /** Ouvre un formulaire de saisie, éventuellement pré-rempli. */
    case OUVRIR_DIALOGUE = 'open-dialog';

    /** Navigation : ouvre une rubrique du workspace. */
    case OUVRIR_RUBRIQUE = 'open-rubrique';

    /** Affiche la fiche d'un enregistrement à l'écran. */
    case VISUALISER_FICHE = 'open-visualization';

    /** Ouvre le sélecteur d'envoi d'un relevé de compte (SOA) à un client. */
    case OUVRIR_ENVOI_SOA = 'open-soa-envoi';

    /** Ouvre une URL (export, téléchargement d'un état). */
    case OUVRIR_URL = 'open-url';

    /** Demande la fermeture de l'espace de travail (confirmation manuelle côté interface). */
    case QUITTER_WORKSPACE = 'close-workspace';

    /** Affiche des boutons de téléchargement sécurisés sous la réponse. */
    case TELECHARGER_FICHIERS = 'files-download';

    /** Expédie par courriel le contenu d'un message du fil. */
    case ENVOYER_MESSAGE_DIRECT = 'assistant:message.envoyer-direct';

    /**
     * Les champs SANS LESQUELS le navigateur ne fait rien — son handler sort par une
     * garde silencieuse. Les émettre incomplets, c'est promettre une action qui
     * n'arrivera pas.
     *
     * `PLAN_A_VALIDER` n'en déclare aucun ici : sa structure est vérifiée bien plus
     * finement par {@see \App\Ai\Mutation\PlanEnAttente::planStockable()}, et le
     * dupliquer créerait deux vérités.
     *
     * @return list<string>
     */
    public function champsRequis(): array
    {
        return match ($this) {
            self::OUVRIR_DIALOGUE, self::OUVRIR_RUBRIQUE => ['entite'],
            self::VISUALISER_FICHE                       => ['entite', 'id'],
            self::OUVRIR_URL                             => ['url'],
            self::OUVRIR_ENVOI_SOA                       => ['clientId'],
            self::TELECHARGER_FICHIERS                   => ['fichiers'],
            self::ENVOYER_MESSAGE_DIRECT                 => ['idMessage', 'destinataires'],
            self::PLAN_A_VALIDER, self::PLAN_ABSENT, self::QUITTER_WORKSPACE => [],
        };
    }

    /** Lecture tolérante : null quand la valeur ne correspond à aucun type déclaré. */
    public static function depuis(mixed $valeur): ?self
    {
        return is_string($valeur) ? self::tryFrom($valeur) : null;
    }

    /** @return list<string> tous les types déclarés, pour le contrat avec le navigateur */
    public static function valeurs(): array
    {
        return array_map(static fn (self $type) => $type->value, self::cases());
    }
}
