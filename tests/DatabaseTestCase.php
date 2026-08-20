<?php

declare(strict_types=1);

namespace Khalyuzh\Tests;

use Khalyuzh\ApiController;
use Khalyuzh\Database;
use Khalyuzh\FoodRecordRepository;
use Khalyuzh\ReportController;
use Khalyuzh\WeightRecordRepository;
use PDO;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

abstract class DatabaseTestCase extends TestCase
{
    protected PDO $pdo;
    protected MockClock $clock;

    /**
     * @var array{
     *     catWeight: float,
     *     dryName: string,
     *     dryCaloriesPerGram: float,
     *     wetName: string,
     *     wetCaloriesPerCan: float,
     *     targetMin: float,
     *     targetMax: float
     * }
     */
    protected array $profile = [
        'catWeight' => 5.5,
        'dryName' => 'Dry Test',
        'dryCaloriesPerGram' => 4.2,
        'wetName' => 'Wet Test',
        'wetCaloriesPerCan' => 100.0,
        'targetMin' => 250.0,
        'targetMax' => 330.0,
    ];

    /**
     * @var array{
     *     name: string,
     *     nameGenitive: string,
     *     species: string,
     *     breed: string,
     *     sex: string,
     *     reproductiveStatus: string,
     *     diagnosis: string,
     *     coatColor: string,
     *     birthDate: string
     * }
     */
    protected array $pet = [
        'name' => 'Халюж',
        'nameGenitive' => 'Халюжа',
        'species' => 'Домашняя кошка',
        'breed' => 'Метис',
        'sex' => 'Самец',
        'reproductiveStatus' => 'Кастрирован',
        'diagnosis' => 'ХПН 1-2 стадия',
        'coatColor' => 'Голубой табби с белым',
        'birthDate' => '2016-04-08',
    ];

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-07-26 12:00:00', 'Asia/Yerevan');
        $database = Database::inMemory($this->clock);
        $database->migrate();
        $this->pdo = $database->connection();
    }

    protected function apiController(): ApiController
    {
        return new ApiController(
            new FoodRecordRepository($this->pdo, $this->clock, $this->profile),
            new WeightRecordRepository($this->pdo, $this->clock),
            $this->clock,
            'Asia/Yerevan',
        );
    }

    protected function reportController(): ReportController
    {
        return new ReportController(
            new Environment(
                new FilesystemLoader(dirname(__DIR__) . '/app/templates'),
                [
                    'autoescape' => 'html',
                    'cache' => false,
                    'strict_variables' => true,
                ],
            ),
            new FoodRecordRepository($this->pdo, $this->clock, $this->profile),
            new WeightRecordRepository($this->pdo, $this->clock),
            $this->clock,
            'Asia/Yerevan',
            $this->pet,
        );
    }
}
