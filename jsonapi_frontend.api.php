<?php

/**
 * @file
 * Hooks and API documentation for JSON:API Frontend module.
 */

/**
 * @defgroup jsonapi_frontend_api JSON:API Frontend API
 * @{
 * Information about the JSON:API Frontend module's API.
 *
 * The JSON:API Frontend module provides events that allow other modules to
 * react to content changes and customize cache invalidation behavior.
 *
 * @section events Events
 *
 * The module dispatches the following Symfony event:
 *
 * - \Drupal\jsonapi_frontend\Event\HeadlessContentChangedEvent::EVENT_NAME
 *   ('jsonapi_frontend.content_changed'): Dispatched when a headless-enabled
 *   entity is inserted, updated, or deleted. Subscribers can use this event
 *   to add custom cache tags, modify paths, or perform additional actions.
 *
 * @section services Services
 *
 * The module provides the following services:
 *
 * - jsonapi_frontend.path_resolver: Resolves frontend paths to JSON:API
 *   resource URLs. Useful for programmatic path resolution.
 *
 * @}
 */

/**
 * @addtogroup hooks
 * @{
 */

/**
 * Example event subscriber for HeadlessContentChangedEvent.
 *
 * To subscribe to the HeadlessContentChangedEvent, create an event subscriber
 * service in your module:
 *
 * @code
 * // mymodule.services.yml
 * services:
 *   mymodule.content_changed_subscriber:
 *     class: Drupal\mymodule\EventSubscriber\ContentChangedSubscriber
 *     tags:
 *       - { name: event_subscriber }
 * @endcode
 *
 * @code
 * // src/EventSubscriber/ContentChangedSubscriber.php
 * namespace Drupal\mymodule\EventSubscriber;
 *
 * use Drupal\jsonapi_frontend\Event\HeadlessContentChangedEvent;
 * use Symfony\Component\EventDispatcher\EventSubscriberInterface;
 *
 * class ContentChangedSubscriber implements EventSubscriberInterface {
 *
 *   public static function getSubscribedEvents(): array {
 *     return [
 *       HeadlessContentChangedEvent::EVENT_NAME => ['onContentChanged', 10],
 *     ];
 *   }
 *
 *   public function onContentChanged(HeadlessContentChangedEvent $event): void {
 *     // Add custom cache tags based on entity relationships.
 *     $entity = $event->getEntity();
 *
 *     // Example: Add tags for referenced taxonomy terms.
 *     if ($entity->hasField('field_tags')) {
 *       foreach ($entity->get('field_tags')->referencedEntities() as $term) {
 *         $event->addTag('taxonomy_term:' . $term->uuid());
 *       }
 *     }
 *
 *     // Example: Add a custom path for invalidation.
 *     $event->addPath('/custom/listing-page');
 *   }
 *
 * }
 * @endcode
 *
 * @see \Drupal\jsonapi_frontend\Event\HeadlessContentChangedEvent
 */

/**
 * Example programmatic path resolution.
 *
 * Use the path resolver service to resolve paths programmatically:
 *
 * @code
 * $resolver = \Drupal::service('jsonapi_frontend.path_resolver');
 * $result = $resolver->resolve('/about-us', 'en');
 *
 * if ($result['resolved']) {
 *   // Path was resolved.
 *   $kind = $result['kind']; // 'entity' or 'view'
 *   $headless = $result['headless']; // TRUE if headless-enabled
 *
 *   if ($result['kind'] === 'entity') {
 *     $jsonapi_url = $result['jsonapi_url'];
 *     $entity_type = $result['entity']['type']; // e.g., 'node--page'
 *     $uuid = $result['entity']['id'];
 *   }
 *   elseif ($result['kind'] === 'view') {
 *     $data_url = $result['data_url'];
 *   }
 * }
 * @endcode
 *
 * @see \Drupal\jsonapi_frontend\Service\PathResolver
 */

/**
 * @} End of "addtogroup hooks".
 */
