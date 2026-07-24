<?php

declare(strict_types=1);

namespace Drupal\Tests\jsonapi_frontend\Unit;

use PHPUnit\Framework\Attributes\Group;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Path\PathValidatorInterface;
use Drupal\jsonapi_frontend\Service\PathResolver;
use Drupal\path_alias\AliasManagerInterface;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Unit tests for PathResolver helper methods.
 *
 * @group jsonapi_frontend
 * @coversDefaultClass \Drupal\jsonapi_frontend\Service\PathResolver
 */
#[Group('jsonapi_frontend')]
class PathResolverTest extends UnitTestCase {

  private function createConfigFactory(array $values): ConfigFactoryInterface {
    $config = new class($values) {
      public function __construct(private readonly array $values) {}

      public function get(string $key): mixed {
        return $this->values[$key] ?? NULL;
      }
    };

    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')
      ->with('jsonapi_frontend.settings')
      ->willReturn($config);

    return $factory;
  }

  private function createUrl(string $route_name, array $params): object {
    return new class($route_name, $params) {
      public function __construct(
        private readonly string $routeName,
        private readonly array $params,
      ) {}

      public function getRouteName(): string {
        return $this->routeName;
      }

      public function getRouteParameters(): array {
        return $this->params;
      }
    };
  }

  /**
   * @covers ::resolve
   */
  public function testResolveReturnsNotFoundWhenPathIsEmptyOrTooLong(): void {
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $aliasManager = $this->createMock(AliasManagerInterface::class);
    $pathValidator = $this->createMock(PathValidatorInterface::class);
    $languageManager = $this->createMock(LanguageManagerInterface::class);
    $moduleHandler = $this->createMock(ModuleHandlerInterface::class);

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->expects($this->never())->method('get');

    $requestStack = $this->createMock(RequestStack::class);

    $resolver = new PathResolver(
      $entityTypeManager,
      $aliasManager,
      $pathValidator,
      $languageManager,
      $moduleHandler,
      $configFactory,
      $requestStack,
      NULL,
    );

    $this->assertFalse($resolver->resolve('')['resolved']);
    $this->assertFalse($resolver->resolve('   ')['resolved']);
    $this->assertFalse($resolver->resolve(str_repeat('a', 2050))['resolved']);
  }

  /**
   * @covers ::resolve
   */
  public function testResolveRedirect(): void {
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);

    $aliasManager = $this->createMock(AliasManagerInterface::class);
    $aliasManager->expects($this->never())->method('getPathByAlias');

    $pathValidator = $this->createMock(PathValidatorInterface::class);
    $pathValidator->expects($this->never())->method('getUrlIfValid');

    $languageManager = $this->createMock(LanguageManagerInterface::class);
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $requestStack = $this->createMock(RequestStack::class);

    $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $moduleHandler->method('moduleExists')->willReturnCallback(static function (string $module): bool {
      return $module === 'redirect';
    });

    $redirectRepository = new class {
      public array $calls = [];

      public function findMatchingRedirect(string $source_path, array $query = [], string $language = 'und'): object {
        $this->calls[] = [
          'source_path' => $source_path,
          'query' => $query,
          'language' => $language,
        ];

        return new class {
          public function getStatusCode(): int {
            return 301;
          }

          public function getRedirectUrl(): object {
            return new class {
              public function toString(): string {
                return '/new-path';
              }
            };
          }
        };
      }
    };

    $resolver = new PathResolver(
      $entityTypeManager,
      $aliasManager,
      $pathValidator,
      $languageManager,
      $moduleHandler,
      $configFactory,
      $requestStack,
      $redirectRepository,
    );

    $result = $resolver->resolve('/old-path?utm=1', 'en');

    $this->assertTrue($result['resolved']);
    $this->assertSame('redirect', $result['kind']);
    $this->assertSame('/old-path', $result['canonical']);
    $this->assertSame([
      'to' => '/new-path',
      'status' => 301,
    ], $result['redirect']);
    $this->assertNull($result['entity']);
    $this->assertNull($result['jsonapi_url']);
    $this->assertNull($result['data_url']);

