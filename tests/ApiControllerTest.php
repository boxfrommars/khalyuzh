<?php

declare(strict_types=1);

namespace Khalyuzh\Tests;

use Khalyuzh\ApiController;
use Khalyuzh\Database;
use Khalyuzh\FoodRecordRepository;
use Khalyuzh\WeightRecordRepository;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class ApiControllerTest extends DatabaseTestCase
{
    public function testPublicWeightApiIsReadOnly(): void
    {
        $response = $this->apiController()->weight($this->jsonRequest(
            '/weight/api.php?date=2026-07-26',
            'PUT',
            ['weightKg' => 5.48],
        ), false);

        self::assertSame(Response::HTTP_METHOD_NOT_ALLOWED, $response->getStatusCode());
        self::assertSame('GET', $response->headers->get('Allow'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function testWeightApiCrudAndJsonContract(): void
    {
        $controller = $this->apiController();
        $created = $controller->weight($this->jsonRequest(
            '/admin/weight/api.php?date=2026-07-26',
            'PUT',
            ['weightKg' => 5.48],
        ), true);

        self::assertSame(Response::HTTP_CREATED, $created->getStatusCode());
        self::assertSame('application/json; charset=utf-8', $created->headers->get('Content-Type'));
        self::assertSame(5.48, $this->json($created)['record']['weightKg']);

        $updated = $controller->weight($this->jsonRequest(
            '/admin/weight/api.php?date=2026-07-26',
            'PUT',
            ['weightKg' => 5.51],
        ), true);
        self::assertSame(Response::HTTP_OK, $updated->getStatusCode());

        $history = $controller->weight(Request::create('/weight/api.php', 'GET'), false);
        self::assertSame([5.51], array_column($this->json($history)['records'], 'weightKg'));

        $deleted = $controller->weight(
            Request::create('/admin/weight/api.php?date=2026-07-26', 'DELETE'),
            true,
        );
        self::assertSame(Response::HTTP_NO_CONTENT, $deleted->getStatusCode());
    }

    #[DataProvider('invalidWeightPayloads')]
    public function testWeightApiRejectsInvalidWeight(string $json): void
    {
        $response = $this->apiController()->weight($this->rawJsonRequest(
            '/admin/weight/api.php?date=2026-07-26',
            'PUT',
            $json,
        ), true);

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidWeightPayloads(): iterable
    {
        yield 'missing' => ['{}'];
        yield 'string' => ['{"weightKg":"5.48"}'];
        yield 'zero' => ['{"weightKg":0}'];
        yield 'negative' => ['{"weightKg":-1}'];
        yield 'infinite' => ['{"weightKg":1e400}'];
    }

    #[DataProvider('invalidWeightDates')]
    public function testWeightApiRejectsInvalidDate(string $uri): void
    {
        $response = $this->apiController()->weight($this->jsonRequest(
            $uri,
            'PUT',
            ['weightKg' => 5.48],
        ), true);

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidWeightDates(): iterable
    {
        yield 'missing' => ['/admin/weight/api.php'];
        yield 'wrong format' => ['/admin/weight/api.php?date=26.07.2026'];
        yield 'impossible' => ['/admin/weight/api.php?date=2026-02-30'];
        yield 'future' => ['/admin/weight/api.php?date=2026-07-27'];
        yield 'array' => ['/admin/weight/api.php?date[]=2026-07-26'];
    }

    #[DataProvider('invalidJsonBodies')]
    public function testApiRejectsInvalidJsonBody(string $json): void
    {
        $response = $this->apiController()->weight($this->rawJsonRequest(
            '/admin/weight/api.php?date=2026-07-26',
            'PUT',
            $json,
        ), true);

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidJsonBodies(): iterable
    {
        yield 'empty' => [''];
        yield 'malformed' => ['{'];
        yield 'not an object' => ['"5.48"'];
    }

    public function testFoodApiKeepsExistingContractAndAllowsFutureDate(): void
    {
        $controller = $this->apiController();
        $created = $controller->food($this->jsonRequest(
            '/admin/api.php?date=2026-07-25',
            'PUT',
            ['dryAmount' => 40, 'wetAmount' => 1],
        ), true);

        self::assertSame(Response::HTTP_CREATED, $created->getStatusCode());
        self::assertSame(
            ['date', 'dryAmount', 'wetAmount', 'catWeight', 'dryName', 'dryCaloriesPerGram',
                'wetName', 'wetCaloriesPerCan', 'targetMin', 'targetMax', 'createdAt', 'updatedAt'],
            array_keys($this->json($created)['record']),
        );

        $future = $controller->food($this->jsonRequest(
            '/admin/api.php?date=2026-07-27',
            'PUT',
            ['dryAmount' => 0, 'wetAmount' => 0],
        ), true);
        self::assertSame(Response::HTTP_CREATED, $future->getStatusCode());

        $publicWrite = $controller->food($this->jsonRequest(
            '/api.php?date=2026-07-25',
            'PUT',
            ['dryAmount' => 40, 'wetAmount' => 1],
        ), false);
        self::assertSame(Response::HTTP_METHOD_NOT_ALLOWED, $publicWrite->getStatusCode());
    }

    public function testAdminApiRejectsUnsupportedMethodWithFullAllowHeader(): void
    {
        $response = $this->apiController()->weight(Request::create(
            '/admin/weight/api.php',
            'PATCH',
        ), true);

        self::assertSame(Response::HTTP_METHOD_NOT_ALLOWED, $response->getStatusCode());
        self::assertSame('GET, PUT, DELETE', $response->headers->get('Allow'));
    }

    public function testUnexpectedRepositoryFailureReturnsInternalServerError(): void
    {
        $controller = $this->apiController();
        $this->pdo->exec('DROP TABLE weight_records');
        $previousErrorLog = ini_set(
            'error_log',
            sys_get_temp_dir() . '/khalyuzh-test-errors.log',
        );

        try {
            $response = $controller->weight(Request::create('/weight/api.php', 'GET'), false);
        } finally {
            if ($previousErrorLog !== false) {
                ini_set('error_log', $previousErrorLog);
            }
        }

        self::assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
        self::assertSame('Internal server error.', $this->json($response)['error']);
    }

    public function testFutureDateUsesInjectedClockAtDayBoundary(): void
    {
        $clock = new MockClock('2026-07-27 00:01:00', 'Asia/Yerevan');
        $database = Database::inMemory($clock);
        $database->migrate();
        $pdo = $database->connection();
        $controller = new ApiController(
            new FoodRecordRepository($pdo, $clock, $this->profile),
            new WeightRecordRepository($pdo, $clock),
            $clock,
            'Asia/Yerevan',
        );

        $today = $controller->weight($this->jsonRequest(
            '/admin/weight/api.php?date=2026-07-27',
            'PUT',
            ['weightKg' => 5.48],
        ), true);
        $tomorrow = $controller->weight($this->jsonRequest(
            '/admin/weight/api.php?date=2026-07-28',
            'PUT',
            ['weightKg' => 5.48],
        ), true);

        self::assertSame(Response::HTTP_CREATED, $today->getStatusCode());
        self::assertSame(Response::HTTP_BAD_REQUEST, $tomorrow->getStatusCode());
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function jsonRequest(string $uri, string $method, array $payload): Request
    {
        return $this->rawJsonRequest(
            $uri,
            $method,
            json_encode($payload, JSON_THROW_ON_ERROR),
        );
    }

    private function rawJsonRequest(string $uri, string $method, string $json): Request
    {
        return Request::create(
            $uri,
            $method,
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            $json,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function json(Response $response): array
    {
        $content = $response->getContent();
        self::assertNotFalse($content);

        /** @var array<string, mixed> $payload */
        $payload = json_decode($content, true, 16, JSON_THROW_ON_ERROR);

        return $payload;
    }
}
