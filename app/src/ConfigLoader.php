<?php

declare(strict_types=1);

namespace Khalyuzh;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use Throwable;
use UnexpectedValueException;

final class ConfigLoader
{
    public const string ENVIRONMENT_VARIABLE = 'KHALYUZH_CONFIG_FILE';

    private const array TOP_LEVEL_KEYS = ['timezone', 'pet', 'profile'];

    private const array PET_KEYS = [
        'name',
        'nameGenitive',
        'species',
        'breed',
        'sex',
        'reproductiveStatus',
        'diagnosis',
        'coatColor',
        'birthDate',
    ];

    private const array PROFILE_KEYS = [
        'catWeight',
        'dryName',
        'dryCaloriesPerGram',
        'wetName',
        'wetCaloriesPerCan',
        'targetMin',
        'targetMax',
    ];

    /**
     * @return array{
     *     databasePath: string,
     *     timezone: string,
     *     pet: array{
     *         name: string,
     *         nameGenitive: string,
     *         species: string,
     *         breed: string,
     *         sex: string,
     *         reproductiveStatus: string,
     *         diagnosis: string,
     *         coatColor: string,
     *         birthDate: string
     *     },
     *     profile: array{
     *         catWeight: float|int,
     *         dryName: string,
     *         dryCaloriesPerGram: float|int,
     *         wetName: string,
     *         wetCaloriesPerCan: float|int,
     *         targetMin: float|int,
     *         targetMax: float|int
     *     }
     * }
     */
    public static function load(string $projectRoot): array
    {
        $externalPath = self::externalPath();
        if ($externalPath === null) {
            $config = require $projectRoot . '/app/config.php';
            if (!is_array($config)) {
                throw new UnexpectedValueException('The local application config must return an array.');
            }
        } else {
            $config = self::loadJson($externalPath);
        }

        self::assertExactKeys($config, self::TOP_LEVEL_KEYS, 'config');
        self::assertString($config, 'timezone', 'config');
        self::assertTimezone($config['timezone']);

        $pet = self::assertArray($config, 'pet', 'config');
        self::assertExactKeys($pet, self::PET_KEYS, 'pet');
        foreach (self::PET_KEYS as $key) {
            self::assertString($pet, $key, 'pet');
        }
        self::assertDate($pet['birthDate'], 'pet.birthDate');

        $profile = self::assertArray($config, 'profile', 'config');
        self::assertExactKeys($profile, self::PROFILE_KEYS, 'profile');
        foreach (['dryName', 'wetName'] as $key) {
            self::assertString($profile, $key, 'profile');
        }
        foreach (['catWeight', 'dryCaloriesPerGram', 'wetCaloriesPerCan'] as $key) {
            self::assertPositiveNumber($profile, $key);
        }
        foreach (['targetMin', 'targetMax'] as $key) {
            self::assertNonNegativeNumber($profile, $key);
        }
        if ((float) $profile['targetMin'] > (float) $profile['targetMax']) {
            throw new UnexpectedValueException('profile.targetMin must not exceed profile.targetMax.');
        }

        /** @var array{
         *     timezone: string,
         *     pet: array{
         *         name: string,
         *         nameGenitive: string,
         *         species: string,
         *         breed: string,
         *         sex: string,
         *         reproductiveStatus: string,
         *         diagnosis: string,
         *         coatColor: string,
         *         birthDate: string
         *     },
         *     profile: array{
         *         catWeight: float|int,
         *         dryName: string,
         *         dryCaloriesPerGram: float|int,
         *         wetName: string,
         *         wetCaloriesPerCan: float|int,
         *         targetMin: float|int,
         *         targetMax: float|int
         *     }
         * } $config
         */
        return [
            'databasePath' => $projectRoot . '/storage/records.sqlite',
            ...$config,
        ];
    }

