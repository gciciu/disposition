<?php

namespace App\Command;

use App\Repository\DeliveryRouteRepository;
use App\Service\DeliveryAnnouncementMailer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:send-delivery-announcements',
    description: 'Send Lieferankündigung emails for routes scheduled tomorrow (Europe/Berlin)',
)]
class SendDeliveryAnnouncementsCommand extends Command
{
    public function __construct(
        private readonly DeliveryRouteRepository $routes,
        private readonly DeliveryAnnouncementMailer $announcementMailer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'date',
            null,
            InputOption::VALUE_REQUIRED,
            'Route scheduled date (Y-m-d). Default: tomorrow in Europe/Berlin',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $tz = new \DateTimeZone('Europe/Berlin');

        $dateOption = $input->getOption('date');
        if (\is_string($dateOption) && '' !== trim($dateOption)) {
            $scheduledDate = \DateTimeImmutable::createFromFormat('Y-m-d', trim($dateOption), $tz);
            if (!$scheduledDate instanceof \DateTimeImmutable) {
                $io->error('Invalid --date; expected Y-m-d');

                return Command::FAILURE;
            }
            $scheduledDate = $scheduledDate->setTime(0, 0);
        } else {
            $scheduledDate = (new \DateTimeImmutable('now', $tz))->modify('+1 day')->setTime(0, 0);
        }

        $io->title(sprintf(
            'Lieferankündigung for routes on %s',
            $scheduledDate->format('Y-m-d'),
        ));

        $routes = $this->routes->findByScheduledDate($scheduledDate);
        if ([] === $routes) {
            $io->success('No routes found for that date.');

            return Command::SUCCESS;
        }

        $sent = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($routes as $route) {
            foreach ($route->getOrders() as $order) {
                $result = $this->announcementMailer->sendForOrder($order, false);
                $orderId = $order->getId() ?? 0;
                $routeId = $route->getId() ?? 0;

                if ('sent' === $result['status']) {
                    ++$sent;
                    $io->writeln(sprintf(
                        '  [sent] route #%d order #%d → %s',
                        $routeId,
                        $orderId,
                        $result['to'] ?? '?',
                    ));
                    continue;
                }

                if ('skipped' === $result['status']) {
                    ++$skipped;
                    $io->writeln(sprintf(
                        '  [skip] route #%d order #%d — %s',
                        $routeId,
                        $orderId,
                        $result['reason'] ?? 'skipped',
                    ));
                    continue;
                }

                ++$failed;
                $io->warning(sprintf(
                    '[fail] route #%d order #%d — %s',
                    $routeId,
                    $orderId,
                    $result['reason'] ?? 'unknown error',
                ));
            }
        }

        $io->success(sprintf('Done. sent=%d skipped=%d failed=%d', $sent, $skipped, $failed));

        return Command::SUCCESS;
    }
}
