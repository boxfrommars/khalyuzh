<?php

declare(strict_types=1);

namespace Khalyuzh\Tests;

use Khalyuzh\ApiController;
use Khalyuzh\AppFactory;
use Khalyuzh\Application;
use Khalyuzh\PageController;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class ApplicationTest extends DatabaseTestCase
{
    #[DataProvider('routes')]
    public function testRoutesAreDispatchedWithExpectedContext(
        string $path,
        string $expectedContent,
        bool $admin,
    ): void {
        $server = $admin ? ['REMOTE_USER' => 'tester'] : [];
        $response = $this->application()->handle(Request::create($path, 'GET', [], [], [], $server));
        $content = $response->getContent();

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertNotFalse($content);
        self::assertStringContainsString($expectedContent, $content);

        if (str_ends_with($path, '/')) {
            self::assertStringContainsString(
                '"isAdmin":' . ($admin ? 'true' : 'false'),
                $content,
            );
            self::assertStringContainsString('role="tablist"', $content);
            self::assertStringContainsString('aria-controls="history-list"', $content);
            self::assertStringContainsString('aria-controls="history-chart"', $content);
            self::assertStringContainsString('class="history-chart history-panel"', $content);
        }
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function routes(): iterable
    {
        yield 'public food page' => ['/', 'Рацион Халюжа', false];
        yield 'public food API' => ['/api.php', '"records":[]', false];
        yield 'public weight page' => ['/weight/', 'Вес Халюжа', false];
        yield 'public weight API' => ['/weight/api.php', '"records":[]', false];
        yield 'admin food page' => ['/admin/', 'Рацион Халюжа', true];
        yield 'admin food API' => ['/admin/api.php', '"records":[]', true];
        yield 'admin weight page' => ['/admin/weight/', 'Вес Халюжа', true];
        yield 'admin weight API' => ['/admin/weight/api.php', '"records":[]', true];
    }

    #[DataProvider('redirects')]
    public function testMissingTrailingSlashIsRedirected(string $path, string $location): void
    {
        $response = $this->application()->handle(Request::create($path));

        self::assertSame(Response::HTTP_MOVED_PERMANENTLY, $response->getStatusCode());
        self::assertSame($location, $response->headers->get('Location'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function redirects(): iterable
    {
        yield 'admin' => ['/admin', '/admin/'];
        yield 'weight' => ['/weight', '/weight/'];
        yield 'admin weight' => ['/admin/weight', '/admin/weight/'];
    }

    #[DataProvider('unknownPublicPaths')]
    public function testUnknownPublicPathsReturnNotFound(string $path): void
    {
        $response = $this->application()->handle(Request::create($path));

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        self::assertSame('text/plain; charset=utf-8', $response->headers->get('Content-Type'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unknownPublicPaths(): iterable
    {
        yield 'unknown URL' => ['/unknown'];
        yield 'front controller URL' => ['/index.php'];
        yield 'dotfile' => ['/.git/config'];
        yield 'arbitrary PHP file' => ['/arbitrary.php'];
    }

    public function testAdminRoutesRequireAuthenticatedIdentity(): void
    {
        $denied = $this->application()->handle(Request::create('/admin/'));
        self::assertSame(Response::HTTP_FORBIDDEN, $denied->getStatusCode());

        $remoteUser = $this->application()->handle(Request::create(
            '/admin/',
            'GET',
            [],
            [],
            [],
            ['REMOTE_USER' => 'tester'],
        ));
        self::assertSame(Response::HTTP_OK, $remoteUser->getStatusCode());

        $phpAuthUser = $this->application()->handle(Request::create(
            '/admin/',
            'GET',
            [],
            [],
            [],
            ['PHP_AUTH_USER' => 'tester'],
        ));
        self::assertSame(Response::HTTP_OK, $phpAuthUser->getStatusCode());
    }

    public function testUnknownAdminPathRequiresIdentityBeforeReturningNotFound(): void
    {
        $denied = $this->application()->handle(Request::create('/admin/unknown'));
        self::assertSame(Response::HTTP_FORBIDDEN, $denied->getStatusCode());

        $authenticated = $this->application()->handle(Request::create(
            '/admin/unknown',
            'GET',
            [],
            [],
            [],
            ['REMOTE_USER' => 'tester'],
        ));
        self::assertSame(Response::HTTP_NOT_FOUND, $authenticated->getStatusCode());
    }

    public function testFrontControllerUrlIsNotConfusedWithRootByScriptName(): void
    {
        $response = $this->application()->handle(Request::create(
            '/index.php',
            'GET',
            [],
            [],
            [],
            [
                'SCRIPT_NAME' => '/index.php',
                'SCRIPT_FILENAME' => '/app/public/index.php',
                'PHP_SELF' => '/index.php',
            ],
        ));

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function testHtmlPageDoesNotOpenDatabase(): void
    {
        $factory = new AppFactory([
            'databasePath' => '/definitely-missing/records.sqlite',
            'timezone' => 'Asia/Yerevan',
            'profile' => $this->profile,
        ]);

        $response = $factory->application()->handle(Request::create('/'));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testFoodPagesDoNotExposeCalorieTargetUi(): void
    {
        foreach ([['/', []], ['/admin/', ['REMOTE_USER' => 'tester']]] as [$path, $server]) {
            $response = $this->application()->handle(Request::create(
                $path,
                'GET',
                [],
                [],
                [],
                $server,
            ));
            $content = $response->getContent();

            self::assertNotFalse($content);
            self::assertStringContainsString('"dryCaloriesPerGram":4.2', $content);
            self::assertStringNotContainsString('targetMin', $content);
            self::assertStringNotContainsString('targetMax', $content);
            self::assertStringNotContainsString('id="range-note"', $content);
            self::assertStringNotContainsString('id="comparison"', $content);
            self::assertStringNotContainsString('id="history-average-badge"', $content);
        }
    }

    private function application(): Application
    {
        $api = $this->apiController();

        return new Application(
            new PageController(
                new Environment(
                    new FilesystemLoader(dirname(__DIR__) . '/app/templates'),
                    [
                        'autoescape' => 'html',
                        'cache' => false,
                        'strict_variables' => true,
                    ],
                ),
                $this->profile,
            ),
            static fn (): ApiController => $api,
        );
    }
}
