<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Schema for the derived trick-progress projection (T-0201).
 *
 * The table, plain index, foreign key and index-on-FK-column come from
 * `doctrine:migrations:diff` against `App\Entity\TrickProgress`. The three
 * CHECK constraints are added by hand below: Doctrine's schema comparator
 * does not introspect CHECK constraints, so a later diff stays empty even
 * though they only exist here (same pattern as Version20260909193000 for
 * body_weight).
 *
 * Schema-only, no data migration: `trick_progress` is a projection that
 * rebuilds itself from `session_trick` on the first `GET /api/trick-tree`
 * (design.md §2, "Keine Datenmigration").
 */
final class Version20260910060000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create trick_progress table with its constraints';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE trick_progress (
                id UUID NOT NULL,
                status TEXT NOT NULL,
                first_landed_on DATE DEFAULT NULL,
                landed_total INT NOT NULL DEFAULT 0,
                attempts_total INT NOT NULL DEFAULT 0,
                updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                trick_id UUID NOT NULL,
                PRIMARY KEY (id)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_trick_progress_status ON trick_progress (status)');
        $this->addSql('CREATE UNIQUE INDEX uniq_trick_progress_trick ON trick_progress (trick_id)');
        $this->addSql('ALTER TABLE trick_progress ADD CONSTRAINT FK_47D276F9B281BE2E FOREIGN KEY (trick_id) REFERENCES trick (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql("ALTER TABLE trick_progress ADD CONSTRAINT chk_trick_progress_status CHECK (status IN ('gesperrt', 'bereit', 'uebe', 'sitzt'))");
        $this->addSql('ALTER TABLE trick_progress ADD CONSTRAINT chk_trick_progress_landed CHECK (landed_total >= 0)');
        $this->addSql('ALTER TABLE trick_progress ADD CONSTRAINT chk_trick_progress_attempts CHECK (attempts_total >= landed_total)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE trick_progress DROP CONSTRAINT FK_47D276F9B281BE2E');
        $this->addSql('DROP TABLE trick_progress');
    }
}
