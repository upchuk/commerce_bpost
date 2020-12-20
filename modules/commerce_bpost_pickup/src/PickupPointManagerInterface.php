<?php

namespace Drupal\commerce_bpost_pickup;

/**
 * Defines pickup point managers.
 */
interface PickupPointManagerInterface {

  /**
   * Gets the points closest to a postal code.
   *
   * @param int $postal_code
   *   The postal code.
   * @param int $type
   *   Requested point type, possible values are:
   *    - 1: Post Office
   *    - 2: Post Point
   *    - 3: (1+2, Post Office + Post Point)
   *    - 4: bpack 24/7
   *    - 7: (1+2+4, Post Office + Post Point + bpack 24/7)
   * @param int $total
   *   The number of points.
   *
   * @return array
   *   The closest points.
   */
  public function getClosestToPostalCode(int $postal_code, int $type, int $total);

  /**
   * Returns details about a given point.
   *
   * @param int $point_id
   *   The point ID.
   * @param int $point_type
   *   The point type.
   *
   * @return \Bpost\BpostApiClient\Geo6\Poi|null
   *   The point details object.
   */
  public function getPointDetails(int $point_id, int $point_type);

}
