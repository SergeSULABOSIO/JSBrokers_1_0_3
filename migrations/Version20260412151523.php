<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260412151523 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        // Étape 1: Ajouter les colonnes SEULEMENT si elles n'existent pas déjà.
        $this->addSql('
            ALTER TABLE revenu_pour_courtier 
            ADD COLUMN IF NOT EXISTS entreprise_id INT DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS invite_id INT DEFAULT NULL
        ');

        // Étape 2: Mettre à jour les enregistrements existants pour remplir les nouvelles colonnes.
        // On remonte la relation Revenu -> Cotation -> Piste -> Invite pour trouver l'entreprise.
        $this->addSql('
            UPDATE revenu_pour_courtier rpc
            JOIN cotation c ON rpc.cotation_id = c.id
            JOIN piste p ON c.piste_id = p.id
            JOIN invite i ON p.invite_id = i.id
            SET rpc.entreprise_id = i.entreprise_id, rpc.invite_id = i.id
        ');

        // Étape 3: Maintenant que les données sont remplies, on peut rendre la colonne `entreprise_id` obligatoire.
        $this->addSql('ALTER TABLE revenu_pour_courtier CHANGE entreprise_id entreprise_id INT NOT NULL');

        $this->addSql('ALTER TABLE revenu_pour_courtier ADD CONSTRAINT FK_8CAA04C1A4AEAFEA FOREIGN KEY (entreprise_id) REFERENCES entreprise (id)');
        $this->addSql('ALTER TABLE revenu_pour_courtier ADD CONSTRAINT FK_8CAA04C1EA417747 FOREIGN KEY (invite_id) REFERENCES invite (id)');
        $this->addSql('CREATE INDEX IDX_8CAA04C1A4AEAFEA ON revenu_pour_courtier (entreprise_id)');
        $this->addSql('CREATE INDEX IDX_8CAA04C1EA417747 ON revenu_pour_courtier (invite_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE revenu_pour_courtier DROP FOREIGN KEY FK_8CAA04C1A4AEAFEA');
        $this->addSql('ALTER TABLE revenu_pour_courtier DROP FOREIGN KEY FK_8CAA04C1EA417747');
        $this->addSql('DROP INDEX IDX_8CAA04C1A4AEAFEA ON revenu_pour_courtier');
        $this->addSql('DROP INDEX IDX_8CAA04C1EA417747 ON revenu_pour_courtier');
        $this->addSql('ALTER TABLE revenu_pour_courtier DROP entreprise_id, DROP invite_id');
    }
}
