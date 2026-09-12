<?php

namespace App\Service\Workspace;

use App\Echange\Service\Progression;
use Doctrine\ORM\EntityManagerInterface;

/**
 * SUPPRIME UNE CHAÎNE ENTIÈRE, SANS JAMAIS EMPORTER CE QUI NE LUI APPARTIENT PAS.
 *
 * Supprimer une opportunité échouait : la base refusait de couper le lien d'une facture,
 * et l'écran renvoyait l'utilisateur à un travail manuel qu'il ne pouvait pas mener à
 * bien — effacer à la main la note, ses lignes, ses règlements, ses chargements, ses
 * commissions, ses échéances et leurs documents, dans le bon ordre. Personne ne le fait.
 *
 * Ce service fait le parcours à sa place, en deux temps :
 *
 *  1. {@see planifier()} établit CE QUI VA PARTIR — sans rien écrire, sans rien hydrater,
 *     par une requête PAR ARÊTE du graphe (jamais une par ligne). C'est ce plan qu'on
 *     montre avant de demander confirmation.
 *  2. {@see executer()} l'applique en une seule transaction, en publiant l'avancement.
 *
 * ⚠ ON NE DÉSARME PAS LES CONTRAINTES. `app:cabinet:vider` le fait, et son commentaire
 * dit pourquoi c'est admissible LÀ : il efface le cabinet EN ENTIER, donc il ne reste à
 * la fin plus rien à quoi une clé pourrait manquer. Une suppression PARTIELLE ne remplit
 * pas cette condition : elle laisserait une note pointant un article disparu.
 *
 * ⚠ ET ON N'ÉCRIT PAS DE TRIEUR. Doctrine ordonne déjà les suppressions par un tri
 * topologique PAR INSTANCE (`UnitOfWork::computeDeleteExecutionOrder`), traite les
 * colonnes nullables comme des arêtes optionnelles et réduit les composantes fortement
 * connexes. Le cycle opportunité → proposition → police a toutes ses colonnes nullables :
 * il se coupe tout seul. Il suffit que toutes les lignes condamnées soient retirées dans
 * le MÊME flush, les détachements posés avant.
 */
// ⚠ NON `final`, COMME `CascadeImpactAnalyzer` : les tests de périmètre de l'assistant
// construisent le moteur d'écriture à la main et doublent ce service, parce qu'aucun
// d'eux ne va jusqu'à effacer une ligne. Un `final` les obligerait à monter une vraie
// suppression pour éprouver un contrôle d'accès — ce qui ne prouverait rien de plus.
class SuppressionEnCascade
{
    /** Au-delà, la clause IN devient hostile au planificateur de requêtes. */
    private const TAILLE_DE_LOT = 1000;

