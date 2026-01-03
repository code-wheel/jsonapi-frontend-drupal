<?php

declare(strict_types=1);

namespace Drupal\jsonapi_frontend\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\path_alias\AliasManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Builds a protected, paginated route list for SSG/build tooling.
 */
final class RoutesFeedBuilder {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityTypeBundleInfoInterface $bundleInfo,
    private readonly AliasManagerInterface $aliasManager,
    private readonly LanguageManagerInterface $languageManager,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly AccountSwitcherInterface $accountSwitcher,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Returns a single page of headless routes.
   *
   * @return array{items: array<int, array>, next_cursor: string|null, langcode: string}
   *   items: List of route items.
   *   next_cursor: Opaque cursor string for the next page.
   *   langcode: Effective langcode used for aliases.
   */
  public function getPage(int $limit, ?string $cursor, ?string $langcode = NULL): array {
    $limit = max(1, min(200, $limit));

    $effective_langcode = $this->getEffectiveLangcode($langcode);

    $view_items = $this->getViewRouteItems();
    $bundle_keys = $this->getHeadlessBundleKeys();

    $state = $this->decodeCursor($cursor) ?? [
      'segment' => 'views',
      'index' => 0,
    ];

    $items = [];
    $bundle_index = 0;
    $last_id = NULL;

    $anonymous = new AnonymousUserSession();
    $this->accountSwitcher->switchTo($anonymous);
    try {
      // Segment 1: View page routes.
      if (($state['segment'] ?? 'views') === 'views') {
        $index = (int) ($state['index'] ?? 0);
        $index = max(0, $index);

        for ($i = $index; $i < count($view_items) && count($items) < $limit; $i++) {
          $items[] = $view_items[$i];
        }

        if ($i < count($view_items)) {
          return [
            'items' => $items,
            'next_cursor' => $this->encodeCursor([
              'segment' => 'views',
              'index' => $i,
            ]),
            'langcode' => $effective_langcode,
          ];
        }

        // Views exhausted; continue with entities.
        $state = [
          'segment' => 'entities',
          'bundle_index' => 0,
          'last_id' => NULL,
        ];
      }

      // Segment 2: Entity routes.
      $bundle_index = (int) ($state['bundle_index'] ?? 0);
      $bundle_index = max(0, $bundle_index);

      $last_id = NULL;
      if (isset($state['last_id']) && (is_int($state['last_id']) || is_string($state['last_id']))) {
        $last_id = (string) $state['last_id'];
        if ($last_id === '') {
          $last_id = NULL;
        }
      }

      while (count($items) < $limit && $bundle_index < count($bundle_keys)) {
        $bundle_key = $bundle_keys[$bundle_index];
        if (!str_contains($bundle_key, ':')) {
          $bundle_index++;
          $last_id = NULL;
          continue;
        }

        [$entity_type_id, $bundle_id] = explode(':', $bundle_key, 2);
        $entity_type_id = trim($entity_type_id);
        $bundle_id = trim($bundle_id);
        if ($entity_type_id === '' || $bundle_id === '') {
          $bundle_index++;
          $last_id = NULL;
          continue;
        }

        $result = $this->getEntityRouteItemsForBundle(
          $entity_type_id,
          $bundle_id,
          $effective_langcode,
          $last_id,
          $limit - count($items),
        );

        $items = array_merge($items, $result['items']);

        if ($result['next_last_id'] !== NULL) {
          return [
            'items' => $items,
            'next_cursor' => $this->encodeCursor([
              'segment' => 'entities',
              'bundle_index' => $bundle_index,
              'last_id' => $result['next_last_id'],
            ]),
            'langcode' => $effective_langcode,
          ];
        }

        // Bundle exhausted; move to the next bundle.
        $bundle_index++;
        $last_id = NULL;
      }
    }
    finally {
      $this->accountSwitcher->switchBack();
    }

    $next_cursor = NULL;
    if (count($items) >= $limit && $bundle_index < count($bundle_keys)) {
      $next_cursor = $this->encodeCursor([
        'segment' => 'entities',
        'bundle_index' => $bundle_index,
        'last_id' => $last_id,
      ]);
    }

    return [
      'items' => $items,
      'next_cursor' => $next_cursor,
      'langcode' => $effective_langcode,
    ];
  }

