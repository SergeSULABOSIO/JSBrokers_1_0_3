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
 * serveur (logo, documents, PDF généré).
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
        #[Autowire('%kernel.project_dir%/public/images/entreprises')]
        private readonly string $repertoireLogos,
        #[Autowire('%kernel.project_dir%/public/uploads/documents')]
        private readonly string $repertoireDocuments,
        #[Autowire('%kernel.project_dir%/public/pdfs')]
        private readonly string $repertoirePdfs,
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
        $chemins = [];

        // Logo de l'entreprise.
        $logo = $entreprise->getThumbnail();
        if ($logo !== null && $logo !== '' && !$this->estProtege($logo)) {
            $chemins[] = $this->repertoireLogos . '/' . $logo;
        }

        // Tous les documents scopés à cette entreprise.
        $noms = $this->em->getConnection()->fetchFirstColumn(
            'SELECT nom_fichier_stocke FROM document WHERE entreprise_id = :id AND nom_fichier_stocke IS NOT NULL',
            ['id' => $entreprise->getId()],
        );
        foreach ($noms as $nom) {
            if ($nom !== '' && !$this->estProtege($nom)) {
                $chemins[] = $this->repertoireDocuments . '/' . $nom;
            }
        }

        // PDF généré de l'entreprise (nommé par id : {id}.pdf / {id}.txt …).
        foreach (glob($this->repertoirePdfs . '/' . $entreprise->getId() . '.*') ?: [] as $pdf) {
            $chemins[] = $pdf;
        }

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
