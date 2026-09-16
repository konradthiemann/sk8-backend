<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Training;

use App\Enum\Equipment;
use App\Enum\ExerciseMeasure;
use App\Enum\KneeLoad;
use App\Service\Training\ExerciseCatalogEntry;
use App\Service\Training\ExerciseCatalogFile;
use App\Service\Training\InvalidExerciseCatalogException;
use PHPUnit\Framework\TestCase;

/**
 * Pure PHP object test, no kernel, no database: `ExerciseCatalogFile` reads
 * and validates `config/data/exercises.json` independently of Doctrine
 * (design.md §4, "ExerciseCatalogFile (unit-testbar, kein Kernel)").
 *
 * Tester's resolution of the ticket's open question (design.md §4, "der Pfad
 * der Datei vermutlich ueber ein Konstruktor-Argument oder Kernel-Parameter
 * injizierbar; entscheide das selbst"): `load()` takes the file path as an
 * explicit method argument rather than a constructor dependency, matching
 * the sequence diagram's own notation (design.md §5:
 * `Cmd->>File: load(config/data/exercises.json)`). That keeps the service
 * itself free of any dependency, and every validation test below runs
 * against a throwaway fixture file instead of the real catalog - see
 * tests.md for the documented assumption.
 */
final class ExerciseCatalogFileTest extends TestCase
{
    /**
     * @var list<string>
     */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        $this->tempFiles = [];
    }

    public function testItLoadsTheRealCatalogFileWithSixteenUniqueSlugsAndNonEmptyNames(): void
    {
        // Acceptance criteria 10/11 as a standing data check on the actual
        // shipped catalog (design.md, Tests table): "Die echte
        // config/data/exercises.json ist gueltig: eindeutige Slugs, ...".
        // config/data/exercises.json is part of this ticket's implementation
        // (design.md §4, Backend-Struktur) - this test stays red until the
        // implementer adds it, same as the class-not-found red above it.
        $path = \dirname(__DIR__, 4).'/config/data/exercises.json';
        self::assertFileExists($path, 'config/data/exercises.json is part of this ticket\'s implementation');

        $entries = (new ExerciseCatalogFile())->load($path);

        self::assertCount(16, $entries);

        $slugs = array_map(static fn (ExerciseCatalogEntry $entry): string => $entry->slug, $entries);
        self::assertSame($slugs, array_unique($slugs), 'every slug in the real catalog must be unique');

        foreach ($entries as $entry) {
            self::assertNotSame('', trim($entry->name), \sprintf('entry "%s" has an empty name', $entry->slug));
        }
    }

    public function testItMapsAValidEntryToItsTypedFields(): void
    {
        $path = $this->writeCatalog([
            [
                'slug' => 'single-leg-balance',
                'name' => 'Einbeinstand',
                'equipment' => 'bodyweight',
                'muscleGroups' => ['rumpf', 'wade'],
                'kneeLoad' => 'niedrig',
                'measure' => 'seconds_per_side',
                'description' => 'Auf einem Bein stehen.',
                'isPrevention' => true,
                'kneeLoadReason' => 'Statischer Stand ohne Gewichtsuebertragung durch ein gebeugtes Knie.',
            ],
        ]);

        $entries = (new ExerciseCatalogFile())->load($path);

        self::assertCount(1, $entries);
        $entry = $entries[0];
        self::assertSame('single-leg-balance', $entry->slug);
        self::assertSame('Einbeinstand', $entry->name);
        self::assertSame(Equipment::Bodyweight, $entry->equipment);
        self::assertSame(['rumpf', 'wade'], $entry->muscleGroups);
        self::assertSame(KneeLoad::Low, $entry->kneeLoad);
        self::assertSame(ExerciseMeasure::SecondsPerSide, $entry->measure);
        self::assertSame('Auf einem Bein stehen.', $entry->description);
        self::assertTrue($entry->isPrevention);
        self::assertSame('Statischer Stand ohne Gewichtsuebertragung durch ein gebeugtes Knie.', $entry->kneeLoadReason);
    }

    public function testItThrowsWhenTwoEntriesShareTheSameSlug(): void
    {
        // Criterion 11: "bricht er ab und nennt den Slug".
        $path = $this->writeCatalog([
            $this->minimalEntry('duplicate-slug'),
            $this->minimalEntry('duplicate-slug'),
        ]);

        $this->expectException(InvalidExerciseCatalogException::class);
        $this->expectExceptionMessageMatches('/duplicate-slug/');

        (new ExerciseCatalogFile())->load($path);
    }

    public function testItThrowsWhenAnEntryHasAnUnknownKneeLoadValue(): void
    {
        // Criterion 10: "bricht er mit einer verstaendlichen Meldung ab und
        // schreibt nichts" - the "schreibt nichts" half is covered by
        // SyncExercisesCommandTest, this half only proves the file itself
        // refuses to parse.
        $entry = $this->minimalEntry('bad-knee-load');
        $entry['kneeLoad'] = 'sehr-hoch';
        $path = $this->writeCatalog([$entry]);

        $this->expectException(InvalidExerciseCatalogException::class);
        $this->expectExceptionMessageMatches('/sehr-hoch/');

        (new ExerciseCatalogFile())->load($path);
    }

    public function testItThrowsWhenAnEntryHasAnUnknownEquipmentValue(): void
    {
        $entry = $this->minimalEntry('bad-equipment');
        $entry['equipment'] = 'wheels';
        $path = $this->writeCatalog([$entry]);

        $this->expectException(InvalidExerciseCatalogException::class);
        $this->expectExceptionMessageMatches('/wheels/');

        (new ExerciseCatalogFile())->load($path);
    }

    public function testItThrowsWhenAnEntryHasAnUnknownMeasureValue(): void
    {
        $entry = $this->minimalEntry('bad-measure');
        $entry['measure'] = 'minutes';
        $path = $this->writeCatalog([$entry]);

        $this->expectException(InvalidExerciseCatalogException::class);
        $this->expectExceptionMessageMatches('/minutes/');

        (new ExerciseCatalogFile())->load($path);
    }

    public function testItThrowsWhenAnEntryHasAnEmptyName(): void
    {
        // design.md §4, ExerciseCatalogFile's validation list: "leerem `name`".
        $entry = $this->minimalEntry('no-name');
        $entry['name'] = '';
        $path = $this->writeCatalog([$entry]);

        $this->expectException(InvalidExerciseCatalogException::class);
        $this->expectExceptionMessageMatches('/no-name/');

        (new ExerciseCatalogFile())->load($path);
    }

    /**
     * @param list<array<string, mixed>> $entries
     */
    private function writeCatalog(array $entries): string
    {
        $path = sys_get_temp_dir().'/exercise-catalog-'.bin2hex(random_bytes(8)).'.json';
        file_put_contents($path, json_encode($entries, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT));
        $this->tempFiles[] = $path;

        return $path;
    }

    /**
     * @return array<string, mixed>
     */
    private function minimalEntry(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => 'Testuebung',
            'equipment' => 'bodyweight',
            'muscleGroups' => ['rumpf'],
            'kneeLoad' => 'keine',
            'measure' => 'reps',
            'description' => null,
            'isPrevention' => false,
            'kneeLoadReason' => 'Testbegruendung.',
        ];
    }
}
