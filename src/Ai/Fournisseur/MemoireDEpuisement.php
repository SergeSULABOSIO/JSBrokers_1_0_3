<?php

namespace App\Ai\Fournisseur;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * SE SOUVENIR QU'UN FOURNISSEUR EST À SEC, pour ne plus l'interroger tant qu'il
 * ne peut rien rendre.
 *
 * Un refus de quota coûte un aller-retour réseau et quelques secondes d'attente,
 * pour un résultat connu d'avance. Le payer une fois est inévitable ; le payer à
 * chaque tour est un défaut. Le fournisseur s'annonce donc lui-même au moment où
 * il l'apprend, et la chaîne l'écarte ensuite sans lui parler
 * (cf. OrdreDesFournisseurs::disponibles).
 *
 * LA DURÉE VIENT DU FOURNISSEUR, JAMAIS DE NOUS : minuit heure du Pacifique pour
 * le quota journalier de Google, le premier du mois pour les crédits ElevenLabs,
 * la valeur de « retry-after » pour une saturation de débit, et la date écrite en
 * toutes lettres dans le message pour un plafond de dépense Anthropic. Inventer
 * une durée, ce serait soit réveiller le fournisseur trop tôt — et repayer le
 * refus —, soit l'écarter plus longtemps qu'il ne le demande.
 *
 * ⚠ SON DÉPÔT N'EST PAS « cache.app », ET CE N'EST PAS UN DÉTAIL. Ce pool-là vit
 * sous var/cache/<env>/pools, que « cache:clear » emporte à CHAQUE déploiement :
 * la mémoire repartait vide, et la première lecture qui suivait une mise en ligne
 * repayait quatre refus réseau avant le premier mot. Le service est donc rangé
 * sous var/ket-voix/, chez les enregistrements, que le déploiement ne touche
 * jamais (cf. config/services.yaml). Même piège, même remède que le store de
 * déduplication de la supervision.
 *
 * Le dossier garde son nom historique : le renommer effacerait les marques en
 * cours, et l'emplacement est documenté à deux endroits.
 */
final class MemoireDEpuisement
{
    private const PREFIXE = 'ket_voix_epuise_';

    public function __construct(
        #[Autowire(service: 'cache.app')] private readonly CacheItemPoolInterface $cache,
        /** Injectable pour les tests : une suite qui dépend de l'heure n'en est plus une. */
        private readonly ?\Closure $horloge = null,
    ) {
    }

    /**
     * LA CLÉ PORTE SA FAMILLE, et ce n'est pas cosmétique.
     *
     * Sans elle, deux usages d'un même fournisseur se marquent l'un l'autre : un
     * quota de synthèse vocale épuisé écarterait la transcription, alors que ce
     * sont deux compteurs distincts chez le fournisseur. Là où les crédits SONT
     * réellement partagés — ElevenLabs facture la voix et l'oreille sur la même
     * réserve —, on le dit explicitement en donnant la même famille aux deux,
     * plutôt que de le laisser arriver par accident.
     */
    public static function cle(string $famille, string $fournisseur, ?string $modele = null): string
    {
        return $modele === null || $modele === ''
            ? sprintf('%s:%s', $famille, $fournisseur)
            : sprintf('%s:%s:%s', $famille, $fournisseur, $modele);
    }

    public function estEpuise(string $cle): bool
    {
        return $this->cache->getItem(self::item($cle))->isHit();
    }

    /**
     * Marque ce fournisseur à sec pour la durée annoncée, et RETIENT L'ÉCHÉANCE.
     *
     * La valeur stockée est l'instant de réouverture, pas un simple « oui » : c'est
     * ce qui permet à la console de dire « à sec jusqu'à 09:05 » au lieu du seul
     * « indisponible », et à Ket d'annoncer un délai réel plutôt qu'un vague
     * « réessayez plus tard ».
     */
    public function marquer(string $cle, int $secondes): void
    {
        $secondes = max(1, $secondes);
        $item = $this->cache->getItem(self::item($cle));
        $item->set($this->maintenant() + $secondes)->expiresAfter($secondes);
        $this->cache->save($item);
    }

    /** L'instant de réouverture, ou null si ce fournisseur n'est pas marqué. */
    public function echeance(string $cle): ?\DateTimeImmutable
    {
        $item = $this->cache->getItem(self::item($cle));
        if (!$item->isHit()) {
            return null;
        }

        $instant = $item->get();

        return \is_int($instant) ? (new \DateTimeImmutable())->setTimestamp($instant) : null;
    }

    /**
     * RÉARME un fournisseur avant son échéance.
     *
     * Une marque erronée — un 429 mal interprété, une clé changée entre-temps —
     * met un fournisseur hors jeu jusqu'à minuit heure du Pacifique. Sans ce
     * geste, la seule issue serait un accès serveur ; c'est ce que le bouton de la
     * console appelle.
     */
    public function oublier(string $cle): void
    {
        $this->cache->deleteItem(self::item($cle));
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

    /**
     * Jusqu'à une date que le fournisseur a lui-même annoncée — le plafond de
     * dépense mensuel d'Anthropic écrit la sienne dans son message d'erreur.
     * Une date déjà passée rend une minute : mieux vaut réessayer une fois de trop
     * que rester fermé sur une horloge mal lue.
     */
    public static function jusqua(\DateTimeImmutable $reouverture, ?\DateTimeImmutable $maintenant = null): int
    {
        $maintenant ??= new \DateTimeImmutable();

        return max(60, $reouverture->getTimestamp() - $maintenant->getTimestamp());
    }

    private function maintenant(): int
    {
        return ($this->horloge ?? static fn (): int => time())();
    }

    private static function item(string $cle): string
    {
        return self::PREFIXE . md5($cle);
    }
}