    /**
     * Garde-fou de boucle : chaque tour ne peut qu'AJOUTER des racines (les factures qui
     * se vident), et le graphe est fini. La borne protège d'un mapping pathologique, pas
     * d'un cas métier.
     */
    private const TOURS_MAX = 10;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CompositionDuGraphe $graphe,
        private readonly WorkspaceAccessResolver $acces,
    ) {
    }

    /**
     * Établit la portée RÉELLE d'une suppression, sans rien écrire.
     *
     * ⚠ AUCUNE ENTITÉ N'EST HYDRATÉE ICI. On ne manipule que des couples (classe,
     * identifiant) : les identifiants d'un niveau alimentent le filtre du suivant. Une
     * opportunité réelle tient en une vingtaine de requêtes, quel que soit son volume.
     */
    public function planifier(object $racine, ?ExclusionsDeSuppression $exclusions = null): PlanDeSuppression
    {
        $libelles = $this->libelles();

        try {
            $classe = $this->em->getClassMetadata($racine::class)->getName();
        } catch (\Throwable) {
            return PlanDeSuppression::refuse($racine::class, null, 'Cet élément n\'est pas reconnu par l\'application.', $libelles);
        }

        // ⚠ UNE LIGNE JAMAIS ENREGISTRÉE N'EST PAS UN REFUS, c'est un plan VIDE. Un
        // formulaire peut tenir en mémoire des enfants pas encore écrits (collections
        // différées) : les déclarer bloqués empêcherait de retirer une ligne qu'on vient
        // tout juste d'ajouter par erreur.
        $id = method_exists($racine, 'getId') ? $racine->getId() : null;
        if ($id === null) {
            return new PlanDeSuppression($classe, null, [], [], [], [], $libelles);
        }
        $id = (int) $id;

        if ($this->graphe->estFrontiere($classe)) {
            return PlanDeSuppression::refuse($classe, $id, sprintf(
                'La suppression d\'un cabinet entier ne se fait pas depuis cet écran : '
                . 'elle efface toutes vos données sans exception. Contactez votre administrateur.',
            ), $libelles);
        }

        $aDetruire = [$classe => [$id => $id]];
        $aDetacher = [];
        $conservations = [];
        $refus = [];
        $provenance = [];
        $verrous = [];
        $aretes = [];
        $file = [[$classe, [$id]]];
        $racine = sprintf('%s#%d', $this->court($classe), $id);

        for ($tour = 0; $tour < self::TOURS_MAX; ++$tour) {
            $this->parcourir($file, $aDetruire, $aDetacher, $refus, $libelles, $provenance, $verrous, $aretes);

            // Les factures qui se vident rejoignent le plan comme racines secondaires, et
            // emportent alors leurs règlements et leurs pièces. Celles qui couvrent encore
            // d'autres affaires survivent — le rapport le dit, nommément.
            $nouvelles = $this->parentsDevenusVides($aDetruire, $conservations, $libelles);
            if ($nouvelles === []) {
                break;
            }

            // ⚠ CES RACINES SECONDAIRES N'ONT AUCUNE ARÊTE ENTRANTE DEPUIS LA CIBLE. Une
            // facture entre au plan parce qu'elle s'est VIDÉE, pas parce que quelque chose
            // la désigne : sans rattachement explicite, elle deviendrait un nœud orphelin que
            // l'écran laisse tomber en silence — et le volume annoncé serait faux.
            foreach ($nouvelles as [$classeVidee, $idsVides]) {
                foreach ($idsVides as $idVide) {
                    $provenance[$classeVidee][$idVide] ??= $racine;
                }
            }
            $file = $nouvelles;
        }

        if ($exclusions !== null && !$exclusions->estVide()) {
            $this->epargner($aDetruire, $aDetacher, $aretes, $refus, $libelles, $exclusions);
        }

        return new PlanDeSuppression(
            racineClasse: $classe,
            racineId: $id,
            aDetruire: array_map('array_values', $aDetruire),
            aDetacher: $aDetacher,
            conservations: $conservations,
            refus: array_values(array_unique($refus)),
            libelles: $libelles,
            provenance: $provenance,
            verrous: $verrous,
        );
    }

    /**
     * Applique le plan : détachements d'abord, suppressions ensuite, le tout d'un bloc.
     *
     * ⚠ UNE SEULE TRANSACTION, ET UN SEUL FLUSH. Un échec en milieu de parcours ne doit
     * rien laisser derrière lui : ni ligne effacée, ni lien coupé. Si l'appelant tient
     * déjà une transaction (le moteur d'écriture de l'assistant en ouvre une pour tout un
     * plan), on ne la double pas — c'est à elle de gouverner l'annulation.
     *
     * ⚠ ET SURTOUT PAS `wrapInTransaction()`, QUI FLUSHE UNE SECONDE FOIS. Ce second
     * flush RESSUSCITE ce qu'on vient d'effacer : une ligne supprimée redevient NEUVE aux
     * yeux de Doctrine, et le premier objet encore en mémoire qui la désigne en
     * `cascade: persist` la réinsère. C'est arrivé avec `Client::portefeuille` — le
     * portefeuille repartait, avec un identifiant neuf, sous le même nom, et la réponse
     * annonçait pourtant une suppression réussie. On ouvre donc la transaction à la main
     * pour que le flush de ce service soit le seul.
     *
     * @return array{detruits: int, detaches: int, conservations: string[], parNature: array<int, array{entite: string, libelle: string, count: int}>}
     */
    public function executer(PlanDeSuppression $plan, ?Progression $progression = null): array
    {
        if ($plan->estBloque()) {
            throw new \RuntimeException(implode(' ', $plan->refus));
        }

        $connexion = $this->em->getConnection();
        if ($connexion->isTransactionActive()) {
            return $this->appliquer($plan, $progression); // l'appelant gouverne l'annulation
        }

        $connexion->beginTransaction();
        try {
            $rapport = $this->appliquer($plan, $progression);
            $connexion->commit();

            return $rapport;
        } catch (\Throwable $e) {
            $connexion->rollBack();

            throw $e;
        }
    }

    // ─────────────────────────────── Parcours ─────────────────────────────────

    /**
     * Descend le graphe en largeur : chaque arête entrante donne une requête, et les
     * identifiants trouvés alimentent le tour suivant.
     *
     * @param array<int, array{0: class-string, 1: int[]}>                       $file
     * @param array<class-string, array<int, int>>                               $aDetruire
     * @param array<int, array{classe: class-string, champ: string, ids: int[]}> $aDetacher
     * @param string[]                                                           $refus
     * @param array<class-string, string>                                        $libelles
     * @param array<class-string, array<int, string>>                            $provenance enfant => « ClasseParent#id »
     * @param array<int, array{classe: class-string, champ: string, ids: int[], parents: array<int,int>, motif: string}> $verrous
     * @param array<class-string, array<int, string>>                            $aretes     enfant => champ emprunté
     */
    private function parcourir(
        array $file,
        array &$aDetruire,
        array &$aDetacher,
        array &$refus,
        array $libelles,
        array &$provenance = [],
        array &$verrous = [],
        array &$aretes = [],
    ): void {
        while ($file !== []) {
            [$classe, $ids] = array_shift($file);

            foreach ($this->graphe->sorties($classe) as $arete) {
                if ($arete['nature'] === CompositionDuGraphe::IGNORER) {
                    continue; // la base applique sa propre règle : pas même une requête
                }

                $couples = $this->couplesQuiPointent($arete['source'], $arete['champ'], $ids);
                if ($couples === []) {
                    continue;
                }
                $trouves = array_keys($couples);

                if ($arete['nature'] === CompositionDuGraphe::REFUS) {
                    // ⚠ ON REFUSE PLUTÔT QUE DE DÉTRUIRE CE QUE PERSONNE N'A DEMANDÉ. La
                    // colonne est NON NULLABLE : ces lignes ne peuvent pas survivre
                    // détachées, et les emporter en silence effacerait une dépense
                    // comptabilisée ou un portefeuille entier. On nomme ce qui retient.
                    $motif = $this->phraseDeRefus($arete['source'], count($trouves), $libelles);
                    $refus[] = $motif;
                    // ⚠ ET ON RETIENT SOUS QUOI ÇA BLOQUE. Le motif seul dit « 3 Dépenses en
                    // dépendent » sans dire de quelle échéance : l'écran ne peut alors ni
                    // peindre la branche fautive, ni laisser le reste du dossier partir.
                    $verrous[] = [
                        'classe'  => $arete['source'],
                        'champ'   => $arete['champ'],
                        'ids'     => $trouves,
                        'parents' => $couples,
                        'motif'   => $motif,
                    ];
                    continue;
                }

                if ($arete['nature'] === CompositionDuGraphe::DETACHER) {
                    // ⚠ ON NE DÉTACHE PAS CE QU'ON DÉTRUIT : une ligne déjà condamnée par un
                    // autre chemin n'a pas besoin qu'on lui coupe ses liens avant de partir.
                    $aDetacher[] = ['classe' => $arete['source'], 'champ' => $arete['champ'], 'ids' => $trouves];
                    continue;
                }

                $connus = $aDetruire[$arete['source']] ?? [];
                $nouveaux = [];
                foreach ($trouves as $trouve) {
                    if (!isset($connus[$trouve])) {
                        $connus[$trouve] = $trouve;
                        $nouveaux[] = $trouve;
                        // ⚠ LA PROVENANCE SE POSE ICI, DANS LE DÉDOUBLONNAGE, ET NULLE PART
                        // AILLEURS. Une ligne de facture pend à la fois de son échéance et de
                        // la commission qu'elle liquide : le parcours est en LARGEUR, donc le
                        // premier parent rencontré est le plus court chemin. L'écrire deux fois
                        // ferait boucler l'arbre et compterait la ligne en double.
                        $provenance[$arete['source']][$trouve] = sprintf(
                            '%s#%d',
                            $this->court($classe),
                            $couples[$trouve],
                        );
                        // Le CHAMP par lequel on l'a atteinte : c'est lui qu'il faudra mettre
                        // à nul si l'utilisateur choisit d'épargner cette ligne plutôt que de
                        // la détruire. Sans lui, « conserver » n'aurait aucun lien à couper.
                        $aretes[$arete['source']][$trouve] = $arete['champ'];
                    }
                }
                if ($nouveaux === []) {
                    continue;
                }
                $aDetruire[$arete['source']] = $connus;
                $file[] = [$arete['source'], $nouveaux];
            }
        }
    }

    /**
     * Les identifiants de $source dont le champ $champ pointe l'un de $ids, ET le parent
     * que chacun désigne.
     *
     * ⚠ LE PARENT EST DANS LE `WHERE` DEPUIS TOUJOURS : le mettre aussi dans le `SELECT` ne
     * coûte rien. C'est ce qui permet à l'écran de montrer la chaîne — quelle échéance pend
     * de quelle proposition — au lieu d'un décompte à plat qui dit « 12 échéances » sans
     * jamais dire desquelles. Une colonne de plus, pas une requête de plus.
     *
     * @param int[] $ids
     *
     * @return array<int, int> identifiant de l'enfant => identifiant de son parent
     */
    private function couplesQuiPointent(string $source, string $champ, array $ids): array
    {
        $trouves = [];
        foreach (array_chunk($ids, self::TAILLE_DE_LOT) as $lot) {
            try {
                $lignes = $this->em->createQueryBuilder()
                    ->select('o.id')
                    // IDENTITY() lit la colonne de jointure SANS joindre la table cible :
                    // la requête reste à une table, même sur les arêtes les plus peuplées.
                    ->addSelect(sprintf('IDENTITY(o.%s) AS parent', $champ))
                    ->from($source, 'o')
                    ->where(sprintf('IDENTITY(o.%s) IN (:cibles)', $champ))
                    ->setParameter('cibles', $lot)
                    ->getQuery()
                    ->getScalarResult();
            } catch (\Throwable) {
                // Mapping incohérent : on n'invente rien. La transaction et le refus nommé
                // restent le filet de sécurité si une contrainte proteste à l'exécution.
                continue;
            }
            foreach ($lignes as $ligne) {
                $trouves[(int) $ligne['id']] = (int) $ligne['parent'];
            }
        }

        return $trouves;
    }

    /**
     * Les parents qui perdent TOUS leurs enfants et rejoignent donc le plan, et ceux qui
     * en gardent — dont on explique la survie.
     *
     * @param array<class-string, array<int, int>> $aDetruire
     * @param string[]                             $conservations
     * @param array<class-string, string>          $libelles
     *
     * @return array<int, array{0: class-string, 1: int[]}> nouvelles racines à parcourir
     */
    private function parentsDevenusVides(array &$aDetruire, array &$conservations, array $libelles): array
    {
        $nouvelles = [];

        foreach ($aDetruire as $classe => $ids) {
            $champ = $this->graphe->parentOrphelin($classe);
            if ($champ === null || $ids === []) {
                continue;
            }

            $condamnesParParent = $this->compterParParent($classe, $champ, array_values($ids), true);
            if ($condamnesParParent === []) {
                continue;
            }
            $parents = array_keys($condamnesParParent);
            $totalParParent = $this->compterParParent($classe, $champ, $parents, false);

            $classeParent = $this->classeCible($classe, $champ);
            if ($classeParent === null) {
                continue;
            }

            $aAjouter = [];
            foreach ($condamnesParParent as $parent => $condamnes) {
                $total = $totalParParent[$parent] ?? 0;
                if (isset($aDetruire[$classeParent][$parent])) {
                    continue; // déjà au plan par un autre chemin
                }
                if ($condamnes >= $total) {
                    $aAjouter[] = (int) $parent;
                    continue;
                }
                $conservations[] = $this->phraseDeConservation($classeParent, (int) $parent, $total - $condamnes, $total, $libelles);
            }

            if ($aAjouter === []) {
                continue;
            }
            foreach ($aAjouter as $parent) {
                $aDetruire[$classeParent][$parent] = $parent;
            }
            $nouvelles[] = [$classeParent, $aAjouter];
        }

        return $nouvelles;
    }

    /**
     * Nombre d'enfants par parent — soit parmi une liste d'enfants (condamnés), soit pour
     * une liste de parents (total). Une requête groupée, jamais une par ligne.
     *
     * @param int[] $valeurs
     *
     * @return array<int, int> id du parent => nombre
     */
    private function compterParParent(string $classe, string $champ, array $valeurs, bool $parEnfant): array
    {
        $comptes = [];
        foreach (array_chunk($valeurs, self::TAILLE_DE_LOT) as $lot) {
            try {
                $qb = $this->em->createQueryBuilder()
                    ->select(sprintf('IDENTITY(o.%s) AS parent', $champ), 'COUNT(o.id) AS nombre')
                    ->from($classe, 'o')
                    ->andWhere(sprintf('o.%s IS NOT NULL', $champ))
                    ->groupBy('o.' . $champ);
                $qb->andWhere($parEnfant ? 'o.id IN (:valeurs)' : sprintf('IDENTITY(o.%s) IN (:valeurs)', $champ))
                    ->setParameter('valeurs', $lot);

                foreach ($qb->getQuery()->getScalarResult() as $ligne) {
                    $parent = (int) $ligne['parent'];
                    $comptes[$parent] = ($comptes[$parent] ?? 0) + (int) $ligne['nombre'];
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return $comptes;
    }

    /**
     * « Impossible : 3 Dépenses en dépendent et ne peuvent pas exister sans cet élément.
     *   Rattachez-les ailleurs, ou supprimez-les d'abord. »
     *
     * ⚠ ON NOMME CE QUI BLOQUE, sans quoi le refus ne sert à rien. « Cet élément est
     * utilisé ailleurs » laisse l'utilisateur devant un constat sans marche à suivre.
     *
     * @param array<class-string, string> $libelles
     */
    private function phraseDeRefus(string $source, int $nombre, array $libelles): string
    {
        $libelle = $libelles[$source] ?? $this->court($source);

        return sprintf(
            'Impossible de supprimer cet élément : %d %s en dépend(ent) et ne peu(ven)t pas '
            . 'exister sans lui. Rattachez-les ailleurs, ou supprimez-les d\'abord.',
            $nombre,
            $libelle,
        );
    }

    /**
     * ÉPARGNE LES LIGNES QUE L'UTILISATEUR A DÉCOCHÉES : elles ne sont plus détruites, leur
     * lien au dossier est coupé.
     *
     * ⚠ ET SEULEMENT CELLES QUI SAVENT VIVRE SEULES. Épargner une échéance tout en
     * supprimant sa proposition est impossible — la base refuserait, ou la ligne serait un
     * débris. L'écran ne le propose pas ; si la demande arrive quand même (requête forgée,
     * écran désynchronisé), on REFUSE en le nommant plutôt que de deviner une intention.
     *
     * @param array<class-string, array<int, int>>                               $aDetruire
     * @param array<int, array{classe: class-string, champ: string, ids: int[]}> $aDetacher
     * @param array<class-string, array<int, string>>                            $aretes
     * @param string[]                                                           $refus
     * @param array<class-string, string>                                        $libelles
     */
    private function epargner(
        array &$aDetruire,
        array &$aDetacher,
        array $aretes,
        array &$refus,
        array $libelles,
        ExclusionsDeSuppression $exclusions,
    ): void {
        foreach ($aDetruire as $classe => $ids) {
            $court = $this->court($classe);
            foreach ($ids as $id) {
                if (!$exclusions->contient($court, (int) $id)) {
                    continue;
                }

                if (!$this->graphe->estDetachableAEcran($classe)) {
                    $refus[] = sprintf(
                        'Impossible de conserver « %s » tout en supprimant ce qui la porte : '
                        . 'cette ligne ne peut pas exister seule. Décochez plutôt l\'élément parent.',
                        $libelles[$classe] ?? $court,
                    );
                    continue;
                }

                $champ = $aretes[$classe][$id] ?? null;
                if ($champ === null) {
                    continue; // atteinte sans arête connue (racine) : rien à couper
                }
                unset($aDetruire[$classe][$id]);
                $aDetacher[] = ['classe' => $classe, 'champ' => $champ, 'ids' => [(int) $id]];
            }
            if (($aDetruire[$classe] ?? []) === []) {
                unset($aDetruire[$classe]);
            }
        }
    }

    private function court(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');

        return $pos === false ? $fqcn : substr($fqcn, $pos + 1);
    }

    /** « Facture ND-2026-014 conservée : 2 de ses 5 lignes concernent d'autres affaires. » */
    private function phraseDeConservation(string $classeParent, int $id, int $restantes, int $total, array $libelles): string
    {
        $libelle = $libelles[$classeParent] ?? 'Élément';
        $nom = $this->nomLisible($classeParent, $id);

        return sprintf(
            '%s %s conservée : %d de ses %d lignes se rattachent encore à d\'autres affaires.',
            rtrim($libelle, 's'),
            $nom,
            $restantes,
            $total,
        );
    }

    // ────────────────────────────── Exécution ─────────────────────────────────

    /**
     * @return array{detruits: int, detaches: int, conservations: string[], parNature: array<int, array{entite: string, libelle: string, count: int}>}
     */
    private function appliquer(PlanDeSuppression $plan, ?Progression $progression): array
    {
        $progression?->totaliser($plan->total());

        // 1. LES LIENS QU'ON COUPE, sur des lignes qui survivent. Posés d'abord, et en
        // masse (UPDATE ... SET x = NULL) : ces lignes ne font pas partie du graphe de
        // suppression, les hydrater pour un seul champ serait payer le prix fort.
        $detaches = 0;
        foreach ($plan->aDetacher as $detachement) {
            $progression?->etape(sprintf('Conservation : %s', $plan->libelle($detachement['classe'])));
            $detaches += $this->detacher($detachement['classe'], $detachement['champ'], $detachement['ids']);
            $progression?->avancer(count($detachement['ids']));
        }

        // 2. LES SUPPRESSIONS, toutes dans le même flush : c'est Doctrine qui en calcule
        // l'ordre, par instance, en coupant les cycles sur les colonnes nullables.
        $detruits = 0;
        foreach ($plan->aDetruire as $classe => $ids) {
            $progression?->etape(sprintf('Suppression : %s', $plan->libelle($classe)));
            foreach ($ids as $id) {
                $entite = $this->em->find($classe, $id);
                if ($entite === null) {
                    continue; // déjà parti par une cascade déclarée : rien à reprocher
                }
                $this->couperLesRemonteesHorsPlan($entite, $classe, $plan);
                $this->em->remove($entite);
                ++$detruits;
                $progression?->avancer();
            }
        }

        $progression?->etape('Enregistrement définitif');
        $this->em->flush();
        $progression?->terminer();

        return [
            'detruits'      => $detruits,
            'detaches'      => $detaches,
            'conservations' => $plan->conservations,
            'parNature'     => $plan->portee(),
        ];
    }

    /**
     * Met à nul le champ de ces lignes, en masse.
     *
     * ⚠ CES LIGNES NE SONT PAS EN MÉMOIRE, et c'est ce qui rend l'écriture directe sûre :
     * rien ne les a chargées (le parcours ne lit que des identifiants, et la cascade de
     * Doctrine n'initialise pas une collection sans `cascade: remove`). Aucun exemplaire
     * périmé ne peut donc réécrire par-dessus.
     *
     * @param int[] $ids
     */
    private function detacher(string $classe, string $champ, array $ids): int
    {
        $touchees = 0;
        foreach (array_chunk($ids, self::TAILLE_DE_LOT) as $lot) {
            try {
                $touchees += (int) $this->em->createQueryBuilder()
                    ->update($classe, 'o')
                    ->set('o.' . $champ, ':vide')
                    ->where('o.id IN (:ids)')
                    ->setParameter('vide', null)
                    ->setParameter('ids', $lot)
                    ->getQuery()
                    ->execute();
            } catch (\Throwable) {
                continue;
            }
        }

        return $touchees;
    }

    /**
     * Coupe les associations to-one déclarées en `cascade: remove` — celles qui REMONTENT
     * vers un parent — mais SEULEMENT quand ce parent n'est pas lui-même condamné.
     *
     * ⚠ LA CONDITION EST TOUT LE SUJET, et l'avoir omise cassait la suppression. Ces liens
     * jouent deux rôles à la fois : ils propagent la cascade (danger) ET ils disent à
     * Doctrine dans quel ORDRE effacer (nécessité). Les couper systématiquement privait
     * le trieur de l'arête « l'avenant part avant sa proposition », et la base refusait la
     * suppression au motif qu'« une proposition s'y rattache encore ».
     *
     * Quand le parent est au plan, laisser le lien ne coûte rien — la cascade n'atteint
     * qu'une ligne déjà condamnée — et fait gagner l'ordre. Quand il n'y est pas, le
     * couper est vital : c'est le cas de la POLICE qu'une opportunité de renouvellement
     * fait évoluer, qui doit survivre.
     */
    private function couperLesRemonteesHorsPlan(object $entite, string $classe, PlanDeSuppression $plan): void
    {
        foreach ($this->graphe->liensRemontants($classe) as $champ) {
            $getter = 'get' . ucfirst($champ);
            $setter = 'set' . ucfirst($champ);
            if (!method_exists($entite, $getter) || !method_exists($entite, $setter)) {
                continue;
            }

            $parent = $entite->{$getter}();
            if ($parent === null) {
                continue;
            }
            // getId() sur un proxy ne le charge pas : la vérification reste gratuite.
            $idParent = method_exists($parent, 'getId') ? (int) $parent->getId() : 0;
            $classeParent = $this->classeReelle($parent);
            if ($classeParent !== null && in_array($idParent, $plan->idsDe($classeParent), true)) {
                continue; // condamné lui aussi : le lien peut vivre jusqu'au flush
            }

            // Sans effet en base — Doctrine n'écrit pas une ligne qu'il va effacer — mais
            // c'est en mémoire que la cascade se décide, et c'est là qu'on la coupe.
            $entite->{$setter}(null);
        }
    }

    /** Classe Doctrine réelle d'un objet, proxy compris. */
    private function classeReelle(object $objet): ?string
    {
        try {
            return $this->em->getClassMetadata($objet::class)->getName();
        } catch (\Throwable) {
            return null;
        }
    }

    // ─────────────────────────────── Libellés ─────────────────────────────────

    /**
     * Libellés métier par classe, puisés à la carte d'accès : une même donnée porte le
     * même nom au menu, dans l'assistant et dans un rapport de suppression.
     *
     * @return array<class-string, string>
     */
    private function libelles(): array
    {
        $libelles = [];
        foreach (self::LIBELLES_HORS_RUBRIQUE as $court => $libelle) {
            $libelles['App\\Entity\\' . $court] = $libelle;
        }
        foreach ($this->acces->libellesEntites() as $court => $libelle) {
            $libelles['App\\Entity\\' . $court] = $libelle;
        }

        return $libelles;
    }

    /**
     * LES SOUS-ENTITÉS QUI N'ONT PAS DE RUBRIQUE, ET QU'IL FAUT POURTANT NOMMER.
     *
     * Une suppression en chaîne les traverse toutes, et « 1 ChargementPourPrime seront
     * supprimés avec » n'est pas une phrase qu'on montre à un courtier.
     *
     * ⚠ ELLES NE SONT PAS DANS LA CARTE D'ACCÈS, ET C'EST VOLONTAIRE. Cette carte est
     * liée à la liste d'écriture de l'assistant par un test de parité, et cette liste sert
     * à construire les rubriques du gabarit d'import/export : y inscrire une ligne de
     * facture ajouterait une feuille au classeur, avec ses colonnes et son dictionnaire —
     * un tout autre chantier. Leur DROIT, lui, suit bien celui de leur parent
     * (WorkspaceAccessResolver::GOUVERNANCE_PARENT).
     *
     * @var array<string, string>
     */
    private const LIBELLES_HORS_RUBRIQUE = [
        'Article' => 'Lignes de facture',
        'ChargementPourPrime' => 'Composition de la prime',
        'AutoriteFiscale' => 'Autorités fiscales',
        'Operation' => 'Lignes de bordereau',
    ];

    /** Le nom sous lequel l'utilisateur reconnaît cette ligne (sa référence, son intitulé). */
    private function nomLisible(string $classe, int $id): string
    {
        $entite = $this->em->find($classe, $id);
        if ($entite === null) {
            return '#' . $id;
        }
        foreach (['getReference', 'getNom', 'getLibelle', 'getTitre', 'getCode'] as $getter) {
            if (!method_exists($entite, $getter)) {
                continue;
            }
            $valeur = $entite->{$getter}();
            if (is_string($valeur) && trim(strip_tags($valeur)) !== '') {
                return trim(strip_tags($valeur));
            }
        }

        return '#' . $id;
    }

    /** Classe visée par une association, ou null si le mapping est illisible. */
    private function classeCible(string $classe, string $champ): ?string
    {
        try {
            $mapping = $this->em->getClassMetadata($classe)->getAssociationMapping($champ);
        } catch (\Throwable) {
            return null;
        }

        $cible = (string) ($mapping->targetEntity ?? '');

        return $cible === '' ? null : $cible;
    }
}
