<?php

declare(strict_types=1);

namespace Khalyuzh;

use Closure;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class Application
{
    /**
     * @param Closure(): ApiController $api
     */
    public function __construct(
        private PageController $pages,
        private Closure $api,
    ) {
    }

    public function handle(Request $request): Response
    {
        $path = parse_url($request->getRequestUri(), PHP_URL_PATH);
        $path = is_string($path) ? $path : '/';
        $redirect = match ($path) {
            '/admin' => '/admin/',
            '/weight' => '/weight/',
            '/admin/weight' => '/admin/weight/',
            default => null,
        };

        if ($redirect !== null) {
            return new RedirectResponse($redirect, Response::HTTP_MOVED_PERMANENTLY);
        }

        if (str_starts_with($path, '/admin/')) {
            $denied = $this->adminAccess($request);
            if ($denied !== null) {
                return $denied;
            }
        }

        return match ($path) {
            '/' => $this->pages->food(false),
            '/api.php' => ($this->api)()->food($request, false),
            '/weight/' => $this->pages->weight(false),
            '/weight/api.php' => ($this->api)()->weight($request, false),
            '/admin/' => $this->pages->food(true),
            '/admin/api.php' => ($this->api)()->food($request, true),
            '/admin/weight/' => $this->pages->weight(true),
            '/admin/weight/api.php' => ($this->api)()->weight($request, true),
            default => new Response(
                'Not found.',
                Response::HTTP_NOT_FOUND,
                ['Content-Type' => 'text/plain; charset=utf-8'],
            ),
        };
    }

    private function adminAccess(Request $request): ?Response
    {
        if (PHP_SAPI === 'cli-server') {
            return null;
        }

        if ($request->server->get('REMOTE_USER') || $request->server->get('PHP_AUTH_USER')) {
            return null;
        }

        return new Response(
            'Administrative access must be authenticated by the web server.',
            Response::HTTP_FORBIDDEN,
            ['Content-Type' => 'text/plain; charset=utf-8'],
        );
    }
}
