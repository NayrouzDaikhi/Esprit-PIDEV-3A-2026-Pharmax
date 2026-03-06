<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260306010720 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE ligne_commandes ADD CONSTRAINT FK_FA3127A482EA2E54 FOREIGN KEY (commande_id) REFERENCES commandes (id)');
        $this->addSql('DROP INDEX idx_commande ON ligne_commandes');
        $this->addSql('CREATE INDEX IDX_FA3127A482EA2E54 ON ligne_commandes (commande_id)');
        $this->addSql('DROP INDEX idx_stripe_session ON payments');
        $this->addSql('ALTER TABLE payments ADD session_id VARCHAR(255) DEFAULT NULL, ADD payment_intent_id VARCHAR(255) DEFAULT NULL, DROP stripe_session_id, DROP stripe_payment_intent_id, CHANGE stripe_metadata payment_metadata LONGTEXT DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_payment_session ON payments (session_id)');
        $this->addSql('DROP INDEX idx_nom ON produit');
        $this->addSql('ALTER TABLE produit DROP FOREIGN KEY produit_ibfk_1');
        $this->addSql('DROP INDEX idx_categorie ON produit');
        $this->addSql('CREATE INDEX IDX_29A5EC27BCF5E72D ON produit (categorie_id)');
        $this->addSql('ALTER TABLE produit ADD CONSTRAINT produit_ibfk_1 FOREIGN KEY (categorie_id) REFERENCES categorie (id) ON DELETE SET NULL');
        $this->addSql('DROP INDEX idx_status ON user');
        $this->addSql('DROP INDEX idx_email ON user');
        $this->addSql('ALTER TABLE user DROP google_authenticator_secret, DROP google_authenticator_secret_pending, DROP is_2fa_setup_in_progress, CHANGE last_name last_name VARCHAR(255) NOT NULL');
        $this->addSql('DROP INDEX email ON user');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8D93D649E7927C74 ON user (email)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE ligne_commandes DROP FOREIGN KEY FK_FA3127A482EA2E54');
        $this->addSql('ALTER TABLE ligne_commandes DROP FOREIGN KEY FK_FA3127A482EA2E54');
        $this->addSql('DROP INDEX idx_fa3127a482ea2e54 ON ligne_commandes');
        $this->addSql('CREATE INDEX idx_commande ON ligne_commandes (commande_id)');
        $this->addSql('ALTER TABLE ligne_commandes ADD CONSTRAINT FK_FA3127A482EA2E54 FOREIGN KEY (commande_id) REFERENCES commandes (id)');
        $this->addSql('DROP INDEX idx_payment_session ON payments');
        $this->addSql('ALTER TABLE payments ADD stripe_session_id VARCHAR(255) DEFAULT NULL, ADD stripe_payment_intent_id VARCHAR(255) DEFAULT NULL, DROP session_id, DROP payment_intent_id, CHANGE payment_metadata stripe_metadata LONGTEXT DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_stripe_session ON payments (stripe_session_id)');
        $this->addSql('ALTER TABLE produit DROP FOREIGN KEY FK_29A5EC27BCF5E72D');
        $this->addSql('CREATE INDEX idx_nom ON produit (nom)');
        $this->addSql('DROP INDEX idx_29a5ec27bcf5e72d ON produit');
        $this->addSql('CREATE INDEX idx_categorie ON produit (categorie_id)');
        $this->addSql('ALTER TABLE produit ADD CONSTRAINT FK_29A5EC27BCF5E72D FOREIGN KEY (categorie_id) REFERENCES categorie (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE `user` ADD google_authenticator_secret VARCHAR(255) DEFAULT NULL, ADD google_authenticator_secret_pending VARCHAR(255) DEFAULT NULL, ADD is_2fa_setup_in_progress TINYINT(1) DEFAULT 0, CHANGE last_name last_name VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_status ON `user` (status)');
        $this->addSql('CREATE INDEX idx_email ON `user` (email)');
        $this->addSql('DROP INDEX uniq_8d93d649e7927c74 ON `user`');
        $this->addSql('CREATE UNIQUE INDEX email ON `user` (email)');
    }
}
