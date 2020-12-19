<?php

namespace Drupal\commerce_bpost_pickup;

use Bpost\BpostApiClient\Geo6;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Language\LanguageManagerInterface;

/**
 * Queries and caches pickup point information from BPost.
 */
class PickupPointManager {

  /**
   * @var \Bpost\BpostApiClient\Geo6
   */
  protected $geo;

  /**
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected $languageManager;

  /**
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cache;

  /**
   * PickupPointManager constructor.
   *
   * @param \Drupal\Core\Language\LanguageManagerInterface $languageManager
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   */
  public function __construct(LanguageManagerInterface $languageManager, CacheBackendInterface $cache) {
    $geo6Partner = '999999';
    $geo6AppId = 'A001';
    // @todo determine a better way for the values.
    $this->geo = new Geo6($geo6Partner, $geo6AppId);
    $this->languageManager = $languageManager;
    $this->cache = $cache;
  }

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
   */
  public function getClosestToPostalCode(int $postal_code, int $type, int $total) {
    $cid = 'close:' . $postal_code . '_' . $type . '_' . $total;
    if ($cache = $this->cache->get($cid)) {
      return $cache->data;
    }

    try {
      $points = $this->geo->getNearestServicePoint(
        '',
        '',
        $postal_code,
        $this->getLanguage(),
        $type,
        $total
      );
    }
    catch (\Exception $exception) {
      return [];
    }

    $this->cache->set($cid, $points);
    return $points;
  }

  /**
   * Returns details about a given point.
   *
   * @param $point_id
   *   The point ID.
   * @param $point_type
   *   The point type.
   *
   * @return \Bpost\BpostApiClient\Geo6\Poi|null
   */
  public function getPointDetails($point_id, $point_type) {
    $cid = 'point:' . $point_id . '_' . $point_type;
    if ($cache = $this->cache->get($cid)) {
      return $cache->data;
    }

    try {
      $poi = $this->geo->getServicePointDetails($point_id, $this->getLanguage(), $point_type);
    }
    catch (\Exception $exception) {
      return NULL;
    }

    $this->cache->set($cid, $poi);
    return $poi;
  }

  /**
   * Returns the current language to use in requests.
   */
  protected function getLanguage() {
    $languages = [
      'fr',
      'nl',
    ];

    $language = 'fr';
    $current_language = $this->languageManager->getCurrentLanguage()->getId();
    if (in_array($current_language, $languages)) {
      $language = $current_language;
    }

    return $language;
  }

}
