<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add google_authenticator_secret_pending and is_2fa_setup_in_progress columns to user table.
 * These columns support the improved 2FA setup flow that uses user entity instead of session.
 */
final class Version20260304120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add pending 2FA secret and setup in progress flag columns to user table';
    }

    public function up(Schema $schema): void
    {
        // Add pending secret column for storing temp secret during setup
        $this->addSql('ALTER TABLE `user` ADD google_authenticator_secret_pending VARCHAR(255) DEFAULT NULL');
        
        // Add flag to track if 2FA setup is in progress
        $this->addSql('ALTER TABLE `user` ADD is_2fa_setup_in_progress TINYINT(1) DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` DROP COLUMN google_authenticator_secret_pending');
        $this->addSql('ALTER TABLE `user` DROP COLUMN is_2fa_setup_in_progress');
    }
}
