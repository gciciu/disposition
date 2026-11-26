<?php

namespace App\Service;

use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

class EmailService
{
    public function __construct(
        private readonly TransportInterface $mailerTransport,
        private readonly string $mailFrom,
        private readonly string $mailerDsn,
    ) {
    }

    /**
     * Sends email synchronously (bypasses Messenger) so callers get immediate errors.
     *
     * @param list<array{content: string, filename: string, contentType?: string}> $attachments
     *
     * @return array{to: string, subject: string, discarded: bool}
     */
    public function send(
        string $to,
        string $subject,
        string $textBody,
        ?string $htmlBody = null,
        array $attachments = [],
        ?Address $from = null,
        ?Address $replyTo = null,
    ): array {
        $to = trim($to);
        $subject = trim($subject);
        $textBody = trim($textBody);

        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new BadRequestHttpException('A valid recipient email is required');
        }

        if ($subject === '') {
            throw new BadRequestHttpException('Subject is required');
        }

        if ($textBody === '') {
            throw new BadRequestHttpException('Message body is required');
        }

        $fromAddress = $from;
        if (null === $fromAddress) {
            $configuredFrom = trim($this->mailFrom);
            if ($configuredFrom === '' || !filter_var($configuredFrom, FILTER_VALIDATE_EMAIL)) {
                throw new BadRequestHttpException('MAIL_FROM is not configured with a valid email address');
            }
            $fromAddress = new Address($configuredFrom, 'Delivery');
        }

        $email = (new Email())
            ->from($fromAddress)
            ->to($to)
            ->subject($subject)
            ->text($textBody);

        if (null !== $replyTo) {
            $email->replyTo($replyTo);
        }

        if ($htmlBody !== null && trim($htmlBody) !== '') {
            $email->html($htmlBody);
        }

        foreach ($attachments as $attachment) {
            $content = $attachment['content'] ?? '';
            $filename = trim((string) ($attachment['filename'] ?? ''));
            if ($content === '' || $filename === '') {
                continue;
            }
            $email->attach(
                $content,
                $filename,
                $attachment['contentType'] ?? 'application/octet-stream',
            );
        }

        try {
            $this->mailerTransport->send($email);
        } catch (TransportExceptionInterface $e) {
            throw new BadRequestHttpException('Failed to send email: '.$e->getMessage(), $e);
        }

        return [
            'to' => $to,
            'subject' => $subject,
            'discarded' => $this->isNullTransport(),
        ];
    }

    /**
     * @return array{to: string, subject: string, discarded: bool}
     */
    public function sendTest(string $to): array
    {
        $subject = 'Delivery — test email';
        $text = "This is a test email from the Delivery admin panel.\n\n"
            ."If you received this message, outbound email is configured correctly.\n";

        $html = '<p>This is a <strong>test email</strong> from the Delivery admin panel.</p>'
            .'<p>If you received this message, outbound email is configured correctly.</p>';

        return $this->send($to, $subject, $text, $html);
    }

    /**
     * Sends a sample Lieferbestätigung (HTML body + PDF attachment) for Mailpit / SMTP tests.
     *
     * @return array{to: string, subject: string, discarded: bool, filename: string}
     */
    public function sendConfirmationSample(string $to, string $pdfBinary, string $filename, string $customerName): array
    {
        return $this->sendDeliveryConfirmation($to, $pdfBinary, $filename, $customerName, true);
    }

    /**
     * Sends Lieferbestätigung PDF to the customer (Doc1 B — Abgestellt vor Ort).
     *
     * @return array{to: string, subject: string, discarded: bool, filename: string}
     */
    public function sendDeliveryConfirmation(
        string $to,
        string $pdfBinary,
        string $filename,
        string $customerName,
        bool $isTest = false,
        ?string $intendedClientEmail = null,
    ): array {
        $redirected = !$isTest
            && null !== $intendedClientEmail
            && '' !== trim($intendedClientEmail)
            && strcasecmp(trim($to), trim($intendedClientEmail)) !== 0;

        $suffix = ($isTest || $redirected) ? ' (Test)' : '';
        $subject = 'Lieferbestätigung — '.$customerName.$suffix;
        $testNote = $isTest ? ' (Testdaten)' : '';
        $redirectNote = '';
        if ($redirected) {
            $redirectNote = "\n\n[Testmodus] Empfänger laut Auftrag: ".trim($intendedClientEmail)
                ." — E-Mail wurde an die Testadresse umgeleitet.\n";
        }

        $text = "Guten Tag,\n\n"
            ."anbei erhalten Sie die Lieferbestätigung für Ihre Lieferung".$testNote.".\n\n"
            ."Ihre Ware wurde abgestellt / vor Ort hinterlassen."
            .$redirectNote."\n\n"
            ."Mit freundlichen Grüßen\n"
            ."MySpiegel-Lieferservice\n";

        $htmlRedirect = '';
        if ($redirected) {
            $htmlRedirect = '<p><em>[Testmodus] Empfänger laut Auftrag: '
                .htmlspecialchars(trim($intendedClientEmail), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                .' — E-Mail wurde an die Testadresse umgeleitet.</em></p>';
        }

        $html = '<p>Guten Tag,</p>'
            .'<p>anbei erhalten Sie die <strong>Lieferbestätigung</strong> für Ihre Lieferung'
            .($isTest ? ' (Testdaten)' : '').'.</p>'
            .'<p>Ihre Ware wurde abgestellt / vor Ort hinterlassen.</p>'
            .$htmlRedirect
            .'<p>Mit freundlichen Grüßen<br>MySpiegel-Lieferservice</p>';

        $result = $this->send($to, $subject, $text, $html, [
            [
                'content' => $pdfBinary,
                'filename' => $filename,
                'contentType' => 'application/pdf',
            ],
        ]);

        return $result + ['filename' => $filename];
    }

    /**
     * Sends the route completion PDF report to the configured admin recipient.
     *
     * @return array{to: string, subject: string, discarded: bool, filename: string}
     */
    public function sendRouteCompletionReport(
        string $to,
        string $pdfBinary,
        string $filename,
        string $routeName,
    ): array {
        $routeLabel = trim($routeName) !== '' ? trim($routeName) : 'Route';
        $subject = 'Tourabschlussbericht — '.$routeLabel;
        $text = "Guten Tag,\n\n"
            ."anbei erhalten Sie den Tourabschlussbericht für die Route „".$routeLabel."“.\n\n"
            ."Mit freundlichen Grüßen\n"
            ."MySpiegel-Lieferservice\n";

        $html = '<p>Guten Tag,</p>'
            .'<p>anbei erhalten Sie den <strong>Tourabschlussbericht</strong> für die Route „'
            .htmlspecialchars($routeLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            .'“.</p>'
            .'<p>Mit freundlichen Grüßen<br>MySpiegel-Lieferservice</p>';

        $result = $this->send($to, $subject, $text, $html, [
            [
                'content' => $pdfBinary,
                'filename' => $filename,
                'contentType' => 'application/pdf',
            ],
        ]);

        return $result + ['filename' => $filename];
    }

    public function isNullTransport(): bool
    {
        return str_starts_with(strtolower(trim($this->mailerDsn)), 'null://');
    }
}
