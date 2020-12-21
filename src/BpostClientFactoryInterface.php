<?php

namespace Drupal\commerce_bpost;

/**
 * Instantiates BPost clients.
 */
interface BpostClientFactoryInterface {

  /**
   * Returns a new instance of the BPost client.
   *
   * @param array $configuration
   *   The needed configuration.
   *
   * @return \Bpost\BpostApiClient\Bpost
   *   The BPost client.
   */
  public function getClient(array $configuration);

}
