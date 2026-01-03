<?php

declare(strict_types=1);

namespace Drupal\Tests\jsonapi_frontend\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\User;
use Drupal\views\Entity\View;

/**
 * Kernel tests for the admin settings form.
 *
 * @group jsonapi_frontend
 */
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
final class SettingsFormTest extends KernelTestBase {

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
    // Test stub module to exercise views-related UI and routing branches.
    'jsonapi_views',
  ];

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

    // Ensure headless selections exist so buildForm can populate "SSG" helpers.
    $this->config('jsonapi_frontend.settings')
      ->set('enable_all', FALSE)
      ->set('headless_bundles', ['node:page'])
      ->set('enable_all_views', FALSE)
      ->set('headless_views', ['blog:page_1'])
      ->save();
  }

  public function testBuildFormRendersKeySections(): void {
    $form_object = \Drupal\jsonapi_frontend\Form\SettingsForm::create($this->container);
    $form_state = new FormState();

    $form = $form_object->buildForm([], $form_state);

    $this->assertIsArray($form);
    $this->assertArrayHasKey('deployment', $form);
    $this->assertArrayHasKey('resolver', $form);
    $this->assertArrayHasKey('entities', $form);
    $this->assertArrayHasKey('views', $form);
    $this->assertArrayHasKey('ssg', $form);
    $this->assertArrayHasKey('revalidation', $form);

    // Views section uses the jsonapi_views stub to exercise the "installed"
    // branch (rather than the notice-only fallback).
    $this->assertArrayHasKey('enable_all_views', $form['views']);
  }

  public function testSubmitFormPersistsConfigAndGeneratesSecrets(): void {
    $form_object = \Drupal\jsonapi_frontend\Form\SettingsForm::create($this->container);
    $form_state = new FormState();
    $form = $form_object->buildForm([], $form_state);

    $form_state->setValue('deployment_mode', 'nextjs_first');
    $form_state->setValue('drupal_base_url', 'https://cms.example.com');
    $form_state->setValue('proxy_protect_jsonapi', TRUE);
    $form_state->setValue('generate_secret', TRUE);

    $form_state->setValue('resolver_cache_max_age', 60);
    $form_state->setValue('resolver_langcode_fallback', 'current');

    $form_state->setValue('enable_all', FALSE);
    $form_state->setValue('headless_bundles', [
      'node' => [
        'page' => TRUE,
      ],
    ]);

    $form_state->setValue('enable_all_views', FALSE);
    $form_state->setValue('headless_views', [
      'blog' => [
        'page_1' => TRUE,
      ],
    ]);

    $form_state->setValue('routes_enabled', TRUE);
    $form_state->setValue('generate_routes_secret', TRUE);

    $form_state->setValue('revalidation_enabled', TRUE);
    $form_state->setValue('revalidation_url', 'https://1.1.1.1/api/revalidate');
    $form_state->setValue('generate_revalidation_secret', TRUE);

    $form_object->submitForm($form, $form_state);

    $config = $this->config('jsonapi_frontend.settings');

    $this->assertSame('nextjs_first', $config->get('deployment_mode'));
    $this->assertSame('https://cms.example.com', $config->get('drupal_base_url'));
    $this->assertTrue((bool) $config->get('proxy_protect_jsonapi'));
    $this->assertSame(60, (int) $config->get('resolver.cache_max_age'));
    $this->assertSame('current', (string) $config->get('resolver.langcode_fallback'));

    $this->assertFalse((bool) $config->get('enable_all'));
    $this->assertSame(['node:page'], $config->get('headless_bundles'));
    $this->assertFalse((bool) $config->get('enable_all_views'));
    $this->assertSame(['blog:page_1'], $config->get('headless_views'));

    $this->assertTrue((bool) $config->get('routes.enabled'));
    $this->assertTrue((bool) $config->get('revalidation.enabled'));
    $this->assertSame('https://1.1.1.1/api/revalidate', $config->get('revalidation.url'));

    // Secrets are stored outside config exports.
    $this->assertSame('', (string) $config->get('proxy_secret'));
    $this->assertSame('', (string) $config->get('routes.secret'));
    $this->assertSame('', (string) $config->get('revalidation.secret'));

    /** @var \Drupal\jsonapi_frontend\Service\SecretManager $secret_manager */
    $secret_manager = $this->container->get('jsonapi_frontend.secret_manager');

    $proxy = $secret_manager->getProxySecret();
    $routes = $secret_manager->getRoutesFeedSecret();
    $revalidation = $secret_manager->getRevalidationSecret();

    foreach ([$proxy, $routes, $revalidation] as $secret) {
      $this->assertNotSame('', $secret);
      $this->assertSame(64, strlen($secret));
      $this->assertTrue(ctype_xdigit($secret));
    }
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

