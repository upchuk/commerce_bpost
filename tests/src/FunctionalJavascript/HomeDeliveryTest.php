<?php

namespace Drupal\Tests\commerce_bpost\FunctionalJavascript;

use Drupal\commerce_shipping\Entity\ShipmentInterface;
use Drupal\physical\Calculator;
use Drupal\profile\Entity\Profile;

/**
 * Tests the home delivery capability.
 */
class HomeDeliveryTest extends BpostWebDriverTestBase {

  /**
   * Tests a default flow for the home delivery plugin.
   */
  public function testDefaultHomeDeliveryFlow() {
    $product = $this->createProduct(10, [], [], 'default', 'Title that contains chars which are not allowed: & < >');

    $user = $this->createUser();
    $this->drupalLogin($user);

    $this->drupalGet($product->toUrl());
    $this->getSession()->getPage()->pressButton('Add to cart');
    $this->getSession()->getPage()->clickLink('your cart');
    $this->getSession()->getPage()->pressButton('Checkout');

    // Assert only the countries enabled in the store are available
    // to be chosen.
    $this->assertEquals([
      'BE' => 'Belgium',
      'FR' => 'France',
      'IT' => "Italy",
    ], $this->getSelectOptions($this->getSession()->getPage()->findField('Country')));

    // Set a shipping address in Belgium.
    $this->getSession()->getPage()->fillField('Company', 'WEBOMELETTE');
    $this->getSession()->getPage()->fillField('First name', 'Danny');
    $this->getSession()->getPage()->fillField('Last name', 'S');
    $this->getSession()->getPage()->fillField('Street address', 'One street');
    $this->getSession()->getPage()->fillField('Postal code', '1000');
    $this->getSession()->getPage()->fillField('City', 'Brussels');

    $this->getSession()->getPage()->pressButton('Continue to review');

    $this->assertSession()->pageTextContains('BPost Shipping');
    $this->assertSession()->pageTextContains('WEBOMELETTE');
    $this->assertSession()->pageTextContains('Danny S');
    $this->assertSession()->pageTextContains('One street');
    $this->assertSession()->pageTextContains('1000 Brussels');
    $this->assertSession()->elementContains('css', '#edit-review-bpost-shipping', 'Home delivery');

    $orders = \Drupal::entityTypeManager()->getStorage('commerce_order')->loadByProperties(['mail' => $user->getEmail()]);
    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = reset($orders);
    $billing_profile = $order->getBillingProfile();
    /** @var \Drupal\commerce_shipping\Entity\ShipmentInterface $shipment */
    $shipment = $order->get('shipments')->entity;
    $this->assertInstanceOf(ShipmentInterface::class, $shipment);
    $shipping_profile = $shipment->getShippingProfile();
    $this->assertEquals('customer', $shipping_profile->bundle());

    // Assert the shipping and billing profiles have the same address because
    // by default the billing profile is copied over from the shipping one.
    $this->assertEquals($shipping_profile->get('address')->first()->getValue(), $billing_profile->get('address')->first()->getValue());

    // Assert the shipment values are created correctly.
    $this->assertEquals('custom_box', $shipment->getPackageType()->getId());
    $this->assertEquals('BPost', $shipment->getShippingMethod()->label());
    $this->assertEquals('home_delivery', $shipment->getShippingService());
    $this->assertEquals($shipping_profile->id(), $shipment->getShippingProfile()->id());
    $this->assertEquals(10, Calculator::trim($shipment->getWeight()->getNumber()));
    $this->assertEquals(10, Calculator::trim($shipment->getAmount()->getNumber()));

    // Go back and change the shipping address, while at the same time have a
    // different billing address.
    $this->getSession()->getPage()->clickLink('Go back');
    $this->getSession()->getPage()->pressButton('Edit');
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->getSession()->getPage()->fillField('Street address', 'Two street');
    $this->getSession()->getPage()->uncheckField('My billing information is the same as my shipping information.');
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->getSession()->getPage()->pressButton('Edit');
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->getSession()->getPage()->fillField('billing_information[profile][address][0][address][organization]', 'Billing company name');
    $this->getSession()->getPage()->fillField('billing_information[profile][address][0][address][address_line1]', 'Billing address street');
    $this->getSession()->getPage()->pressButton('Continue to review');
    $this->assertSession()->pageTextContains('Two street');
    $this->assertSession()->pageTextContains('Billing company name');
    $this->assertSession()->pageTextContains('Billing address street');
    \Drupal::entityTypeManager()->getStorage('profile')->resetCache();
    $billing_profile = Profile::load($billing_profile->id());
    $billing_address = $billing_profile->get('address')->first()->getValue();
    $this->assertEquals('Billing company name', $billing_address['organization']);
    $this->assertEquals('Billing address street', $billing_address['address_line1']);
    $shipping_profile = Profile::load($shipping_profile->id());
    $shipping_address = $shipping_profile->get('address')->first()->getValue();
    $this->assertEquals('Two street', $shipping_address['address_line1']);

    // Change the shipping country and assert the shipping price changes.
    $this->getSession()->getPage()->clickLink('Go back');
    $this->getSession()->getPage()->pressButton('Edit');
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->getSession()->getPage()->selectFieldOption('Country', 'France');
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->getSession()->getPage()->fillField('Street address', 'One street');
    $this->getSession()->getPage()->fillField('Postal code', 10000);
    $this->getSession()->getPage()->fillField('City', 'Paris');
    $this->getSession()->getPage()->pressButton('Continue to review');
    \Drupal::entityTypeManager()->getStorage('commerce_shipment')->resetCache();
    $shipment = \Drupal::entityTypeManager()->getStorage('commerce_shipment')->load($shipment->id());
    $this->assertEquals(10, Calculator::trim($shipment->getWeight()->getNumber()));
    // The price has updated because the shipment delivers to France now.
    $this->assertEquals(15, Calculator::trim($shipment->getAmount()->getNumber()));

    // Place the order to trigger the subscribers.
    $order->getState()->applyTransitionById('place');
    $order->save();

    /** @var \Bpost\BpostApiClient\Bpost\Order $bpost_order */
    $bpost_order = \Drupal::state()->get('commerce_bpost_client_test.last_order');
    // The box contents are tested in Kernel tests, so we just need to check
    // that the event subscriber works.
    $box = $bpost_order->getBoxes()[0];
    $destination = $box->getNationalBox() ? $box->getNationalBox() : $box->getInternationalBox();
    $address = $destination->getReceiver()->getAddress();
    $this->assertEquals(50, $address->getNumber());

    // Assert the product titles don't contain invalid characters (& or <>).
    $line_item_title = $bpost_order->getLines()[0]->getText();
    $this->assertEquals('Title that contains chars which are not allowed:', trim($line_item_title));
  }

