<?php

declare(strict_types=1);

function appConfig(): array
{
    static $config;

    if ($config === null) {
        $config = require __DIR__ . '/config.php';
    }

    return $config;
}

function database(): PDO
{
    static $pdo;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $databasePath = appConfig()['databasePath'];
    $storageDirectory = dirname($databasePath);

    if (!is_dir($storageDirectory) || !is_writable($storageDirectory)) {
        throw new RuntimeException('The storage directory does not exist or is not writable.');
    }

    $pdo = new PDO('sqlite:' . $databasePath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_STRINGIFY_FETCHES => false,
    ]);
    $pdo->exec('PRAGMA busy_timeout = 5000');
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
        SQL
    );

    return $pdo;
}

function recordToArray(array $record): array
{
    return [
        'date' => $record['date'],
        'dryAmount' => (float) $record['dry_amount'],
        'wetAmount' => (float) $record['wet_amount'],
        'catWeight' => (float) $record['cat_weight'],
        'dryName' => $record['dry_name'],
        'dryCaloriesPerGram' => (float) $record['dry_calories_per_gram'],
        'wetName' => $record['wet_name'],
        'wetCaloriesPerCan' => (float) $record['wet_calories_per_can'],
        'targetMin' => (float) $record['target_min'],
        'targetMax' => (float) $record['target_max'],
        'createdAt' => $record['created_at'],
        'updatedAt' => $record['updated_at'],
    ];
}

function findRecord(string $date): ?array
{
    $statement = database()->prepare('SELECT * FROM records WHERE date = :date');
    $statement->execute(['date' => $date]);
    $record = $statement->fetch();

    return $record === false ? null : recordToArray($record);
}

function fetchRecords(): array
{
    $statement = database()->query('SELECT * FROM records ORDER BY date DESC');

    return array_map('recordToArray', $statement->fetchAll());
}

function validateRecordDate(mixed $date): string
{
    if (!is_string($date) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
        throw new InvalidArgumentException('Date must use the YYYY-MM-DD format.');
    }

    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
        throw new InvalidArgumentException('Date is invalid.');
    }

    return $date;
}

function validateAmount(mixed $value, string $field): float
{
    if (!is_int($value) && !is_float($value)) {
        throw new InvalidArgumentException(sprintf('%s must be a number.', $field));
    }

    $amount = (float) $value;
    if (!is_finite($amount) || $amount < 0) {
        throw new InvalidArgumentException(sprintf('%s must be a non-negative finite number.', $field));
    }

    return $amount;
}

function saveRecord(string $date, float $dryAmount, float $wetAmount): array
{
    $pdo = database();
    $pdo->beginTransaction();

    try {
        $existing = findRecord($date);
        $now = gmdate('c');

        if ($existing !== null) {
            $statement = $pdo->prepare(
                'UPDATE records
                 SET dry_amount = :dry_amount,
                     wet_amount = :wet_amount,
                     updated_at = :updated_at
                 WHERE date = :date'
            );
            $statement->execute([
                'date' => $date,
                'dry_amount' => $dryAmount,
                'wet_amount' => $wetAmount,
                'updated_at' => $now,
            ]);
        } else {
            $profile = appConfig()['profile'];
            $statement = $pdo->prepare(
                'INSERT INTO records (
                    date, dry_amount, wet_amount, cat_weight,
                    dry_name, dry_calories_per_gram,
                    wet_name, wet_calories_per_can,
                    target_min, target_max, created_at, updated_at
                 ) VALUES (
                    :date, :dry_amount, :wet_amount, :cat_weight,
                    :dry_name, :dry_calories_per_gram,
                    :wet_name, :wet_calories_per_can,
                    :target_min, :target_max, :created_at, :updated_at
                 )'
            );
            $statement->execute([
                'date' => $date,
                'dry_amount' => $dryAmount,
                'wet_amount' => $wetAmount,
                'cat_weight' => $profile['catWeight'],
                'dry_name' => $profile['dryName'],
                'dry_calories_per_gram' => $profile['dryCaloriesPerGram'],
                'wet_name' => $profile['wetName'],
                'wet_calories_per_can' => $profile['wetCaloriesPerCan'],
                'target_min' => $profile['targetMin'],
                'target_max' => $profile['targetMax'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $error;
    }

    return [
        'record' => findRecord($date),
        'created' => $existing === null,
    ];
}

function deleteRecord(string $date): void
{
    $statement = database()->prepare('DELETE FROM records WHERE date = :date');
    $statement->execute(['date' => $date]);
}

function requireAdminAccess(): void
{
    if (PHP_SAPI === 'cli-server') {
        return;
    }

    if (empty($_SERVER['REMOTE_USER']) && empty($_SERVER['PHP_AUTH_USER'])) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Administrative access must be authenticated by the web server.';
        exit;
    }
}