  /**
   * Build View page route items.
   *
   * @return array<int, array{path: string, kind: string, data_url: string, jsonapi_url: null}>
   */
  private function getViewRouteItems(): array {
    if (!$this->moduleHandler->moduleExists('views') || !$this->moduleHandler->moduleExists('jsonapi_views')) {
      return [];
    }

    $config = $this->configFactory->get('jsonapi_frontend.settings');
    $enable_all_views = (bool) ($config->get('enable_all_views') ?? TRUE);
    $headless_views = $config->get('headless_views') ?: [];

    $items = [];

    try {
      $storage = $this->entityTypeManager->getStorage('view');

      $views = [];
      if ($enable_all_views) {
        $views = $storage->loadMultiple();
      }
      else {
        foreach ($headless_views as $key) {
          if (!is_string($key) || !str_contains($key, ':')) {
            continue;
          }
          [$view_id] = explode(':', $key, 2);
          $view_id = trim($view_id);
          if ($view_id === '') {
            continue;
          }
          $views[$view_id] = $storage->load($view_id);
        }
      }

      foreach ($views as $view_id => $view) {
        if (!$view || !$view->status()) {
          continue;
        }

        /** @var array $displays */
        $displays = $view->get('display');
        foreach ($displays as $display_id => $display) {
          if (!is_array($display)) {
            continue;
          }
          if (($display['display_plugin'] ?? NULL) !== 'page') {
            continue;
          }

          if (!$enable_all_views) {
            $allowed_key = "{$view_id}:{$display_id}";
            if (!in_array($allowed_key, $headless_views, TRUE)) {
              continue;
            }
          }

          $path = (string) ($display['display_options']['path'] ?? '');
          $path = trim($path);
          if ($path === '') {
            continue;
          }
          $path = $this->normalizePath('/' . ltrim($path, '/'));
          if ($path === '') {
            continue;
          }

          // Skip dynamic Views paths (SSG can’t enumerate arguments).
          if (str_contains($path, '%') || str_contains($path, '{')) {
            continue;
          }

          $items[] = [
            'path' => $path,
            'kind' => 'view',
            'jsonapi_url' => NULL,
            'data_url' => "/jsonapi/views/{$view_id}/{$display_id}",
          ];
        }
      }
    }
    catch (\Exception $e) {
      $this->logger->warning('Could not build View routes feed: @message', [
        '@message' => $e->getMessage(),
      ]);
    }

    usort($items, static fn (array $a, array $b): int => strcmp($a['path'], $b['path']));

    return $items;
  }

  /**
   * Get headless-enabled bundle keys in stable order.
   *
   * @return string[]
   *   Keys in "entity_type:bundle" format.
   */
  private function getHeadlessBundleKeys(): array {
    $config = $this->configFactory->get('jsonapi_frontend.settings');
    $enable_all = (bool) ($config->get('enable_all') ?? TRUE);

    if (!$enable_all) {
      $keys = array_values(array_filter($config->get('headless_bundles') ?: [], 'is_string'));
      sort($keys);
      return $keys;
    }

    $keys = [];

    foreach ($this->getSupportedEntityTypeIds() as $entity_type_id) {
      $bundles = $this->bundleInfo->getBundleInfo($entity_type_id);
      if (empty($bundles)) {
        $keys[] = "{$entity_type_id}:{$entity_type_id}";
        continue;
      }

      foreach (array_keys($bundles) as $bundle_id) {
        $keys[] = "{$entity_type_id}:{$bundle_id}";
      }
    }

    sort($keys);
    return $keys;
  }

