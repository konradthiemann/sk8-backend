<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Migration\ReplayableMigration;
use Doctrine\DBAL\Schema\Schema;

/**
 * Schema for the habit entries (T-0402).
 *
 * The table, the foreign key and the unique index come from
 * `doctrine:migrations:diff` against App\Entity\HabitEntry. Added by hand:
 * the descending order of `idx_habit_entry_date` (Doctrine cannot map DESC,
 * same pattern as `idx_skate_session_date` in Version20260909184514) and the
 * CHECK constraint `chk_habit_entry_value` (exactly one of the two values; the
 * schema comparator does not introspect check constraints, so a later diff
 * stays empty). The IDX_ index on `habit_id` is Doctrine's own foreign key
 * index, redundant to the unique index but not avoidable.
 *
 * The generated diff also proposed `ALTER TABLE exercise ALTER muscle_groups
 * DROP DEFAULT` (unrelated drift from the deprecated "jsonb" column option,
 * see Version20260921074522) and, depending on the database, `DROP TABLE
 * messenger_messages` (transport table without an entity). Both are left out
 * on purpose, together with their reverse in down().
 *
 * Unlike Version20260921105016 this migration uses addSql() through
 * ReplayableMigration: `migrate --dry-run` and `--write-sql` therefore write
 * nothing, and the down-then-up round trip of HabitSchemaTest still works.
 */
final class Version20260921123744 extends ReplayableMigration
{
    public function getDescription(): string
    {
        return 'Create habit_entry table with its unique day index and value check';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE habit_entry (id UUID NOT NULL, entry_date DATE NOT NULL, value_numeric NUMERIC(8, 2) DEFAULT NULL, value_bool BOOLEAN DEFAULT NULL, note TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, habit_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_habit_entry_habit_date ON habit_entry (habit_id, entry_date)');
        $this->addSql('CREATE INDEX idx_habit_entry_date ON habit_entry (entry_date DESC)');
        $this->addSql('CREATE INDEX IDX_A789F1AE7AEB3B2 ON habit_entry (habit_id)');
        $this->addSql('ALTER TABLE habit_entry ADD CONSTRAINT FK_A789F1AE7AEB3B2 FOREIGN KEY (habit_id) REFERENCES habit (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE habit_entry ADD CONSTRAINT chk_habit_entry_value CHECK ((value_numeric IS NOT NULL) <> (value_bool IS NOT NULL))');
    }

    public function down(Schema $schema): void
    {
        // The indexes, the foreign key and the CHECK constraint go away with the table.
        $this->addSql('DROP TABLE habit_entry');
    }
}
