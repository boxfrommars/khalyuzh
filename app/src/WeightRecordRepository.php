<?php

declare(strict_types=1);

namespace Khalyuzh;

use DateTimeZone;
use PDO;
use Psr\Clock\ClockInterface;
use RuntimeException;
use Throwable;

final readonly class WeightRecordRepository
{
    public function __construct(
        private PDO $pdo,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $date): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM weight_records WHERE date = :date');
        $statement->execute(['date' => $date]);
        $record = $statement->fetch();

        return $record === false ? null : $this->toArray($record);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchAll(): array
    {
        $statement = $this->pdo->query('SELECT * FROM weight_records ORDER BY date DESC');
        if ($statement === false) {
            throw new RuntimeException('Could not read weight records.');
        }

        return array_values(array_map(
            fn (array $record): array => $this->toArray($record),
            $statement->fetchAll(),
        ));
    }

    /**
     * @return array{record: array<string, mixed>, created: bool}
     */
    public function save(string $date, float $weightKg): array
    {
        $this->pdo->beginTransaction();

        try {
            $existing = $this->find($date);
            $now = $this->timestamp();

            if ($existing !== null) {
                $statement = $this->pdo->prepare(
                    'UPDATE weight_records
                     SET weight_kg = :weight_kg,
                         updated_at = :updated_at
                     WHERE date = :date',
                );
                $statement->execute([
                    'date' => $date,
                    'weight_kg' => $weightKg,
                    'updated_at' => $now,
                ]);
            } else {
                $statement = $this->pdo->prepare(
                    'INSERT INTO weight_records (date, weight_kg, created_at, updated_at)
                     VALUES (:date, :weight_kg, :created_at, :updated_at)',
                );
                $statement->execute([
                    'date' => $date,
                    'weight_kg' => $weightKg,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $error;
        }

        /** @var array<string, mixed> $record */
        $record = $this->find($date);

        return [
            'record' => $record,
            'created' => $existing === null,
        ];
    }

    public function delete(string $date): void
    {
        $statement = $this->pdo->prepare('DELETE FROM weight_records WHERE date = :date');
        $statement->execute(['date' => $date]);
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    private function toArray(array $record): array
    {
        return [
            'date' => (string) $record['date'],
            'weightKg' => (float) $record['weight_kg'],
            'createdAt' => (string) $record['created_at'],
            'updatedAt' => (string) $record['updated_at'],
        ];
    }

    private function timestamp(): string
    {
        return $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
    }
}
