<?php

namespace App\Ai\Fournisseur;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * LE DERNIER REFUS DU FOURNISSEUR, PAR MODÈLE — et pourquoi il ne pouvait être nulle part.
 *
 * Trois choses empêchent Ket de répondre, et deux seulement laissaient une trace :
 *
 *  · pas de clé sur ce serveur — la configuration le dit ;
 *  · quota épuisé — {@see MemoireDEpuisement} pose une marque datée ;
 *  · fenêtre de débit pleine — {@see \App\Ai\Debit\BudgetDebit} tient le compte.
 *
 * La quatrième n'avait pas de mémoire : le 503. « This model is currently experiencing
 * high demand » n'est pas un épuisement — aucune marque durable, aucune échéance, cela
 * passe en quelques minutes. Le moteur basculait donc sur le modèle suivant, le journal
 * le notait, et la console continuait d'annoncer « peut répondre » pendant que les trois
 * modèles refusaient. Vérifié par un appel réel le 2026-09-28.
 *
 * ── POURQUOI UNE MÉMOIRE COURTE, ET PAS UNE MARQUE ──────────────────────────────
 *
 * Une surcharge n'est pas un état du fournisseur, c'est un incident. La garder trop
 * longtemps ferait déclarer indisponible un modèle revenu depuis. On retient donc
 * quelques minutes — assez pour que l'agent qui ouvre la console après un refus
 * comprenne ce qu'il vient de voir, trop peu pour qu'elle survive au problème.
 */
final class MemoireDesRefus
{
    /** Au-delà, l'incident n'explique plus rien de ce qui se passe maintenant. */
    public const FENETRE_SECONDES = 300;

    public function __construct(
        #[Autowire(service: 'cache.app')] private readonly CacheItemPoolInterface $cache,
    ) {
    }

    /** Un refus du fournisseur pour ce modèle, avec son motif tel qu'il l'a formulé. */
    public function noter(string $modele, string $motif): void
    {
        $item = $this->cache->getItem($this->cle($modele));
        $item->set(['instant' => time(), 'motif' => $motif]);
        $item->expiresAfter(self::FENETRE_SECONDES);
        $this->cache->save($item);
    }

    /**
     * Le dernier refus encore parlant, ou null.
     *
     * @return array{secondes: int, motif: string}|null
     */
    public function dernier(string $modele): ?array
    {
        $enregistre = $this->cache->getItem($this->cle($modele))->get();
        if (!\is_array($enregistre) || !isset($enregistre['instant'])) {
            return null;
        }

        $age = time() - (int) $enregistre['instant'];
        if ($age > self::FENETRE_SECONDES) {
            return null;
        }

        return ['secondes' => max(0, $age), 'motif' => (string) ($enregistre['motif'] ?? '')];
    }

    private function cle(string $modele): string
    {
        return 'ket.refus.' . preg_replace('/[^a-zA-Z0-9_.-]/', '_', $modele);
    }
}
