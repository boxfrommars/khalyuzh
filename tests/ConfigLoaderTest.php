<?php

declare(strict_types=1);

namespace Khalyuzh\Tests;

use Khalyuzh\ConfigLoader;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

final class ConfigLoaderTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryFiles = [];

    private bool $serverValueExisted;

    private mixed $serverValue;

    private string|false $environmentValue;

    protected function setUp(): void
    {
        $this->serverValueExisted = array_key_exists(ConfigLoader::ENVIRONMENT_VARIABLE, $_SERVER);
        $this->serverValue = $_SERVER[ConfigLoader::ENVIRONMENT_VARIABLE] ?? null;
        $this->environmentValue = getenv(ConfigLoader::ENVIRONMENT_VARIABLE);
        unset($_SERVER[ConfigLoader::ENVIRONMENT_VARIABLE]);
        putenv(ConfigLoader::ENVIRONMENT_VARIABLE);
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            @unlink($path);
        }

        if ($this->serverValueExisted) {
            $_SERVER[ConfigLoader::ENVIRONMENT_VARIABLE] = $this->serverValue;
        } else {
            unset($_SERVER[ConfigLoader::ENVIRONMENT_VARIABLE]);
        }

        if ($this->environmentValue === false) {
            putenv(ConfigLoader::ENVIRONMENT_VARIABLE);
        } else {
            putenv(ConfigLoader::ENVIRONMENT_VARIABLE . '=' . $this->environmentValue);
        }
    }

    public function testLocalDefaultIsUsedWithoutExternalPath(): void
    {
        $config = ConfigLoader::load(dirname(__DIR__));

        self::assertSame('Халюж', $config['pet']['name']);
        self::assertSame('Халюжа', $config['pet']['nameGenitive']);
        self::assertSame(
            dirname(__DIR__) . '/storage/records.sqlite',
            $config['databasePath'],
        );
    }

    public function testValidExternalJsonOverridesInstanceSettingsOnly(): void
    {
        $external = $this->validConfig();
        $external['pet']['name'] = 'Милкинс';
        $external['pet']['nameGenitive'] = 'Милкинса';
        $path = $this->writeJson($external);
        $_SERVER[ConfigLoader::ENVIRONMENT_VARIABLE] = $path;

        $config = ConfigLoader::load(dirname(__DIR__));

        self::assertSame('Милкинс', $config['pet']['name']);
        self::assertSame('Милкинса', $config['pet']['nameGenitive']);
        self::assertSame(
            dirname(__DIR__) . '/storage/records.sqlite',
            $config['databasePath'],
        );
    }

    public function testCliEnvironmentVariableLoadsExternalJson(): void
    {
        $external = $this->validConfig();
        $external['pet']['name'] = 'Милкинс';
        $path = $this->writeJson($external);
        putenv(ConfigLoader::ENVIRONMENT_VARIABLE . '=' . $path);

        $config = ConfigLoader::load(dirname(__DIR__));

        self::assertSame('Милкинс', $config['pet']['name']);
    }

    public function testExplicitMissingConfigDoesNotFallBackToLocalDefaults(): void
    {
        $_SERVER[ConfigLoader::ENVIRONMENT_VARIABLE] = dirname(__DIR__) . '/missing-config.json';

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('not a readable file');

        ConfigLoader::load(dirname(__DIR__));
    }

    public function testExternalConfigPathMustBeAbsolute(): void
    {
        $_SERVER[ConfigLoader::ENVIRONMENT_VARIABLE] = 'relative/config.json';

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('must be an absolute path');

        ConfigLoader::load(dirname(__DIR__));
    }

    public function testMalformedJsonIsRejected(): void
    {
        $path = $this->writeContents('{not json');
        $_SERVER[ConfigLoader::ENVIRONMENT_VARIABLE] = $path;

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('invalid JSON');

        ConfigLoader::load(dirname(__DIR__));
    }

    public function testMissingAndUnknownKeysAreRejected(): void
    {
        $missing = $this->validConfig();
        unset($missing['pet']['nameGenitive']);
        $this->assertInvalidConfig($missing, 'missing required keys: nameGenitive');

        $unknown = $this->validConfig();
        $unknown['databasePath'] = '/tmp/wrong.sqlite';
        $this->assertInvalidConfig($unknown, 'unknown keys: databasePath');
    }

    public function testInvalidValuesAreRejected(): void
    {
        $timezone = $this->validConfig();
        $timezone['timezone'] = 'Not/A-Timezone';
        $this->assertInvalidConfig($timezone, 'valid timezone identifier');

        $birthDate = $this->validConfig();
        $birthDate['pet']['birthDate'] = '2026-02-30';
        $this->assertInvalidConfig($birthDate, 'valid YYYY-MM-DD date');

        $calories = $this->validConfig();
        $calories['profile']['dryCaloriesPerGram'] = 0;
        $this->assertInvalidConfig($calories, 'must be greater than zero');

        $target = $this->validConfig();
        $target['profile']['targetMin'] = 400;
        $target['profile']['targetMax'] = 300;
        $this->assertInvalidConfig($target, 'must not exceed');
    }

    /**
     * @return array<string, mixed>
     */
    private function validConfig(): array
    {
        /** @var array<string, mixed> $config */
        $config = require dirname(__DIR__) . '/app/config.php';

        return $config;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function writeJson(array $config): string
    {
        return $this->writeContents((string) json_encode(
            $config,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));
    }

    private function writeContents(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'khalyuzh-config-');
        self::assertNotFalse($path);
        $this->temporaryFiles[] = $path;
        self::assertNotFalse(file_put_contents($path, $contents));

        return $path;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function assertInvalidConfig(array $config, string $message): void
    {
        $_SERVER[ConfigLoader::ENVIRONMENT_VARIABLE] = $this->writeJson($config);

        try {
            ConfigLoader::load(dirname(__DIR__));
            self::fail('Invalid config was accepted.');
        } catch (UnexpectedValueException $exception) {
            self::assertStringContainsString($message, $exception->getMessage());
        }
    }
}
