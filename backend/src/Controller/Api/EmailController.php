<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Service\EmailService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin/email')]
#[IsGranted('ROLE_ADMIN')]
class EmailController extends AbstractController
{
    public function __construct(
        private readonly EmailService $emailService,
    ) {
    }

    #[Route('/test', name: 'api_admin_email_test', methods: ['POST'])]
    public function sendTest(Request $request): JsonResponse
    {
        $payload = $request->toArray();
        $to = isset($payload['to']) ? trim((string) $payload['to']) : '';

        if ($to === '') {
            $user = $this->getUser();
            if ($user instanceof User) {
                $to = $user->getEmail();
            }
        }

        try {
            $result = $this->emailService->sendTest($to);
        } catch (HttpExceptionInterface $e) {
            return $this->json(['error' => $e->getMessage()], $e->getStatusCode());
        }

        return $this->json([
            'ok' => true,
            'to' => $result['to'],
            'subject' => $result['subject'],
            'discarded' => $result['discarded'],
            'message' => $result['discarded']
                ? 'Email accepted by null transport (MAILER_DSN=null://null). Configure a real MAILER_DSN to deliver messages.'
                : 'Test email sent',
        ]);
    }
}
