<?php

namespace App\Controller\Api;

use App\Entity\Courier;
use App\Entity\DeliveryRoute;
use App\Entity\User;
use App\Repository\DeliveryRouteRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/driver/routes')]
#[IsGranted('ROLE_COURIER')]
class DriverRouteController extends AbstractController
{
    public function __construct(
        private readonly DeliveryRouteRepository $routes,
    ) {
    }

    #[Route('', name: 'api_driver_routes_list', methods: ['GET'])]
    public function list(#[CurrentUser] User $user): JsonResponse
    {
        $courier = $this->requireCourier($user);
        if ($courier instanceof JsonResponse) {
            return $courier;
        }

        $items = array_map(
            static fn (DeliveryRoute $route): array => $route->toListArray(),
            $this->routes->findAssignedToCourierNewestFirst($courier),
        );

        return $this->json(['routes' => $items]);
    }

    #[Route('/{id}', name: 'api_driver_routes_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id, #[CurrentUser] User $user): JsonResponse
    {
        $courier = $this->requireCourier($user);
        if ($courier instanceof JsonResponse) {
            return $courier;
        }

        $route = $this->routes->findOneAssignedToCourier($id, $courier);
        if (!$route instanceof DeliveryRoute) {
            return $this->json(['error' => 'Route not found'], Response::HTTP_NOT_FOUND);
        }

        return $this->json(['route' => $route->toArray()]);
    }

    private function requireCourier(User $user): Courier|JsonResponse
    {
        $courier = $user->getCourier();
        if (!$courier instanceof Courier) {
            return $this->json(['error' => 'Courier profile not found'], Response::HTTP_FORBIDDEN);
        }

        return $courier;
    }
}
