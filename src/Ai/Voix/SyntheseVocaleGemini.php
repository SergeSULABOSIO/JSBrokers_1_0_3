<?php

namespace App\Ai\Voix;

use App\Ai\Debit\BudgetDebit;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * LA VOIX DE KET : synthèse vocale Gemini (voix « Aoede »), en flux.
 *
 * Mesures du 2026-09-16 sur la clé du projet, qui est au PALIER GRATUIT :
 * - la génération est gratuite, mais limitée à **10 requêtes par JOUR, par modèle**
 *   (`GenerateRequestsPerDayPerProjectPerModel-FreeTier = 10`), pour toute la
 *   plateforme ;
 * - servie d'un bloc, une réponse de 23 s d'audio met 20 à 60 s à arriver ; en flux
 *   (`streamGenerateContent`), le premier son arrive en 3 s sur la 3.1. La 2.5 accepte
 *   le même appel mais rend tout l'audio en un seul événement.
 *
 * D'où la CHAÎNE DE MODÈLES : chacun a son compteur de 10, on passe au suivant dès
 * qu'un modèle répond 429 avant d'avoir émis le moindre son. Un modèle épuisé est
 * mémorisé jusqu'à la remise à zéro du quota (minuit, heure du Pacifique) pour ne pas
 * payer un aller-retour inutile à chaque écoute. Quand toute la chaîne est épuisée,
 * c'est la voix du navigateur qui prend le relais (côté client).
 *
 * La consigne de style va DANS le texte : le modèle refuse l'instruction système
 * (« Developer instruction is not enabled for this model »).
 */
final class SyntheseVocaleGemini implements FournisseurDeVoix
{
    /** Une génération qui ne commence pas dans ce délai n'aidera personne. */
    private const TIMEOUT_SECONDES = 60;

