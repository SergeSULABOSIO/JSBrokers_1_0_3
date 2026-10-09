<?php

namespace App\Ai;

use App\Ai\Boussole\PlanDuJourService;
use App\Entity\Invite;
use App\Service\Workspace\WorkspaceAccessResolver;

/**
 * L'ACCUEIL D'UNE CONVERSATION VIDE : une salutation, une phrase, trois suggestions.
 *
 * Il remplace le programme du jour, qui s'affichait d'office à chaque nouveau fil :
 * jusqu'à sept tableaux avant même la première question. Le programme reste
 * disponible — c'est la première suggestion —, mais c'est l'utilisateur qui le
 * demande.
 *
 * AUCUNE PHRASE NE CONTREDIT LA SALUTATION. Elles sont toutes valables à toute
 * heure (pas de « belle journée » après « Bonsoir ») et neutres en genre : le nom
 * de l'assistant se configure, son accord grammatical ne se devine pas.
 *
 * UNE SUGGESTION N'EST PROPOSÉE QU'À QUI OBTIENDRAIT UNE RÉPONSE. Mesuré : sans le
 * droit de lecture, « Quelles polices arrivent à échéance ? » vaut un refus et le
 * programme du jour revient vide. Chaque suggestion porte donc les entités dont
 * dépend sa réponse, contrôlées par le même WorkspaceAccessResolver que les outils.
 *
 * Les questions ne contiennent aucun verbe d'action de SelecteurDeTrousse (pas de
 * « renouveler ») : elles doivent rester en trousse LECTURE — SelecteurDeTrousseTest
 * le vérifie sur cette constante même.
 */
final class AccueilDeKet
{
    /** Courtes : un accueil n'est pas un discours (AccueilDeKetTest borne la longueur). */
    public const PHRASES = [
        'Par quel dossier commençons-nous ?',
        'Une police, un client, une prime ? Je vous écoute.',
        'Que puis-je faire pour vous ?',
        'Votre portefeuille est à portée de question.',
        'Une question sur vos affaires ? Demandez-moi.',
        'Dites-moi ce que vous cherchez.',
    ];

    /**
     * `entites` : il suffit d'en lire UNE pour que la suggestion ait une réponse.
     *
     * @var list<array{libelle: string, question: string, icone: string, entites: list<string>}>
     */
    public const SUGGESTIONS = [
        [
            'libelle' => 'Mon programme du jour',
            'question' => 'Quel est mon programme du jour ?',
            'icone' => 'tache',
            'entites' => PlanDuJourService::ENTITES,
        ],
        [
            'libelle' => 'Polices à échéance',
            'question' => 'Quelles polices arrivent à échéance ?',
            'icone' => 'avenant',
            'entites' => ['Avenant'],
        ],
        [
            'libelle' => 'Primes à encaisser',
            'question' => 'Quelles primes restent à encaisser ?',
            'icone' => 'paiement',
            'entites' => ['Tranche'],
        ],
    ];

    /** « Bonsoir » de 18 h à 4 h 59, « Bonjour » de 5 h à 17 h 59. */
    private const HEURE_DU_SOIR = 18;
    private const HEURE_DU_MATIN = 5;

    public function __construct(private readonly WorkspaceAccessResolver $acces)
    {
    }

    /**
     * @return array{titre: string, phrase: string, suggestions: list<array{libelle: string, question: string, icone: string, entites: list<string>}>}
     */
    public function pour(Invite $invite, ?string $nomComplet, \DateTimeImmutable $maintenant): array
    {
        $prenom = self::prenom($nomComplet);
        $salutation = self::salutation($maintenant);

        return [
            'titre' => $prenom === null ? $salutation : $salutation . ', ' . $prenom,
            'phrase' => self::PHRASES[array_rand(self::PHRASES)],
            'suggestions' => array_values(array_filter(
                self::SUGGESTIONS,
                fn (array $suggestion): bool => $this->lisUneDe($invite, $suggestion['entites']),
            )),
        ];
    }

    public static function salutation(\DateTimeImmutable $maintenant): string
    {
        $heure = (int) $maintenant->format('G');

        return $heure >= self::HEURE_DU_SOIR || $heure < self::HEURE_DU_MATIN ? 'Bonsoir' : 'Bonjour';
    }

    /**
     * L'heure de mur dans le fuseau de l'APPLICATION, nommé explicitement : sur un
     * serveur mutualisé, le fuseau courant de PHP peut être UTC, et « Bonsoir »
     * tomberait alors une heure trop tôt ou trop tard. Repli sur le fuseau courant
     * si le paramètre est vide ou invalide — l'ouverture du chat ne doit dépendre
     * de rien.
     */
    public static function maintenant(?string $fuseau): \DateTimeImmutable
    {
        try {
            $zone = new \DateTimeZone((string) $fuseau);
        } catch (\Exception) {
            $zone = new \DateTimeZone(date_default_timezone_get());
        }

        return new \DateTimeImmutable('now', $zone);
    }

    /**
     * Le prénom tiré du « Nom complet » saisi à l'inscription — il n'existe pas de
     * champ prénom. Convention française : le nom de famille s'écrit en capitales
     * (« Serge SULA BOSIO », « SULA BOSIO Serge ») ; le prénom est donc le premier
     * mot qui ne l'est pas. Tout en capitales ou tout en minuscules : on ne peut
     * rien distinguer, on garde le premier mot, comme le suggère l'exemple du
     * formulaire (« Marie Dupont »).
     */
    public static function prenom(?string $nomComplet): ?string
    {
        $mots = preg_split('/\s+/u', trim((string) $nomComplet), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($mots === []) {
            return null;
        }

        foreach ($mots as $mot) {
            if (mb_strtoupper($mot) !== $mot) {
                return $mot;
            }
        }

        return $mots[0];
    }

    /** @param list<string> $entites */
    private function lisUneDe(Invite $invite, array $entites): bool
    {
        foreach ($entites as $entite) {
            if ($this->acces->canRead($invite, $entite)) {
                return true;
            }
        }

        return false;
    }
}
