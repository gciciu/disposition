<?php

namespace App\Entity;

use App\Repository\AppSettingsRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AppSettingsRepository::class)]
#[ORM\Table(name: 'app_settings')]
class AppSettings
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $pdfReportRecipientEmail = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPdfReportRecipientEmail(): ?string
    {
        return $this->pdfReportRecipientEmail;
    }

    public function setPdfReportRecipientEmail(?string $pdfReportRecipientEmail): static
    {
        $value = null !== $pdfReportRecipientEmail ? trim($pdfReportRecipientEmail) : null;
        $this->pdfReportRecipientEmail = (null === $value || '' === $value) ? null : $value;

        return $this;
    }

    /**
     * @return array{pdfReportRecipientEmail: string|null}
     */
    public function toArray(): array
    {
        return [
            'pdfReportRecipientEmail' => $this->pdfReportRecipientEmail,
        ];
    }
}
