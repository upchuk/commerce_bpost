<?php

namespace Drupal\commerce_bpost\Exception;

use Drupal\commerce_order\Entity\OrderInterface;

/**
 * Exception thrown when there is a problem with the request.
 */
class BpostCheckoutException extends BpostException {

  /**
   * The order.
   *
   * @var \Drupal\commerce_order\Entity\OrderInterface
   */
  protected $order;

  /**
   * Returns the order that went wrong.
   *
   * @return \Drupal\commerce_order\Entity\OrderInterface
   *   The order.
   */
  public function getOrder(): OrderInterface {
    return $this->order;
  }

  /**
   * Sets the order that went wrong.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   */
  public function setOrder(OrderInterface $order): void {
    $this->order = $order;
  }

}
