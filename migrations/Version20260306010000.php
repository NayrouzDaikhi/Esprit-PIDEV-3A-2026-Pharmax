<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add data_face_api column to user table for face recognition support
 */
final class Version20260306010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add data_face_api column to user table for face recognition';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` ADD data_face_api LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` DROP COLUMN data_face_api');
    }
}
