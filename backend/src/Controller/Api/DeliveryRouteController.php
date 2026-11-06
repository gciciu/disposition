<?php

namespace App\Controller\Api;

use App\Entity\Courier;
use App\Entity\DeliveryOrder;
use App\Entity\DeliveryRoute;
use App\Enum\RouteOriginType;
use App\Exception\RouteAddressValidationException;
use App\Repository\CourierRepository;
use App\Repository\DeliveryRouteRepository;
use App\Repository\WarehouseRepository;
use App\Service\DeliveryConfirmationPdfService;
use App\Service\DeliveryProofService;
use App\Service\DeliveryRouteMetricsService;
use App\Service\GoogleMapsService;
use App\Service\RouteCodeNameBuilder;
use App\Service\RouteCompletionPdfService;
use App\Service\RouteFinishService;
use App\Service\RoutePdfImportService;
use App\Service\RouteWeekListBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin/routes')]
#[IsGranted('ROLE_ADMIN')]
class DeliveryRouteController extends AbstractController
{
    public function __construct(
        private readonly DeliveryRouteRepository $routes,
        private readonly CourierRepository $couriers,
        private readonly WarehouseRepository $warehouses,
        private readonly RoutePdfImportService $importer,
        private readonly RouteCodeNameBuilder $routeCodeNameBuilder,
        private readonly RouteCompletionPdfService $completionPdf,
        private readonly DeliveryConfirmationPdfService $confirmationPdf,
        private readonly RouteFinishService $routeFinish,
        private readonly GoogleMapsService $googleMaps,
        private readonly DeliveryRouteMetricsService $metrics,
        private readonly DeliveryProofService $proofService,
        private readonly RouteWeekListBuilder $weekListBuilder,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'api_admin_routes_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return $this->json($this->weekListBuilder->build());
    }

