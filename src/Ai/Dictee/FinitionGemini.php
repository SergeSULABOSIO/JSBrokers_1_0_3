<?php

namespace App\Ai\Dictee;

use App\Ai\Debit\BudgetDebit;
use App\Ai\Fournisseur\FournisseurAModele;
use App\Ai\Fournisseur\MemoireDEpuisement;
use App\Ai\Fournisseur\ModeleChoisi;
use App\Ai\Fournisseur\PolitiqueDesFournisseurs;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * La finition de dictée chez Google — le code d'origine, déplacé.
 *
 * Sortie imposée par `responseSchema` : le modèle ne peut rendre qu'un objet
 * `{"texte": "..."}`. Ici, aucun outil n'est déclaré, donc le proto accepte le
 * schéma sans réserve — contrairement à la phase de compréhension, où les deux
 * s'excluent.
 */
final class FinitionGemini implements FournisseurDeFinition, FournisseurAModele
{
    /** Une finition lente fait attendre quelqu'un qui a fini de parler. */
    private const TIMEOUT_SECONDES = 10;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly BudgetDebit $budget,
        private readonly MemoireDEpuisement $epuisement,
        #[Autowire(env: 'GEMINI_API_KEY')] private readonly string $apiKey,
        #[Autowire(env: 'GEMINI_MODELE_COMPREHENSION')] private readonly string $modeleParDefaut,
        // Les tests forcent le moteur simulé : aucune API réelle, même avec une clé
        // posée en variable d'environnement du poste.
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
        return ModeleChoisi::pour($this->politique, 'dictee', 'gemini', $this->modeleParDefaut);
    }

    public function cleDeDebit(): string
    {
        return $this->modele();
    }

    public function estDisponible(): bool
    {
        return trim($this->apiKey) !== '' && strtolower(trim($this->moteurForce)) !== 'simulated';
    }

    /** La clé de ce fournisseur dans la mémoire d'épuisement — famille comprise. */
    public function cleDEpuisement(): string
    {
        return MemoireDEpuisement::cle('dictee', 'gemini', $this->modele());
    }

    public function estEpuise(): bool
    {
        return $this->epuisement->estEpuise($this->cleDEpuisement());
    }

    public function finir(string $consigne, string $brut, int $plafondSortie): array
    {
        $reponse = $this->httpClient->request('POST', sprintf(
            'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent',
            $this->modele(),
        ), [
            'headers' => [
                'x-goog-api-key' => $this->apiKey,
                'content-type'   => 'application/json',
            ],
            'json' => [
                'systemInstruction' => ['parts' => [['text' => $consigne]]],
                'contents'          => [['role' => 'user', 'parts' => [['text' => $brut]]]],
                'generationConfig'  => [
                    'maxOutputTokens'  => $plafondSortie,
                    'temperature'      => 0.0,
                    'responseMimeType' => 'application/json',
                    'responseSchema'   => [
                        'type'       => 'OBJECT',
                        'properties' => ['texte' => ['type' => 'STRING']],
                        'required'   => ['texte'],
                    ],
                ],
            ],
            'timeout' => self::TIMEOUT_SECONDES,
        ])->toArray();

        $tokens = (int) ($reponse['usageMetadata']['promptTokenCount'] ?? 0);
        $this->budget->enregistrer($this->cleDeDebit(), $tokens);

        $texte = '';
        foreach ($reponse['candidates'][0]['content']['parts'] ?? [] as $part) {
            $texte .= (string) ($part['text'] ?? '');
        }

        $json = json_decode(trim($texte), true);

        return [
            'texte'  => is_array($json) ? trim((string) ($json['texte'] ?? '')) : '',
            'tokens' => $tokens,
        ];
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
