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
 * Kernel tests for settings form when jsonapi_views is not installed.
 *
 * @group jsonapi_frontend
 */
#[RunTestsInSeparateProcesses]
#[Group('jsonapi_frontend')]
final class SettingsFormNoJsonapiViewsTest extends KernelTestBase {

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

    // Ensure the "enable all" branch is exercised for SSG guidance.
    $this->config('jsonapi_frontend.settings')
      ->set('enable_all', TRUE)
      ->save();
  }

  public function testBuildFormShowsJsonapiViewsInstallNotice(): void {
    $form_object = SettingsForm::create($this->container);
    $form_state = new FormState();

    $form = $form_object->buildForm([], $form_state);

    $this->assertIsArray($form);
    $this->assertArrayHasKey('views', $form);
    $this->assertArrayHasKey('notice', $form['views']);
    $this->assertArrayNotHasKey('enable_all_views', $form['views']);

    $this->assertArrayHasKey('ssg', $form);
    $this->assertArrayHasKey('entities_note', $form['ssg']);
  }

}

