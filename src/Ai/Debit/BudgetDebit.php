<?php

namespace App\Ai\Debit;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Compteur de débit du fournisseur d'IA : combien de tokens d'ENTRÉE ont été
 * consommés sur la minute écoulée, et combien il en reste.
 *
 * POURQUOI CE SERVICE EXISTE. Le moteur se protégeait auparavant avec un plafond
 * de tokens PAR MESSAGE (MAX_INPUT_TOKENS_PAR_MESSAGE). C'était une erreur de
 * catégorie : le quota du fournisseur se compte PAR MINUTE et se partage entre
 * TOUS les invités, un cap par message n'a donc aucun rapport avec ce qui est
 * réellement disponible. La mesure du 2026-08-08 l'a montré sans appel — sur
 * 23 messages, 10 ont été refusés par ce cap et UN SEUL par un vrai 429. Autrement
 * dit, l'assistant s'interdisait de finir son travail alors que la fenêtre était
 * vide. On mesure désormais la fenêtre plutôt que de la deviner.
 *
 * UN COMPTEUR PAR MODÈLE. Chez Gemini, « flash » et « flash-lite » ont des
 * compteurs SÉPARÉS au même plafond : les additionner ferait refuser à tort.
 *
 * LE CACHE NE DESSERRE PAS LE PLAFOND. On enregistre le promptTokenCount COMPLET,
 * tokens cachés inclus. Le cache implicite du fournisseur (71 à 93 % de hit
 * observés) allège la facture, jamais le débit : le 429 du 2026-08-08T21:59 est
 * survenu alors que 77 % des tokens de la minute étaient cachés.
 *
 * CONCURRENCE. Le stockage passe par le pool « cache.app » (partagé entre
 * processus — indispensable, le quota l'étant aussi). La séquence lire-modifier-
 * écrire n'est pas atomique : deux requêtes simultanées peuvent se recouvrir et
 * sous-compter. C'est précisément ce qu'absorbe la MARGE — on préfère renoncer à
 * quelques tokens plutôt que prendre un 429, dont le prix est bien plus élevé :
 * les tokens du message sont DÉJÀ débités au compte de l'utilisateur
 * (TokenAccountService::meterWrite) avant même l'appel au moteur.
 */
final class BudgetDebit
{
    /**
     * Plafond du palier GRATUIT Gemini (quota
     * GenerateContentInputTokensPerModelPerMinute-FreeTier, relevé dans le corps
     * d'un 429 réel). Surchargeable par GEMINI_TPM_PLAFOND : c'est le seul
     * réglage à changer le jour où la facturation est activée.
     */
    public const PLAFOND_DEFAUT_PAR_MINUTE = 250000;

    /** Fenêtre du quota, en secondes. */
    public const FENETRE_SECONDES = 60;

    /**
     * Part du plafond gardée en réserve (courses entre processus, imprécision
     * d'estimation).
     *
     * PUBLIQUE parce qu'elle est partagée : le dialecte Anthropic s'en sert pour
     * décider à partir de quel solde ANNONCÉ il faut écarter le fournisseur. Deux
     * prudences différentes sur la même question donneraient deux comportements
     * qu'on ne saurait plus expliquer.
     */
    public const MARGE = 0.15;

    /** @var \Closure(): int horloge injectable — les tests ne doivent pas dormir */
    private readonly \Closure $horloge;

    /**
     * @param array<string, int> $plafondsParPrefixe plafonds propres à certains
     *                                               compteurs, indexés par préfixe de clé
     */
    public function __construct(
        #[Autowire(service: 'cache.app')] private readonly CacheItemPoolInterface $cache,
        #[Autowire(env: 'int:GEMINI_TPM_PLAFOND')] private readonly int $plafondParMinute = self::PLAFOND_DEFAUT_PAR_MINUTE,
        private readonly float $marge = self::MARGE,
        ?\Closure $horloge = null,
        // 5ᵉ POSITION, ET C'EST VOULU : les onze tests existants passent des arguments
        // positionnels 1 à 4. Un paramètre glissé avant eux les aurait tous cassés,
        // pour un enrichissement qui ne les concerne pas.
        private readonly array $plafondsParPrefixe = [],
    ) {
        $this->horloge = $horloge ?? static fn (): int => time();
    }

    /**
     * Plafond réellement opposable, marge de sécurité déduite.
     *
     * POURQUOI UN PLAFOND PAR PRÉFIXE plutôt qu'un seul réglage. Cette classe est
     * restée mono-fournisseur tant qu'un seul moteur l'utilisait : son plafond vient
     * de GEMINI_TPM_PLAFOND, et cette variable est lue telle quelle par les deux
     * commandes de mesure — la renommer les casserait sans rien apporter.
     *
     * Or les fournisseurs ne se ressemblent pas. Gemini compte un seul quota de
     * tokens d'entrée, cache inclus. Anthropic en tient DEUX (entrée et sortie,
     * plafonds distincts) et n'y compte PAS les tokens lus en cache — son plafond
     * d'entrée est huit fois plus haut. Opposer le plafond de Google au compteur
     * d'Anthropic ferait patienter Ket devant une porte grande ouverte.
     *
     * La clé du compteur porte donc son préfixe (« anthropic:in: »), et c'est le
     * préfixe qui décide du plafond. Une clé inconnue retombe sur le défaut : aucun
     * appelant existant ne change de comportement.
     */
    public function plafondUtile(?string $compteur = null): int
    {
        $plafond = $this->plafondPour($compteur);

        return max(1, (int) floor($plafond * (1.0 - $this->marge)));
    }

    /** Le plafond BRUT applicable à ce compteur, marge non déduite. */
    private function plafondPour(?string $compteur): int
    {
        if ($compteur !== null) {
            foreach ($this->plafondsParPrefixe as $prefixe => $plafond) {
                if ($plafond > 0 && str_starts_with($compteur, (string) $prefixe)) {
                    return $plafond;
                }
            }
        }

        return $this->plafondParMinute > 0 ? $this->plafondParMinute : self::PLAFOND_DEFAUT_PAR_MINUTE;
    }

    /** Tokens d'entrée encore disponibles sur la minute glissante, pour ce compteur. */
    public function restant(string $modele): int
    {
        return max(0, $this->plafondUtile($modele) - $this->consomme($this->fenetre($modele)));
    }

    /** Enregistre les tokens d'entrée facturés par un aller-retour (cache inclus). */
    public function enregistrer(string $modele, int $tokens): void
    {
        if ($tokens <= 0) {
            return;
        }

        $fenetre = $this->fenetre($modele);
        $fenetre[] = [($this->horloge)(), $tokens];
        $this->ecrire($modele, $fenetre);
    }

    /**
     * Dans combien de secondes la fenêtre aura-t-elle assez de place pour
     * $tokensVoulus ? 0 si c'est déjà le cas.
     *
     * Rend null quand la demande dépasse le plafond à elle seule : attendre n'y
     * changerait rien, même une fenêtre vide ne suffirait pas. L'appelant doit
     * alors conclure au lieu de patienter pour rien.
     */
    public function secondesAvantLiberation(string $modele, int $tokensVoulus): ?int
    {
        $utile = $this->plafondUtile($modele);
        if ($tokensVoulus > $utile) {
            return null;
        }

        $fenetre = $this->fenetre($modele);
        $consomme = $this->consomme($fenetre);
        if ($consomme + $tokensVoulus <= $utile) {
            return 0;
        }

        // Les entrées sont retirées de la fenêtre par ancienneté : on avance
        // jusqu'à ce que celles qui restent laissent la place demandée.
        usort($fenetre, static fn (array $a, array $b) => $a[0] <=> $b[0]);
        $maintenant = ($this->horloge)();
        foreach ($fenetre as [$instant, $tokens]) {
            $consomme -= $tokens;
            if ($consomme + $tokensVoulus <= $utile) {
                // +1 : la seconde où l'entrée sort tout juste de la fenêtre.
                return max(0, $instant + self::FENETRE_SECONDES - $maintenant + 1);
            }
        }

        return 0;
    }

    /**
     * Entrées encore dans la fenêtre, purgées des périmées.
     *
     * @return list<array{0: int, 1: int}>
     */
    private function fenetre(string $modele): array
    {
        $enregistre = $this->cache->getItem($this->cle($modele))->get();
        if (!\is_array($enregistre)) {
            return [];
        }

        $limite = ($this->horloge)() - self::FENETRE_SECONDES;
        $fenetre = [];
        foreach ($enregistre as $entree) {
            if (\is_array($entree) && isset($entree[0], $entree[1]) && (int) $entree[0] > $limite) {
                $fenetre[] = [(int) $entree[0], (int) $entree[1]];
            }
        }

        return $fenetre;
    }

    /** @param list<array{0: int, 1: int}> $fenetre */
    private function consomme(array $fenetre): int
    {
        return array_sum(array_column($fenetre, 1));
    }

    /** @param list<array{0: int, 1: int}> $fenetre */
    private function ecrire(string $modele, array $fenetre): void
    {
        $item = $this->cache->getItem($this->cle($modele));
        $item->set($fenetre);
        // Au-delà de la fenêtre, l'entrée ne vaut plus rien : on laisse le pool
        // la balayer plutôt que d'accumuler un fichier par modèle jamais purgé.
        $item->expiresAfter(self::FENETRE_SECONDES * 2);
        $this->cache->save($item);
    }

    /** Clé PSR-6 valide (le jeu de caractères autorisé exclut « : », « @ », « {} »…). */
    private function cle(string $modele): string
    {
        return 'ai_debit.' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $modele);
    }
}
