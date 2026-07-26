<?php

declare(strict_types=1);

namespace Khalyuzh;

use DateTimeZone;
use PDO;
use Psr\Clock\ClockInterface;
use RuntimeException;
use Throwable;

final readonly class FoodRecordRepository
{
    /**
     * @param array{
     *     catWeight: float|int,
     *     dryName: string,
     *     dryCaloriesPerGram: float|int,
     *     wetName: string,
     *     wetCaloriesPerCan: float|int,
     *     targetMin: float|int,
     *     targetMax: float|int
     * } $profile
     */
    public function __construct(
        private PDO $pdo,
        private ClockInterface $clock,
        private array $profile,
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $date): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM records WHERE date = :date');
        $statement->execute(['date' => $date]);
        $record = $statement->fetch();

        return $record === false ? null : $this->toArray($record);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchAll(): array
    {
        $statement = $this->pdo->query('SELECT * FROM records ORDER BY date DESC');
        if ($statement === false) {
            throw new RuntimeException('Could not read food records.');
        }

        return array_values(array_map(
            fn (array $record): array => $this->toArray($record),
            $statement->fetchAll(),
        ));
    }

    /**
     * @return array{record: array<string, mixed>, created: bool}
     */
    public function save(string $date, float $dryAmount, float $wetAmount): array
    {
        $this->pdo->beginTransaction();

        try {
            $existing = $this->find($date);
            $now = $this->timestamp();

            if ($existing !== null) {
                $statement = $this->pdo->prepare(
                    'UPDATE records
                     SET dry_amount = :dry_amount,
                         wet_amount = :wet_amount,
                         updated_at = :updated_at
                     WHERE date = :date',
                );
                $statement->execute([
                    'date' => $date,
                    'dry_amount' => $dryAmount,
                    'wet_amount' => $wetAmount,
                    'updated_at' => $now,
                ]);
            } else {
                $statement = $this->pdo->prepare(
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
                     )',
                );
                $statement->execute([
                    'date' => $date,
                    'dry_amount' => $dryAmount,
                    'wet_amount' => $wetAmount,
                    'cat_weight' => $this->profile['catWeight'],
                    'dry_name' => $this->profile['dryName'],
                    'dry_calories_per_gram' => $this->profile['dryCaloriesPerGram'],
                    'wet_name' => $this->profile['wetName'],
                    'wet_calories_per_can' => $this->profile['wetCaloriesPerCan'],
                    'target_min' => $this->profile['targetMin'],
                    'target_max' => $this->profile['targetMax'],
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
        $statement = $this->pdo->prepare('DELETE FROM records WHERE date = :date');
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
            'dryAmount' => (float) $record['dry_amount'],
            'wetAmount' => (float) $record['wet_amount'],
            'catWeight' => (float) $record['cat_weight'],
            'dryName' => (string) $record['dry_name'],
            'dryCaloriesPerGram' => (float) $record['dry_calories_per_gram'],
            'wetName' => (string) $record['wet_name'],
            'wetCaloriesPerCan' => (float) $record['wet_calories_per_can'],
            'targetMin' => (float) $record['target_min'],
            'targetMax' => (float) $record['target_max'],
            'createdAt' => (string) $record['created_at'],
            'updatedAt' => (string) $record['updated_at'],
        ];
    }

    private function timestamp(): string
    {
        return $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
    }
}
