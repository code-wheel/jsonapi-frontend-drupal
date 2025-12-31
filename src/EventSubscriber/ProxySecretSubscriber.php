<?php

declare(strict_types=1);

namespace Drupal\jsonapi_frontend\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Validates proxy secret for Next.js First deployment mode.
 *
 * When enabled, this subscriber rejects requests that don't include
 * the correct X-Proxy-Secret header. This protects the Drupal origin
 * from direct access when running behind a Next.js proxy.
 *
 * Excluded paths:
 * - /jsonapi/* (API access for resolver and data fetching)
 * - /admin/* (direct admin access)
 * - /user/* (login/logout)
 * - /batch (batch processing)
 */
final class ProxySecretSubscriber implements EventSubscriberInterface {

  /**
   * Paths that bypass proxy secret validation.
   */
  private const EXCLUDED_PATHS = [
    '/jsonapi',
    '/admin',
    '/user',
    '/batch',
    '/system',
  ];

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  public static function getSubscribedEvents(): array {
    // Run early, but after routing is determined.
    return [
      KernelEvents::REQUEST => ['onRequest', 100],
    ];
  }

  public function onRequest(RequestEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }

    $config = $this->configFactory->get('jsonapi_frontend.settings');

    // Only enforce in nextjs_first mode.
    if ($config->get('deployment_mode') !== 'nextjs_first') {
      return;
    }

    $proxy_secret = $config->get('proxy_secret');

    // If no secret configured, skip validation (not recommended for production).
    if (empty($proxy_secret)) {
      return;
    }

    $request = $event->getRequest();
    $path = $request->getPathInfo();

    // Normalize path to lowercase for case-insensitive comparison.
    // Prevents bypass via /ADMIN, /JsonApi, etc.
    $path_lower = strtolower($path);

    // Skip validation for excluded paths.
    foreach (self::EXCLUDED_PATHS as $excluded) {
      if (str_starts_with($path_lower, $excluded)) {
        return;
      }
    }

    // Validate proxy secret header.
    $provided_secret = $request->headers->get('X-Proxy-Secret', '');

    if (!hash_equals($proxy_secret, $provided_secret)) {
      // Return 403 in JSON:API error format for consistency.
      $response = new JsonResponse([
        'errors' => [
          [
            'status' => '403',
            'title' => 'Forbidden',
            'detail' => 'Access denied',
          ],
        ],
      ], 403, [
        'Content-Type' => 'application/vnd.api+json; charset=utf-8',
      ]);

      $event->setResponse($response);
    }
  }

}
