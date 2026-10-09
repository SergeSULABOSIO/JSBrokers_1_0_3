<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Entity\Avenant;
use App\Entity\Piste;
use App\Services\Piste\NomDePisteDerivee;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * LE STATUT DE LA POLICE DE BASE SUIT SES MOUVEMENTS — et se restitue à l'abandon.
 *
 * ── SCHÉMA ────────────────────────────────────────────────────────────────────
 * `piste.statut_base_avant_mouvement` : le statut que portait la police de base quand
 * l'opportunité dérivée l'a rattachée. Supprimer l'opportunité (abandon du mouvement)
 * rend à la base EXACTEMENT ce statut (LiensProteges::dissocier).
 *
 * ── RATTRAPAGE DES DONNÉES (idempotent : chaque UPDATE ne vise que l'état d'avant) ──
 *  1. Mémoire du statut d'avant, pour les opportunités dérivées existantes. L'ancien code
 *     n'écrivait rien sur la base pour un renouvellement ou une prorogation : son statut
 *     actuel EST celui d'avant. Pour une annulation / résiliation, il écrivait « Annulé » :
 *     le statut d'avant était « En cours » (valeur de naissance d'un avenant).
 *  2. Polices de base RENOUVELÉES / PROROGÉES : encore « En cours » alors qu'un avenant
 *     successeur existe — comptées parmi les polices actives à côté de lui.
 *  3. Avenants d'ACTE d'annulation / résiliation : nés « En cours », ils gonflaient les
 *     polices actives. Passés « Annulé / résilié ».
 *  4. Noms d'opportunités au préfixe cumulé (« Renouvellement — Renouvellement — X »),
 *     ramenés à UN préfixe — le premier, celui du mouvement le plus récent — par la règle
 *     de NomDePisteDerivee, la même que l'application et que piste-name-sync.
 *
 * À JOUER AUSSI EN PRODUCTION (bin/deploy.sh exécute les migrations).
 */
final class Version20261009120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Statut de la police de base tenu par les mouvements (mémoire du statut d’avant, rattrapage des '
            . 'bases renouvelées/prorogées et des actes de fin, noms d’opportunités dédoublés).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE piste ADD statut_base_avant_mouvement SMALLINT DEFAULT NULL');

        $scellants = implode(',', [Piste::AVENANT_ANNULATION, Piste::AVENANT_RESILIATION]);

        // 1. Statut d'avant le mouvement (seulement là où il n'est pas encore mémorisé).
        $this->addSql(sprintf(
            'UPDATE piste p INNER JOIN avenant b ON b.id = p.avenant_de_base_id
             SET p.statut_base_avant_mouvement = CASE
                 WHEN p.type_avenant IN (%s) AND b.renewal_status = %d THEN %d
                 ELSE b.renewal_status END
             WHERE p.statut_base_avant_mouvement IS NULL',
            $scellants,
            Avenant::RENEWAL_STATUS_CANCELLED,
            Avenant::RENEWAL_STATUS_RUNNING,
        ));

        // 2. Bases encore « En cours » dont l'opportunité dérivée a produit un successeur.
        foreach ([
            Piste::AVENANT_RENOUVELLEMENT => Avenant::RENEWAL_STATUS_RENEWED,
            Piste::AVENANT_PROROGATION    => Avenant::RENEWAL_STATUS_EXTENDED,
        ] as $type => $statut) {
            $this->addSql(sprintf(
                'UPDATE avenant b
                 INNER JOIN piste p ON p.avenant_de_base_id = b.id AND p.type_avenant = %d
                 SET b.renewal_status = %d
                 WHERE b.renewal_status = %d
                   AND EXISTS (
                       SELECT 1 FROM (
                           SELECT s.id AS sid, c.piste_id AS pid FROM avenant s INNER JOIN cotation c ON c.id = s.cotation_id
                       ) succ WHERE succ.pid = p.id AND succ.sid <> b.id
                   )',
                $type,
                $statut,
                Avenant::RENEWAL_STATUS_RUNNING,
            ));
        }

        // 3. Actes d'annulation / résiliation encore « En cours ».
        $this->addSql(sprintf(
            'UPDATE avenant a
             INNER JOIN cotation c ON c.id = a.cotation_id
             INNER JOIN piste p ON p.id = c.piste_id
             SET a.renewal_status = %d
             WHERE p.type_avenant IN (%s)
               AND a.renewal_status = %d
               AND (p.avenant_de_base_id IS NULL OR p.avenant_de_base_id <> a.id)',
            Avenant::RENEWAL_STATUS_CANCELLED,
            $scellants,
            Avenant::RENEWAL_STATUS_RUNNING,
        ));

        // 4. Préfixes cumulés.
        $this->nettoyerLesNoms();
    }

    /**
     * Retour arrière documenté.
     *
     *  - RESTITUÉ : le statut des polices de base que le rattrapage 2 (ou un mouvement
     *    enregistré depuis) a fait passer « Renouvelé » / « Prorogé » : la colonne
     *    mémorisée le permet, et l'ancien code ne sait pas tenir ces statuts.
     *  - NON RESTITUÉS, volontairement : les actes de fin repassés « Annulé / résilié »
     *    (rattrapage 3) et les noms dédoublés (rattrapage 4). Ce sont des corrections
     *    d'erreurs, et rien ne distingue après coup une ligne corrigée d'une ligne écrite
     *    juste : les « défaire » réintroduirait les erreurs sur des lignes saines.
     *  - La colonne est ensuite supprimée.
     */
    public function down(Schema $schema): void
    {
        foreach ([
            Piste::AVENANT_RENOUVELLEMENT => Avenant::RENEWAL_STATUS_RENEWED,
            Piste::AVENANT_PROROGATION    => Avenant::RENEWAL_STATUS_EXTENDED,
        ] as $type => $statut) {
            $this->addSql(sprintf(
                'UPDATE avenant b
                 INNER JOIN piste p ON p.avenant_de_base_id = b.id AND p.type_avenant = %d
                 SET b.renewal_status = p.statut_base_avant_mouvement
                 WHERE b.renewal_status = %d AND p.statut_base_avant_mouvement IS NOT NULL',
                $type,
                $statut,
            ));
        }

        $this->addSql('ALTER TABLE piste DROP statut_base_avant_mouvement');
    }

    private function nettoyerLesNoms(): void
    {
        $separateur = NomDePisteDerivee::SEPARATEUR;

        foreach ($this->connection->fetchAllAssociative('SELECT id, nom FROM piste') as $ligne) {
            $nom = (string) $ligne['nom'];

            // Le premier préfixe connu est conservé : c'est le type du mouvement le plus
            // récent. Un nom sans préfixe connu n'est pas touché.
            $premier = null;
            foreach (NomDePisteDerivee::libellesConnus() as $libelle) {
                if (str_starts_with($nom, $libelle . $separateur)) {
                    $premier = $libelle;
                    break;
                }
            }
            if ($premier === null) {
                continue;
            }

            $propre = NomDePisteDerivee::nommer($premier, $nom);
            if ($propre !== $nom) {
                $this->addSql('UPDATE piste SET nom = ? WHERE id = ?', [$propre, (int) $ligne['id']]);
            }
        }
    }
}
