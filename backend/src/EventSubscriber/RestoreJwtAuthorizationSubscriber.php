<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Some SAPIs strip Authorization; the admin/driver SPAs also send
 * X-Delivery-Authorization. Copy it onto Authorization so Lexik finds the JWT.
 */
class RestoreJwtAuthorizationSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 255],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if ($request->headers->get('Authorization')) {
            return;
        }

        $fallback = $request->headers->get('X-Delivery-Authorization');
        if (!\is_string($fallback) || $fallback === '') {
            return;
        }

        $request->headers->set('Authorization', $fallback);
    }
}
