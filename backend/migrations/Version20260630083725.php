<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260630083725 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add is_active field to user table';
    }

    public function up(Schema $schema): void
    {
        // Ajouter la colonne avec une valeur par défaut
        $this->addSql('ALTER TABLE "user" ADD COLUMN is_active BOOLEAN NOT NULL DEFAULT true');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP COLUMN is_active');
    }
}