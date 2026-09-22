<?php

namespace App\Ai\Oreille;

use App\Ai\Fournisseur\MemoireDEpuisement;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Les oreilles d'ElevenLabs (Scribe) : `POST /v1/speech-to-text`, envoi multipart du
 * WAV, réponse `{text}`.
 *
 * Les crédits sont les MÊMES que ceux de la voix : quand le mois est épuisé, la
 * mémoire d'épuisement (partagée) écarte aussi bien l'oreille que la bouche jusqu'au
 * mois suivant. Sans clé — cas de la production tant que le plan gratuit n'a pas de
 * licence commerciale — ce fournisseur est simplement indisponible.
 */
final class TranscriptionElevenLabs implements FournisseurDOreille
{
    private const URL = 'https://api.elevenlabs.io/v1/speech-to-text';

    private const TIMEOUT_SECONDES = 30;

    /** ISO-639-1 → ISO-639-3, attendu par l'API. */
    private const LANGUES = ['fr' => 'fra', 'en' => 'eng'];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly MemoireDEpuisement $epuisement,
        private readonly LoggerInterface $logger,
        #[Autowire(env: 'ELEVENLABS_API_KEY')] private readonly string $apiKey,
        #[Autowire(env: 'ELEVENLABS_MODELE_OREILLE')] private readonly string $modele,
        #[Autowire(env: 'AI_ENGINE')] private readonly string $moteurForce = '',
    ) {
    }

    public function nom(): string
    {
        return 'elevenlabs';
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

    public function estEpuise(): bool
    {
        return $this->epuisement->estEpuise($this->cleDEpuisement());
    }

    public function estDisponible(): bool
    {
        return trim($this->apiKey) !== '' && strtolower(trim($this->moteurForce)) !== 'simulated';
    }

    public function transcrire(string $wav, string $langue): Transcription
    {
        if ($wav === '' || !$this->estDisponible()) {
            return Transcription::refus(Transcription::INDISPONIBLE);
        }
        // Le quota est celui de la voix : un mois épuisé l'est pour les deux.
        if ($this->estEpuise()) {
            return Transcription::refus(Transcription::QUOTA);
        }

        try {
            $formulaire = new FormDataPart([
                'model_id'      => $this->modele,
                'language_code' => self::LANGUES[$langue] ?? 'fra',
                'file'          => new DataPart($wav, 'parole.wav', 'audio/wav'),
            ]);

            $reponse = $this->httpClient->request('POST', self::URL, [
                'headers' => $formulaire->getPreparedHeaders()->toArray() + ['xi-api-key' => $this->apiKey],
                'body'    => $formulaire->bodyToIterable(),
                'timeout' => self::TIMEOUT_SECONDES,
            ]);

            $statut = $reponse->getStatusCode();
            if ($statut !== 200) {
                return Transcription::refus($this->refus($statut, $reponse->getContent(false)));
            }

            return Transcription::entendue((string) ($reponse->toArray()['text'] ?? ''), $this->nom());
        } catch (\Throwable $e) {
            $this->logger->warning('Oreilles de Ket (ElevenLabs) : la transcription a échoué.', ['exception' => $e]);

            return Transcription::refus(Transcription::ECHEC);
        }
    }

    /** Même lecture des refus que la voix : `detail.code`, sinon `detail.status`. */
    private function refus(int $statut, string $corps): string
    {
        $detail = json_decode($corps, true)['detail'] ?? null;
        $cause = \is_array($detail) ? (string) ($detail['code'] ?? $detail['status'] ?? '') : '';

        if ($cause === 'quota_exceeded') {
            $this->epuisement->marquer($this->cleDEpuisement(), MemoireDEpuisement::jusquAuMoisProchain());
            $this->logger->notice('Oreilles de Ket (ElevenLabs) : crédits du mois épuisés, oreille suivante.');

            return Transcription::QUOTA;
        }
        if ($statut === 429 || \in_array($cause, ['too_many_concurrent_requests', 'system_busy'], true)) {
            $this->epuisement->marquer($this->cleDEpuisement(), 60);

            return Transcription::QUOTA;
        }

        $this->logger->warning('Oreilles de Ket (ElevenLabs) : transcription refusée.', [
            'statut' => $statut,
            'cause'  => $cause,
            'corps'  => mb_substr($corps, 0, 300),
        ]);

        return Transcription::ECHEC;
    }
}
