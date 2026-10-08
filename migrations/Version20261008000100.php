<?php

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261008000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Baseline user and project schema before ownership.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf($schema->hasTable('user') || $schema->hasTable('project'), 'Existing schema: verify the baseline and mark version 20261008000100 as executed; see docs/upgrading.md.');
        $baseline = new Schema();
        $user = $baseline->createTable('user');
        $user->addColumn('id', 'integer', ['autoincrement' => true]);
        $user->setPrimaryKey(['id']);
        $user->addColumn('email', 'string', ['length' => 180]);
        $user->addUniqueIndex(['email'], 'UNIQ_IDENTIFIER_EMAIL');
        $user->addColumn('roles', 'json');
        $user->addColumn('password', 'string', ['length' => 255]);
        $user->addColumn('first_name', 'string', ['length' => 100]);
        $user->addColumn('last_name', 'string', ['length' => 100]);
        $user->addColumn('phone', 'string', ['length' => 20]);
        $project = $baseline->createTable('project');
        $project->addColumn('id', 'integer', ['autoincrement' => true]);
        $project->setPrimaryKey(['id']);
        foreach (['name', 'label', 'address'] as $field) {
            $project->addColumn($field, 'string', ['length' => 255]);
        }
        $project->addColumn('number_of_floors', 'integer');
        $project->addColumn('postal_code', 'string', ['length' => 6]);
        $project->addColumn('delivery_date', 'datetime', ['notnull' => false]);
        $project->addColumn('picture', 'string', ['length' => 255, 'notnull' => false]);
        $project->addColumn('active', 'boolean');
        foreach ($baseline->toSql($this->connection->getDatabasePlatform()) as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(true, 'Baseline rollback would delete user/project data. Restore a verified backup instead.');
    }
}
