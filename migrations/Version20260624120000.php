<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Coupon : drapeau de visibilité sur la vitrine publique (badge promo).
 */
final class Version20260624120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute coupon.visible_public (mise en avant du coupon sur la vitrine publique).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE coupon ADD visible_public TINYINT(1) DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE coupon DROP visible_public');
    }
}
