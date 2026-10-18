<?php

namespace App\Controller\Api;

use App\Entity\Warehouse;
use App\Service\WarehouseService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin/warehouses')]
#[IsGranted('ROLE_ADMIN')]
class WarehouseController extends AbstractController
{
    public function __construct(
        private readonly WarehouseService $warehouseService,
    ) {
    }

    #[Route('', name: 'api_admin_warehouses_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $warehouses = array_map(
            static fn (Warehouse $warehouse): array => $warehouse->toArray(),
            $this->warehouseService->list(),
        );

        return $this->json(['warehouses' => $warehouses]);
    }

    #[Route('/{id}', name: 'api_admin_warehouses_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(Warehouse $warehouse): JsonResponse
    {
        return $this->json(['warehouse' => $warehouse->toArray()]);
    }

    #[Route('', name: 'api_admin_warehouses_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        try {
            $warehouse = $this->warehouseService->create($request->toArray());
        } catch (HttpExceptionInterface $e) {
            return $this->json(['error' => $e->getMessage()], $e->getStatusCode());
        }

        return $this->json(['warehouse' => $warehouse->toArray()], Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'api_admin_warehouses_update', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function update(Warehouse $warehouse, Request $request): JsonResponse
    {
        try {
            $warehouse = $this->warehouseService->update($warehouse, $request->toArray());
        } catch (HttpExceptionInterface $e) {
            return $this->json(['error' => $e->getMessage()], $e->getStatusCode());
        }

        return $this->json(['warehouse' => $warehouse->toArray()]);
    }

    #[Route('/{id}', name: 'api_admin_warehouses_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(Warehouse $warehouse): JsonResponse
    {
        try {
            $this->warehouseService->delete($warehouse);
        } catch (HttpExceptionInterface $e) {
            return $this->json(['error' => $e->getMessage()], $e->getStatusCode());
        }

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }
}
