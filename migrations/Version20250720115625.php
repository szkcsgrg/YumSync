<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250720115625 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE created_shops ADD user_id VARCHAR(255) NOT NULL');
        $this->addSql('ALTER TABLE user ADD is_initial_setup_done TINYINT(1) NOT NULL, CHANGE household_id household_id INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE created_shops DROP user_id');
        $this->addSql('ALTER TABLE user DROP is_initial_setup_done, CHANGE household_id household_id INT NOT NULL');
    }
}
