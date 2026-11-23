<?php

namespace App\Entity;

use App\Enum\ConfirmationEmailMode;
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

    /** @see ConfirmationEmailMode */
    #[ORM\Column(length: 16, options: ['default' => 'test'])]
    private string $confirmationEmailMode = 'test';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $confirmationEmailTestRecipient = null;

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

    public function getConfirmationEmailMode(): ConfirmationEmailMode
    {
        return ConfirmationEmailMode::tryFrom($this->confirmationEmailMode)
            ?? ConfirmationEmailMode::default();
    }

    public function setConfirmationEmailMode(ConfirmationEmailMode $mode): static
    {
        $this->confirmationEmailMode = $mode->value;

        return $this;
    }

    public function getConfirmationEmailTestRecipient(): ?string
    {
        return $this->confirmationEmailTestRecipient;
    }

    public function setConfirmationEmailTestRecipient(?string $confirmationEmailTestRecipient): static
    {
        $value = null !== $confirmationEmailTestRecipient ? trim($confirmationEmailTestRecipient) : null;
        $this->confirmationEmailTestRecipient = (null === $value || '' === $value) ? null : $value;

        return $this;
    }

    /**
     * @return array{
     *     pdfReportRecipientEmail: string|null,
     *     confirmationEmailMode: string,
     *     confirmationEmailTestRecipient: string|null
     * }
     */
    public function toArray(): array
    {
        return [
            'pdfReportRecipientEmail' => $this->pdfReportRecipientEmail,
            'confirmationEmailMode' => $this->getConfirmationEmailMode()->value,
            'confirmationEmailTestRecipient' => $this->confirmationEmailTestRecipient,
        ];
    }
}
