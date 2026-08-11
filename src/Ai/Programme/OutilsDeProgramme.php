<?php

namespace App\Ai\Programme;

use App\Ai\Mutation\OutilsDePlan;
use App\Ai\Tool\AiToolInterface;
use App\Ai\Tool\AiToolProduisantUnPlan;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Quels outils une étape de programme peut-elle déclencher, et comment lui
 * fabriquer ses arguments — le tout DÉRIVÉ DU CODE, jamais recopié à la main.
 *
 * Deux contraintes se rejoignent ici :
 *
 *  1. SÉCURITÉ — une étape ne peut appeler qu'un outil PRODUCTEUR DE PLAN
 *     (marqueur AiToolProduisantUnPlan). Un programme ne doit jamais devenir un
 *     moyen détourné de déclencher un outil arbitraire.
 *
 *  2. DIALECTE DES MODÈLES — les arguments d'étape sont des PAIRES PLATES
 *     (clé/valeur en texte), pas un objet libre. Un paramètre complexe optionnel
 *     et non typé est précisément ce que les modèles omettent : c'est ce qui a
 *     déjà produit une écriture « réussie » sans contenu (composition de prime
 *     restée à zéro), et c'est pourquoi ModifierCompositionPrimeTool existe. Les
 *     valeurs sont ensuite RETYPÉES d'après le schéma de l'outil cible — le seul
 *     endroit qui connaisse vraiment le type attendu.
 *
 * Un outil n'est éligible que si TOUS ses paramètres REQUIS sont scalaires — OU
 * s'il sait ASSEMBLER lui-même ses arguments à partir d'une étape décrite à plat
 * (marqueur {@see EtapeAssemblable}). Cette seconde porte a été ouverte le
 * 2026-08-11 : sans elle, `preparer_operations` restait inéligible et une série
 * aussi banale que « créer ce fournisseur, puis enregistrer la dépense » était
 * INEXPRIMABLE — Ket validait le premier plan puis s'arrêtait, faute d'un moyen
 * de déclarer la suite. La structure n'est toujours pas demandée au modèle : c'est
 * l'outil qui la fabrique.
 */
final class OutilsDeProgramme
{
    private const TYPES_SCALAIRES = ['string', 'integer', 'number', 'boolean'];

    /**
     * Outils qu'une étape ne peut PAS déclencher, même s'ils produisent un plan :
     * le lanceur de programme lui-même. Une étape qui lancerait un programme
     * ouvrirait une récursion sans fond, et le verrou « un seul programme en
     * cours » n'y suffirait pas — il vaut mieux fermer la porte ici.
     */
    private const NON_PILOTABLES = ['preparer_programme'];

    /** @var array<string, AiToolInterface>|null */
    private ?array $eligibles = null;

    /** @param iterable<AiToolInterface> $outils */
    public function __construct(
        #[AutowireIterator('app.ai_tool')] private readonly iterable $outils,
        private readonly OutilsDePlan $outilsDePlan,
    ) {
    }

    /**
     * Noms techniques des outils pilotables par une étape, ordre déterministe.
     *
     * @return list<string>
     */
    public function noms(): array
    {
        return array_keys($this->catalogue());
    }

    /** Aide en une ligne par outil, pour la description destinée au modèle. */
    public function aideParametres(): string
    {
        $lignes = [];
        foreach ($this->catalogue() as $nom => $outil) {
            // Un outil assemblable décrit lui-même la forme de son étape : elle n'a
            // rien à voir avec la liste de ses paramètres plats.
            if ($outil instanceof EtapeAssemblable) {
                $lignes[] = $outil->aideEtape();
                continue;
            }
            $params = [];
            foreach ($this->proprietes($outil) as $cle => $definition) {
                if ($cle === 'remplacerPlanEnAttente') {
                    continue; // géré par le programme lui-même.
                }
                if (!$this->estScalaire($definition)) {
                    continue;
                }
                $params[] = $cle . (in_array($cle, $this->requis($outil), true) ? ' (requis)' : '');
            }
            $lignes[] = sprintf('%s : %s', $nom, $params === [] ? 'aucun paramètre plat' : implode(', ', $params));
        }

        return implode(' | ', $lignes);
    }

    public function estEligible(string $nom): bool
    {
        return isset($this->catalogue()[$nom]);
    }

    /**
     * L'instance de l'outil d'une étape, ou null s'il n'est pas pilotable.
     * SOURCE UNIQUE de la résolution : le runner ne doit pas refaire ce filtrage
     * de son côté, sous peine qu'un outil autorisé à la déclaration devienne
     * refusé à l'exécution (ou l'inverse).
     */
    public function outil(string $nom): ?AiToolInterface
    {
        return $this->catalogue()[$nom] ?? null;
    }

