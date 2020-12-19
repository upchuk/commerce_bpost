<?php

namespace Drupal\Tests\commerce_bpost\Kernel;

use Bpost\BpostApiClient\Bpost\Order\Address;
use Bpost\BpostApiClient\Bpost\Order\Box;
use Bpost\BpostApiClient\Bpost\Order\Box\AtHome;
use Bpost\BpostApiClient\Bpost\Order\Box\CustomsInfo\CustomsInfo;
use Bpost\BpostApiClient\Bpost\Order\Box\International;
use Bpost\BpostApiClient\Bpost\Order\Receiver;
use Bpost\BpostApiClient\Bpost\ProductConfiguration\Product;
use Drupal\profile\Entity\Profile;

/**
 * Tests the BPost services.
 *
 * @package Drupal\Tests\commerce_bpost\Kernel
 */
class HomeDeliveryServiceTest extends BpostKernelTestBase {

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

    /** @var \Drupal\commerce_bpost\Plugin\BpostService\HomeDelivery $home_delivery */
    $home_delivery = $this->shippingMethod->getPlugin()->instantiateServicePlugin('home_delivery');
    $this->shipment->setShippingProfile($national_shipping_profile);
    $box = $home_delivery->prepareDeliveryBox($this->shipment);
    $this->assertInstanceOf(Box::class, $box);
    $destination = $box->getNationalBox();
    $this->assertInstanceOf(AtHome::class, $destination);
    $this->assertEquals('10', $destination->getWeight());
    $this->assertEquals(Product::PRODUCT_NAME_BPACK_24H_PRO, $destination->getProduct());

    /** @var \Bpost\BpostApiClient\Bpost\Order\Receiver $receiver */
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
    $box = $home_delivery->prepareDeliveryBox($this->shipment);
    $receiver = $box->getNationalBox()->getReceiver();
    $this->assertEquals('My company', $receiver->getCompany());
    $this->assertEquals(5555, $receiver->getPhoneNumber());
    $this->assertAddress($receiver->getAddress(), $this->nationalAddress);

    // Test the international profile.
    $this->shipment->setShippingProfile($international_shipping_profile);
    $this->shipment->save();
    $box = $home_delivery->prepareDeliveryBox($this->shipment);
    $this->assertInstanceOf(Box::class, $box);
    /** @var \Bpost\BpostApiClient\Bpost\Order\Box\International $destination */
    $destination = $box->getInternationalBox();
    $this->assertInstanceOf(International::class, $destination);
    $this->assertEquals('10', $destination->getParcelWeight());
    $this->assertEquals(Product::PRODUCT_NAME_BPACK_WORLD_EXPRESS_PRO, $destination->getProduct());
    $customs_info = $destination->getCustomsInfo();
    $expected_value = (float) $this->order->getSubtotalPrice()->getNumber();
    $this->assertEquals((int) $expected_value * 100, $customs_info->getParcelValue());
    $this->assertEquals('Books', $customs_info->getContentDescription());
    $this->assertEquals(CustomsInfo::CUSTOM_INFO_SHIPMENT_TYPE_GOODS, $customs_info->getShipmentType());
    $this->assertEquals(CustomsInfo::CUSTOM_INFO_PARCEL_RETURN_INSTRUCTION_RTS, $customs_info->getParcelReturnInstructions());
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
