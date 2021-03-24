<?php

namespace Drupal\Tests\commerce_bpost_pickup\FunctionalJavascript;

use Drupal\commerce_shipping\Entity\ShipmentInterface;
use Drupal\physical\Calculator;
use Drupal\profile\Entity\Profile;
use Drupal\Tests\commerce_bpost\FunctionalJavascript\BpostWebDriverTestBase;

/**
 * Tests the pickup capability.
 */
class PickupPointTest extends BpostWebDriverTestBase {

  /**
   * {@inheritdoc}
   */
  public static $modules = [
    'commerce_bpost_pickup',
    'commerce_bpost_pickup_test',
  ];

  /**
   * Tests a default flow for the pickup plugin.
   */
  public function testDefaultPickupPoint() {
    $product = $this->createProduct(10);

    $user = $this->createUser();
    $this->drupalLogin($user);

    $this->drupalGet($product->toUrl());
    $this->getSession()->getPage()->pressButton('Add to cart');
    $this->getSession()->getPage()->clickLink('your cart');
    $this->getSession()->getPage()->pressButton('Checkout');

    $this->assertEquals([
      'home_delivery' => 'Home delivery',
      'pickup_point' => 'Pickup point',
      '' => 'Select',
    ], $this->getSelectOptions($this->getSession()->getPage()->findField('Please select your delivery choice')));
    $this->getSession()->getPage()->selectFieldOption('Please select your delivery choice', 'Pickup point');
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->getSession()->getPage()->fillField('bpost_shipping[search_wrapper][postal_code]', 1000);
    $this->getSession()->getPage()->pressButton('Search');
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->assertSession()->pageTextNotContains('Your selection');

    // Click on the list.
    $this->clickLink('BRUXELLES DE BROUCKERE');
    $this->assertCurrentSelection('BRUXELLES DE BROUCKERE', 'BOULEVARD ANSPACH 1, 1000 BRUXELLES');
    $this->clickLink('IXELLES PORTE DE NAMUR');
    $this->assertCurrentSelection('IXELLES PORTE DE NAMUR', 'CHAUSSÉE D\'IXELLES 27, 1050 IXELLES');

    // Click in the map, but zoom out first.
    $this->getSession()->getPage()->find('css', '.leaflet-control-zoom-out')->click();
    $this->getSession()->getPage()->find('css', '.leaflet-control-zoom-out')->click();
    $this->clickMapPin(634473);
    $this->assertCurrentSelection('NIGHT AND DAY 71 BOURSE', 'RUE AUGUSTE ORTS 8-12, 1000 BRUXELLES');

    // Fill in a billing profile.
    $this->getSession()->getPage()->fillField('billing_information[profile][address][0][address][given_name]', 'Danny');
    $this->getSession()->getPage()->fillField('billing_information[profile][address][0][address][family_name]', 'S');
    $this->getSession()->getPage()->fillField('billing_information[profile][address][0][address][address_line1]', 'Billing address street');
    $this->getSession()->getPage()->fillField('billing_information[profile][address][0][address][postal_code]', '1000');
    $this->getSession()->getPage()->fillField('billing_information[profile][address][0][address][locality]', 'Brussels');
    $this->getSession()->getPage()->pressButton('Continue to review');
    $this->assertSession()->pageTextContains('Shipping to pickup point: NIGHT AND DAY 71 BOURSE.');
    $this->assertSession()->pageTextContains('Billing address street');
    $this->assertSession()->pageTextContains('Brussels');
    $this->assertSession()->pageTextContains('Belgium');

    // Assert the generated profiles.
    $orders = \Drupal::entityTypeManager()->getStorage('commerce_order')->loadByProperties(['mail' => $user->getEmail()]);
    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = reset($orders);
    $billing_profile = $order->getBillingProfile();
    /** @var \Drupal\commerce_shipping\Entity\ShipmentInterface $shipment */
    $shipment = $order->get('shipments')->entity;
    $this->assertInstanceOf(ShipmentInterface::class, $shipment);
    $shipping_profile = $shipment->getShippingProfile();
    $this->assertEquals('bpost_pickup_point', $shipping_profile->bundle());
    $this->assertEquals('634473', $shipping_profile->get('point_id')->value);
    $this->assertEquals('2', $shipping_profile->get('point_type')->value);
    // The shipping profile address is empty.
    $this->assertTrue($shipping_profile->get('address')->isEmpty());

    $billing_address = $billing_profile->get('address')->first()->getValue();
    $this->assertEquals('Billing address street', $billing_address['address_line1']);
    $this->assertEquals('Danny', $billing_address['given_name']);
    $this->assertEquals('S', $billing_address['family_name']);
    $this->assertEquals('1000', $billing_address['postal_code']);
    $this->assertEquals('Brussels', $billing_address['locality']);

    // Assert the shipment values are created correctly.
    $this->assertEquals('custom_box', $shipment->getPackageType()->getId());
    $this->assertEquals('BPost', $shipment->getShippingMethod()->label());
    $this->assertEquals('pickup_point', $shipment->getShippingService());
    $this->assertEquals($shipping_profile->id(), $shipment->getShippingProfile()->id());
    $this->assertEquals(10, Calculator::trim($shipment->getWeight()->getNumber()));
    $this->assertEquals(14, Calculator::trim($shipment->getAmount()->getNumber()));

    // Go back and change the pickup point.
    $this->getSession()->getPage()->clickLink('Go back');

    // Assert the form is pre-populated from the current profile.
    $this->assertTrue($this->assertSession()->optionExists('Please select your delivery choice', 'Pickup point')->isSelected());
    $this->assertSession()->fieldValueEquals('bpost_shipping[search_wrapper][postal_code]', '1000');
    $this->assertCurrentSelection('NIGHT AND DAY 71 BOURSE', 'RUE AUGUSTE ORTS 8-12, 1000 BRUXELLES');

    // Change the pickup point from the existing ones.
    $this->getSession()->getPage()->clickLink('BRUXELLES DE BROUCKERE');
    $this->assertCurrentSelection('BRUXELLES DE BROUCKERE', 'BOULEVARD ANSPACH 1, 1000 BRUXELLES');
    $this->getSession()->getPage()->pressButton('Continue to review');
    $this->assertSession()->pageTextContains('Shipping to pickup point: BRUXELLES DE BROUCKERE.');
    \Drupal::entityTypeManager()->getStorage('profile')->resetCache();
    /** @var \Drupal\profile\Entity\ProfileInterface $shipping_profile */
    $shipping_profile = Profile::load($shipping_profile->id());
    $this->assertTrue($shipping_profile->get('address')->isEmpty());
    $this->assertEquals('20100', $shipping_profile->get('point_id')->value);
    $this->assertEquals('1', $shipping_profile->get('point_type')->value);

    // Go back and change the pick up point from a different post code.
    $this->getSession()->getPage()->clickLink('Go back');
    $this->assertCurrentSelection('BRUXELLES DE BROUCKERE', 'BOULEVARD ANSPACH 1, 1000 BRUXELLES');
    $this->getSession()->getPage()->fillField('bpost_shipping[search_wrapper][postal_code]', 1050);
    $this->getSession()->getPage()->pressButton('Search');
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->getSession()->getPage()->clickLink('QUARTIER GENERAL IXELLES');
    $this->assertCurrentSelection('QUARTIER GENERAL IXELLES', 'BOULEVARD GÉNÉRAL JACQUES 117, 1050 IXELLES');

    $this->getSession()->getPage()->pressButton('Continue to review');
    $this->assertSession()->pageTextContains('Shipping to pickup point: QUARTIER GENERAL IXELLES.');
    \Drupal::entityTypeManager()->getStorage('profile')->resetCache();
    /** @var \Drupal\profile\Entity\ProfileInterface $shipping_profile */
    $shipping_profile = Profile::load($shipping_profile->id());
    $this->assertTrue($shipping_profile->get('address')->isEmpty());
    $this->assertEquals('825010', $shipping_profile->get('point_id')->value);
    $this->assertEquals('2', $shipping_profile->get('point_type')->value);

    // Go back and switch to Home Delivery.
    $this->getSession()->getPage()->clickLink('Go back');
    $this->getSession()->getPage()->selectFieldOption('Please select your delivery choice', 'Home delivery');
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->getSession()->getPage()->fillField('First name', 'Danny');
    $this->getSession()->getPage()->fillField('Last name', 'S');
    $this->getSession()->getPage()->fillField('Street address', 'One street');
    $this->getSession()->getPage()->fillField('Postal code', '1000');
    $this->getSession()->getPage()->fillField('City', 'Brussels');
    $this->getSession()->getPage()->pressButton('Continue to review');

    $this->assertSession()->elementContains('css', '#edit-review-bpost-shipping', 'Home delivery');
    \Drupal::entityTypeManager()->getStorage('commerce_order')->resetCache();
    \Drupal::entityTypeManager()->getStorage('profile')->resetCache();
    \Drupal::entityTypeManager()->getStorage('commerce_shipment')->resetCache();

    // The bpost pickup profile was deleted and replaced with a customer one
    // for home delivery.
    $this->assertNull(\Drupal::entityTypeManager()->getStorage('profile')->load($shipping_profile->id()));
    $order = \Drupal::entityTypeManager()->getStorage('commerce_order')->load($order->id());
    /** @var \Drupal\commerce_shipping\Entity\ShipmentInterface $shipment */
    $shipment = $order->get('shipments')->entity;
    $shipping_profile = $shipment->getShippingProfile();
    $this->assertEquals('customer', $shipping_profile->bundle());
    $this->assertEquals('home_delivery', $shipment->getShippingService());
    $this->assertEquals($shipping_profile->id(), $shipment->getShippingProfile()->id());
    $this->assertEquals(10, Calculator::trim($shipment->getWeight()->getNumber()));
    $this->assertEquals(10, Calculator::trim($shipment->getAmount()->getNumber()));
  }

