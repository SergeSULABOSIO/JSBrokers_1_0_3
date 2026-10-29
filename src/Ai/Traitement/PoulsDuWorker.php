<?php

namespace App\Ai\Traitement;

use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * LE WORKER DE KET EST-IL VIVANT ? — source unique (Serge, 2026-10-29).
 *
 * Quand Ket traite en file (`ASSISTANT_ASYNC=1`), une question n'obtient de réponse que si un
 * worker `messenger:consume async` tourne. Il s'arrêtait sans bruit (limite de durée, jamais
 * relancé) : la bulle « attend son tour » restait affichée une heure. Le worker BAT donc
 * (BattementDuWorkerSubscriber écrit l'heure ici) ; au-delà de SEUIL_SECONDES sans battement,
 * il est ABSENT. Le chat le dit (« Ket est momentanément indisponible… ») et la console de
 * supervision le signale — tous deux lisent CETTE classe.
 *
 * Un fichier, pas la base ni le cache : il survit au `cache:clear` du déploiement, ne coûte
 * aucune requête au scrutin du chat, et le worker comme le site l'écrivent et le lisent sous le
 * même compte. Un fichier par environnement : les tests ne voient pas le worker de dev.
 */
final class PoulsDuWorker
{
    /** Au-delà, le worker est tenu pour absent. Il bat toutes les INTERVALLE_SECONDES. */
    public const SEUIL_SECONDES = 120;
    public const INTERVALLE_SECONDES = 30;

    public const VIVANT = 'vivant';
    public const ABSENT = 'absent';

    public function __construct(
        #[Autowire('%kernel.project_dir%/var/worker/pouls-%kernel.environment%')]
        private readonly string $fichier,
        private readonly ClockInterface $horloge,
        #[Autowire('%env(bool:ASSISTANT_ASYNC)%')]
        private readonly bool $asynchrone,
    ) {
    }

    /**
     * LA RÈGLE, pure : vivant si le dernier battement date de SEUIL_SECONDES au plus.
     * Jamais battu (null), ou daté du futur au-delà du seuil (horloge déréglée) : absent.
     */
    public static function etat(?int $dernierBattement, int $maintenant, int $seuil = self::SEUIL_SECONDES): string
    {
        if ($dernierBattement === null) {
            return self::ABSENT;
        }

        return abs($maintenant - $dernierBattement) <= $seuil ? self::VIVANT : self::ABSENT;
    }

    /** Le worker dit qu'il vit : l'heure de maintenant, écrite d'un coup. */
    public function battre(): void
    {
        $dossier = \dirname($this->fichier);
        if (!is_dir($dossier)) {
            @mkdir($dossier, 0775, true);
        }
        @file_put_contents($this->fichier, (string) $this->horloge->now()->getTimestamp(), LOCK_EX);
    }

    /** L'horodatage du dernier battement, ou null s'il n'a jamais battu. */
    public function dernierBattement(): ?int
    {
        $contenu = is_file($this->fichier) ? trim((string) @file_get_contents($this->fichier)) : '';

        return ctype_digit($contenu) ? (int) $contenu : null;
    }

    /**
     * Le constat, pour la console : le mode de traitement, l'état, et depuis combien de secondes
     * le worker n'a pas battu (null s'il n'a jamais battu).
     *
     * @return array{asynchrone: bool, etat: string, depuis: ?int}
     */
    public function constat(): array
    {
        $dernier = $this->dernierBattement();
        $maintenant = $this->horloge->now()->getTimestamp();

        return [
            'asynchrone' => $this->asynchrone,
            'etat' => self::etat($dernier, $maintenant),
            'depuis' => $dernier === null ? null : max(0, $maintenant - $dernier),
        ];
    }

    /**
     * Ket peut-il répondre ? Toujours quand il traite PENDANT la requête (aucun worker en jeu) ;
     * en file, seulement si le worker vit.
     */
    public function ketDisponible(): bool
    {
        return !$this->asynchrone || $this->constat()['etat'] === self::VIVANT;
    }
}
