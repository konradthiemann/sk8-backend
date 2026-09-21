<?php

declare(strict_types=1);

namespace App\Migration;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Type;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\FrozenMigration;
use Doctrine\Migrations\Query\Query;

/**
 * Base class for migrations that write their SQL with addSql() and can still
 * be run twice on the same object (T-0402 design.md §2).
 *
 * addSql() is what makes `migrate --dry-run` and `--write-sql` harmless: the
 * statements are only recorded, the executor runs them afterwards (or not).
 * The catch is that Doctrine freezes a migration object after its run, and a
 * frozen one refuses addSql() with FrozenMigration. The round trip test of
 * the habit schema (HabitSchemaTest) runs down() and up() of the same object
 * in one process, which a plain addSql() migration cannot survive.
 *
 * So the first run records like any migration. Once the object is frozen, the
 * statement is executed directly on the connection instead, and the object
 * then reports nothing as planned: Doctrine's executor asks getSql() after
 * every run, and the statements planned by the first run must not be executed
 * a second time. In production one process runs one migration once, so only
 * the recording path is ever taken.
 */
abstract class ReplayableMigration extends AbstractMigration
{
    private bool $replayed = false;

    /**
     * @param mixed[] $params
     * @param mixed[] $types
     */
    protected function addSql(string $sql, array $params = [], array $types = []): void
    {
        try {
            parent::addSql($sql, $params, $types);
        } catch (FrozenMigration) {
            // Control flow by exception on purpose: the frozen state is private to the parent class.
            $this->replayed = true;
            $this->connection->executeStatement($sql, self::parameters($params), self::types($types));
        }
    }

    /**
     * @return Query[]
     */
    public function getSql(): array
    {
        return $this->replayed ? [] : parent::getSql();
    }

    /**
     * @param mixed[] $params
     *
     * @return array<int<0, max>|string, mixed>
     */
    private static function parameters(array $params): array
    {
        $checked = [];
        foreach ($params as $key => $value) {
            if (\is_int($key) && $key < 0) {
                throw new \InvalidArgumentException('Statement parameters are indexed from 0 or named.');
            }

            $checked[$key] = $value;
        }

        return $checked;
    }

    /**
     * @param mixed[] $types
     *
     * @return array<int<0, max>|string, ArrayParameterType|ParameterType|Type|string>
     */
    private static function types(array $types): array
    {
        $checked = [];
        foreach ($types as $key => $type) {
            if ((\is_int($key) && $key < 0) || !($type instanceof ArrayParameterType || $type instanceof ParameterType || $type instanceof Type || \is_string($type))) {
                throw new \InvalidArgumentException('Statement types must be indexed from 0 or named and be DBAL types.');
            }

            $checked[$key] = $type;
        }

        return $checked;
    }
}
