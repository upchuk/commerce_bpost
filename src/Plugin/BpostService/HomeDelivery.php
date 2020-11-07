<?php

namespace Drupal\commerce_bpost\Plugin\BpostService;

use Bpost\BpostApiClient\Bpost\Order\Address;
use Bpost\BpostApiClient\Bpost\Order\Box;
use Bpost\BpostApiClient\Bpost\Order\Box\AtHome;
use Bpost\BpostApiClient\Bpost\Order\Box\CustomsInfo\CustomsInfo;
use Bpost\BpostApiClient\Bpost\Order\Box\International;
use Bpost\BpostApiClient\Bpost\Order\Receiver;
use Bpost\BpostApiClient\Bpost\ProductConfiguration\Product;
use CommerceGuys\Addressing\Country\CountryRepositoryInterface;
use Drupal\commerce\InlineFormManager;
use Drupal\commerce_bpost\BpostServicePluginBase;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_shipping\Entity\ShipmentInterface;
use Drupal\commerce_shipping\OrderShipmentSummaryInterface;
use Drupal\commerce_shipping\PackerManagerInterface;
use Drupal\commerce_shipping\ShipmentManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the bpost_service.
 *
 * @BpostService(
 *   id = "home_delivery",
 *   label = @Translation("Home delivery"),
 *   description = @Translation("Home delivery.")
 * )
 */
class HomeDelivery extends BpostServicePluginBase {