  /**
   * Tests that the shipment price is calculated based on weight and location.
   *
   * @param int $weight
   *   The product weight.
   * @param int $price
   *   The expected price.
   *
   * @dataProvider providerShippingPrices
   */
  public function testPickupPointShippingPrice(int $weight, int $price) {
    $product = $this->createProduct(10, [], [
      'number' => $weight,
      'unit' => 'g',
    ]);

    $user = $this->createUser();
    $this->drupalLogin($user);

    $this->drupalGet($product->toUrl());
    $this->getSession()->getPage()->pressButton('Add to cart');
    $this->getSession()->getPage()->clickLink('your cart');
    $this->getSession()->getPage()->pressButton('Checkout');

    $this->getSession()->getPage()->selectFieldOption('Please select your delivery choice', 'Pickup point');
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->getSession()->getPage()->fillField('bpost_shipping[search_wrapper][postal_code]', 1000);
    $this->getSession()->getPage()->pressButton('Search');
    $this->assertSession()->assertWaitOnAjaxRequest();

    // Select a pickup point.
    $this->clickLink('BRUXELLES DE BROUCKERE');

    // Fill in a billing profile.
    $this->getSession()->getPage()->fillField('billing_information[profile][address][0][address][given_name]', 'Danny');
    $this->getSession()->getPage()->fillField('billing_information[profile][address][0][address][family_name]', 'S');
    $this->getSession()->getPage()->fillField('billing_information[profile][address][0][address][address_line1]', 'Billing address street');
    $this->getSession()->getPage()->fillField('billing_information[profile][address][0][address][postal_code]', '1000');
    $this->getSession()->getPage()->fillField('billing_information[profile][address][0][address][locality]', 'Brussels');

    $this->getSession()->getPage()->pressButton('Continue to review');
    // Assert the generated profiles.
    $orders = \Drupal::entityTypeManager()->getStorage('commerce_order')->loadByProperties(['mail' => $user->getEmail()]);
    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = reset($orders);
    /** @var \Drupal\commerce_shipping\Entity\ShipmentInterface $shipment */
    $shipment = $order->get('shipments')->entity;
    $this->assertInstanceOf(ShipmentInterface::class, $shipment);
    $this->assertEquals($price, Calculator::trim($shipment->getAmount()->getNumber()));
  }

  /**
   * Data provider for testPickupPointShippingPrice().
   *
   * @return array
   *   The data.
   */
  public function providerShippingPrices() {
    return [
      [1000, 14],
      [3000, 19],
      // Default rate.
      [6000, 21],
    ];
  }

  /**
   * Asserts the current Bpost pickup point selection.
   *
   * @param string $name
   *   The name of the point.
   * @param string $address
   *   The displayed address.
   */
  protected function assertCurrentSelection(string $name, string $address) {
    $this->assertSession()->elementContains('css', '.selected-pickup-point', 'Your selection');
    $this->assertSession()->elementContains('css', '.selected-pickup-point', $name);
    $this->assertSession()->elementContains('css', '.selected-pickup-point', $address);
  }

  /**
   * Clicks a pin on the map by the point ID.
   *
   * @param int $id
   *   The point ID.
   *
   * @return string
   *   The result.
   */
  protected function clickMapPin(int $id) {
    $js = sprintf(
      'jQuery(\'i[data-poi-id="%s"]\').parent().click()',
      $id
    );

    return $this->getSession()->evaluateScript($js);
  }

}
