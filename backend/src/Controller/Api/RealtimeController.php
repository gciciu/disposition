<?php

namespace App\Controller\Api;

use App\Service\RealtimeTicketService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin/realtime')]
#[IsGranted('ROLE_ADMIN')]
class RealtimeController extends AbstractController
{
    public function __construct(
        private readonly RealtimeTicketService $tickets,
    ) {
    }

    #[Route('/ticket', name: 'api_admin_realtime_ticket', methods: ['GET'])]
    public function ticket(Request $request): JsonResponse
    {
        $channels = ['admin.routes'];

        $routeIdRaw = $request->query->get('routeId');
        if (null !== $routeIdRaw && '' !== $routeIdRaw) {
            if (!is_numeric($routeIdRaw) || (int) $routeIdRaw < 1) {
                return $this->json(['error' => 'routeId must be a positive integer'], Response::HTTP_BAD_REQUEST);
            }
            $channels[] = sprintf('admin.routes.%d', (int) $routeIdRaw);
        }

        return $this->json($this->tickets->issue($channels));
    }
}
