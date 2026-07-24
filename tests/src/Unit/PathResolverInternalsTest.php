<?php

declare(strict_types=1);

namespace Drupal\Tests\jsonapi_frontend\Unit;

use PHPUnit\Framework\Attributes\Group;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Path\PathValidatorInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\jsonapi_frontend\Service\PathResolver;
use Drupal\path_alias\AliasManagerInterface;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Unit tests for PathResolver internal helpers.
 *
 * @group jsonapi_frontend
 * @coversDefaultClass \Drupal\jsonapi_frontend\Service\PathResolver
 */
#[Group('jsonapi_frontend')]
final class PathResolverInternalsTest extends UnitTestCase {

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

  private function createResolver(
    array $configValues = [],
    ?EntityTypeManagerInterface $entityTypeManager = NULL,
    ?RequestStack $requestStack = NULL,
    ?ModuleHandlerInterface $moduleHandler = NULL,
    ?object $redirectRepository = NULL,
  ): PathResolver {
    return new PathResolver(
      $entityTypeManager ?? $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(AliasManagerInterface::class),
      $this->createMock(PathValidatorInterface::class),
      $this->createMock(LanguageManagerInterface::class),
      $moduleHandler ?? $this->createMock(ModuleHandlerInterface::class),
      $this->createConfigFactory($configValues),
      $requestStack ?? $this->createMock(RequestStack::class),
      $redirectRepository,
    );
  }

  private function callPrivate(PathResolver $resolver, string $method, array $args = []): mixed {
    $ref = new \ReflectionMethod($resolver, $method);
    return $ref->invokeArgs($resolver, $args);
  }

  /**
   * @covers ::splitPathAndQuery
   */
  public function testSplitPathAndQueryParsesQueryAndStripsFragment(): void {
    $resolver = $this->createResolver();

    [$path, $query] = $this->callPrivate($resolver, 'splitPathAndQuery', ['/about?foo=bar#frag']);
    $this->assertSame('/about', $path);
    $this->assertSame(['foo' => 'bar'], $query);

    [$path2, $query2] = $this->callPrivate($resolver, 'splitPathAndQuery', ['/about#frag']);
    $this->assertSame('/about', $path2);
    $this->assertSame([], $query2);

    [$path3, $query3] = $this->callPrivate($resolver, 'splitPathAndQuery', ['  ']);
    $this->assertSame('', $path3);
    $this->assertSame([], $query3);
  }

  /**
   * @covers ::normalizePath
   */
  public function testNormalizePathHandlesCommonEdgeCases(): void {
    $resolver = $this->createResolver();

    $this->assertSame('', $this->callPrivate($resolver, 'normalizePath', ['']));
    $this->assertSame('', $this->callPrivate($resolver, 'normalizePath', [str_repeat('a', 2049)]));
    $this->assertSame('', $this->callPrivate($resolver, 'normalizePath', ['?utm=1']));
    $this->assertSame('/about', $this->callPrivate($resolver, 'normalizePath', ['about']));
    $this->assertSame('/about', $this->callPrivate($resolver, 'normalizePath', ['/about/']));
  }

  /**
   * @covers ::canonicalEntityTypeFromRouteName
   */
  public function testCanonicalEntityTypeFromRouteNameRejectsNonCanonicalRoutes(): void {
    $resolver = $this->createResolver();

    $this->assertNull($this->callPrivate($resolver, 'canonicalEntityTypeFromRouteName', ['node.page']));
    $this->assertNull($this->callPrivate($resolver, 'canonicalEntityTypeFromRouteName', ['entity.node.edit_form']));
    $this->assertNull($this->callPrivate($resolver, 'canonicalEntityTypeFromRouteName', ['entity.node.canonical.extra']));
    $this->assertNull($this->callPrivate($resolver, 'canonicalEntityTypeFromRouteName', ['entity.node.sub.canonical']));
    $this->assertNull($this->callPrivate($resolver, 'canonicalEntityTypeFromRouteName', ['entity..canonical']));
  }

  /**
   * @covers ::loadContentEntityFromParam
   */
  public function testLoadContentEntityFromParamReturnsNullWhenDefinitionMissing(): void {
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getDefinition')->with('node', FALSE)->willReturn(FALSE);

    $resolver = $this->createResolver(entityTypeManager: $entityTypeManager);
    $this->assertNull($this->callPrivate($resolver, 'loadContentEntityFromParam', ['node', 1]));
  }

  /**
   * @covers ::loadContentEntityFromParam
   */
  public function testLoadContentEntityFromParamReturnsNullWhenEntityTypeIsNotContentEntity(): void {
    $definition = $this->createMock(EntityTypeInterface::class);
    $definition->method('entityClassImplements')->with(ContentEntityInterface::class)->willReturn(FALSE);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getDefinition')->with('node', FALSE)->willReturn($definition);

    $resolver = $this->createResolver(entityTypeManager: $entityTypeManager);
    $this->assertNull($this->callPrivate($resolver, 'loadContentEntityFromParam', ['node', 1]));
  }

