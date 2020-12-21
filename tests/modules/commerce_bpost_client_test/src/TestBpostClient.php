<?php

namespace Drupal\commerce_bpost_client_test;

use Bpost\BpostApiClient\Bpost;
use Bpost\BpostApiClient\Bpost\Order;

/**
 * Test implementation of the BPost client.
 */
class TestBpostClient extends Bpost {

  /**
   * Stores the last order sent to Bpost.
   *
   * @var \Bpost\BpostApiClient\Bpost\Order|null
   */
  protected $lastOrder = NULL;

  /**
   * {@inheritdoc}
   */
  public function createOrReplaceOrder(Order $order) {
    $this->lastOrder = $order;
  }

  /**
   * Returns the last order sent to BPost.
   *
   * @return \Bpost\BpostApiClient\Bpost\Order|null
   *   The order.
   */
  public function getLastOrder() {
    return $this->lastOrder;
  }

}
