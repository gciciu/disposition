<?php

namespace App\Service;

use App\Entity\DeliveryOrder;
use App\Enum\DeliveryMethod;
use App\Enum\DeliveryOrderStatus;
use App\Enum\DeliveryProofType;
use App\Enum\ReturnDestination;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class DeliveryProofService
{
    private const MAX_BYTES = 10 * 1024 * 1024;

    /** @var list<string> */
    private const ALLOWED_MIME = [
        'image/jpeg',
        'image/png',
        'image/jpg',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DriverRealtimePublisher $realtime,
        private readonly string $projectDir,
    ) {
    }

    /**
     * Complete a stop with Doc1 §4 delivery method A–D.
     *
     * @param list<int>|null $deliveredProductIndexes null = all products delivered
     */
    public function completeWithProof(
        DeliveryOrder $order,
        DeliveryMethod $deliveryMethod,
        DeliveryProofType $proofType,
        UploadedFile $file,
        ?array $deliveredProductIndexes = null,
        ?string $courierComment = null,
        ?string $neighborName = null,
    ): DeliveryOrder {
        if ($order->getStatus()->isFinal()) {
            throw new ConflictHttpException('Order is already completed');
        }

        if (!$deliveryMethod->isDeliveredOutcome()) {
            throw new BadRequestHttpException('deliveryMethod must be personal, left_on_site, neighbor, or partial');
        }

        $this->assertProofMatchesMethod($deliveryMethod, $proofType);

        $productCount = \count($order->getProducts());
        $deliveredProductIndexes = $this->normalizeDeliveredIndexes(
            $order,
            $deliveryMethod,
            $deliveredProductIndexes,
            $productCount,
        );

        $resolvedNeighbor = $this->resolveNeighborName($deliveryMethod, $neighborName);

        if (!$file->isValid()) {
            throw new BadRequestHttpException(sprintf('Proof upload failed: %s', $file->getErrorMessage()));
        }

        $size = $file->getSize();
        if (false === $size || $size <= 0) {
            throw new BadRequestHttpException('Proof file is empty');
        }
        if ($size > self::MAX_BYTES) {
            throw new BadRequestHttpException('Proof file is too large (max 10 MB)');
        }

        try {
            $mime = $file->getMimeType() ?: $file->getClientMimeType() ?: '';
        } catch (FileException $e) {
            throw new BadRequestHttpException('Proof file is not readable', $e);
        }

        if (!\in_array($mime, self::ALLOWED_MIME, true)) {
            throw new BadRequestHttpException('Proof file must be a JPEG or PNG image');
        }

        $extension = match ($mime) {
            'image/png' => 'png',
            default => 'jpg',
        };

        $orderId = $order->getId();
        if (null === $orderId) {
            throw new BadRequestHttpException('Order is not persisted');
        }

        $relativeDir = sprintf('pod/%d', $orderId);
        $absoluteDir = $this->uploadsRoot().\DIRECTORY_SEPARATOR.$relativeDir;
        if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0775, true) && !is_dir($absoluteDir)) {
            throw new \RuntimeException('Could not create proof upload directory');
        }

        $filename = sprintf('%s.%s', bin2hex(random_bytes(16)), $extension);
        $relativePath = $relativeDir.'/'.$filename;
        $absolutePath = $absoluteDir.\DIRECTORY_SEPARATOR.$filename;

        $file->move($absoluteDir, $filename);

        if (!is_file($absolutePath)) {
            throw new \RuntimeException('Failed to store proof file');
        }

        $order
            ->setStatus(DeliveryOrderStatus::Delivered)
            ->setCompletedAt(new \DateTimeImmutable())
            ->setDeliveryMethod($deliveryMethod)
            ->setNeighborName($resolvedNeighbor)
            ->setConfirmationEmailSentAt(null)
            ->setDeliveredProductIndexes($deliveredProductIndexes)
            ->setProofType($proofType)
            ->setProofPath($relativePath)
            ->setProofMime($mime === 'image/jpg' ? 'image/jpeg' : $mime)
            ->setCourierComment($courierComment);

        $this->em->flush();
        $this->realtime->publishOrderCompleted($order);

        return $order;
    }

    public function refuse(DeliveryOrder $order, ?string $courierComment = null): DeliveryOrder
    {
        if ($order->getStatus()->isFinal()) {
            throw new ConflictHttpException('Order is already completed');
        }

        $order
            ->setStatus(DeliveryOrderStatus::Refused)
            ->setCompletedAt(new \DateTimeImmutable())
            ->setDeliveryMethod(DeliveryMethod::NotDelivered)
            ->setNeighborName(null)
            ->setConfirmationEmailSentAt(null)
            ->setDeliveredProductIndexes([])
            ->setProofType(null)
            ->setProofPath(null)
            ->setProofMime(null)
            ->setCourierComment($courierComment);

        if (null === $order->getReturnDestination()) {
            $order->setReturnDestination(ReturnDestination::WarehouseReturn);
        }

        $this->em->flush();
        $this->realtime->publishOrderRefused($order);

        return $order;
    }

    public function markConfirmationEmailSent(DeliveryOrder $order): void
    {
        $order->setConfirmationEmailSentAt(new \DateTimeImmutable());
        $this->em->flush();
    }

    public function absoluteProofPath(DeliveryOrder $order): ?string
    {
        $relative = $order->getProofPath();
        if (null === $relative || '' === $relative) {
            return null;
        }

        $absolute = $this->uploadsRoot().\DIRECTORY_SEPARATOR.str_replace('/', \DIRECTORY_SEPARATOR, $relative);
        if (!is_file($absolute)) {
            return null;
        }

        return $absolute;
    }

    private function assertProofMatchesMethod(DeliveryMethod $method, DeliveryProofType $proofType): void
    {
        if ($method->requiresSignature() && DeliveryProofType::Signature !== $proofType) {
            throw new BadRequestHttpException(sprintf(
                'deliveryMethod "%s" requires proofType signature',
                $method->value,
            ));
        }

        if ($method->requiresPhoto() && DeliveryProofType::Photo !== $proofType) {
            throw new BadRequestHttpException(sprintf(
                'deliveryMethod "%s" requires proofType photo',
                $method->value,
            ));
        }

        if ($method->allowsPhotoOrSignature()
            && !\in_array($proofType, [DeliveryProofType::Photo, DeliveryProofType::Signature], true)
        ) {
            throw new BadRequestHttpException('deliveryMethod "partial" requires proofType photo or signature');
        }
    }

    /**
     * @param list<int>|null $deliveredProductIndexes
     *
     * @return list<int>
     */
    private function normalizeDeliveredIndexes(
        DeliveryOrder $order,
        DeliveryMethod $deliveryMethod,
        ?array $deliveredProductIndexes,
        int $productCount,
    ): array {
        if (null === $deliveredProductIndexes) {
            if (DeliveryMethod::Partial === $deliveryMethod) {
                throw new BadRequestHttpException(
                    'deliveredProductIndexes is required for partial delivery and must be a proper subset',
                );
            }

            return $productCount > 0 ? range(0, $productCount - 1) : [];
        }

        $normalized = [];
        foreach ($deliveredProductIndexes as $index) {
            if (!\is_int($index) && !(\is_string($index) && ctype_digit((string) $index))) {
                throw new BadRequestHttpException('deliveredProductIndexes must be a list of integers');
            }
            $value = (int) $index;
            if ($value < 0 || $value >= $productCount) {
                throw new BadRequestHttpException(sprintf(
                    'deliveredProductIndexes contains invalid index %d',
                    $value,
                ));
            }
            $normalized[$value] = $value;
        }
        $deliveredProductIndexes = array_values($normalized);
        sort($deliveredProductIndexes);

        if (DeliveryMethod::Partial === $deliveryMethod) {
            if (0 === \count($deliveredProductIndexes)) {
                throw new BadRequestHttpException('Partial delivery requires at least one delivered product');
            }
            if ($productCount > 0 && \count($deliveredProductIndexes) >= $productCount) {
                throw new BadRequestHttpException(
                    'Partial delivery must deliver fewer than all products; use personal/left_on_site/neighbor for full delivery',
                );
            }
        }

        return $deliveredProductIndexes;
    }

    private function resolveNeighborName(DeliveryMethod $deliveryMethod, ?string $neighborName): ?string
    {
        if (DeliveryMethod::Neighbor !== $deliveryMethod) {
            return null;
        }

        $trimmed = null !== $neighborName ? trim($neighborName) : '';
        if ('' === $trimmed) {
            throw new BadRequestHttpException('neighborName is required for neighbor delivery');
        }

        return $trimmed;
    }

    private function uploadsRoot(): string
    {
        return $this->projectDir.\DIRECTORY_SEPARATOR.'var'.\DIRECTORY_SEPARATOR.'uploads';
    }
}