  /**
   * @covers ::loadContentEntityFromParam
   */
  public function testLoadContentEntityFromParamHandlesInvalidAndValidValues(): void {
    $definition = $this->createMock(EntityTypeInterface::class);
    $definition->method('entityClassImplements')->with(ContentEntityInterface::class)->willReturn(TRUE);

    $contentEntity = $this->createMock(ContentEntityInterface::class);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->expects($this->once())->method('load')->with(123)->willReturn($contentEntity);
    $storage->expects($this->never())->method('loadMultiple');

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getDefinition')->with('node', FALSE)->willReturn($definition);
    $entityTypeManager->method('getStorage')->with('node')->willReturn($storage);

    $resolver = $this->createResolver(entityTypeManager: $entityTypeManager);

    $this->assertNull($this->callPrivate($resolver, 'loadContentEntityFromParam', ['node', []]));
    $this->assertNull($this->callPrivate($resolver, 'loadContentEntityFromParam', ['node', '']));
    $this->assertSame($contentEntity, $this->callPrivate($resolver, 'loadContentEntityFromParam', ['node', 123]));
    $this->assertSame($contentEntity, $this->callPrivate($resolver, 'loadContentEntityFromParam', ['node', $contentEntity]));
  }

  /**
   * @covers ::loadContentEntityFromParam
   */
  public function testLoadContentEntityFromParamReturnsNullWhenStorageDoesNotReturnContentEntity(): void {
    $definition = $this->createMock(EntityTypeInterface::class);
    $definition->method('entityClassImplements')->with(ContentEntityInterface::class)->willReturn(TRUE);

    $nonContentEntity = $this->createMock(EntityInterface::class);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->expects($this->once())->method('load')->with('1')->willReturn($nonContentEntity);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getDefinition')->with('node', FALSE)->willReturn($definition);
    $entityTypeManager->method('getStorage')->with('node')->willReturn($storage);

    $resolver = $this->createResolver(entityTypeManager: $entityTypeManager);
    $this->assertNull($this->callPrivate($resolver, 'loadContentEntityFromParam', ['node', '1']));
  }

  /**
   * @covers ::getDrupalUrl
   */
  public function testGetDrupalUrlFallsBackToPathWhenNoRequestAndNoBaseUrl(): void {
    $requestStack = $this->createMock(RequestStack::class);
    $requestStack->method('getCurrentRequest')->willReturn(NULL);

    $resolver = $this->createResolver(configValues: ['drupal_base_url' => ''], requestStack: $requestStack);
    $this->assertSame('/example', $this->callPrivate($resolver, 'getDrupalUrl', ['/example']));
  }

  /**
   * @covers ::resolveRedirect
   */
  public function testResolveRedirectReturnsNullWhenRepositoryThrows(): void {
    $redirectRepository = new class() {
      public function findMatchingRedirect(string $path, array $query, string $langcode): mixed {
        throw new \RuntimeException('Boom');
      }
    };

    $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $moduleHandler->method('moduleExists')->with('redirect')->willReturn(TRUE);

    $resolver = $this->createResolver(moduleHandler: $moduleHandler, redirectRepository: $redirectRepository);
    $this->assertNull($this->callPrivate($resolver, 'resolveRedirect', ['/old', [], 'en']));
  }

  /**
   * @covers ::resolveRedirect
   */
  public function testResolveRedirectReturnsNullWhenRepositoryMissingMethodOrReturnsNonObject(): void {
    $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $moduleHandler->method('moduleExists')->with('redirect')->willReturn(TRUE);

    $noMethodRepository = new class() {
    };

    $resolver = $this->createResolver(moduleHandler: $moduleHandler, redirectRepository: $noMethodRepository);
    $this->assertNull($this->callPrivate($resolver, 'resolveRedirect', ['/old', [], 'en']));

    $nonObjectRepository = new class() {
      public function findMatchingRedirect(string $path, array $query, string $langcode): string {
        return 'nope';
      }
    };

    $resolver2 = $this->createResolver(moduleHandler: $moduleHandler, redirectRepository: $nonObjectRepository);
    $this->assertNull($this->callPrivate($resolver2, 'resolveRedirect', ['/old', [], 'en']));
  }

  /**
   * @covers ::resolveRedirect
   */
  public function testResolveRedirectNormalizesStatusAndTargetPath(): void {
    $redirect = new class() {
      public function getStatusCode(): int {
        return 500;
      }

      public function getRedirectUrl(): object {
        return new class() {
          public function toString(): string {
            return 'about';
          }
        };
      }
    };

    $redirectRepository = new class($redirect) {
      public function __construct(private readonly object $redirect) {}

      public function findMatchingRedirect(string $path, array $query, string $langcode): object {
        return $this->redirect;
      }
    };

    $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $moduleHandler->method('moduleExists')->with('redirect')->willReturn(TRUE);

    $resolver = $this->createResolver(moduleHandler: $moduleHandler, redirectRepository: $redirectRepository);
    $result = $this->callPrivate($resolver, 'resolveRedirect', ['/old', [], 'en']);

    $this->assertIsArray($result);
    $this->assertSame('redirect', $result['kind']);
    $this->assertSame(301, $result['redirect']['status']);
    $this->assertSame('/about', $result['redirect']['to']);
  }

