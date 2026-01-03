<?php

declare(strict_types=1);

namespace Drupal\Tests\jsonapi_frontend\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\State\StateInterface;
use Drupal\jsonapi_frontend\EventSubscriber\ProxySecretSubscriber;
use Drupal\jsonapi_frontend\Service\SecretManager;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Unit tests for the proxy secret request subscriber.
 *
 * @group jsonapi_frontend
 * @coversDefaultClass \Drupal\jsonapi_frontend\EventSubscriber\ProxySecretSubscriber
 */
final class ProxySecretSubscriberTest extends UnitTestCase {

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

  private function createSecretManager(ConfigFactoryInterface $configFactory, string $secret): SecretManager {
    $state = $this->createMock(StateInterface::class);
    $state->method('get')->willReturnCallback(static function (string $key) use ($secret): mixed {
      return $key === 'jsonapi_frontend.proxy_secret' ? $secret : NULL;
    });

    $storage = $this->createMock(StorageInterface::class);
    $storage->method('read')->willReturn([]);

    return new SecretManager($state, $configFactory, $storage);
  }

  private function createEvent(Request $request, int $type = HttpKernelInterface::MAIN_REQUEST): RequestEvent {
    $kernel = $this->createMock(HttpKernelInterface::class);
    return new RequestEvent($kernel, $request, $type);
  }

  /**
   * @covers ::onRequest
   */
  public function testSkipsNonMainRequests(): void {
    $configFactory = $this->createConfigFactory([
      'deployment_mode' => 'nextjs_first',
      'proxy_protect_jsonapi' => FALSE,
    ]);
    $secrets = $this->createSecretManager($configFactory, 'secret');

    $subscriber = new ProxySecretSubscriber($configFactory, $secrets);

    $event = $this->createEvent(Request::create('/somewhere'), HttpKernelInterface::SUB_REQUEST);
    $subscriber->onRequest($event);

    $this->assertNull($event->getResponse());
  }

  /**
   * @covers ::onRequest
   */
  public function testSkipsWhenNotInNextJsFirstMode(): void {
    $configFactory = $this->createConfigFactory([
      'deployment_mode' => 'split_routing',
      'proxy_protect_jsonapi' => FALSE,
    ]);
    $secrets = $this->createSecretManager($configFactory, 'secret');

    $subscriber = new ProxySecretSubscriber($configFactory, $secrets);

    $event = $this->createEvent(Request::create('/somewhere'));
    $subscriber->onRequest($event);

    $this->assertNull($event->getResponse());
  }

  /**
   * @covers ::onRequest
   */
  public function testSkipsWhenSecretIsEmpty(): void {
    $configFactory = $this->createConfigFactory([
      'deployment_mode' => 'nextjs_first',
      'proxy_protect_jsonapi' => FALSE,
    ]);
    $secrets = $this->createSecretManager($configFactory, '');

    $subscriber = new ProxySecretSubscriber($configFactory, $secrets);

    $event = $this->createEvent(Request::create('/somewhere'));
    $subscriber->onRequest($event);

    $this->assertNull($event->getResponse());
  }

  /**
   * @covers ::onRequest
   */
  public function testExcludedPathsBypassValidation(): void {
    $configFactory = $this->createConfigFactory([
      'deployment_mode' => 'nextjs_first',
      'proxy_protect_jsonapi' => FALSE,
    ]);
    $secrets = $this->createSecretManager($configFactory, 'secret');

    $subscriber = new ProxySecretSubscriber($configFactory, $secrets);

    $event = $this->createEvent(Request::create('/ADMIN/config'));
    $subscriber->onRequest($event);
    $this->assertNull($event->getResponse());

    $event = $this->createEvent(Request::create('/jsonapi/resolve'));
    $subscriber->onRequest($event);
    $this->assertNull($event->getResponse());
  }

  /**
   * @covers ::onRequest
   */
  public function testRejectsMissingOrInvalidSecretForProtectedPaths(): void {
    $configFactory = $this->createConfigFactory([
      'deployment_mode' => 'nextjs_first',
      'proxy_protect_jsonapi' => FALSE,
    ]);
    $secrets = $this->createSecretManager($configFactory, 'secret');

    $subscriber = new ProxySecretSubscriber($configFactory, $secrets);

    $event = $this->createEvent(Request::create('/somewhere'));
    $subscriber->onRequest($event);

    $this->assertNotNull($event->getResponse());
    $this->assertSame(403, $event->getResponse()->getStatusCode());
  }

  /**
   * @covers ::onRequest
   */
  public function testCanProtectJsonapiWhenEnabled(): void {
    $configFactory = $this->createConfigFactory([
      'deployment_mode' => 'nextjs_first',
      'proxy_protect_jsonapi' => TRUE,
    ]);
    $secrets = $this->createSecretManager($configFactory, 'secret');

    $subscriber = new ProxySecretSubscriber($configFactory, $secrets);

    $event = $this->createEvent(Request::create('/jsonapi/resolve'));
    $subscriber->onRequest($event);

    $this->assertNotNull($event->getResponse());
    $this->assertSame(403, $event->getResponse()->getStatusCode());
  }

}
