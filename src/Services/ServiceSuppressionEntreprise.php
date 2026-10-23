<?php

namespace App\Services;

use App\Entity\Entreprise;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ManyToManyOwningSideMapping;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Détruit INTÉGRALEMENT et INCONDITIONNELLEMENT une entreprise : toutes ses
 * données opérationnelles (toutes les entités scopées par `entreprise_id`), ses
 * lignes de paramétrage, l'entreprise elle-même, ET ses fichiers uploadés sur le
 * serveur : TOUS (chantier 19, décision de Serge) — logo, pièces déposées, fichiers joints au
 * chat de Ket et documents qu'il a produits, dépôts et exports de l'échange, cache audio de la
 * voix. Les temporaires (var/tmp, dossier système) n'appartiennent à personne : ils sont
 * effacés juste après usage, et leurs restes par l'âge.
 *
 * Stratégie « table rase » assumée : on neutralise les contraintes d'intégrité
 * le temps de la purge (SET FOREIGN_KEY_CHECKS = 0 sous MariaDB/MySQL) et on
 * supprime en SQL natif. Aucune cascade ORM, aucune migration de schéma.
 *
 * IMPORTANT — séquence sûre :
 *   A. Collecte des chemins de fichiers AVANT toute suppression (lignes encore là).
 *   B. Purge BD dans une transaction (rollback intégral en cas d'erreur).
 *   C. Suppression physique des fichiers UNIQUEMENT après commit réussi, en
 *      épargnant systématiquement les fichiers par défaut de l'application.
 *
 * Espace disque : la suppression par lignes laisse InnoDB réutiliser l'espace en
 * interne. Pas d'OPTIMIZE TABLE (verrouillerait les tables partagées des autres
 * locataires de cette base multi-entreprises).
 */
class ServiceSuppressionEntreprise
{
    /**
     * Noms de fichiers livrés avec l'application et partagés par tous les
     * locataires : ils ne doivent JAMAIS être supprimés, même si une entreprise
     * y fait référence (ex. logo par défaut non remplacé).
     *
     * @var string[]
     */
    private const FICHIERS_PROTEGES = [
        'default_entreprise.jpg',
        'logo.jpg',
        'logofav.png',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Filesystem $filesystem,
        private readonly LoggerInterface $logger,
        #[Autowire('%app.dossier_des_logos%')]
        private readonly string $repertoireLogos,
        #[Autowire('%app.dossier_des_pieces%')]
        private readonly string $repertoireDocuments,
        #[Autowire('%app.dossier_des_fichiers_de_ket%')]
        private readonly string $repertoireFichiersDeKet,
        #[Autowire('%app.dossier_des_documents_de_ket%')]
        private readonly string $repertoireDocumentsDeKet,
        #[Autowire('%app.dossier_de_l_echange%')]
        private readonly string $repertoireEchange,
        #[Autowire('%app.dossier_de_la_voix_de_ket%')]
        private readonly string $repertoireVoix,
        // Les pièces comptables se conservent dix ans : pas de purge sans archive remise.
        private readonly \App\Service\Note\ConservationDesPieces $conservation,
    ) {}

    /**
     * Détruit l'entreprise et tout ce qui s'y rattache.
     */
    public function supprimer(Entreprise $entreprise): void
    {
        $id = $entreprise->getId();
        if ($id === null) {
            throw new \InvalidArgumentException("Impossible de supprimer une entreprise non persistée.");
        }

        // ⚠ LES NOTES ÉMISES SE CONSERVENT DIX ANS (règle OHADA, décision du 2026-10-15) :
        // la purge ne les emporte qu'une fois leur archive remise, et à jour. Les deux portes
        // (propriétaire, console) passent ici.
        if (($refus = $this->conservation->refusDeSuppression($entreprise)) !== null) {
            throw new \DomainException($refus);
        }

        // ── Phase A : collecte des fichiers à détruire (avant la purge) ────────
        $fichiers = $this->collecterFichiers($entreprise);

        // ── Phase B : purge transactionnelle de la base ───────────────────────
        $this->purgerBaseDeDonnees($id);

        // ── Phase C : suppression physique des fichiers (post-commit) ──────────
        $this->supprimerFichiers($fichiers);
    }

    /**
     * Construit la liste absolue des fichiers uploadés rattachés à l'entreprise,
     * en excluant d'emblée les fichiers protégés. Lue AVANT la purge car elle
     * s'appuie sur des lignes (logo, documents) qui vont disparaître.
     *
     * @return string[] chemins absolus
     */
    private function collecterFichiers(Entreprise $entreprise): array
    {
        return $this->fichiersDuCabinet((int) $entreprise->getId());
    }

