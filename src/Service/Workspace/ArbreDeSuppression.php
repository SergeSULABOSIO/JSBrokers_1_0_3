<?php

namespace App\Service\Workspace;

use App\Services\Canvas\Provider\Icon\IconCanvasProvider;

/**
 * LE PLAN, REPLIÉ EN ARBRE — pour qu'on puisse le LIRE avant de l'exécuter.
 *
 * {@see PlanDeSuppression} sait compter par classe : « 12 échéances ». C'est ce qu'il faut
 * pour exécuter, et c'est insuffisant pour décider. On ne peut pas demander à quelqu'un
 * d'épargner une pièce d'un dossier si on ne lui montre pas où elle pend.
 *
 * ⚠ CETTE CLASSE NE FAIT AUCUNE REQUÊTE DE GRAPHE. Toute la hiérarchie vient de
 * {@see PlanDeSuppression::$provenance}, que le parcours remplit avec la colonne de jointure
 * qu'il lisait déjà. Un second moteur de graphe divergerait du premier, et l'écran
 * promettrait une portée que l'exécution démentirait. Seuls les NOMS coûtent des requêtes —
 * une par classe, jamais une par ligne.
 */
final class ArbreDeSuppression
{
    /**
     * Au-delà de ce nombre de frères de même classe sous un même parent, on replie.
     *
     * ⚠ ON NE DÉPLIE PAS 247 LIGNES DE FACTURE. Personne ne les arbitre une par une, et les
     * faire défiler noierait les trois nœuds sur lesquels une décision se prend vraiment.
     */
    public const SEUIL_DETAIL = 25;

    /**
     * Plafond de nœuds rendus. Au-delà, on replie du BAS vers le haut : ce sont les feuilles
     * qui se comptent, ce sont les branches qui se décident.
     */
    public const NOEUDS_MAX = 800;

    /**
     * LES ENTITÉS DONT LE NOM NE DONNE PAS L'ALIAS D'ICÔNE.
     *
     * La carte du fournisseur d'icônes est indexée par nom de rubrique en minuscules —
     * « piste », « cotation », « avenant » — ce qui couvre l'immense majorité des nœuds.
     * Ces cinq-là portent un nom technique plus long que leur rubrique, et n'y tomberaient
     * donc jamais juste.
     *
     * @var array<string, string>
     */
    private const ALIAS_PARTICULIERS = [
        'RevenuPourCourtier'    => 'revenu',
        'ChargementPourPrime'   => 'chargement',
        'ReversementRetroAgent' => 'partenaire',
        'DepenseCourtier'       => 'depense',
        'PaiementPrime'         => 'paiement',
        'Article'               => 'note',
    ];

    /** Ce qu'on met devant un nœud dont la rubrique n'a pas d'icône à elle. */
    private const ALIAS_PAR_DEFAUT = 'collection';

    public function __construct(
        private readonly NomsDesNoeuds $noms,
        private readonly CompositionDuGraphe $graphe,
        private readonly IconCanvasProvider $icones,
    ) {
    }

