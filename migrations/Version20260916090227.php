<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Schema for training sessions and their sets (T-0302).
 *
 * The tables, plain indexes and foreign keys come from
 * `doctrine:migrations:diff` against App\Entity\TrainingSession /
 * App\Entity\TrainingSet. The CHECK constraints and the descending date
 * index are added by hand below: Doctrine's schema comparator does not
 * introspect either, so a later diff stays empty even though they exist
 * only here (design.md §2/§4, same pattern as Version20260909184514 for
 * skate_session/session_trick).
 *
 * The generated diff also proposed `ALTER TABLE exercise ALTER
 * muscle_groups DROP DEFAULT` - unrelated drift from the deprecated
 * "jsonb" column option (see the deprecation notice `doctrine:migrations:diff`
 * printed while generating this file), not anything this ticket touches.
 * Left out on purpose: this migration is schema for training_session/
 * training_set only, no unrelated changes.
 */
final class Version20260916090227 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create training_session and training_set tables with their constraints';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE training_session (
                id UUID NOT NULL,
                session_date DATE NOT NULL,
                duration_minutes SMALLINT NOT NULL,
                perceived_exertion SMALLINT DEFAULT NULL,
                knee_pain SMALLINT DEFAULT NULL,
                notes TEXT DEFAULT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_training_session_date ON training_session (session_date DESC)');
        $this->addSql('ALTER TABLE training_session ADD CONSTRAINT chk_training_session_duration CHECK (duration_minutes BETWEEN 1 AND 600)');
        $this->addSql('ALTER TABLE training_session ADD CONSTRAINT chk_training_session_exertion CHECK (perceived_exertion IS NULL OR perceived_exertion BETWEEN 1 AND 10)');
        $this->addSql('ALTER TABLE training_session ADD CONSTRAINT chk_training_session_knee_pain CHECK (knee_pain IS NULL OR knee_pain BETWEEN 0 AND 10)');

        $this->addSql(<<<'SQL'
            CREATE TABLE training_set (
                id UUID NOT NULL,
                training_session_id UUID NOT NULL,
                exercise_id UUID NOT NULL,
                set_number SMALLINT NOT NULL,
                reps SMALLINT DEFAULT NULL,
                seconds SMALLINT DEFAULT NULL,
                side TEXT DEFAULT NULL,
                PRIMARY KEY (id)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_training_set_session ON training_set (training_session_id)');
        $this->addSql('CREATE INDEX IDX_7CAA5295E934951A ON training_set (exercise_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_training_set_session_exercise_number ON training_set (training_session_id, exercise_id, set_number)');
        $this->addSql('ALTER TABLE training_set ADD CONSTRAINT FK_7CAA5295DB8156B9 FOREIGN KEY (training_session_id) REFERENCES training_session (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE training_set ADD CONSTRAINT FK_7CAA5295E934951A FOREIGN KEY (exercise_id) REFERENCES exercise (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE training_set ADD CONSTRAINT chk_training_set_number CHECK (set_number BETWEEN 1 AND 20)');
        $this->addSql('ALTER TABLE training_set ADD CONSTRAINT chk_training_set_reps CHECK (reps IS NULL OR reps BETWEEN 1 AND 999)');
        $this->addSql('ALTER TABLE training_set ADD CONSTRAINT chk_training_set_seconds CHECK (seconds IS NULL OR seconds BETWEEN 1 AND 3600)');
        $this->addSql("ALTER TABLE training_set ADD CONSTRAINT chk_training_set_side CHECK (side IS NULL OR side IN ('links', 'rechts'))");
        $this->addSql('ALTER TABLE training_set ADD CONSTRAINT chk_training_set_reps_xor_seconds CHECK ((reps IS NOT NULL) <> (seconds IS NOT NULL))');
    }

    public function down(Schema $schema): void
    {
        // training_set first (FK to training_session), then training_session.
        $this->addSql('ALTER TABLE training_set DROP CONSTRAINT FK_7CAA5295DB8156B9');
        $this->addSql('ALTER TABLE training_set DROP CONSTRAINT FK_7CAA5295E934951A');
        $this->addSql('DROP TABLE training_set');
        $this->addSql('DROP TABLE training_session');
    }
}
