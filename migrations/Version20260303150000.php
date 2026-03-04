<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add google_authenticator_secret column to user table for 2FA support.
 */
final class Version20260303150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add google_authenticator_secret column to user table for two-factor authentication';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE `user` ADD google_authenticator_secret VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE `user` DROP COLUMN google_authenticator_secret');
    }
}
