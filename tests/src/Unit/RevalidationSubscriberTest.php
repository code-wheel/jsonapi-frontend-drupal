<?php

declare(strict_types=1);

namespace Drupal\Tests\jsonapi_frontend\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\State\StateInterface;
use Drupal\jsonapi_frontend\Event\HeadlessContentChangedEvent;
use Drupal\jsonapi_frontend\EventSubscriber\RevalidationSubscriber;
use Drupal\jsonapi_frontend\Service\SecretManager;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;

/**
 * Unit tests for the revalidation webhook subscriber.
 *
 * @group jsonapi_frontend
 * @coversDefaultClass \Drupal\jsonapi_frontend\EventSubscriber\RevalidationSubscriber
 */
final class RevalidationSubscriberTest extends UnitTestCase {

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
      return $key === 'jsonapi_frontend.revalidation_secret' ? $secret : NULL;
    });

    $storage = $this->createMock(StorageInterface::class);
    $storage->method('read')->willReturn([]);

    return new SecretManager($state, $configFactory, $storage);
  }

  private function createEvent(): HeadlessContentChangedEvent {
    $entity = $this->createMock(EntityInterface::class);
    $entity->method('getEntityTypeId')->willReturn('node');
    $entity->method('bundle')->willReturn('page');
    $entity->method('uuid')->willReturn('uuid');

    return new HeadlessContentChangedEvent(
      $entity,
      'update',
      ['/about-us'],
      ['drupal', 'type:node--page'],
    );
  }

  /**
   * @covers ::onContentChanged
   */
  public function testDoesNothingWhenDisabled(): void {
    $configFactory = $this->createConfigFactory([
      'revalidation.enabled' => FALSE,
      'revalidation.url' => 'https://1.1.1.1/api/revalidate',
    ]);

    $secrets = $this->createSecretManager($configFactory, '');
    $client = $this->createMock(ClientInterface::class);
    $client->expects($this->never())->method('request');

    $logger = $this->createMock(LoggerChannelInterface::class);
    $logger->expects($this->never())->method('error');
    $logger->expects($this->never())->method('warning');
    $logger->expects($this->never())->method('info');

    $subscriber = new RevalidationSubscriber($configFactory, $secrets, $client, $logger);
    $subscriber->onContentChanged($this->createEvent());
  }

  /**
   * @covers ::onContentChanged
   */
  public function testRejectsInsecureWebhookUrls(): void {
    $configFactory = $this->createConfigFactory([
      'revalidation.enabled' => TRUE,
      'revalidation.url' => 'http://localhost/revalidate',
    ]);

    $secrets = $this->createSecretManager($configFactory, '');
    $client = $this->createMock(ClientInterface::class);
    $client->expects($this->never())->method('request');

    $logger = $this->createMock(LoggerChannelInterface::class);
    $logger->expects($this->once())->method('error');

    $subscriber = new RevalidationSubscriber($configFactory, $secrets, $client, $logger);
    $subscriber->onContentChanged($this->createEvent());
  }

  /**
   * @covers ::onContentChanged
   */
  public function testSendsWebhookAndLogsOnSuccess(): void {
    $configFactory = $this->createConfigFactory([
      'revalidation.enabled' => TRUE,
      'revalidation.url' => 'https://1.1.1.1/api/revalidate',
    ]);

    $secrets = $this->createSecretManager($configFactory, 'secret');

    $response = $this->createMock(ResponseInterface::class);
    $response->method('getStatusCode')->willReturn(204);

    $client = $this->createMock(ClientInterface::class);
    $client->expects($this->once())
      ->method('request')
      ->with(
        'POST',
        'https://1.1.1.1/api/revalidate',
        $this->callback(static function (array $options): bool {
          $headers = $options['headers'] ?? [];
          if (($headers['X-Revalidation-Secret'] ?? NULL) !== 'secret') {
            return FALSE;
          }
          if (!isset($options['json']['operation']) || $options['json']['operation'] !== 'update') {
            return FALSE;
          }
          if (!isset($options['json']['paths']) || $options['json']['paths'] !== ['/about-us']) {
            return FALSE;
          }
          return isset($options['timeout'], $options['connect_timeout']);
        })
      )
      ->willReturn($response);

    $logger = $this->createMock(LoggerChannelInterface::class);
    $logger->expects($this->once())->method('info');

    $subscriber = new RevalidationSubscriber($configFactory, $secrets, $client, $logger);
    $subscriber->onContentChanged($this->createEvent());
  }

  /**
   * @covers ::onContentChanged
   */
  public function testLogsWarningOnNon2xx(): void {
    $configFactory = $this->createConfigFactory([
      'revalidation.enabled' => TRUE,
      'revalidation.url' => 'https://1.1.1.1/api/revalidate',
    ]);

    $secrets = $this->createSecretManager($configFactory, '');

    $response = $this->createMock(ResponseInterface::class);
    $response->method('getStatusCode')->willReturn(500);

    $client = $this->createMock(ClientInterface::class);
    $client->expects($this->once())->method('request')->willReturn($response);

    $logger = $this->createMock(LoggerChannelInterface::class);
    $logger->expects($this->once())->method('warning');

    $subscriber = new RevalidationSubscriber($configFactory, $secrets, $client, $logger);
    $subscriber->onContentChanged($this->createEvent());
  }

  /**
   * @covers ::onContentChanged
   */
  public function testLogsErrorOnGuzzleException(): void {
    $configFactory = $this->createConfigFactory([
      'revalidation.enabled' => TRUE,
      'revalidation.url' => 'https://1.1.1.1/api/revalidate',
    ]);

    $secrets = $this->createSecretManager($configFactory, '');

    $client = $this->createMock(ClientInterface::class);
    $client->expects($this->once())->method('request')->willThrowException(
      new class('Boom') extends \Exception implements GuzzleException {},
    );

    $logger = $this->createMock(LoggerChannelInterface::class);
    $logger->expects($this->once())->method('error');

    $subscriber = new RevalidationSubscriber($configFactory, $secrets, $client, $logger);
    $subscriber->onContentChanged($this->createEvent());
  }

}
