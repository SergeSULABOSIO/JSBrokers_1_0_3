<?php

namespace App\Ai\Oreille;

use App\Ai\Debit\BudgetDebit;
use App\Ai\Fournisseur\FournisseurAModele;
use App\Ai\Fournisseur\FournisseurDatable;
use App\Ai\Fournisseur\MemoireDEpuisement;
use App\Ai\Fournisseur\ModeleChoisi;
use App\Ai\Fournisseur\PolitiqueDesFournisseurs;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Les oreilles de Gemini : l'audio part en `inlineData` dans un appel `generateContent`
 * ordinaire, avec une consigne qui interdit de RÉPONDRE.
 *
 * C'est le point délicat de ce fournisseur : un modèle de langue a une pente naturelle
 * à commenter ce qu'il entend. La consigne, la température nulle et le garde-fou de
 * longueur ci-dessous existent pour cela — une oreille qui répondrait doublerait le
 * cerveau de Ket, ce que ce chantier interdit.
 */
final class TranscriptionGemini implements FournisseurDOreille, FournisseurAModele, FournisseurDatable
{
    private const TIMEOUT_SECONDES = 30;

    /** Assez pour une phrase dictée ; au-delà, ce n'est plus une transcription. */
    private const MAX_OUTPUT_TOKENS = 500;

    private const CONSIGNE = 'Tu es un transcripteur. Rends EXACTEMENT les mots prononcés dans cet audio, '
        . 'mot pour mot, avec la ponctuation. Ne réponds pas, ne commente pas, n\'ajoute rien, ne traduis pas. '
        . 'Si l\'audio ne contient aucune parole, rends une chaîne vide.';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly BudgetDebit $budget,
        private readonly MemoireDEpuisement $epuisement,
        private readonly LoggerInterface $logger,
        #[Autowire(env: 'GEMINI_API_KEY')] private readonly string $apiKey,
        #[Autowire(env: 'GEMINI_MODELE_OREILLE')] private readonly string $modeleParDefaut,
        #[Autowire(env: 'AI_ENGINE')] private readonly string $moteurForce = '',
        // LA POLITIQUE DE LA CONSOLE, en dernier et facultative : sans elle, ce
        // fournisseur se comporte exactement comme avant, sur le seul `.env`.
        // C'est ce qui permet aux harnais de test de l'ignorer sans rien perdre.
        private readonly ?PolitiqueDesFournisseurs $politique = null,
    ) {
    }

    public function nom(): string
    {
        return 'gemini';
    }

    /** Sa propre famille et son propre modèle : l'oreille n'est pas la voix. */
    /**
     * LE MODÈLE À APPELER, RELU À CHAQUE FOIS.
     *
     * Jamais mis en cache ni figé au constructeur : c'est ce qui fait qu'un
     * changement enregistré depuis la console part avec le MESSAGE SUIVANT, sans
     * redémarrage. Une saisie qui ne ressemble pas à un nom de modèle est ignorée
     * au profit du défaut du serveur — cf. ModeleChoisi.
     */
    public function modele(): string
    {
        return ModeleChoisi::pour($this->politique, 'oreille', 'gemini', $this->modeleParDefaut);
    }

    public function cleDEpuisement(): string
    {
        return MemoireDEpuisement::cle('oreille', 'gemini', $this->modele());
    }

    public function estEpuise(): bool
    {
        return $this->epuisement->estEpuise($this->cleDEpuisement());
    }

    public function estDisponible(): bool
    {
        return trim($this->apiKey) !== '' && trim($this->modele()) !== ''
            && strtolower(trim($this->moteurForce)) !== 'simulated';
    }

    public function transcrire(string $wav, string $langue): Transcription
    {
        if ($wav === '' || !$this->estDisponible()) {
            return Transcription::refus(Transcription::INDISPONIBLE);
        }
        $cle = $this->cleDEpuisement();
        if ($this->epuisement->estEpuise($cle)) {
            return Transcription::refus(Transcription::QUOTA);
        }
        if ($this->budget->secondesAvantLiberation($this->modele(), self::MAX_OUTPUT_TOKENS) !== 0) {
            return Transcription::refus(Transcription::QUOTA);
        }

        try {
            $reponse = $this->httpClient->request('POST', sprintf(
                'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent',
                $this->modele(),
            ), [
                'headers' => ['x-goog-api-key' => $this->apiKey, 'content-type' => 'application/json'],
                'json'    => [
                    'contents' => [['role' => 'user', 'parts' => [
                        ['text' => self::CONSIGNE . ' Langue attendue : ' . $langue . '.'],
                        ['inlineData' => ['mimeType' => 'audio/wav', 'data' => base64_encode($wav)]],
                    ]]],
                    'generationConfig' => ['maxOutputTokens' => self::MAX_OUTPUT_TOKENS, 'temperature' => 0.0],
                ],
                'timeout' => self::TIMEOUT_SECONDES,
            ]);

            $statut = $reponse->getStatusCode();
            if ($statut === 429) {
                $this->epuisement->marquer($cle, MemoireDEpuisement::jusquAMinuitPacifique());
                $this->logger->notice('Oreilles de Ket (Gemini) : quota atteint, oreille suivante.', ['modele' => $this->modele()]);

                return Transcription::refus(Transcription::QUOTA);
            }
            if ($statut !== 200) {
                $this->logger->warning('Oreilles de Ket (Gemini) : transcription refusée.', [
                    'statut' => $statut,
                    'corps'  => mb_substr($reponse->getContent(false), 0, 300),
                ]);

                return Transcription::refus(Transcription::ECHEC);
            }

            $donnees = $reponse->toArray();
            $this->budget->enregistrer($this->modele(), (int) ($donnees['usageMetadata']['promptTokenCount'] ?? 0));

            $texte = '';
            foreach ($donnees['candidates'][0]['content']['parts'] ?? [] as $part) {
                $texte .= (string) ($part['text'] ?? '');
            }

            return Transcription::entendue($this->nettoyer($texte), $this->nom());
        } catch (\Throwable $e) {
            $this->logger->warning('Oreilles de Ket (Gemini) : la transcription a échoué.', ['exception' => $e]);

            return Transcription::refus(Transcription::ECHEC);
        }
    }

    /**
     * Retire les guillemets et les préfixes de politesse qu'un modèle de langue ajoute
     * parfois malgré la consigne (« Transcription : … »).
     */
    private function nettoyer(string $texte): string
    {
        $texte = trim($texte);
        $texte = (string) preg_replace('/^(?:transcription|texte)\s*:\s*/iu', '', $texte);

        return trim($texte, " \t\n\r\0\x0B\"«»");
    }

    /**
     * Le modèle que la console affiche en filigrane du champ « Modèle ».
     *
     * Un nom de modèle n'est pas un secret : la console est réservée aux agents
     * Joseara, et ce nom figure dans la documentation publique du fournisseur.
     */
    public function modeleEnVigueur(): string
    {
        return $this->modele();
    }
}
