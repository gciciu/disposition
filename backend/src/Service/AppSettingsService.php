<?php

namespace App\Service;

use App\Entity\AppSettings;
use App\Enum\ConfirmationEmailMode;
use App\Repository\AppSettingsRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class AppSettingsService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AppSettingsRepository $repository,
    ) {
    }

    public function get(): AppSettings
    {
        $settings = $this->repository->getSingleton();
        if (null !== $settings) {
            return $settings;
        }

        $settings = new AppSettings();
        $this->em->persist($settings);
        $this->em->flush();

        return $settings;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function update(array $payload): AppSettings
    {
        $settings = $this->get();

        if (\array_key_exists('pdfReportRecipientEmail', $payload)) {
            $settings->setPdfReportRecipientEmail($this->normalizeOptionalEmail(
                $payload['pdfReportRecipientEmail'],
                'pdfReportRecipientEmail',
            ));
        }

        if (\array_key_exists('confirmationEmailMode', $payload)) {
            $mode = ConfirmationEmailMode::tryFromMixed($payload['confirmationEmailMode']);
            if (null === $mode) {
                throw new BadRequestHttpException('confirmationEmailMode must be "test" or "client"');
            }
            $settings->setConfirmationEmailMode($mode);
        }

        if (\array_key_exists('confirmationEmailTestRecipient', $payload)) {
            $settings->setConfirmationEmailTestRecipient($this->normalizeOptionalEmail(
                $payload['confirmationEmailTestRecipient'],
                'confirmationEmailTestRecipient',
            ));
        }

        $this->em->flush();

        return $settings;
    }

    public function getPdfReportRecipientEmail(): ?string
    {
        return $this->validatedEmail($this->get()->getPdfReportRecipientEmail());
    }

    public function getConfirmationEmailMode(): ConfirmationEmailMode
    {
        return $this->get()->getConfirmationEmailMode();
    }

    public function getConfirmationEmailTestRecipient(): ?string
    {
        return $this->validatedEmail($this->get()->getConfirmationEmailTestRecipient());
    }

    /**
     * Resolves the Lieferbestätigung recipient for a delivered order.
     * Returns null when sending should be skipped (missing configured address).
     */
    public function resolveConfirmationEmailRecipient(?string $clientEmail): ?string
    {
        $mode = $this->getConfirmationEmailMode();

        if (ConfirmationEmailMode::Client === $mode) {
            return $this->validatedEmail($clientEmail);
        }

        return $this->getConfirmationEmailTestRecipient();
    }

    private function normalizeOptionalEmail(mixed $email, string $field): ?string
    {
        if (null !== $email && !\is_string($email)) {
            throw new BadRequestHttpException($field.' must be a string or null');
        }

        $email = null !== $email ? trim($email) : '';
        if ('' === $email) {
            return null;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new BadRequestHttpException('A valid recipient email is required');
        }

        return $email;
    }

    private function validatedEmail(?string $email): ?string
    {
        $email = null !== $email ? trim($email) : '';
        if ('' === $email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return $email;
    }
}
