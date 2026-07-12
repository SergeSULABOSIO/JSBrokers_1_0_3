<?php

namespace App\Ai\Engine;

use App\Ai\AiReply;
use App\Ai\AiRequest;
use App\Ai\AiText;
use App\Ai\Tool\AiToolInterface;
use App\Ai\Tool\AiToolResult;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Moteur simulé de l'assistant IA : routage DÉTERMINISTE par mots-clés, sans
 * appel réseau. Assez riche pour prouver tout le circuit (identité du
 * personnage, restitution du périmètre, réponse à une vraie question de
 * données via un outil, refus poli hors périmètre, repli guidé) — c'est aussi
 * le moteur des tests fonctionnels une fois le bridge réel branché.
 */
final class SimulatedAiEngine implements AiEngineInterface
{
    /** @var iterable<AiToolInterface> */
    private iterable $tools;

    public function __construct(
        #[AutowireIterator('app.ai_tool')] iterable $tools,
    ) {
        $this->tools = $tools;
    }

    public function name(): string
    {
        return 'simulated';
    }

    public function reply(AiRequest $request): AiReply
    {
        $question = $request->lastUserMessage();
        $normalized = AiText::normalize($question);
        $nom = $request->systemContext['assistantNom'];
        $entreprise = $request->systemContext['entrepriseNom'];

        // 1) Identité / salutations : l'assistant se présente.
        if (preg_match('/\b(qui es[ -]tu|ton nom|tu t appelles|comment tu t appelles|presente[ -]toi|bonjour|salut|hello|bonsoir)\b/', $normalized)) {
            return new AiReply(sprintf(
                "Bonjour ! Je suis %s, l'assistant IA de %s. Je connais les données de votre espace de travail "
                . "(dans les limites de vos droits d'accès) et je peux par exemple compter vos enregistrements "
                . "(« Combien de clients avons-nous ? »), les lister (« Liste nos clients ») ou donner un "
                . "indicateur calculé (« Quelle est la prime totale du client X ? »). Demandez-moi "
                . "« Que peux-tu faire ? » pour l'inventaire complet de mes capacités. Comment puis-je vous aider ?",
                $nom,
                $entreprise,
            ));
        }

        // 2) Périmètre : restitution lisible des droits de l'invité.
        if (preg_match('/\b(perimetre|droits?|acces|autorisations?|permissions?)\b/', $normalized)) {
            return new AiReply($this->describePerimetre($request));
        }

        // 3) Question de données : premier outil dont le match aboutit.
        foreach ($this->tools as $tool) {
            $args = $tool->match($question, $request->scope);
            if ($args === null) {
                continue;
            }

            $result = $tool->execute($args, $request->scope);

            if ($result->status === AiToolResult::STATUS_HORS_PERIMETRE) {
                return new AiReply($this->refusPoli($result->data['libelle'] ?? 'ces données', $nom), refused: true, toolUsed: $tool->name());
            }
            if ($result->status === AiToolResult::STATUS_INTROUVABLE) {
                return new AiReply(sprintf(
                    "Je n'ai rien trouvé qui corresponde à votre demande%s. Pouvez-vous préciser (nom exact, catégorie) ?",
                    ($result->data['precision'] ?? '') !== '' ? sprintf(' (« %s »)', $result->data['precision']) : '',
                ), toolUsed: $tool->name());
            }

            return new AiReply(
                $this->formatToolReply($tool->name(), $result->data),
                toolUsed: $tool->name(),
                actions: $result->uiAction !== null ? [$result->uiAction] : [],
            );
        }

        // 4) Repli : réponse polie + exemples construits depuis le périmètre réel
        //    (donc jamais hors périmètre).
        return new AiReply($this->repliGuide($request));
    }

    /** Refus poli standardisé : limitation technique liée aux droits, sans détail des données. */
    private function refusPoli(string $libelle, string $nom): string
    {
        return sprintf(
            "Je suis désolé, mais je ne peux pas répondre à cette question : mes connaissances sont "
            . "strictement limitées à votre périmètre d'accès dans cet espace de travail, et les données "
            . "« %s » n'en font pas partie. Si vous pensez en avoir besoin, rapprochez-vous du propriétaire "
            . "de l'espace de travail pour faire élargir vos droits. — %s",
            $libelle,
            $nom,
        );
    }

