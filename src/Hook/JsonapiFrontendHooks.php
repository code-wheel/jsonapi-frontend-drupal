<?php

declare(strict_types=1);

namespace Drupal\jsonapi_frontend\Hook;

use Drupal\Component\Utility\DeprecationHelper;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\jsonapi_frontend\Event\HeadlessContentChangedEvent;
use Drupal\jsonapi_frontend\Service\SecretManager;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Hook implementations for jsonapi_frontend.
 *
 * Drupal 12 removes procedural hook implementations; the functions in
 * jsonapi_frontend.module and .install carry LegacyHook attributes and
 * delegate here so Drupal 10 and 11.0 keep working from the same logic.
 */
class JsonapiFrontendHooks {

  use StringTranslationTrait;

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected ModuleHandlerInterface $moduleHandler,
    protected EventDispatcherInterface $eventDispatcher,
    protected LoggerChannelFactoryInterface $loggerFactory,
    protected SecretManager $secretManager,
  ) {}

  /**
   * Implements hook_entity_insert().
   */
  #[Hook('entity_insert')]
  public function entityInsert(EntityInterface $entity): void {
    $this->dispatchContentEvent($entity, 'insert');
  }

  /**
   * Implements hook_entity_update().
   */
  #[Hook('entity_update')]
  public function entityUpdate(EntityInterface $entity): void {
    $this->dispatchContentEvent($entity, 'update');
  }

  /**
   * Implements hook_entity_delete().
   */
  #[Hook('entity_delete')]
  public function entityDelete(EntityInterface $entity): void {
    $this->dispatchContentEvent($entity, 'delete');
  }

  /**
   * Implements hook_runtime_requirements().
   */
  #[Hook('runtime_requirements')]
  public function runtimeRequirements(): array {
    $requirements = [];
    $config = $this->configFactory->get('jsonapi_frontend.settings');

    // phpcs:disable Drupal.Semantics.ConstantName.ConstantRename
    $warning = static fn() => DeprecationHelper::backwardsCompatibleCall(\Drupal::VERSION, '11.2.0', static fn() => RequirementSeverity::Warning, static fn() => REQUIREMENT_WARNING);
    $info = static fn() => DeprecationHelper::backwardsCompatibleCall(\Drupal::VERSION, '11.2.0', static fn() => RequirementSeverity::Info, static fn() => REQUIREMENT_INFO);
    // phpcs:enable

    if ($config->get('revalidation.enabled') && empty($config->get('revalidation.url'))) {
      $requirements['jsonapi_frontend_revalidation'] = [
        'title' => $this->t('JSON:API Frontend Cache Revalidation'),
        'value' => $this->t('Not configured'),
        'description' => $this->t('Cache revalidation is enabled but no webhook URL is configured. <a href=":url">Configure the webhook URL</a>.', [
          ':url' => Url::fromRoute('jsonapi_frontend.settings')->toString(),
        ]),
        'severity' => $warning(),
      ];
    }

    if ($config->get('deployment_mode') === 'nextjs_first' && $this->secretManager->getProxySecret() === '') {
      $requirements['jsonapi_frontend_proxy_secret'] = [
        'title' => $this->t('JSON:API Frontend Origin Protection'),
        'value' => $this->t('Not configured'),
        'description' => $this->t('Next.js First mode is enabled but no proxy secret is configured. This leaves your Drupal origin unprotected. <a href=":url">Configure a proxy secret</a>.', [
          ':url' => Url::fromRoute('jsonapi_frontend.settings')->toString(),
        ]),
        'severity' => $warning(),
      ];
    }

    if ($config->get('routes.enabled') && $this->secretManager->getRoutesFeedSecret() === '') {
      $requirements['jsonapi_frontend_routes_secret'] = [
        'title' => $this->t('JSON:API Frontend Routes Feed'),
        'value' => $this->t('Not configured'),
        'description' => $this->t('Routes feed is enabled but no routes feed secret is configured. This endpoint requires a secret header to work. <a href=":url">Configure a routes feed secret</a>.', [
          ':url' => Url::fromRoute('jsonapi_frontend.settings')->toString(),
        ]),
        'severity' => $warning(),
      ];
    }

    if (!$this->moduleHandler->moduleExists('jsonapi_views')) {
      $requirements['jsonapi_frontend_views'] = [
        'title' => $this->t('JSON:API Frontend Views Support'),
        'value' => $this->t('jsonapi_views not installed'),
        'description' => $this->t('For Views support in your headless frontend, install the <a href=":url">jsonapi_views</a> module.', [
          ':url' => 'https://www.drupal.org/project/jsonapi_views',
        ]),
        'severity' => $info(),
      ];
    }

    return $requirements;
  }

  /**
   * Dispatches the content changed event for headless entities.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity that changed.
   * @param string $operation
   *   The operation: 'insert', 'update', or 'delete'.
   */
  protected function dispatchContentEvent(EntityInterface $entity, string $operation): void {
    if (!$this->isEntityHeadless($entity)) {
      return;
    }

    if ($operation !== 'delete' && method_exists($entity, 'isPublished') && !$entity->isPublished()) {
      return;
    }

    $entity_type_id = $entity->getEntityTypeId();
    $bundle = $entity->bundle();
    $uuid = $entity->uuid();

    $tags = [
      'drupal',
      "type:{$entity_type_id}--{$bundle}",
      "bundle:{$bundle}",
      "{$entity_type_id}:{$uuid}",
      "uuid:{$uuid}",
    ];

    $paths = [];
    if ($entity->hasLinkTemplate('canonical')) {
      try {
        $paths[] = $entity->toUrl('canonical')->toString();
      }
      catch (\Exception $e) {
        // Some entity types have no canonical URL; that is expected.
        $this->loggerFactory->get('jsonapi_frontend')->debug('Could not get canonical URL for @type @uuid: @message', [
          '@type' => $entity_type_id,
          '@uuid' => $uuid,
          '@message' => $e->getMessage(),
        ]);
      }
    }

    $event = new HeadlessContentChangedEvent($entity, $operation, $paths, $tags);
    $this->eventDispatcher->dispatch($event, HeadlessContentChangedEvent::EVENT_NAME);
  }

  /**
   * Checks whether an entity is configured as headless.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to check.
   *
   * @return bool
   *   TRUE when the entity is headless-enabled.
   */
  protected function isEntityHeadless(EntityInterface $entity): bool {
    if (!$entity instanceof ContentEntityInterface || !$entity->hasLinkTemplate('canonical')) {
      return FALSE;
    }

    $config = $this->configFactory->get('jsonapi_frontend.settings');
    if ($config->get('enable_all')) {
      return TRUE;
    }

    $bundle_key = $entity->getEntityTypeId() . ':' . $entity->bundle();
    $enabled_bundles = $config->get('headless_bundles') ?: [];

    return in_array($bundle_key, $enabled_bundles, TRUE);
  }

}
