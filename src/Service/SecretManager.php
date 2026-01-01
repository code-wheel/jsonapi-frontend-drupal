<?php

declare(strict_types=1);

namespace Drupal\jsonapi_frontend\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;

/**
 * Provides secret values without storing them in config exports.
 *
 * Precedence (highest → lowest):
 * 1) settings.php ($settings['jsonapi_frontend'][...])
 * 2) settings.php config overrides ($config['jsonapi_frontend.settings'][...])
 * 3) state (set via admin UI)
 * 4) config storage (legacy fallback)
 */
final class SecretManager {

  private const SETTINGS_ROOT_KEY = 'jsonapi_frontend';

  public function __construct(
    private readonly StateInterface $state,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly StorageInterface $configStorage,
  ) {}

  public function getProxySecret(): string {
    return $this->getSecret(
      settings_key: 'proxy_secret',
      state_key: 'jsonapi_frontend.proxy_secret',
      config_key: 'proxy_secret',
    );
  }

  public function isProxySecretOverridden(): bool {
    return $this->isOverridden('proxy_secret', 'proxy_secret');
  }

  public function setProxySecret(string $secret): void {
    $this->state->set('jsonapi_frontend.proxy_secret', trim($secret));
  }

  public function getRevalidationSecret(): string {
    return $this->getSecret(
      settings_key: 'revalidation_secret',
      state_key: 'jsonapi_frontend.revalidation_secret',
      config_key: 'revalidation.secret',
    );
  }

  public function isRevalidationSecretOverridden(): bool {
    return $this->isOverridden('revalidation_secret', 'revalidation.secret');
  }

  public function setRevalidationSecret(string $secret): void {
    $this->state->set('jsonapi_frontend.revalidation_secret', trim($secret));
  }

  public function getRoutesFeedSecret(): string {
    return $this->getSecret(
      settings_key: 'routes_secret',
      state_key: 'jsonapi_frontend.routes_secret',
      config_key: 'routes.secret',
    );
  }

  public function isRoutesFeedSecretOverridden(): bool {
    return $this->isOverridden('routes_secret', 'routes.secret');
  }

  public function setRoutesFeedSecret(string $secret): void {
    $this->state->set('jsonapi_frontend.routes_secret', trim($secret));
  }

  private function getSecret(string $settings_key, string $state_key, string $config_key): string {
    $override = $this->getOverrideFromSettings($settings_key);
    if ($override !== NULL) {
      return $override;
    }

    $config = $this->configFactory->get('jsonapi_frontend.settings');
    $config_value = $config->get($config_key);

    $stored = $this->configStorage->read('jsonapi_frontend.settings');
    $stored_value = is_array($stored) ? $this->getStoredValue($stored, $config_key) : NULL;

    // If the value differs from storage, assume it's overridden and treat it
    // as "external" (wins over state).
    if (is_string($config_value) && trim($config_value) !== '' && $config_value !== $stored_value) {
      return trim($config_value);
    }

    $state_value = $this->state->get($state_key);
    if (is_string($state_value) && trim($state_value) !== '') {
      return trim($state_value);
    }

    return is_string($stored_value) ? trim($stored_value) : '';
  }

  private function isOverridden(string $settings_key, string $config_key): bool {
    if ($this->getOverrideFromSettings($settings_key) !== NULL) {
      return TRUE;
    }

    $config = $this->configFactory->get('jsonapi_frontend.settings');
    $config_value = $config->get($config_key);

    $stored = $this->configStorage->read('jsonapi_frontend.settings');
    $stored_value = is_array($stored) ? $this->getStoredValue($stored, $config_key) : NULL;

    return is_string($config_value) && trim($config_value) !== '' && $config_value !== $stored_value;
  }

  /**
   * Allow secrets to be provided via settings.php.
   *
   * Supported forms:
   * - $settings['jsonapi_frontend']['proxy_secret'] = '...';
   * - $settings['jsonapi_frontend_proxy_secret'] = '...';
   */
  private function getOverrideFromSettings(string $key): ?string {
    $root = Settings::get(self::SETTINGS_ROOT_KEY);
    if (is_array($root) && isset($root[$key]) && is_string($root[$key]) && trim($root[$key]) !== '') {
      return trim($root[$key]);
    }

    $flat = Settings::get(self::SETTINGS_ROOT_KEY . '_' . $key);
    if (is_string($flat) && trim($flat) !== '') {
      return trim($flat);
    }

    return NULL;
  }

  private function getStoredValue(array $stored, string $key): mixed {
    $value = $stored;
    foreach (explode('.', $key) as $part) {
      if (!is_array($value) || !array_key_exists($part, $value)) {
        return NULL;
      }
      $value = $value[$part];
    }

    return $value;
  }

}
