<?php

namespace App\Ai\Voix;

use App\Ai\Fournisseur\MemoireDEpuisement;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * LA VOIX DE KET PAR ELEVENLABS.
 *
 * Vérifié le 2026-09-17 dans la documentation : l'API en flux sait rendre directement
 * du PCM 16 bits 24 kHz (`output_format=pcm_24000`), le format exact que le lecteur du
 * navigateur joue déjà. Le corps de la réponse EST l'audio : chaque morceau HTTP part
 * tel quel, sans SSE ni base64.
 *
 * Plan gratuit : 10 000 crédits par mois, voix par défaut seulement, PAS de licence
 * commerciale — il sert aux tests. En production, la clé n'est posée qu'avec un plan
 * payant (Starter) ; sans clé, ce fournisseur est simplement indisponible.
 */
final class SyntheseVocaleElevenLabs implements FournisseurDeVoix
{
    private const URL = 'https://api.elevenlabs.io/v1/text-to-speech/%s/stream?output_format=pcm_24000';

    private const TIMEOUT_SECONDES = 60;

    /** Diction posée et professionnelle, sans théâtralité. */
    private const REGLAGES = ['stability' => 0.5, 'similarity_boost' => 0.75, 'style' => 0.2, 'speed' => 1.0];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly MemoireDEpuisement $epuisement,
        private readonly LoggerInterface $logger,
        #[Autowire(env: 'ELEVENLABS_API_KEY')] private readonly string $apiKey,
        #[Autowire(env: 'ELEVENLABS_VOIX_KET')] private readonly string $voix,
        #[Autowire(env: 'ELEVENLABS_MODELE')] private readonly string $modele,
        // MODE LIVE : le modèle rapide. Mesuré le 2026-09-17 sur la voix Bella —
        // premier son à 1,2 s contre 2,3 s, et deux fois moins de crédits. Dans une
        // conversation parlée, cette seconde compte plus que la richesse de diction.
        #[Autowire(env: 'ELEVENLABS_MODELE_LIVE')] private readonly string $modeleLive,
        #[Autowire(env: 'AI_ENGINE')] private readonly string $moteurForce = '',
    ) {
    }

    public function nom(): string
    {
        return 'elevenlabs';
    }

    public function voix(): string
    {
        return $this->voix;
    }

    /**
     * CRÉDITS PARTAGÉS, ET C'EST VOULU. ElevenLabs facture la voix et la
     * transcription sur la MÊME réserve mensuelle : quand elle est vide, les deux
     * le sont. La famille « credits » le dit explicitement, là où la clé « elevenlabs »
     * d'avant le laissait arriver par accident — ce qui rendait le comportement juste
     * pour la mauvaise raison, et faux le jour où les réserves se sépareraient.
     */
    public function cleDEpuisement(): string
    {
        return MemoireDEpuisement::cle('credits', 'elevenlabs');
    }

    public function estDisponible(): bool
    {
        return trim($this->apiKey) !== '' && trim($this->voix) !== ''
            && strtolower(trim($this->moteurForce)) !== 'simulated';
    }

    public function modele(bool $vitesse = false): string
    {
        return $vitesse && trim($this->modeleLive) !== '' ? $this->modeleLive : $this->modele;
    }

    public function estEpuise(): bool
    {
        return $this->epuisement->estEpuise($this->cleDEpuisement());
    }

    public function flux(string $texte, bool $vitesse = false): \Generator
    {
        $texte = trim($texte);
        if ($texte === '' || !$this->estDisponible()) {
            return self::INDISPONIBLE;
        }
        if ($this->estEpuise()) {
            return self::QUOTA;
        }

        $emis = 0;
        try {
            $reponse = $this->httpClient->request('POST', sprintf(self::URL, rawurlencode($this->voix)), [
                'headers' => ['xi-api-key' => $this->apiKey, 'content-type' => 'application/json', 'accept' => 'audio/pcm'],
                'json'    => [
                    'text'           => $texte,
                    'model_id'       => $this->modele($vitesse),
                    'language_code'  => 'fr',
                    'voice_settings' => self::REGLAGES,
                ],
                'timeout' => self::TIMEOUT_SECONDES,
                'buffer'  => false,
            ]);

            $statut = $reponse->getStatusCode();
            if ($statut !== 200) {
                return $this->refus($statut, $reponse->getContent(false));
            }

            foreach ($this->httpClient->stream($reponse) as $morceau) {
                $pcm = $morceau->getContent();
                if ($pcm !== '') {
                    $emis += \strlen($pcm);
                    yield $pcm;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Voix de Ket (ElevenLabs) : la synthèse a échoué.', ['exception' => $e]);

            return self::ECHEC;
        }

        return $emis > 0 ? self::COMPLET : self::ECHEC;
    }

    /**
     * Un refus d'ElevenLabs : le statut applicatif est dans `detail.status`. Le quota du
     * mois épuisé met le fournisseur de côté jusqu'au mois suivant ; une saturation
     * passagère, une minute. Le reste (clé invalide, voix réservée aux plans payants…) est
     * une erreur de configuration : journalisée, et la voix suivante prend la main.
     */
    private function refus(int $statut, string $corps): string
    {
        // Deux formats coexistent : `detail.code` (relevé le 2026-09-17, ex. « paid_plan_required »)
        // et l'ancien `detail.status`.
        $detail = json_decode($corps, true)['detail'] ?? null;
        $cause = \is_array($detail) ? (string) ($detail['code'] ?? $detail['status'] ?? '') : '';

        if ($cause === 'quota_exceeded') {
            $this->epuisement->marquer($this->cleDEpuisement(), MemoireDEpuisement::jusquAuMoisProchain());
            $this->logger->notice('Voix de Ket (ElevenLabs) : crédits du mois épuisés, voix suivante.');

            return self::QUOTA;
        }
        if ($statut === 429 || \in_array($cause, ['too_many_concurrent_requests', 'system_busy'], true)) {
            $this->epuisement->marquer($this->cleDEpuisement(), 60);

            return self::QUOTA;
        }

        $this->logger->warning('Voix de Ket (ElevenLabs) : synthèse refusée.', [
            'statut' => $statut,
            'cause'  => $cause,
            'corps'  => mb_substr($corps, 0, 300),
        ]);

        return self::ECHEC;
    }
}
