<?php

namespace Drupal\commerce_bpost\Exception;

use Drupal\commerce_order\Entity\OrderInterface;

/**
 * Exception thrown when there is a problem with the request.
 */
class BpostCheckoutException extends BpostException {

  /**
   * @var \Drupal\commerce_order\Entity\OrderInterface
   */
  protected $order;

  /**
   * @return \Drupal\commerce_order\Entity\OrderInterface
   */
  public function getOrder(): OrderInterface {
    return $this->order;
  }

  /**
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   */
  public function setOrder(OrderInterface $order): void {
    $this->order = $order;
  }
}
