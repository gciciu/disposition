<?php

namespace App\Controller\Api;

use App\Entity\Courier;
use App\Service\CourierService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin/couriers')]
#[IsGranted('ROLE_ADMIN')]
class CourierController extends AbstractController
{
    public function __construct(
        private readonly CourierService $courierService,
    ) {
    }

    #[Route('', name: 'api_admin_couriers_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $couriers = array_map(
            static fn (Courier $courier): array => $courier->toArray(),
            $this->courierService->list(),
        );

        return $this->json(['couriers' => $couriers]);
    }

    #[Route('/{id}', name: 'api_admin_couriers_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(Courier $courier): JsonResponse
    {
        return $this->json(['courier' => $courier->toArray()]);
    }

    #[Route('', name: 'api_admin_couriers_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        try {
            $courier = $this->courierService->create($request->toArray());
        } catch (HttpExceptionInterface $e) {
            return $this->json(['error' => $e->getMessage()], $e->getStatusCode());
        }

        return $this->json(['courier' => $courier->toArray()], Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'api_admin_couriers_update', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function update(Courier $courier, Request $request): JsonResponse
    {
        try {
            $courier = $this->courierService->update($courier, $request->toArray());
        } catch (HttpExceptionInterface $e) {
            return $this->json(['error' => $e->getMessage()], $e->getStatusCode());
        }

        return $this->json(['courier' => $courier->toArray()]);
    }

    #[Route('/{id}', name: 'api_admin_couriers_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(Courier $courier): JsonResponse
    {
        try {
            $this->courierService->delete($courier);
        } catch (HttpExceptionInterface $e) {
            return $this->json(['error' => $e->getMessage()], $e->getStatusCode());
        }

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }
}
