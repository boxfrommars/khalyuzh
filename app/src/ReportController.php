<?php

declare(strict_types=1);

namespace Khalyuzh;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonException;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

final readonly class ReportController
{
    private const int SUMMARY_WINDOW_DAYS = 7;

    private const array MONTHS = [
        1 => 'января',
        2 => 'февраля',
        3 => 'марта',
        4 => 'апреля',
        5 => 'мая',
        6 => 'июня',
        7 => 'июля',
        8 => 'августа',
        9 => 'сентября',
        10 => 'октября',
        11 => 'ноября',
        12 => 'декабря',
    ];

    private DateTimeZone $timezone;

    /**
     * @param array{
     *     name: string,
     *     nameGenitive: string,
     *     species: string,
     *     breed: string,
     *     sex: string,
     *     reproductiveStatus: string,
     *     diagnosis: string,
     *     coatColor: string,
     *     birthDate: string
     * } $pet
     */
    public function __construct(
        private Environment $twig,
        private FoodRecordRepository $foodRecords,
        private WeightRecordRepository $weightRecords,
        private ClockInterface $clock,
        string $timezone,
        private array $pet,
    ) {
        $this->timezone = new DateTimeZone($timezone);
    }

    /**
     * @throws JsonException
     */
    public function report(Request $request): Response
    {
        if ($request->getMethod() !== Request::METHOD_GET) {
            return new Response(
                'Method not allowed.',
                Response::HTTP_METHOD_NOT_ALLOWED,
                [
                    'Allow' => 'GET',
                    'Cache-Control' => 'no-store',
                    'Content-Type' => 'text/plain; charset=utf-8',
                ],
            );
        }

        $now = $this->clock->now()->setTimezone($this->timezone);
        $today = $now->format('Y-m-d');
        $allFoodRecords = $this->foodRecords->fetchAll();
        $allWeightRecords = $this->weightRecords->fetchAll();
        $query = $request->query->all();
        $error = null;

        try {
            $period = $this->resolvePeriod($query, $allFoodRecords, $allWeightRecords, $today);
        } catch (InvalidArgumentException $exception) {
            $error = $exception->getMessage();
            $period = [
                'key' => is_string($query['period'] ?? null) ? $query['period'] : 'custom',
                'from' => is_string($query['from'] ?? null) ? $query['from'] : '',
                'to' => is_string($query['to'] ?? null) ? $query['to'] : '',
            ];
        }

        $foodRecords = [];
        $weightRecords = [];
        $foodContextRecords = [];
        $weightContextRecords = [];
        if ($error === null) {
            $foodRecords = $this->withinPeriod($allFoodRecords, $period['from'], $period['to']);
            $weightRecords = $this->withinPeriod($allWeightRecords, $period['from'], $period['to']);
            $contextFrom = $this->shiftDate($period['from'], -(self::SUMMARY_WINDOW_DAYS - 1));
            $foodContextRecords = $this->withinPeriod($allFoodRecords, $contextFrom, $period['to']);
            $weightContextRecords = $this->withinPeriod($allWeightRecords, $contextFrom, $period['to']);
        }

        $food = $this->prepareFood($foodRecords);
        $weights = $this->prepareWeights($weightRecords);
        $foodContext = $this->prepareFood($foodContextRecords);
        $weightContext = $this->prepareWeights($weightContextRecords);
        $dailyRecords = $this->combineDailyRecords($food['records'], $weights['records']);
        $summaryTo = $error === null ? $period['to'] : $today;
        $weightSummary = $this->prepareWeightSummary(
            $weights['points'],
            $weightContext['points'],
            $summaryTo,
        );
        $recentCalories = $this->prepareRecentCalories($foodContext['points'], $summaryTo);
        $periodDisplay = $error === null
            ? sprintf('с %s по %s', $this->longDate($period['from']), $this->longDate($period['to']))
            : 'период не выбран';
        $titlePeriod = $error === null
            ? sprintf('%s-%s', $this->shortDate($period['from']), $this->shortDate($period['to']))
            : 'неверный-период';

        $content = $this->twig->render('report.html.twig', [
            'is_admin' => false,
            'active_section' => 'report',
            'page_config' => $this->pageConfig([
                'foodPoints' => $food['points'],
                'foodContextPoints' => $foodContext['points'],
                'weightPoints' => $weights['points'],
                'weightContextPoints' => $weightContext['points'],
                'dateRange' => $error === null
                    ? ['from' => $period['from'], 'to' => $period['to']]
                    : null,
            ]),
            'report_ready' => $error === null,
            'report_error' => $error,
            'report_title' => sprintf('%s - отчёт %s', $this->pet['name'], $titlePeriod),
            'period_key' => $period['key'],
            'period_from' => $period['from'],
            'period_to' => $period['to'],
            'today' => $today,
            'period_display' => $periodDisplay,
            'generated_at' => sprintf('%s, %s', $this->longDate($today), $now->format('H:i')),
            'pet' => [
                ...$this->pet,
                'birthDateDisplay' => $this->longDate($this->pet['birthDate']),
                'ageDisplay' => $this->age($this->pet['birthDate'], $today),
            ],
            'daily_record_chunks' => array_chunk($dailyRecords, 28),
            'food_products' => $food['products'],
            'weight_summary' => $weightSummary,
            'recent_calories' => $recentCalories,
            'average_calories' => $food['averageCalories'],
            'food_record_count' => count($foodRecords),
            'food_record_count_display' => $this->recordCountDisplay(
                count($foodRecords),
                'запись',
                'записи',
                'записей',
            ),
            'weight_record_count' => count($weightRecords),
        ]);

        return new Response(
            $content,
            $error === null ? Response::HTTP_OK : Response::HTTP_BAD_REQUEST,
            [
                'Cache-Control' => 'no-store',
                'Content-Type' => 'text/html; charset=utf-8',
            ],
        );
    }

    /**
     * @param array<string, mixed> $query
     * @param list<array<string, mixed>> $foodRecords
     * @param list<array<string, mixed>> $weightRecords
     * @return array{key: string, from: string, to: string}
     */
    private function resolvePeriod(
        array $query,
        array $foodRecords,
        array $weightRecords,
        string $today,
    ): array {
        $key = $query['period'] ?? 'all';
        if (!is_string($key) || !in_array($key, ['30', '90', 'all', 'custom'], true)) {
            throw new InvalidArgumentException('Выберите допустимый период отчёта.');
        }

        if ($key === '30' || $key === '90') {
            $days = (int) $key;
            $from = (new DateTimeImmutable($today, $this->timezone))
                ->modify(sprintf('-%d days', $days - 1))
                ->format('Y-m-d');

            return ['key' => $key, 'from' => $from, 'to' => $today];
        }

        if ($key === 'all') {
            $dates = [];
            foreach ([...$foodRecords, ...$weightRecords] as $record) {
                $date = $record['date'] ?? null;
                if (is_string($date) && $date <= $today) {
                    $dates[] = $date;
                }
            }

            return [
                'key' => $key,
                'from' => $dates === [] ? $today : min($dates),
                'to' => $dates === [] ? $today : max($dates),
            ];
        }

        $from = $this->validQueryDate($query['from'] ?? null);
        $to = $this->validQueryDate($query['to'] ?? null);
        if ($from > $to) {
            throw new InvalidArgumentException('Начало периода должно быть не позже его окончания.');
        }
        if ($from > $today || $to > $today) {
            throw new InvalidArgumentException('Будущие даты нельзя включить в отчёт.');
        }

        return ['key' => $key, 'from' => $from, 'to' => $to];
    }

    private function validQueryDate(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            throw new InvalidArgumentException('Укажите обе даты периода.');
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $this->timezone);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('Дата периода указана неверно.');
        }

        return $value;
    }

    /**
     * @param list<array<string, mixed>> $records
     * @return list<array<string, mixed>>
     */
    private function withinPeriod(array $records, string $from, string $to): array
    {
        $filtered = array_values(array_filter(
            $records,
            static fn (array $record): bool => $record['date'] >= $from && $record['date'] <= $to,
        ));
        usort(
            $filtered,
            static fn (array $left, array $right): int => $left['date'] <=> $right['date'],
        );

        return $filtered;
    }

    /**
     * @param list<array<string, mixed>> $records
     * @return array{
     *     records: list<array{date: string, dateDisplay: string, caloriesDisplay: string}>,
     *     products: list<array<string, string>>,
     *     points: list<array{date: string, value: float}>,
     *     averageCalories: string|null
     * }
     */
    private function prepareFood(array $records): array
    {
        $prepared = [];
        $products = [];
        $points = [];
        $totalCalories = 0.0;

        foreach ($records as $record) {
            $dryAmount = (float) $record['dryAmount'];
            $wetAmount = (float) $record['wetAmount'];
            $calories = $dryAmount * (float) $record['dryCaloriesPerGram']
                + $wetAmount * (float) $record['wetCaloriesPerCan'];
            $totalCalories += $calories;
            $points[] = ['date' => (string) $record['date'], 'value' => $calories];

            if ($dryAmount > 0) {
                $key = sprintf('dry\0%s\0%s', $record['dryName'], $record['dryCaloriesPerGram']);
                $products[$key] = [
                    'type' => 'Сухой корм',
                    'name' => (string) $record['dryName'],
                    'energy' => sprintf('%s ккал/г', $this->compact((float) $record['dryCaloriesPerGram'])),
                ];
            }
            if ($wetAmount > 0) {
                $key = sprintf('wet\0%s\0%s', $record['wetName'], $record['wetCaloriesPerCan']);
                $products[$key] = [
                    'type' => 'Влажный корм',
                    'name' => (string) $record['wetName'],
                    'energy' => sprintf('%s ккал/банка', $this->compact((float) $record['wetCaloriesPerCan'])),
                ];
            }

            $prepared[] = [
                'date' => (string) $record['date'],
                'dateDisplay' => $this->shortDate((string) $record['date']),
                'caloriesDisplay' => sprintf('%s ккал', $this->decimal($calories, 1)),
            ];
        }

        return [
            'records' => $prepared,
            'products' => array_values($products),
            'points' => $points,
            'averageCalories' => $records === []
                ? null
                : sprintf('%s ккал', $this->decimal($totalCalories / count($records), 1)),
        ];
    }

    /**
     * @param list<array<string, mixed>> $records
     * @return array{
     *     records: list<array{date: string, dateDisplay: string, weightDisplay: string}>,
     *     points: list<array{date: string, value: float}>
     * }
     */
    private function prepareWeights(array $records): array
    {
        $prepared = array_map(fn (array $record): array => [
            'date' => (string) $record['date'],
            'dateDisplay' => $this->shortDate((string) $record['date']),
            'weightDisplay' => sprintf('%s кг', $this->decimal((float) $record['weightKg'], 2)),
        ], $records);
        $points = array_map(static fn (array $record): array => [
            'date' => (string) $record['date'],
            'value' => (float) $record['weightKg'],
        ], $records);

        return [
            'records' => $prepared,
            'points' => $points,
        ];
    }

    /**
     * @param list<array{date: string, value: float}> $selectedPoints
     * @param list<array{date: string, value: float}> $contextPoints
     * @return array{
     *     first: array{average: string|null, countDisplay: string},
     *     last: array{average: string|null, countDisplay: string},
     *     change: string|null
     * }
     */
    private function prepareWeightSummary(
        array $selectedPoints,
        array $contextPoints,
        string $periodTo,
    ): array
    {
        $firstWindowFrom = $selectedPoints === []
            ? $periodTo
            : min(array_column($selectedPoints, 'date'));
        $firstWindowTo = min(
            $periodTo,
            $this->shiftDate($firstWindowFrom, self::SUMMARY_WINDOW_DAYS - 1),
        );
        $lastWindowFrom = $this->shiftDate($periodTo, -(self::SUMMARY_WINDOW_DAYS - 1));
        $first = $this->averageInWindow($selectedPoints, $firstWindowFrom, $firstWindowTo);
        $last = $this->averageInWindow($contextPoints, $lastWindowFrom, $periodTo);
        $difference = $first['average'] === null || $last['average'] === null
            ? null
            : $last['average'] - $first['average'];
        $differenceGrams = $difference === null ? null : (int) round($difference * 1000);

        return [
            'first' => [
                'average' => $first['average'] === null
                    ? null
                    : sprintf('%s кг', $this->decimal($first['average'], 2)),
                'countDisplay' => $this->recordCountDisplay(
                    $first['count'],
                    'измерение',
                    'измерения',
                    'измерений',
                ),
            ],
            'last' => [
                'average' => $last['average'] === null
                    ? null
                    : sprintf('%s кг', $this->decimal($last['average'], 2)),
                'countDisplay' => $this->recordCountDisplay(
                    $last['count'],
                    'измерение',
                    'измерения',
                    'измерений',
                ),
            ],
            'change' => $differenceGrams === null
                ? null
                : sprintf('%s%d г', $differenceGrams > 0 ? '+' : '', $differenceGrams),
        ];
    }

    /**
     * @param list<array{date: string, value: float}> $points
     * @return array{average: string|null, countDisplay: string}
     */
    private function prepareRecentCalories(array $points, string $periodTo): array
    {
        $windowFrom = $this->shiftDate($periodTo, -(self::SUMMARY_WINDOW_DAYS - 1));
        $average = $this->averageInWindow($points, $windowFrom, $periodTo);

        return [
            'average' => $average['average'] === null
                ? null
                : sprintf('%s ккал', $this->decimal($average['average'], 1)),
            'countDisplay' => $this->recordCountDisplay(
                $average['count'],
                'запись',
                'записи',
                'записей',
            ),
        ];
    }

    /**
     * @param list<array{date: string, value: float}> $points
     * @return array{average: float|null, count: int}
     */
    private function averageInWindow(array $points, string $from, string $to): array
    {
        $values = array_column(array_filter(
            $points,
            static fn (array $point): bool => $point['date'] >= $from && $point['date'] <= $to,
        ), 'value');
        $count = count($values);

        return [
            'average' => $count === 0 ? null : array_sum($values) / $count,
            'count' => $count,
        ];
    }

    private function shiftDate(string $date, int $days): string
    {
        return (new DateTimeImmutable($date, $this->timezone))
            ->modify(sprintf('%+d days', $days))
            ->format('Y-m-d');
    }

    private function recordCountDisplay(int $count, string $one, string $few, string $many): string
    {
        return sprintf('%d %s', $count, $this->plural($count, $one, $few, $many));
    }

    /**
     * @param list<array{date: string, dateDisplay: string, caloriesDisplay: string}> $foodRecords
     * @param list<array{date: string, dateDisplay: string, weightDisplay: string}> $weightRecords
     * @return list<array{
     *     date: string,
     *     dateDisplay: string,
     *     caloriesDisplay: string|null,
     *     weightDisplay: string|null
     * }>
     */
    private function combineDailyRecords(array $foodRecords, array $weightRecords): array
    {
        /** @var array<string, array{
         *     date: string,
         *     dateDisplay: string,
         *     caloriesDisplay: string|null,
         *     weightDisplay: string|null
         * }> $combined
         */
        $combined = [];

        foreach ($foodRecords as $record) {
            $combined[$record['date']] = [
                'date' => $record['date'],
                'dateDisplay' => $record['dateDisplay'],
                'caloriesDisplay' => $record['caloriesDisplay'],
                'weightDisplay' => null,
            ];
        }

        foreach ($weightRecords as $record) {
            $combined[$record['date']] ??= [
                'date' => $record['date'],
                'dateDisplay' => $record['dateDisplay'],
                'caloriesDisplay' => null,
                'weightDisplay' => null,
            ];
            $combined[$record['date']]['weightDisplay'] = $record['weightDisplay'];
        }

        ksort($combined, SORT_STRING);

        return array_values($combined);
    }

    private function age(string $birthDate, string $onDate): string
    {
        $birth = new DateTimeImmutable($birthDate, $this->timezone);
        $date = new DateTimeImmutable($onDate, $this->timezone);
        $difference = $birth->diff($date);
        $parts = [];

        if ($difference->y > 0) {
            $parts[] = sprintf('%d %s', $difference->y, $this->plural($difference->y, 'год', 'года', 'лет'));
        }
        if ($difference->m > 0) {
            $parts[] = sprintf(
                '%d %s',
                $difference->m,
                $this->plural($difference->m, 'месяц', 'месяца', 'месяцев'),
            );
        }

        return $parts === [] ? 'меньше месяца' : implode(' ', $parts);
    }

    private function plural(int $value, string $one, string $few, string $many): string
    {
        $lastTwo = abs($value) % 100;
        $last = $lastTwo % 10;
        if ($lastTwo >= 11 && $lastTwo <= 14) {
            return $many;
        }
        if ($last === 1) {
            return $one;
        }
        if ($last >= 2 && $last <= 4) {
            return $few;
        }

        return $many;
    }

    private function longDate(string $date): string
    {
        $parsed = new DateTimeImmutable($date, $this->timezone);

        return sprintf(
            '%d %s %d',
            (int) $parsed->format('j'),
            self::MONTHS[(int) $parsed->format('n')],
            (int) $parsed->format('Y'),
        );
    }

    private function shortDate(string $date): string
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $this->timezone);

        return $parsed === false ? $date : $parsed->format('d.m.Y');
    }

    private function compact(float $value): string
    {
        return rtrim(rtrim($this->decimal($value, 3), '0'), ',');
    }

    private function decimal(float $value, int $precision): string
    {
        return number_format($value, $precision, ',', ' ');
    }

    /**
     * @param array<string, mixed> $config
     *
     * @throws JsonException
     */
    private function pageConfig(array $config): string
    {
        return json_encode(
            $config,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_THROW_ON_ERROR
            | JSON_HEX_TAG,
        );
    }
}
