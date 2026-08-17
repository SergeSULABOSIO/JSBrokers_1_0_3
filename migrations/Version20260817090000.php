<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Un classeur peut être LE classeur d'un client.
 *
 * CE QUE CETTE COLONNE REND POSSIBLE. Le classement des documents existait à moitié :
 * `document.classeur_id` était là depuis toujours, le formulaire offrait le champ, l'écran
 * affichait « Classé dans : … » — et aucun code de production ne créait jamais de classeur
 * ni n'en posait un. Tous les documents affichaient donc « Non classé ». Le rangement
 * devient automatique : tout document relevant d'un client va dans le classeur de ce
 * client, créé au besoin. Encore fallait-il pouvoir désigner ce classeur autrement que par
 * son intitulé — une correspondance de nom se trompe dès que deux clients sont homonymes,
 * et se perd au premier renommage.
 *
 * UNIQUE : « un client, un classeur ». Sans cette contrainte, deux enregistrements
 * simultanés en créeraient deux, et le rangement cesserait d'être déterministe — le même
 * client se retrouverait avec ses pièces réparties entre deux meubles. La base refuse
 * désormais le doublon, plutôt que de compter sur le code pour ne pas le produire.
 *
 * NULLABLE : les classeurs créés à la main n'appartiennent à personne et continuent
 * d'exister tels quels. MySQL admet autant de NULL qu'on veut dans un index unique.
 *
 * ON DELETE CASCADE : supprimer un client emporte SON classeur, qui n'a plus d'objet.
 * Les documents qu'il contenait ne sont pas emportés par cette contrainte-ci —
 * `Classeur.documents` n'a ni cascade ni orphanRemoval — mais ils le sont par leurs
 * propres relations au client, qui portent `orphanRemoval` depuis leur origine.
 *
 * AUCUNE DONNÉE N'EST TOUCHÉE ICI. La colonne naît vide : le rattrapage des classeurs
 * manquants est le travail d'une commande dédiée (`app:classeur:aligner-clients`), en
 * dry-run par défaut, pour que la reprise des anciennes données reste une décision et
 * non un effet de bord d'une migration.
 *
 * Écrite à la main, comme les précédentes : un `doctrine:migrations:diff` sur ce dépôt
 * embarquerait une soixantaine de renommages d'index sans rapport avec ce lot.
 */
final class Version20260817090000 extends AbstractMigration
{
    private const CONTRAINTE = 'FK_D15F835A19EB6921';
    private const INDEX = 'UNIQ_D15F835A19EB6921';

    public function getDescription(): string
    {
        return 'Classeur.client : un client a SON classeur (unique, nullable).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE classeur ADD client_id INT DEFAULT NULL');
        $this->addSql(sprintf(
            'ALTER TABLE classeur ADD CONSTRAINT %s FOREIGN KEY (client_id) REFERENCES client (id) ON DELETE CASCADE',
            self::CONTRAINTE,
        ));
        $this->addSql(sprintf('CREATE UNIQUE INDEX %s ON classeur (client_id)', self::INDEX));
    }

    public function down(Schema $schema): void
    {
        // Le retour en arrière ne fait que défaire la structure. Les classeurs créés par
        // le rattrapage subsistent, désormais rattachés à personne : c'est la bonne
        // direction de défaut — on ne détruit pas des dossiers pour annuler une colonne.
        $this->addSql(sprintf('ALTER TABLE classeur DROP FOREIGN KEY %s', self::CONTRAINTE));
        $this->addSql(sprintf('DROP INDEX %s ON classeur', self::INDEX));
        $this->addSql('ALTER TABLE classeur DROP client_id');
    }
}