    #[Route('/import', name: 'api_admin_routes_import', methods: ['POST'])]
    public function import(Request $request): JsonResponse
    {
        if (!$this->googleMaps->isConfigured()) {
            return $this->json([
                'error' => 'GOOGLE_MAPS_API_KEY is not configured',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $file = $request->files->get('file');
        if (!$file) {
            return $this->json(['error' => 'PDF file is required (field name: file)'], Response::HTTP_BAD_REQUEST);
        }

        $mime = (string) $file->getMimeType();
        $original = strtolower((string) $file->getClientOriginalName());
        $isPdf = str_ends_with($original, '.pdf')
            || \in_array($mime, ['application/pdf', 'application/x-pdf'], true);

        if (!$isPdf) {
            return $this->json(['error' => 'Only PDF files are supported'], Response::HTTP_BAD_REQUEST);
        }

        $courierResult = $this->resolveCourier($request->request->get('courierId'));
        if ($courierResult instanceof JsonResponse) {
            return $courierResult;
        }
        if (!$courierResult instanceof Courier) {
            return $this->json(['error' => 'courierId is required'], Response::HTTP_BAD_REQUEST);
        }

        $scheduledDate = $this->parseScheduledDate($request->request->get('scheduledDate'));
        if ($scheduledDate instanceof JsonResponse) {
            return $scheduledDate;
        }

        $pastOrWeekend = $this->assertImportableDate($scheduledDate);
        if (null !== $pastOrWeekend) {
            return $pastOrWeekend;
        }

        $scheduledStartTime = $this->parseScheduledStartTime($request->request->get('scheduledStartTime'));
        if ($scheduledStartTime instanceof JsonResponse) {
            return $scheduledStartTime;
        }

        $originPayload = [
            'originType' => $request->request->get('originType'),
            'warehouseId' => $request->request->get('warehouseId'),
        ];
        if (!\is_string($originPayload['originType']) || '' === $originPayload['originType']) {
            return $this->json(['error' => 'originType is required'], Response::HTTP_BAD_REQUEST);
        }
        if (!\in_array($originPayload['originType'], [
            RouteOriginType::Warehouse->value,
            RouteOriginType::CourierLocation->value,
        ], true)) {
            return $this->json(['error' => 'originType must be warehouse or courier_location'], Response::HTTP_BAD_REQUEST);
        }

        $replaceExisting = \in_array(
            (string) $request->request->get('replaceExisting', '0'),
            ['1', 'true', 'yes'],
            true,
        );

        $existing = $this->routes->findOneByCourierAndScheduledDate($courierResult, $scheduledDate);
        if ($existing instanceof DeliveryRoute) {
            if ($existing->isStarted()) {
                return $this->json([
                    'error' => 'A started route already exists for this courier on the selected date',
                    'code' => 'ROUTE_STARTED',
                    'route' => $existing->toListArray(),
                ], Response::HTTP_CONFLICT);
            }
            if (!$replaceExisting) {
                return $this->json([
                    'error' => 'A route already exists for this courier on the selected date',
                    'code' => 'ROUTE_EXISTS',
                    'route' => $existing->toListArray(),
                ], Response::HTTP_CONFLICT);
            }
        }

        try {
            $route = $this->importer->import($file);
        } catch (RouteAddressValidationException $e) {
            return $this->json([
                'error' => $e->getMessage(),
                'invalidAddresses' => $e->getInvalidAddresses(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), 'GOOGLE_MAPS_API_KEY')) {
                return $this->json(['error' => $e->getMessage()], Response::HTTP_SERVICE_UNAVAILABLE);
            }

            return $this->json(['error' => 'Import failed: '.$e->getMessage()], Response::HTTP_BAD_GATEWAY);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'Import failed: '.$e->getMessage()], Response::HTTP_BAD_GATEWAY);
        }

        $originResult = $this->applyOrigin($route, $originPayload);
        if ($originResult instanceof JsonResponse) {
            return $originResult;
        }

        $route->setCourier($courierResult);
        $route->setScheduledDate($scheduledDate);
        $route->setScheduledStartTime($scheduledStartTime);
        $route->setName($this->routeCodeNameBuilder->build(
            (string) $file->getClientOriginalName(),
            $scheduledDate,
            $route->getName(),
        ));

        if ($this->googleMaps->isConfigured()) {
            $this->metrics->rebuild($route);
        }

        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        try {
            // Delete the old unstarted route first so the unique (courier, date) slot is free.
            if ($existing instanceof DeliveryRoute && $replaceExisting) {
                $this->em->remove($existing);
                $this->em->flush();
            }

            $this->em->persist($route);
            $this->em->flush();
            $connection->commit();
        } catch (\Throwable $e) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }

            return $this->json([
                'error' => 'Import failed: '.$e->getMessage(),
            ], Response::HTTP_BAD_GATEWAY);
        }

