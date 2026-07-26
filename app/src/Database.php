<?php

declare(strict_types=1);

namespace Khalyuzh;

use Closure;
use DateTimeZone;
use PDO;
use Psr\Clock\ClockInterface;
use RuntimeException;
use Throwable;

final class Database
{
    private ?PDO $pdo = null;

    /** @var array<int, Closure(PDO): void> */
    private array $migrations;

    /**
     * @param array<int, Closure(PDO): void>|null $migrations
     */
    public function __construct(
        private readonly string $path,
        private readonly ClockInterface $clock,
        ?array $migrations = null,
    ) {
        $migrations ??= self::defaultMigrations();
        ksort($migrations, SORT_NUMERIC);
        $this->migrations = $migrations;
    }

    /**
     * @param array<int, Closure(PDO): void>|null $migrations
     */
    public static function inMemory(ClockInterface $clock, ?array $migrations = null): self
    {
        return new self(':memory:', $clock, $migrations);
    }

    public function connection(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        if ($this->path !== ':memory:') {
            $storageDirectory = dirname($this->path);
            if (!is_dir($storageDirectory) || !is_writable($storageDirectory)) {
                throw new RuntimeException('The storage directory does not exist or is not writable.');
            }
        }

        $dsn = $this->path === ':memory:' ? 'sqlite::memory:' : 'sqlite:' . $this->path;
        $this->pdo = new PDO($dsn);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, false);
        $this->pdo->exec('PRAGMA busy_timeout = 5000');

        return $this->pdo;
    }

    /**
     * @return list<int>
     */
    public function migrate(): array
    {
        $pdo = $this->connection();
        $pdo->exec(
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS schema_migrations (
                version INTEGER PRIMARY KEY,
                applied_at TEXT NOT NULL
            )
            SQL,
        );

        $statement = $pdo->query('SELECT version FROM schema_migrations ORDER BY version');
        if ($statement === false) {
            throw new RuntimeException('Could not read applied database migrations.');
        }

        $applied = array_map(
            static fn (mixed $version): int => (int) $version,
            $statement->fetchAll(PDO::FETCH_COLUMN),
        );
        $newVersions = [];

        foreach ($this->migrations as $version => $migration) {
            if (in_array($version, $applied, true)) {
                continue;
            }

            $pdo->beginTransaction();
            try {
                $migration($pdo);
                $statement = $pdo->prepare(
                    'INSERT INTO schema_migrations (version, applied_at) VALUES (:version, :applied_at)',
                );
                $statement->execute([
                    'version' => $version,
                    'applied_at' => $this->clock->now()
                        ->setTimezone(new DateTimeZone('UTC'))
                        ->format(DATE_ATOM),
                ]);
                $pdo->commit();
                $newVersions[] = $version;
            } catch (Throwable $error) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                throw $error;
            }
        }

        return $newVersions;
    }

    /**
     * @return array<int, Closure(PDO): void>
     */
    private static function defaultMigrations(): array
    {
        return [
            1 => static function (PDO $pdo): void {
                $pdo->exec(
                    <<<'SQL'
                    CREATE TABLE IF NOT EXISTS records (
                        date TEXT PRIMARY KEY,
                        dry_amount REAL NOT NULL CHECK (dry_amount >= 0),
                        wet_amount REAL NOT NULL CHECK (wet_amount >= 0),
                        cat_weight REAL NOT NULL CHECK (cat_weight > 0),
                        dry_name TEXT NOT NULL,
                        dry_calories_per_gram REAL NOT NULL CHECK (dry_calories_per_gram > 0),
                        wet_name TEXT NOT NULL,
                        wet_calories_per_can REAL NOT NULL CHECK (wet_calories_per_can > 0),
                        target_min REAL NOT NULL CHECK (target_min >= 0),
                        target_max REAL NOT NULL CHECK (target_max >= target_min),
                        created_at TEXT NOT NULL,
                        updated_at TEXT NOT NULL
                    )
                    SQL,
                );
            },
            2 => static function (PDO $pdo): void {
                $pdo->exec(
                    <<<'SQL'
                    CREATE TABLE IF NOT EXISTS weight_records (
                        date TEXT PRIMARY KEY,
                        weight_kg REAL NOT NULL CHECK (weight_kg > 0),
                        created_at TEXT NOT NULL,
                        updated_at TEXT NOT NULL
                    )
                    SQL,
                );
            },
        ];
    }
}
