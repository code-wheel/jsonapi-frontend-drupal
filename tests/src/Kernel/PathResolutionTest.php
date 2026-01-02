<?php

declare(strict_types=1);

namespace Drupal\Tests\jsonapi_frontend\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\Request;

/**
 * Kernel tests for path resolution functionality.
 *
 * @group jsonapi_frontend
 */
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
class PathResolutionTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'file',
    'node',
    'path',
    'path_alias',
    'jsonapi',
    'serialization',
    'jsonapi_frontend',
  ];

  /**
   * The path resolver service.
   *
   * @var \Drupal\jsonapi_frontend\Service\PathResolver
   */
  protected $pathResolver;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('path_alias');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['jsonapi_frontend']);

    // Ensure route validation and entity access checks run as an allowed user.
    // User 1 is treated as an administrator in Drupal.
    $admin = User::create([
      'name' => 'admin',
      'status' => 1,
    ]);
    $admin->save();
    $this->container->get('current_user')->setAccount($admin);

    $this->pathResolver = $this->container->get('jsonapi_frontend.path_resolver');

    // Create a content type.
    NodeType::create([
      'type' => 'page',
      'name' => 'Page',
    ])->save();
  }

  /**
   * Tests resolving a node path.
   */
  public function testResolveNodePath(): void {
    // Create a node with a path alias.
    $node = Node::create([
      'type' => 'page',
      'title' => 'About Us',
      'status' => 1,
      'path' => ['alias' => '/about-us'],
    ]);
    $node->save();

    // Resolve the path.
    $result = $this->pathResolver->resolve('/about-us');

    $this->assertTrue($result['resolved']);
    $this->assertEquals('entity', $result['kind']);
    $this->assertEquals('node--page', $result['entity']['type']);
    $this->assertEquals($node->uuid(), $result['entity']['id']);
    $this->assertStringContainsString('/jsonapi/node/page/', $result['jsonapi_url']);
  }

  /**
   * Tests resolving a non-existent path.
   */
  public function testResolveNonExistentPath(): void {
    $result = $this->pathResolver->resolve('/does-not-exist');

    $this->assertFalse($result['resolved']);
    $this->assertNull($result['kind']);
    $this->assertNull($result['entity']);
    $this->assertNull($result['jsonapi_url']);
  }

  /**
   * Tests that unpublished nodes are not resolved for anonymous users.
   */
  public function testUnpublishedNodeNotResolved(): void {
    // Create an unpublished node.
    $node = Node::create([
      'type' => 'page',
      'title' => 'Draft Page',
      'status' => 0,
      'path' => ['alias' => '/draft-page'],
    ]);
    $node->save();

    // Ensure we're testing anonymous access.
    $this->container->get('current_user')->setAccount(new AnonymousUserSession());

    // Anonymous user should not see unpublished content.
    $result = $this->pathResolver->resolve('/draft-page');

    $this->assertFalse($result['resolved']);
  }

  /**
   * Tests headless configuration.
   */
  public function testHeadlessConfiguration(): void {
    // Create a published node.
    $node = Node::create([
      'type' => 'page',
      'title' => 'Test Page',
      'status' => 1,
      'path' => ['alias' => '/test-page'],
    ]);
    $node->save();

    // With enable_all = TRUE (default), should be headless.
    $result = $this->pathResolver->resolve('/test-page');
    $this->assertTrue($result['headless']);

    // Disable enable_all and check specific bundle.
    $config = $this->config('jsonapi_frontend.settings');
    $config->set('enable_all', FALSE);
    $config->set('headless_bundles', []);
    $config->save();

    // Clear static cache if any.
    drupal_flush_all_caches();
    $this->pathResolver = $this->container->get('jsonapi_frontend.path_resolver');

    $result = $this->pathResolver->resolve('/test-page');
    $this->assertFalse($result['headless']);
    $this->assertNotNull($result['drupal_url']);
  }

  /**
   * Tests canonical path is returned.
   */
  public function testCanonicalPath(): void {
    $node = Node::create([
      'type' => 'page',
      'title' => 'About Us',
      'status' => 1,
      'path' => ['alias' => '/about-us'],
    ]);
    $node->save();

    $result = $this->pathResolver->resolve('/about-us');

    $this->assertEquals('/about-us', $result['canonical']);
  }

  /**
   * Tests resolving by internal path.
   */
  public function testResolveInternalPath(): void {
    $node = Node::create([
      'type' => 'page',
      'title' => 'Test Node',
      'status' => 1,
    ]);
    $node->save();

    // Resolve by internal path /node/1.
    $result = $this->pathResolver->resolve('/node/' . $node->id());

    $this->assertTrue($result['resolved']);
    $this->assertEquals('entity', $result['kind']);
    $this->assertEquals($node->uuid(), $result['entity']['id']);
  }

  /**
   * Tests the /jsonapi/resolve controller response.
   */
  public function testResolveControllerResponse(): void {
    $node = Node::create([
      'type' => 'page',
      'title' => 'About Us',
      'status' => 1,
      'path' => ['alias' => '/about-us'],
    ]);
    $node->save();

    $controller = \Drupal\jsonapi_frontend\Controller\PathResolverController::create($this->container);
    $request = Request::create('/jsonapi/resolve', 'GET', [
      'path' => '/about-us',
      '_format' => 'json',
    ]);

    $response = $controller->resolve($request);
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertIsArray($payload);
    $this->assertTrue($payload['resolved']);
    $this->assertEquals('entity', $payload['kind']);
    $this->assertEquals($node->uuid(), $payload['entity']['id']);
  }

}
