<?php

declare(strict_types=1);

namespace Drupal\Tests\jsonapi_frontend\Kernel;

use Drupal\Core\Session\AnonymousUserSession;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\RoleInterface;
use Drupal\views\Entity\View;

/**
 * Kernel tests for the build-time routes feed builder.
 *
 * @group jsonapi_frontend
 */
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
final class RoutesFeedBuilderTest extends KernelTestBase {

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
    'views',
    'jsonapi_frontend',
    // Test stub module so moduleExists('jsonapi_views') is TRUE.
    'jsonapi_views',
  ];

  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('path_alias');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['jsonapi_frontend']);

    // Ensure anonymous can view published nodes.
    $anonymous = Role::load(RoleInterface::ANONYMOUS_ID);
    if (!$anonymous) {
      $anonymous = Role::create([
        'id' => RoleInterface::ANONYMOUS_ID,
        'label' => 'Anonymous',
      ]);
    }
    $anonymous->grantPermission('access content');
    $anonymous->save();

    $admin = User::create([
      'name' => 'admin',
      'status' => 1,
    ]);
    $admin->save();
    $this->container->get('current_user')->setAccount($admin);

    NodeType::create([
      'type' => 'page',
      'name' => 'Page',
    ])->save();

    $this->createViewWithPageDisplay('blog', '/blog');

    Node::create([
      'type' => 'page',
      'title' => 'About Us',
      'status' => 1,
      'path' => ['alias' => '/about-us'],
    ])->save();

    Node::create([
      'type' => 'page',
      'title' => 'Contact',
      'status' => 1,
      'path' => ['alias' => '/contact'],
    ])->save();

    $this->config('jsonapi_frontend.settings')
      ->set('resolver.langcode_fallback', 'site_default')
      ->set('enable_all', FALSE)
      ->set('headless_bundles', ['node:page'])
      ->set('enable_all_views', FALSE)
      ->set('headless_views', ['blog:page_1'])
      ->save();
  }

  public function testRoutesFeedPagesThroughViewsThenEntities(): void {
    /** @var \Drupal\jsonapi_frontend\Service\RoutesFeedBuilder $builder */
    $builder = $this->container->get('jsonapi_frontend.routes_feed_builder');

    $this->container->get('current_user')->setAccount(new AnonymousUserSession());

    $page1 = $builder->getPage(1, NULL, NULL);
    $this->assertCount(1, $page1['items']);
    $this->assertSame('/blog', $page1['items'][0]['path']);
    $this->assertSame('view', $page1['items'][0]['kind']);
    $this->assertNotNull($page1['next_cursor']);

    $page2 = $builder->getPage(1, $page1['next_cursor'], NULL);
    $this->assertCount(1, $page2['items']);
    $this->assertSame('entity', $page2['items'][0]['kind']);
    $this->assertSame('/about-us', $page2['items'][0]['path']);
    $this->assertNotNull($page2['next_cursor']);
  }

  private function createViewWithPageDisplay(string $id, string $path): void {
    $view = View::create([
      'id' => $id,
      'label' => ucfirst($id),
      'description' => '',
      'base_table' => 'node_field_data',
      'base_field' => 'nid',
      'status' => TRUE,
      'display' => [
        'default' => [
          'display_plugin' => 'default',
          'id' => 'default',
          'display_title' => 'Default',
          'position' => 0,
          'display_options' => [
            'fields' => [
              'title' => [
                'id' => 'title',
                'table' => 'node_field_data',
                'field' => 'title',
                'plugin_id' => 'field',
              ],
            ],
            'pager' => [
              'type' => 'some',
              'options' => [
                'items_per_page' => 10,
              ],
            ],
            'row' => [
              'type' => 'fields',
            ],
          ],
        ],
        'page_1' => [
          'display_plugin' => 'page',
          'id' => 'page_1',
          'display_title' => 'Page',
          'position' => 1,
          'display_options' => [
            'path' => ltrim($path, '/'),
          ],
        ],
      ],
    ]);

    $view->save();
  }

}

