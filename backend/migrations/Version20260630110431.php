<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260630110431 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE ticket ADD date_commande TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE ticket ADD id_commande VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE ticket ADD reference_commande VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE ticket ADD id_transaction VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE ticket ADD methode_paiement VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE ticket ADD identifiant_compte VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE ticket ADD type_transaction VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE ticket ADD montant_commande VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE ticket ADD rib VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE ticket ADD description TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE ticket DROP date_commande');
        $this->addSql('ALTER TABLE ticket DROP id_commande');
        $this->addSql('ALTER TABLE ticket DROP reference_commande');
        $this->addSql('ALTER TABLE ticket DROP id_transaction');
        $this->addSql('ALTER TABLE ticket DROP methode_paiement');
        $this->addSql('ALTER TABLE ticket DROP identifiant_compte');
        $this->addSql('ALTER TABLE ticket DROP type_transaction');
        $this->addSql('ALTER TABLE ticket DROP montant_commande');
        $this->addSql('ALTER TABLE ticket DROP rib');
        $this->addSql('ALTER TABLE ticket DROP description');
    }
}
