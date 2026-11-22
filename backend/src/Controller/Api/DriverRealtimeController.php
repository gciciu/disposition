<?php

namespace App\Controller\Api;

use App\Entity\Courier;
use App\Entity\DeliveryRoute;
use App\Entity\User;
use App\Repository\DeliveryRouteRepository;
use App\Service\RealtimeTicketService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/driver/realtime')]
#[IsGranted('ROLE_COURIER')]
class DriverRealtimeController extends AbstractController
{
    public function __construct(
        private readonly RealtimeTicketService $tickets,
        private readonly DeliveryRouteRepository $routes,
    ) {
    }

    #[Route('/ticket', name: 'api_driver_realtime_ticket', methods: ['GET'])]
    public function ticket(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $courier = $user->getCourier();
        if (!$courier instanceof Courier || null === $courier->getId()) {
            return $this->json(['error' => 'Courier profile not found'], Response::HTTP_FORBIDDEN);
        }

        $topics = [
            sprintf('driver.courier.%d', $courier->getId()),
        ];

        $routeIdRaw = $request->query->get('routeId');
        if (null !== $routeIdRaw && '' !== $routeIdRaw) {
            if (!is_numeric($routeIdRaw) || (int) $routeIdRaw < 1) {
                return $this->json(['error' => 'routeId must be a positive integer'], Response::HTTP_BAD_REQUEST);
            }

            $routeId = (int) $routeIdRaw;
            $route = $this->routes->findOneAssignedToCourier($routeId, $courier);
            if (!$route instanceof DeliveryRoute) {
                return $this->json(['error' => 'Route not found'], Response::HTTP_NOT_FOUND);
            }

            $topics[] = sprintf('driver.routes.%d', $routeId);
        }

        return $this->json($this->tickets->issue($topics));
    }
}
