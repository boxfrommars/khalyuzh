<?php

declare(strict_types=1);

namespace Khalyuzh\Tests;

use Khalyuzh\FoodRecordRepository;
use Khalyuzh\WeightRecordRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class ReportControllerTest extends DatabaseTestCase
{
    public function testDefaultReportUsesCompleteActualHistoryAndSnapshotCalories(): void
    {
        $food = new FoodRecordRepository($this->pdo, $this->clock, $this->profile);
        $weights = new WeightRecordRepository($this->pdo, $this->clock);
        $food->save('2026-04-27', 50, 0);
        $food->save('2026-04-28', 10, 1);
        $food->save('2026-07-24', 20, 0);
        $weights->save('2026-04-26', 4.80);
        $weights->save('2026-05-01', 5.00);
        $weights->save('2026-05-04', 5.20);
        $weights->save('2026-07-20', 5.40);
        $weights->save('2026-07-23', 5.60);

        $response = $this->reportController()->report(Request::create('/report/'));
        $content = $this->content($response);
        $config = $this->pageConfig($content);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertStringContainsString(
            'class="report-period-link selected" href="/report/?period=all"',
            $content,
        );
        self::assertStringContainsString('id="report-from" name="from" type="date" value="2026-04-26"', $content);
        self::assertStringContainsString('id="report-to" name="to" type="date" value="2026-07-24"', $content);
        self::assertSame(
            ['from' => '2026-04-26', 'to' => '2026-07-24'],
            $config['dateRange'],
        );
        self::assertStringContainsString('26.04.2026', $content);
        self::assertStringContainsString('28.04.2026', $content);
        self::assertStringContainsString('24.07.2026', $content);
        self::assertStringNotContainsString('26.07.2026', $content);
        self::assertStringContainsString('145,3 ккал', $content);
        self::assertStringContainsString('Средний вес за последние 7 дней', $content);
        self::assertStringContainsString('5,50 кг', $content);
        self::assertStringContainsString('+600 г', $content);
        self::assertStringContainsString('Первые 7 дней: 4,90 кг, последние 7 дней: 5,50 кг', $content);
        self::assertStringContainsString('Средние калории за последние 7 дней', $content);
        self::assertStringContainsString('<strong>84,0 ккал</strong>', $content);
        self::assertStringContainsString('1 запись', $content);
        self::assertStringContainsString('Средние калории за выбранный период', $content);
        self::assertStringNotContainsString('Последний фактический вес', $content);
        self::assertStringNotContainsString('Полнота дневника кормления', $content);
        self::assertSame(4, substr_count($content, '<article class="report-metric">'));
        self::assertStringNotContainsString('targetMin', $content);
        self::assertStringNotContainsString('targetMax', $content);
        self::assertStringContainsString('10 лет 3 месяца', $content);
        self::assertStringContainsString('Динамика веса', $content);
        self::assertStringContainsString('Динамика питания', $content);
        self::assertStringNotContainsString('Динамика фактического веса', $content);
        self::assertStringNotContainsString('<h3 id="calorie-chart-title">Дневная калорийность</h3>', $content);
        self::assertStringNotContainsString('Отчёт содержит только внесённые в дневник данные', $content);
        $tablePosition = strpos($content, 'id="daily-table-title"');
        $productsPosition = strpos($content, 'id="products-title"');
        self::assertNotFalse($tablePosition);
        self::assertNotFalse($productsPosition);
        self::assertLessThan(
            $productsPosition,
            $tablePosition,
        );
    }

    public function testWeightChangeStartsWithFirstMeasurementInsideSelectedPeriod(): void
    {
        $food = new FoodRecordRepository($this->pdo, $this->clock, $this->profile);
        $weights = new WeightRecordRepository($this->pdo, $this->clock);
        $food->save('2026-06-01', 10, 0);
        $weights->save('2026-05-31', 9.0);
        $weights->save('2026-06-10', 4.8);
        $weights->save('2026-06-16', 5.0);
        $weights->save('2026-06-17', 8.0);
        $weights->save('2026-06-25', 5.4);
        $weights->save('2026-07-01', 5.6);
        $weights->save('2026-07-02', 9.0);

        $response = $this->reportController()->report(Request::create(
            '/report/?period=custom&from=2026-06-01&to=2026-07-01',
        ));
        $content = $this->content($response);
        $config = $this->pageConfig($content);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('<strong>+600 г</strong>', $content);
        self::assertStringContainsString(
            'Первые 7 дней: 4,90 кг, последние 7 дней: 5,50 кг',
            $content,
        );
        self::assertSame(
            ['from' => '2026-06-01', 'to' => '2026-07-01'],
            $config['dateRange'],
        );
        self::assertStringContainsString('01.06.2026', $content);
        self::assertStringNotContainsString('31.05.2026', $content);
        self::assertStringNotContainsString('02.07.2026', $content);
    }

    public function testLateFirstWeightWindowIsTruncatedAndMayOverlapTrailingWindow(): void
    {
        $weights = new WeightRecordRepository($this->pdo, $this->clock);
        $weights->save('2026-07-08', 4.8);
        $weights->save('2026-07-10', 5.0);

        $response = $this->reportController()->report(Request::create(
            '/report/?period=custom&from=2026-07-01&to=2026-07-10',
        ));
        $content = $this->content($response);

        self::assertStringContainsString('<strong>4,90 кг</strong>', $content);
        self::assertStringContainsString('<strong>0 г</strong>', $content);
        self::assertStringContainsString(
            'Первые 7 дней: 4,90 кг, последние 7 дней: 4,90 кг',
            $content,
        );
    }

    public function testReportMarksPrintProfileFieldsWithoutRemovingScreenDetails(): void
    {
        $response = $this->reportController()->report(Request::create('/report/'));
        $content = $this->content($response);

        foreach ([
            'report-pet-name',
            'report-pet-species',
            'report-pet-breed',
            'report-pet-sex',
            'report-pet-diagnosis',
            'report-pet-coat-color',
            'report-pet-birth-date',
            'report-pet-age',
        ] as $className) {
            self::assertStringContainsString(sprintf('class="%s"', $className), $content);
        }
        self::assertStringContainsString('class="report-section report-summary"', $content);
        self::assertStringContainsString('<dt>Вид</dt><dd>Домашняя кошка</dd>', $content);
        self::assertStringContainsString('<dt>Порода</dt><dd>Метис</dd>', $content);
        self::assertStringContainsString('<dt>Диагноз</dt><dd>ХПН 1-2 стадия</dd>', $content);
        self::assertStringContainsString('<dt>Окрас</dt><dd>Голубой табби с белым</dd>', $content);
        self::assertStringContainsString('<dt>Дата рождения</dt><dd>8 апреля 2016</dd>', $content);

        $namePosition = strpos($content, 'class="report-pet-name"');
        $sexPosition = strpos($content, 'class="report-pet-sex"');
        $diagnosisPosition = strpos($content, 'class="report-pet-diagnosis"');
        $agePosition = strpos($content, 'class="report-pet-age"');
        self::assertIsInt($namePosition);
        self::assertIsInt($sexPosition);
        self::assertIsInt($diagnosisPosition);
        self::assertIsInt($agePosition);
        self::assertLessThan($sexPosition, $namePosition);
        self::assertLessThan($diagnosisPosition, $sexPosition);
        self::assertLessThan($agePosition, $diagnosisPosition);
    }

    public function testThirtyDayPresetUsesInclusiveCalendarWindow(): void
    {
        $food = new FoodRecordRepository($this->pdo, $this->clock, $this->profile);
        $food->save('2026-06-26', 10, 0);
        $food->save('2026-06-27', 20, 0);

        $response = $this->reportController()->report(Request::create('/report/?period=30'));
        $content = $this->content($response);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringNotContainsString('26.06.2026', $content);
        self::assertStringContainsString('27.06.2026', $content);
        self::assertStringContainsString('1 запись', $content);
        self::assertStringNotContainsString('пропуски не считаются нулём</small>', $content);
        self::assertStringNotContainsString('Полнота дневника кормления', $content);
    }

    public function testNinetyDayPresetUsesInclusiveCalendarWindow(): void
    {
        $food = new FoodRecordRepository($this->pdo, $this->clock, $this->profile);
        $food->save('2026-04-27', 10, 0);
        $food->save('2026-04-28', 20, 0);

        $response = $this->reportController()->report(Request::create('/report/?period=90'));
        $content = $this->content($response);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringNotContainsString('27.04.2026', $content);
        self::assertStringContainsString('28.04.2026', $content);
    }

    public function testDefaultAllPeriodWithEmptyHistoryProducesOneDayEmptyReport(): void
    {
        $response = $this->reportController()->report(Request::create('/report/'));
        $content = $this->content($response);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString(
            'class="report-period-link selected" href="/report/?period=all"',
            $content,
        );
        self::assertStringContainsString('с 26 июля 2026 по 26 июля 2026', $content);
        self::assertStringContainsString('0 записей', $content);
        self::assertStringNotContainsString('Полнота дневника кормления', $content);
        self::assertStringContainsString(
            'Нет записей о кормлении и взвешиваниях за выбранный период.',
            $content,
        );
        self::assertStringContainsString('"foodPoints":[]', $content);
        self::assertStringContainsString('"foodContextPoints":[]', $content);
        self::assertStringContainsString('"weightPoints":[]', $content);
        self::assertStringContainsString('"weightContextPoints":[]', $content);
    }

    public function testCombinedTableJoinsRecordsByDateAndMarksMissingValues(): void
    {
        $food = new FoodRecordRepository($this->pdo, $this->clock, $this->profile);
        $weights = new WeightRecordRepository($this->pdo, $this->clock);
        $food->save('2026-07-10', 10, 0);
        $food->save('2026-07-12', 12, 0);
        $weights->save('2026-07-10', 4.60);
        $weights->save('2026-07-11', 4.70);

        $response = $this->reportController()->report(Request::create(
            '/report/?period=custom&from=2026-07-10&to=2026-07-12',
        ));
        $content = $this->content($response);
        $completeRow = $this->tableRow($content, '2026-07-10');
        $weightOnlyRow = $this->tableRow($content, '2026-07-11');
        $foodOnlyRow = $this->tableRow($content, '2026-07-12');

        self::assertStringContainsString('Дневник кормления и веса', $content);
        self::assertStringContainsString('42,0 ккал', $completeRow);
        self::assertStringContainsString('4,60 кг', $completeRow);
        self::assertStringNotContainsString('—', $completeRow);
        self::assertStringContainsString('<td class="report-calories">—</td>', $weightOnlyRow);
        self::assertStringContainsString('4,70 кг', $weightOnlyRow);
        self::assertStringContainsString('50,4 ккал', $foodOnlyRow);
        self::assertStringContainsString('<td class="report-weight">—</td>', $foodOnlyRow);
        self::assertStringNotContainsString('<th scope="col">Сухой корм</th>', $content);
        self::assertStringNotContainsString('<th scope="col">Влажный корм</th>', $content);
        self::assertLessThan(
            strpos($content, '12.07.2026'),
            strpos($content, '10.07.2026'),
        );
    }

    public function testCombinedTableRepeatsHeadersAcrossPrintChunks(): void
    {
        $weights = new WeightRecordRepository($this->pdo, $this->clock);
        for ($day = 1; $day <= 29; ++$day) {
            $weights->save(sprintf('2026-06-%02d', $day), 4.0 + $day / 100);
        }

        $response = $this->reportController()->report(Request::create(
            '/report/?period=custom&from=2026-06-01&to=2026-06-29',
        ));
        $content = $this->content($response);

        self::assertSame(2, substr_count($content, '<table class="report-table report-data-table">'));
        self::assertSame(2, substr_count($content, '<th scope="col">Калорийность</th>'));
        self::assertSame(2, substr_count($content, '<th scope="col">Вес</th>'));
    }

    public function testCustomPeriodIncludesBothBoundariesAndOrdersRowsChronologically(): void
    {
        $food = new FoodRecordRepository($this->pdo, $this->clock, $this->profile);
        $food->save('2026-07-09', 9, 0);
        $food->save('2026-07-10', 10, 0);
        $food->save('2026-07-12', 12, 0);
        $food->save('2026-07-13', 13, 0);

        $response = $this->reportController()->report(Request::create(
            '/report/?period=custom&from=2026-07-10&to=2026-07-12',
        ));
        $content = $this->content($response);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringNotContainsString('09.07.2026', $content);
        self::assertStringNotContainsString('13.07.2026', $content);
        self::assertLessThan(
            strpos($content, '12.07.2026'),
            strpos($content, '10.07.2026'),
        );
        self::assertStringContainsString('2 записи', $content);
    }

    public function testShortPeriodUsesContextForTrailingWeightWindowOnly(): void
    {
        $weights = new WeightRecordRepository($this->pdo, $this->clock);
        $weights->save('2026-07-09', 9.0);
        $weights->save('2026-07-10', 4.8);
        $weights->save('2026-07-12', 5.0);
        $weights->save('2026-07-15', 5.2);
        $weights->save('2026-07-16', 9.0);

        $response = $this->reportController()->report(Request::create(
            '/report/?period=custom&from=2026-07-10&to=2026-07-15',
        ));
        $content = $this->content($response);

        self::assertStringContainsString('Средний вес за последние 7 дней', $content);
        self::assertStringContainsString('<strong>6,00 кг</strong>', $content);
        self::assertStringContainsString('<strong>+1000 г</strong>', $content);
        self::assertStringContainsString('Первые 7 дней: 5,00 кг, последние 7 дней: 6,00 кг', $content);
        self::assertStringNotContainsString('09.07.2026', $content);
        self::assertStringNotContainsString('16.07.2026', $content);
    }

    public function testWeightAveragesUseOneOrMoreMeasurementsInEachWindow(): void
    {
        $weights = new WeightRecordRepository($this->pdo, $this->clock);
        $weights->save('2026-07-01', 4.8);
        $weights->save('2026-07-07', 5.0);
        $weights->save('2026-07-14', 5.4);
        $weights->save('2026-07-17', 5.5);
        $weights->save('2026-07-20', 5.6);

        $response = $this->reportController()->report(Request::create(
            '/report/?period=custom&from=2026-07-01&to=2026-07-20',
        ));
        $content = $this->content($response);

        self::assertStringContainsString('<strong>5,50 кг</strong>', $content);
        self::assertStringContainsString('<strong>+600 г</strong>', $content);
        self::assertStringContainsString('Первые 7 дней: 4,90 кг, последние 7 дней: 5,50 кг', $content);
        self::assertStringNotContainsString('нужно не менее 3', mb_strtolower($content));
    }

    public function testSingleRecordedValueIsEnoughForWeightAndCalorieAverages(): void
    {
        $food = new FoodRecordRepository($this->pdo, $this->clock, $this->profile);
        $weights = new WeightRecordRepository($this->pdo, $this->clock);
        $food->save('2026-07-10', 10, 0);
        $weights->save('2026-07-10', 4.8);

        $response = $this->reportController()->report(Request::create(
            '/report/?period=custom&from=2026-07-10&to=2026-07-10',
        ));
        $content = $this->content($response);

        self::assertStringContainsString('<strong>4,80 кг</strong>', $content);
        self::assertStringContainsString('<strong>0 г</strong>', $content);
        self::assertStringContainsString('Первые 7 дней: 4,80 кг, последние 7 дней: 4,80 кг', $content);
        self::assertSame(1, substr_count($content, '1 измерение'));
        self::assertSame(2, substr_count($content, '<strong>42,0 ккал</strong>'));
        self::assertStringNotContainsString('Недостаточно данных', $content);
    }

    public function testWeightChangeUsesUnroundedWindowAverages(): void
    {
        $weights = new WeightRecordRepository($this->pdo, $this->clock);
        foreach (['2026-07-01', '2026-07-03', '2026-07-07'] as $date) {
            $weights->save($date, 3.996);
        }
        foreach (['2026-07-14', '2026-07-17', '2026-07-20'] as $date) {
            $weights->save($date, 4.004);
        }

        $response = $this->reportController()->report(Request::create(
            '/report/?period=custom&from=2026-07-01&to=2026-07-20',
        ));
        $content = $this->content($response);

        self::assertStringContainsString('Первые 7 дней: 4,00 кг, последние 7 дней: 4,00 кг', $content);
        self::assertStringContainsString('<strong>+8 г</strong>', $content);
    }

    public function testWeightChangeShowsNegativeWholeGrams(): void
    {
        $weights = new WeightRecordRepository($this->pdo, $this->clock);
        $weights->save('2026-07-01', 5.60);
        $weights->save('2026-07-20', 5.54);

        $response = $this->reportController()->report(Request::create(
            '/report/?period=custom&from=2026-07-01&to=2026-07-20',
        ));
        $content = $this->content($response);

        self::assertStringContainsString('<strong>-60 г</strong>', $content);
        self::assertStringContainsString(
            'Первые 7 дней: 5,60 кг, последние 7 дней: 5,54 кг',
            $content,
        );
    }

    public function testWeightChangeDeterminesSignAfterRoundingToGrams(): void
    {
        $weights = new WeightRecordRepository($this->pdo, $this->clock);
        $weights->save('2026-07-01', 4.8000);
        $weights->save('2026-07-20', 4.8004);

        $response = $this->reportController()->report(Request::create(
            '/report/?period=custom&from=2026-07-01&to=2026-07-20',
        ));
        $content = $this->content($response);

        self::assertStringContainsString('<strong>0 г</strong>', $content);
        self::assertStringNotContainsString('<strong>+0 г</strong>', $content);
    }

    public function testRecentCaloriesUseRecordedDaysAndHistoricalSnapshots(): void
    {
        $historicalFood = new FoodRecordRepository($this->pdo, $this->clock, $this->profile);
        $historicalFood->save('2026-07-01', 10, 0);
        $historicalFood->save('2026-07-13', 100, 0);
        $historicalFood->save('2026-07-14', 10, 0);

        $changedProfile = $this->profile;
        $changedProfile['dryCaloriesPerGram'] = 5.0;
        $currentFood = new FoodRecordRepository($this->pdo, $this->clock, $changedProfile);
        $currentFood->save('2026-07-17', 10, 0);
        $currentFood->save('2026-07-20', 20, 0);
        $currentFood->save('2026-07-21', 100, 0);

        $response = $this->reportController()->report(Request::create(
            '/report/?period=custom&from=2026-07-01&to=2026-07-20',
        ));
        $content = $this->content($response);

        self::assertStringContainsString('Средние калории за последние 7 дней', $content);
        self::assertStringContainsString('<strong>64,0 ккал</strong>', $content);
        self::assertStringContainsString('3 записи', $content);
        self::assertStringContainsString('Средние калории за выбранный период', $content);
        self::assertStringContainsString('<strong>130,8 ккал</strong>', $content);
        self::assertStringContainsString('5 записей', $content);
        self::assertStringNotContainsString('21.07.2026', $content);
        self::assertStringContainsString('4,2 ккал/г', $content);
        self::assertStringContainsString('5 ккал/г', $content);
    }

    public function testRecentCaloriesUseTwoRecordedDaysWithoutZeroFillingGaps(): void
    {
        $food = new FoodRecordRepository($this->pdo, $this->clock, $this->profile);
        $food->save('2026-07-14', 10, 0);
        $food->save('2026-07-20', 20, 0);

        $response = $this->reportController()->report(Request::create(
            '/report/?period=custom&from=2026-07-01&to=2026-07-20',
        ));
        $content = $this->content($response);

        self::assertStringContainsString('2 записи', $content);
        self::assertSame(2, substr_count($content, '<strong>63,0 ккал</strong>'));
        self::assertStringNotContainsString('<strong>9,0 ккал</strong>', $content);
    }

    public function testContextRecordsAffectOnlyRollingCalculations(): void
    {
        $contextProfile = $this->profile;
        $contextProfile['dryName'] = 'Контекстный корм';
        $food = new FoodRecordRepository($this->pdo, $this->clock, $contextProfile);
        $weights = new WeightRecordRepository($this->pdo, $this->clock);
        $food->save('2026-07-03', 100, 0);
        $food->save('2026-07-05', 10, 0);
        $food->save('2026-07-06', 20, 0);
        $selectedProfile = $this->profile;
        $selectedProfile['dryName'] = 'Корм выбранного периода';
        $selectedFood = new FoodRecordRepository($this->pdo, $this->clock, $selectedProfile);
        $selectedFood->save('2026-07-10', 30, 0);
        $selectedFood->save('2026-07-12', 40, 0);
        $weights->save('2026-07-03', 9.0);
        $weights->save('2026-07-05', 8.0);
        $weights->save('2026-07-06', 6.0);
        $weights->save('2026-07-10', 4.0);
        $weights->save('2026-07-12', 5.0);

        $response = $this->reportController()->report(Request::create(
            '/report/?period=custom&from=2026-07-10&to=2026-07-12',
        ));
        $content = $this->content($response);
        $config = $this->pageConfig($content);

        self::assertSame(['2026-07-10', '2026-07-12'], array_column($config['foodPoints'], 'date'));
        self::assertSame(
            ['2026-07-05', '2026-07-06', '2026-07-10', '2026-07-12'],
            array_column($config['foodContextPoints'], 'date'),
        );
        self::assertSame(['2026-07-10', '2026-07-12'], array_column($config['weightPoints'], 'date'));
        self::assertSame(
            ['2026-07-05', '2026-07-06', '2026-07-10', '2026-07-12'],
            array_column($config['weightContextPoints'], 'date'),
        );
        self::assertStringContainsString('<strong>5,00 кг</strong>', $content);
        self::assertStringContainsString('<strong>+500 г</strong>', $content);
        self::assertStringContainsString('<strong>126,0 ккал</strong>', $content);
        self::assertStringContainsString('<strong>147,0 ккал</strong>', $content);
        self::assertStringNotContainsString('03.07.2026', $content);
        self::assertStringNotContainsString('05.07.2026', $content);
        self::assertStringNotContainsString('06.07.2026', $content);
        self::assertStringContainsString('Корм выбранного периода', $content);
        self::assertStringNotContainsString('Контекстный корм', $content);
    }

    public function testAllPeriodStartsAtEarliestNonFutureRecordAndExcludesFutureRecords(): void
    {
        $food = new FoodRecordRepository($this->pdo, $this->clock, $this->profile);
        $weights = new WeightRecordRepository($this->pdo, $this->clock);
        $food->save('2025-12-31', 25, 0);
        $food->save('2026-07-27', 30, 0);
        $weights->save('2026-01-15', 4.8);
        $weights->save('2026-07-27', 4.9);

        $response = $this->reportController()->report(Request::create('/report/?period=all'));
        $content = $this->content($response);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('с 31 декабря 2025 по 15 января 2026', $content);
        self::assertStringContainsString('31.12.2025', $content);
        self::assertStringContainsString('15.01.2026', $content);
        self::assertStringNotContainsString('27.07.2026', $content);
        self::assertSame(
            ['from' => '2025-12-31', 'to' => '2026-01-15'],
            $this->pageConfig($content)['dateRange'],
        );
    }

    #[DataProvider('invalidPeriods')]
    public function testInvalidPeriodReturnsFormWithoutPartialReport(string $uri): void
    {
        $food = new FoodRecordRepository($this->pdo, $this->clock, $this->profile);
        $food->save('2026-07-10', 10, 0);

        $response = $this->reportController()->report(Request::create($uri));
        $content = $this->content($response);

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertStringContainsString('role="alert"', $content);
        self::assertStringNotContainsString('id="report-title"', $content);
        self::assertStringNotContainsString('10.07.2026', $content);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidPeriods(): iterable
    {
        yield 'unknown preset' => ['/report/?period=7'];
        yield 'missing end' => ['/report/?period=custom&from=2026-07-01'];
        yield 'invalid date' => ['/report/?period=custom&from=2026-02-30&to=2026-07-01'];
        yield 'reversed' => ['/report/?period=custom&from=2026-07-10&to=2026-07-01'];
        yield 'future' => ['/report/?period=custom&from=2026-07-01&to=2026-07-27'];
        yield 'array period' => ['/report/?period[]=custom&from=2026-07-01&to=2026-07-10'];
    }

    public function testReportRejectsNonGetRequests(): void
    {
        $response = $this->reportController()->report(Request::create('/report/', 'POST'));

        self::assertSame(Response::HTTP_METHOD_NOT_ALLOWED, $response->getStatusCode());
        self::assertSame('GET', $response->headers->get('Allow'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    private function content(Response $response): string
    {
        $content = $response->getContent();
        self::assertNotFalse($content);

        return $content;
    }

    /**
     * @return array{
     *     foodPoints: list<array{date: string, value: float}>,
     *     foodContextPoints: list<array{date: string, value: float}>,
     *     weightPoints: list<array{date: string, value: float}>,
     *     weightContextPoints: list<array{date: string, value: float}>,
     *     dateRange: array{from: string, to: string}|null
     * }
     */
    private function pageConfig(string $content): array
    {
        $found = preg_match(
            '~<script id="page-config" type="application/json">(.*?)</script>~s',
            $content,
            $matches,
        );
        self::assertSame(1, $found, 'Page configuration was not found.');
        $config = json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($config);

        /** @var array{
         *     foodPoints: list<array{date: string, value: float}>,
         *     foodContextPoints: list<array{date: string, value: float}>,
         *     weightPoints: list<array{date: string, value: float}>,
         *     weightContextPoints: list<array{date: string, value: float}>,
         *     dateRange: array{from: string, to: string}|null
         * } $config
         */
        return $config;
    }

    private function tableRow(string $content, string $date): string
    {
        $found = preg_match(
            sprintf('~<tr>\s*<td><time datetime="%s">.*?</tr>~s', preg_quote($date, '~')),
            $content,
            $matches,
        );
        self::assertSame(1, $found, sprintf('Table row for %s was not found.', $date));

        return $matches[0];
    }
}
