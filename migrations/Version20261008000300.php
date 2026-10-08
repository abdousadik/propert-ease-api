<?php

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261008000300 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Require ownership after explicit assignment of legacy projects.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf((int) $this->connection->fetchOne('SELECT COUNT(*) FROM project WHERE owner_id IS NULL') > 0, 'There are unowned projects. Run app:projects:assign-owner EMAIL before retrying.');
        $target = clone $schema;
        $target->getTable('project')->changeColumn('owner_id', ['notnull' => true]);
        $diff = $this->connection->createSchemaManager()->createComparator()->compareSchemas($schema, $target);
        foreach ($this->connection->getDatabasePlatform()->getAlterSchemaSQL($diff) as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(true, 'Ownership enforcement must not be silently removed. Restore a verified backup instead.');
    }
}
