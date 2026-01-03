<?php

declare(strict_types=1);

namespace Drupal\Tests\jsonapi_frontend\Kernel;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\jsonapi_frontend\Service\RoutesFeedBuilder;
use Drupal\path_alias\AliasManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Kernel tests for the /jsonapi/routes controller.
 *
 * @group jsonapi_frontend
 */
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
final class RoutesFeedControllerTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'file',
    'jsonapi',
    'serialization',
    'path_alias',
    'jsonapi_frontend',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['jsonapi_frontend']);
  }

  public function testRoutesFeedReturns404WhenDisabled(): void {
    $this->config('jsonapi_frontend.settings')
      ->set('routes.enabled', FALSE)
      ->save();

    $controller = \Drupal\jsonapi_frontend\Controller\RoutesFeedController::create($this->container);
    $request = Request::create('/jsonapi/routes', 'GET', [
      '_format' => 'json',
    ]);

    $response = $controller->routes($request);
    $this->assertSame(404, $response->getStatusCode());
  }

  public function testRoutesFeedReturns500WhenSecretMissing(): void {
    $this->config('jsonapi_frontend.settings')
      ->set('routes.enabled', TRUE)
      ->save();

    /** @var \Drupal\jsonapi_frontend\Service\SecretManager $secrets */
    $secrets = $this->container->get('jsonapi_frontend.secret_manager');
    $secrets->setRoutesFeedSecret('');

    $controller = \Drupal\jsonapi_frontend\Controller\RoutesFeedController::create($this->container);
    $request = Request::create('/jsonapi/routes', 'GET', [
      '_format' => 'json',
    ]);

    $response = $controller->routes($request);
    $this->assertSame(500, $response->getStatusCode());
  }

  public function testRoutesFeedReturns403WhenSecretMismatch(): void {
    $this->config('jsonapi_frontend.settings')
      ->set('routes.enabled', TRUE)
      ->save();

    /** @var \Drupal\jsonapi_frontend\Service\SecretManager $secrets */
    $secrets = $this->container->get('jsonapi_frontend.secret_manager');
    $secrets->setRoutesFeedSecret('expected');

    $controller = \Drupal\jsonapi_frontend\Controller\RoutesFeedController::create($this->container);
    $request = Request::create('/jsonapi/routes', 'GET', [
      '_format' => 'json',
    ]);

    $request->headers->set('X-Routes-Secret', 'wrong');
    $response = $controller->routes($request);
    $this->assertSame(403, $response->getStatusCode());
  }

  public function testRoutesFeedReturns200WithLinksWhenAuthorized(): void {
    $this->config('jsonapi_frontend.settings')
      ->set('routes.enabled', TRUE)
      ->save();

    /** @var \Drupal\jsonapi_frontend\Service\SecretManager $secrets */
    $secrets = $this->container->get('jsonapi_frontend.secret_manager');
    $secrets->setRoutesFeedSecret('expected');

    // Use a real RoutesFeedBuilder instance with mocked dependencies.
    $config = new class {
      public function get(string $key): mixed {
        return match ($key) {
          'enable_all' => FALSE,
          'headless_bundles' => [],
          'enable_all_views' => TRUE,
          'resolver.langcode_fallback' => 'site_default',
          default => NULL,
        };
      }
    };

    $builder_config_factory = $this->createMock(ConfigFactoryInterface::class);
    $builder_config_factory->method('get')->with('jsonapi_frontend.settings')->willReturn($config);

    $language = $this->createMock(LanguageInterface::class);
    $language->method('getId')->willReturn('en');

    $language_manager = $this->createMock(LanguageManagerInterface::class);
    $language_manager->method('getDefaultLanguage')->willReturn($language);

    $module_handler = $this->createMock(ModuleHandlerInterface::class);
    $module_handler->method('moduleExists')->willReturnCallback(static function (string $module): bool {
      return in_array($module, ['views', 'jsonapi_views'], TRUE);
    });

    $view_entity = new class {
      public function status(): bool {
        return TRUE;
      }

      public function get(string $key): mixed {
        if ($key !== 'display') {
          return NULL;
        }

        return [
          'page_1' => [
            'display_plugin' => 'page',
            'display_options' => [
              'path' => 'about',
            ],
          ],
          'page_2' => [
            'display_plugin' => 'page',
            'display_options' => [
              'path' => 'contact',
            ],
          ],
        ];
      }
    };

    $view_storage = new class($view_entity) {
      public function __construct(private readonly object $view) {}

      public function loadMultiple(): array {
        return ['nav' => $this->view];
      }
    };

    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->with('view')->willReturn($view_storage);

    $bundle_info = $this->createMock(EntityTypeBundleInfoInterface::class);
    $alias_manager = $this->createMock(AliasManagerInterface::class);

    $account_switcher = $this->createMock(AccountSwitcherInterface::class);
    $logger = $this->createMock(LoggerInterface::class);

    $builder = new RoutesFeedBuilder(
      $entity_type_manager,
      $bundle_info,
      $alias_manager,
      $language_manager,
      $module_handler,
      $builder_config_factory,
      $account_switcher,
      $logger,
    );

    $this->container->set('jsonapi_frontend.routes_feed_builder', $builder);

    $controller = \Drupal\jsonapi_frontend\Controller\RoutesFeedController::create($this->container);
    $request = Request::create('/jsonapi/routes', 'GET', [
      '_format' => 'json',
      'page' => [
        'limit' => 1,
        'cursor' => 'cur123',
      ],
    ]);
    $request->headers->set('X-Routes-Secret', 'expected');

    $response = $controller->routes($request);
    $this->assertSame(200, $response->getStatusCode());

    $payload = json_decode((string) $response->getContent(), TRUE);
    $this->assertIsArray($payload);
    $this->assertSame('/about', $payload['data'][0]['path']);

    $this->assertNotEmpty($payload['links']['self']);
    $this->assertNotEmpty($payload['links']['next']);
    $this->assertSame('en', $payload['meta']['langcode']);
    $this->assertSame(1, (int) $payload['meta']['page']['limit']);
    $this->assertSame('cur123', $payload['meta']['page']['cursor']);
  }

}
