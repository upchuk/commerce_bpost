<?php

namespace Drupal\commerce_bpost\Event;

use Bpost\BpostApiClient\Bpost\Order\Box;
use Drupal\commerce_shipping\Entity\ShipmentInterface;
use Symfony\Component\EventDispatcher\Event;

/**
 * Event used in the box preparation process.
 */
class BoxPreparationEvent extends Event {

  /**
   * Used to alter a box that has already been prepared.
   */
  const BOX_ALTER = 'commerce_bpost.box_preparation.alter';

  /**
   * The shipment entity.
   *
   * @var \Drupal\commerce_shipping\Entity\ShipmentInterface
   */
  protected $shipment;

  /**
   * The box object.
   *
   * @var \Bpost\BpostApiClient\Bpost\Order\Box
   */
  protected $box;

  /**
   * BoxPreparationEvent constructor.
   *
   * @param \Drupal\commerce_shipping\Entity\ShipmentInterface $shipment
   *   The shipment entity.
   * @param \Bpost\BpostApiClient\Bpost\Order\Box $box
   *   The box object.
   */
  public function __construct(ShipmentInterface $shipment, Box $box) {
    $this->shipment = $shipment;
    $this->box = $box;
  }

  /**
   * Returns the shipment.
   *
   * @return \Drupal\commerce_shipping\Entity\ShipmentInterface
   *   The shipment.
   */
  public function getShipment(): ShipmentInterface {
    return $this->shipment;
  }

  /**
   * Returns the box.
   *
   * @return \Bpost\BpostApiClient\Bpost\Order\Box
   *   The box.
   */
  public function getBox(): Box {
    return $this->box;
  }

}