    /**
     * Le paramètre REQUIS de type entier de l'outil, quand il n'y en a qu'un :
     * c'est lui que `cibleId` alimente (« la tranche 64 », « l'avenant 12 »).
     * null si l'outil n'en a pas — `cibleId` est alors sans objet.
     */
    public function parametreCible(string $nom): ?string
    {
        $outil = $this->catalogue()[$nom] ?? null;
        if ($outil === null) {
            return null;
        }
        $proprietes = $this->proprietes($outil);
        $candidats = [];
        foreach ($this->requis($outil) as $cle) {
            if (($proprietes[$cle]['type'] ?? null) === 'integer') {
                $candidats[] = $cle;
            }
        }
        if (count($candidats) === 1) {
            return $candidats[0];
        }

        // Aucun requis entier : on accepte l'unique entier OPTIONNEL, quand il est
        // seul (preparer_marquage_non_renouvelable et son avenantId).
        $optionnels = [];
        foreach ($proprietes as $cle => $definition) {
            if (($definition['type'] ?? null) === 'integer') {
                $optionnels[] = $cle;
            }
        }

        return count($optionnels) === 1 ? $optionnels[0] : null;
    }

    /**
     * Assemble les arguments d'une étape : la cible (id) plus les paires
     * fournies, chaque valeur RETYPÉE d'après le schéma de l'outil. Une clé
     * inconnue de l'outil est ignorée (fail-closed : on ne transmet pas de
     * paramètre fantôme), sauf `remplacerPlanEnAttente` qui n'a rien à faire ici.
     *
     * Un outil ASSEMBLABLE fait exception : lui seul connaît la structure de ses
     * arguments, on lui passe donc l'étape entière et il la fabrique.
     *
     * @param array{
     *     cibleId?: int|null,
     *     arguments?: array<int, array{cle?: string, valeur?: mixed}>,
     *     entite?: string, operation?: string, ref?: string|null,
     *     champs?: array<int, array{cle?: string, valeur?: mixed}>
     * } $etape
     *
     * @return array<string, mixed>
     */
    public function arguments(string $nom, array $etape): array
    {
        $outil = $this->catalogue()[$nom] ?? null;
        if ($outil === null) {
            return [];
        }
        if ($outil instanceof EtapeAssemblable) {
            return $outil->argumentsDepuisEtape($etape);
        }

        $cibleId = isset($etape['cibleId']) ? (int) $etape['cibleId'] : null;
        $paires = is_array($etape['arguments'] ?? null) ? $etape['arguments'] : [];
        $proprietes = $this->proprietes($outil);

        $args = [];
        $cible = $this->parametreCible($nom);
        if ($cible !== null && $cibleId !== null && $cibleId > 0) {
            $args[$cible] = $cibleId;
        }

        foreach ($paires as $paire) {
            $cle = trim((string) ($paire['cle'] ?? ''));
            if ($cle === '' || $cle === 'remplacerPlanEnAttente' || !isset($proprietes[$cle])) {
                continue;
            }
            $args[$cle] = $this->retyper($paire['valeur'] ?? null, (string) ($proprietes[$cle]['type'] ?? 'string'));
        }

        return $args;
    }

    /** @return array<string, AiToolInterface> */
    private function catalogue(): array
    {
        if ($this->eligibles !== null) {
            return $this->eligibles;
        }

        $catalogue = [];
        $autorises = $this->outilsDePlan->noms();
        foreach ($this->outils as $outil) {
            if (!$outil instanceof AiToolProduisantUnPlan || !in_array($outil->name(), $autorises, true)) {
                continue;
            }
            if (in_array($outil->name(), self::NON_PILOTABLES, true)) {
                continue;
            }
            $proprietes = $this->proprietes($outil);
            // L'outil sait fabriquer ses propres arguments : la forme de son schéma ne
            // le disqualifie plus.
            $pilotable = $outil instanceof EtapeAssemblable;
            if (!$pilotable) {
                $pilotable = true;
                foreach ($this->requis($outil) as $cle) {
                    if (!$this->estScalaire($proprietes[$cle] ?? [])) {
                        $pilotable = false;
                        break;
                    }
                }
            }
            if ($pilotable) {
                $catalogue[$outil->name()] = $outil;
            }
        }
        ksort($catalogue);

        return $this->eligibles = $catalogue;
    }

    /** @return array<string, array> */
    private function proprietes(AiToolInterface $outil): array
    {
        $schema = $outil->schema();

        return is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
    }

    /** @return list<string> */
    private function requis(AiToolInterface $outil): array
    {
        $schema = $outil->schema();

        return array_values(array_filter(
            is_array($schema['required'] ?? null) ? $schema['required'] : [],
            static fn ($cle) => is_string($cle),
        ));
    }

    private function estScalaire(array $definition): bool
    {
        return in_array($definition['type'] ?? null, self::TYPES_SCALAIRES, true);
    }

    private function retyper(mixed $valeur, string $type): mixed
    {
        return match ($type) {
            'integer' => (int) $valeur,
            'number'  => (float) str_replace([' ', ','], ['', '.'], (string) $valeur),
            'boolean' => filter_var($valeur, FILTER_VALIDATE_BOOL),
            default   => (string) $valeur,
        };
    }
}
