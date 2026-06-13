<?php

declare(strict_types=1);

namespace Pixiekat\LuminaUiBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds is_active + created_at to the users table, matching the EntityActiveTrait
 * and EntityCreatedAtTrait that were added to App\Entity\User after the table was
 * first created.
 *
 * Narrated + idempotent (hasColumn guards). created_at is NOT NULL with no
 * default — safe because users is currently empty; if you ever apply this to a
 * populated table, backfill the column (or add a default) before the NOT NULL.
 */
final class Version20260613190500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add is_active + created_at to users (User active/createdAt traits).';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('users')) {
            $this->write('↷ users table missing — skipping.');
            return;
        }
        $users = $schema->getTable('users');

        if ($users->hasColumn('is_active')) {
            $this->write('↷ users.is_active already exists — skipping.');
        } else {
            $this->write('✚ Adding users.is_active …');
            $this->addSql('ALTER TABLE users ADD is_active BOOLEAN DEFAULT true NOT NULL');
        }

        if ($users->hasColumn('created_at')) {
            $this->write('↷ users.created_at already exists — skipping.');
        } else {
            $this->write('✚ Adding users.created_at …');
            $this->addSql('ALTER TABLE users ADD created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('users')) {
            $this->write('↷ users table missing — nothing to revert.');
            return;
        }
        $users = $schema->getTable('users');

        if ($users->hasColumn('created_at')) {
            $this->write('✖ Dropping users.created_at …');
            $this->addSql('ALTER TABLE users DROP created_at');
        }
        if ($users->hasColumn('is_active')) {
            $this->write('✖ Dropping users.is_active …');
            $this->addSql('ALTER TABLE users DROP is_active');
        }
    }
}
