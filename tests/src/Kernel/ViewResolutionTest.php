<?php

declare(strict_types=1);

namespace Drupal\Tests\jsonapi_frontend\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\User;
use Drupal\views\Entity\View;

/**
 * Kernel tests for resolving Views routes.
 *
 * @group jsonapi_frontend
 */
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
final class ViewResolutionTest extends KernelTestBase {

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
    'jsonapi',
    'serialization',
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

    $this->config('jsonapi_frontend.settings')
      ->set('drupal_base_url', 'https://cms.example.com')
      ->set('enable_all_views', FALSE)
      ->save();
  }

  public function testResolveViewRouteHeadlessEnabled(): void {
    $this->config('jsonapi_frontend.settings')
      ->set('headless_views', ['blog:page_1'])
      ->save();

    /** @var \Drupal\jsonapi_frontend\Service\PathResolverInterface $resolver */
    $resolver = $this->container->get('jsonapi_frontend.path_resolver');

    $result = $resolver->resolve('/blog');

    $this->assertTrue($result['resolved']);
    $this->assertSame('view', $result['kind']);
    $this->assertSame('/jsonapi/views/blog/page_1', $result['data_url']);
    $this->assertTrue($result['headless']);
    $this->assertNull($result['drupal_url']);
  }

  public function testResolveViewRouteCanBeNonHeadless(): void {
    $this->config('jsonapi_frontend.settings')
      ->set('headless_views', [])
      ->save();

    /** @var \Drupal\jsonapi_frontend\Service\PathResolverInterface $resolver */
    $resolver = $this->container->get('jsonapi_frontend.path_resolver');

    $result = $resolver->resolve('/blog');

    $this->assertTrue($result['resolved']);
    $this->assertSame('view', $result['kind']);
    $this->assertFalse($result['headless']);
    $this->assertSame('https://cms.example.com/blog', $result['drupal_url']);
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

    // Ensure the Views route exists for path validation.
    $this->container->get('router.builder')->rebuild();
  }

}
