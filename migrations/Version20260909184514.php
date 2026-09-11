<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Schema for skate sessions and their practiced-trick rows (T-0102).
 *
 * The tables, plain indexes and foreign keys come from `doctrine:migrations:diff`
 * against `App\Entity\SkateSession` / `App\Entity\SessionTrick`. The CHECK
 * constraints and the descending date index are added by hand below:
 * Doctrine's schema comparator does not introspect either, so a later diff
 * stays empty even though they exist only here (see design.md §2/§4.4,
 * same pattern as Version20260909100000 for the trick catalog).
 *
 * Note: design.md's prose says "six" CHECK constraints, but its own data
 * model table (and the ticket text it is taken from) names seven -
 * five on skate_session, two on session_trick. All seven are created here;
 * see impl.md for this discrepancy.
 */
final class Version20260909184514 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create skate_session and session_trick tables with their constraints';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE skate_session (
                id UUID NOT NULL,
                session_date DATE NOT NULL,
                started_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                duration_minutes SMALLINT NOT NULL,
                location TEXT NOT NULL,
                weight_before_kg NUMERIC(5, 2) DEFAULT NULL,
                weight_after_kg NUMERIC(5, 2) DEFAULT NULL,
                perceived_exertion SMALLINT DEFAULT NULL,
                knee_pain SMALLINT DEFAULT NULL,
                notes TEXT DEFAULT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_skate_session_date ON skate_session (session_date DESC)');
        $this->addSql('ALTER TABLE skate_session ADD CONSTRAINT chk_skate_session_duration CHECK (duration_minutes BETWEEN 1 AND 600)');
        $this->addSql('ALTER TABLE skate_session ADD CONSTRAINT chk_skate_session_weight_before CHECK (weight_before_kg IS NULL OR weight_before_kg BETWEEN 30 AND 250)');
        $this->addSql('ALTER TABLE skate_session ADD CONSTRAINT chk_skate_session_weight_after CHECK (weight_after_kg IS NULL OR weight_after_kg BETWEEN 30 AND 250)');
        $this->addSql('ALTER TABLE skate_session ADD CONSTRAINT chk_skate_session_exertion CHECK (perceived_exertion IS NULL OR perceived_exertion BETWEEN 1 AND 10)');
        $this->addSql('ALTER TABLE skate_session ADD CONSTRAINT chk_skate_session_knee_pain CHECK (knee_pain IS NULL OR knee_pain BETWEEN 0 AND 10)');

        $this->addSql(<<<'SQL'
            CREATE TABLE session_trick (
                id UUID NOT NULL,
                skate_session_id UUID NOT NULL,
                trick_id UUID NOT NULL,
                attempts SMALLINT NOT NULL,
                landed SMALLINT NOT NULL,
                notes TEXT DEFAULT NULL,
                PRIMARY KEY (id)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_session_trick_session ON session_trick (skate_session_id)');
        $this->addSql('CREATE INDEX IDX_38D11C8B281BE2E ON session_trick (trick_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_session_trick ON session_trick (skate_session_id, trick_id)');
        $this->addSql('ALTER TABLE session_trick ADD CONSTRAINT FK_38D11C8344261BD FOREIGN KEY (skate_session_id) REFERENCES skate_session (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE session_trick ADD CONSTRAINT FK_38D11C8B281BE2E FOREIGN KEY (trick_id) REFERENCES trick (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE session_trick ADD CONSTRAINT chk_session_trick_attempts CHECK (attempts BETWEEN 1 AND 999)');
        $this->addSql('ALTER TABLE session_trick ADD CONSTRAINT chk_session_trick_landed CHECK (landed >= 0 AND landed <= attempts)');
    }

    public function down(Schema $schema): void
    {
        // session_trick first (FK to skate_session), then skate_session.
        $this->addSql('ALTER TABLE session_trick DROP CONSTRAINT FK_38D11C8344261BD');
        $this->addSql('ALTER TABLE session_trick DROP CONSTRAINT FK_38D11C8B281BE2E');
        $this->addSql('DROP TABLE session_trick');
        $this->addSql('DROP TABLE skate_session');
    }
}