    private static function externalPath(): ?string
    {
        if (array_key_exists(self::ENVIRONMENT_VARIABLE, $_SERVER)) {
            $value = $_SERVER[self::ENVIRONMENT_VARIABLE];
            if (!is_string($value)) {
                throw new UnexpectedValueException(self::ENVIRONMENT_VARIABLE . ' must be a string.');
            }

            return self::assertAbsolutePath($value);
        }

        $value = getenv(self::ENVIRONMENT_VARIABLE);
        if ($value === false) {
            return null;
        }

        return self::assertAbsolutePath($value);
    }

    private static function assertAbsolutePath(string $path): string
    {
        $windowsAbsolute = preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
        if ($path === '' || (!str_starts_with($path, '/') && !$windowsAbsolute)) {
            throw new UnexpectedValueException(self::ENVIRONMENT_VARIABLE . ' must be an absolute path.');
        }

        return $path;
    }

    /**
     * @return array<string, mixed>
     */
    private static function loadJson(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new UnexpectedValueException("External config is not a readable file: $path");
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new UnexpectedValueException("External config could not be read: $path");
        }

        try {
            $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new UnexpectedValueException(
                "External config contains invalid JSON: $path",
                previous: $exception,
            );
        }

        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new UnexpectedValueException('External config must contain a JSON object.');
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $value
     * @param list<string> $expected
     */
    private static function assertExactKeys(array $value, array $expected, string $section): void
    {
        $actual = array_keys($value);
        $missing = array_values(array_diff($expected, $actual));
        $unknown = array_values(array_diff($actual, $expected));

        if ($missing !== []) {
            throw new UnexpectedValueException(
                sprintf('%s is missing required keys: %s.', $section, implode(', ', $missing)),
            );
        }
        if ($unknown !== []) {
            throw new UnexpectedValueException(
                sprintf('%s contains unknown keys: %s.', $section, implode(', ', $unknown)),
            );
        }
    }

    /**
     * @param array<string, mixed> $value
     *
     * @return array<string, mixed>
     */
    private static function assertArray(array $value, string $key, string $section): array
    {
        if (!is_array($value[$key]) || array_is_list($value[$key])) {
            throw new UnexpectedValueException("$section.$key must be an object.");
        }

        return $value[$key];
    }

    /**
     * @param array<string, mixed> $value
     */
    private static function assertString(array $value, string $key, string $section): void
    {
        if (!is_string($value[$key]) || trim($value[$key]) === '') {
            throw new UnexpectedValueException("$section.$key must be a non-empty string.");
        }
    }

    private static function assertTimezone(string $timezone): void
    {
        try {
            new DateTimeZone($timezone);
        } catch (Throwable $exception) {
            throw new UnexpectedValueException(
                'config.timezone must be a valid timezone identifier.',
                previous: $exception,
            );
        }
    }

    private static function assertDate(string $value, string $field): void
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new UnexpectedValueException("$field must be a valid YYYY-MM-DD date.");
        }
    }

    /**
     * @param array<string, mixed> $profile
     */
    private static function assertPositiveNumber(array $profile, string $key): void
    {
        self::assertFiniteNumber($profile, $key);
        if ((float) $profile[$key] <= 0) {
            throw new UnexpectedValueException("profile.$key must be greater than zero.");
        }
    }

    /**
     * @param array<string, mixed> $profile
     */
    private static function assertNonNegativeNumber(array $profile, string $key): void
    {
        self::assertFiniteNumber($profile, $key);
        if ((float) $profile[$key] < 0) {
            throw new UnexpectedValueException("profile.$key must not be negative.");
        }
    }

    /**
     * @param array<string, mixed> $profile
     */
    private static function assertFiniteNumber(array $profile, string $key): void
    {
        if ((!is_int($profile[$key]) && !is_float($profile[$key]))
            || !is_finite((float) $profile[$key])) {
            throw new UnexpectedValueException("profile.$key must be a finite number.");
        }
    }
}
