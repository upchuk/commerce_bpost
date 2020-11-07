<?php

namespace Drupal\Tests\commerce_bpost\Kernel;

use Bpost\BpostApiClient\Bpost\Order\Address;
use Bpost\BpostApiClient\Bpost\Order\Box;
use Bpost\BpostApiClient\Bpost\Order\Receiver;
use Bpost\BpostApiClient\Bpost\ProductConfiguration\Product;
use Drupal\commerce_bpost\BpostServiceInterface;
use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderItem;
use Drupal\commerce_order\Entity\OrderType;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\commerce_product\Entity\ProductVariationType;
use Drupal\commerce_shipping\Entity\Shipment;
use Drupal\commerce_shipping\Entity\ShippingMethod;
use Drupal\commerce_shipping\ShipmentItem;
use Drupal\commerce_store\Entity\Store;
use Drupal\physical\Weight;
use Drupal\profile\Entity\Profile;
use Drupal\Tests\commerce_order\Kernel\OrderKernelTestBase;
use Drupal\Tests\commerce_shipping\Kernel\ShippingKernelTestBase;

/**
 * Tests the BPost services.
 *
 * @package Drupal\Tests\commerce_bpost\Kernel
 */
class BpostServiceTest extends BpostKernelTestBase {

  /**
   * Tests that an API "box" can be prepared using the Home Delivery service.
   */
  public function testHomeDeliveryBoxPreparation() {
    $national_shipping_profile = Profile::create([
      'type' => 'customer',
      'address' => $this->nationalAddress,
    ]);
    $national_shipping_profile->save();

    $international_shipping_profile = Profile::create([
      'type' => 'customer',
      'address' => $this->internationalAddress,
    ]);
    $international_shipping_profile->save();

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
    $order = Order::create([
      'type' => 'default',
      'state' => 'draft',
      'mail' => $this->user->getEmail(),
      'uid' => $this->user->id(),
      'store_id' => $this->store->id(),
      'order_items' => [$order_item],
    ]);
    $order->save();

    $shipping_method = ShippingMethod::load(1);
    /** @var \Drupal\commerce_shipping\Entity\ShipmentInterface $shipment */
    $shipment = Shipment::create([
      'type' => 'default',
      'order_id' => $order->id(),
      'shipping_method' => $shipping_method,
      'title' => 'Shipment',
      'amount' => $shipping_price,
      'items' => [
        new ShipmentItem([
          'order_item_id' => 10,
          'title' => 'Book',
          'quantity' => 1,
          'weight' => new Weight('10', 'g'),
          'declared_value' => $product_price,
        ]),
      ],
    ]);

    $shipment->save();

    /** @var \Drupal\commerce_bpost\Plugin\BpostService\HomeDelivery $home_delivery */
    $home_delivery = $shipping_method->getPlugin()->instantiateServicePlugin('home_delivery');
    $shipment->setShippingProfile($national_shipping_profile);
    $box = $home_delivery->prepareDeliveryBox($shipment);
    $this->assertInstanceOf(Box::class, $box);
    $destination = $box->getNationalBox();
    $this->assertInstanceOf(Box\AtHome::class, $destination);
    $this->assertEquals('10', $destination->getWeight());
    $this->assertEquals(Product::PRODUCT_NAME_BPACK_24H_PRO, $destination->getProduct());

    /** @var Receiver $receiver */
    $receiver = $destination->getReceiver();
    $this->assertInstanceOf(Receiver::class, $receiver);
    $address = $receiver->getAddress();
    $this->assertInstanceOf(Address::class, $address);
    $this->assertAddress($address, $this->nationalAddress);
    $this->assertEquals($this->name['given_name'] . ' ' . $this->name['family_name'], $receiver->getName());
    $this->assertEquals($this->user->getEmail(), $receiver->getEmailAddress());
    $this->assertEmpty($receiver->getCompany());
    $this->assertEmpty($receiver->getPhoneNumber());
    $this->nationalAddress['organization'] = 'My company';
    $national_shipping_profile->set('address', $this->nationalAddress);
    $national_shipping_profile->set('phone_number', 5555);
    $national_shipping_profile->save();
    $box = $home_delivery->prepareDeliveryBox($shipment);
    $receiver = $box->getNationalBox()->getReceiver();
    $this->assertEquals('My company', $receiver->getCompany());
    $this->assertEquals(5555, $receiver->getPhoneNumber());
    $this->assertAddress($receiver->getAddress(), $this->nationalAddress);

    // Test the international profile.
    $shipment->setShippingProfile($international_shipping_profile);
    $shipment->save();
    $box = $home_delivery->prepareDeliveryBox($shipment);
    $this->assertInstanceOf(Box::class, $box);
    /** @var \Bpost\BpostApiClient\Bpost\Order\Box\International $destination */
    $destination = $box->getInternationalBox();
    $this->assertInstanceOf(Box\International::class, $destination);
    $this->assertEquals('10', $destination->getParcelWeight());
    $this->assertEquals(Product::PRODUCT_NAME_BPACK_WORLD_EXPRESS_PRO, $destination->getProduct());
    $customs_info = $destination->getCustomsInfo();
    $expected_value = (float) $order->getSubtotalPrice()->getNumber();
    $this->assertEquals((int) $expected_value * 100, $customs_info->getParcelValue());
    $this->assertEquals('Books', $customs_info->getContentDescription());
    $this->assertEquals(Box\CustomsInfo\CustomsInfo::CUSTOM_INFO_SHIPMENT_TYPE_GOODS, $customs_info->getShipmentType());
    $this->assertEquals(Box\CustomsInfo\CustomsInfo::CUSTOM_INFO_PARCEL_RETURN_INSTRUCTION_RTS, $customs_info->getParcelReturnInstructions());
    $this->assertTrue($customs_info->getPrivateAddress());
    $receiver = $destination->getReceiver();
    $this->assertInstanceOf(Receiver::class, $receiver);
    $address = $receiver->getAddress();
    $this->assertInstanceOf(Address::class, $address);
    $this->assertAddress($address, $this->internationalAddress);
  }

  /**
   * Asserts that an API address object contains the correct values.
   *
   * @param \Bpost\BpostApiClient\Bpost\Order\Address $address
   * @param array $expected
   */
  protected function assertAddress(Address $address, array $expected) {
    $this->assertEquals($expected['country_code'], $address->getCountryCode());
    $this->assertEquals($expected['locality'], $address->getLocality());
    $this->assertEquals($expected['address_line1'], $address->getStreetName());
    $this->assertEquals($expected['postal_code'], $address->getPostalCode());
  }
}