    /**
     * TOUS LES FICHIERS D'UN CABINET, par son identifiant : la source unique de la purge — et des
     * tests, qui effacent ainsi les fichiers de leurs cabinets d'essai (sans quoi ils
     * s'accumulent sur le poste). Lue en base : à appeler AVANT d'effacer les lignes.
     *
     * @return string[] chemins absolus (fichiers, et dossiers propres au cabinet)
     */
    public function fichiersDuCabinet(int $idCabinet): array
    {
        $chemins = [];
        $conn = $this->em->getConnection();
        $id = $idCabinet;

        // Logo de l'entreprise.
        $logo = $conn->fetchOne('SELECT thumbnail FROM entreprise WHERE id = :id', ['id' => $id]);
        if (\is_string($logo) && $logo !== '' && $logo === basename($logo) && !$this->estProtege($logo)) {
            $chemins[] = $this->repertoireLogos . '/' . $logo;
        }

        // Tous les documents scopés à cette entreprise.
        $noms = $conn->fetchFirstColumn(
            'SELECT nom_fichier_stocke FROM document WHERE entreprise_id = :id AND nom_fichier_stocke IS NOT NULL',
            ['id' => $id],
        );
        foreach ($noms as $nom) {
            if ($nom !== '' && $nom === basename($nom) && !$this->estProtege($nom)) {
                $chemins[] = $this->repertoireDocuments . '/' . $nom;
            }
        }

        // Les fichiers JOINTS au chat de Ket : rattachés par la conversation, pas par le cabinet.
        foreach ($conn->fetchFirstColumn(
            'SELECT f.nom_fichier_stocke FROM assistant_conversation_fichier f
             JOIN assistant_conversation c ON c.id = f.conversation_id
             WHERE c.entreprise_id = :id AND f.nom_fichier_stocke IS NOT NULL',
            ['id' => $id],
        ) as $nom) {
            if ($nom !== '' && $nom === basename($nom)) {
                $chemins[] = $this->repertoireFichiersDeKet . '/' . $nom;
            }
        }

        // Les documents PRODUITS par Ket pour ce cabinet.
        foreach ($conn->fetchFirstColumn(
            'SELECT nom_fichier_stocke FROM assistant_document WHERE entreprise_id = :id AND nom_fichier_stocke IS NOT NULL',
            ['id' => $id],
        ) as $nom) {
            if ($nom !== '' && $nom === basename($nom)) {
                $chemins[] = $this->repertoireDocumentsDeKet . '/' . $nom;
            }
        }

        // Les dossiers NOMMÉS par l'identifiant du cabinet, effacés entiers : dépôts et
        // exports de l'échange, cache audio de la voix de Ket.
        $chemins[] = $this->repertoireEchange . '/' . $id;
        $chemins[] = $this->repertoireEchange . '/exports/' . $id;
        $chemins[] = $this->repertoireVoix . '/' . $id;

        return $chemins;
    }

