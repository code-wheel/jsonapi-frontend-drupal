<?php

declare(strict_types=1);

namespace Drupal\Tests\jsonapi_frontend\Unit;

use Drupal\Core\Entity\EntityInterface;
use Drupal\jsonapi_frontend\Event\HeadlessContentChangedEvent;
use Drupal\Tests\UnitTestCase;

/**
 * Unit tests for the HeadlessContentChangedEvent.
 *
 * @group jsonapi_frontend
 * @coversDefaultClass \Drupal\jsonapi_frontend\Event\HeadlessContentChangedEvent
 */
final class HeadlessContentChangedEventTest extends UnitTestCase {

  public function testAccessorsAndMutators(): void {
    $entity = $this->createMock(EntityInterface::class);
    $entity->method('getEntityTypeId')->willReturn('node');
    $entity->method('bundle')->willReturn('page');
    $entity->method('uuid')->willReturn('uuid');

    $event = new HeadlessContentChangedEvent(
      $entity,
      'update',
      ['/about-us'],
      ['drupal'],
    );

    $this->assertSame($entity, $event->getEntity());
    $this->assertSame('update', $event->getOperation());
    $this->assertSame(['/about-us'], $event->getPaths());
    $this->assertSame(['drupal'], $event->getTags());

    $event->addPath('/contact');
    $event->addTag('node:uuid');

    $this->assertSame(['/about-us', '/contact'], $event->getPaths());
    $this->assertSame(['drupal', 'node:uuid'], $event->getTags());

    $event->setPaths(['/reset']);
    $event->setTags(['reset']);

    $this->assertSame(['/reset'], $event->getPaths());
    $this->assertSame(['reset'], $event->getTags());

    $this->assertSame('node', $event->getEntityTypeId());
    $this->assertSame('page', $event->getBundle());
    $this->assertSame('uuid', $event->getUuid());
  }

}

