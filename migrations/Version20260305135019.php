<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260305135019 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // Create Livraison table only if it doesn't exist
        if (!$schema->hasTable('livraisons')) {
            $this->addSql('CREATE TABLE livraisons (id INT AUTO_INCREMENT NOT NULL, last_name VARCHAR(100) NOT NULL, first_name VARCHAR(100) NOT NULL, email VARCHAR(180) NOT NULL, adresse VARCHAR(255) NOT NULL, tel VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL, commande_id INT DEFAULT NULL, INDEX IDX_96A0CE6182EA2E54 (commande_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4');
            $this->addSql('ALTER TABLE livraisons ADD CONSTRAINT FK_96A0CE6182EA2E54 FOREIGN KEY (commande_id) REFERENCES commandes (id) ON DELETE CASCADE');
        }
        
        // Create Payment table only if it doesn't exist
        if (!$schema->hasTable('payments')) {
            $this->addSql('CREATE TABLE payments (id INT AUTO_INCREMENT NOT NULL, montant NUMERIC(10, 2) NOT NULL, statut VARCHAR(50) NOT NULL, methode_paiement VARCHAR(50) NOT NULL, date_paiement DATETIME NOT NULL, stripe_session_id VARCHAR(255) DEFAULT NULL, stripe_payment_intent_id VARCHAR(255) DEFAULT NULL, stripe_metadata LONGTEXT DEFAULT NULL, transaction_ref VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, commande_id INT NOT NULL, INDEX idx_payment_commande (commande_id), INDEX idx_stripe_session (stripe_session_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4');
            $this->addSql('ALTER TABLE payments ADD CONSTRAINT FK_65D29B3282EA2E54 FOREIGN KEY (commande_id) REFERENCES commandes (id) ON DELETE CASCADE');
        }
    }

    public function down(Schema $schema): void
    {
        // Drop constraints first
        $this->addSql('ALTER TABLE payments DROP FOREIGN KEY FK_65D29B3282EA2E54');
        $this->addSql('ALTER TABLE livraisons DROP FOREIGN KEY FK_96A0CE6182EA2E54');
        
        // Drop tables
        $this->addSql('DROP TABLE IF EXISTS payments');
        $this->addSql('DROP TABLE IF EXISTS livraisons');
    }
}
