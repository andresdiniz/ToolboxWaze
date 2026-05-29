<?php

namespace App\EventSubscriber;

use App\Service\BotDetectorService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpFoundation\Response;

class BotDetectionSubscriber implements EventSubscriberInterface
{
    public function __construct(private BotDetectorService $botDetector) {}

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 100]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) return;

        $request = $event->getRequest();
        $result = $this->botDetector->analyze($request);

        if ($result['action'] === 'block') {
            $event->setResponse(new Response('Forbidden', 403));
        }
    }
}
