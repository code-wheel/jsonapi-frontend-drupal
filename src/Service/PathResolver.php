<?php

declare(strict_types=1);

namespace Drupal\jsonapi_frontend\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Path\PathValidatorInterface;
use Drupal\path_alias\AliasManagerInterface;
use Drupal\views\Views;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Resolves frontend paths to JSON:API resource URLs.
 */
final class PathResolver implements PathResolverInterface {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AliasManagerInterface $aliasManager,
    private readonly PathValidatorInterface $pathValidator,
    private readonly LanguageManagerInterface $languageManager,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly RequestStack $requestStack,
    private readonly ?object $redirectRepository = NULL,
  ) {}

  /**
   * Resolve a frontend path.
   *
   * Contract:
   * - resolved: bool
   * - kind: "entity"|"view"|"redirect"|"route"|null
   * - canonical: string|null
   * - entity: {type,id,langcode}|null
   * - redirect: {to,status}|null
   * - jsonapi_url: string|null (for entities)
   * - data_url: string|null (for views)
   * - headless: bool (whether this content type is headless-enabled)
   * - drupal_url: string|null (URL to Drupal frontend for non-headless content)
   *
   * Notes:
   * - Optional integration modules may return additional kinds (e.g. "route")
   *   to indicate Drupal-rendered routes that should be proxied/redirected.
   */
  public function resolve(string $path, ?string $langcode = NULL): array {
    [$path, $query] = $this->splitPathAndQuery($path);
    $path = $this->normalizePath($path);

    if ($path === '') {
      return $this->notFound();
    }

    // Language negotiation: site default if none provided.
    if (!$langcode) {
      $config = $this->configFactory->get('jsonapi_frontend.settings');
      $fallback = (string) ($config->get('resolver.langcode_fallback') ?? 'site_default');

      if ($fallback === 'current') {
        $langcode = $this->languageManager->getCurrentLanguage(LanguageInterface::TYPE_CONTENT)->getId();
      }
      else {
        $langcode = $this->languageManager->getDefaultLanguage()->getId();
      }
    }

    // If the Redirect module is installed, mimic Drupal's redirect behavior.
    // This allows frontends to return the correct 301/302 responses during
    // migrations (and keeps path-based links stable).
    if ($redirect = $this->resolveRedirect($path, $query, $langcode)) {
      return $redirect;
    }

    // Alias → internal system path.
    $internal = $this->aliasManager->getPathByAlias($path, $langcode);

    // Validate the internal path and extract route parameters.
    $url = $this->pathValidator->getUrlIfValid($internal);
    if (!$url) {
      return $this->notFound();
    }

    $route_name = $url->getRouteName();
    $params = $url->getRouteParameters();

    // Check if this is a View route (pattern: view.{view_id}.{display_id}).
    if ($route_name && str_starts_with($route_name, 'view.')) {
      return $this->resolveViewRoute($route_name, $path, $langcode);
    }

    // Otherwise, attempt entity resolution.
    $current_alias = $this->aliasManager->getAliasByPath($internal, $langcode);
    $canonical = ($current_alias && $current_alias !== '') ? $current_alias : $path;

    $entity = $this->extractEntityFromRoute($route_name, $params);
    if (!$entity) {
      return $this->notFound();
    }

    // Check entity access. If user cannot view, treat as not found.
    // This ensures we don't leak existence of unpublished/restricted content.
    if (!$entity->access('view')) {
      return $this->notFound();
    }

    $resource_type = $this->jsonapiResourceType($entity);
    $jsonapi_url = $this->jsonapiPath($entity);

    if (!$resource_type || !$jsonapi_url) {
      return $this->notFound();
    }

    // Check if this bundle is headless-enabled.
    $entity_type_id = $entity->getEntityTypeId();
    $bundle = method_exists($entity, 'bundle') ? $entity->bundle() : $entity_type_id;
    $headless = $this->isHeadlessEnabled($entity_type_id, $bundle);

    // Generate Drupal frontend URL for non-headless content.
    $drupal_url = $headless ? NULL : $this->getDrupalUrl($canonical);

    return [
      'resolved' => TRUE,
      'kind' => 'entity',
      'canonical' => $canonical,
      'entity' => [
        'type' => $resource_type,
        'id' => $entity->uuid(),
        'langcode' => $langcode,
      ],
      'redirect' => NULL,
      'jsonapi_url' => $jsonapi_url,
      'data_url' => NULL,
      'headless' => $headless,
      'drupal_url' => $drupal_url,
    ];
  }

  /**
   * Resolve a View page route.
   *
   * Only supported if jsonapi_views module is installed.
   */
  private function resolveViewRoute(string $route_name, string $path, string $langcode): array {
    // Check if jsonapi_views is installed.
    if (!$this->moduleHandler->moduleExists('jsonapi_views')) {
      // Views not exposed as JSON:API. Return not found.
      // Users should install jsonapi_views or use JSON:API filters directly.
      return $this->notFound();
    }

    // Parse route name: view.{view_id}.{display_id}
    $parts = explode('.', $route_name);
    if (count($parts) < 3) {
      return $this->notFound();
    }

    $view_id = $parts[1];
    $display_id = $parts[2];

    // Explicit access check to avoid leaking existence of restricted Views.
    // Path validation may or may not include access checks depending on Drupal
    // version/config, so we enforce it here for consistency.
    if (!$this->moduleHandler->moduleExists('views')) {
      return $this->notFound();
    }

    $view = Views::getView($view_id);
    if (!$view || !$view->access($display_id)) {
      return $this->notFound();
    }

    // jsonapi_views exposes views at: /jsonapi/views/{view_id}/{display_id}
    $data_url = sprintf('/jsonapi/views/%s/%s', $view_id, $display_id);

    // Check if this view is headless-enabled.
    $headless = $this->isViewHeadlessEnabled($view_id, $display_id);

    // Generate Drupal frontend URL for non-headless views.
    $drupal_url = $headless ? NULL : $this->getDrupalUrl($path);

    return [
      'resolved' => TRUE,
      'kind' => 'view',
      'canonical' => $path,
      'entity' => NULL,
      'redirect' => NULL,
      'jsonapi_url' => NULL,
      'data_url' => $data_url,
      'headless' => $headless,
      'drupal_url' => $drupal_url,
    ];
  }

  /**
   * Check if a view display is enabled for headless rendering.
   */
  private function isViewHeadlessEnabled(string $view_id, string $display_id): bool {
    $config = $this->configFactory->get('jsonapi_frontend.settings');

    // If enable_all_views is true, all views are headless.
    if ($config->get('enable_all_views')) {
      return TRUE;
    }

    // Check if this specific view:display is in the enabled list.
    $enabled_views = $config->get('headless_views') ?: [];
    $view_key = "{$view_id}:{$display_id}";

    return in_array($view_key, $enabled_views, TRUE);
  }

  /**
   * Check if a bundle is enabled for headless rendering.
   */
  private function isHeadlessEnabled(string $entity_type_id, string $bundle): bool {
    $config = $this->configFactory->get('jsonapi_frontend.settings');

    // If enable_all is true, everything is headless.
    if ($config->get('enable_all')) {
      return TRUE;
    }

    // Check if this specific bundle is in the enabled list.
    $enabled_bundles = $config->get('headless_bundles') ?: [];
    $bundle_key = "{$entity_type_id}:{$bundle}";

    return in_array($bundle_key, $enabled_bundles, TRUE);
  }

  /**
   * Get the Drupal frontend URL for a path.
   */
  private function getDrupalUrl(string $path): string {
    $config = $this->configFactory->get('jsonapi_frontend.settings');
    $base_url = $config->get('drupal_base_url');

    // If no base URL configured, use the current site URL.
    if (empty($base_url)) {
      $request = $this->requestStack->getCurrentRequest();
      if ($request) {
        $base_url = $request->getSchemeAndHttpHost();
      }
      else {
        // Fallback if no request available.
        $base_url = '';
      }
    }

    // Remove trailing slash from base URL.
    $base_url = rtrim($base_url, '/');

    return $base_url . $path;
  }

  private function normalizePath(string $path): string {
    $path = trim($path);
    if ($path === '') {
      return '';
    }
    // Limit path length to prevent DoS via extremely long paths.
    if (strlen($path) > 2048) {
      return '';
    }
    // Remove query string and fragment if passed.
    $path = preg_replace('/[?#].*$/', '', $path) ?? $path;
    if ($path === '') {
      return '';
    }
    // Ensure leading slash.
    if ($path[0] !== '/') {
      $path = '/' . $path;
    }
    // Collapse repeated slashes, strip trailing slash except root.
    $path = preg_replace('#/+#', '/', $path) ?? $path;
    if ($path !== '/' && str_ends_with($path, '/')) {
      $path = rtrim($path, '/');
    }
    return $path;
  }

  /**
   * Splits a user-provided path string into path and query array.
   *
   * The resolver accepts a single `path` string, but Redirect can match based
   * on query strings. To support this, we parse "path?key=val" input.
   *
   * @return array{0: string, 1: array}
   *   [path, query]
   */
  private function splitPathAndQuery(string $path): array {
    $path = trim($path);
    if ($path === '') {
      return ['', []];
    }

    // Remove fragment if present (it shouldn't affect routing/redirects).
    if (str_contains($path, '#')) {
      $path = strstr($path, '#', TRUE) ?: '';
    }

    $query = [];
    $qpos = strpos($path, '?');
    if ($qpos === FALSE) {
      return [$path, $query];
    }

    $raw_path = substr($path, 0, $qpos);
    $query_string = substr($path, $qpos + 1);

    if ($query_string !== '') {
      parse_str($query_string, $query);
      if (!is_array($query)) {
        $query = [];
      }
    }

    return [$raw_path, $query];
  }

  private function resolveRedirect(string $path, array $query, string $langcode): ?array {
    if (!$this->redirectRepository || !$this->moduleHandler->moduleExists('redirect')) {
      return NULL;
    }

    if (!method_exists($this->redirectRepository, 'findMatchingRedirect')) {
      return NULL;
    }

    try {
      $redirect = $this->redirectRepository->findMatchingRedirect($path, $query, $langcode);
    }
    catch (\Throwable) {
      return NULL;
    }

    if (!is_object($redirect)) {
      return NULL;
    }

    $status = 301;
    if (method_exists($redirect, 'getStatusCode')) {
      $raw_status = $redirect->getStatusCode();
      if (is_int($raw_status) || (is_string($raw_status) && ctype_digit($raw_status))) {
        $status = (int) $raw_status;
      }
    }

    if ($status < 300 || $status > 399) {
      $status = 301;
    }

    $to = NULL;
    if (method_exists($redirect, 'getRedirectUrl')) {
      $url = $redirect->getRedirectUrl();
      if (is_object($url) && method_exists($url, 'toString')) {
        $to = $url->toString();
      }
    }

    if (!is_string($to) || trim($to) === '') {
      return NULL;
    }

    $to = trim($to);

    // Ensure internal paths always have a leading slash.
    if (!preg_match('#^[a-z][a-z0-9+\\-.]*://#i', $to) && !str_starts_with($to, '/')) {
      $to = '/' . $to;
    }

    return [
      'resolved' => TRUE,
      'kind' => 'redirect',
      'canonical' => $path,
      'entity' => NULL,
      'redirect' => [
        'to' => $to,
        'status' => $status,
      ],
      'jsonapi_url' => NULL,
      'data_url' => NULL,
      'headless' => FALSE,
      'drupal_url' => NULL,
    ];
  }

  private function extractEntityFromRoute(?string $route_name, array $params): ?ContentEntityInterface {
    // Prefer already upcasted entities (if present).
    foreach ($params as $value) {
      if ($value instanceof ContentEntityInterface) {
        return $value;
      }
    }

    // Canonical entity route pattern: entity.{entity_type}.canonical
    $canonical_entity_type = $route_name ? $this->canonicalEntityTypeFromRouteName($route_name) : NULL;
    if ($canonical_entity_type && array_key_exists($canonical_entity_type, $params)) {
      $entity = $this->loadContentEntityFromParam($canonical_entity_type, $params[$canonical_entity_type]);
      if ($entity) {
        return $entity;
      }
    }

    // Fallback: look for any route parameter whose name matches an entity type.
    foreach ($params as $param_name => $value) {
      if (!is_string($param_name)) {
        continue;
      }
      $entity = $this->loadContentEntityFromParam($param_name, $value);
      if ($entity) {
        return $entity;
      }
    }

    return NULL;
  }

  private function canonicalEntityTypeFromRouteName(string $route_name): ?string {
    if (!str_starts_with($route_name, 'entity.') || !str_ends_with($route_name, '.canonical')) {
      return NULL;
    }

    $parts = explode('.', $route_name);
    if (count($parts) !== 3) {
      return NULL;
    }

    $entity_type_id = $parts[1];

    return $entity_type_id !== '' ? $entity_type_id : NULL;
  }

  private function loadContentEntityFromParam(string $entity_type_id, mixed $value): ?ContentEntityInterface {
    $definition = $this->entityTypeManager->getDefinition($entity_type_id, FALSE);
    if (!$definition || !$definition->entityClassImplements(ContentEntityInterface::class)) {
      return NULL;
    }

    if ($value instanceof ContentEntityInterface) {
      return $value;
    }

    if (!is_int($value) && !is_string($value)) {
      return NULL;
    }

    if ($value === '') {
      return NULL;
    }

    $entity = $this->entityTypeManager->getStorage($entity_type_id)->load($value);
    return $entity instanceof ContentEntityInterface ? $entity : NULL;
  }

  /**
   * JSON:API resource type (e.g., node--page).
   */
  private function jsonapiResourceType(EntityInterface $entity): ?string {
    $entity_type = $entity->getEntityTypeId();
    $bundle = method_exists($entity, 'bundle') ? $entity->bundle() : NULL;
    if (!$bundle) {
      return NULL;
    }
    return sprintf('%s--%s', $entity_type, $bundle);
  }

  /**
   * JSON:API URL path (e.g., /jsonapi/node/page/{uuid}).
   */
  private function jsonapiPath(EntityInterface $entity): ?string {
    $entity_type = $entity->getEntityTypeId();
    $bundle = method_exists($entity, 'bundle') ? $entity->bundle() : NULL;
    if (!$bundle) {
      return NULL;
    }
    return sprintf('/jsonapi/%s/%s/%s', $entity_type, $bundle, $entity->uuid());
  }

  private function notFound(): array {
    return [
      'resolved' => FALSE,
      'kind' => NULL,
      'canonical' => NULL,
      'entity' => NULL,
      'redirect' => NULL,
      'jsonapi_url' => NULL,
      'data_url' => NULL,
      'headless' => FALSE,
      'drupal_url' => NULL,
    ];
  }

}
