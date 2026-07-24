<?php

declare(strict_types=1);

namespace Drupal\Tests\jsonapi_frontend\Unit;

use PHPUnit\Framework\Attributes\Group;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\jsonapi_frontend\Service\RoutesFeedBuilder;
use Drupal\path_alias\AliasManagerInterface;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for RoutesFeedBuilder internals.
 *
 * @group jsonapi_frontend
 * @coversDefaultClass \Drupal\jsonapi_frontend\Service\RoutesFeedBuilder
 */
#[Group('jsonapi_frontend')]
final class RoutesFeedBuilderUnitTest extends UnitTestCase {

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

  private function createBuilder(
    array $configValues = [],
    ?EntityTypeManagerInterface $entityTypeManager = NULL,
    ?EntityTypeBundleInfoInterface $bundleInfo = NULL,
    ?LanguageManagerInterface $languageManager = NULL,
    ?ModuleHandlerInterface $moduleHandler = NULL,
    ?LoggerInterface $logger = NULL,
  ): RoutesFeedBuilder {
    return new RoutesFeedBuilder(
      $entityTypeManager ?? $this->createMock(EntityTypeManagerInterface::class),
      $bundleInfo ?? $this->createMock(EntityTypeBundleInfoInterface::class),
      $this->createMock(AliasManagerInterface::class),
      $languageManager ?? $this->createMock(LanguageManagerInterface::class),
      $moduleHandler ?? $this->createMock(ModuleHandlerInterface::class),
      $this->createConfigFactory($configValues),
      $this->createMock(AccountSwitcherInterface::class),
      $logger ?? $this->createMock(LoggerInterface::class),
    );
  }

  private function callPrivate(RoutesFeedBuilder $builder, string $method, array $args = []): mixed {
    $ref = new \ReflectionMethod($builder, $method);
    return $ref->invokeArgs($builder, $args);
  }

  /**
   * @covers ::encodeCursor
   */
  public function testEncodeCursorReturnsEmptyStringWhenJsonEncodeFails(): void {
    $builder = $this->createBuilder();

    $invalid_utf8 = "\xB1\x31";
    $encoded = $this->callPrivate($builder, 'encodeCursor', [['segment' => $invalid_utf8]]);
    $this->assertSame('', $encoded);
  }

  /**
   * @covers ::encodeCursor
   * @covers ::decodeCursor
   */
  public function testEncodeDecodeCursorRoundTrip(): void {
    $builder = $this->createBuilder();

    $state = ['segment' => 'views', 'index' => 3];
    $cursor = $this->callPrivate($builder, 'encodeCursor', [$state]);

    $this->assertIsString($cursor);
    $this->assertNotSame('', $cursor);
    $this->assertSame($state, $this->callPrivate($builder, 'decodeCursor', [$cursor]));
  }

  /**
   * @covers ::decodeCursor
   */
  public function testDecodeCursorRejectsInvalidPayloads(): void {
    $builder = $this->createBuilder();

    $this->assertNull($this->callPrivate($builder, 'decodeCursor', [NULL]));
    $this->assertNull($this->callPrivate($builder, 'decodeCursor', ['']));
    $this->assertNull($this->callPrivate($builder, 'decodeCursor', ['not-base64']));

    // Base64url for "{" (invalid JSON).
    $this->assertNull($this->callPrivate($builder, 'decodeCursor', ['ew']));
  }

  /**
   * @covers ::normalizePath
   */
  public function testNormalizePathHandlesCommonEdgeCases(): void {
    $builder = $this->createBuilder();

    $this->assertSame('', $this->callPrivate($builder, 'normalizePath', ['']));
    $this->assertSame('', $this->callPrivate($builder, 'normalizePath', ['   ']));
    $this->assertSame('', $this->callPrivate($builder, 'normalizePath', [str_repeat('a', 2049)]));
    $this->assertSame('/about', $this->callPrivate($builder, 'normalizePath', ['about']));
    $this->assertSame('/about', $this->callPrivate($builder, 'normalizePath', ['///about//']));
    $this->assertSame('/about', $this->callPrivate($builder, 'normalizePath', ['/about?x=1#y']));
  }

  /**
   * @covers ::getEffectiveLangcode
   */
  public function testGetEffectiveLangcodeUsesExplicitLangcode(): void {
    $builder = $this->createBuilder();
    $this->assertSame('fr', $this->callPrivate($builder, 'getEffectiveLangcode', ['fr']));
  }

  /**
   * @covers ::getEffectiveLangcode
   */
  public function testGetEffectiveLangcodeFallsBackToDefaultLanguage(): void {
    $language = $this->createMock(LanguageInterface::class);
    $language->method('getId')->willReturn('en');

    $languageManager = $this->createMock(LanguageManagerInterface::class);
    $languageManager->method('getDefaultLanguage')->willReturn($language);

    $builder = $this->createBuilder(['resolver.langcode_fallback' => 'site_default'], languageManager: $languageManager);
    $this->assertSame('en', $this->callPrivate($builder, 'getEffectiveLangcode', [NULL]));
  }

  /**
   * @covers ::getEffectiveLangcode
   */
  public function testGetEffectiveLangcodeFallsBackToCurrentLanguageWhenConfigured(): void {
    $language = $this->createMock(LanguageInterface::class);
    $language->method('getId')->willReturn('de');

    $languageManager = $this->createMock(LanguageManagerInterface::class);
    $languageManager->expects($this->once())
      ->method('getCurrentLanguage')
      ->with(LanguageInterface::TYPE_CONTENT)
      ->willReturn($language);

    $builder = $this->createBuilder(['resolver.langcode_fallback' => 'current'], languageManager: $languageManager);
    $this->assertSame('de', $this->callPrivate($builder, 'getEffectiveLangcode', [NULL]));
  }

