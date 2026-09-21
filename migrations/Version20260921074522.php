<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Schema for fitness assessments (T-0303).
 *
 * The table and the unique index on assessed_on come from
 * `doctrine:migrations:diff` against App\Entity\FitnessAssessment. The eight
 * CHECK constraints are added by hand below: Doctrine's schema comparator
 * does not introspect them, so a later diff stays empty even though they
 * exist only here (design.md §2, same pattern as Version20260916090227).
 * Their bounds mirror the Range constraints of
 * App\Dto\Training\FitnessAssessmentRequest and must change together.
 *
 * The generated diff also proposed `ALTER TABLE exercise ALTER
 * muscle_groups DROP DEFAULT` - the same unrelated drift from the
 * deprecated "jsonb" column option that Version20260916090227 already
 * documents. Left out on purpose (and its reverse from down()): this
 * migration is schema for fitness_assessment only.
 */
final class Version20260921074522 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create fitness_assessment table with its unique date index and range constraints';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE fitness_assessment (
                id UUID NOT NULL,
                assessed_on DATE NOT NULL,
                push_ups_max SMALLINT DEFAULT NULL,
                squats_max SMALLINT DEFAULT NULL,
                ring_pull_ups_max SMALLINT DEFAULT NULL,
                plank_seconds SMALLINT DEFAULT NULL,
                single_leg_balance_left_seconds SMALLINT DEFAULT NULL,
                single_leg_balance_right_seconds SMALLINT DEFAULT NULL,
                wall_sit_seconds SMALLINT DEFAULT NULL,
                standing_broad_jump_cm SMALLINT DEFAULT NULL,
                notes TEXT DEFAULT NULL,
                PRIMARY KEY (id)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_fitness_assessment_assessed_on ON fitness_assessment (assessed_on)');
        $this->addSql('ALTER TABLE fitness_assessment ADD CONSTRAINT chk_fitness_assessment_push_ups CHECK (push_ups_max IS NULL OR push_ups_max BETWEEN 0 AND 500)');
        $this->addSql('ALTER TABLE fitness_assessment ADD CONSTRAINT chk_fitness_assessment_squats CHECK (squats_max IS NULL OR squats_max BETWEEN 0 AND 1000)');
        $this->addSql('ALTER TABLE fitness_assessment ADD CONSTRAINT chk_fitness_assessment_ring_pull_ups CHECK (ring_pull_ups_max IS NULL OR ring_pull_ups_max BETWEEN 0 AND 200)');
        $this->addSql('ALTER TABLE fitness_assessment ADD CONSTRAINT chk_fitness_assessment_plank CHECK (plank_seconds IS NULL OR plank_seconds BETWEEN 0 AND 3600)');
        $this->addSql('ALTER TABLE fitness_assessment ADD CONSTRAINT chk_fitness_assessment_balance_left CHECK (single_leg_balance_left_seconds IS NULL OR single_leg_balance_left_seconds BETWEEN 0 AND 3600)');
        $this->addSql('ALTER TABLE fitness_assessment ADD CONSTRAINT chk_fitness_assessment_balance_right CHECK (single_leg_balance_right_seconds IS NULL OR single_leg_balance_right_seconds BETWEEN 0 AND 3600)');
        $this->addSql('ALTER TABLE fitness_assessment ADD CONSTRAINT chk_fitness_assessment_wall_sit CHECK (wall_sit_seconds IS NULL OR wall_sit_seconds BETWEEN 0 AND 3600)');
        $this->addSql('ALTER TABLE fitness_assessment ADD CONSTRAINT chk_fitness_assessment_broad_jump CHECK (standing_broad_jump_cm IS NULL OR standing_broad_jump_cm BETWEEN 0 AND 400)');
    }

    public function down(Schema $schema): void
    {
        // The unique index and the CHECK constraints go away with the table.
        $this->addSql('DROP TABLE fitness_assessment');
    }
}
