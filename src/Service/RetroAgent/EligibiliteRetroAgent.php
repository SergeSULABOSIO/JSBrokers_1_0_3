<?php

namespace App\Service\RetroAgent;

use App\Entity\Piste;
use App\Repository\PisteRepository;

/**
 * L'AFFAIRE OUVRE-T-ELLE DROIT À UNE RÉTROCOMMISSION D'AGENT ? — verdict INDICATIF.
 *
 * La règle du cabinet reconnaît deux efforts commerciaux récompensables :
 *  - l'agent apporte une NOUVELLE AFFAIRE : le client n'était pas au portefeuille de la
 *    société l'exercice précédent ;
 *  - l'agent SATURE un client existant sur une LIGNE NEUVE : le couple client × risque
 *    était absent du portefeuille de la société en N-1, même si le client y figurait.
 * Une ligne déjà couverte en N-1 n'est ni l'un ni l'autre : c'est un renouvellement.
 *
 * ── POURQUOI CE VERDICT NE BLOQUE RIEN ──────────────────────────────────────────────
 * Il informe, il n'interdit pas. Une reprise de portefeuille, une affaire réattribuée
 * après le départ d'un collègue, un client revenu après deux ans d'absence : autant de
 * cas légitimes qu'une règle mécanique classerait « hors règle ». Refuser la saisie
 * obligerait alors à intervenir en base — le pire des contournements. Le gestionnaire
 * voit le verdict, et décide.
 *
 * ── LE PÉRIMÈTRE EST CELUI DE LA SOCIÉTÉ, PAS DE L'AGENT ────────────────────────────
 * « N'ayant pas été dans le portefeuille GLOBAL de la société » : on interroge tout le
 * portefeuille de l'entreprise, pas seulement les affaires de l'agent ni celles du
 * gestionnaire. Une ligne qu'un autre collaborateur couvrait déjà en N-1 n'est pas neuve.
 */
final class EligibiliteRetroAgent
{
    /** Le client était absent du portefeuille de la société en N-1. */
    public const NOUVELLE_AFFAIRE = 'nouvelle_affaire';
    /** Le client était présent, mais pas sur ce risque. */
    public const NOUVELLE_LIGNE_SATUREE = 'nouvelle_ligne_saturee';
    /** Le couple client × risque était déjà couvert : renouvellement, hors règle. */
    public const LIGNE_EXISTANTE_N1 = 'ligne_existante_n1';
    /** Exercice ou risque absent : rien à comparer, on ne conclut pas. */
    public const INDETERMINE = 'indetermine';

    private const LIBELLES = [
        self::NOUVELLE_AFFAIRE => 'Nouvelle affaire',
        self::NOUVELLE_LIGNE_SATUREE => 'Nouvelle ligne saturée',
        self::LIGNE_EXISTANTE_N1 => 'Ligne déjà au portefeuille en N-1',
        self::INDETERMINE => 'Éligibilité indéterminée',
    ];

    /** @var array<string, string> mémoïsation : une page de liste interroge le même exercice N-1 en boucle */
    private array $cache = [];

    public function __construct(
        private readonly PisteRepository $pisteRepository,
    ) {
    }

    /** Le verdict pour cette affaire, l'une des constantes ci-dessus. */
    public function verdict(Piste $piste): string
    {
        $entreprise = $piste->getEntreprise();
        $client = $piste->getClient();
        $risque = $piste->getRisque();
        $exercice = $piste->getExercice();

        if ($entreprise === null || $client === null || $risque === null || !$exercice) {
            return self::INDETERMINE;
        }

        $cle = implode(':', [
            (int) $entreprise->getId(), (int) $client->getId(), (int) $risque->getId(), $exercice,
        ]);
        if (isset($this->cache[$cle])) {
            return $this->cache[$cle];
        }

        $precedent = $exercice - 1;

        // Deux questions, dans cet ordre : le client existait-il, puis cette ligne-là.
        // L'ordre compte — « nouvelle affaire » et « nouvelle ligne » ne se distinguent
        // que par la première réponse, et le libellé rendu à l'utilisateur en dépend.
        if (!$this->pisteRepository->clientCouvertEnExercice($entreprise, $client, $precedent)) {
            return $this->cache[$cle] = self::NOUVELLE_AFFAIRE;
        }

        return $this->cache[$cle] = $this->pisteRepository->ligneCouverteEnExercice($entreprise, $client, $risque, $precedent)
            ? self::LIGNE_EXISTANTE_N1
            : self::NOUVELLE_LIGNE_SATUREE;
    }

    public function libelle(string $verdict): string
    {
        return self::LIBELLES[$verdict] ?? self::LIBELLES[self::INDETERMINE];
    }

    /**
     * L'effort est-il de ceux que la règle récompense ? Un verdict INDÉTERMINÉ répond oui :
     * faute de pouvoir comparer, on n'accuse pas — l'avertissement est réservé au cas où
     * l'on SAIT que la ligne était déjà couverte.
     */
    public function estEligible(Piste $piste): bool
    {
        return $this->verdict($piste) !== self::LIGNE_EXISTANTE_N1;
    }

    /**
     * L'avertissement à afficher quand une rétrocommission est déclarée hors règle, ou null
     * quand il n'y a rien à signaler.
     */
    public function avertissement(Piste $piste): ?string
    {
        if ($this->verdict($piste) !== self::LIGNE_EXISTANTE_N1) {
            return null;
        }

        return sprintf(
            'Rétrocommission déclarée hors règle : %s figurait déjà au portefeuille de la '
            . 'société sur %s en %d. La règle récompense une nouvelle affaire ou une ligne '
            . 'nouvellement saturée.',
            $piste->getClient()?->getNom() ?? 'ce client',
            $piste->getRisque()?->getCode() ?? 'ce risque',
            ((int) $piste->getExercice()) - 1,
        );
    }
}
