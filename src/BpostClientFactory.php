<?php

namespace Drupal\commerce_bpost;

use Bpost\BpostApiClient\Bpost as BpostClient;

/**
 * Instantiates a new instance of the BPost client.
 */
class BpostClientFactory implements BpostClientFactoryInterface {

  /**
   * {@inheritdoc}
   */
  public function getClient(array $configuration) {
    return new BpostClient($configuration['username'], $configuration['password']);
  }

}
