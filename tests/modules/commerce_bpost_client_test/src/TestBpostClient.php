<?php

namespace Drupal\commerce_bpost_client_test;

use Bpost\BpostApiClient\Bpost;
use Bpost\BpostApiClient\Bpost\Order;

/**
 * Test implementation of the BPost client.
 */
class TestBpostClient extends Bpost {

  /**
   * {@inheritdoc}
   */
  public function createOrReplaceOrder(Order $order) {
    \Drupal::state()->set('commerce_bpost_client_test.last_order', $order);
  }

}