        return $this->json([
            'route' => $route->toArray(),
        ], Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'api_admin_routes_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): JsonResponse
    {
        $route = $this->routes->find($id);
        if (!$route instanceof DeliveryRoute) {
            return $this->json(['error' => 'Route not found'], Response::HTTP_NOT_FOUND);
        }

        if ($this->googleMaps->isConfigured()) {
            $this->metrics->rebuild($route);
            $this->em->flush();
        }

        return $this->json(['route' => $route->toArray()]);
    }

    /**
     * Download the route completion PDF (same layout as the imported route report).
     * Optional query: format=html to preview the Twig markup in a browser.
     */
    #[Route(
        '/{id}/completion-pdf',
        name: 'api_admin_routes_completion_pdf',
        methods: ['GET'],
        requirements: ['id' => '\d+'],
    )]
    public function completionPdf(int $id, Request $request): Response
    {
        $route = $this->routes->find($id);
        if (!$route instanceof DeliveryRoute) {
            return $this->json(['error' => 'Route not found'], Response::HTTP_NOT_FOUND);
        }

        if ('html' === $request->query->get('format')) {
            return new Response(
                $this->completionPdf->generateHtml($route),
                Response::HTTP_OK,
                ['Content-Type' => 'text/html; charset=UTF-8'],
            );
        }

        $filename = $this->completionPdf->suggestedFilename($route);
        $storedPath = $this->routeFinish->absolutePdfPath($route);

        if (null === $storedPath && $route->isCompleted()) {
            $this->routeFinish->ensurePdf($route);
            $storedPath = $this->routeFinish->absolutePdfPath($route);
        }

        if (null !== $storedPath) {
            $response = new BinaryFileResponse($storedPath);
            $response->headers->set('Content-Type', 'application/pdf');
            $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $filename);

            return $response;
        }

        $pdf = $this->completionPdf->generatePdf($route);
        $response = new Response($pdf, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
        ]);
        $response->headers->set(
            'Content-Disposition',
            HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $filename),
        );

        return $response;
    }

    #[Route(
        '/{routeId}/orders/{orderId}/proof',
        name: 'api_admin_order_proof',
        methods: ['GET'],
        requirements: ['routeId' => '\d+', 'orderId' => '\d+'],
    )]
    public function proof(int $routeId, int $orderId): Response
    {
        $route = $this->routes->find($routeId);
        if (!$route instanceof DeliveryRoute) {
            return $this->json(['error' => 'Route not found'], Response::HTTP_NOT_FOUND);
        }

        $order = null;
        foreach ($route->getOrders() as $candidate) {
            if ($candidate->getId() === $orderId) {
                $order = $candidate;
                break;
            }
        }

        if (!$order instanceof DeliveryOrder) {
            return $this->json(['error' => 'Order not found'], Response::HTTP_NOT_FOUND);
        }

        $path = $this->proofService->absoluteProofPath($order);
        if (null === $path) {
            return $this->json(['error' => 'Proof not found'], Response::HTTP_NOT_FOUND);
        }

        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', $order->getProofMime() ?: 'application/octet-stream');
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, basename($path));

        return $response;
    }

    /**
     * Download Lieferbestätigung PDF for one stop (photo / left-on-site confirmation).
     * Optional query: format=html to preview Twig markup.
     */
    #[Route(
        '/{routeId}/orders/{orderId}/confirmation-pdf',
        name: 'api_admin_order_confirmation_pdf',
        methods: ['GET'],
        requirements: ['routeId' => '\d+', 'orderId' => '\d+'],
    )]
    public function confirmationPdf(int $routeId, int $orderId, Request $request): Response
    {
        $route = $this->routes->find($routeId);
        if (!$route instanceof DeliveryRoute) {
            return $this->json(['error' => 'Route not found'], Response::HTTP_NOT_FOUND);
        }

        $order = null;
        foreach ($route->getOrders() as $candidate) {
            if ($candidate->getId() === $orderId) {
                $order = $candidate;
                break;
            }
        }

        if (!$order instanceof DeliveryOrder) {
            return $this->json(['error' => 'Order not found'], Response::HTTP_NOT_FOUND);
        }

        if ('html' === $request->query->get('format')) {
            return new Response(
                $this->confirmationPdf->generateHtml($order),
                Response::HTTP_OK,
                ['Content-Type' => 'text/html; charset=UTF-8'],
            );
        }

        $pdf = $this->confirmationPdf->generatePdf($order);
        $filename = $this->confirmationPdf->suggestedFilename($order);

        $response = new Response($pdf, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
        ]);
        $response->headers->set(
            'Content-Disposition',
            HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $filename),
        );

        return $response;
    }

    #[Route('/{id}', name: 'api_admin_routes_update', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $route = $this->routes->find($id);
        if (!$route instanceof DeliveryRoute) {
            return $this->json(['error' => 'Route not found'], Response::HTTP_NOT_FOUND);
        }

        $payload = $request->toArray();
        $previousOriginType = $route->getOriginType();
        $previousWarehouseId = $route->getWarehouse()?->getId();
        $previousCourierId = $route->getCourier()?->getId();
        $previousScheduledDate = $route->getScheduledDate()?->format('Y-m-d');
        $previousScheduledStartTime = $route->getScheduledStartTime()?->format('H:i');
        $originChanged = false;

        $nextCourierId = \array_key_exists('courierId', $payload)
            ? $this->normalizeNullableId($payload['courierId'])
            : $previousCourierId;

        $nextScheduledDateRaw = \array_key_exists('scheduledDate', $payload)
            ? $payload['scheduledDate']
            : $previousScheduledDate;
        $nextScheduledStartTimeRaw = \array_key_exists('scheduledStartTime', $payload)
            ? $payload['scheduledStartTime']
            : $previousScheduledStartTime;

        if ($route->isStarted()) {
            $changingCourier = \array_key_exists('courierId', $payload) && $nextCourierId !== $previousCourierId;
            $changingOrigin = (\array_key_exists('originType', $payload) || \array_key_exists('warehouseId', $payload))
                && $this->wouldChangeOrigin($route, $payload);
            $changingDate = \array_key_exists('scheduledDate', $payload)
                && (string) $nextScheduledDateRaw !== (string) $previousScheduledDate;
            $changingTime = \array_key_exists('scheduledStartTime', $payload)
                && $this->normalizeTimeString($nextScheduledStartTimeRaw) !== $previousScheduledStartTime;

            if ($changingCourier || $changingOrigin || $changingDate || $changingTime) {
                return $this->json([
                    'error' => 'Courier, schedule and warehouse cannot be changed after the route has started',
                ], Response::HTTP_CONFLICT);
            }
        }

        $scheduledDate = null;
        if (\array_key_exists('scheduledDate', $payload) || \array_key_exists('courierId', $payload)) {
            if (null === $nextScheduledDateRaw || '' === $nextScheduledDateRaw) {
                return $this->json(['error' => 'scheduledDate is required'], Response::HTTP_BAD_REQUEST);
            }
            $parsedDate = $this->parseScheduledDate($nextScheduledDateRaw);
            if ($parsedDate instanceof JsonResponse) {
                return $parsedDate;
            }
            $scheduledDate = $parsedDate;

            // Allow keeping past date if unchanged; otherwise enforce weekday/not-past
            if ($scheduledDate->format('Y-m-d') !== $previousScheduledDate) {
                $pastOrWeekend = $this->assertImportableDate($scheduledDate);
                if (null !== $pastOrWeekend) {
                    return $pastOrWeekend;
                }
            }
        }

        $scheduledStartTime = null;
        $clearScheduledStartTime = false;
        if (\array_key_exists('scheduledStartTime', $payload)) {
            if (null === $payload['scheduledStartTime'] || '' === $payload['scheduledStartTime']) {
                $clearScheduledStartTime = true;
            } else {
                $parsedTime = $this->parseScheduledStartTime($payload['scheduledStartTime']);
                if ($parsedTime instanceof JsonResponse) {
                    return $parsedTime;
                }
                $scheduledStartTime = $parsedTime;
            }
        }

        if (\array_key_exists('courierId', $payload)) {
            $courierResult = $this->resolveCourier($payload['courierId']);
            if ($courierResult instanceof JsonResponse) {
                return $courierResult;
            }
            if (!$courierResult instanceof Courier) {
                return $this->json(['error' => 'courierId is required'], Response::HTTP_BAD_REQUEST);
            }
            $route->setCourier($courierResult);
        }

        if (null !== $scheduledDate) {
            $route->setScheduledDate($scheduledDate);
            if ($scheduledDate->format('Y-m-d') !== $previousScheduledDate) {
                $route->setName($this->routeCodeNameBuilder->build(
                    (string) ($route->getSourceFilename() ?? ''),
                    $scheduledDate,
                    $route->getName(),
                ));
            }
        }

        if ($clearScheduledStartTime) {
            $route->setScheduledStartTime(null);
        } elseif (null !== $scheduledStartTime) {
            $route->setScheduledStartTime($scheduledStartTime);
        }

        $effectiveCourier = $route->getCourier();
        $effectiveDate = $route->getScheduledDate();
        if ($effectiveCourier instanceof Courier && null !== $effectiveDate) {
            $conflict = $this->routes->findOneByCourierAndScheduledDate(
                $effectiveCourier,
                $effectiveDate,
                $route->getId(),
            );
            if ($conflict instanceof DeliveryRoute) {
                return $this->json([
                    'error' => 'A route already exists for this courier on the selected date',
                    'code' => 'ROUTE_EXISTS',
                    'route' => $conflict->toListArray(),
                ], Response::HTTP_CONFLICT);
            }
        }

        if (\array_key_exists('originType', $payload) || \array_key_exists('warehouseId', $payload)) {
            $originResult = $this->applyOrigin($route, $payload);
            if ($originResult instanceof JsonResponse) {
                return $originResult;
            }
            $originChanged = $previousOriginType !== $route->getOriginType()
                || $previousWarehouseId !== $route->getWarehouse()?->getId();
        }

        if ($originChanged && $this->googleMaps->isConfigured()) {
            $this->metrics->rebuild($route);
        }

        if (\array_key_exists('orders', $payload)) {
            $ordersResult = $this->applyOrderUpdates($route, $payload['orders']);
            if ($ordersResult instanceof JsonResponse) {
                return $ordersResult;
            }
        }

        $this->em->flush();

        return $this->json(['route' => $route->toArray()]);
    }

    /**
     * @param mixed $ordersPayload
     */
    private function applyOrderUpdates(DeliveryRoute $route, mixed $ordersPayload): ?JsonResponse
    {
        if (!\is_array($ordersPayload)) {
            return $this->json(['error' => 'orders must be an array'], Response::HTTP_BAD_REQUEST);
        }

        $ordersById = [];
        foreach ($route->getOrders() as $order) {
            if (null !== $order->getId()) {
                $ordersById[$order->getId()] = $order;
            }
        }

        foreach ($ordersPayload as $index => $item) {
            if (!\is_array($item)) {
                return $this->json([
                    'error' => sprintf('orders[%s] must be an object', (string) $index),
                ], Response::HTTP_BAD_REQUEST);
            }

            $orderId = $item['id'] ?? null;
            if (!\is_int($orderId) && !(\is_string($orderId) && ctype_digit($orderId))) {
                return $this->json([
                    'error' => sprintf('orders[%s].id is required', (string) $index),
                ], Response::HTTP_BAD_REQUEST);
            }
            $orderId = (int) $orderId;

            $order = $ordersById[$orderId] ?? null;
            if (!$order instanceof DeliveryOrder) {
                return $this->json([
                    'error' => sprintf('Order %d does not belong to this route', $orderId),
                ], Response::HTTP_BAD_REQUEST);
            }

            if (\array_key_exists('unloadingDurationMinutes', $item)) {
                $minutes = $item['unloadingDurationMinutes'];
                if (!\is_int($minutes) && !(\is_string($minutes) && is_numeric($minutes))) {
                    return $this->json([
                        'error' => sprintf(
                            'orders[%s].unloadingDurationMinutes must be an integer between %d and %d',
                            (string) $index,
                            DeliveryOrder::UNLOADING_DURATION_MIN,
                            DeliveryOrder::UNLOADING_DURATION_MAX,
                        ),
                    ], Response::HTTP_BAD_REQUEST);
                }
                $minutes = (int) $minutes;
                if (
                    $minutes < DeliveryOrder::UNLOADING_DURATION_MIN
                    || $minutes > DeliveryOrder::UNLOADING_DURATION_MAX
                ) {
                    return $this->json([
                        'error' => sprintf(
                            'unloadingDurationMinutes must be between %d and %d',
                            DeliveryOrder::UNLOADING_DURATION_MIN,
                            DeliveryOrder::UNLOADING_DURATION_MAX,
                        ),
                    ], Response::HTTP_BAD_REQUEST);
                }

                $order->setUnloadingDurationMinutes($minutes);
            }

            if (\array_key_exists('comments', $item)) {
                $commentsResult = $this->normalizeOrderComments($item['comments'], (string) $index);
                if ($commentsResult instanceof JsonResponse) {
                    return $commentsResult;
                }
                $order->setComments($commentsResult);
            }
        }

        return null;
    }

    /**
     * @return list<array{id: string, text: string, color: string}>|JsonResponse
     */
    private function normalizeOrderComments(mixed $comments, string $index): array|JsonResponse
    {
        if (!\is_array($comments)) {
            return $this->json([
                'error' => sprintf('orders[%s].comments must be an array', $index),
            ], Response::HTTP_BAD_REQUEST);
        }

        $normalized = [];
        foreach ($comments as $commentIndex => $comment) {
            if (!\is_array($comment)) {
                return $this->json([
                    'error' => sprintf(
                        'orders[%s].comments[%s] must be an object',
                        $index,
                        (string) $commentIndex,
                    ),
                ], Response::HTTP_BAD_REQUEST);
            }

            $id = $comment['id'] ?? null;
            if (!\is_string($id) || '' === trim($id)) {
                return $this->json([
                    'error' => sprintf(
                        'orders[%s].comments[%s].id is required',
                        $index,
                        (string) $commentIndex,
                    ),
                ], Response::HTTP_BAD_REQUEST);
            }

            $text = $comment['text'] ?? null;
            if (!\is_string($text) || '' === trim($text)) {
                return $this->json([
                    'error' => sprintf(
                        'orders[%s].comments[%s].text is required',
                        $index,
                        (string) $commentIndex,
                    ),
                ], Response::HTTP_BAD_REQUEST);
            }

            $color = $comment['color'] ?? DeliveryOrder::COMMENT_COLOR_DEFAULT;
            if (!\is_string($color) || !\in_array($color, DeliveryOrder::COMMENT_COLORS, true)) {
                return $this->json([
                    'error' => sprintf(
                        'orders[%s].comments[%s].color must be one of: %s',
                        $index,
                        (string) $commentIndex,
                        implode(', ', DeliveryOrder::COMMENT_COLORS),
                    ),
                ], Response::HTTP_BAD_REQUEST);
            }

            $normalized[] = [
                'id' => trim($id),
                'text' => trim($text),
                'color' => $color,
            ];
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function wouldChangeOrigin(DeliveryRoute $route, array $payload): bool
    {
        $originTypeRaw = $payload['originType'] ?? null;
        $warehouseId = $payload['warehouseId'] ?? null;

        if (\is_string($originTypeRaw) && '' !== $originTypeRaw) {
            $originType = RouteOriginType::tryFrom($originTypeRaw);
            if (null === $originType) {
                return true;
            }
        } elseif (null !== $warehouseId) {
            $originType = RouteOriginType::Warehouse;
        } else {
            $originType = RouteOriginType::None;
        }

        if ($originType !== $route->getOriginType()) {
            return true;
        }

        if (RouteOriginType::Warehouse === $originType) {
            return $this->normalizeNullableId($warehouseId) !== $route->getWarehouse()?->getId();
        }

        return false;
    }

    private function normalizeNullableId(mixed $value): ?int
    {
        if (null === $value || '' === $value) {
            return null;
        }

        if (\is_int($value) || (\is_string($value) && ctype_digit($value))) {
            return (int) $value;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function applyOrigin(DeliveryRoute $route, array $payload): ?JsonResponse
    {
        $originTypeRaw = $payload['originType'] ?? null;
        $warehouseId = $payload['warehouseId'] ?? null;

        if (\is_string($originTypeRaw) && '' !== $originTypeRaw) {
            $originType = RouteOriginType::tryFrom($originTypeRaw);
            if (null === $originType) {
                return $this->json(['error' => 'originType is invalid'], Response::HTTP_BAD_REQUEST);
            }
        } elseif (null !== $warehouseId) {
            $originType = RouteOriginType::Warehouse;
        } else {
            $originType = RouteOriginType::None;
        }

        if (RouteOriginType::CourierLocation === $originType) {
            $route->setOriginType(RouteOriginType::CourierLocation);

            return null;
        }

        if (RouteOriginType::None === $originType) {
            $route->setOriginType(RouteOriginType::None);

            return null;
        }

        $warehouseResult = $this->resolveWarehouse($warehouseId);
        if ($warehouseResult instanceof JsonResponse) {
            return $warehouseResult;
        }
        if (null === $warehouseResult) {
            return $this->json(['error' => 'warehouseId is required for warehouse origin'], Response::HTTP_BAD_REQUEST);
        }

        $route->setWarehouse($warehouseResult);

        return null;
    }

    /**
     * @return Courier|JsonResponse|null
     */
    private function resolveCourier(mixed $courierId): mixed
    {
        if (null === $courierId || '' === $courierId) {
            return null;
        }

        if (!\is_int($courierId) && !(\is_string($courierId) && ctype_digit($courierId))) {
            return $this->json(['error' => 'courierId must be an integer or null'], Response::HTTP_BAD_REQUEST);
        }

        $courier = $this->couriers->find((int) $courierId);
        if (null === $courier) {
            return $this->json(['error' => 'Courier not found'], Response::HTTP_NOT_FOUND);
        }

        return $courier;
    }

    /**
     * @return \DateTimeImmutable|JsonResponse
     */
    private function parseScheduledDate(mixed $value): \DateTimeImmutable|JsonResponse
    {
        if (!\is_string($value) || '' === trim($value)) {
            return $this->json(['error' => 'scheduledDate is required (Y-m-d)'], Response::HTTP_BAD_REQUEST);
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));
        $errors = \DateTimeImmutable::getLastErrors();
        if (
            false === $date
            || (($errors['warning_count'] ?? 0) > 0)
            || (($errors['error_count'] ?? 0) > 0)
        ) {
            return $this->json(['error' => 'scheduledDate must be Y-m-d'], Response::HTTP_BAD_REQUEST);
        }

        return $date;
    }

    /**
     * @return \DateTimeImmutable|JsonResponse|null
     */
    private function parseScheduledStartTime(mixed $value): \DateTimeImmutable|JsonResponse|null
    {
        if (null === $value || '' === $value) {
            return null;
        }

        if (!\is_string($value)) {
            return $this->json(['error' => 'scheduledStartTime must be H:i'], Response::HTTP_BAD_REQUEST);
        }

        $time = \DateTimeImmutable::createFromFormat('!H:i', trim($value));
        $errors = \DateTimeImmutable::getLastErrors();
        if (
            false === $time
            || (($errors['warning_count'] ?? 0) > 0)
            || (($errors['error_count'] ?? 0) > 0)
        ) {
            return $this->json(['error' => 'scheduledStartTime must be H:i'], Response::HTTP_BAD_REQUEST);
        }

        return $time;
    }

    private function assertImportableDate(\DateTimeImmutable $date): ?JsonResponse
    {
        $dayOfWeek = (int) $date->format('N');
        if ($dayOfWeek > 5) {
            return $this->json(['error' => 'scheduledDate must be a weekday (Mon–Fri)'], Response::HTTP_BAD_REQUEST);
        }

        $today = new \DateTimeImmutable('today');
        if ($date < $today) {
            return $this->json(['error' => 'scheduledDate cannot be in the past'], Response::HTTP_BAD_REQUEST);
        }

        return null;
    }

    private function normalizeTimeString(mixed $value): ?string
    {
        if (null === $value || '' === $value) {
            return null;
        }

        if (!\is_string($value)) {
            return null;
        }

        $time = \DateTimeImmutable::createFromFormat('!H:i', trim($value));

        return false === $time ? null : $time->format('H:i');
    }

    /**
     * @return \App\Entity\Warehouse|JsonResponse|null
     */
    private function resolveWarehouse(mixed $warehouseId): mixed
    {
        if (null === $warehouseId) {
            return null;
        }

        if (!\is_int($warehouseId) && !(\is_string($warehouseId) && ctype_digit($warehouseId))) {
            return $this->json(['error' => 'warehouseId must be an integer or null'], Response::HTTP_BAD_REQUEST);
        }

        $warehouse = $this->warehouses->find((int) $warehouseId);
        if (null === $warehouse) {
            return $this->json(['error' => 'Warehouse not found'], Response::HTTP_NOT_FOUND);
        }

        return $warehouse;
    }

    #[Route('/{id}', name: 'api_admin_routes_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(int $id): JsonResponse
    {
        $route = $this->routes->find($id);
        if (!$route instanceof DeliveryRoute) {
            return $this->json(['error' => 'Route not found'], Response::HTTP_NOT_FOUND);
        }

        $this->em->remove($route);
        $this->em->flush();

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }
}
