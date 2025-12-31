<?php

declare(strict_types=1);

namespace Drupal\jsonapi_frontend\Controller;

use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\jsonapi_frontend\Service\PathResolver;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * JSON:API path resolver endpoint.
 *
 * Lives at /jsonapi/resolve so it can share the same perimeter rules applied
 * to /jsonapi/* (OAuth, Basic Auth, IP restrictions, etc.).
 */
final class PathResolverController extends ControllerBase {

  private const CONTENT_TYPE = 'application/vnd.api+json; charset=utf-8';

  public function __construct(
    private readonly PathResolver $resolver,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('jsonapi_frontend.path_resolver'),
    );
  }

  public function resolve(Request $request): CacheableJsonResponse {
    $path = (string) $request->query->get('path', '');

    // Return 400 if path is missing (JSON:API error format).
    if (trim($path) === '') {
      return $this->errorResponse(
        status: 400,
        title: 'Bad Request',
        detail: 'Missing required query parameter: path',
      );
    }

    $langcode = $request->query->get('langcode');
    $langcode = is_string($langcode) && $langcode !== '' ? $langcode : NULL;

    $result = $this->resolver->resolve($path, $langcode);

    $response = new CacheableJsonResponse($result, 200, [
      'Content-Type' => self::CONTENT_TYPE,
    ]);

    $max_age = $this->getCacheMaxAge();

    $cacheable = new CacheableMetadata();
    $cacheable->setCacheMaxAge($max_age);
    $cacheable->addCacheTags(['config:jsonapi_frontend.settings']);
    $cacheable->addCacheContexts([
      'url.query_args:path',
      'url.query_args:langcode',
      'url.site',
    ]);

    $config = $this->config('jsonapi_frontend.settings');
    $langcode_fallback = (string) ($config->get('resolver.langcode_fallback') ?? 'site_default');
    if ($langcode_fallback === 'current') {
      $cacheable->addCacheContexts(['languages:language_content']);
    }

    $cacheable->applyTo($response);

    $this->applySecurityHeaders($response, $max_age);

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
    $this->applySecurityHeaders($response, 0);

    return $response;
  }

  private function getCacheMaxAge(): int {
    // Only allow caching for anonymous requests to avoid leaking
    // access-controlled routing data across users.
    if (!$this->currentUser()->isAnonymous()) {
      return 0;
    }

    $config = $this->config('jsonapi_frontend.settings');
    $max_age = (int) ($config->get('resolver.cache_max_age') ?? 0);

    return max(0, $max_age);
  }

  private function applySecurityHeaders(CacheableJsonResponse $response, int $max_age): void {
    $response->headers->set('X-Content-Type-Options', 'nosniff');

    if ($max_age > 0) {
      $response->headers->set('Cache-Control', 'public, max-age=' . $max_age);
    }
    else {
      $response->headers->set('Cache-Control', 'no-store');
    }
  }

}