    /**
     * Supprime, contraintes désactivées, toutes les lignes rattachées à
     * l'entreprise puis l'entreprise elle-même, dans une transaction.
     */
    private function purgerBaseDeDonnees(int $id): void
    {
        $conn = $this->em->getConnection();

        [$tablesScopees, $tablesJointure] = $this->cartographierTables();

        $conn->beginTransaction();
        try {
            $conn->executeStatement('SET FOREIGN_KEY_CHECKS = 0');

            // 1. Tables de jointure ManyToMany : on retire les lignes dont le côté
            //    propriétaire appartient à l'entreprise, AVANT de purger les entités.
            foreach ($tablesJointure as $jt) {
                $conn->executeStatement(
                    sprintf(
                        'DELETE FROM %s WHERE %s IN (SELECT id FROM %s WHERE entreprise_id = :id)',
                        $conn->quoteIdentifier($jt['joinTable']),
                        $conn->quoteIdentifier($jt['joinColumn']),
                        $conn->quoteIdentifier($jt['sourceTable']),
                    ),
                    ['id' => $id],
                );
            }

            // 1 bis. LES LIGNES RATTACHÉES INDIRECTEMENT (chantier 19) : messages et fichiers
            //    d'une conversation de Ket, retours d'un ticket, opérations d'un bordereau…
            //    Elles ne portent pas entreprise_id ; contraintes désactivées, rien ne les
            //    emportait. Effacées AVANT leurs parents (qui disent encore à qui elles sont),
            //    du plus profond au moins profond.
            $dependantes = $this->cartographierDependantes($tablesScopees);
            foreach ($this->ordreDEffacement($dependantes) as $table) {
                $conditions = [];
                foreach ($dependantes[$table] as $lien) {
                    $conditions[] = sprintf(
                        '%s IN (%s)',
                        $conn->quoteIdentifier($lien['colonne']),
                        $this->sqlIdsDuCabinet($lien['parent'], $tablesScopees, $dependantes, [$table]),
                    );
                }
                $conn->executeStatement(
                    sprintf('DELETE FROM %s WHERE %s', $conn->quoteIdentifier($table), implode(' OR ', $conditions)),
                    ['id' => $id],
                );
            }

            // 2. Toutes les tables scopées par entreprise_id (≈ 37 entités).
            foreach ($tablesScopees as $table => $joinColumn) {
                $conn->executeStatement(
                    sprintf(
                        'DELETE FROM %s WHERE %s = :id',
                        $conn->quoteIdentifier($table),
                        $conn->quoteIdentifier($joinColumn),
                    ),
                    ['id' => $id],
                );
            }

            // 3. Détacher (sans supprimer) les utilisateurs connectés à l'entreprise.
            $conn->executeStatement(
                'UPDATE utilisateur SET connected_to_id = NULL WHERE connected_to_id = :id',
                ['id' => $id],
            );

            // 4. Filet de sécurité si l'association n'a pas été cartographiée.
            $conn->executeStatement('DELETE FROM invite WHERE entreprise_id = :id', ['id' => $id]);

            // 5. L'entreprise elle-même (emporte sa colonne utilisateur_id propriétaire).
            $conn->executeStatement('DELETE FROM entreprise WHERE id = :id', ['id' => $id]);

            $conn->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();
            // Réactive les contraintes sur cette connexion quoi qu'il arrive.
            try {
                $conn->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
            } catch (\Throwable) {
                // ignoré : la connexion sera de toute façon recyclée.
            }
            throw $e;
        }
    }

    /**
     * Inspecte les métadonnées Doctrine pour découvrir, sans liste codée en dur :
     *  - les tables possédant une association ManyToOne « entreprise » (purge directe) ;
     *  - les tables de jointure ManyToMany de ces mêmes entités (purge préalable).
     *
     * @return array{0: array<string, string>, 1: list<array{joinTable: string, joinColumn: string, sourceTable: string}>}
     */
    private function cartographierTables(): array
    {
        $tablesScopees = [];
        $tablesJointure = [];

        foreach ($this->em->getMetadataFactory()->getAllMetadata() as $meta) {
            if ($meta->isMappedSuperclass
                || !$meta->hasAssociation('entreprise')
                || !$meta->isSingleValuedAssociation('entreprise')
                || $meta->getAssociationTargetClass('entreprise') !== Entreprise::class) {
                continue;
            }

            $table = $meta->getTableName();
            $tablesScopees[$table] = $meta->getSingleAssociationJoinColumnName('entreprise');

            // Tables de jointure ManyToMany détenues (côté propriétaire) par cette entité.
            foreach ($meta->associationMappings as $m) {
                if (!$m instanceof ManyToManyOwningSideMapping) {
                    continue;
                }
                $tablesJointure[] = [
                    'joinTable'   => $m->joinTable->name,
                    'joinColumn'  => $m->joinTable->joinColumns[0]->name,
                    'sourceTable' => $table,
                ];
            }
        }

        // L'entreprise elle-même et la table utilisateur sont gérées à part.
        unset($tablesScopees['entreprise']);

        return [$tablesScopees, $tablesJointure];
    }

