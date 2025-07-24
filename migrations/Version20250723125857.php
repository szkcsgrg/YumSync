<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250723125857 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE user ADD country_code VARCHAR(10) DEFAULT NULL, ADD country VARCHAR(255) DEFAULT NULL, ADD region VARCHAR(255) DEFAULT NULL, ADD detected_from VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {       
        $this->addSql('ALTER TABLE user DROP country_code, DROP country, DROP region, DROP detected_from');
    }
}
