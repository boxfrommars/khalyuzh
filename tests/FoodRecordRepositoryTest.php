<?php

declare(strict_types=1);

namespace Khalyuzh\Tests;

use Khalyuzh\FoodRecordRepository;

final class FoodRecordRepositoryTest extends DatabaseTestCase
{
    public function testCreateUpdateFetchAndDeletePreserveProfileSnapshot(): void
    {
        $repository = new FoodRecordRepository($this->pdo, $this->clock, $this->profile);

        $created = $repository->save('2026-07-25', 40.0, 1.0);
        self::assertTrue($created['created']);
        self::assertSame(40.0, $created['record']['dryAmount']);
        self::assertSame('Dry Test', $created['record']['dryName']);
        self::assertSame(5.5, $created['record']['catWeight']);
        $createdAt = $created['record']['createdAt'];

        $this->clock->sleep(60);
        $updated = $repository->save('2026-07-25', 42.0, 0.5);
        self::assertFalse($updated['created']);
        self::assertSame(42.0, $updated['record']['dryAmount']);
        self::assertSame(0.5, $updated['record']['wetAmount']);
        self::assertSame($createdAt, $updated['record']['createdAt']);
        self::assertNotSame($createdAt, $updated['record']['updatedAt']);
        self::assertCount(1, $repository->fetchAll());

        $repository->delete('2026-07-25');
        self::assertNull($repository->find('2026-07-25'));
    }

    public function testRecordsAreSortedNewestFirst(): void
    {
        $repository = new FoodRecordRepository($this->pdo, $this->clock, $this->profile);
        $repository->save('2026-07-20', 30.0, 1.0);
        $repository->save('2026-07-25', 40.0, 1.0);

        self::assertSame(
            ['2026-07-25', '2026-07-20'],
            array_column($repository->fetchAll(), 'date'),
        );
    }
}
