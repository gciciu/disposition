<?php

namespace App\EventSubscriber;

use App\Entity\User;
use App\Service\RefreshTokenService;
use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationFailureEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationSuccessEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTCreatedEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;

class JwtAuthenticationSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly RefreshTokenService $refreshTokenService,
        private readonly RequestStack $requestStack,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            Events::AUTHENTICATION_SUCCESS => 'onAuthenticationSuccess',
            Events::AUTHENTICATION_FAILURE => 'onAuthenticationFailure',
            Events::JWT_CREATED => 'onJwtCreated',
        ];
    }

    public function onAuthenticationSuccess(AuthenticationSuccessEvent $event): void
    {
        $user = $event->getUser();
        if (!$user instanceof User) {
            return;
        }

        $data = $event->getData();
        $data['user'] = $user->toArray();
        $data['refresh_token'] = $this->refreshTokenService->issue($user, $this->wantsRememberMe());
        $event->setData($data);
    }

    private function wantsRememberMe(): bool
    {
        $request = $this->requestStack->getCurrentRequest();
        if (null === $request) {
            return true;
        }

        $payload = json_decode($request->getContent(), true);
        if (!\is_array($payload) || !\array_key_exists('remember', $payload)) {
            return true;
        }

        return (bool) $payload['remember'];
    }

    public function onAuthenticationFailure(AuthenticationFailureEvent $event): void
    {
        $message = $event->getException()->getMessageKey()
            ?: 'Неверный логин или пароль';

        if (str_contains(strtolower($message), 'отключена')) {
            $message = 'Учётная запись отключена';
        } else {
            $message = 'Неверный логин или пароль';
        }

        $event->setResponse(new JsonResponse([
            'code' => 401,
            'message' => $message,
        ], JsonResponse::HTTP_UNAUTHORIZED));
    }

    public function onJwtCreated(JWTCreatedEvent $event): void
    {
        $user = $event->getUser();
        if (!$user instanceof User) {
            return;
        }

        $payload = $event->getData();
        $payload['roles'] = $user->getRoles();
        $payload['name'] = $user->getName();
        $event->setData($payload);
    }
}