  /**
   * Tests that the shipment price is calculated based on weight and location.
   *
   * @param string $weight
   *   The product weight.
   * @param string $country
   *   The shipping country.
   * @param int $price
   *   The expected price.
   * @param string $unit
   *   The weight unit.
   *
   * @dataProvider providerShippingPrices
   */
  public function testHomeDeliveryShippingPrice($weight, $country, $price, $unit = 'g') {
    $product = $this->createProduct(10, [], [
      'number' => $weight,
      'unit' => $unit,
    ]);

    $user = $this->createUser();
    $this->drupalLogin($user);

    $this->drupalGet($product->toUrl());
    $this->getSession()->getPage()->pressButton('Add to cart');
    $this->getSession()->getPage()->clickLink('your cart');
    $this->getSession()->getPage()->pressButton('Checkout');

    $postal_codes = [
      'Belgium' => 1000,
      'France' => 10000,
      'Italy' => 20010,
    ];
    $this->getSession()->getPage()->selectFieldOption('Country', $country);
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->getSession()->getPage()->fillField('First name', 'Danny');
    $this->getSession()->getPage()->fillField('Last name', 'S');
    $this->getSession()->getPage()->fillField('Street address', 'One street');
    $this->getSession()->getPage()->fillField('Postal code', $postal_codes[$country]);
    $this->getSession()->getPage()->fillField('City', 'Fake city');
    if ($country == 'Italy') {
      $this->getSession()->getPage()->selectFieldOption('Province', 'Milano');
    }
    $this->getSession()->getPage()->pressButton('Continue to review');

    $orders = \Drupal::entityTypeManager()->getStorage('commerce_order')->loadByProperties(['mail' => $user->getEmail()]);
    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = reset($orders);
    /** @var \Drupal\commerce_shipping\Entity\ShipmentInterface $shipment */
    $shipment = $order->get('shipments')->entity;
    $this->assertInstanceOf(ShipmentInterface::class, $shipment);
    $this->assertEquals($price, Calculator::trim($shipment->getAmount()->getNumber()));
  }

  /**
   * Data provider for testHomeDeliveryShippingPrice().
   *
   * @return array
   *   The test cases.
   */
  public function providerShippingPrices() {
    return [
      [1000, 'Belgium', 10],
      [3000, 'Belgium', 20],
      // Different weight unit.
      [3, 'Belgium', 20, 'kg'],
      // Default rate.
      [50000, 'Belgium', 25],
      [100, 'France', 15],
      [400, 'France', 25],
      // Default rate.
      [4000, 'France', 12],
     [400, 'Italy', 45],
      // Default rate.
     [4000, 'Italy', 40],
    ];
  }

}
