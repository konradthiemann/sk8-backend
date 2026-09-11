<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Schema for the body-weight history (T-0103).
 *
 * The table, plain index, foreign key and index-on-FK-column come from
 * `doctrine:migrations:diff` against `App\Entity\BodyWeight`. The two CHECK
 * constraints and the partial unique index are added by hand below, and the
 * measured_on index is switched to DESC: Doctrine's schema comparator
 * introspects neither CHECK constraints nor sort direction nor partial
 * (`WHERE`-qualified) indexes, so a later diff stays empty even though they
 * only exist here (same pattern as Version20260909100000 for the trick
 * catalog and Version20260909184514 for skate_session).
 */
final class Version20260909193000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create body_weight table with its constraints';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE body_weight (
                id UUID NOT NULL,
                measured_on DATE NOT NULL,
                measured_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                weight_kg NUMERIC(5, 2) NOT NULL,
                context TEXT NOT NULL,
                skate_session_id UUID DEFAULT NULL,
                PRIMARY KEY (id)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_body_weight_measured_on ON body_weight (measured_on DESC)');
        $this->addSql('CREATE INDEX IDX_92D1F647344261BD ON body_weight (skate_session_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_body_weight_session_context ON body_weight (skate_session_id, context) WHERE (skate_session_id IS NOT NULL)');
        $this->addSql('ALTER TABLE body_weight ADD CONSTRAINT FK_92D1F647344261BD FOREIGN KEY (skate_session_id) REFERENCES skate_session (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE body_weight ADD CONSTRAINT chk_body_weight_range CHECK (weight_kg BETWEEN 30 AND 250)');
        $this->addSql("ALTER TABLE body_weight ADD CONSTRAINT chk_body_weight_context CHECK (context IN ('morgens', 'vor_session', 'nach_session', 'sonstiges'))");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE body_weight DROP CONSTRAINT FK_92D1F647344261BD');
        $this->addSql('DROP TABLE body_weight');
    }
}
