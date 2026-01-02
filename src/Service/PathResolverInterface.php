<?php

declare(strict_types=1);

namespace Drupal\jsonapi_frontend\Service;

/**
 * Interface for path resolution services.
 *
 * Implementations may be decorated by optional integration modules to add
 * support for additional Drupal route types while keeping jsonapi_frontend
 * core minimal.
 */
interface PathResolverInterface {

  /**
   * Resolve a frontend path.
   *
   * @return array
   *   Resolver response array. See PathResolver::resolve() for the canonical
   *   response contract.
   */
  public function resolve(string $path, ?string $langcode = NULL): array;

}

