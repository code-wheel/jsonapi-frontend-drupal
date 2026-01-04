<?php

declare(strict_types=1);

namespace Drupal\Tests\jsonapi_frontend\Functional;

use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;

/**
 * Smoke tests for the settings form + resolver endpoint.
 *
 * @group jsonapi_frontend
 */
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
final class SmokeTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'file',
    'node',
    'path',
    'path_alias',
    'serialization',
    'jsonapi',
    'jsonapi_frontend',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  public function testSettingsSaveAndResolverResponse(): void {
    $anonymous = Role::load(RoleInterface::ANONYMOUS_ID);
    $anonymous?->grantPermission('access content');
    $anonymous?->save();

    NodeType::create([
      'type' => 'page',
      'name' => 'Page',
    ])->save();

    $node = Node::create([
      'type' => 'page',
      'title' => 'About Us',
      'status' => 1,
      'path' => ['alias' => '/about-us'],
    ]);
    $node->save();

    $admin = $this->createUser(['administer jsonapi frontend']);
    $this->drupalLogin($admin);

    $this->drupalGet('/admin/config/services/jsonapi-frontend');
    $this->assertSession()->statusCodeEquals(200);

    $this->submitForm([
      'enable_all' => FALSE,
      'headless_bundles[node][page]' => TRUE,
      'resolver_cache_max_age' => 60,
      'resolver_langcode_fallback' => 'site_default',
    ], 'Save configuration');

    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('The configuration options have been saved.');

    $this->drupalLogout();

    $this->drupalGet('/jsonapi/resolve', [
      'query' => [
        'path' => '/about-us',
        '_format' => 'json',
      ],
    ]);
    $this->assertSession()->statusCodeEquals(200);

    $payload = json_decode($this->getSession()->getPage()->getContent(), TRUE);
    $this->assertIsArray($payload);
    $this->assertTrue($payload['resolved']);
    $this->assertSame('entity', $payload['kind']);
    $this->assertSame('node--page', $payload['entity']['type']);
    $this->assertSame($node->uuid(), $payload['entity']['id']);
    $this->assertSame(TRUE, $payload['headless']);
    $this->assertIsString($payload['jsonapi_url']);
    $this->assertStringContainsString('/jsonapi/node/page/', $payload['jsonapi_url']);
  }

}