    private const CONSIGNE = 'Lis en français, d\'une voix de jeune femme chaleureuse, posée et professionnelle, '
        . 'au débit naturel d\'une conseillère en assurance : ';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly BudgetDebit $budget,
        private readonly MemoireDEpuisement $epuisement,
        private readonly LoggerInterface $logger,
        #[Autowire(env: 'GEMINI_API_KEY')] private readonly string $apiKey,
        #[Autowire(env: 'GEMINI_MODELES_VOIX')] private readonly string $modeles,
        #[Autowire(env: 'GEMINI_VOIX_KET')] private readonly string $voix,
        // Les tests forcent le moteur simulé : aucune API réelle.
        #[Autowire(env: 'AI_ENGINE')] private readonly string $moteurForce = '',
    ) {
    }

    public function nom(): string
    {
        return 'gemini';
    }

    public function voix(): string
    {
        return $this->voix;
    }

    public function estDisponible(): bool
    {
        return trim($this->apiKey) !== '' && strtolower(trim($this->moteurForce)) !== 'simulated'
            && $this->listeModeles() !== [];
    }

    /**
     * Le texte en voix, morceau PCM par morceau PCM.
     *
     * Le générateur rend son statut en valeur de retour (`getReturn()`) : COMPLET si au
     * moins un son a été émis et que le flux s'est terminé normalement ; QUOTA quand toute
     * la chaîne est épuisée avant le premier son ; INDISPONIBLE sans moteur réel ; ECHEC
     * sinon. Un échec APRÈS le premier son rend ECHEC : l'audio partiel ne doit être ni
     * mis en cache ni facturé.
     *
     * @return \Generator<int, string, mixed, string>
     */
    public function flux(string $texte): \Generator
    {
        $texte = trim($texte);
        if ($texte === '' || !$this->estDisponible()) {
            return self::INDISPONIBLE;
        }

        $quota = false;
        foreach ($this->listeModeles() as $modele) {
            if ($this->epuisement->estEpuise('gemini:' . $modele)) {
                $quota = true;
                continue;
            }
            if ($this->budget->secondesAvantLiberation($modele, 2000) !== 0) {
                continue;
            }

            $emis = 0;
            try {
                $reponse = $this->httpClient->request('POST', sprintf(
                    'https://generativelanguage.googleapis.com/v1beta/models/%s:streamGenerateContent?alt=sse',
                    $modele,
                ), [
                    'headers' => ['x-goog-api-key' => $this->apiKey, 'content-type' => 'application/json'],
                    'json'    => [
                        'contents'         => [['role' => 'user', 'parts' => [['text' => self::CONSIGNE . $texte]]]],
                        'generationConfig' => [
                            'responseModalities' => ['AUDIO'],
                            'speechConfig'       => ['voiceConfig' => ['prebuiltVoiceConfig' => ['voiceName' => $this->voix]]],
                        ],
                    ],
                    'timeout' => self::TIMEOUT_SECONDES,
                    'buffer'  => false,
                ]);

                $statut = $reponse->getStatusCode();
                if ($statut === 429) {
                    // Quota JOURNALIER (10 générations au palier gratuit) : épuisé jusqu'à minuit
                    // heure du Pacifique, et le modèle suivant prend la main.
                    $this->epuisement->marquer('gemini:' . $modele, MemoireDEpuisement::jusquAMinuitPacifique());
                    $this->logger->notice('Voix de Ket : quota journalier gratuit atteint, modèle suivant.', ['modele' => $modele]);
                    $quota = true;
                    continue;
                }
                if ($statut !== 200) {
                    $this->logger->warning('Voix de Ket : le modèle a refusé la synthèse.', [
                        'modele' => $modele,
                        'statut' => $statut,
                        'corps'  => mb_substr($reponse->getContent(false), 0, 500),
                    ]);
                    continue;
                }

                $tampon = '';
                $tokens = 0;
                foreach ($this->httpClient->stream($reponse) as $morceau) {
                    $tampon .= $morceau->getContent();
                    foreach ($this->extraireEvenements($tampon) as $evenement) {
                        $tokens = (int) ($evenement['usageMetadata']['promptTokenCount'] ?? $tokens);
                        foreach ($evenement['candidates'][0]['content']['parts'] ?? [] as $part) {
                            $pcm = base64_decode((string) ($part['inlineData']['data'] ?? ''), true);
                            if ($pcm !== false && $pcm !== '') {
                                $emis += \strlen($pcm);
                                yield $pcm;
                            }
                        }
                    }
                }
                $this->budget->enregistrer($modele, $tokens);
            } catch (\Throwable $e) {
                $this->logger->warning('Voix de Ket : la synthèse a échoué.', ['modele' => $modele, 'exception' => $e]);
                if ($emis > 0) {
                    return self::ECHEC;
                }
                continue;
            }

            if ($emis > 0) {
                return self::COMPLET;
            }
        }

        return $quota ? self::QUOTA : self::ECHEC;
    }

    /**
     * Les événements SSE complets du tampon (lignes « data: {json} » séparées par une
     * ligne vide). Ce qui n'est pas encore terminé reste dans le tampon.
     *
     * @return list<array<string, mixed>>
     */
    public function extraireEvenements(string &$tampon): array
    {
        $tampon = str_replace("\r\n", "\n", $tampon);
        $evenements = [];
        while (($fin = strpos($tampon, "\n\n")) !== false) {
            $bloc = substr($tampon, 0, $fin);
            $tampon = substr($tampon, $fin + 2);
            $donnees = '';
            foreach (explode("\n", $bloc) as $ligne) {
                if (str_starts_with($ligne, 'data:')) {
                    $donnees .= ltrim(substr($ligne, 5));
                }
            }
            $json = json_decode($donnees, true);
            if (is_array($json)) {
                $evenements[] = $json;
            }
        }

        return $evenements;
    }

    /** @return list<string> */
    private function listeModeles(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $this->modeles))));
    }
}
