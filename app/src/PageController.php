<?php

declare(strict_types=1);

namespace Khalyuzh;

use JsonException;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

final readonly class PageController
{
    /**
     * @param array<string, float|int|string> $profile
     * @param array{name: string, nameGenitive: string} $pet
     */
    public function __construct(
        private Environment $twig,
        private array $profile,
        private array $pet,
    ) {
    }

    /**
     * @throws JsonException
     */
    public function food(bool $isAdmin): Response
    {
        return new Response($this->twig->render('food.html.twig', [
            'is_admin' => $isAdmin,
            'active_section' => 'food',
            'pet' => $this->pet,
            'page_config' => $this->pageConfig([
                'isAdmin' => $isAdmin,
                'apiUrl' => $isAdmin ? '/admin/api.php' : '/api.php',
                'profile' => [
                    'dryName' => $this->profile['dryName'],
                    'dryCaloriesPerGram' => $this->profile['dryCaloriesPerGram'],
                    'wetName' => $this->profile['wetName'],
                    'wetCaloriesPerCan' => $this->profile['wetCaloriesPerCan'],
                ],
            ]),
        ]));
    }

    /**
     * @throws JsonException
     */
    public function weight(bool $isAdmin): Response
    {
        return new Response($this->twig->render('weight.html.twig', [
            'is_admin' => $isAdmin,
            'active_section' => 'weight',
            'pet' => $this->pet,
            'page_config' => $this->pageConfig([
                'isAdmin' => $isAdmin,
                'apiUrl' => $isAdmin ? '/admin/weight/api.php' : '/weight/api.php',
            ]),
        ]));
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
