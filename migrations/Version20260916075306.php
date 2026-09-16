<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Schema for the training exercise catalog (T-0301).
 *
 * The table and its plain indexes come from `doctrine:migrations:generate`
 * against App\Entity\Exercise. The three CHECK constraints are added by
 * hand below: Doctrine's schema comparator does not introspect them, so a
 * later diff stays empty even though they exist only here - same pattern as
 * Version20260909100000 for `trick` (T-0301 design.md §2/§4).
 *
 * Executes each statement directly via `$this->connection` instead of
 * `$this->addSql()`: tests/Functional/Migration/ExerciseMigrationTest runs
 * this migration's down() and up() twice each, on the same object, within
 * one process (down to reverse, then up again to restore, design.md §4).
 * Doctrine\Migrations\AbstractMigration::freeze() is called unconditionally
 * after every execution and permanently blocks any later addSql() call on
 * that same instance (Doctrine\Migrations\Exception\FrozenMigration) -
 * addSql() is the only place that checks it. Running statements straight
 * against the connection produces the identical DDL without touching that
 * queue, so the same migration object stays replayable. Result and content
 * are unaffected either way; only the delivery mechanism differs.
 */
final class Version20260916075306 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create exercise table with its constraints';
    }

    public function up(Schema $schema): void
    {
        $this->connection->executeStatement(<<<'SQL'
            CREATE TABLE exercise (
                id UUID NOT NULL,
                slug TEXT NOT NULL,
                name TEXT NOT NULL,
                equipment TEXT NOT NULL,
                muscle_groups JSONB NOT NULL DEFAULT '[]',
                knee_load TEXT NOT NULL,
                measure TEXT NOT NULL,
                description TEXT DEFAULT NULL,
                is_prevention BOOLEAN NOT NULL,
                PRIMARY KEY (id)
            )
            SQL);
        $this->connection->executeStatement('CREATE UNIQUE INDEX uniq_exercise_slug ON exercise (slug)');
        $this->connection->executeStatement('CREATE INDEX idx_exercise_knee_load ON exercise (knee_load)');
        $this->connection->executeStatement("ALTER TABLE exercise ADD CONSTRAINT chk_exercise_equipment CHECK (equipment IN ('bodyweight', 'rings'))");
        $this->connection->executeStatement("ALTER TABLE exercise ADD CONSTRAINT chk_exercise_knee_load CHECK (knee_load IN ('keine', 'niedrig', 'mittel', 'hoch'))");
        $this->connection->executeStatement("ALTER TABLE exercise ADD CONSTRAINT chk_exercise_measure CHECK (measure IN ('reps', 'seconds', 'reps_per_side', 'seconds_per_side'))");
    }

    public function down(Schema $schema): void
    {
        $this->connection->executeStatement('DROP TABLE exercise');
    }
}
