<?php

declare(strict_types=1);

namespace Drupal\Tests\jsonapi_frontend\Kernel;

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Group;
use Drupal\jsonapi_frontend\Form\SettingsForm;
use Drupal\Core\Form\FormState;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\User;

/**
 * Kernel tests for the settings form when no Views page displays exist.
 *
 * @group jsonapi_frontend
 */
#[RunTestsInSeparateProcesses]
#[Group('jsonapi_frontend')]
final class SettingsFormEmptyViewsTest extends KernelTestBase {

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
  }

  public function testBuildFormShowsEmptyViewsMessage(): void {
    $form_object = SettingsForm::create($this->container);
    $form_state = new FormState();

    $form = $form_object->buildForm([], $form_state);

    $this->assertIsArray($form);
    $this->assertArrayHasKey('views', $form);
    $this->assertArrayHasKey('views_container', $form['views']);
    $this->assertArrayHasKey('headless_views', $form['views']['views_container']);
    $this->assertArrayHasKey('empty', $form['views']['views_container']['headless_views']);
  }

}

