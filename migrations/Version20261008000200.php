<?php

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261008000200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Normalize email identifiers and introduce nullable project ownership for legacy assignment.';
    }

    public function up(Schema $schema): void
    {
        $users = $this->connection->fetchAllAssociative('SELECT id, email FROM user');
        $seen = [];
        foreach ($users as $user) {
            $email = mb_strtolower(trim($user['email']));
            $this->abortIf(isset($seen[$email]), 'Normalized email collision: resolve duplicate accounts explicitly before retrying.');
            $seen[$email] = true;
        }
        foreach ($users as $user) {
            $this->addSql('UPDATE user SET email = ? WHERE id = ?', [mb_strtolower(trim($user['email'])), $user['id']]);
        }
        $target = clone $schema;
        $project = $target->getTable('project');
        $project->addColumn('owner_id', 'integer', ['notnull' => false]);
        $project->addIndex(['owner_id'], 'IDX_2FB3D0EE7E3C61F9');
        $project->addIndex(['owner_id', 'active', 'id'], 'IDX_PROJECT_OWNER_ACTIVE_ID');
        $project->addForeignKeyConstraint('user', ['owner_id'], ['id'], [], 'FK_2FB3D0EE7E3C61F9');
        $diff = $this->connection->createSchemaManager()->createComparator()->compareSchemas($schema, $target);
        foreach ($this->connection->getDatabasePlatform()->getAlterSchemaSQL($diff) as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(true, 'Rollback would discard ownership. Restore a verified backup instead.');
    }
}
