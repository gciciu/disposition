<?php

namespace App\Controller\Api;

use App\Service\GoogleMapsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin/maps')]
#[IsGranted('ROLE_ADMIN')]
class MapsController extends AbstractController
{
    public function __construct(
        private readonly GoogleMapsService $googleMaps,
    ) {
    }

    #[Route('/autocomplete', name: 'api_admin_maps_autocomplete', methods: ['GET'])]
    public function autocomplete(Request $request): JsonResponse
    {
        if (!$this->googleMaps->isConfigured()) {
            return $this->json(['error' => 'GOOGLE_MAPS_API_KEY is not configured'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $input = $request->query->getString('input');
        if ('' === trim($input)) {
            return $this->json(['predictions' => []]);
        }

        try {
            $predictions = $this->googleMaps->autocompleteAddress($input);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_GATEWAY);
        }

        return $this->json(['predictions' => $predictions]);
    }
}
