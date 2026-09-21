<?php

declare(strict_types=1);

namespace App\Tests\Functional\Migration;

use App\Migration\ReplayableMigration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\Query\Query;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Verifies the contract of `App\Migration\ReplayableMigration` (T-0402
 * design.md §2) with a throw-away subclass, independent of any real
 * migration: the first run only records its statements through `addSql()`
 * (so a dry run and `--write-sql` stay harmless), and once the migration
 * object is frozen - Doctrine freezes it after a run, and HabitSchemaTest runs
 * the same object a second time - `addSql()` executes the statement directly
 * instead of throwing `FrozenMigration`.
 *
 * Runs inside dama's per-test transaction: Postgres DDL is transactional, so
 * the probe table disappears with the rollback.
 */
final class ReplayableMigrationTest extends KernelTestCase
{
    public const string PROBE_TABLE = 'replayable_migration_probe';

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function testItOnlyRecordsItsStatementsOnTheFirstRun(): void
    {
        $migration = $this->probeMigration();

        $migration->up(new Schema());

        self::assertFalse($this->probeTableExists(), 'the first run must not write anything, it only plans');
        $planned = array_map(static fn (Query $query): string => $query->getStatement(), $migration->getSql());
        self::assertCount(2, $planned);
        self::assertStringContainsString('CREATE TABLE '.self::PROBE_TABLE, $planned[0]);
        self::assertStringContainsString('INSERT INTO '.self::PROBE_TABLE, $planned[1]);
    }

    public function testItExecutesTheStatementsDirectlyOnceTheMigrationIsFrozen(): void
    {
        $migration = $this->probeMigration();
        $migration->up(new Schema());
        $migration->freeze();

        $migration->up(new Schema());

        self::assertTrue($this->probeTableExists(), 'a frozen migration must run its statements directly');
        self::assertSame(
            ['replayed'],
            $this->connection()->fetchFirstColumn('SELECT label FROM '.self::PROBE_TABLE),
            'the parameters of the statement must reach the database',
        );
    }

    public function testItReplaysDownAfterUpOnTheSameFrozenObject(): void
    {
        // The round trip of HabitSchemaTest: up() and down() of one object in one process.
        $migration = $this->probeMigration();
        $migration->up(new Schema());
        $migration->down(new Schema());
        $migration->freeze();

        $migration->up(new Schema());
        self::assertTrue($this->probeTableExists());

        $migration->down(new Schema());
        self::assertFalse($this->probeTableExists(), 'down() must drop the table again');
    }

    public function testItPlansNothingAfterAReplayHasAlreadyExecutedDirectly(): void
    {
        // Doctrine's executor runs `getSql()` after `up()`/`down()`. The statements planned by the first run
        // are still on the object; if a replay executed directly AND left them there, the executor would run
        // them a second time (here: the CREATE TABLE would fail with "relation already exists", and after a
        // down() the stale DROP TABLE would remove what the replayed up() just created).
        $migration = $this->probeMigration();
        $migration->up(new Schema());
        $migration->freeze();

        $migration->up(new Schema());

        self::assertSame([], $migration->getSql(), 'a replay executes directly, so nothing may be left to execute afterwards');
    }

    public function testItDoesNotThrowFrozenMigrationWhenAFrozenMigrationRunsAgain(): void
    {
        $migration = $this->probeMigration();
        $migration->freeze();

        $migration->up(new Schema());
        $migration->down(new Schema());
        $migration->up(new Schema());

        self::assertTrue($this->probeTableExists());
    }

    private function probeMigration(): ReplayableMigration
    {
        return new class($this->connection(), new NullLogger()) extends ReplayableMigration {
            public function up(Schema $schema): void
            {
                $this->addSql('CREATE TABLE '.ReplayableMigrationTest::PROBE_TABLE.' (label TEXT NOT NULL)');
                $this->addSql('INSERT INTO '.ReplayableMigrationTest::PROBE_TABLE.' (label) VALUES (?)', ['replayed']);
            }

            public function down(Schema $schema): void
            {
                $this->addSql('DROP TABLE '.ReplayableMigrationTest::PROBE_TABLE);
            }
        };
    }

    private function probeTableExists(): bool
    {
        return $this->connection()->createSchemaManager()->tablesExist([self::PROBE_TABLE]);
    }

    private function connection(): Connection
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager->getConnection();
    }
}
