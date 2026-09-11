<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Schema for the trick catalog and its prerequisite graph (T-0101).
 *
 * The tables, plain indexes and foreign keys come from `doctrine:migrations:diff`
 * against `App\Entity\Trick` / `App\Entity\TrickPrerequisite`. The four CHECK
 * constraints and the partial unique index are added by hand below: Doctrine's
 * schema comparator does not introspect either, so a later diff stays empty
 * even though they exist only here (see design.md §2/§4).
 */
final class Version20260909100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create trick and trick_prerequisite tables with their constraints';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE trick (
                id UUID NOT NULL,
                slug TEXT NOT NULL,
                name TEXT NOT NULL,
                category TEXT NOT NULL,
                difficulty SMALLINT NOT NULL,
                description TEXT DEFAULT NULL,
                is_goal BOOLEAN NOT NULL,
                goal_order SMALLINT DEFAULT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_trick_difficulty ON trick (difficulty)');
        $this->addSql('CREATE UNIQUE INDEX uniq_trick_slug ON trick (slug)');
        $this->addSql('CREATE UNIQUE INDEX uniq_trick_goal_order ON trick (goal_order) WHERE (goal_order IS NOT NULL)');
        $this->addSql("ALTER TABLE trick ADD CONSTRAINT chk_trick_category CHECK (category IN ('flat', 'rotation', 'balance', 'slide', 'grind', 'air'))");
        $this->addSql('ALTER TABLE trick ADD CONSTRAINT chk_trick_difficulty CHECK (difficulty BETWEEN 1 AND 10)');
        $this->addSql('ALTER TABLE trick ADD CONSTRAINT chk_trick_goal CHECK ((is_goal AND goal_order BETWEEN 1 AND 7) OR (NOT is_goal AND goal_order IS NULL))');

        $this->addSql(<<<'SQL'
            CREATE TABLE trick_prerequisite (
                id UUID NOT NULL,
                trick_id UUID NOT NULL,
                requires_trick_id UUID NOT NULL,
                PRIMARY KEY (id)
            )
            SQL);
        $this->addSql('CREATE INDEX IDX_71744CA3B281BE2E ON trick_prerequisite (trick_id)');
        $this->addSql('CREATE INDEX idx_trick_prerequisite_requires ON trick_prerequisite (requires_trick_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_trick_prerequisite ON trick_prerequisite (trick_id, requires_trick_id)');
        $this->addSql('ALTER TABLE trick_prerequisite ADD CONSTRAINT FK_71744CA3B281BE2E FOREIGN KEY (trick_id) REFERENCES trick (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE trick_prerequisite ADD CONSTRAINT FK_71744CA3ECB60728 FOREIGN KEY (requires_trick_id) REFERENCES trick (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE trick_prerequisite ADD CONSTRAINT chk_trick_prerequisite_self CHECK (trick_id <> requires_trick_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE trick_prerequisite DROP CONSTRAINT FK_71744CA3B281BE2E');
        $this->addSql('ALTER TABLE trick_prerequisite DROP CONSTRAINT FK_71744CA3ECB60728');
        $this->addSql('DROP TABLE trick_prerequisite');
        $this->addSql('DROP TABLE trick');
    }
}
