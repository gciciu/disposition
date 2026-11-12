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

        $from = trim($this->mailFrom);
        if ($from === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
            throw new BadRequestHttpException('MAIL_FROM is not configured with a valid email address');
        }

        $email = (new Email())
            ->from(new Address($from, 'Delivery'))
            ->to($to)
            ->subject($subject)
            ->text($textBody);

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
        $subject = 'Lieferbestätigung — '.$customerName.' (Test)';
        $text = "Guten Tag,\n\n"
            ."anbei erhalten Sie die Lieferbestätigung für Ihre Lieferung (Testdaten).\n\n"
            ."Mit freundlichen Grüßen\n"
            ."MySpiegel-Lieferservice\n";

        $html = '<p>Guten Tag,</p>'
            .'<p>anbei erhalten Sie die <strong>Lieferbestätigung</strong> für Ihre Lieferung '
            .'(Testdaten).</p>'
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
