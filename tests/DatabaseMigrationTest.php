<?php

declare(strict_types=1);

namespace Khalyuzh\Tests;

use Khalyuzh\Database;
use PDO;
use RuntimeException;
use Symfony\Component\Clock\MockClock;

final class DatabaseMigrationTest extends DatabaseTestCase
{
    public function testFreshDatabaseReceivesAllMigrationsAndRepeatIsSafe(): void
    {
        $database = Database::inMemory($this->clock);

        self::assertSame([1, 2], $database->migrate());
        self::assertSame([], $database->migrate());
        $pdo = $database->connection();
        self::assertSame(
            ['records', 'schema_migrations', 'weight_records'],
            $pdo->query(
                "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name",
            )->fetchAll(PDO::FETCH_COLUMN),
        );
        self::assertSame(
            [1, 2],
            array_map(
                'intval',
                $pdo->query('SELECT version FROM schema_migrations ORDER BY version')->fetchAll(PDO::FETCH_COLUMN),
            ),
        );
    }

    public function testExistingFoodTableIsPreserved(): void
    {
        $database = Database::inMemory($this->clock);
        $pdo = $database->connection();
        $pdo->exec(
            'CREATE TABLE records (
                date TEXT PRIMARY KEY,
                dry_amount REAL NOT NULL,
                wet_amount REAL NOT NULL,
                cat_weight REAL NOT NULL,
                dry_name TEXT NOT NULL,
                dry_calories_per_gram REAL NOT NULL,
                wet_name TEXT NOT NULL,
                wet_calories_per_can REAL NOT NULL,
                target_min REAL NOT NULL,
                target_max REAL NOT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )',
        );
        $pdo->exec(
            "INSERT INTO records VALUES (
                '2026-07-25', 40, 1, 5.5, 'Dry', 4.2, 'Wet', 100, 250, 330, 'created', 'updated'
            )",
        );

        $database->migrate();

        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM records')->fetchColumn());
        self::assertSame('Dry', $pdo->query('SELECT dry_name FROM records')->fetchColumn());
    }

    public function testFailedMigrationRollsBackItsSchemaAndVersion(): void
    {
        $database = Database::inMemory(
            new MockClock(),
            [
                99 => static function (PDO $pdo): void {
                    $pdo->exec('CREATE TABLE should_rollback (id INTEGER PRIMARY KEY)');
                    throw new RuntimeException('stop');
                },
            ],
        );
        $pdo = $database->connection();

        try {
            $database->migrate();
            self::fail('The migration should fail.');
        } catch (RuntimeException $error) {
            self::assertSame('stop', $error->getMessage());
        }

        self::assertFalse(
            $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'should_rollback'")
                ->fetchColumn(),
        );
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn());
    }
}