  /**
   * @covers ::getHeadlessBundleKeys
   */
  public function testGetHeadlessBundleKeysUsesConfiguredListWhenEnableAllDisabled(): void {
    $builder = $this->createBuilder([
      'enable_all' => FALSE,
      'headless_bundles' => ['taxonomy_term:tags', 123, 'node:page'],
    ]);

    $this->assertSame([
      'node:page',
      'taxonomy_term:tags',
    ], $this->callPrivate($builder, 'getHeadlessBundleKeys'));
  }

  /**
   * @covers ::getHeadlessBundleKeys
   * @covers ::getSupportedEntityTypeIds
   */
  public function testGetHeadlessBundleKeysEnumeratesSupportedEntityBundlesWhenEnableAllEnabled(): void {
    $nodeDefinition = $this->createMock(EntityTypeInterface::class);
    $nodeDefinition->method('entityClassImplements')->with(ContentEntityInterface::class)->willReturn(TRUE);
    $nodeDefinition->method('hasLinkTemplate')->with('canonical')->willReturn(TRUE);

    $blockDefinition = $this->createMock(EntityTypeInterface::class);
    $blockDefinition->method('entityClassImplements')->with(ContentEntityInterface::class)->willReturn(TRUE);
    $blockDefinition->method('hasLinkTemplate')->with('canonical')->willReturn(TRUE);

    $configDefinition = $this->createMock(EntityTypeInterface::class);
    $configDefinition->method('entityClassImplements')->with(ContentEntityInterface::class)->willReturn(FALSE);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getDefinitions')->willReturn([
      'node' => $nodeDefinition,
      'block_content' => $blockDefinition,
      'config_test' => $configDefinition,
    ]);

    $bundleInfo = $this->createMock(EntityTypeBundleInfoInterface::class);
    $bundleInfo->method('getBundleInfo')->willReturnCallback(static function (string $entityTypeId): array {
      return match ($entityTypeId) {
        'node' => ['article' => [], 'page' => []],
        'block_content' => [],
        default => [],
      };
    });

    $builder = $this->createBuilder(
      ['enable_all' => TRUE],
      entityTypeManager: $entityTypeManager,
      bundleInfo: $bundleInfo,
    );

    $this->assertSame([
      'block_content:block_content',
      'node:article',
      'node:page',
    ], $this->callPrivate($builder, 'getHeadlessBundleKeys'));
  }

  /**
   * @covers ::getViewRouteItems
   */
  public function testGetViewRouteItemsSkipsInvalidDisplaysAndDynamicPaths(): void {
    $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $moduleHandler->method('moduleExists')->willReturnCallback(static function (string $module): bool {
      return in_array($module, ['views', 'jsonapi_views'], TRUE);
    });

    $enabledView = new class() {
      public function status(): bool {
        return TRUE;
      }

      public function get(string $key): mixed {
        if ($key !== 'display') {
          return NULL;
        }

        return [
          'page_10' => [
            'display_plugin' => 'page',
            'display_options' => ['path' => '/about'],
          ],
          'page_1' => [
            'display_plugin' => 'page',
            'display_options' => ['path' => 'news'],
          ],
          'page_2' => [
            'display_plugin' => 'page',
            'display_options' => ['path' => 'news/%'],
          ],
          'page_3' => [
            'display_plugin' => 'page',
            'display_options' => ['path' => '{arg}'],
          ],
          'block_1' => [
            'display_plugin' => 'block',
            'display_options' => ['path' => 'ignored'],
          ],
          'page_4' => [
            'display_plugin' => 'page',
            'display_options' => [],
          ],
          'page_5' => 'not-an-array',
        ];
      }

    };

    $disabledView = new class() {
      public function status(): bool {
        return FALSE;
      }

      public function get(string $key): mixed {
        return $key === 'display' ? [] : NULL;
      }

    };

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->expects($this->exactly(3))
      ->method('load')
      ->willReturnCallback(static function (string $viewId) use ($enabledView, $disabledView): object {
        return $viewId === 'enabled' ? $enabledView : $disabledView;
      });

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->with('view')->willReturn($storage);

    $builder = $this->createBuilder(
      [
        'enable_all_views' => FALSE,
        'headless_views' => [
          123,
          'missing-delimiter',
          'enabled:page_1',
          'enabled:page_10',
          'disabled:page_1',
        ],
      ],
      entityTypeManager: $entityTypeManager,
      moduleHandler: $moduleHandler,
    );

    $items = $this->callPrivate($builder, 'getViewRouteItems');
    $this->assertSame([
      [
        'path' => '/about',
        'kind' => 'view',
        'jsonapi_url' => NULL,
        'data_url' => '/jsonapi/views/enabled/page_10',
      ],
      [
        'path' => '/news',
        'kind' => 'view',
        'jsonapi_url' => NULL,
        'data_url' => '/jsonapi/views/enabled/page_1',
      ],
    ], $items);
  }

  /**
   * @covers ::getViewRouteItems
   */
  public function testGetViewRouteItemsLogsWarningOnException(): void {
    $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $moduleHandler->method('moduleExists')->willReturnCallback(static function (string $module): bool {
      return in_array($module, ['views', 'jsonapi_views'], TRUE);
    });

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->with('view')->willThrowException(new \RuntimeException('Boom'));

    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('warning');

    $builder = $this->createBuilder(
      ['enable_all_views' => TRUE],
      entityTypeManager: $entityTypeManager,
      moduleHandler: $moduleHandler,
      logger: $logger,
    );

    $this->assertSame([], $this->callPrivate($builder, 'getViewRouteItems'));
  }

}