    /**
     * LES TABLES RATTACHÉES AU CABINET SANS PORTER SON IDENTIFIANT, découvertes dans le modèle
     * Doctrine (jamais une liste figée : une table ajoutée demain est couverte d'office). Une
     * table l'est si l'une de ses relations « vers un » pointe vers une table du cabinet, ou
     * vers une table elle-même rattachée — jusqu'au point fixe. Exclus : `utilisateur` (un
     * compte peut servir à d'autres cabinets ; il est seulement détaché) et les relations d'une
     * table vers elle-même (une réponse à un message suit déjà sa conversation).
     *
     * @param array<string, string> $tablesScopees table => colonne entreprise
     * @return array<string, list<array{colonne: string, parent: string}>>
     */
    public function cartographierDependantes(array $tablesScopees): array
    {
        $parTable = [];
        foreach ($this->em->getMetadataFactory()->getAllMetadata() as $meta) {
            if (!$meta->isMappedSuperclass && !$meta->isEmbeddedClass) {
                $parTable[$meta->getTableName()] = $meta;
            }
        }

        $dependantes = [];
        do {
            $ajout = false;
            foreach ($parTable as $table => $meta) {
                if (isset($tablesScopees[$table]) || \in_array($table, ['entreprise', 'utilisateur'], true)) {
                    continue;
                }
                foreach ($meta->associationMappings as $champ => $mapping) {
                    if (!$mapping->isToOneOwningSide()) {
                        continue;
                    }
                    $parent = $this->em->getClassMetadata($mapping->targetEntity)->getTableName();
                    if ($parent === $table || !($parent === 'entreprise' || isset($tablesScopees[$parent]) || isset($dependantes[$parent]))) {
                        continue;
                    }
                    $lien = ['colonne' => $meta->getSingleAssociationJoinColumnName($champ), 'parent' => $parent];
                    if (!\in_array($lien, $dependantes[$table] ?? [], true)) {
                        $dependantes[$table][] = $lien;
                        $ajout = true;
                    }
                }
            }
        } while ($ajout);

        return $dependantes;
    }

    /**
     * Du plus profond au moins profond : une ligne s'efface pendant que ses parents disent
     * encore à quel cabinet elle appartient.
     *
     * @param array<string, list<array{colonne: string, parent: string}>> $dependantes
     * @return list<string>
     */
    private function ordreDEffacement(array $dependantes): array
    {
        $profondeur = [];
        $mesurer = function (string $table, array $chemin) use (&$mesurer, &$profondeur, $dependantes): int {
            if (!isset($dependantes[$table]) || \in_array($table, $chemin, true)) {
                return 0;
            }
            if (isset($profondeur[$table])) {
                return $profondeur[$table];
            }
            $max = 0;
            foreach ($dependantes[$table] as $lien) {
                $max = max($max, $mesurer($lien['parent'], [...$chemin, $table]));
            }

            return $profondeur[$table] = $max + 1;
        };
        $tables = array_keys($dependantes);
        usort($tables, static fn (string $a, string $b): int => $mesurer($b, []) <=> $mesurer($a, []) ?: strcmp($a, $b));

        return $tables;
    }

    /**
     * La requête des identifiants d'une table qui appartiennent au cabinet `:id`.
     *
     * @param array<string, string> $tablesScopees
     * @param array<string, list<array{colonne: string, parent: string}>> $dependantes
     * @param list<string> $chemin tables déjà traversées (garde contre les cycles)
     */
    private function sqlIdsDuCabinet(string $table, array $tablesScopees, array $dependantes, array $chemin): string
    {
        $conn = $this->em->getConnection();
        if ($table === 'entreprise') {
            return 'SELECT :id';
        }
        if (isset($tablesScopees[$table])) {
            return sprintf('SELECT id FROM %s WHERE %s = :id', $conn->quoteIdentifier($table), $conn->quoteIdentifier($tablesScopees[$table]));
        }
        if (\in_array($table, $chemin, true) || !isset($dependantes[$table])) {
            return 'SELECT NULL';
        }
        $conditions = [];
        foreach ($dependantes[$table] as $lien) {
            $conditions[] = sprintf(
                '%s IN (%s)',
                $conn->quoteIdentifier($lien['colonne']),
                $this->sqlIdsDuCabinet($lien['parent'], $tablesScopees, $dependantes, [...$chemin, $table]),
            );
        }

        return sprintf('SELECT id FROM %s WHERE %s', $conn->quoteIdentifier($table), implode(' OR ', $conditions));
    }

    /**
     * Supprime physiquement les fichiers collectés. Non bloquant : la base est
     * déjà purgée, un échec d'unlink ne doit pas faire échouer la suppression.
     *
     * @param string[] $chemins
     */
    private function supprimerFichiers(array $chemins): void
    {
        foreach ($chemins as $chemin) {
            if ($this->estProtege(basename($chemin))) {
                continue;
            }
            try {
                if ($this->filesystem->exists($chemin)) {
                    $this->filesystem->remove($chemin);
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Suppression entreprise : fichier non supprimé.', [
                    'chemin' => $chemin,
                    'erreur' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Un fichier livré avec l'application, jamais supprimable.
     */
    private function estProtege(string $nomFichier): bool
    {
        return \in_array(basename($nomFichier), self::FICHIERS_PROTEGES, true);
    }
}
