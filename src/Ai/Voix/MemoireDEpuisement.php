<?php

namespace App\Ai\Voix;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Se souvenir qu'une voix est ÉPUISÉE, pour ne pas lui refaire un aller-retour inutile
 * à chaque écoute. Partagé par tous les fournisseurs : seule la durée change (minuit
 * heure du Pacifique pour le quota journalier de Gemini, le mois suivant pour celui
 * d'ElevenLabs, une minute pour une saturation passagère).
 *
 * ⚠ SON DÉPÔT N'EST PAS « cache.app », ET CE N'EST PAS UN DÉTAIL. Ce pool-là vit sous
 * var/cache/<env>/pools, que « cache:clear » emporte à CHAQUE déploiement : la mémoire
 * repartait vide, et la première lecture qui suivait une mise en ligne repayait quatre
 * refus réseau avant le premier mot. Le service est donc rangé sous var/ket-voix/, chez
 * les enregistrements, que le déploiement ne touche jamais (cf. config/services.yaml).
 * Même piège, même remède que le store de déduplication de la supervision.
 */
final class MemoireDEpuisement
{
    private const PREFIXE = 'ket_voix_epuise_';

    public function __construct(
        #[Autowire(service: 'cache.app')] private readonly CacheItemPoolInterface $cache,
    ) {
    }

    public function estEpuise(string $cle): bool
    {
        return $this->cache->getItem(self::PREFIXE . md5($cle))->isHit();
    }

    public function marquer(string $cle, int $secondes): void
    {
        $item = $this->cache->getItem(self::PREFIXE . md5($cle));
        $item->set(true)->expiresAfter(max(1, $secondes));
        $this->cache->save($item);
    }

    /** Jusqu'à la remise à zéro d'un quota journalier Google : minuit, heure du Pacifique. */
    public static function jusquAMinuitPacifique(): int
    {
        $maintenant = new \DateTimeImmutable('now', new \DateTimeZone('America/Los_Angeles'));

        return max(60, $maintenant->modify('tomorrow')->getTimestamp() - $maintenant->getTimestamp());
    }

    /** Jusqu'au premier jour du mois suivant (UTC). */
    public static function jusquAuMoisProchain(): int
    {
        $maintenant = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return max(3600, $maintenant->modify('first day of next month midnight')->getTimestamp() - $maintenant->getTimestamp());
    }
}