    /**
     * @return array{
     *     racine: array{cle: string, classe: string, libelle: string, nom: string, total: int},
     *     total: int, detaches: int, tronque: bool,
     *     noeuds: array<int, array<string, mixed>>,
     *     conservations: string[], refus: string[]
     * }
     */
    public function depuis(PlanDeSuppression $plan): array
    {
        $racineCle = sprintf('%s#%d', $this->court($plan->racineClasse), (int) $plan->racineId);

        // 1. Les noms — une requête par classe, verrous compris.
        $aNommer = $plan->aDetruire;
        foreach ($plan->verrous as $verrou) {
            $aNommer[$verrou['classe']] = array_values(array_unique(
                array_merge($aNommer[$verrou['classe']] ?? [], $verrou['ids']),
            ));
        }
        $noms = $this->noms->pour($aNommer, $this->libellesDuPlan($plan, $aNommer));

        // 2. Les nœuds, à plat, indexés par clé.
        $parCle = [];
        foreach ($plan->aDetruire as $classe => $ids) {
            foreach ($ids as $id) {
                $cle = sprintf('%s#%d', $this->court($classe), $id);
                $parCle[$cle] = $this->noeud($plan, $classe, (int) $id, $cle, $noms, 'detruire', null);
            }
        }
        foreach ($plan->verrous as $verrou) {
            foreach ($verrou['ids'] as $id) {
                $cle = sprintf('%s#%d', $this->court($verrou['classe']), $id);
                if (isset($parCle[$cle])) {
                    continue; // déjà condamné par un autre chemin : il n'est pas un verrou
                }
                $noeud = $this->noeud($plan, $verrou['classe'], (int) $id, $cle, $noms, 'verrouille', $verrou['motif']);
                // ⚠ UN VERROU N'EST PAS DANS LE PLAN, donc pas dans la provenance : il est
                // rattaché à la racine par défaut, puis replacé sous la ligne qu'il retient
                // (cf. rattacherLesVerrous). Le laisser sans parent le ferait DISPARAÎTRE de
                // l'arbre — et c'est justement le nœud qu'il faut montrer.
                $noeud['parent'] = $racineCle;
                $parCle[$cle] = $noeud;
            }
        }
        $this->rattacherLesVerrous($parCle, $plan);

        // 3. La racine n'a pas de parent, et porte le dossier entier.
        if (isset($parCle[$racineCle])) {
            $parCle[$racineCle]['parent'] = null;
        }

        // 4. Enfants par parent, puis repliage en paquets, puis totaux, puis mise à plat.
        $enfants = $this->enfantsParParent($parCle, $racineCle);
        $tronque = $this->replier($parCle, $enfants, $racineCle);
        $this->totaliser($parCle, $enfants, $racineCle);
        $noeuds = $this->enLargeur($parCle, $enfants, $racineCle);

        return [
            'racine' => [
                'cle'     => $racineCle,
                'classe'  => $this->court($plan->racineClasse),
                'libelle' => $plan->libelle($plan->racineClasse),
                'nom'     => $noms[$plan->racineClasse][(int) $plan->racineId] ?? $racineCle,
                'icone'   => $this->icone($plan->racineClasse),
                'total'   => $parCle[$racineCle]['total'] ?? $plan->nombreDetruits(),
            ],
            'total'         => $plan->nombreDetruits(),
            'detaches'      => $plan->nombreDetaches(),
            'tronque'       => $tronque,
            'noeuds'        => $noeuds,
            'conservations' => $plan->conservations,
            'refus'         => $plan->refus,
            // Les deux dessins du pliage, résolus ici plutôt que devinés à l'écran : c'est
            // le fournisseur d'icônes qui décide à quoi ressemble un dossier ouvert.
            'iconesPliage'  => [
                'ouvert' => (string) $this->icones->resolveIconName('action:deplier'),
                'ferme'  => (string) $this->icones->resolveIconName('action:replier'),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function noeud(PlanDeSuppression $plan, string $classe, int $id, string $cle, array $noms, string $geste, ?string $verrou): array
    {
        return [
            'cle'     => $cle,
            'parent'  => $plan->parentDe($classe, $id),
            'classe'  => $this->court($classe),
            'libelle' => $plan->libelle($classe),
            'nom'     => $noms[$classe][$id] ?? $cle,
            // ⚠ « DÉTACHABLE » N'EST PAS « NULLABLE ». C'est une intention métier : une pièce
            // jointe survit très bien seule, une ligne de facture sans sa facture est un
            // débris. Seules les familles nommées par le graphe portent ce choix.
            'nature'  => $this->graphe->estDetachableAEcran($classe) ? 'detachable' : 'structurel',
            'geste'   => $geste,
            'total'   => 1,
            'verrou'  => $verrou,
            // L'icône de la rubrique, résolue par le MÊME fournisseur que les fiches et les
            // menus : un avenant porte le même dessin ici que partout ailleurs, faute de
            // quoi l'arbre ressemblerait à un écran d'une autre application.
            'icone'   => $this->icone($classe),
        ];
    }

    /** Le nom d'icône de cette rubrique, tel que le circuit d'icônes sait le charger. */
    private function icone(string $classe): string
    {
        $court = $this->court($classe);
        $alias = self::ALIAS_PARTICULIERS[$court] ?? strtolower($court);

        // ⚠ ON VÉRIFIE QUE L'ALIAS EXISTE, on ne le suppose pas. Un alias inconnu rendrait
        // `null`, et le nœud s'afficherait sans repère visuel là où tous ses voisins en ont.
        return $this->icones->resolveIconName($alias)
            ?? $this->icones->resolveIconName(self::ALIAS_PAR_DEFAUT)
            ?? '';
    }

    /** Chaque verrou pend du nœud du plan que son arête désigne. */
    private function rattacherLesVerrous(array &$parCle, PlanDeSuppression $plan): void
    {
        foreach ($plan->verrous as $verrou) {
            foreach ($verrou['parents'] as $idEnfant => $idParent) {
                $cle = sprintf('%s#%d', $this->court($verrou['classe']), $idEnfant);
                if (!isset($parCle[$cle]) || $parCle[$cle]['geste'] !== 'verrouille') {
                    continue;
                }
                // Le parent d'un verrou est la ligne du plan qu'il retient. On la cherche
                // dans les classes du plan : une seule la porte.
                foreach (array_keys($plan->aDetruire) as $classeDuPlan) {
                    $candidate = sprintf('%s#%d', $this->court($classeDuPlan), $idParent);
                    if (isset($parCle[$candidate])) {
                        $parCle[$cle]['parent'] = $candidate;
                        continue 2;
                    }
                }
            }
        }
    }

    /**
     * @return array<string, string[]> clé du parent => clés de ses enfants
     */
    private function enfantsParParent(array $parCle, string $racineCle): array
    {
        $enfants = [];
        foreach ($parCle as $cle => $noeud) {
            if ($cle === $racineCle || $noeud['parent'] === null) {
                continue;
            }
            // ⚠ UN PARENT ABSENT RATTACHE À LA RACINE plutôt que de disparaître. Un nœud
            // orphelin serait ignoré en silence par l'écran, et le volume annoncé serait
            // faux — c'est-à-dire le seul chiffre sur lequel la décision se prend.
            $parent = isset($parCle[$noeud['parent']]) ? $noeud['parent'] : $racineCle;
            $enfants[$parent][] = $cle;
        }

        return $enfants;
    }

    /** Replie les fratries trop nombreuses en paquets. Rend true si le plafond a joué. */
    private function replier(array &$parCle, array &$enfants, string $racineCle): bool
    {
        foreach (array_keys($enfants) as $parent) {
            $parClasse = [];
            foreach ($enfants[$parent] as $cle) {
                $parClasse[$parCle[$cle]['classe']][] = $cle;
            }

            foreach ($parClasse as $classe => $cles) {
                if (count($cles) <= self::SEUIL_DETAIL) {
                    continue;
                }
                // ⚠ ON N'EMPAQUETTE JAMAIS CE QUI SE DÉCIDE UN PAR UN. C'est précisément sur
                // une pièce jointe que l'arbitrage individuel a un sens ; sur 247 lignes de
                // facture, il n'en a aucun.
                if ($parCle[$cles[0]]['nature'] === 'detachable') {
                    continue;
                }
                $this->empaqueter($parCle, $enfants, $parent, $classe, $cles);
            }
        }

        $tronque = false;
        // Le plafond joue en dernier : on replie encore, du bas vers le haut, tant qu'il
        // reste trop de nœuds à montrer.
        for ($garde = 0; $garde < 5 && count($parCle) > self::NOEUDS_MAX; ++$garde) {
            $profonds = $this->parentsLesPlusProfonds($parCle, $enfants, $racineCle);
            if ($profonds === []) {
                break;
            }
            foreach ($profonds as $parent) {
                $parClasse = [];
                foreach ($enfants[$parent] ?? [] as $cle) {
                    $parClasse[$parCle[$cle]['classe']][] = $cle;
                }
                foreach ($parClasse as $classe => $cles) {
                    if (count($cles) > 1) {
                        $this->empaqueter($parCle, $enfants, $parent, $classe, $cles);
                        $tronque = true;
                    }
                }
            }
        }

        return $tronque;
    }

    /** Remplace une fratrie par un nœud unique qui la représente et la compte. */
    private function empaqueter(array &$parCle, array &$enfants, string $parent, string $classe, array $cles): void
    {
        $cleDuPaquet = sprintf('paquet:%s@%s', $classe, $parent);
        $echantillon = [];
        $volume = 0;

        foreach ($cles as $cle) {
            $volume += $this->volumeRecursif($parCle, $enfants, $cle);
            if (count($echantillon) < 3) {
                $echantillon[] = $parCle[$cle]['nom'];
            }
            $this->oublier($parCle, $enfants, $cle);
        }

        $parCle[$cleDuPaquet] = [
            'cle'         => $cleDuPaquet,
            'parent'      => $parent,
            'classe'      => $classe,
            'libelle'     => $parCle[$cles[0]]['libelle'] ?? $classe,
            'nom'         => null,
            'nature'      => 'structurel',
            'geste'       => 'detruire',
            'total'       => $volume,
            'verrou'      => null,
            'paquet'      => true,
            'elements'    => count($cles),
            'echantillon' => $echantillon,
        ];
        $enfants[$parent] = array_values(array_filter(
            $enfants[$parent],
            static fn (string $c): bool => !in_array($c, $cles, true),
        ));
        $enfants[$parent][] = $cleDuPaquet;
    }

    private function volumeRecursif(array $parCle, array $enfants, string $cle): int
    {
        $volume = $parCle[$cle]['paquet'] ?? false ? (int) $parCle[$cle]['total'] : 1;
        foreach ($enfants[$cle] ?? [] as $enfant) {
            $volume += $this->volumeRecursif($parCle, $enfants, $enfant);
        }

        return $volume;
    }

    private function oublier(array &$parCle, array &$enfants, string $cle): void
    {
        foreach ($enfants[$cle] ?? [] as $petit) {
            $this->oublier($parCle, $enfants, $petit);
        }
        unset($enfants[$cle], $parCle[$cle]);
    }

    /** @return string[] */
    private function parentsLesPlusProfonds(array $parCle, array $enfants, string $racineCle): array
    {
        $profondeurs = [];
        foreach (array_keys($enfants) as $parent) {
            $profondeurs[$parent] = $this->profondeur($parCle, $parent, $racineCle);
        }
        if ($profondeurs === []) {
            return [];
        }
        $max = max($profondeurs);

        return array_keys(array_filter($profondeurs, static fn (int $p): bool => $p === $max));
    }

    private function profondeur(array $parCle, string $cle, string $racineCle): int
    {
        $niveau = 0;
        $courant = $cle;
        while ($courant !== $racineCle && isset($parCle[$courant]) && ++$niveau < 20) {
            $courant = (string) ($parCle[$courant]['parent'] ?? $racineCle);
        }

        return $niveau;
    }

    /** Le total d'un nœud comprend le nœud lui-même et tout ce qui pend de lui. */
    private function totaliser(array &$parCle, array $enfants, string $cle): int
    {
        $propre = ($parCle[$cle]['paquet'] ?? false) ? (int) $parCle[$cle]['total'] : 1;
        if (($parCle[$cle]['geste'] ?? '') === 'verrouille') {
            $propre = 0; // un verrou n'emporte rien : il retient
        }

        $total = $propre;
        foreach ($enfants[$cle] ?? [] as $enfant) {
            $total += $this->totaliser($parCle, $enfants, $enfant);
        }
        $parCle[$cle]['total'] = $total;

        return $total;
    }

    /**
     * ⚠ EN LARGEUR, ET LE PARENT PRÉCÈDE TOUJOURS SES ENFANTS. L'écran construit l'arbre en
     * un seul passage : recevoir un enfant avant son parent l'obligerait à garder en
     * mémoire des nœuds en attente, ou pire, à les perdre.
     *
     * @return array<int, array<string, mixed>>
     */
    private function enLargeur(array $parCle, array $enfants, string $racineCle): array
    {
        $sortie = [];
        $file = $enfants[$racineCle] ?? [];

        while ($file !== []) {
            $cle = array_shift($file);
            if (!isset($parCle[$cle])) {
                continue;
            }
            $sortie[] = $parCle[$cle];
            foreach ($enfants[$cle] ?? [] as $enfant) {
                $file[] = $enfant;
            }
        }

        return $sortie;
    }

    /** @return array<class-string, string> */
    private function libellesDuPlan(PlanDeSuppression $plan, array $aNommer): array
    {
        $libelles = [];
        foreach (array_keys($aNommer) as $classe) {
            $libelles[$classe] = $plan->libelle($classe);
        }

        return $libelles;
    }

    private function court(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');

        return $pos === false ? $fqcn : substr($fqcn, $pos + 1);
    }
}
