<?php

namespace Drupal\Tests\commerce_bpost_pickup\Kernel;

use Bpost\BpostApiClient\Bpost\Order\Box;
use Bpost\BpostApiClient\Bpost\Order\Box\Option\Messaging;
use Bpost\BpostApiClient\Bpost\ProductConfiguration\Product;
use Drupal\commerce_bpost\Exception\BpostCheckoutException;
use Drupal\profile\Entity\Profile;
use Drupal\Tests\commerce_bpost\Kernel\BpostKernelTestBase;

/**
 * Tests the BPost pickup point service.
 */
class PickupPointServiceTest extends BpostKernelTestBase {

  /**
   * {@inheritdoc}
   */
  public static $modules = [
    'commerce_bpost_pickup',
    'commerce_bpost_pickup_test',
    'leaflet',
    'geofield',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installConfig(['commerce_bpost_pickup']);
    module_load_install('commerce_bpost_pickup_test');
    commerce_bpost_pickup_test_install();
  }

  /**
   * Tests that an API "box" can be prepared using the Pickup point service.
   */
  public function testPickupPointBoxPreparation() {
    /** @var \Drupal\profile\Entity\ProfileInterface $shipping_profile */
    $shipping_profile = Profile::create([
      'type' => 'bpost_pickup_point',
      // No point details.
      'point_id' => 0,
      'point_type' => 1,
      'postal_code' => 1000,
      'uid' => $this->user->id()
    ]);
    $shipping_profile->save();

    // Create a billing profile and add it to the order.
    /** @var \Drupal\profile\Entity\ProfileInterface $billing_profile */
    $billing_profile = Profile::create([
      'type' => 'customer',
      'address' => $this->nationalAddress + ['organization' => 'Billing organization'],
      'phone_number' => '0000',
    ]);
    $billing_profile->save();
    $this->order->setBillingProfile($billing_profile);
    $this->order->save();

    /** @var \Drupal\commerce_bpost_pickup\Plugin\BpostService\PickupPoint $pickup_point */
    $pickup_point = $this->shipping_method->getPlugin()->instantiateServicePlugin('pickup_point');
    $this->shipment->setShippingProfile($shipping_profile);
    $exception = NULL;
    try {
      $box = $pickup_point->prepareDeliveryBox($this->shipment);
    }
    catch (BpostCheckoutException $exception) {
      // Do nothing.
    }
    // The point was wrong.
    $this->assertInstanceOf(BpostCheckoutException::class, $exception);
    $this->assertEquals($this->order->id(), $exception->getOrder()->id());

    // Update the point to use a Bpost post office one (type 1).
    $shipping_profile->set('point_id', 20100);
    $shipping_profile->set('point_type', 1);
    $shipping_profile->save();
    $this->shipment->setShippingProfile($shipping_profile);

    $box = $pickup_point->prepareDeliveryBox($this->shipment);
    $this->assertInstanceOf(Box::class, $box);
    /** @var \Bpost\BpostApiClient\Bpost\Order\Box\AtBpost $destination */
    $destination = $box->getNationalBox();
    $this->assertInstanceOf(Box\AtBpost::class, $destination);
    $this->assertEquals(Product::PRODUCT_NAME_BPACK_AT_BPOST, $destination->getProduct());
    $this->assertEquals('10', $destination->getWeight());
    $this->assertEquals('20100', $destination->getPugoId());
    $this->assertEquals('BRUXELLES DE BROUCKERE', $destination->getPugoName());
    $this->assertEquals('Dan Smith', $destination->getReceiverName());
    $this->assertEquals('Billing organization', $destination->getReceiverCompany());
    $address = $destination->getPugoAddress();
    $this->assertAddress($address, [
      'address_line1' => 'BOULEVARD ANSPACH',
      'locality' => 'BRUXELLES',
      'postal_code' => '1000',
      'country_code' => 'BE',
    ]);
    $this->assertEquals(1, $address->getNumber());

    $options = $destination->getOptions();
    $this->assertCount(1, $options);
    $option = reset($options);
    $this->assertInstanceOf(Box\Option\Messaging::class, $option);
    $this->assertEquals(Messaging::MESSAGING_TYPE_KEEP_ME_INFORMED, $option->getType());
    $this->assertEquals('EN', $option->getLanguage());
    $this->assertEquals($this->order->getCustomer()->getEmail(), $option->getEmailAddress());

    // Switch to a parcel distributor.
    $shipping_profile->set('point_id', 18448);
    $shipping_profile->set('point_type', 4);
    $shipping_profile->save();
    $this->shipment->setShippingProfile($shipping_profile);
    $box = $pickup_point->prepareDeliveryBox($this->shipment);
    /** @var \Bpost\BpostApiClient\Bpost\Order\Box\At247 $destination */
    $destination = $box->getNationalBox();
    $this->assertInstanceOf(Box\At247::class, $destination);
    $this->assertEquals(Product::PRODUCT_NAME_BPACK_24_7, $destination->getProduct());
    $this->assertEquals('10', $destination->getWeight());
    $this->assertEquals('18448', $destination->getParcelsDepotId());
    $this->assertEquals('DISTRIBUTEUR BPOST MCM', $destination->getParcelsDepotName());
    $address = $destination->getParcelsDepotAddress();
    $this->assertAddress($address, [
      'address_line1' => 'RUE DE L\'EVÊQUE',
      'locality' => 'BRUXELLES',
      'postal_code' => '1000',
      'country_code' => 'BE',
    ]);
    $this->assertEquals(26, $address->getNumber());
    $unregistered = $destination->getUnregistered();
    $this->assertEquals($this->order->getCustomer()->getEmail(), $unregistered->getEmailAddress());
    $this->assertEquals('EN', $unregistered->getLanguage());
    $this->assertEquals('0000', $unregistered->getMobilePhone());
    $this->assertEquals('Dan Smith', $destination->getReceiverName());
    $this->assertEquals('Billing organization', $destination->getReceiverCompany());
  }

}