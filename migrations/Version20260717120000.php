<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Instantané des objets du contexte porté par chaque message utilisateur du chat
 * IA (assistant_message.contexte_objets, JSON nullable) : le message « transporte »
 * ses objets — trace immuable affichée en agrafe sur la bulle et annotée dans
 * l'historique transmis au moteur. Migration écrite à la main (ciblée).
 */
final class Version20260717120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'AssistantMessage : instantané contexte_objets (agrafe des messages du chat IA)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE assistant_message ADD contexte_objets LONGTEXT DEFAULT NULL COMMENT \'(DC2Type:json)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE assistant_message DROP contexte_objets');
    }
}
