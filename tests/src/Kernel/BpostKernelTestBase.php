<?php

namespace Drupal\Tests\commerce_bpost\Kernel;

use Bpost\BpostApiClient\Bpost\Order\Address;
use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderItem;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\commerce_product\Entity\ProductVariationType;
use Drupal\commerce_shipping\Entity\Shipment;
use Drupal\commerce_shipping\ShipmentItem;
use Drupal\physical\Weight;
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
   * A test order.
   *
   * @var \Drupal\commerce_order\Entity\OrderInterface
   */
  protected $order;

  /**
   * A test shipment.
   *
   * @var \Drupal\commerce_shipping\Entity\ShipmentInterface
   */
  protected $shipment;

  /**
   * A test shipment method.
   *
   * @var \Drupal\commerce_shipping\Entity\ShippingMethodInterface
   */
  protected $shippingMethod;

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
      'commerce_bpost_test',
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

    $this->user = $this->createUser(['mail' => 'example@example.org']);

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

    // Create a default order setup.
    $product_price = new Price('20', 'EUR');
    $shipping_price = new Price('10', 'EUR');

    $variation = ProductVariation::create([
      'type' => 'default',
      'sku' => 'test-product-01',
      'title' => 'The Godfather',
      'price' => $product_price,
    ]);
    $variation->save();

    $order_item = OrderItem::create([
      'type' => 'default',
      'quantity' => 2,
      'title' => 'A book',
      'purchased_entity' => $variation,
      'unit_price' => $product_price,
    ]);

    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $this->order = Order::create([
      'type' => 'default',
      'state' => 'draft',
      'mail' => $this->user->getEmail(),
      'uid' => $this->user->id(),
      'store_id' => $this->store->id(),
      'order_items' => [$order_item],
    ]);
    $this->order->save();

    $shipping_methods = \Drupal::entityTypeManager()->getStorage('commerce_shipping_method')->loadByProperties(['name' => 'BPost']);
    $this->shippingMethod = reset($shipping_methods);

    /** @var \Drupal\commerce_shipping\Entity\ShipmentInterface $shipment */
    $this->shipment = Shipment::create([
      'type' => 'default',
      'order_id' => $this->order->id(),
      'shipping_method' => $this->shippingMethod,
      'title' => 'Shipment',
      'amount' => $shipping_price,
      'items' => [
        new ShipmentItem([
          'order_item_id' => $order_item->id(),
          'title' => 'Book',
          'quantity' => 1,
          'weight' => new Weight('10', 'g'),
          'declared_value' => $product_price,
        ]),
      ],
    ]);

    $this->shipment->save();
  }

  /**
   * Asserts that an API address object contains the correct values.
   *
   * @param \Bpost\BpostApiClient\Bpost\Order\Address $address
   *   The BPost address.
   * @param array $expected
   *   The expected values.
   */
  protected function assertAddress(Address $address, array $expected) {
    $this->assertEquals($expected['country_code'], $address->getCountryCode());
    $this->assertEquals($expected['locality'], $address->getLocality());
    $this->assertEquals($expected['address_line1'], $address->getStreetName());
    $this->assertEquals($expected['postal_code'], $address->getPostalCode());
  }

}
