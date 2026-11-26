<?php

namespace App\Service;

use App\Entity\DeliveryOrder;
use App\Entity\DeliveryRoute;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Mime\Address;
use Twig\Environment;

class DeliveryAnnouncementMailer
{
    public const SUBJECT = 'Lieferankündigung – Ihre Bestellung bei myspiegel.de';

    public function __construct(
        private readonly EmailService $emailService,
        private readonly AppSettingsService $settings,
        private readonly Environment $twig,
        private readonly EntityManagerInterface $em,
        private readonly DriverRealtimePublisher $realtime,
    ) {
    }

    /**
     * Sends Lieferankündigung for one order. Throws on validation / send failures
     * when $strict is true (manual API). When $strict is false (cron), returns a
     * skip/fail result without throwing for expected skip cases.
     *
     * @return array{
     *     status: 'sent'|'skipped'|'failed',
     *     reason?: string,
     *     to?: string,
     *     discarded?: bool
     * }
     */
    public function sendForOrder(DeliveryOrder $order, bool $strict = true): array
    {
        if (null !== $order->getAnnouncementEmailSentAt()) {
            return $this->skipOrThrow($strict, 'Announcement email already sent');
        }

        $route = $order->getRoute();
        if (!$route instanceof DeliveryRoute) {
            return $this->skipOrThrow($strict, 'Order has no route');
        }

        $courier = $route->getCourier();
        if (null === $courier) {
            return $this->skipOrThrow($strict, 'Route has no assigned courier');
        }

        $driverEmail = trim((string) $courier->getUser()?->getEmail());
        if ('' === $driverEmail || !filter_var($driverEmail, FILTER_VALIDATE_EMAIL)) {
            return $this->skipOrThrow($strict, 'Courier has no valid corporate email');
        }

        $driverDisplayName = trim((string) $courier->getUser()?->getName());
        if ('' === $driverDisplayName) {
            $driverDisplayName = $driverEmail;
        }

        $clientEmail = trim((string) $order->getEmail());
        if ('' === $clientEmail || !filter_var($clientEmail, FILTER_VALIDATE_EMAIL)) {
            return $this->skipOrThrow($strict, 'Order has no customer email');
        }

        $to = $this->settings->resolveConfirmationEmailRecipient($clientEmail);
        if (null === $to) {
            return $this->skipOrThrow(
                $strict,
                'No announcement recipient configured (check confirmation email settings)',
            );
        }

        $arrivalTimes = $route->computeArrivalTimes();
        $arrivalTime = $arrivalTimes[$order->getId()] ?? null;
        $timeWindow = $this->resolveTimeWindow($arrivalTime);
        $driverName = $this->formatDriverName($driverDisplayName);

        $htmlBody = $this->twig->render('email/delivery_announcement.html.twig', [
            'driver_name' => $driverName,
            'time_window' => $timeWindow,
        ]);
        $textBody = $this->buildTextBody($driverName, $timeWindow);

        $from = new Address($driverEmail, $driverDisplayName);

        try {
            $result = $this->emailService->send(
                $to,
                self::SUBJECT,
                $textBody,
                $htmlBody,
                [],
                $from,
                $from,
            );
        } catch (BadRequestHttpException $e) {
            if ($strict) {
                throw $e;
            }

            return [
                'status' => 'failed',
                'reason' => $e->getMessage(),
            ];
        }

        $order->setAnnouncementEmailSentAt(new \DateTimeImmutable());
        $this->em->flush();
        $this->realtime->publishOrderUpdated($order);

        return [
            'status' => 'sent',
            'to' => $result['to'],
            'discarded' => $result['discarded'],
        ];
    }

    /**
     * Maps planned arrival (H:i) to the customer-facing window phrase.
     * < 12:00 → 7 und 12; 12:00–15:59 → 11 und 16; ≥ 16:00 → 17 und 20.
     */
    public function resolveTimeWindow(?string $arrivalTime): string
    {
        $minutes = $this->arrivalMinutesFromMidnight($arrivalTime);
        if (null === $minutes || $minutes < 12 * 60) {
            return '7 und 12';
        }
        if ($minutes < 16 * 60) {
            return '11 und 16';
        }

        return '17 und 20';
    }

    public function formatDriverName(string $name): string
    {
        $name = trim($name);
        if ('' === $name) {
            return 'Herr Fahrer';
        }

        if (1 === preg_match('/^(Herr|Frau)\b/iu', $name)) {
            return $name;
        }

        return 'Herr '.$name;
    }

    private function buildTextBody(string $driverName, string $timeWindow): string
    {
        return "Sehr geehrte Damen und Herren,\n\n"
            ."vielen Dank für Ihre Bestellung bei myspiegel.de\n\n"
            .'Mein Name ist '.$driverName.' und ich bin für die Auslieferung Ihrer bestellten Ware verantwortlich.'
            ."\nIch werde voraussichtlich morgen zwischen ".$timeWindow." Uhr Ihre Ware anliefern.\n\n"
            .'Sollten Sie nicht vor Ort sein, können wir die Ware gerne bei einem Nachbarn abgeben'
            ." oder an einem sicheren Ort abstellen. In diesem Fall bitte ich um eine kurze Rückinfo"
            ." Ihrerseits als Antwort auf diese Mail.\n\n"
            .'Bitte beachten Sie, dass wir Ihre Ware im Rahmen einer Route für Sie extra aus dem Werk'
            .' in Kassel versenden, um die Versandkosten so gering wie möglich für Sie zu halten und'
            ." können daher leider keine genaueren Termine festlegen.\n\n"
            ."Vielen Dank für Ihr Verständnis.\n";
    }

    private function arrivalMinutesFromMidnight(?string $arrivalTime): ?int
    {
        if (null === $arrivalTime || '' === trim($arrivalTime)) {
            return null;
        }

        if (1 !== preg_match('/^(\d{1,2}):(\d{2})$/', trim($arrivalTime), $matches)) {
            return null;
        }

        $hours = (int) $matches[1];
        $minutes = (int) $matches[2];
        if ($hours > 23 || $minutes > 59) {
            return null;
        }

        return ($hours * 60) + $minutes;
    }

    /**
     * @return array{status: 'skipped', reason: string}
     */
    private function skipOrThrow(bool $strict, string $reason): array
    {
        if ($strict) {
            if (str_contains($reason, 'already sent')) {
                throw new ConflictHttpException($reason);
            }

            throw new BadRequestHttpException($reason);
        }

        return [
            'status' => 'skipped',
            'reason' => $reason,
        ];
    }
}
