<?php

namespace App\Ai\Engine\Socle;

use App\Ai\AiEngineFailure;
use App\Ai\AiRequest;
use App\Ai\AiText;
use App\Ai\Debit\BudgetDebit;
use App\Ai\Engine\Usage;
use App\Ai\Fournisseur\MemoireDEpuisement;
use App\Ai\Trousse\Phase;
use App\Ai\Trousse\Trousse;
use App\Ai\Trousse\TrousseCatalogue;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * LE FIL AU FORMAT ANTHROPIC (Messages API), et le transport qui va avec.
 *
 * Ce qui est ici et nulle part ailleurs : les rôles user/assistant, le prompt
 * système dans « system », les outils en name/description/input_schema, la clé en
 * en-tête x-api-key, les blocs image/document pour les pièces natives — et les
 * deux particularités qui n'existent que chez ce fournisseur : le cache de prompt
 * EXPLICITE, et un plafond par minute qui ne compte pas les tokens lus en cache.
 *
 * Adaptateur direct (sans SDK) : le projet est verrouillé en Symfony 7.1.*,
 * incompatible avec les paquets symfony/ai-* (qui exigent clock ^7.3 et
 * phpdoc-parser ^2). Le jour où le socle passera en 7.3+, un adaptateur Symfony
 * AI pourra remplacer celui-ci sans toucher à l'orchestrateur.
 */
