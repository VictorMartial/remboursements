<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260630104027 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE refund DROP CONSTRAINT fk_5b2c1458e840103a');
        $this->addSql('DROP INDEX idx_5b2c1458e840103a');
        $this->addSql('DROP INDEX uniq_5b2c1458700047d2');
        $this->addSql('ALTER TABLE refund ADD obligatoire BOOLEAN NOT NULL');
        $this->addSql('ALTER TABLE refund ADD date_creation TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE refund ADD date_validation TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE refund DROP reference_remboursement');
        $this->addSql('ALTER TABLE refund DROP nombre_tentatives');
        $this->addSql('ALTER TABLE refund DROP date_echec');
        $this->addSql('ALTER TABLE refund DROP a_refaire');
        $this->addSql('ALTER TABLE refund DROP a_rembourser');
        $this->addSql('ALTER TABLE refund DROP motif_rejet');
        $this->addSql('ALTER TABLE refund DROP created_at');
        $this->addSql('ALTER TABLE refund DROP date_remboursement');
        $this->addSql('ALTER TABLE refund DROP ticket_parent_id');
        $this->addSql('ALTER TABLE refund ALTER montant_rembourse TYPE VARCHAR(255)');
        $this->addSql('ALTER TABLE refund ALTER methode_paiement TYPE VARCHAR(50)');
        $this->addSql('CREATE INDEX IDX_5B2C1458700047D2 ON refund (ticket_id)');
        $this->addSql('ALTER TABLE ticket ADD statut_paiement VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE ticket ADD nombre_tentatives INT NOT NULL');
        $this->addSql('ALTER TABLE ticket ADD motif_echec VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE ticket ADD a_rembourser BOOLEAN NOT NULL');
        $this->addSql('ALTER TABLE ticket ADD date_remboursement TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE ticket ADD statut_remboursement VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE ticket ADD ticket_parent_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE ticket DROP date_commande');
        $this->addSql('ALTER TABLE ticket DROP id_commande');
        $this->addSql('ALTER TABLE ticket DROP reference_commande');
        $this->addSql('ALTER TABLE ticket DROP id_transaction');
        $this->addSql('ALTER TABLE ticket DROP methode_paiement');
        $this->addSql('ALTER TABLE ticket DROP identifiant_compte');
        $this->addSql('ALTER TABLE ticket DROP type_transaction');
        $this->addSql('ALTER TABLE ticket DROP montant_commande');
        $this->addSql('ALTER TABLE ticket DROP code_reponse');
        $this->addSql('ALTER TABLE ticket DROP rib');
        $this->addSql('ALTER TABLE ticket DROP description');
        $this->addSql('ALTER TABLE ticket DROP created_at');
        $this->addSql('ALTER TABLE ticket RENAME COLUMN updated_at TO date_echec');
        $this->addSql('ALTER TABLE ticket ALTER date_echec TYPE TIMESTAMP(0) WITHOUT TIME ZONE');
        $this->addSql('ALTER TABLE ticket ADD CONSTRAINT FK_97A0ADA3E840103A FOREIGN KEY (ticket_parent_id) REFERENCES ticket (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_97A0ADA3E840103A ON ticket (ticket_parent_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX IDX_5B2C1458700047D2');
        $this->addSql('ALTER TABLE refund ADD reference_remboursement VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE refund ADD nombre_tentatives INT NOT NULL');
        $this->addSql('ALTER TABLE refund ADD date_echec TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE refund ADD a_rembourser BOOLEAN NOT NULL');
        $this->addSql('ALTER TABLE refund ADD motif_rejet TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE refund ADD created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL');
        $this->addSql('ALTER TABLE refund ADD date_remboursement TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE refund ADD ticket_parent_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE refund DROP date_creation');
        $this->addSql('ALTER TABLE refund DROP date_validation');
        $this->addSql('ALTER TABLE refund ALTER montant_rembourse TYPE NUMERIC(10, 2)');
        $this->addSql('ALTER TABLE refund ALTER methode_paiement TYPE VARCHAR(255)');
        $this->addSql('ALTER TABLE refund RENAME COLUMN obligatoire TO a_refaire');
        $this->addSql('ALTER TABLE refund ALTER a_refaire TYPE BOOLEAN');
        $this->addSql('ALTER TABLE refund ADD CONSTRAINT fk_5b2c1458e840103a FOREIGN KEY (ticket_parent_id) REFERENCES ticket (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX idx_5b2c1458e840103a ON refund (ticket_parent_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_5b2c1458700047d2 ON refund (ticket_id)');
        $this->addSql('ALTER TABLE ticket DROP CONSTRAINT FK_97A0ADA3E840103A');
        $this->addSql('DROP INDEX IDX_97A0ADA3E840103A');
        $this->addSql('ALTER TABLE ticket ADD date_commande DATE NOT NULL');
        $this->addSql('ALTER TABLE ticket ADD id_commande VARCHAR(255) NOT NULL');
        $this->addSql('ALTER TABLE ticket ADD reference_commande VARCHAR(255) NOT NULL');
        $this->addSql('ALTER TABLE ticket ADD id_transaction VARCHAR(255) NOT NULL');
        $this->addSql('ALTER TABLE ticket ADD methode_paiement VARCHAR(50) NOT NULL');
        $this->addSql('ALTER TABLE ticket ADD identifiant_compte VARCHAR(255) NOT NULL');
        $this->addSql('ALTER TABLE ticket ADD type_transaction VARCHAR(100) NOT NULL');
        $this->addSql('ALTER TABLE ticket ADD montant_commande NUMERIC(10, 2) NOT NULL');
        $this->addSql('ALTER TABLE ticket ADD rib VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE ticket ADD description TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE ticket ADD created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL');
        $this->addSql('ALTER TABLE ticket ADD updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE ticket DROP statut_paiement');
        $this->addSql('ALTER TABLE ticket DROP nombre_tentatives');
        $this->addSql('ALTER TABLE ticket DROP date_echec');
        $this->addSql('ALTER TABLE ticket DROP a_rembourser');
        $this->addSql('ALTER TABLE ticket DROP date_remboursement');
        $this->addSql('ALTER TABLE ticket DROP statut_remboursement');
        $this->addSql('ALTER TABLE ticket DROP ticket_parent_id');
        $this->addSql('ALTER TABLE ticket RENAME COLUMN motif_echec TO code_reponse');
        $this->addSql('ALTER TABLE ticket ALTER code_reponse TYPE VARCHAR(255)');
    }
}
