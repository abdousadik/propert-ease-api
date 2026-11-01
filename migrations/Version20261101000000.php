<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261101000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Index organization-scoped active project counts and delivery-date filters';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_project_org_active_delivery ON project (organization_id, active, delivery_date)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_project_org_active_delivery ON project');
    }
}
