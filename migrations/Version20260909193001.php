<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

/**
 * Backfills `body_weight` rows for every `skate_session` that already
 * carries a weight but predates T-0103 (design.md §2). Data migration,
 * deliberately separate from the schema migration (Version20260909193000)
 * so schema and data changes never mix in one step.
 *
 * Computed entirely in SQL (no access to the injected `%app.timezone%`
 * parameter here): `session_date + INTERVAL '12 hours'` is the ticket's own
 * prescribed substitute base timestamp, `started_at` preferred when present -
 * the same derivation rule as App\Service\Body\BodyWeightSynchronizer, but
 * without a timezone conversion (design.md §8, "Timezone-Drift" risk,
 * accepted as specified by the ticket). IDs come from PHP-side `Uuid::v7()`
 * like Version20260909100001, because Postgres has no v7 generator without an
 * extension. `uniq_body_weight_session_context` (from the schema migration)
 * guards against silent duplicates if this migration ever ran twice.
 */
final class Version20260909193001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Backfill body_weight rows for existing skate_session weights';
    }

    public function up(Schema $schema): void
    {
        /**
         * @var list<array{
         *     id: string,
         *     session_date: string,
         *     weight_before_kg: string|null,
         *     weight_after_kg: string|null,
         *     before_at: string,
         *     after_at: string,
         * }> $rows
         */
        $rows = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT
                id,
                session_date,
                weight_before_kg,
                weight_after_kg,
                COALESCE(started_at, session_date + INTERVAL '12 hours')                                   AS before_at,
                COALESCE(started_at, session_date + INTERVAL '12 hours')
                    + (duration_minutes || ' minutes')::interval                                            AS after_at
            FROM skate_session
            WHERE weight_before_kg IS NOT NULL OR weight_after_kg IS NOT NULL
            SQL);

        foreach ($rows as $row) {
            if (null !== $row['weight_before_kg']) {
                $this->insertBodyWeight($row['id'], $row['session_date'], $row['before_at'], $row['weight_before_kg'], 'vor_session');
            }
            if (null !== $row['weight_after_kg']) {
                $this->insertBodyWeight($row['id'], $row['session_date'], $row['after_at'], $row['weight_after_kg'], 'nach_session');
            }
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DELETE FROM body_weight WHERE skate_session_id IS NOT NULL');
    }

    private function insertBodyWeight(string $sessionId, string $measuredOn, string $measuredAt, string $weightKg, string $context): void
    {
        $this->addSql(
            'INSERT INTO body_weight (id, measured_on, measured_at, weight_kg, context, skate_session_id) VALUES (?, ?, ?, ?, ?, ?)',
            [Uuid::v7()->toRfc4122(), $measuredOn, $measuredAt, $weightKg, $context, $sessionId],
            ['string', 'string', 'string', 'string', 'string', 'string'],
        );
    }
}
