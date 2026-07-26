<?php

declare(strict_types=1);

namespace Khalyuzh;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonException;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final readonly class ApiController
{
    private DateTimeZone $timezone;

    public function __construct(
        private FoodRecordRepository $foodRecords,
        private WeightRecordRepository $weightRecords,
        private ClockInterface $clock,
        string $timezone,
    ) {
        $this->timezone = new DateTimeZone($timezone);
    }

    public function food(Request $request, bool $allowWrites): Response
    {
        return $this->records(
            request: $request,
            allowWrites: $allowWrites,
            rejectFutureDate: false,
            fetch: fn (): array => $this->foodRecords->fetchAll(),
            save: fn (string $date, array $payload): array => $this->foodRecords->save(
                $date,
                $this->number($payload['dryAmount'] ?? null, 'dryAmount', false),
                $this->number($payload['wetAmount'] ?? null, 'wetAmount', false),
            ),
            delete: function (string $date): void {
                $this->foodRecords->delete($date);
            },
        );
    }

    public function weight(Request $request, bool $allowWrites): Response
    {
        return $this->records(
            request: $request,
            allowWrites: $allowWrites,
            rejectFutureDate: true,
            fetch: fn (): array => $this->weightRecords->fetchAll(),
            save: fn (string $date, array $payload): array => $this->weightRecords->save(
                $date,
                $this->number($payload['weightKg'] ?? null, 'weightKg', true),
            ),
            delete: function (string $date): void {
                $this->weightRecords->delete($date);
            },
        );
    }

    /**
     * @param Closure(): list<array<string, mixed>> $fetch
     * @param Closure(string, array<string, mixed>): array{
     *     record: array<string, mixed>,
     *     created: bool
     * } $save
     * @param Closure(string): void $delete
     */
    private function records(
        Request $request,
        bool $allowWrites,
        bool $rejectFutureDate,
        Closure $fetch,
        Closure $save,
        Closure $delete,
    ): Response {
        $method = strtoupper($request->getMethod());
        $query = $request->query->all();

        try {
            if ($method === Request::METHOD_GET) {
                return $this->json(['records' => $fetch()]);
            }

            if (!$allowWrites) {
                return $this->methodNotAllowed('GET');
            }

            if ($method === Request::METHOD_PUT) {
                $date = $this->date($query['date'] ?? null, $rejectFutureDate);
                $result = $save($date, $this->payload($request));

                return $this->json(
                    ['record' => $result['record']],
                    $result['created'] ? Response::HTTP_CREATED : Response::HTTP_OK,
                );
            }

            if ($method === Request::METHOD_DELETE) {
                $delete($this->date($query['date'] ?? null, $rejectFutureDate));

                return new Response('', Response::HTTP_NO_CONTENT, ['Cache-Control' => 'no-store']);
            }

            return $this->methodNotAllowed('GET, PUT, DELETE');
        } catch (InvalidArgumentException $error) {
            return $this->json(['error' => $error->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (Throwable $error) {
            error_log((string) $error);

            return $this->json(['error' => 'Internal server error.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    private function methodNotAllowed(string $allow): Response
    {
        $response = $this->json(['error' => 'Method not allowed.'], Response::HTTP_METHOD_NOT_ALLOWED);
        $response->headers->set('Allow', $allow);

        return $response;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(array $payload, int $status = Response::HTTP_OK): JsonResponse
    {
        $response = new JsonResponse($payload, $status);
        $response->setEncodingOptions(
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_THROW_ON_ERROR,
        );
        $response->headers->set('Content-Type', 'application/json; charset=utf-8');
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Request $request): array
    {
        $content = $request->getContent();
        if (trim($content) === '') {
            throw new InvalidArgumentException('Request body must contain JSON.');
        }

        try {
            $payload = json_decode($content, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new InvalidArgumentException('Request body contains invalid JSON.', 0, $error);
        }

        if (!is_array($payload)) {
            throw new InvalidArgumentException('Request body must be a JSON object.');
        }

        return $payload;
    }

    private function date(mixed $value, bool $rejectFuture): string
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            throw new InvalidArgumentException('Date must use the YYYY-MM-DD format.');
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $this->timezone);
        if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('Date is invalid.');
        }

        if ($rejectFuture && $value > $this->clock->now()->setTimezone($this->timezone)->format('Y-m-d')) {
            throw new InvalidArgumentException('Date must not be in the future.');
        }

        return $value;
    }

    private function number(mixed $value, string $field, bool $positive): float
    {
        if (!is_int($value) && !is_float($value)) {
            throw new InvalidArgumentException(sprintf('%s must be a number.', $field));
        }

        $number = (float) $value;
        if (!is_finite($number) || ($positive ? $number <= 0 : $number < 0)) {
            throw new InvalidArgumentException(sprintf(
                '%s must be a %s finite number.',
                $field,
                $positive ? 'positive' : 'non-negative',
            ));
        }

        return $number;
    }
}
