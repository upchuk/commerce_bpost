<?php

namespace Drupal\commerce_bpost_client_test;

use Drupal\commerce_bpost\BpostClientFactoryInterface;

/**
 * Override of the BPost client factory for testing purposes.
 */
class TestBpostClientFactory implements BpostClientFactoryInterface {

  /**
   * {@inheritdoc}
   */
  public function getClient(array $configuration) {
    return new TestBpostClient($configuration['username'], $configuration['password']);
  }

}
