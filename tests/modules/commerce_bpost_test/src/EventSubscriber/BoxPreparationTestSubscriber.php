<?php

namespace Drupal\commerce_bpost_test\EventSubscriber;

use Drupal\commerce_bpost\Event\BoxPreparationEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Test subscriber for the box preparation events.
 */
class BoxPreparationTestSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      BoxPreparationEvent::BOX_ALTER => ['alterBox'],
    ];
  }

  /**
   * Sets a dummy street number to each resulting box.
   */
  public function alterBox(BoxPreparationEvent $event) {
    $box = $event->getBox();
    $destination = $box->getNationalBox() ? $box->getNationalBox() : $box->getInternationalBox();
    $address = $destination->getReceiver()->getAddress();
    $address->setNumber(50);
  }

}