final class DialecteAnthropicDuFil implements DialecteDuFil
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION = '2023-06-01';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly \Closure $promptSysteme,
        private readonly TrousseCatalogue $trousseCatalogue,
        private readonly string $apiKey,
        private readonly string $model,
        private readonly LoggerInterface $logger,
        private readonly int $maxOutputTokens,
        private readonly int $maxAttenteSecondes,
        private readonly int $attenteSurchargeSecondes,
        private readonly bool $cacheActif,
        private readonly ?\Closure $dormir = null,
        private readonly ?MemoireDEpuisement $epuisement = null,
        private readonly string $cleDEpuisement = '',
    ) {
    }

    public function nom(): string
    {
        return 'anthropic';
    }

    /** Aucun repli de modèle ici : le remède d'Anthropic est le réessai, pas la bascule. */
    public function modeleCourant(): string
    {
        return $this->model;
    }

    /**
     * Le compteur d'ENTRÉE de ce modèle.
     *
     * Le préfixe n'est pas décoratif : Anthropic plafonne séparément l'entrée et la
     * sortie, et surtout il ne compte pas la même chose que Google — d'où une clé
     * qui ne peut pas être le simple nom du modèle.
     */
    public function cleDeDebit(): string
    {
        return 'anthropic:in:' . $this->model;
    }

    public function filInitial(AiRequest $request): array
    {
        return array_map(
            static fn (array $m) => ['role' => $m['role'], 'content' => $m['content']],
            $request->messages,
        );
    }

    /**
     * Joint les pièces lisibles nativement (images, PDF scannés) au DERNIER tour
     * utilisateur, en blocs de contenu Messages API.
     *
     * Sans cela, le prompt affirmait au modèle que ces pièces lui « sont transmises
     * DIRECTEMENT pour lecture visuelle » alors que ce moteur ne les envoyait pas :
     * une image ou un PDF sans couche texte était invisible, et le modèle, sommé de
     * le lire, n'avait d'autre issue que d'inventer. L'écart était sans effet tant
     * que Gemini restait le moteur actif — mais ANTHROPIC_API_KEY est PRIORITAIRE
     * dans AiEngineResolver, donc une simple clé posée suffisait à l'ouvrir.
     */
    public function joindrePieces(array $fil, array $pieces): array
    {
        if ($pieces === []) {
            return $fil;
        }

        for ($i = count($fil) - 1; $i >= 0; $i--) {
            if (($fil[$i]['role'] ?? null) !== 'user') {
                continue;
            }
            // Le tour devient une liste de blocs : le texte d'abord, les pièces ensuite.
            $contenu = $fil[$i]['content'];
            $blocs = is_array($contenu) ? $contenu : [['type' => 'text', 'text' => (string) $contenu]];

            foreach ($pieces as $piece) {
                $mime = (string) $piece['mimeType'];
                // Une image et un PDF ne portent pas le même type de bloc chez Anthropic.
                $blocs[] = [
                    'type'   => $mime === 'application/pdf' ? 'document' : 'image',
                    'source' => [
                        'type'       => 'base64',
                        'media_type' => $mime,
                        'data'       => (string) $piece['donneesBase64'],
                    ],
                ];
            }
            $fil[$i]['content'] = $blocs;
            break;
        }

        return $fil;
    }

    public function tourDeRelance(string $texte): array
    {
        return ['role' => 'user', 'content' => $texte];
    }

    /**
     * UN SEUL RÉESSAI, et seulement quand il a des chances d'aboutir.
     *
     * Deux familles d'échec se rattrapent pour le prix d'un aller-retour : le 429 de
     * débit quand le fournisseur annonce lui-même un délai court, et la surcharge
     * (5xx, 529 « overloaded_error ») où aucun délai n'est annoncé parce qu'il n'y en
     * a pas à annoncer — l'épisode est bref.
     *
     * DEUX CAS QU'ON NE RÉESSAIE JAMAIS, et c'est le cœur de la méthode :
     *   - le PLAFOND DE DÉPENSE mensuel, un 429 qu'aucune attente ne rouvre.
     *     Réessayer, c'est perdre un aller-retour pour reproduire à l'identique un
     *     refus déjà certain ;
     *   - un délai annoncé trop long : mieux vaut une réponse honnête, avec la bonne
     *     durée, qu'un silence de trente secondes suivi du même refus.
     */
    public function appeler(AiRequest $request, array $fil, Trousse $trousse, Phase $phase): array
    {
        try {
            return $this->call($request, $fil, $trousse, $phase);
        } catch (\Throwable $e) {
            $attente = $this->attenteUtileApres($e);
            if ($attente === null) {
                // PLUS RIEN À TENTER. Deux cas, deux durées, toutes deux annoncées par
                // le fournisseur : le plafond de dépense mensuel donne sa date de
                // réouverture en toutes lettres, et un 429 de débit trop long donne son
                // « retry-after ». Toute autre panne — 400, 401, réseau — ne marque
                // RIEN : l'écarter une journée pour un schéma malformé transformerait
                // un défaut passager en panne longue que personne ne relierait.
                $this->marquerSiASec($e);

                throw $e;
            }

            $this->logger->warning('Assistant IA (anthropic) : refus du fournisseur, une reprise après attente.', [
                'attente' => $attente,
                'details' => AiEngineFailure::detailsPourJournal($e),
            ]);
            ($this->dormir ?? static fn (int $s) => sleep($s))($attente);

            return $this->call($request, $fil, $trousse, $phase);
        }
    }

    /**
     * Combien de secondes attendre avant de rejouer — ou null s'il ne faut pas
     * rejouer du tout. Toute la politique de reprise tient ici.
     */
    private function attenteUtileApres(\Throwable $e): ?int
    {
        // Le plafond de dépense mensuel se présente en 429 mais n'est pas une
        // saturation : il se teste EN PREMIER, sans quoi il tomberait dans la
        // branche du débit et on attendrait pour rien.
        if (AiEngineFailure::estPlafondDeDepense($e)) {
            return null;
        }

        if (AiEngineFailure::estMoteurIndisponible($e)) {
            return $this->attenteSurchargeSecondes;
        }

        if (!AiEngineFailure::estLimiteDeDebit($e)) {
            return null; // 400, 401, panne réseau : rejouer reproduirait la même erreur.
        }

        $delai = AiEngineFailure::secondesAvantNouvelEssai($e);

        return $delai !== null && $delai <= $this->maxAttenteSecondes ? $delai : null;
    }

    /**
     * Appel HTTP Messages API (synchrone, sans streaming).
     *
     * Rend aussi la taille des trois blocs du payload : c'est la seule façon de
     * savoir OÙ partent les tokens sans payer un aller-retour count_tokens, le
     * fournisseur ne renvoyant qu'un total.
     *
     * @return array{reponse: array<string, mixed>, octets: array<string, int>, usage: Usage}
     */
    private function call(AiRequest $request, array $fil, Trousse $trousse, Phase $phase): array
    {
        ['stable' => $stable, 'volatil' => $volatil] = ($this->promptSysteme)($request, $trousse, $phase);
        // LA PIÈCE MAÎTRESSE DE L'ÉCONOMIE : en rédaction, aucun outil n'est déclaré.
        // Les 72 Ko de déclarations ne servent qu'à CHOISIR un outil ; commenter un
        // résultat déjà obtenu n'en a aucun besoin.
        $declarations = $phase->declareDesOutils() ? $this->declarations($trousse, $request) : [];

        $charge = [
            'model'      => $this->model,
            'max_tokens' => $this->maxOutputTokens,
            'system'     => $this->blocsSysteme($stable, $volatil),
            'messages'   => $fil,
        ] + ($declarations === [] ? [] : ['tools' => $declarations]);

        $response = $this->httpClient->request('POST', self::API_URL, [
            'headers' => [
                'x-api-key'         => $this->apiKey,
                'anthropic-version' => self::API_VERSION,
                'content-type'      => 'application/json',
            ],
            // Texte non UTF-8 (fichier joint, troncature) : réparé, sinon le JSON ne part pas.
            'json'    => AiText::utf8Profond($charge),
            'timeout' => 90,
        ]);

        $reponse = $response->toArray(); // lève une exception explicite sur 4xx/5xx
        $this->lireLeSoldeDeclare($response->getHeaders(false));

        return [
            'reponse' => $reponse,
            'octets'  => [
                'systeme'    => \strlen($stable) + \strlen($volatil),
                'outils'     => \strlen((string) json_encode($declarations, JSON_UNESCAPED_UNICODE)),
                'historique' => \strlen((string) json_encode($fil, JSON_UNESCAPED_UNICODE)),
            ],
            'usage'   => Usage::depuisAnthropic($reponse),
        ];
    }

    /**
     * LE SECOND POINT DE RUPTURE : la fin de la partie stable du prompt système.
     *
     * L'ordre de rendu est tools → system → messages. Le premier point de rupture
     * couvre les déclarations d'outils (~20 600 tokens) ; celui-ci étend le préfixe
     * caché à l'invariant du prompt — identité, aiguillage, glossaire, règles,
     * protocoles d'écriture, catalogue des fiches, ~15 400 tokens de plus. Ensemble,
     * ils font passer un appel de planification de 36 000 tokens facturés plein
     * tarif à ~2 400.
     *
     * DEUX CAS SANS RUPTURE, et ils sont voulus : le cache désactivé
     * (ANTHROPIC_CACHE=0, pour comparer les deux régimes sur du trafic réel), et une
     * partie stable vide — c'est le cas des phases de rédaction et de compréhension,
     * dont le prompt est court et entièrement lié au message en cours. Marquer un
     * préfixe qu'on ne relira jamais coûterait 1,25× sans jamais rien rapporter.
     *
     * @return string|list<array<string, mixed>>
     */
    private function blocsSysteme(string $stable, string $volatil): string|array
    {
        if (!$this->cacheActif || $stable === '') {
            return $stable . $volatil;
        }

        return [
            ['type' => 'text', 'text' => $stable, 'cache_control' => ['type' => 'ephemeral']],
            ['type' => 'text', 'text' => $volatil],
        ];
    }

    /**
     * S'ANNONCER À SEC APRÈS UN REFUS — la source CONSTATÉE.
     *
     * La durée n'est jamais devinée : elle vient du fournisseur. Le plafond de
     * dépense écrit sa date de réouverture dans son message (« You will regain
     * access on … ») ; une saturation de débit donne son « retry-after ».
     */
    private function marquerSiASec(\Throwable $e): void
    {
        if ($this->epuisement === null || $this->cleDEpuisement === '') {
            return;
        }

        if (AiEngineFailure::estPlafondDeDepense($e)) {
            $date = AiEngineFailure::dateDeReouverture($e);
            $secondes = $date !== null
                ? MemoireDEpuisement::jusqua(new \DateTimeImmutable($date . ' 00:00:00', new \DateTimeZone('UTC')))
                : MemoireDEpuisement::jusquAuMoisProchain();
            $this->epuisement->marquer($this->cleDEpuisement, $secondes);
            $this->logger->notice('Assistant IA (anthropic) : plafond de dépense atteint, moteur écarté de la chaîne.', [
                'reouverture' => $date,
            ]);

            return;
        }

        if (AiEngineFailure::estLimiteDeDebit($e)) {
            $this->epuisement->marquer($this->cleDEpuisement, AiEngineFailure::secondesAvantNouvelEssai($e) ?? 60);
        }
    }

    /**
     * LE SOLDE QUE LE FOURNISSEUR PUBLIE — la source DÉCLARÉE, et la plus utile.
     *
     * Anthropic annonce ce qu'il lui reste sur CHAQUE réponse, y compris celles qui
     * réussissent. On peut donc l'écarter AVANT son premier refus : zéro tour perdu
     * au lieu d'un. Google, lui, ne dit rien tant qu'il n'a pas refusé — pour lui,
     * seul le constat existe.
     *
     * La marge est celle du compteur de débit (15 %), pour ne pas raisonner avec
     * deux prudences différentes sur la même question.
     *
     * @param array<string, list<string>> $entetes
     */
    private function lireLeSoldeDeclare(array $entetes): void
    {
        if ($this->epuisement === null || $this->cleDEpuisement === '') {
            return;
        }

        $restant = $entetes['anthropic-ratelimit-input-tokens-remaining'][0] ?? null;
        $plafond = $entetes['anthropic-ratelimit-input-tokens-limit'][0] ?? null;
        $reset = $entetes['anthropic-ratelimit-input-tokens-reset'][0] ?? null;
        if ($restant === null || $plafond === null || (int) $plafond <= 0) {
            return;
        }

        if ((int) $restant > (int) $plafond * BudgetDebit::MARGE) {
            return;
        }

        $secondes = 60;
        if (\is_string($reset)) {
            try {
                $secondes = MemoireDEpuisement::jusqua(new \DateTimeImmutable($reset));
            } catch (\Throwable) {
                // En-tête illisible : une minute suffit, la fenêtre est glissante.
            }
        }

        $this->epuisement->marquer($this->cleDEpuisement, $secondes);
        $this->logger->notice('Assistant IA (anthropic) : solde annoncé sous la marge, moteur écarté avant son premier refus.', [
            'restant' => $restant,
            'plafond' => $plafond,
            'reset'   => $reset,
        ]);
    }

    /**
     * Déclarations d'outils au format Messages API, avec le point de rupture du cache.
     *
     * @return list<array<string, mixed>>
     */
    private function declarations(Trousse $trousse, AiRequest $request): array
    {
        $definitions = [];
        // Même source que le prompt système (TrousseCatalogue) : c'est ce qui interdit
        // qu'une consigne nomme un outil non déclaré.
        foreach ($this->trousseCatalogue->outilsDe($trousse, $request->scope) as $tool) {
            $definitions[] = [
                'name'         => $tool->name(),
                'description'  => $tool->description(),
                'input_schema' => $tool->schema(),
            ];
        }

        return $this->posterLePointDeRupture($definitions);
    }

    /**
     * LE POINT DE RUPTURE DU CACHE, posé sur la DERNIÈRE déclaration d'outil.
     *
     * CE QUE ÇA RAPPORTE. Les déclarations pèsent ~72 Ko, soit ~20 600 tokens : plus
     * de la moitié de ce qu'un appel de planification transporte. L'API est sans
     * mémoire, donc sans cache on les repaie plein tarif à CHAQUE appel. Marquées
     * ici, elles se relisent à un dixième du prix — et surtout elles sortent du
     * décompte du plafond par minute, qui ne compte pas les tokens lus en cache.
     *
     * POURQUOI LA DERNIÈRE, ET UNE SEULE. Le cache est un PRÉFIXE : la marque dit
     * « tout ce qui précède est stable ». L'ordre de rendu étant tools → system →
     * messages, une marque sur la dernière déclaration couvre exactement le bloc des
     * outils, et rien d'autre. En poser plusieurs ne cacherait pas davantage, cela
     * multiplierait seulement les points à invalider.
     *
     * CE QUE ÇA COÛTE, ET POURQUOI UN INTERRUPTEUR. L'écriture vaut 1,25× le plein
     * tarif, pour une durée de vie de 5 minutes. Un message ISOLÉ coûte donc 25 % de
     * plus que sans cache ; c'est au DEUXIÈME message de la conversation que
     * l'opération devient gagnante (1,25 + 0,10 contre 2,00). C'est le cas courant,
     * mais c'est une mesure à faire et non une évidence : ANTHROPIC_CACHE permet de
     * comparer les deux régimes sur du trafic réel.
     *
     * CE QUI LE CASSERAIT EN SILENCE. Un seul octet qui change dans ce bloc et plus
     * rien ne se cache — sans erreur, sans log, avec pour tout symptôme une facture
     * qui double. C'est ce que verrouille le test
     * « testDeuxMessagesEnvoientDesDeclarationsOctetAOctetIdentiques ».
     *
     * @param list<array<string, mixed>> $definitions
     *
     * @return list<array<string, mixed>>
     */
    private function posterLePointDeRupture(array $definitions): array
    {
        if (!$this->cacheActif || $definitions === []) {
            return $definitions;
        }

        $definitions[array_key_last($definitions)]['cache_control'] = ['type' => 'ephemeral'];

        return $definitions;
    }

    public function appelsDOutils(array $reponse): array
    {
        $appels = [];
        foreach (($reponse['content'] ?? []) as $bloc) {
            if (($bloc['type'] ?? null) !== 'tool_use') {
                continue;
            }
            $appels[] = [
                'nom'  => (string) ($bloc['name'] ?? ''),
                'args' => (array) ($bloc['input'] ?? []),
                // Anthropic apparie les résultats par IDENTIFIANT : sans lui, le tour
                // suivant est rejeté.
                'id'   => isset($bloc['id']) ? (string) $bloc['id'] : null,
            ];
        }

        return $appels;
    }

    public function toursDeResultats(array $reponse, array $resultats, bool $rattrapage): array
    {
        // Un appel RATTRAPÉ n'existe pas dans le tour du modèle : lui renvoyer un
        // « tool_result » sans « tool_use » correspondant ferait rejeter toute la
        // requête (400). Le résultat repart donc en texte — même contenu, canal que
        // la conversation accepte.
        if ($rattrapage) {
            $lignes = [];
            foreach ($resultats as $resultat) {
                $lignes[] = sprintf(
                    'Résultat de %s : %s',
                    $resultat['appel']['nom'],
                    (string) json_encode(['status' => $resultat['statut']] + $resultat['data'], JSON_UNESCAPED_UNICODE),
                );
            }

            return [['role' => 'user', 'content' => implode("\n\n", $lignes)]];
        }

        $blocs = [];
        foreach ($resultats as $resultat) {
            $blocs[] = [
                'type'        => 'tool_result',
                'tool_use_id' => (string) $resultat['appel']['id'],
                'content'     => json_encode(
                    ['status' => $resultat['statut']] + $resultat['data'],
                    JSON_UNESCAPED_UNICODE,
                ),
            ];
        }

        return [
            ['role' => 'assistant', 'content' => self::preserverInputsObjets($reponse['content'] ?? [])],
            ['role' => 'user', 'content' => $blocs],
        ];
    }

    /**
     * PHP décode « input: {} » (objet JSON vide) en TABLEAU vide ; ré-encodé
     * tel quel dans l'écho du tour assistant, il redeviendrait [] (une liste),
     * rejetée par l'API (input d'un tool_use = objet). On restitue l'objet
     * vide — cas de tout outil SANS paramètre (solde_tokens, quitter_workspace).
     *
     * La cause est PHP, pas le proto : le dialecte Gemini a exactement le même
     * garde-fou, sous le nom preserverArgsObjets().
     */
    private static function preserverInputsObjets(array $blocs): array
    {
        foreach ($blocs as $i => $bloc) {
            if (($bloc['type'] ?? null) === 'tool_use' && ($bloc['input'] ?? null) === []) {
                $blocs[$i]['input'] = new \stdClass();
            }
        }

        return $blocs;
    }

    /**
     * Sans objet chez Anthropic : il n'existe aucun équivalent du
     * MALFORMED_FUNCTION_CALL de Google, qui est un défaut du sérialiseur d'appels
     * de CE fournisseur-là. Rendre false plutôt que d'écrire un faux détecteur —
     * du code mort qui laisserait croire à une couverture qu'on n'a pas.
     */
    public function estAppelMalforme(array $reponse): bool
    {
        return false;
    }

    /**
     * Le tour n'a-t-il RIEN produit — ni appel d'outil, ni le moindre mot ?
     *
     * À ne pas confondre avec un refus : une demande déclinée par les garde-fous
     * d'Anthropic a son propre chemin, et la rejouer ne ferait que la faire décliner
     * une seconde fois. Ici, le modèle avait le droit de parler et n'a pas parlé.
     */
    public function estTourVide(array $reponse): bool
    {
        if ($this->estBloquee($reponse)) {
            return false;
        }

        foreach (($reponse['content'] ?? []) as $bloc) {
            if (($bloc['type'] ?? null) === 'tool_use') {
                return false;
            }
            if (($bloc['type'] ?? null) === 'text' && trim((string) ($bloc['text'] ?? '')) !== '') {
                return false;
            }
        }

        return true;
    }

    /** Garde de sécurité Anthropic : la requête a été déclinée. */
    public function estBloquee(array $reponse): bool
    {
        return ($reponse['stop_reason'] ?? null) === 'refusal';
    }

    public function texte(array $reponse, ?string $repli = null): string
    {
        $textes = [];
        foreach (($reponse['content'] ?? []) as $bloc) {
            if (($bloc['type'] ?? null) === 'text' && trim((string) ($bloc['text'] ?? '')) !== '') {
                $textes[] = trim((string) $bloc['text']);
            }
        }

        if ($textes !== []) {
            return implode("\n\n", $textes);
        }

        return $repli ?? "Je n'ai pas de réponse à formuler sur ce point. Pouvez-vous préciser votre question ?";
    }
}