  /**
   * @var \Drupal\commerce\InlineFormManager
   */
  protected $inlineFormManager;

  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entityTypeManager, ShipmentManagerInterface $shipmentManager, OrderShipmentSummaryInterface $shipmentSummary, PackerManagerInterface $packerManager, InlineFormManager $inlineFormManager, CountryRepositoryInterface $countryRepository) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $entityTypeManager, $shipmentManager, $shipmentSummary, $packerManager, $countryRepository);
    $this->inlineFormManager = $inlineFormManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('commerce_shipping.shipment_manager'),
      $container->get('commerce_shipping.order_shipment_summary'),
      $container->get('commerce_shipping.packer_manager'),
      $container->get('plugin.manager.commerce_inline_form'),
      $container->get('address.country_repository')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getBpostProduct(ShipmentInterface $shipment) {
    $shipping_profile = $shipment->getShippingProfile();
    if ($shipping_profile->get('address')->isEmpty()) {
      return NULL;
    }

    $values = $shipping_profile->get('address')->first()->getValue();
    // It's either national or international shipping.
    return $values['country_code'] === 'BE' ? Product::PRODUCT_NAME_BPACK_24H_PRO : Product::PRODUCT_NAME_BPACK_WORLD_EXPRESS_PRO;
  }

  /**
   * {@inheritdoc}
   */
  public function prepareDeliveryBox(ShipmentInterface $shipment) {
    $shipping_profile = $shipment->getShippingProfile();

    $values = $shipping_profile->get('address')->first()->getValue();

    $address = new Address();
    $street = $values['address_line1'];
    if (isset($values['address_line2']) && $values['address_line2']) {
      $street .= ', ' . $values['address_line2'];
    }
    $address->setStreetName($street);
    $address->setPostalCode($values['postal_code']);
    $address->setLocality($values['locality']);
    $address->setCountryCode($values['country_code']);

    $receiver = new Receiver();
    if (isset($values['organization']) && $values['organization']) {
      $receiver->setCompany($values['organization']);
    }
    $receiver->setAddress($address);
    $receiver->setName($values['given_name'] . ' ' . $values['family_name']);
    if ($shipping_profile->hasField('phone_number') && !$shipping_profile->get('phone_number')->isEmpty()) {
      $receiver->setPhoneNumber($shipping_profile->get('phone_number')->value);
    }
    $receiver->setEmailAddress($shipment->getOrder()->getCustomer()->getEmail());

    $box = new Box();

    if ($receiver->getAddress()->getCountryCode() === 'BE') {
      $destination = new AtHome();
      $destination->setWeight((int) $shipment->getWeight()->getNumber());
    }
    else {
      $destination = new International();
      $destination->setParcelWeight((int) $shipment->getWeight()->getNumber());
      $customs_info = new CustomsInfo();
      $price = (float) $shipment->getOrder()->getSubtotalPrice()->getNumber();
      $customs_info->setParcelValue((int) $price * 100);
      $customs_info->setContentDescription($this->t('Books'));
      $customs_info->setShipmentType(CustomsInfo::CUSTOM_INFO_SHIPMENT_TYPE_GOODS);
      $customs_info->setParcelReturnInstructions(CustomsInfo::CUSTOM_INFO_PARCEL_RETURN_INSTRUCTION_RTS);
      $customs_info->setPrivateAddress(TRUE);
      $destination->setCustomsInfo($customs_info);
    }

    $destination->setReceiver($receiver);
    $destination->setProduct($this->getBpostProduct($shipment));
    if ($receiver->getAddress()->getCountryCode() === 'BE') {
      $box->setNationalBox($destination);
    }
    else {
      $box->setInternationalBox($destination);
    }

    return $box;
  }

  /**
   * {@inheritdoc}
   */
  protected function getApplicableShippingProfileBundle() {
    return 'customer';
  }

  /**
   * {@inheritdoc}
   */
  public function buildCheckoutPaneForm(array $pane_form, FormStateInterface $form_state, array &$complete_form) {
    $context = $form_state->get('bpost_service_checkout_pane_context');
    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = $context['order'];

    $store = $order->getStore();
    $available_countries = [];
    foreach ($store->get('shipping_countries') as $country_item) {
      $available_countries[] = $country_item->value;
    }
    /** @var \Drupal\commerce\Plugin\Commerce\InlineForm\EntityInlineFormInterface $inline_form */
    $inline_form = $this->inlineFormManager->createInstance('customer_profile', [
      'profile_scope' => 'shipping',
      'available_countries' => $available_countries,
      'address_book_uid' => $order->getCustomerId(),
      // Don't copy the profile to address book until the order is placed.
      'copy_on_save' => FALSE,
    ], $this->getShippingProfile($order));

    $pane_form['shipping_profile'] = [
      '#parents' => array_merge($pane_form['#parents'], ['shipping_profile']),
      '#inline_form' => $inline_form,
    ];
    $pane_form['shipping_profile'] = $inline_form->buildInlineForm($pane_form['shipping_profile'], $form_state);
    // The shipping_profile should always exist in form state.
    if (!$form_state->has('shipping_profile')) {
      $form_state->set('shipping_profile', $inline_form->getEntity());
    }

    // Override the available countries in the address depending on whether
    // we have international rates configured for that country.
    if (isset($pane_form['shipping_profile']['address']) && isset($pane_form['shipping_profile']['address']['widget'])) {
      $pane_form['shipping_profile']['#process'][] = [$this, 'processAvailableCountries'];
    }

    return $pane_form;
  }

  /**
   * Removes the available countries if there are not rates for international.
   *
   * @param array $element
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   * @param array $complete_form
   *
   * @return array
   */
  public function processAvailableCountries(array $element, FormStateInterface $form_state, array &$complete_form) {
    $countries = &$element['address']['widget'][0]['address']['#available_countries'];
    if (!$this->configuration['supports_international']) {
      $countries = ['BE'];
    }
    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function validateCheckoutPaneForm(array &$pane_form, FormStateInterface $form_state, array &$complete_form) {
    /** @var \Drupal\commerce\Plugin\Commerce\InlineForm\EntityInlineFormInterface $inline_form */
    $inline_form = $pane_form['shipping_profile']['#inline_form'];
    // The profile in form state needs to reflect the submitted values,
    // for packers invoked on form rebuild, or "Billing same as shipping".
    $form_state->set('shipping_profile', $inline_form->getEntity());

    // Ensure the two address lines are not longer than 40 chars each as the API
    // cannot handle it...
    $values = $inline_form->getEntity()->get('address')->first()->getValue();
    $street = $values['address_line1'] . ', ' . $values['address_line2'];
    $length = 40;
    if (mb_strlen($street) > $length) {
      $element = $pane_form['shipping_profile']['address']['widget'][0]['address']['address_line1'] ?? $pane_form['shipping_profile'];
      $form_state->setError($element,  $this->t('Please ensure that the two address lines combined are under 40 characters long.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitCheckoutPaneForm(array &$pane_form, FormStateInterface $form_state, array &$complete_form) {
    $context = $form_state->get('bpost_service_checkout_pane_context');
    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = $context['order'];

    $this->clearShippingProfile($order);

    /** @var \Drupal\commerce\Plugin\Commerce\InlineForm\EntityInlineFormInterface $inline_form */
    $inline_form = $pane_form['shipping_profile']['#inline_form'];
    /** @var \Drupal\profile\Entity\ProfileInterface $profile */
    $profile = $inline_form->getEntity();

    // Pack and save the shipments.
    $order_shipments = $order->get('shipments')->referencedEntities();
    list($packed_shipments, $removed_shipments) = $this->packerManager->packToShipments($order, $profile, $order_shipments);

    $shipments = [];
    foreach ($packed_shipments as $shipment) {
      /** @var \Drupal\commerce_shipping\Entity\ShipmentInterface $shipment */
      $new_shipment = $shipment;
      $new_shipment->setShippingService($this->getPluginId());
      $new_shipment->setShippingProfile($profile);
      $rates = $this->shipmentManager->calculateRates($new_shipment);
      $rate = reset($rates);
      $this->shipmentManager->applyRate($new_shipment, $rate);
      $new_shipment->save();
      $shipments[] = $new_shipment;
    }
    $order->shipments = $shipments;

    // Delete shipments that are no longer in use.
    $removed_shipment_ids = array_map(function ($shipment) {
      /** @var \Drupal\commerce_shipping\Entity\ShipmentInterface $shipment */
      return $shipment->id();
    }, $removed_shipments);
    if (!empty($removed_shipment_ids)) {
      $shipment_storage = $this->entityTypeManager->getStorage('commerce_shipment');
      $removed_shipments = $shipment_storage->loadMultiple($removed_shipment_ids);
      $shipment_storage->delete($removed_shipments);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function buildPaneSummary(OrderInterface $order) {
    return $this->shipmentSummary->build($order, 'checkout');
  }

  /**
   * Gets the shipping profile.
   *
   * The shipping profile is assumed to be the same for all shipments.
   * Therefore, it is taken from the first found shipment, or created from
   * scratch if no shipments were found.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The current order.
   *
   * @return \Drupal\profile\Entity\ProfileInterface
   *   The shipping profile.
   */
  protected function getShippingProfile(OrderInterface $order) {
    $shipping_profile = $this->getShippingProfileFromOrder($order);

    if (!$shipping_profile) {
      $shipping_profile = $this->entityTypeManager->getStorage('profile')->create([
        'type' => $this->getApplicableShippingProfileBundle(),
        'uid' => 0,
      ]);
    }

    return $shipping_profile;
  }
}
