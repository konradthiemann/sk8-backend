<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

/**
 * Seeds the curated 16-exercise training catalog (T-0301, R-03).
 *
 * Data migration, deliberately separate from the schema migration
 * (Version20260916075306) so schema and data changes never mix in one
 * step. Values mirror config/data/exercises.json, minus `kneeLoadReason`
 * (catalog-file-only documentation field, no column on `exercise`).
 *
 * Executes each statement directly via `$this->connection` instead of
 * `$this->addSql()` - see Version20260916075306 for why: the reversibility
 * test replays down()/up() on the same object within one process, which
 * addSql()'s one-time freeze() does not allow.
 */
final class Version20260916075313 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Seed the 16 catalog exercises';
    }

    public function up(Schema $schema): void
    {
        foreach ($this->exercises() as $exercise) {
            $this->connection->executeStatement(
                'INSERT INTO exercise (id, slug, name, equipment, muscle_groups, knee_load, measure, description, is_prevention) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    Uuid::v7()->toRfc4122(),
                    $exercise['slug'],
                    $exercise['name'],
                    $exercise['equipment'],
                    json_encode($exercise['muscleGroups'], \JSON_THROW_ON_ERROR),
                    $exercise['kneeLoad'],
                    $exercise['measure'],
                    $exercise['description'],
                    $exercise['isPrevention'],
                ],
                [
                    'string', 'string', 'string', 'string', 'string', 'string', 'string', 'string', 'boolean',
                ],
            );
        }
    }

    public function down(Schema $schema): void
    {
        // Deletes exactly the 16 seeded slugs, not TRUNCATE, so rows added
        // later by app:exercise:sync or by hand survive.
        foreach ($this->exercises() as $exercise) {
            $this->connection->executeStatement('DELETE FROM exercise WHERE slug = ?', [$exercise['slug']], ['string']);
        }
    }

    /**
     * @return list<array{slug: string, name: string, equipment: string, muscleGroups: list<string>, kneeLoad: string, measure: string, description: string, isPrevention: bool}>
     */
    private function exercises(): array
    {
        return [
            ['slug' => 'single-leg-balance', 'name' => 'Einbeinstand', 'equipment' => 'bodyweight', 'muscleGroups' => ['rumpf', 'sprunggelenk', 'huefte'], 'kneeLoad' => 'keine', 'measure' => 'seconds_per_side', 'description' => 'Barfuss, Haende in die Huefte gestuetzt, ein Bein angewinkelt anheben, Blick geradeaus. Bei Bedarf Ringe leicht beruehren zur Sicherung.', 'isPrevention' => true],
            ['slug' => 'clamshell', 'name' => 'Muschelübung', 'equipment' => 'bodyweight', 'muscleGroups' => ['huefte', 'gesaess'], 'kneeLoad' => 'keine', 'measure' => 'reps_per_side', 'description' => 'Seitlich liegen, Knie gebeugt uebereinander, Fersen zusammen, oberes Knie kontrolliert oeffnen und schliessen ohne das Becken zu kippen.', 'isPrevention' => true],
            ['slug' => 'side-plank-hip-abduction', 'name' => 'Seitstütz mit Beinheben', 'equipment' => 'bodyweight', 'muscleGroups' => ['rumpf', 'huefte'], 'kneeLoad' => 'keine', 'measure' => 'reps_per_side', 'description' => 'Seitstuetz auf dem Unterarm, Koerper gerade, oberes Bein gestreckt kontrolliert anheben und senken.', 'isPrevention' => true],
            ['slug' => 'broad-jump', 'name' => 'Strecksprung (Standweitsprung)', 'equipment' => 'bodyweight', 'muscleGroups' => ['beine', 'rumpf'], 'kneeLoad' => 'hoch', 'measure' => 'reps', 'description' => 'Aus dem Stand beidbeinig mit Armschwung so weit wie moeglich nach vorne springen und kontrolliert, mit gebeugten Knien, landen.', 'isPrevention' => false],
            ['slug' => 'bodyweight-squat', 'name' => 'Kniebeuge', 'equipment' => 'bodyweight', 'muscleGroups' => ['beine', 'gesaess', 'rumpf'], 'kneeLoad' => 'mittel', 'measure' => 'reps', 'description' => 'Beidbeinig, hueftbreiter Stand, Knie bis maximal 90 Grad beugen, Knie bleibt ueber dem Fuss, kontrolliert wieder aufrichten.', 'isPrevention' => false],
            ['slug' => 'shallow-box-squat', 'name' => 'Flache Kniebeuge', 'equipment' => 'bodyweight', 'muscleGroups' => ['beine', 'gesaess'], 'kneeLoad' => 'niedrig', 'measure' => 'reps', 'description' => 'Beidbeinig, Knie nur bis ca. 45 Grad beugen (Oberschenkel deutlich ueber der Waagerechten), kontrolliertes Tempo.', 'isPrevention' => true],
            ['slug' => 'step-down', 'name' => 'Kontrollierter Stufenabstieg', 'equipment' => 'bodyweight', 'muscleGroups' => ['beine', 'gesaess'], 'kneeLoad' => 'hoch', 'measure' => 'reps_per_side', 'description' => 'Auf eine niedrige Stufe oder ein Buch stellen, ein Bein langsam exzentrisch absenken bis die Ferse fast den Boden beruehrt, ohne Gewicht abzusetzen, dann zurueck.', 'isPrevention' => true],
            ['slug' => 'nordic-hamstring-curl', 'name' => 'Nordisches Beinbeuger-Curl', 'equipment' => 'bodyweight', 'muscleGroups' => ['oberschenkelrueckseite'], 'kneeLoad' => 'niedrig', 'measure' => 'reps', 'description' => 'Kniend, Fuesse fixiert (z. B. an Ringen oder von Partner gehalten), Oberkoerper langsam exzentrisch nach vorne absenken, mit den Haenden abfangen.', 'isPrevention' => true],
            ['slug' => 'wall-sit', 'name' => 'Wandsitz', 'equipment' => 'bodyweight', 'muscleGroups' => ['beine'], 'kneeLoad' => 'mittel', 'measure' => 'seconds', 'description' => 'Ruecken an die Wand, in die Hocke bis Ober- und Unterschenkel etwa 90 Grad bilden, Position isometrisch halten.', 'isPrevention' => false],
            ['slug' => 'single-leg-wall-sit', 'name' => 'Einbeiniger Wandsitz', 'equipment' => 'bodyweight', 'muscleGroups' => ['beine'], 'kneeLoad' => 'hoch', 'measure' => 'seconds_per_side', 'description' => 'Wie Wandsitz, dann ein Bein leicht anheben und das Koerpergewicht einbeinig isometrisch halten.', 'isPrevention' => false],
            ['slug' => 'ring-row', 'name' => 'Ruderzug an den Ringen', 'equipment' => 'rings', 'muscleGroups' => ['ruecken', 'bizeps', 'rumpf'], 'kneeLoad' => 'keine', 'measure' => 'reps', 'description' => 'Koerper gestreckt, Schulterblaetter zuerst, Ringe zum Brustkorb ziehen.', 'isPrevention' => false],
            ['slug' => 'ring-pull-up', 'name' => 'Klimmzug an den Ringen', 'equipment' => 'rings', 'muscleGroups' => ['ruecken', 'bizeps', 'rumpf'], 'kneeLoad' => 'keine', 'measure' => 'reps', 'description' => 'Haengend an den Ringen, Koerper hochziehen bis das Kinn ueber die Ringe kommt, kontrolliert absenken.', 'isPrevention' => false],
            ['slug' => 'push-up', 'name' => 'Liegestütz', 'equipment' => 'bodyweight', 'muscleGroups' => ['brust', 'trizeps', 'rumpf'], 'kneeLoad' => 'keine', 'measure' => 'reps', 'description' => 'Stuetzposition mit geradem Koerper, Ellbogen ca. 45 Grad zum Koerper, Brust nah an den Boden absenken, druecken.', 'isPrevention' => false],
            ['slug' => 'ring-dip', 'name' => 'Dip an den Ringen', 'equipment' => 'rings', 'muscleGroups' => ['brust', 'trizeps', 'schultern'], 'kneeLoad' => 'keine', 'measure' => 'reps', 'description' => 'An den Ringen im Stuetz, Koerper langsam absenken bis Oberarm etwa parallel zum Boden, wieder hochdruecken. Ringe nah am Koerper fuehren.', 'isPrevention' => false],
            ['slug' => 'calf-raise', 'name' => 'Wadenheben', 'equipment' => 'bodyweight', 'muscleGroups' => ['waden', 'sprunggelenk'], 'kneeLoad' => 'keine', 'measure' => 'reps', 'description' => 'Stand auf einer Stufenkante oder flach, kontrolliert auf die Fussballen heben und langsam absenken, Knie bleibt weitgehend gestreckt.', 'isPrevention' => true],
            ['slug' => 'plank', 'name' => 'Unterarmstütz', 'equipment' => 'bodyweight', 'muscleGroups' => ['rumpf'], 'kneeLoad' => 'keine', 'measure' => 'seconds', 'description' => 'Unterarmstuetz, Koerper von Kopf bis Ferse gerade, Blick nach unten, Position halten.', 'isPrevention' => false],
        ];
    }
}
