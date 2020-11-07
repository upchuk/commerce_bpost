<?php

namespace Drupal\Tests\commerce_bpost\Kernel;

use Drupal\commerce_product\Entity\ProductVariationType;
use Drupal\Tests\commerce_order\Kernel\OrderKernelTestBase;

/**
 * Base class for BPost kernel tests.
 *
 * @package Drupal\Tests\commerce_bpost\Kernel
 */
abstract class BpostKernelTestBase extends OrderKernelTestBase {

  /**
   * A test customer.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $user;

  /**
   * A default name for an address.
   *
   * @var array
   */
  protected $name = [
    'given_name' => 'Dan',
    'family_name' => 'Smith',
  ];

  /**
   * A default national address.
   *
   * @var array
   */
  protected $nationalAddress = [];

  /**
   * A default international address.
   *
   * @var array
   */
  protected $internationalAddress = [];

  /**
   * {@inheritdoc}
   */
  public static $modules = [
    'physical',
    'path',
    'telephone',
    'commerce_shipping',
    'commerce_shipping_test',
    'commerce_bpost',
    'commerce_bpost_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('commerce_shipping_method');
    $this->installEntitySchema('commerce_shipment');
    $this->installConfig([
      'profile',
      'commerce_product',
      'commerce_order',
      'commerce_shipping',
      'commerce_bpost',
      'commerce_bpost_test'
    ]);

    /** @var \Drupal\commerce_product\Entity\ProductVariationTypeInterface $product_variation_type */
    $product_variation_type = ProductVariationType::load('default');
    $product_variation_type->setGenerateTitle(FALSE);
    $product_variation_type->save();

    $this->store->delete();

    module_load_install('commerce_bpost_test');
    commerce_bpost_test_install();

    $stores = \Drupal::entityTypeManager()->getStorage('commerce_store')->loadByProperties(['name' => 'Test store']);
    $this->store = reset($stores);

    $this->user = $this->createUser(['mail' => $this->randomString() . '@example.com']);

    $this->nationalAddress = [
        'country_code' => 'BE',
        'locality' => 'Brussels',
        'postal_code' => 1050,
        'address_line1' => 'Brussels street name',
      ] + $this->name;

    $this->internationalAddress = [
        'country_code' => 'FR',
        'locality' => 'Paris',
        'postal_code' => 75016,
        'address_line1' => 'Paris street name',
      ] + $this->name;
  }

}