  /**
   * @covers ::resolveRedirect
   */
  public function testResolveRedirectRejectsEmptyTargetAndKeepsAbsoluteUrlsUntouched(): void {
    $emptyTargetRedirect = new class() {
      public function getStatusCode(): string {
        return '302';
      }

      public function getRedirectUrl(): object {
        return new class() {
          public function toString(): string {
            return ' ';
          }
        };
      }
    };

    $absoluteRedirect = new class() {
      public function getStatusCode(): string {
        return '302';
      }

      public function getRedirectUrl(): object {
        return new class() {
          public function toString(): string {
            return 'https://example.com/new';
          }
        };
      }
    };

    $redirectRepository = new class($emptyTargetRedirect, $absoluteRedirect) {
      private int $calls = 0;

      public function __construct(private readonly object $first, private readonly object $second) {}

      public function findMatchingRedirect(string $path, array $query, string $langcode): object {
        $this->calls++;
        return $this->calls === 1 ? $this->first : $this->second;
      }
    };

    $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $moduleHandler->method('moduleExists')->with('redirect')->willReturn(TRUE);

    $resolver = $this->createResolver(moduleHandler: $moduleHandler, redirectRepository: $redirectRepository);
    $this->assertNull($this->callPrivate($resolver, 'resolveRedirect', ['/old', [], 'en']));

    $result = $this->callPrivate($resolver, 'resolveRedirect', ['/old', [], 'en']);
    $this->assertIsArray($result);
    $this->assertSame('https://example.com/new', $result['redirect']['to']);
    $this->assertSame(302, $result['redirect']['status']);
  }

  /**
   * @covers ::extractEntityFromRoute
   */
  public function testExtractEntityFromRouteReturnsUpcastedEntityOrLoadsFromParams(): void {
    $upcasted = $this->createMock(ContentEntityInterface::class);

    $resolver = $this->createResolver();
    $this->assertSame($upcasted, $this->callPrivate($resolver, 'extractEntityFromRoute', [NULL, [$upcasted]]));

    $definition = $this->createMock(EntityTypeInterface::class);
    $definition->method('entityClassImplements')->with(ContentEntityInterface::class)->willReturn(TRUE);

    $contentEntity = $this->createMock(ContentEntityInterface::class);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->with(1)->willReturn($contentEntity);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getDefinition')->with('node', FALSE)->willReturn($definition);
    $entityTypeManager->method('getStorage')->with('node')->willReturn($storage);

    $resolver2 = $this->createResolver(entityTypeManager: $entityTypeManager);

    $params = [
      0 => 123,
      'node' => 1,
    ];
    $this->assertSame($contentEntity, $this->callPrivate($resolver2, 'extractEntityFromRoute', [NULL, $params]));
    $this->assertNull($this->callPrivate($resolver2, 'extractEntityFromRoute', [NULL, [0 => 123]]));
  }

  /**
   * @covers ::jsonapiResourceType
   * @covers ::jsonapiPath
   */
  public function testJsonapiHelpersReturnNullWhenBundleMissing(): void {
    $entity = $this->createMock(EntityInterface::class);
    $entity->method('getEntityTypeId')->willReturn('node');
    $entity->method('bundle')->willReturn('');

    $resolver = $this->createResolver();
    $this->assertNull($this->callPrivate($resolver, 'jsonapiResourceType', [$entity]));
    $this->assertNull($this->callPrivate($resolver, 'jsonapiPath', [$entity]));
  }

  /**
   * @covers ::resolveViewRoute
   */
  public function testResolveViewRouteEarlyReturnsForMissingModulesAndMalformedRoutes(): void {
    $moduleHandler = $this->createMock(ModuleHandlerInterface::class);

    $moduleHandler->method('moduleExists')->willReturnCallback(static function (string $module): bool {
      return $module === 'views';
    });

    $resolver = $this->createResolver(moduleHandler: $moduleHandler);
    $result = $this->callPrivate($resolver, 'resolveViewRoute', ['view.blog.page_1', '/blog', 'en']);
    $this->assertFalse($result['resolved']);

    $moduleHandler2 = $this->createMock(ModuleHandlerInterface::class);
    $moduleHandler2->method('moduleExists')->willReturnCallback(static function (string $module): bool {
      return in_array($module, ['views', 'jsonapi_views'], TRUE);
    });

    $resolver2 = $this->createResolver(moduleHandler: $moduleHandler2);
    $result2 = $this->callPrivate($resolver2, 'resolveViewRoute', ['view.blog', '/blog', 'en']);
    $this->assertFalse($result2['resolved']);

    $moduleHandler3 = $this->createMock(ModuleHandlerInterface::class);
    $moduleHandler3->method('moduleExists')->willReturnCallback(static function (string $module): bool {
      return $module === 'jsonapi_views';
    });

    $resolver3 = $this->createResolver(moduleHandler: $moduleHandler3);
    $result3 = $this->callPrivate($resolver3, 'resolveViewRoute', ['view.blog.page_1', '/blog', 'en']);
    $this->assertFalse($result3['resolved']);
  }

}
