<?php

declare(strict_types=1);

namespace Drupal\jsonapi_frontend\Controller;

use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\jsonapi_frontend\Service\RoutesFeedBuilder;
use Drupal\jsonapi_frontend\Service\SecretManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Protected build-time route feed for static site generators.
 *
 * Lives at /jsonapi/routes so it can share the same perimeter rules applied
 * to /jsonapi/* (OAuth, Basic Auth, IP restrictions, etc.).
 */
final class RoutesFeedController extends ControllerBase {

  private const CONTENT_TYPE = 'application/vnd.api+json; charset=utf-8';

  public function __construct(
    private readonly RoutesFeedBuilder $routesFeedBuilder,
    private readonly SecretManager $secretManager,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('jsonapi_frontend.routes_feed_builder'),
      $container->get('jsonapi_frontend.secret_manager'),
    );
  }

  public function routes(Request $request): CacheableJsonResponse {
    $config = $this->config('jsonapi_frontend.settings');

    if (!$config->get('routes.enabled')) {
      return $this->errorResponse(
        status: 404,
        title: 'Not Found',
        detail: 'Not Found',
      );
    }

    $configured_secret = $this->secretManager->getRoutesFeedSecret();
    if ($configured_secret === '') {
      return $this->errorResponse(
        status: 500,
        title: 'Configuration Error',
        detail: 'Routes feed is enabled but no secret is configured.',
      );
    }

    $provided_secret = (string) $request->headers->get('X-Routes-Secret', '');
    if (!hash_equals($configured_secret, $provided_secret)) {
      return $this->errorResponse(
        status: 403,
        title: 'Forbidden',
        detail: 'Access denied',
      );
    }

    $page = $request->query->get('page');
    $page = is_array($page) ? $page : [];

    $limit = (int) ($page['limit'] ?? 50);
    $limit = max(1, min(200, $limit));

    $cursor = NULL;
    if (isset($page['cursor']) && is_string($page['cursor']) && $page['cursor'] !== '') {
      $cursor = $page['cursor'];
    }

    $langcode = $request->query->get('langcode');
    $langcode = is_string($langcode) && $langcode !== '' ? $langcode : NULL;

    $result = $this->routesFeedBuilder->getPage($limit, $cursor, $langcode);

    $query = $request->query->all();

    $query['page'] = is_array($query['page'] ?? NULL) ? $query['page'] : [];
    $query['page']['limit'] = $limit;
    if ($cursor !== NULL) {
      $query['page']['cursor'] = $cursor;
    }
    else {
      unset($query['page']['cursor']);
    }

    $self = $request->getPathInfo() . '?' . http_build_query($query);

    $links = [
      'self' => $self,
      'next' => NULL,
    ];

    if ($result['next_cursor']) {
      $query['page']['cursor'] = $result['next_cursor'];
      $links['next'] = $request->getPathInfo() . '?' . http_build_query($query);
    }

    $response = new CacheableJsonResponse([
      'data' => $result['items'],
      'links' => $links,
      'meta' => [
        'langcode' => $result['langcode'],
        'page' => [
          'limit' => $limit,
          'cursor' => $cursor,
        ],
      ],
    ], 200, [
      'Content-Type' => self::CONTENT_TYPE,
    ]);

    $cacheable = new CacheableMetadata();
    $cacheable->setCacheMaxAge(0);
    $cacheable->addCacheTags(['config:jsonapi_frontend.settings']);
    $cacheable->applyTo($response);

    $this->applySecurityHeaders($response);

    return $response;
  }

  /**
   * Build a JSON:API-style error response.
   */
  private function errorResponse(int $status, string $title, string $detail): CacheableJsonResponse {
    $response = new CacheableJsonResponse([
      'errors' => [
        [
          'status' => (string) $status,
          'title' => $title,
          'detail' => $detail,
        ],
      ],
    ], $status, [
      'Content-Type' => self::CONTENT_TYPE,
    ]);

    $cacheable = new CacheableMetadata();
    $cacheable->setCacheMaxAge(0);
    $cacheable->applyTo($response);
    $this->applySecurityHeaders($response);

    return $response;
  }

  private function applySecurityHeaders(CacheableJsonResponse $response): void {
    $response->headers->set('X-Content-Type-Options', 'nosniff');
    $response->headers->set('Cache-Control', 'no-store');
  }

}
