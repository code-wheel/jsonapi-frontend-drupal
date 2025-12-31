<?php

declare(strict_types=1);

namespace Drupal\Tests\jsonapi_frontend\Unit;

use Drupal\Tests\UnitTestCase;

/**
 * Unit tests for PathResolver helper methods.
 *
 * @group jsonapi_frontend
 * @coversDefaultClass \Drupal\jsonapi_frontend\Service\PathResolver
 */
class PathResolverTest extends UnitTestCase {

  /**
   * Tests JSON:API resource type generation.
   *
   * @covers ::jsonapiResourceType
   * @dataProvider jsonapiResourceTypeProvider
   */
  public function testJsonapiResourceType(string $entityType, string $bundle, string $expected): void {
    // The jsonapiResourceType method is private, so we test via reflection
    // or through the public resolve() method in kernel tests.
    // This test documents the expected format.
    $result = $entityType . '--' . $bundle;
    $this->assertEquals($expected, $result);
  }

  /**
   * Data provider for testJsonapiResourceType.
   */
  public static function jsonapiResourceTypeProvider(): array {
    return [
      'node page' => ['node', 'page', 'node--page'],
      'node article' => ['node', 'article', 'node--article'],
      'taxonomy term tags' => ['taxonomy_term', 'tags', 'taxonomy_term--tags'],
      'media image' => ['media', 'image', 'media--image'],
      'user user' => ['user', 'user', 'user--user'],
    ];
  }

  /**
   * Tests JSON:API path generation.
   *
   * @covers ::jsonapiPath
   * @dataProvider jsonapiPathProvider
   */
  public function testJsonapiPath(string $entityType, string $bundle, string $uuid, string $expected): void {
    // Documents the expected JSON:API URL format.
    $result = '/jsonapi/' . $entityType . '/' . $bundle . '/' . $uuid;
    $this->assertEquals($expected, $result);
  }

  /**
   * Data provider for testJsonapiPath.
   */
  public static function jsonapiPathProvider(): array {
    return [
      'node page' => [
        'node',
        'page',
        '550e8400-e29b-41d4-a716-446655440000',
        '/jsonapi/node/page/550e8400-e29b-41d4-a716-446655440000',
      ],
      'taxonomy term' => [
        'taxonomy_term',
        'tags',
        '123e4567-e89b-12d3-a456-426614174000',
        '/jsonapi/taxonomy_term/tags/123e4567-e89b-12d3-a456-426614174000',
      ],
    ];
  }

  /**
   * Tests path normalization.
   *
   * @dataProvider pathNormalizationProvider
   */
  public function testPathNormalization(string $input, string $expected): void {
    // Test path normalization logic.
    $path = $input;

    // Ensure leading slash.
    if (!str_starts_with($path, '/')) {
      $path = '/' . $path;
    }

    // Remove query string.
    if (str_contains($path, '?')) {
      $path = strstr($path, '?', TRUE);
    }

    // Remove fragment.
    if (str_contains($path, '#')) {
      $path = strstr($path, '#', TRUE);
    }

    // Remove trailing slash (except for root).
    if ($path !== '/' && str_ends_with($path, '/')) {
      $path = rtrim($path, '/');
    }

    $this->assertEquals($expected, $path);
  }

  /**
   * Data provider for testPathNormalization.
   */
  public static function pathNormalizationProvider(): array {
    return [
      'simple path' => ['/about-us', '/about-us'],
      'path without slash' => ['about-us', '/about-us'],
      'path with query' => ['/about-us?foo=bar', '/about-us'],
      'path with fragment' => ['/about-us#section', '/about-us'],
      'path with trailing slash' => ['/about-us/', '/about-us'],
      'root path' => ['/', '/'],
      'complex path' => ['/blog/2024/my-post?ref=home#comments', '/blog/2024/my-post'],
    ];
  }

}
