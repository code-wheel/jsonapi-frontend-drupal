<?php

declare(strict_types=1);

namespace Drupal\jsonapi_frontend\Event;

use Drupal\Core\Entity\EntityInterface;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Event dispatched when headless content changes.
 *
 * This event is fired for entity insert, update, and delete operations
 * on entities that are configured as headless. Subscribers can use this
 * event to trigger cache invalidation webhooks to the frontend.
 */
final class HeadlessContentChangedEvent extends Event {

  /**
   * Event name constant for subscribing.
   */
  public const EVENT_NAME = 'jsonapi_frontend.content_changed';

  /**
   * The entity that changed.
   */
  private readonly EntityInterface $entity;

  /**
   * The operation that occurred.
   */
  private readonly string $operation;

  /**
   * Paths affected by this change.
   */
  private array $paths;

  /**
   * Cache tags for this change.
   */
  private array $tags;

  /**
   * Constructs a HeadlessContentChangedEvent.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity that changed.
   * @param string $operation
   *   The operation: 'insert', 'update', or 'delete'.
   * @param array $paths
   *   Paths affected by this change.
   * @param array $tags
   *   Cache tags for this change.
   */
  public function __construct(
    EntityInterface $entity,
    string $operation,
    array $paths = [],
    array $tags = [],
  ) {
    $this->entity = $entity;
    $this->operation = $operation;
    $this->paths = $paths;
    $this->tags = $tags;
  }

  /**
   * Gets the entity that changed.
   */
  public function getEntity(): EntityInterface {
    return $this->entity;
  }

  /**
   * Gets the operation that occurred.
   */
  public function getOperation(): string {
    return $this->operation;
  }

  /**
   * Gets the paths affected by this change.
   */
  public function getPaths(): array {
    return $this->paths;
  }

  /**
   * Sets the paths affected by this change.
   */
  public function setPaths(array $paths): void {
    $this->paths = $paths;
  }

  /**
   * Adds a path to the affected paths.
   */
  public function addPath(string $path): void {
    $this->paths[] = $path;
  }

  /**
   * Gets the cache tags for this change.
   */
  public function getTags(): array {
    return $this->tags;
  }

  /**
   * Sets the cache tags for this change.
   */
  public function setTags(array $tags): void {
    $this->tags = $tags;
  }

  /**
   * Adds a cache tag.
   */
  public function addTag(string $tag): void {
    $this->tags[] = $tag;
  }

  /**
   * Gets the entity type ID.
   */
  public function getEntityTypeId(): string {
    return $this->entity->getEntityTypeId();
  }

  /**
   * Gets the entity bundle.
   */
  public function getBundle(): string {
    return $this->entity->bundle();
  }

  /**
   * Gets the entity UUID.
   */
  public function getUuid(): string {
    return $this->entity->uuid();
  }

}
