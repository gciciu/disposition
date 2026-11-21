<?php

namespace App\Service;

use App\Entity\AppSettings;
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
            $email = $payload['pdfReportRecipientEmail'];
            if (null !== $email && !\is_string($email)) {
                throw new BadRequestHttpException('pdfReportRecipientEmail must be a string or null');
            }

            $email = null !== $email ? trim($email) : '';
            if ('' !== $email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new BadRequestHttpException('A valid recipient email is required');
            }

            $settings->setPdfReportRecipientEmail('' === $email ? null : $email);
        }

        $this->em->flush();

        return $settings;
    }

    public function getPdfReportRecipientEmail(): ?string
    {
        $email = $this->get()->getPdfReportRecipientEmail();
        if (null === $email || '' === $email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return $email;
    }
}