    $this->assertCount(1, $redirectRepository->calls);
    $this->assertSame([
      'utm' => '1',
    ], $redirectRepository->calls[0]['query']);
    $this->assertSame('en', $redirectRepository->calls[0]['language']);
  }

  /**
   * @covers ::resolve
   */
  public function testResolveEntityUsesSiteDefaultLanguageByDefault(): void {
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);

    $definition = $this->createMock(EntityTypeInterface::class);
    $definition->method('entityClassImplements')->with(ContentEntityInterface::class)->willReturn(TRUE);
    $entityTypeManager->method('getDefinition')->with('node', FALSE)->willReturn($definition);

    $storage = $this->createMock(EntityStorageInterface::class);

    $entity = $this->createMock(ContentEntityInterface::class);
    $entity->method('getEntityTypeId')->willReturn('node');
    $entity->method('bundle')->willReturn('page');
    $entity->method('uuid')->willReturn('550e8400-e29b-41d4-a716-446655440000');
    $entity->method('access')->with('view')->willReturn(TRUE);

    $storage->method('load')->with('1')->willReturn($entity);
    $entityTypeManager->method('getStorage')->with('node')->willReturn($storage);

    $aliasManager = $this->createMock(AliasManagerInterface::class);
    $aliasManager->expects($this->once())
      ->method('getPathByAlias')
      ->with('/about-us', 'en')
      ->willReturn('/node/1');
    $aliasManager->expects($this->once())
      ->method('getAliasByPath')
      ->with('/node/1', 'en')
      ->willReturn('/about-us');

    $pathValidator = $this->createMock(PathValidatorInterface::class);
    $pathValidator->expects($this->once())
      ->method('getUrlIfValid')
      ->with('/node/1')
      ->willReturn($this->createUrl('entity.node.canonical', ['node' => '1']));

    $languageManager = $this->createMock(LanguageManagerInterface::class);
    $default = $this->createMock(LanguageInterface::class);
    $default->method('getId')->willReturn('en');
    $languageManager->method('getDefaultLanguage')->willReturn($default);

    $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $moduleHandler->method('moduleExists')->willReturn(FALSE);

    $configFactory = $this->createConfigFactory([
      'resolver.langcode_fallback' => 'site_default',
      'enable_all' => TRUE,
      'drupal_base_url' => 'https://cms.example.com',
    ]);

    $requestStack = $this->createMock(RequestStack::class);

    $resolver = new PathResolver(
      $entityTypeManager,
      $aliasManager,
      $pathValidator,
      $languageManager,
      $moduleHandler,
      $configFactory,
      $requestStack,
      NULL,
    );

    $result = $resolver->resolve('/about-us');

    $this->assertTrue($result['resolved']);
    $this->assertSame('entity', $result['kind']);
    $this->assertSame('/about-us', $result['canonical']);
    $this->assertSame('node--page', $result['entity']['type']);
    $this->assertSame('550e8400-e29b-41d4-a716-446655440000', $result['entity']['id']);
    $this->assertSame('en', $result['entity']['langcode']);
    $this->assertSame('/jsonapi/node/page/550e8400-e29b-41d4-a716-446655440000', $result['jsonapi_url']);
    $this->assertTrue($result['headless']);
    $this->assertNull($result['drupal_url']);
  }

  /**
   * @covers ::resolve
   */
  public function testResolveEntityReturnsDrupalUrlWhenNotHeadless(): void {
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);

    $definition = $this->createMock(EntityTypeInterface::class);
    $definition->method('entityClassImplements')->with(ContentEntityInterface::class)->willReturn(TRUE);
    $entityTypeManager->method('getDefinition')->with('node', FALSE)->willReturn($definition);

    $storage = $this->createMock(EntityStorageInterface::class);

    $entity = $this->createMock(ContentEntityInterface::class);
    $entity->method('getEntityTypeId')->willReturn('node');
    $entity->method('bundle')->willReturn('page');
    $entity->method('uuid')->willReturn('uuid');
    $entity->method('access')->with('view')->willReturn(TRUE);

    $storage->method('load')->with('1')->willReturn($entity);
    $entityTypeManager->method('getStorage')->with('node')->willReturn($storage);

    $aliasManager = $this->createMock(AliasManagerInterface::class);
    $aliasManager->method('getPathByAlias')->willReturn('/node/1');
    $aliasManager->method('getAliasByPath')->willReturn('/about-us');

    $pathValidator = $this->createMock(PathValidatorInterface::class);
    $pathValidator->method('getUrlIfValid')->willReturn($this->createUrl('entity.node.canonical', ['node' => '1']));

    $languageManager = $this->createMock(LanguageManagerInterface::class);
    $default = $this->createMock(LanguageInterface::class);
    $default->method('getId')->willReturn('en');
    $languageManager->method('getDefaultLanguage')->willReturn($default);

    $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $moduleHandler->method('moduleExists')->willReturn(FALSE);

    $configFactory = $this->createConfigFactory([
      'resolver.langcode_fallback' => 'site_default',
      'enable_all' => FALSE,
      'headless_bundles' => [],
      'drupal_base_url' => 'https://cms.example.com/',
    ]);

    $requestStack = $this->createMock(RequestStack::class);
    $requestStack->method('getCurrentRequest')->willReturn(Request::create('https://current.example'));

    $resolver = new PathResolver(
      $entityTypeManager,
      $aliasManager,
      $pathValidator,
      $languageManager,
      $moduleHandler,
      $configFactory,
      $requestStack,
      NULL,
    );

    $result = $resolver->resolve('about-us/');

    $this->assertTrue($result['resolved']);
    $this->assertFalse($result['headless']);
    $this->assertSame('https://cms.example.com/about-us', $result['drupal_url']);
  }

  /**
   * @covers ::resolve
   */
  public function testResolveReturnsNotFoundWhenEntityIsNotViewable(): void {
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);

    $definition = $this->createMock(EntityTypeInterface::class);
    $definition->method('entityClassImplements')->with(ContentEntityInterface::class)->willReturn(TRUE);
    $entityTypeManager->method('getDefinition')->with('node', FALSE)->willReturn($definition);

    $storage = $this->createMock(EntityStorageInterface::class);
    $entity = $this->createMock(ContentEntityInterface::class);
    $entity->method('getEntityTypeId')->willReturn('node');
    $entity->method('bundle')->willReturn('page');
    $entity->method('uuid')->willReturn('uuid');
    $entity->method('access')->with('view')->willReturn(FALSE);

    $storage->method('load')->with('1')->willReturn($entity);
    $entityTypeManager->method('getStorage')->with('node')->willReturn($storage);

    $aliasManager = $this->createMock(AliasManagerInterface::class);
    $aliasManager->method('getPathByAlias')->willReturn('/node/1');
    $aliasManager->method('getAliasByPath')->willReturn('/about-us');

    $pathValidator = $this->createMock(PathValidatorInterface::class);
    $pathValidator->method('getUrlIfValid')->willReturn($this->createUrl('entity.node.canonical', ['node' => '1']));

    $languageManager = $this->createMock(LanguageManagerInterface::class);
    $default = $this->createMock(LanguageInterface::class);
    $default->method('getId')->willReturn('en');
    $languageManager->method('getDefaultLanguage')->willReturn($default);

    $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $moduleHandler->method('moduleExists')->willReturn(FALSE);

    $configFactory = $this->createConfigFactory([
      'resolver.langcode_fallback' => 'site_default',
      'enable_all' => TRUE,
    ]);

    $requestStack = $this->createMock(RequestStack::class);

    $resolver = new PathResolver(
      $entityTypeManager,
      $aliasManager,
      $pathValidator,
      $languageManager,
      $moduleHandler,
      $configFactory,
      $requestStack,
      NULL,
    );

    $this->assertFalse($resolver->resolve('/about-us')['resolved']);
  }

  /**
   * @covers ::resolve
   */
  public function testResolvePrefersUpcastedEntityFromRouteParams(): void {
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->expects($this->never())->method('getDefinition');

    $entity = $this->createMock(ContentEntityInterface::class);
    $entity->method('getEntityTypeId')->willReturn('node');
    $entity->method('bundle')->willReturn('page');
    $entity->method('uuid')->willReturn('uuid');
    $entity->method('access')->with('view')->willReturn(TRUE);

    $aliasManager = $this->createMock(AliasManagerInterface::class);
    $aliasManager->method('getPathByAlias')->willReturn('/node/1');
    $aliasManager->method('getAliasByPath')->willReturn('/about-us');

    $pathValidator = $this->createMock(PathValidatorInterface::class);
    $pathValidator->method('getUrlIfValid')->willReturn($this->createUrl('entity.node.canonical', ['node' => $entity]));

    $languageManager = $this->createMock(LanguageManagerInterface::class);
    $default = $this->createMock(LanguageInterface::class);
    $default->method('getId')->willReturn('en');
    $languageManager->method('getDefaultLanguage')->willReturn($default);

    $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $moduleHandler->method('moduleExists')->willReturn(FALSE);

    $configFactory = $this->createConfigFactory([
      'resolver.langcode_fallback' => 'site_default',
      'enable_all' => TRUE,
    ]);

    $requestStack = $this->createMock(RequestStack::class);

    $resolver = new PathResolver(
      $entityTypeManager,
      $aliasManager,
      $pathValidator,
      $languageManager,
      $moduleHandler,
      $configFactory,
      $requestStack,
      NULL,
    );

    $result = $resolver->resolve('/about-us');
    $this->assertTrue($result['resolved']);
    $this->assertSame('entity', $result['kind']);
  }

  /**
   * @covers ::resolve
   */
  public function testResolveReturnsNotFoundWhenJsonapiResourceTypeCannotBeBuilt(): void {
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);

    $definition = $this->createMock(EntityTypeInterface::class);
    $definition->method('entityClassImplements')->with(ContentEntityInterface::class)->willReturn(TRUE);
    $entityTypeManager->method('getDefinition')->with('node', FALSE)->willReturn($definition);

    $storage = $this->createMock(EntityStorageInterface::class);
    $entityTypeManager->method('getStorage')->with('node')->willReturn($storage);

    $entity = $this->createMock(ContentEntityInterface::class);
    $entity->method('getEntityTypeId')->willReturn('node');
    $entity->method('bundle')->willReturn('');
    $entity->method('uuid')->willReturn('uuid');
    $entity->method('access')->with('view')->willReturn(TRUE);

    $storage->method('load')->with('1')->willReturn($entity);

    $aliasManager = $this->createMock(AliasManagerInterface::class);
    $aliasManager->method('getPathByAlias')->willReturn('/node/1');
    $aliasManager->method('getAliasByPath')->willReturn('/about-us');

    $pathValidator = $this->createMock(PathValidatorInterface::class);
    $pathValidator->method('getUrlIfValid')->willReturn($this->createUrl('entity.node.canonical', ['node' => '1']));

    $languageManager = $this->createMock(LanguageManagerInterface::class);
    $default = $this->createMock(LanguageInterface::class);
    $default->method('getId')->willReturn('en');
    $languageManager->method('getDefaultLanguage')->willReturn($default);

    $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $moduleHandler->method('moduleExists')->willReturn(FALSE);

    $configFactory = $this->createConfigFactory([
      'resolver.langcode_fallback' => 'site_default',
      'enable_all' => TRUE,
    ]);

    $requestStack = $this->createMock(RequestStack::class);

    $resolver = new PathResolver(
      $entityTypeManager,
      $aliasManager,
      $pathValidator,
      $languageManager,
      $moduleHandler,
      $configFactory,
      $requestStack,
      NULL,
    );

    $this->assertFalse($resolver->resolve('/about-us')['resolved']);
  }

  /**
   * @covers ::resolve
   */
  public function testResolveUsesCurrentRequestHostWhenDrupalBaseUrlNotConfigured(): void {
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);

    $definition = $this->createMock(EntityTypeInterface::class);
    $definition->method('entityClassImplements')->with(ContentEntityInterface::class)->willReturn(TRUE);
    $entityTypeManager->method('getDefinition')->with('node', FALSE)->willReturn($definition);

    $storage = $this->createMock(EntityStorageInterface::class);

    $entity = $this->createMock(ContentEntityInterface::class);
    $entity->method('getEntityTypeId')->willReturn('node');
    $entity->method('bundle')->willReturn('page');
    $entity->method('uuid')->willReturn('uuid');
    $entity->method('access')->with('view')->willReturn(TRUE);

    $storage->method('load')->with('1')->willReturn($entity);
    $entityTypeManager->method('getStorage')->with('node')->willReturn($storage);

    $aliasManager = $this->createMock(AliasManagerInterface::class);
    $aliasManager->method('getPathByAlias')->willReturn('/node/1');
    $aliasManager->method('getAliasByPath')->willReturn('/about-us');

    $pathValidator = $this->createMock(PathValidatorInterface::class);
    $pathValidator->method('getUrlIfValid')->willReturn($this->createUrl('entity.node.canonical', ['node' => '1']));

    $languageManager = $this->createMock(LanguageManagerInterface::class);
    $default = $this->createMock(LanguageInterface::class);
    $default->method('getId')->willReturn('en');
    $languageManager->method('getDefaultLanguage')->willReturn($default);

    $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $moduleHandler->method('moduleExists')->willReturn(FALSE);

    $configFactory = $this->createConfigFactory([
      'resolver.langcode_fallback' => 'site_default',
      'enable_all' => FALSE,
      'headless_bundles' => [],
      'drupal_base_url' => '',
    ]);

    $requestStack = $this->createMock(RequestStack::class);
    $requestStack->method('getCurrentRequest')->willReturn(Request::create('https://current.example'));

    $resolver = new PathResolver(
      $entityTypeManager,
      $aliasManager,
      $pathValidator,
      $languageManager,
      $moduleHandler,
      $configFactory,
      $requestStack,
      NULL,
    );

    $result = $resolver->resolve('/about-us');
    $this->assertTrue($result['resolved']);
    $this->assertSame('https://current.example/about-us', $result['drupal_url']);
  }

  /**
   * @covers ::resolve
   */
  public function testResolveViewRoutesReturnNotFoundWhenJsonapiViewsModuleMissing(): void {
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $aliasManager = $this->createMock(AliasManagerInterface::class);
    $aliasManager->method('getPathByAlias')->willReturn('/blog');

    $pathValidator = $this->createMock(PathValidatorInterface::class);
    $pathValidator->method('getUrlIfValid')->willReturn($this->createUrl('view.blog.page_1', []));

    $languageManager = $this->createMock(LanguageManagerInterface::class);
    $default = $this->createMock(LanguageInterface::class);
    $default->method('getId')->willReturn('en');
    $languageManager->method('getDefaultLanguage')->willReturn($default);

    $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $moduleHandler->method('moduleExists')->willReturnCallback(static function (string $module): bool {
      return $module === 'redirect';
    });

    $configFactory = $this->createConfigFactory([
      'resolver.langcode_fallback' => 'site_default',
    ]);

    $requestStack = $this->createMock(RequestStack::class);

    $resolver = new PathResolver(
      $entityTypeManager,
      $aliasManager,
      $pathValidator,
      $languageManager,
      $moduleHandler,
      $configFactory,
      $requestStack,
      NULL,
    );

    $this->assertFalse($resolver->resolve('/blog')['resolved']);
  }

  /**
   * @covers ::resolve
   */
  public function testResolveViewRoutesReturnNotFoundWhenViewsModuleMissing(): void {
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);

    $aliasManager = $this->createMock(AliasManagerInterface::class);
    $aliasManager->method('getPathByAlias')->willReturn('/blog');

    $pathValidator = $this->createMock(PathValidatorInterface::class);
    $pathValidator->method('getUrlIfValid')->willReturn($this->createUrl('view.blog.page_1', []));

    $languageManager = $this->createMock(LanguageManagerInterface::class);
    $default = $this->createMock(LanguageInterface::class);
    $default->method('getId')->willReturn('en');
    $languageManager->method('getDefaultLanguage')->willReturn($default);

    $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $moduleHandler->method('moduleExists')->willReturnCallback(static function (string $module): bool {
      return $module === 'jsonapi_views';
    });

    $configFactory = $this->createConfigFactory([
      'resolver.langcode_fallback' => 'site_default',
    ]);

    $requestStack = $this->createMock(RequestStack::class);

    $resolver = new PathResolver(
      $entityTypeManager,
      $aliasManager,
      $pathValidator,
      $languageManager,
      $moduleHandler,
      $configFactory,
      $requestStack,
      NULL,
    );

    $this->assertFalse($resolver->resolve('/blog')['resolved']);
  }

  /**
   * @covers ::resolve
   */
  public function testResolveViewRoutesReturnNotFoundWhenRouteNameMalformed(): void {
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);

    $aliasManager = $this->createMock(AliasManagerInterface::class);
    $aliasManager->method('getPathByAlias')->willReturn('/blog');

    $pathValidator = $this->createMock(PathValidatorInterface::class);
    $pathValidator->method('getUrlIfValid')->willReturn($this->createUrl('view.blog', []));

    $languageManager = $this->createMock(LanguageManagerInterface::class);
    $default = $this->createMock(LanguageInterface::class);
    $default->method('getId')->willReturn('en');
    $languageManager->method('getDefaultLanguage')->willReturn($default);

    $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $moduleHandler->method('moduleExists')->willReturnCallback(static function (string $module): bool {
      return $module === 'jsonapi_views';
    });

    $configFactory = $this->createConfigFactory([
      'resolver.langcode_fallback' => 'site_default',
    ]);

    $requestStack = $this->createMock(RequestStack::class);

    $resolver = new PathResolver(
      $entityTypeManager,
      $aliasManager,
      $pathValidator,
      $languageManager,
      $moduleHandler,
      $configFactory,
      $requestStack,
      NULL,
    );

    $this->assertFalse($resolver->resolve('/blog')['resolved']);
  }

  /**
   * @covers ::resolve
   */
  public function testResolveRedirectNormalizesInvalidStatusAndTargetPath(): void {
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);

    $aliasManager = $this->createMock(AliasManagerInterface::class);
    $aliasManager->expects($this->never())->method('getPathByAlias');

    $pathValidator = $this->createMock(PathValidatorInterface::class);
    $pathValidator->expects($this->never())->method('getUrlIfValid');

    $languageManager = $this->createMock(LanguageManagerInterface::class);
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $requestStack = $this->createMock(RequestStack::class);

    $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $moduleHandler->method('moduleExists')->willReturnCallback(static function (string $module): bool {
      return $module === 'redirect';
    });

    $redirectRepository = new class {
      public function findMatchingRedirect(string $source_path, array $query = [], string $language = 'und'): object {
        return new class {
          public function getStatusCode(): int {
            return 999;
          }

          public function getRedirectUrl(): object {
            return new class {
              public function toString(): string {
                return 'new-path';
              }
            };
          }
        };
      }
    };

    $resolver = new PathResolver(
      $entityTypeManager,
      $aliasManager,
      $pathValidator,
      $languageManager,
      $moduleHandler,
      $configFactory,
      $requestStack,
      $redirectRepository,
    );

    $result = $resolver->resolve('/old-path?utm=1', 'en');

    $this->assertTrue($result['resolved']);
    $this->assertSame('redirect', $result['kind']);
    $this->assertSame('/old-path', $result['canonical']);
    $this->assertSame([
      'to' => '/new-path',
      'status' => 301,
    ], $result['redirect']);
  }

  /**
   * @covers ::resolve
   */
  public function testResolveRedirectIgnoresNonRedirectRepositoryObjects(): void {
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);

    $aliasManager = $this->createMock(AliasManagerInterface::class);
    $aliasManager->method('getPathByAlias')->willReturn('/does-not-exist');

    $pathValidator = $this->createMock(PathValidatorInterface::class);
    $pathValidator->method('getUrlIfValid')->willReturn(NULL);

    $languageManager = $this->createMock(LanguageManagerInterface::class);
    $default = $this->createMock(LanguageInterface::class);
    $default->method('getId')->willReturn('en');
    $languageManager->method('getDefaultLanguage')->willReturn($default);

    $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $moduleHandler->method('moduleExists')->willReturnCallback(static function (string $module): bool {
      return $module === 'redirect';
    });

    $configFactory = $this->createConfigFactory([
      'resolver.langcode_fallback' => 'site_default',
    ]);

    $requestStack = $this->createMock(RequestStack::class);

    $resolver = new PathResolver(
      $entityTypeManager,
      $aliasManager,
      $pathValidator,
      $languageManager,
      $moduleHandler,
      $configFactory,
      $requestStack,
      new \stdClass(),
    );

    $this->assertFalse($resolver->resolve('/old-path', NULL)['resolved']);
  }

  /**
   * @covers ::resolve
   */
  public function testResolveUsesCurrentLanguageWhenFallbackIsCurrent(): void {
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);

    $aliasManager = $this->createMock(AliasManagerInterface::class);
    $aliasManager->method('getPathByAlias')->willReturn('/node/1');
    $aliasManager->method('getAliasByPath')->willReturn('/about-us');

    $entity = $this->createMock(ContentEntityInterface::class);
    $entity->method('access')->with('view')->willReturn(TRUE);
    $entity->method('getEntityTypeId')->willReturn('node');
    $entity->method('bundle')->willReturn('page');
    $entity->method('uuid')->willReturn('uuid');

    $pathValidator = $this->createMock(PathValidatorInterface::class);
    $pathValidator->method('getUrlIfValid')->willReturn($this->createUrl('entity.node.canonical', ['node' => $entity]));

    $current = $this->createMock(LanguageInterface::class);
    $current->method('getId')->willReturn('fr');

    $languageManager = $this->createMock(LanguageManagerInterface::class);
    $languageManager->expects($this->once())
      ->method('getCurrentLanguage')
      ->with(LanguageInterface::TYPE_CONTENT)
      ->willReturn($current);

    $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $moduleHandler->method('moduleExists')->willReturn(FALSE);

    $configFactory = $this->createConfigFactory([
      'resolver.langcode_fallback' => 'current',
      'enable_all' => TRUE,
    ]);

    $requestStack = $this->createMock(RequestStack::class);

    $resolver = new PathResolver(
      $entityTypeManager,
      $aliasManager,
      $pathValidator,
      $languageManager,
      $moduleHandler,
      $configFactory,
      $requestStack,
      NULL,
    );

    $result = $resolver->resolve('/about-us');
    $this->assertTrue($result['resolved']);
    $this->assertSame('fr', $result['entity']['langcode']);
  }

  /**
   * @covers ::resolve
   */
  public function testResolveReturnsNotFoundWhenNoEntityRouteParametersPresent(): void {
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);

    $aliasManager = $this->createMock(AliasManagerInterface::class);
    $aliasManager->method('getPathByAlias')->willReturn('/node/1');

    $pathValidator = $this->createMock(PathValidatorInterface::class);
    $pathValidator->method('getUrlIfValid')->willReturn($this->createUrl('entity.node.canonical', []));

    $languageManager = $this->createMock(LanguageManagerInterface::class);
    $default = $this->createMock(LanguageInterface::class);
    $default->method('getId')->willReturn('en');
    $languageManager->method('getDefaultLanguage')->willReturn($default);

    $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $moduleHandler->method('moduleExists')->willReturn(FALSE);

    $configFactory = $this->createConfigFactory([
      'resolver.langcode_fallback' => 'site_default',
    ]);

    $requestStack = $this->createMock(RequestStack::class);

    $resolver = new PathResolver(
      $entityTypeManager,
      $aliasManager,
      $pathValidator,
      $languageManager,
      $moduleHandler,
      $configFactory,
      $requestStack,
      NULL,
    );

    $this->assertFalse($resolver->resolve('/about-us')['resolved']);
  }

}