  /**
   * @return array{items: array<int, array>, next_last_id: string|null}
   */
  private function getEntityRouteItemsForBundle(
    string $entity_type_id,
    string $bundle_id,
    string $langcode,
    ?string $last_id,
    int $limit,
  ): array {
    $definition = $this->entityTypeManager->getDefinition($entity_type_id, FALSE);
    if (!$definition || !$definition->entityClassImplements(ContentEntityInterface::class) || !$definition->hasLinkTemplate('canonical')) {
      return ['items' => [], 'next_last_id' => NULL];
    }

    $storage = $this->entityTypeManager->getStorage($entity_type_id);
    $id_key = (string) ($definition->getKey('id') ?? '');
    if ($id_key === '') {
      return ['items' => [], 'next_last_id' => NULL];
    }

    $query = $storage->getQuery()->accessCheck(TRUE);

    $bundle_key = $definition->getKey('bundle');
    if (is_string($bundle_key) && $bundle_key !== '') {
      $query->condition($bundle_key, $bundle_id);
    }

    $status_key = $definition->getKey('status');
    if (is_string($status_key) && $status_key !== '') {
      $query->condition($status_key, 1);
    }

    if ($last_id !== NULL) {
      $query->condition($id_key, $last_id, '>');
    }

    $query->sort($id_key, 'ASC');
    $query->range(0, $limit);

    $ids = array_values($query->execute());
    if (empty($ids)) {
      return ['items' => [], 'next_last_id' => NULL];
    }

    $loaded = $storage->loadMultiple($ids);
    $items = [];

    $last_seen_id = NULL;
    foreach ($ids as $id) {
      $last_seen_id = (string) $id;
      $entity = $loaded[$id] ?? NULL;
      if (!$entity instanceof ContentEntityInterface) {
        continue;
      }

      // Ensure access is enforced and treat restricted content as absent.
      if (!$entity->access('view')) {
        continue;
      }

      $bundle = method_exists($entity, 'bundle') ? (string) $entity->bundle() : $entity_type_id;
      $bundle = $bundle !== '' ? $bundle : $entity_type_id;

      $internal_path = '/' . ltrim($entity->toUrl('canonical', ['absolute' => FALSE])->getInternalPath(), '/');
      $alias = $this->aliasManager->getAliasByPath($internal_path, $langcode);
      $alias = $this->normalizePath($alias !== '' ? $alias : $internal_path);
      if ($alias === '') {
        continue;
      }

      $items[] = [
        'path' => $alias,
        'kind' => 'entity',
        'jsonapi_url' => "/jsonapi/{$entity_type_id}/{$bundle}/{$entity->uuid()}",
        'data_url' => NULL,
      ];
    }

    // If we returned fewer items than requested, this bundle may still have
    // more IDs (e.g., filtered by access). Continue paging until we hit an
    // empty query result.
    if (count($ids) < $limit) {
      return ['items' => $items, 'next_last_id' => NULL];
    }

    return ['items' => $items, 'next_last_id' => $last_seen_id];
  }

  /**
   * Get supported entity type IDs for canonical route listing.
   *
   * @return string[]
   *   Entity type IDs.
   */
  private function getSupportedEntityTypeIds(): array {
    $entity_type_ids = [];

    foreach ($this->entityTypeManager->getDefinitions() as $entity_type_id => $definition) {
      if (!$definition->entityClassImplements(ContentEntityInterface::class)) {
        continue;
      }
      if (!$definition->hasLinkTemplate('canonical')) {
        continue;
      }
      $entity_type_ids[] = $entity_type_id;
    }

    sort($entity_type_ids);

    return $entity_type_ids;
  }

  private function getEffectiveLangcode(?string $langcode): string {
    if (is_string($langcode) && $langcode !== '') {
      return $langcode;
    }

    $config = $this->configFactory->get('jsonapi_frontend.settings');
    $fallback = (string) ($config->get('resolver.langcode_fallback') ?? 'site_default');

    if ($fallback === 'current') {
      return $this->languageManager->getCurrentLanguage(LanguageInterface::TYPE_CONTENT)->getId();
    }

    return $this->languageManager->getDefaultLanguage()->getId();
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

  private function encodeCursor(array $state): string {
    $payload = json_encode($state);
    if (!is_string($payload)) {
      return '';
    }

    return rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
  }

  private function decodeCursor(?string $cursor): ?array {
    if (!is_string($cursor) || $cursor === '') {
      return NULL;
    }

    $base64 = strtr($cursor, '-_', '+/');
    $pad = strlen($base64) % 4;
    if ($pad) {
      $base64 .= str_repeat('=', 4 - $pad);
    }

    $decoded = base64_decode($base64, TRUE);
    if (!is_string($decoded) || $decoded === '') {
      return NULL;
    }

    try {
      $data = json_decode($decoded, TRUE, flags: JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      return NULL;
    }

    return is_array($data) ? $data : NULL;
  }

}
