<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Raw UX telemetry events collected from the frontends (ADR-009).
 */
final class Version20260907093709 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create ux_event table with indexes on (app, occurred_at) and (type)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE ux_event (
                id UUID NOT NULL,
                app TEXT NOT NULL,
                session_id UUID NOT NULL,
                type TEXT NOT NULL,
                screen TEXT NOT NULL,
                target TEXT DEFAULT NULL,
                meta JSONB NOT NULL,
                occurred_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                received_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_ux_event_app_occurred_at ON ux_event (app, occurred_at)');
        $this->addSql('CREATE INDEX idx_ux_event_type ON ux_event (type)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE ux_event');
    }
}
