<?php

declare(strict_types=1);

namespace Khalyuzh\Tests;

use Khalyuzh\WeightRecordRepository;

final class WeightRecordRepositoryTest extends DatabaseTestCase
{
    public function testCreateUpdateFetchAndDelete(): void
    {
        $repository = new WeightRecordRepository($this->pdo, $this->clock);

        $created = $repository->save('2026-07-26', 5.48);
        self::assertTrue($created['created']);
        self::assertSame(5.48, $created['record']['weightKg']);
        $createdAt = $created['record']['createdAt'];

        $this->clock->sleep(60);
        $updated = $repository->save('2026-07-26', 5.51);
        self::assertFalse($updated['created']);
        self::assertSame(5.51, $updated['record']['weightKg']);
        self::assertSame($createdAt, $updated['record']['createdAt']);
        self::assertNotSame($createdAt, $updated['record']['updatedAt']);
        self::assertCount(1, $repository->fetchAll());

        $repository->delete('2026-07-26');
        self::assertNull($repository->find('2026-07-26'));
    }

    public function testRecordsAreSortedNewestFirst(): void
    {
        $repository = new WeightRecordRepository($this->pdo, $this->clock);
        $repository->save('2026-07-20', 5.50);
        $repository->save('2026-07-26', 5.48);

        self::assertSame(
            ['2026-07-26', '2026-07-20'],
            array_column($repository->fetchAll(), 'date'),
        );
    }
}
