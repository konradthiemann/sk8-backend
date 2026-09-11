<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

/**
 * Seeds the fixed trick catalog: 16 tricks (T-0101, PRODUCT-SPEC.md §3/4) and
 * their 18 direct prerequisite edges (transitive reduction, see design.md §7.2).
 *
 * Data migration, deliberately separate from the schema migration
 * (Version20260909100000) so schema and data changes never mix in one step.
 */
final class Version20260909100001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Seed the 16 catalog tricks and their 18 prerequisite edges';
    }

    public function up(Schema $schema): void
    {
        $now = $this->currentTimestamp();

        foreach ($this->tricks() as $trick) {
            $this->addSql(
                'INSERT INTO trick (id, slug, name, category, difficulty, description, is_goal, goal_order, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    Uuid::v7()->toRfc4122(),
                    $trick['slug'],
                    $trick['name'],
                    $trick['category'],
                    $trick['difficulty'],
                    $trick['description'],
                    $trick['isGoal'],
                    $trick['goalOrder'],
                    $now,
                ],
                [
                    'string', 'string', 'string', 'string', 'integer', 'string', 'boolean', 'integer', 'string',
                ],
            );
        }

        foreach ($this->edges() as [$trickSlug, $requiresSlug]) {
            $this->addSql(
                <<<'SQL'
                    INSERT INTO trick_prerequisite (id, trick_id, requires_trick_id)
                    VALUES (
                        ?,
                        (SELECT id FROM trick WHERE slug = ?),
                        (SELECT id FROM trick WHERE slug = ?)
                    )
                    SQL,
                [Uuid::v7()->toRfc4122(), $trickSlug, $requiresSlug],
                ['string', 'string', 'string'],
            );
        }
    }

    public function down(Schema $schema): void
    {
        // Deletes the 18 seeded edges first (FK), then the 16 seeded tricks by
        // slug. Not TRUNCATE, so rows added by a later migration survive.
        foreach ($this->edges() as [$trickSlug, $requiresSlug]) {
            $this->addSql(
                <<<'SQL'
                    DELETE FROM trick_prerequisite
                    WHERE trick_id = (SELECT id FROM trick WHERE slug = ?)
                      AND requires_trick_id = (SELECT id FROM trick WHERE slug = ?)
                    SQL,
                [$trickSlug, $requiresSlug],
                ['string', 'string'],
            );
        }

        foreach ($this->tricks() as $trick) {
            $this->addSql('DELETE FROM trick WHERE slug = ?', [$trick['slug']], ['string']);
        }
    }

    private function currentTimestamp(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d H:i:sP');
    }

    /**
     * @return list<array{slug: string, name: string, category: string, difficulty: int, description: string, isGoal: bool, goalOrder: int|null}>
     */
    private function tricks(): array
    {
        return [
            ['slug' => 'rolling', 'name' => 'Sicher rollen', 'category' => 'flat', 'difficulty' => 1, 'description' => 'Pushen, Richtung halten, bremsen und in der Fahrt stehen.', 'isGoal' => false, 'goalOrder' => null],
            ['slug' => 'tic-tac', 'name' => 'Tic-Tac', 'category' => 'balance', 'difficulty' => 1, 'description' => 'Nose leicht anheben und die Front im Wechsel nach links und rechts setzen.', 'isGoal' => false, 'goalOrder' => null],
            ['slug' => 'drop-in', 'name' => 'Drop-in', 'category' => 'air', 'difficulty' => 2, 'description' => 'Vom Coping in die Rampe einsteigen, Gewicht früh über den vorderen Fuß.', 'isGoal' => false, 'goalOrder' => null],
            ['slug' => 'ollie-stand', 'name' => 'Ollie im Stand', 'category' => 'flat', 'difficulty' => 2, 'description' => 'Tail knallen und den vorderen Fuß nachziehen, ohne Fahrt.', 'isGoal' => false, 'goalOrder' => null],
            ['slug' => 'shove-it-stand', 'name' => 'Shove-it im Stand', 'category' => 'rotation', 'difficulty' => 2, 'description' => 'Das Board mit dem hinteren Fuß 180 Grad unter den Füßen drehen, ohne Fahrt.', 'isGoal' => false, 'goalOrder' => null],
            ['slug' => 'ollie', 'name' => 'Ollie', 'category' => 'flat', 'difficulty' => 3, 'description' => 'Ollie in der Fahrt. Ausgangsniveau etwa 25 cm, Zielhöhe darüber.', 'isGoal' => true, 'goalOrder' => 1],
            ['slug' => 'pop-shove-it', 'name' => 'Pop Shove-it', 'category' => 'rotation', 'difficulty' => 4, 'description' => 'Shove-it mit Pop: Das Board dreht 180 Grad, die Füße landen wieder mittig.', 'isGoal' => true, 'goalOrder' => 2],
            ['slug' => 'manual', 'name' => 'Manual', 'category' => 'balance', 'difficulty' => 4, 'description' => 'Auf den Hinterrädern rollen, Nose oben, Blick nach vorn.', 'isGoal' => true, 'goalOrder' => 3],
            ['slug' => 'board-stall', 'name' => 'Board-Stall auf dem Curb', 'category' => 'slide', 'difficulty' => 5, 'description' => 'Das Board quer auf die Kante setzen und im Stand balancieren. Vorstufe zum Boardslide.', 'isGoal' => false, 'goalOrder' => null],
            ['slug' => 'ollie-to-manual', 'name' => 'Ollie in den Manual', 'category' => 'balance', 'difficulty' => 6, 'description' => 'Ollie auf das Manual-Pad und direkt in den Manual abrollen.', 'isGoal' => true, 'goalOrder' => 4],
            ['slug' => 'pop-shove-it-to-manual', 'name' => 'Pop Shove-it in den Manual', 'category' => 'balance', 'difficulty' => 6, 'description' => 'Zweiter Weg zum vierten Ziel: Pop Shove-it auf das Pad und in den Manual.', 'isGoal' => false, 'goalOrder' => null],
            ['slug' => 'nose-manual', 'name' => 'Nose Manual', 'category' => 'balance', 'difficulty' => 6, 'description' => 'Auf den Vorderrädern rollen, Tail oben.', 'isGoal' => false, 'goalOrder' => null],
            ['slug' => 'kickflip-stand', 'name' => 'Kickflip im Stand', 'category' => 'flat', 'difficulty' => 6, 'description' => 'Flick über die Nose-Kante, das Board dreht einmal um die Längsachse, ohne Fahrt.', 'isGoal' => false, 'goalOrder' => null],
            ['slug' => 'manual-to-nose-manual', 'name' => 'Manual zu Nose Manual', 'category' => 'balance', 'difficulty' => 7, 'description' => 'Aus dem Manual über die Mitte in den Nose Manual wechseln, ohne aufzusetzen.', 'isGoal' => true, 'goalOrder' => 5],
            ['slug' => 'boardslide', 'name' => 'Boardslide', 'category' => 'slide', 'difficulty' => 7, 'description' => 'Über die Rail ollien, mit der Boardmitte quer aufsetzen und rutschen.', 'isGoal' => true, 'goalOrder' => 6],
            ['slug' => 'kickflip', 'name' => 'Kickflip', 'category' => 'flat', 'difficulty' => 8, 'description' => 'Streckziel: zuerst auf der Bank, wo die Schräge dem Board hilft.', 'isGoal' => true, 'goalOrder' => 7],
        ];
    }

    /**
     * @return list<array{0: string, 1: string}> [trick slug, requires slug]
     */
    private function edges(): array
    {
        return [
            ['tic-tac', 'rolling'],
            ['drop-in', 'rolling'],
            ['ollie-stand', 'rolling'],
            ['shove-it-stand', 'rolling'],
            ['ollie', 'ollie-stand'],
            ['pop-shove-it', 'ollie'],
            ['pop-shove-it', 'shove-it-stand'],
            ['manual', 'tic-tac'],
            ['board-stall', 'ollie'],
            ['kickflip-stand', 'ollie'],
            ['ollie-to-manual', 'ollie'],
            ['ollie-to-manual', 'manual'],
            ['pop-shove-it-to-manual', 'pop-shove-it'],
            ['pop-shove-it-to-manual', 'manual'],
            ['nose-manual', 'manual'],
            ['manual-to-nose-manual', 'nose-manual'],
            ['boardslide', 'board-stall'],
            ['kickflip', 'kickflip-stand'],
        ];
    }
}
