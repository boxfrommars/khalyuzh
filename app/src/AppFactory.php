<?php

declare(strict_types=1);

namespace Khalyuzh;

use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class AppFactory
{
    private ?Database $database = null;
    private ?ClockInterface $clock = null;
    private ?Environment $twig = null;
    private ?PageController $pages = null;
    private ?ApiController $api = null;
    private ?ReportController $report = null;

    /**
     * @param array{
     *     databasePath: string,
     *     timezone: string,
     *     pet: array{
     *         name: string,
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
    public function __construct(private readonly array $config)
    {
    }

    public function application(): Application
    {
        return new Application(
            $this->pages(),
            fn (): ApiController => $this->api(),
            fn (): ReportController => $this->report(),
        );
    }

    /**
     * @return list<int>
     */
    public function migrate(): array
    {
        return $this->database()->migrate();
    }

    private function database(): Database
    {
        return $this->database ??= new Database(
            $this->config['databasePath'],
            $this->clock(),
        );
    }

    private function clock(): ClockInterface
    {
        return $this->clock ??= new NativeClock($this->config['timezone']);
    }

    private function pages(): PageController
    {
        return $this->pages ??= new PageController($this->twig(), $this->config['profile']);
    }

    private function api(): ApiController
    {
        if ($this->api instanceof ApiController) {
            return $this->api;
        }

        $pdo = $this->database()->connection();
        $this->api = new ApiController(
            new FoodRecordRepository($pdo, $this->clock(), $this->config['profile']),
            new WeightRecordRepository($pdo, $this->clock()),
            $this->clock(),
            $this->config['timezone'],
        );

        return $this->api;
    }

    private function report(): ReportController
    {
        if ($this->report instanceof ReportController) {
            return $this->report;
        }

        $pdo = $this->database()->connection();
        $this->report = new ReportController(
            $this->twig(),
            new FoodRecordRepository($pdo, $this->clock(), $this->config['profile']),
            new WeightRecordRepository($pdo, $this->clock()),
            $this->clock(),
            $this->config['timezone'],
            $this->config['pet'],
        );

        return $this->report;
    }

    private function twig(): Environment
    {
        if ($this->twig instanceof Environment) {
            return $this->twig;
        }

        $this->twig = new Environment(
            new FilesystemLoader(dirname(__DIR__) . '/templates'),
            [
                'autoescape' => 'html',
                'cache' => false,
                'strict_variables' => true,
            ],
        );

        return $this->twig;
    }
}
