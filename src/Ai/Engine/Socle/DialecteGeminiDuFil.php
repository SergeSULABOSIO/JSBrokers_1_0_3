<?php

namespace App\Ai\Engine\Socle;

use App\Ai\AiEngineFailure;
use App\Ai\AiRequest;
use App\Ai\AiText;
use App\Ai\Engine\DialecteGemini;
use App\Ai\Engine\Usage;
use App\Ai\Fournisseur\MemoireDEpuisement;
use App\Ai\Telemetrie\JournalTokens;
use App\Ai\Trousse\Phase;
use App\Ai\Trousse\Trousse;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * LE FIL AU FORMAT GOOGLE, et le transport qui va avec.
 *
 * Ce qui est ici et nulle part ailleurs : les rôles user/model (jamais
 * « assistant »), le prompt système dans systemInstruction, les outils en
 * functionDeclarations, la clé en en-tête x-goog-api-key (jamais dans l'URL) —
 * et les deux remèdes propres à ce fournisseur : la bascule sur un modèle de
 * secours quand le sien est débordé, et la lecture de usageMetadata.
 *
 * ⚠ CET OBJET PORTE DE L'ÉTAT, à dessein : le modèle réellement interrogé change
 * en cours de route et n'est pas remis à zéro. Voir $modeleCourant.
 *
 * Le découpage des schémas, lui, reste dans DialecteGemini : il décrit ce que le
 * PROTO accepte, là où cette classe décrit la forme du FIL. Les deux sont utilisés
 * aussi par la phase de compréhension, qui parle à Google en direct.
 */
final class DialecteGeminiDuFil implements DialecteDuFil
{
    /**
     * Durée TOTALE accordée à un appel au modèle, réessais non compris.
     *
     * Généreuse à dessein : la sortie est plafonnée à quelques milliers de jetons
     * et une génération normale tient en quelques secondes — les mesures de
     * production donnent 3,0 et 3,8 secondes. Quarante-cinq secondes laissent donc
     * toute la marge nécessaire, tout en divisant par deux le pire cas observé.
     */
    private const DUREE_MAX_SECONDES = 45;

    private const API_BASE = 'https://generativelanguage.googleapis.com/v1beta/models';

    /**
     * LE MODÈLE RÉELLEMENT INTERROGÉ, qui n'est pas toujours celui de la configuration.
     *
     * Un 503 le fait basculer sur un modèle de secours, et il y RESTE pour le reste du
     * message : revenir au principal à chaque tour ferait repayer un échec certain à
     * chaque appel d'outil, sur un message qui en compte souvent cinq ou six.
     */
    private string $modeleCourant;

    /**
     * LA RÉDACTION PEUT AVOIR SON PROPRE MODÈLE, et c'est le meilleur rapport
     * qualité/coût du chantier.
     *
     * Mesuré sur la campagne au 2026-09-25 : la planification pèse 83,9 % des jetons
     * d'entrée, la rédaction 9,8 %. Or c'est la rédaction, et elle seule, qui écrit
     * le texte que l'utilisateur lit. Payer un modèle plus capable sur un dixième du
     * volume pour améliorer la totalité de ce qui est lu est un marché qu'on ne
     * refuse pas.
     *
     * ET LE QUOTA SUIT. Google tient sa fenêtre PAR MODÈLE : les trois phases
     * partageaient jusqu'ici un seul compteur de 250 000 jetons/minute — trois
     * messages suffisaient à le saturer. Chaque phase déplacée arrive avec sa propre
     * fenêtre. C'est la même raison qui justifie un modèle distinct pour la
     * compréhension, et le .env le dit depuis l'origine.
     *
     * Vide = aucun modèle dédié, et tout se passe exactement comme avant.
     */
    private bool $redactionAbandonnee = false;

    /** @var string[] modèles de secours pas encore essayés, dans l'ordre */
    private array $replisRestants;

    /** Le modèle que le RÉGLAGE demandait la dernière fois qu'on a regardé. */
    private string $modeleRegle;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly \Closure $promptSysteme,
        private readonly DialecteGemini $dialecte,
        private readonly string $apiKey,
        // DEUX RÉSOLVEURS, PAS DEUX CHAÎNES : la console peut changer le modèle
        // principal et la chaîne de secours entre deux messages. Ils sont donc
        // relus, pas figés — voir `accorderAuReglage()`.
        private readonly \Closure $modeleConfigure,
        private readonly \Closure $replisConfigures,
        private readonly LoggerInterface $logger,
        private readonly int $maxOutputTokens,
        private readonly int $maxAttenteSecondes,
        private readonly ?\Closure $dormir = null,
        private readonly ?MemoireDEpuisement $epuisement = null,
        private readonly ?\Closure $cleDEpuisement = null,
        // LE JOURNAL DE CAMPAGNE, en dernier et facultatif : sans lui ce dialecte se
        // comporte exactement comme avant, et les harnais qui le construisent à la main
        // n'ont rien à changer. Il ne sert qu'à COMPTER les bascules de secours.
        private readonly ?JournalTokens $journal = null,
        /**
         * LE MODÈLE DÉDIÉ À LA RÉDACTION, relu à chaque appel. Rend '' quand il n'y
         * en a pas, et tout se passe alors exactement comme avant.
         *
         * ⚠ EN DERNIER ET FACULTATIF, comme les quatre paramètres ci-dessus. Posé au
         * milieu, il s'était interverti avec la chaîne de secours — qui recevait la
         * fermeture de la rédaction, donc une liste vide : les replis disparaissaient
         * en silence, et trois tests l'ont dit aussitôt (2026-09-25).
         */
        private readonly ?\Closure $modeleDeRedaction = null,
    ) {
        $this->accorderAuReglage();
    }

    public function nom(): string
    {
        return 'gemini';
    }

    public function modeleCourant(): string
    {
        return $this->modeleCourant;
    }

    /**
     * REMETTRE L'ÉTAT D'ACCORD AVEC LE RÉGLAGE EN VIGUEUR.
     *
     * Deux exigences qui se contredisent, et c'est tout l'objet de cette méthode.
     *
     * D'UN CÔTÉ, la bascule de secours ne doit PAS être remise à zéro entre deux
     * messages : un modèle qui vient de rendre un 503 le rendra encore, et y
     * revenir à chaque message ferait repayer un échec certain.
     *
     * DE L'AUTRE, un agent qui change le modèle depuis la console doit voir son
     * choix appliqué AU MESSAGE SUIVANT — y compris, et surtout, quand le modèle
     * courant est justement celui qui posait problème.
     *
     * On ne remet donc l'état à zéro que lorsque le RÉGLAGE a changé, jamais
     * autrement. Appelée à chaque début de message, par `filInitial()`.
     */
    private function accorderAuReglage(): void
    {
        $configure = ($this->modeleConfigure)();
        if (isset($this->modeleRegle) && $this->modeleRegle === $configure) {
            return;
        }

        $this->modeleRegle = $configure;
        $this->modeleCourant = $configure;
        // Nouveau réglage, nouvelle chance pour le modèle dédié : on ne traîne pas
        // d'un message à l'autre l'abandon décidé au précédent.
        $this->redactionAbandonnee = false;
        $this->replisRestants = array_values(array_filter(
            array_map('trim', explode(',', (string) ($this->replisConfigures)())),
            static fn (string $m): bool => $m !== '' && $m !== $configure,
        ));
    }

    /** La marque d'épuisement du modèle configuré — vide quand rien ne la nomme. */
    private function cle(): string
    {
        return $this->cleDEpuisement === null ? '' : ($this->cleDEpuisement)();
    }

    /**
     * LE MODÈLE DE CETTE PHASE-CI — celui du moteur, sauf pour la rédaction quand on
     * lui en a donné un.
     *
     * ⚠ UN MODÈLE DÉDIÉ QUI TOMBE EST ABANDONNÉ POUR LE MESSAGE. Sans cette mémoire,
     * la bascule de secours le rappellerait à chaque essai : elle change
     * `modeleCourant`, mais cette méthode-ci rendrait toujours le même modèle dédié,
     * et la boucle tournerait sur l'échec certain.
     */
    private function modelePour(Phase $phase): string
    {
        if ($phase !== Phase::REDACTION || $this->redactionAbandonnee) {
            return $this->modeleCourant;
        }
        $dedie = $this->modeleDeRedaction === null ? '' : trim((string) ($this->modeleDeRedaction)());

        return $dedie === '' ? $this->modeleCourant : $dedie;
    }

    /**
     * Le compteur de débit de Google est tenu PAR MODÈLE : la clé est le nom du
     * modèle, tel quel. C'est aussi ce qui rend la bascule de secours utile — un
     * autre modèle arrive avec sa propre fenêtre.
     *
     * La phase compte, depuis qu'une d'elles peut avoir son propre modèle : sans
     * elle, la rédaction ferait fermer la fenêtre de la planification, et la sienne
     * s'épuiserait sans qu'on la voie venir.
     */
    public function cleDeDebit(?Phase $phase = null): string
    {
        return $phase === null ? $this->modeleCourant : $this->modelePour($phase);
    }

    public function filInitial(AiRequest $request): array
    {
        // DÉBUT DE MESSAGE : c'est ici, et nulle part ailleurs, qu'on regarde si le
        // réglage a changé depuis la dernière fois. `filInitial()` est appelée une
        // fois par message par l'orchestrateur — jamais entre deux tours d'outils,
        // ce qui laisse la bascule de secours tranquille pendant un message.
        $this->accorderAuReglage();

        // Historique : notre rôle « assistant » devient « model » chez Gemini.
        return array_map(
            static fn (array $m) => [
                'role'  => $m['role'] === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => (string) $m['content']]],
            ],
            $request->messages,
        );
    }

    public function joindrePieces(array $fil, array $pieces): array
    {
        if ($pieces === []) {
            return $fil;
        }

        for ($i = count($fil) - 1; $i >= 0; $i--) {
            if (($fil[$i]['role'] ?? null) !== 'user') {
                continue;
            }
            foreach ($pieces as $piece) {
                $fil[$i]['parts'][] = ['inlineData' => [
                    'mimeType' => $piece['mimeType'],
                    'data'     => $piece['donneesBase64'],
                ]];
            }
            break;
        }

        return $fil;
    }

    public function tourDeRelance(string $texte): array
    {
        return ['role' => 'user', 'parts' => [['text' => $texte]]];
    }

    /**
     * Appel HTTP, avec UN réessai si le fournisseur répond 429 en annonçant un
     * délai court.
     *
     * Notre compteur local (BudgetDebit) peut sous-estimer : le quota est
     * partagé entre processus, et la séquence lire-modifier-écrire n'est pas
     * atomique. Quand le mur est touché malgré tout, abandonner ferait perdre
     * TOUS les tours déjà payés du message. Le fournisseur, lui, dit exactement
     * combien de temps attendre (google.rpc.RetryInfo) : autant s'en servir.
     *
     * Un seul réessai, et seulement si le délai tient dans le plafond d'attente —
     * au-delà, l'exception remonte à l'orchestrateur, qui sait déjà l'expliquer
     * (AiEngineFailure) et journaliser le quota violé.
     */
    public function appeler(AiRequest $request, array $fil, Trousse $trousse, Phase $phase): array
    {
        try {
            return $this->call($request, $fil, $trousse, $phase);
        } catch (\Throwable $e) {
            // LE MODÈLE DÉDIÉ À LA RÉDACTION TOMBE : on le lâche et on rend la main au
            // modèle du moteur, qui vient de faire tout le travail de planification et
            // qui répond donc certainement. Un seul essai, et seulement ici : le
            // rattrapage général ci-dessous s'applique ensuite comme avant.
            if (!$this->redactionAbandonnee && $this->modelePour($phase) !== $this->modeleCourant) {
                $this->redactionAbandonnee = true;
                $this->logger->warning('Assistant IA (gemini) : le modèle dédié à la rédaction n\'a pas répondu, retour au modèle du moteur.', [
                    'abandonne' => $this->modeleDeRedaction === null ? '' : ($this->modeleDeRedaction)(),
                    'pris'      => $this->modeleCourant,
                    'details'   => AiEngineFailure::detailsPourJournal($e),
                ]);

                return $this->appeler($request, $fil, $trousse, $phase);
            }
            // ⚠ LE 503 NE SE SOIGNE PAS EN ATTENDANT. Il dit que le modèle est débordé
            // chez Google, pas que nous avons trop consommé : le fournisseur n'annonce
            // aucun délai, et l'attente n'a rien de prévisible. On change de modèle.
            if (AiEngineFailure::estMoteurIndisponible($e)) {
                return $this->basculerSurUnRepli($e, $request, $fil, $trousse, $phase);
            }

            $delai = AiEngineFailure::estLimiteDeDebit($e)
                ? AiEngineFailure::secondesAvantNouvelEssai($e)
                : null;

            if ($delai === null || $delai > $this->maxAttenteSecondes) {
                // LE QUOTA SE COMPTE PAR MODÈLE. Un 429 qu'on ne peut pas attendre ne dit
                // rien des modèles de secours, qui ont leur propre fenêtre : les essayer
                // coûte un aller-retour et sauve le message. C'est ce que fait déjà la
                // VOIX de Ket avec sa chaîne de modèles ; il n'y avait aucune raison que
                // le texte abandonne là où la voix continue.
                if (AiEngineFailure::estLimiteDeDebit($e) && $this->replisRestants !== []) {
                    return $this->basculerSurUnRepli($e, $request, $fil, $trousse, $phase);
                }

                // PLUS RIEN À TENTER : ce fournisseur s'annonce à sec, et la chaîne
                // l'écartera au tour suivant sans lui reparler. Le délai vient de lui
                // — « retryDelay » quand il le donne, sinon la remise à zéro du quota
                // journalier de Google, à minuit heure du Pacifique.
                //
                // ⚠ SEULEMENT SUR UN REFUS DE QUOTA. Cette branche attrape AUSSI les
                // 400, les 401 et les pannes réseau : y marquer le moteur l'écarterait
                // pour une journée entière à cause d'un schéma d'outil malformé ou
                // d'une coupure de trois secondes. Ce serait transformer un défaut
                // passager en panne longue, et personne ne ferait le lien.
                if (AiEngineFailure::estLimiteDeDebit($e)) {
                    $this->marquerASec($delai);
                }

                throw $e;
            }

            $this->logger->warning('Assistant IA (gemini) : 429 du fournisseur, un réessai après le délai annoncé.', [
                'delai'   => $delai,
                'details' => AiEngineFailure::detailsPourJournal($e),
            ]);
            ($this->dormir ?? static fn (int $s) => sleep($s))($delai);

            return $this->call($request, $fil, $trousse, $phase);
        }
    }

    /**
     * S'annoncer à sec — le geste qui évite de repayer le même refus au tour suivant.
     *
     * ⚠ SEULEMENT QUAND IL N'Y A PLUS RIEN À TENTER. Un 429 qu'on peut attendre, ou
     * qu'un modèle de secours peut absorber, n'épuise pas le fournisseur : le marquer
     * là priverait Ket d'un moteur encore utilisable.
     */
    private function marquerASec(?int $secondes): void
    {
        if ($this->epuisement === null || $this->cle() === '') {
            return;
        }

        $this->epuisement->marquer(
            $this->cle(),
            $secondes ?? MemoireDEpuisement::jusquAMinuitPacifique(),
        );
        $this->logger->notice('Assistant IA (gemini) : moteur marqué à sec, il sera écarté de la chaîne.', [
            'modele'   => $this->modeleCourant,
            'secondes' => $secondes,
        ]);
    }

    /**
     * Rejoue l'appel sur les modèles de secours, l'un après l'autre.
     *
     * ⚠ LE BASCULEMENT EST DÉFINITIF POUR CE MESSAGE. Le modèle retenu le reste pour
     * tous les tours suivants : un message qui appelle six outils repaierait sinon six
     * échecs certains avant chaque succès, et l'utilisateur attendrait six fois le
     * délai réseau pour rien.
     *
     * ⚠ ON NE REVIENT PAS AU MODÈLE PRINCIPAL EN COURS DE ROUTE, et on ne réessaie pas
     * un secours déjà tombé : sans cette mémoire, un fournisseur durablement saturé
     * ferait tourner la boucle en rond à chaque tour.
     *
     * Si tous les secours échouent, c'est l'exception D'ORIGINE qui remonte — celle du
     * modèle principal. C'est elle qui décrit la panne réelle ; celle du dernier
     * secours ne parlerait que d'un modèle que l'utilisateur n'a jamais choisi.
     *
     * @param array<int, array<string, mixed>> $fil
     *
     * @return array{reponse: array, octets: array<string, int>, usage: Usage}
     */
    private function basculerSurUnRepli(
        \Throwable $origine,
        AiRequest $request,
        array $fil,
        Trousse $trousse,
        Phase $phase,
    ): array {
        while ($this->replisRestants !== []) {
            $abandonne = $this->modeleCourant;
            $this->modeleCourant = array_shift($this->replisRestants);

            $this->logger->warning('Assistant IA (gemini) : modèle indisponible (503) ou saturé (429), bascule sur un modèle de secours.', [
                'abandonne' => $abandonne,
                'repli'     => $this->modeleCourant,
                'details'   => AiEngineFailure::detailsPourJournal($origine),
            ]);

            // ⚠ DANS LE CANAL DE CAMPAGNE, pas seulement dans le journal général : c'est
            // le seul que `app:assistant:tokens:rapport` relit. Un message qui change de
            // modèle en route mélange deux tarifs et deux quotas ; le rapport sait le
            // dire, à condition qu'on le lui écrive.
            $this->journal?->repli(
                $request,
                $this->nom(),
                $abandonne,
                $this->modeleCourant,
                AiEngineFailure::estLimiteDeDebit($origine) ? 'debit' : 'indisponible',
                $phase,
            );

            try {
                return $this->call($request, $fil, $trousse, $phase);
            } catch (\Throwable $e) {
                // Le secours est débordé lui aussi : on passe au suivant. Toute autre
                // panne, en revanche, appartient à ce modèle-là et doit remonter telle
                // quelle — un 400 sur un modèle qui refuse notre schéma d'outils n'a
                // rien à voir avec une surcharge, et l'enterrer serait perdre la cause.
                if (!AiEngineFailure::estMoteurIndisponible($e) && !AiEngineFailure::estLimiteDeDebit($e)) {
                    throw $e;
                }
            }
        }

        throw $origine;
    }

    /**
     * Appel HTTP generateContent (synchrone, sans streaming).
     *
     * Rend aussi la taille des trois blocs du payload. C'est la seule façon de
     * savoir OÙ partent les tokens sans payer un aller-retour countTokens : le
     * fournisseur ne renvoie qu'un total. Le rapport convertit ces octets en
     * tokens via le ratio observé.
     *
     * @return array{reponse: array, octets: array<string, int>, usage: Usage}
     */
    private function call(AiRequest $request, array $fil, Trousse $trousse, Phase $phase): array
    {
        $promptSysteme = ($this->promptSysteme)($request, $trousse, $phase);
        // LA PIÈCE MAÎTRESSE DE L'ÉCONOMIE : en rédaction, aucun outil n'est déclaré.
        // Les 72 Ko de déclarations ne servent qu'à CHOISIR un outil ; commenter un
        // résultat déjà obtenu n'en a aucun besoin. Les envoyer quand même, c'était
        // payer le catalogue deux fois par message.
        $declarations = $phase->declareDesOutils() ? $this->dialecte->declarations($trousse, $request->scope) : [];

        $charge = [
            'systemInstruction' => ['parts' => [['text' => $promptSysteme]]],
            'contents'          => $fil,
            'generationConfig'  => ['maxOutputTokens' => $this->maxOutputTokens],
        ] + ($declarations === []
            // Aucun outil à déclarer : on OMET la clé au lieu d'envoyer une
            // liste vide. Un « tools » vide reste une invitation à en chercher,
            // et la phase de rédaction ne doit en trouver aucun.
            ? []
            : ['tools' => [['functionDeclarations' => $declarations]]]);

        // Un seul octet invalide rend TOUT le JSON inencodable, et Ket ne répond plus
        // (incident du 2026-09-16). On répare, et on dit d'où venait le texte.
        $repares = [];
        $charge = AiText::utf8Profond($charge, $repares);
        if ($repares !== []) {
            $this->logger->warning('Assistant IA : texte non UTF-8 réparé avant l\'envoi au modèle.', ['chemins' => $repares]);
        }

        $response = $this->httpClient->request('POST', sprintf('%s/%s:generateContent', self::API_BASE, $this->modelePour($phase)), [
            'headers' => [
                'x-goog-api-key' => $this->apiKey,
                'content-type'   => 'application/json',
            ],
            'json'    => $charge,
            'timeout' => 90,
            // ⚠ LA DURÉE TOTALE, ET PAS SEULEMENT LE SILENCE DU RÉSEAU.
            //
            // Le `timeout` ci-dessus ne compte que les INACTIVITÉS : un flux qui
            // trickle indéfiniment ne l'atteint jamais. La leçon avait déjà été
            // payée le 2026-09-17 sur la phase de compréhension, qui porte depuis
            // un `max_duration` (cf. AppelGemini) — mais elle n'avait jamais été
            // appliquée ICI, là où se joue la vraie réponse.
            //
            // Relevé en production le 2026-09-24 : « 1 appel · 63 501 jetons IA ·
            // 95,8 s » pour une phrase de politesse, quand deux appels du même
            // volume tenaient en 3,0 et 3,8 secondes. La latence n'était corrélée
            // ni au volume ni au quota : c'était un appel qui traînait.
            //
            // Un dépassement lève une exception de transport, donc emprunte le
            // chemin déjà écrit : réessai unique, puis modèle de secours.
            'max_duration' => self::DUREE_MAX_SECONDES,
        ]);

        $reponse = $response->toArray(); // lève une exception explicite sur 4xx/5xx

        return [
            'reponse' => $reponse,
            'octets'  => [
                'systeme'    => \strlen($promptSysteme),
                'outils'     => \strlen((string) json_encode($declarations, JSON_UNESCAPED_UNICODE)),
                'historique' => \strlen((string) json_encode($fil, JSON_UNESCAPED_UNICODE)),
            ],
            'usage'   => Usage::depuisGemini($reponse),
        ];
    }

    public function appelsDOutils(array $reponse): array
    {
        $appels = [];
        foreach ($this->parts($reponse) as $part) {
            if (!isset($part['functionCall'])) {
                continue;
            }
            $appels[] = [
                'nom'  => (string) $part['functionCall']['name'],
                'args' => (array) ($part['functionCall']['args'] ?? []),
                // Gemini apparie les résultats par NOM, pas par identifiant : il n'y a
                // rien à transporter ici. Anthropic, lui, exige le tool_use_id.
                'id'   => null,
            ];
        }

        return $appels;
    }

    public function toursDeResultats(array $reponse, array $resultats, bool $rattrapage): array
    {
        $responseParts = [];
        foreach ($resultats as $resultat) {
            $responseParts[] = [
                'functionResponse' => [
                    'name'     => $resultat['appel']['nom'],
                    'response' => ['status' => $resultat['statut']] + $resultat['data'],
                ],
            ];
        }

        return [
            ['role' => 'model', 'parts' => DialecteGemini::preserverArgsObjets($this->parts($reponse))],
            // Un appel RATTRAPÉ n'existe pas dans le tour du modèle : lui renvoyer un
            // « functionResponse » sans « functionCall » correspondant ferait rejeter
            // toute la requête par le proto (400). Le résultat repart donc en texte —
            // même contenu, canal que la conversation accepte.
            $rattrapage
                ? ['role' => 'user', 'parts' => [['text' => self::resultatsEnTexte($responseParts)]]]
                : ['role' => 'user', 'parts' => $responseParts],
        ];
    }

    /**
     * Les résultats d'outils rendus en TEXTE, pour le cas d'un appel rattrapé.
     *
     * Le canal normal (functionResponse) exige un functionCall correspondant dans le
     * tour du modèle ; un appel écrit en prose n'en a pas. Le contenu, lui, est le
     * même : le modèle reçoit les mêmes données, seule l'enveloppe change.
     *
     * @param array<int, array{functionResponse: array{name: string, response: array}}> $responseParts
     */
    private static function resultatsEnTexte(array $responseParts): string
    {
        $lignes = [];
        foreach ($responseParts as $part) {
            $reponse = $part['functionResponse'] ?? null;
            if (!is_array($reponse)) {
                continue;
            }
            $lignes[] = sprintf(
                'Résultat de %s : %s',
                (string) ($reponse['name'] ?? '?'),
                (string) json_encode($reponse['response'] ?? [], JSON_UNESCAPED_UNICODE),
            );
        }

        return implode("\n\n", $lignes);
    }

    /**
     * Le fournisseur a-t-il rejeté SON PROPRE appel d'outil ?
     *
     * `MALFORMED_FUNCTION_CALL` signifie que le modèle a voulu appeler un outil et
     * que la structure émise était invalide : rien n'arrive dans le canal des
     * fonctions, et le texte est vide. C'est un défaut de sérialisation, pas de
     * raisonnement — d'où la reprise.
     */
    public function estAppelMalforme(array $reponse): bool
    {
        if (($reponse['candidates'][0]['finishReason'] ?? null) !== 'MALFORMED_FUNCTION_CALL') {
            return false;
        }

        // Ceinture : si malgré tout un appel est arrivé, il n'y a rien à reprendre.
        foreach ($this->parts($reponse) as $part) {
            if (isset($part['functionCall'])) {
                return false;
            }
        }

        return true;
    }

    public function estTourVide(array $reponse): bool
    {
        if ($this->estBloquee($reponse)) {
            return false;
        }

        foreach ($this->parts($reponse) as $part) {
            if (isset($part['functionCall'])) {
                return false;
            }
            if (trim((string) ($part['text'] ?? '')) !== '') {
                return false;
            }
        }

        return true;
    }

    /** Prompt bloqué ou réponse coupée par les filtres de sécurité Gemini ? */
    public function estBloquee(array $reponse): bool
    {
        return isset($reponse['promptFeedback']['blockReason'])
            || ($reponse['candidates'][0]['finishReason'] ?? null) === 'SAFETY';
    }

    public function texte(array $reponse, ?string $repli = null): string
    {
        $textes = [];
        foreach ($this->parts($reponse) as $part) {
            if (isset($part['text']) && trim((string) $part['text']) !== '') {
                $textes[] = trim((string) $part['text']);
            }
        }

        if ($textes !== []) {
            return implode("\n\n", $textes);
        }

        return $repli ?? "Je n'ai pas de réponse à formuler sur ce point. Pouvez-vous préciser votre question ?";
    }

    /** @return array<int, array<string, mixed>> */
    private function parts(array $reponse): array
    {
        return $reponse['candidates'][0]['content']['parts'] ?? [];
    }
}
