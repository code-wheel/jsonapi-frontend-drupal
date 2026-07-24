<?php

declare(strict_types=1);

namespace Drupal\Tests\jsonapi_frontend\Unit;

use PHPUnit\Framework\Attributes\Group;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\State\StateInterface;
use Drupal\jsonapi_frontend\Service\SecretManager;
use Drupal\Tests\UnitTestCase;

/**
 * Unit tests for SecretManager precedence rules.
 *
 * @group jsonapi_frontend
 * @coversDefaultClass \Drupal\jsonapi_frontend\Service\SecretManager
 */
#[Group('jsonapi_frontend')]
final class SecretManagerTest extends UnitTestCase {

  private function createConfigFactory(array $values): ConfigFactoryInterface {
    $config = new class($values) {
      public function __construct(private readonly array $values) {}

      public function get(string $key): mixed {
        return $this->values[$key] ?? NULL;
      }
    };

    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->with('jsonapi_frontend.settings')->willReturn($config);

    return $factory;
  }

  /**
   * @covers ::getProxySecret
   * @covers ::isProxySecretOverridden
   */
  public function testConfigOverrideWinsOverStateAndStorage(): void {
    $state = $this->createMock(StateInterface::class);
    $state->method('get')->willReturnCallback(static function (string $key): mixed {
      return $key === 'jsonapi_frontend.proxy_secret' ? 'state' : NULL;
    });

    $configFactory = $this->createConfigFactory([
      'proxy_secret' => ' override ',
    ]);

    $storage = $this->createMock(StorageInterface::class);
    $storage->method('read')->with('jsonapi_frontend.settings')->willReturn([
      'proxy_secret' => 'stored',
    ]);

    $manager = new SecretManager($state, $configFactory, $storage);

    $this->assertSame('override', $manager->getProxySecret());
    $this->assertTrue($manager->isProxySecretOverridden());
  }

  /**
   * @covers ::getProxySecret
   * @covers ::isProxySecretOverridden
   */
  public function testStateWinsWhenConfigMatchesStorage(): void {
    $state = $this->createMock(StateInterface::class);
    $state->method('get')->willReturnCallback(static function (string $key): mixed {
      return $key === 'jsonapi_frontend.proxy_secret' ? ' state ' : NULL;
    });

    $configFactory = $this->createConfigFactory([
      'proxy_secret' => 'stored',
    ]);

    $storage = $this->createMock(StorageInterface::class);
    $storage->method('read')->with('jsonapi_frontend.settings')->willReturn([
      'proxy_secret' => 'stored',
    ]);

    $manager = new SecretManager($state, $configFactory, $storage);

    $this->assertSame('state', $manager->getProxySecret());
    $this->assertFalse($manager->isProxySecretOverridden());
  }

  /**
   * @covers ::getRoutesFeedSecret
   * @covers ::getRevalidationSecret
   */
  public function testStorageIsFallbackWhenNothingElseConfigured(): void {
    $state = $this->createMock(StateInterface::class);
    $state->method('get')->willReturn('');

    $configFactory = $this->createConfigFactory([
      'routes.secret' => '',
      'revalidation.secret' => '',
    ]);

    $storage = $this->createMock(StorageInterface::class);
    $storage->method('read')->with('jsonapi_frontend.settings')->willReturn([
      'routes' => [
        'secret' => 'stored-routes',
      ],
      'revalidation' => [
        'secret' => 'stored-revalidation',
      ],
    ]);

    $manager = new SecretManager($state, $configFactory, $storage);

    $this->assertSame('stored-routes', $manager->getRoutesFeedSecret());
    $this->assertSame('stored-revalidation', $manager->getRevalidationSecret());
  }

  /**
   * @covers ::setProxySecret
   * @covers ::setRoutesFeedSecret
   * @covers ::setRevalidationSecret
   */
  public function testSettersTrimValuesBeforeStoringInState(): void {
    $state = $this->createMock(StateInterface::class);
    $calls = [];
    $state->expects($this->exactly(3))
      ->method('set')
      ->willReturnCallback(static function (string $key, mixed $value) use (&$calls): void {
        $calls[] = [$key, $value];
      });

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $storage = $this->createMock(StorageInterface::class);

    $manager = new SecretManager($state, $configFactory, $storage);
    $manager->setProxySecret(' proxy ');
    $manager->setRoutesFeedSecret(" routes\n");
    $manager->setRevalidationSecret("\trevalidation\t");

    $this->assertSame([
      ['jsonapi_frontend.proxy_secret', 'proxy'],
      ['jsonapi_frontend.routes_secret', 'routes'],
      ['jsonapi_frontend.revalidation_secret', 'revalidation'],
    ], $calls);
  }

}
