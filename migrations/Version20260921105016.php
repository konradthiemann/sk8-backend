<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Schema for the habit catalog (T-0401).
 *
 * The table, its unique slug index and the (is_active, sort_order) index come
 * from `doctrine:migrations:diff` against App\Entity\Habit. The four CHECK
 * constraints are added by hand below: Doctrine's schema comparator does not
 * introspect them, so a later diff stays empty even though they exist only
 * here (same pattern as Version20260916075306). Their rules mirror
 * App\Service\Habit\HabitDefinitionValidator and must change together.
 *
 * The generated diff also proposed `ALTER TABLE exercise ALTER muscle_groups
 * DROP DEFAULT` (unrelated drift from the deprecated "jsonb" column option,
 * already documented in Version20260921074522) and, depending on the
 * database, `DROP TABLE messenger_messages` (transport table without an
 * entity). Both are left out on purpose, together with their reverse in down().
 *
 * Executes each statement directly via `$this->connection` instead of
 * `$this->addSql()`: tests/Functional/Migration/HabitSchemaTest runs down()
 * and up() on the same migration object within one process, and
 * Doctrine\Migrations\AbstractMigration::freeze() permanently blocks any
 * later addSql() call on that instance (FrozenMigration). The DDL is
 * identical either way (same approach as Version20260916075306).
 */
final class Version20260921105016 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create habit table with its indexes and check constraints';
    }

    public function up(Schema $schema): void
    {
        $this->connection->executeStatement(<<<'SQL'
            CREATE TABLE habit (
                id UUID NOT NULL,
                slug TEXT NOT NULL,
                name TEXT NOT NULL,
                value_type TEXT NOT NULL,
                unit TEXT DEFAULT NULL,
                scale_min SMALLINT DEFAULT NULL,
                scale_max SMALLINT DEFAULT NULL,
                target_direction TEXT DEFAULT NULL,
                target_value NUMERIC(8, 2) DEFAULT NULL,
                sort_order SMALLINT NOT NULL,
                is_active BOOLEAN DEFAULT true NOT NULL,
                PRIMARY KEY (id)
            )
            SQL);
        $this->connection->executeStatement('CREATE UNIQUE INDEX uniq_habit_slug ON habit (slug)');
        $this->connection->executeStatement('CREATE INDEX idx_habit_active_sort ON habit (is_active, sort_order)');
        $this->connection->executeStatement(<<<'SQL'
            ALTER TABLE habit ADD CONSTRAINT chk_habit_scale CHECK (
                (value_type = 'scale' AND scale_min IS NOT NULL AND scale_max IS NOT NULL AND scale_min < scale_max)
                OR (value_type <> 'scale' AND scale_min IS NULL AND scale_max IS NULL)
            )
            SQL);
        $this->connection->executeStatement("ALTER TABLE habit ADD CONSTRAINT chk_habit_duration_unit CHECK (value_type <> 'duration' OR unit IS NOT NULL)");
        $this->connection->executeStatement('ALTER TABLE habit ADD CONSTRAINT chk_habit_target CHECK (target_value IS NULL OR target_direction IS NOT NULL)');
        $this->connection->executeStatement('ALTER TABLE habit ADD CONSTRAINT chk_habit_sort_order CHECK (sort_order >= 0)');
    }

    public function down(Schema $schema): void
    {
        // The indexes and the CHECK constraints go away with the table.
        $this->connection->executeStatement('DROP TABLE habit');
    }
}