    /** Phrase de réponse par outil (le futur LLM formulera lui-même à partir des données). */
    private function formatToolReply(string $toolName, array $data): string
    {
        return match ($toolName) {
            'compter_entites' => sprintf(
                'Votre espace de travail compte actuellement %d enregistrement%s dans la rubrique « %s ».',
                $data['count'],
                $data['count'] > 1 ? 's' : '',
                $data['libelle'],
            ),
            'indicateur_calcule' => ($data['ambigu'] ?? false)
                ? $this->formatCandidats($data)
                : sprintf(
                    'Pour « %s », l\'indicateur « %s » vaut actuellement %s %s (valeur calculée en temps réel%s).',
                    $data['cible'],
                    $data['indicateur'],
                    number_format($data['valeur'], 2, ',', ' '),
                    $data['unite'],
                    isset($data['du']) || isset($data['au'])
                        ? sprintf(', période %s → %s', $data['du'] ?? '…', $data['au'] ?? '…')
                        : '',
                ),
            'rechercher_entites' => $this->formatListe($data),
            'lire_fiche' => $this->formatFiche($data),
            'document_comptable' => $this->formatDocumentComptable($data),
            'consulter_guide' => sprintf(
                "D'après la fiche « %s » :\n%s",
                $data['titre'],
                trim((string) $data['contenu']),
            ),
            'ouvrir_dialogue' => sprintf(
                'J\'ouvre le formulaire %s de la rubrique « %s »%s. Vérifiez les informations puis enregistrez.',
                ($data['mode'] ?? 'creation') === 'edition' ? 'd\'édition' : 'de création',
                $data['libelle'],
                isset($data['cible']) ? sprintf(' pour « %s »', $data['cible']) : '',
            ),
            default => trim(implode(' · ', array_map(
                fn ($k, $v) => sprintf('%s : %s', $k, is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE)),
                array_keys($data),
                $data,
            ))),
        };
    }

    /** Liste des candidats sur une cible ambiguë (partagé lire_fiche / indicateur_calcule). */
    private function formatCandidats(array $data): string
    {
        $lignes = array_map(
            static fn (array $c) => sprintf('- %s (id %d)', $c['libelle'], $c['id']),
            $data['candidats'],
        );

        return sprintf(
            "Plusieurs enregistrements de la rubrique « %s » correspondent — lequel voulez-vous ?\n%s",
            $data['libelle'],
            implode("\n", $lignes),
        );
    }

    /** Restitution d'une fiche (lire_fiche) : candidats si ambigu, sinon attributs scalaires. */
    private function formatFiche(array $data): string
    {
        if (($data['ambigu'] ?? false) === true) {
            return $this->formatCandidats($data);
        }

        $lignes = [];
        foreach ($data['fiche'] as $champ => $valeur) {
            if (is_scalar($valeur)) {
                $lignes[] = sprintf('- %s : %s', $champ, is_bool($valeur) ? ($valeur ? 'oui' : 'non') : (string) $valeur);
            }
        }

        return sprintf(
            "Fiche « %s » (rubrique %s) :\n%s",
            $data['nom'],
            $data['libelle'],
            $lignes === [] ? '(aucun attribut renseigné)' : implode("\n", $lignes),
        );
    }

    /**
     * Restitution d'un document comptable : la trésorerie (le cas le plus demandé)
     * est détaillée poste par poste ; les autres états sont restitués en compact
     * (le LLM réel, lui, formule librement à partir des mêmes données).
     */
    private function formatDocumentComptable(array $data): string
    {
        $fmt = static fn (float $m) => number_format($m, 2, ',', ' ');

        if ($data['document'] === 'tresorerie') {
            $t = $data['donnees'];

            return sprintf(
                "Trésorerie de l'exercice %d (montants en %s) :\n"
                . "- Trésorerie d'ouverture : %s\n"
                . "- Encaissements : %s · Décaissements : %s\n"
                . "- Flux d'exploitation : %s · Flux de financement : %s\n"
                . "- Variation : %s\n"
                . 'Le solde actuel (trésorerie de clôture) est de %s %s.',
                $data['exercice'],
                $data['monnaie'],
                $fmt($t['ouverture']),
                $fmt($t['encaissements']),
                $fmt($t['decaissements']),
                $fmt($t['fluxExploitation']),
                $fmt($t['fluxFinancement']),
                $fmt($t['variation']),
                $fmt($t['cloture']),
                $data['monnaie'],
            );
        }

        return sprintf(
            "%s — exercice %d (montants en %s) :\n%s",
            $data['titre'],
            $data['exercice'],
            $data['monnaie'],
            json_encode($data['donnees'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        );
    }

    /** Restitution d'une page de liste (rechercher_entites) : total, items, invite à paginer. */
    private function formatListe(array $data): string
    {
        if (($data['totalItems'] ?? 0) === 0) {
            return sprintf(
                'La rubrique « %s » ne contient aucun enregistrement%s.',
                $data['libelle'],
                isset($data['filtre']) ? sprintf(' correspondant à « %s »', $data['filtre']) : '',
            );
        }

        $lignes = array_map(
            static fn (array $item) => '- ' . $item['libelle'],
            $data['items'] ?? [],
        );

        $texte = sprintf(
            'La rubrique « %s » contient %d enregistrement%s%s (page %d/%d) :%s%s',
            $data['libelle'],
            $data['totalItems'],
            $data['totalItems'] > 1 ? 's' : '',
            isset($data['filtre']) ? sprintf(' correspondant à « %s »', $data['filtre']) : '',
            $data['page'],
            $data['totalPages'],
            "\n",
            implode("\n", $lignes),
        );

        if (($data['totalPages'] ?? 1) > 1 && $data['page'] < $data['totalPages']) {
            $texte .= "\nDemandez la page suivante pour voir la suite.";
        }

        return $texte;
    }

    /** Restitue le périmètre (structure de WorkspaceAccessResolver::describePerimetreDetailed). */
    private function describePerimetre(AiRequest $request): string
    {
        $perimetre = $request->systemContext['perimetre'];

        if (($perimetre['owner'] ?? false) === true) {
            return "Vous êtes propriétaire de cet espace de travail : vous avez un accès complet à toutes "
                . 'les rubriques, et je peux donc répondre à vos questions sur l\'ensemble des données de '
                . "l'entreprise.";
        }

        $modules = $perimetre['modules'] ?? [];
        if (empty($modules)) {
            return "Aucun droit d'accès ne vous a encore été attribué dans cet espace de travail : je ne "
                . "peux donc répondre à aucune question sur les données. Rapprochez-vous du propriétaire "
                . "de l'espace pour obtenir un périmètre.";
        }

        $lignes = [];
        foreach ($modules as $module) {
            $entites = array_map(
                fn (array $e) => sprintf('%s (%s)', $e['nom'], implode(', ', $e['niveaux'])),
                $module['entites'],
            );
            $lignes[] = sprintf('%s : %s', $module['nom'], implode(' · ', $entites));
        }

        return "Voici votre périmètre d'accès actuel — je ne peux répondre qu'aux questions portant sur "
            . "ces rubriques :\n" . implode("\n", $lignes);
    }

    /** Repli : exemples de questions dérivés du périmètre réel de l'invité. */
    private function repliGuide(AiRequest $request): string
    {
        $nom = $request->systemContext['assistantNom'];
        $perimetre = $request->systemContext['perimetre'];

        $exemples = [];
        if (($perimetre['owner'] ?? false) === true) {
            $exemples = [
                'Combien de clients avons-nous ?',
                'Quelle est la prime totale du client X ?',
                'Quel est mon périmètre d\'accès ?',
            ];
        } else {
            foreach (($perimetre['modules'] ?? []) as $module) {
                foreach ($module['entites'] as $entite) {
                    $exemples[] = sprintf('Combien de %s avons-nous ?', mb_strtolower($entite['nom'], 'UTF-8'));
                    if (count($exemples) >= 2) {
                        break 2;
                    }
                }
            }
            $exemples[] = 'Quel est mon périmètre d\'accès ?';
        }

        return sprintf(
            "Je n'ai pas compris votre demande — je suis %s et je réponds aux questions sur les données de "
            . "votre espace de travail. Essayez par exemple :\n- %s",
            $nom,
            implode("\n- ", $exemples),
        );
    }
}
