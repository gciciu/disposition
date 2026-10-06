<?php

namespace App\EventSubscriber;

use App\Entity\User;
use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationFailureEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationSuccessEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTCreatedEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

class JwtAuthenticationSubscriber implements EventSubscriberInterface
{
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
        $event->setData($data);
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
