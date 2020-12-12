<?php

declare(strict_types = 1);

namespace Drupal\Tests\commerce_bpost\Traits;

/**
 * Tracks entities of a given type to clean them up once the test ends.
 */
trait ExistingSiteCleanupTrait {

  /**
   * The IDs of the existing entities in the system, keyed by entity type.
   *
   * @var array
   */
  protected $existing = [];

  /**
   * {@inheritdoc}
   */
  protected function deleteExtraEntities(): void {
    // Before tearing down, delete all the extra entities created.
    foreach ($this->existing as $entity_type => $ids) {
      $current_ids = $this->getAllEntityIds($entity_type);
      $test_entity_ids = array_diff($current_ids, $ids);

      if ($test_entity_ids) {
        $storage = \Drupal::entityTypeManager()->getStorage($entity_type);
        $storage->delete($storage->loadMultiple($test_entity_ids));
      }
    }
  }

  /**
   * Keeps track of the entities of a given type and removes the test ones.
   *
   * @param string $entity_type
   *   The entity type to keep track of.
   */
  protected function cleanupEntityType(string $entity_type): void {
    $this->existing[$entity_type] = $this->getAllEntityIds($entity_type);
  }

  /**
   * Returns all the IDs of a given entity type.
   *
   * @param string $entity_type
   *   The entity type.
   *
   * @return array
   *   The entity IDs.
   */
  protected function getAllEntityIds(string $entity_type): array {
    return \Drupal::entityTypeManager()->getStorage($entity_type)->getQuery()->accessCheck(FALSE)->execute();
  }

}
