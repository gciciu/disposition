<?php

namespace App\Service;

use App\Entity\DeliveryOrder;
use App\Enum\DeliveryOrderStatus;
use App\Enum\DeliveryProofType;
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
     * @param list<int>|null $deliveredProductIndexes null = all products delivered
     */
    public function completeWithProof(
        DeliveryOrder $order,
        DeliveryProofType $proofType,
        UploadedFile $file,
        ?array $deliveredProductIndexes = null,
    ): DeliveryOrder {
        if ($order->getStatus()->isFinal()) {
            throw new ConflictHttpException('Order is already completed');
        }

        $productCount = \count($order->getProducts());
        if (null === $deliveredProductIndexes) {
            $deliveredProductIndexes = $productCount > 0 ? range(0, $productCount - 1) : [];
        } else {
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
        }

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
            ->setDeliveredProductIndexes($deliveredProductIndexes)
            ->setProofType($proofType)
            ->setProofPath($relativePath)
            ->setProofMime($mime === 'image/jpg' ? 'image/jpeg' : $mime);

        $order->getRoute()?->syncCompletionFromOrders();

        $this->em->flush();
        $this->realtime->publishOrderCompleted($order);

        return $order;
    }

    public function refuse(DeliveryOrder $order): DeliveryOrder
    {
        if ($order->getStatus()->isFinal()) {
            throw new ConflictHttpException('Order is already completed');
        }

        $order
            ->setStatus(DeliveryOrderStatus::Refused)
            ->setCompletedAt(new \DateTimeImmutable())
            ->setDeliveredProductIndexes([])
            ->setProofType(null)
            ->setProofPath(null)
            ->setProofMime(null);

        $order->getRoute()?->syncCompletionFromOrders();

        $this->em->flush();
        $this->realtime->publishOrderRefused($order);

        return $order;
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

    private function uploadsRoot(): string
    {
        return $this->projectDir.\DIRECTORY_SEPARATOR.'var'.\DIRECTORY_SEPARATOR.'uploads';
    }
}
