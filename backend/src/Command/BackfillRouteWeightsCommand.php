<?php

namespace App\Command;

use App\Repository\DeliveryRouteRepository;
use App\Service\RoutePdfImportService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpFoundation\File\UploadedFile;

#[AsCommand(name: 'app:backfill-route-weights', description: 'Re-parse source PDFs and fill missing route/order weights')]
class BackfillRouteWeightsCommand extends Command
{
    public function __construct(
        private readonly DeliveryRouteRepository $routes,
        private readonly RoutePdfImportService $importer,
        private readonly EntityManagerInterface $em,
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'materials-dir',
            null,
            InputOption::VALUE_REQUIRED,
            'Directory with original route PDFs',
            dirname($this->projectDir).DIRECTORY_SEPARATOR.'materials',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $materialsDir = (string) $input->getOption('materials-dir');
        if (!is_dir($materialsDir)) {
            $io->error(sprintf('Materials directory not found: %s', $materialsDir));

            return Command::FAILURE;
        }

        $ref = new \ReflectionClass($this->importer);
        $extractText = $ref->getMethod('extractText');
        $extractText->setAccessible(true);
        $parseFirstTable = $ref->getMethod('parseFirstTable');
        $parseFirstTable->setAccessible(true);

        $updated = 0;
        $skipped = 0;

        foreach ($this->routes->findAllNewestFirst() as $route) {
            $filename = $route->getSourceFilename();
            if (null === $filename || '' === $filename) {
                ++$skipped;
                continue;
            }

            $path = $materialsDir.DIRECTORY_SEPARATOR.$filename;
            if (!is_readable($path)) {
                $io->warning(sprintf('Route #%d: PDF not found (%s)', $route->getId(), $filename));
                ++$skipped;
                continue;
            }

            $file = new UploadedFile($path, $filename, 'application/pdf', null, true);
            $text = $extractText->invoke($this->importer, $file);
            $parsed = $parseFirstTable->invoke($this->importer, $text);

            $changed = false;
            $sum = 0.0;
            $hasOrderWeight = false;
            $parsedOrders = array_values($parsed['orders']);

            // Import normalizes positions to 1..n; match by order index.
            foreach (array_values($route->getOrders()->toArray()) as $index => $order) {
                $orderData = $parsedOrders[$index] ?? null;
                $weight = null;
                if (null !== $orderData && isset($orderData['weightKg']) && is_numeric($orderData['weightKg'])) {
                    $weight = (float) $orderData['weightKg'];
                }
                if (null !== $weight) {
                    $order->setWeightKg($weight);
                    $sum += $weight;
                    $hasOrderWeight = true;
                    $changed = true;
                }
            }

            $total = $parsed['totalWeightKg'] ?? null;
            if (null === $total && $hasOrderWeight) {
                $total = round($sum, 1);
            }
            if (null !== $total) {
                $route->setTotalWeightKg((float) $total);
                $changed = true;
            }

            if ($changed) {
                ++$updated;
                $io->writeln(sprintf(
                    'Route #%d (%s): totalWeightKg=%s',
                    $route->getId(),
                    $route->getName(),
                    var_export($route->getTotalWeightKg(), true),
                ));
            } else {
                ++$skipped;
            }
        }

        $this->em->flush();
        $io->success(sprintf('Updated %d route(s), skipped %d.', $updated, $skipped));

        return Command::SUCCESS;
    }
}
