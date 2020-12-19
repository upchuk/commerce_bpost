<?php

namespace Drupal\commerce_bpost_pickup_test;

use Drupal\commerce_bpost_pickup\PickupPointManager;

/**
 * Mock for the pickup point manager.
 *
 * Returns local results for when calling for pickup point data.
 */
class TestPickupPointManager extends PickupPointManager {

  /**
   * {@inheritdoc}
   */
  public function getClosestToPostalCode(int $postal_code, int $type, int $total) {
    $filename = $postal_code . '-' . $type . '-' . $total;
    $path = drupal_get_path('module', 'commerce_bpost_pickup_test') . '/fixtures/closest-to-postcode/' . $filename . '.txt';
    if (file_exists($path)) {
      $contents = file_get_contents($path);
      return unserialize($contents);
    }

    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getPointDetails($point_id, $point_type) {
    $filename = $point_id . '-' . $point_type;
    $path = drupal_get_path('module', 'commerce_bpost_pickup_test') . '/fixtures/point-details/' . $filename . '.txt';

    if (file_exists($path)) {
      $contents = file_get_contents($path);
      return unserialize($contents);
    }

    return NULL;
  }

}
