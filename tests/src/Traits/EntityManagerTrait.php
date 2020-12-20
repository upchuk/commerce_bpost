<?php

declare(strict_types = 1);

namespace Drupal\Tests\commerce_bpost\Traits;

use Drupal\commerce_product\Entity\Product;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\Core\Entity\EntityInterface;

/**
 * Helper methods for interacting with entities.
 */
trait EntityManagerTrait {

  /**
   * Load an entity by label.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   * @param string $label
   *   The label of the entity to load.
   *
   * @return \Drupal\Core\Entity\EntityInterface
   *   The loaded entity.
   */
  protected function getEntityByLabel(string $entity_type_id, string $label): EntityInterface {
    $manager = \Drupal::entityTypeManager();
    $entity_type_definition = $manager->getDefinition($entity_type_id);
    $label_field = $manager->getDefinition($entity_type_id)->getKey('label');
    $entity_list = $manager->getStorage($entity_type_id)->loadByProperties([$label_field => $label]);
    if (empty($entity_list)) {
      throw new \Exception(sprintf('No %s entity name %s found.', $entity_type_definition->getLabel(), $label));
    }

    if (count($entity_list) > 1) {
      throw new \Exception(sprintf('Multiple %s entities named "%s" found.', $entity_type_definition->getLabel(), $label));
    }
    return array_shift($entity_list);
  }

  /**
   * Creates a product.
   *
   * @param float $price
   *   The price.
   * @param array $dimensions
   *   The dimensions.
   * @param array $weight
   *   The weight.
   * @param string $bundle
   *   The bundle.
   * @param string|null $title
   *   The title.
   *
   * @return \Drupal\commerce_product\Entity\ProductInterface
   *   The product.
   */
  protected function createProduct(float $price, array $dimensions = [], array $weight = [], string $bundle = 'default', string $title = NULL) {
    $variation = ProductVariation::create([
      'type' => $bundle,
      'sku' => strtolower($this->randomMachineName()),
      'title' => $title ? $title : $this->randomString(),
      'status' => 1,
      'price' => [
        'number' => $price,
        'currency_code' => 'EUR',
      ],
      'dimensions' => $dimensions ? $dimensions : [
        'length' => 10,
        'width' => 10,
        'height' => 10,
        'unit' => 'mm',
      ],
      'weight' => $weight ? $weight : ['number' => 10, 'unit' => 'g'],
    ]);
    $variation->save();

    /** @var \Drupal\commerce_product\Entity\ProductInterface $product */
    $product = Product::create([
      'type' => 'default',
      'title' => $variation->label(),
    ]);
    $product->setVariations([$variation]);
    $product->save();

    return $product;
  }

}
