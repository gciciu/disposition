<?php

namespace App\Command;

use App\Repository\DeliveryRouteRepository;
use App\Service\RouteFinishService;
use App\Service\RouteOverviewMapService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:regenerate-completion-pdf')]
class RegenerateCompletionPdfCommand extends Command
{
    public function __construct(
        private readonly DeliveryRouteRepository $routes,
        private readonly RouteFinishService $routeFinish,
        private readonly RouteOverviewMapService $overviewMap,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $route = $this->routes->find((int) $input->getArgument('id'));
        if (!$route) {
            $output->writeln('<error>Route not found</error>');

            return Command::FAILURE;
        }

        $map = $this->overviewMap->buildDataUri($route);
        $output->writeln($map ? 'map: ok' : 'map: FAILED');

        if (!$route->isCompleted()) {
            $output->writeln('<error>Route is not completed</error>');

            return Command::FAILURE;
        }

        $this->routeFinish->ensurePdf($route, true);
        $path = $this->routeFinish->absolutePdfPath($route);
        $output->writeln('stored: '.($path ?? 'null'));
        if ($path) {
            $output->writeln('size: '.\filesize($path));
        }

        return Command::SUCCESS;
    }
}
