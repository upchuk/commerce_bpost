<?php

namespace Drupal\commerce_bpost_pickup\Plugin\BpostService;

use Bpost\BpostApiClient\Bpost\Order\Box;
use Bpost\BpostApiClient\Bpost\Order\Box\At247;
use Bpost\BpostApiClient\Bpost\Order\Box\AtBpost;
use Bpost\BpostApiClient\Bpost\Order\Box\National\Unregistered;
use Bpost\BpostApiClient\Bpost\Order\Box\Option\Messaging;
use Bpost\BpostApiClient\Bpost\Order\ParcelsDepotAddress;
use Bpost\BpostApiClient\Bpost\Order\PugoAddress;
use Bpost\BpostApiClient\Bpost\ProductConfiguration\Product;
use Bpost\BpostApiClient\Geo6\Poi;
use CommerceGuys\Addressing\Country\CountryRepositoryInterface;
use Drupal\commerce\AjaxFormTrait;
use Drupal\commerce_bpost\BpostServicePluginBase;
use Drupal\commerce_bpost\Exception\BpostCheckoutException;
use Drupal\commerce_bpost_pickup\PickupPointManagerInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_shipping\Entity\ShipmentInterface;
use Drupal\commerce_shipping\OrderShipmentSummaryInterface;
use Drupal\commerce_shipping\PackerManagerInterface;
use Drupal\commerce_shipping\ShipmentManagerInterface;
use Drupal\commerce_shipping\ShippingRate;
use Drupal\Component\Render\FormattableMarkup;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Url;
use Drupal\leaflet\LeafletService;
use Drupal\profile\Entity\ProfileInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the bpost_service.
 *
 * @BpostService(
 *   id = "pickup_point",
 *   label = @Translation("Pickup point"),
 *   description = @Translation("Pickup point.")
 * )
 */
class PickupPoint extends BpostServicePluginBase implements ContainerFactoryPluginInterface {

  use AjaxFormTrait;

  /**
   * The leaflet service.
   *
   * @var \Drupal\leaflet\LeafletService
   */
  protected $leafletService;

  /**
   * The pickup point manager.
   *
   * @var \Drupal\commerce_bpost_pickup\PickupPointManager
   */
  protected $pointManager;

  /**
   * PickupPoint constructor.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\commerce_shipping\ShipmentManagerInterface $shipment_manager
   *   The shipment manager.
   * @param \Drupal\commerce_shipping\OrderShipmentSummaryInterface $shipment_summary
   *   The shipment summary service.
   * @param \Drupal\commerce_shipping\PackerManagerInterface $packer_manager
   *   The packer manager.
   * @param \CommerceGuys\Addressing\Country\CountryRepositoryInterface $country_repository
   *   The country repository.
   * @param \Drupal\leaflet\LeafletService $leflet_service
   *   The leaflet service.
   * @param \Drupal\commerce_bpost_pickup\PickupPointManagerInterface $point_manager
   *   The pickup point manager.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, ShipmentManagerInterface $shipment_manager, OrderShipmentSummaryInterface $shipment_summary, PackerManagerInterface $packer_manager, CountryRepositoryInterface $country_repository, LeafletService $leflet_service, PickupPointManagerInterface $point_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $entity_type_manager, $shipment_manager, $shipment_summary, $packer_manager, $country_repository);
    $this->leafletService = $leflet_service;
    $this->pointManager = $point_manager;
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
      $container->get('address.country_repository'),
      $container->get('leaflet.service'),
      $container->get('commerce_bpost_pickup.points_manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'allowed_pickup_point_types' => [
        // 1 is post office.
        1 => 1,
        // 2 is post point.
        2 => 2,
      ],
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   *
   * For the moment, we do not support international shipments.
   */
  protected function getBpostProduct(ShipmentInterface $shipment) {
    $shipping_profile = $shipment->getShippingProfile();

    $details = $this->getPointDetails($shipping_profile);
    if (!$details instanceof Poi) {
      return NULL;
    }

    $type = $details->getType();
    $map = [
      // 1 is post office.
      1 => Product::PRODUCT_NAME_BPACK_AT_BPOST,
      // 2 is post point.
      2 => Product::PRODUCT_NAME_BPACK_AT_BPOST,
      // 4 is parcel distributor.
      4 => Product::PRODUCT_NAME_BPACK_24_7,
    ];

    return isset($map[$type]) ? $map[$type] : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function prepareDeliveryBox(ShipmentInterface $shipment) {
    $shipping_profile = $shipment->getShippingProfile();
    $point_details = $this->getPointDetails($shipping_profile);
    if (!$point_details) {
      $message = new FormattableMarkup('No point details could be retrieved for profile @id', ['@id' => $shipping_profile->id()]);
      $exception = new BpostCheckoutException($message);
      $exception->setOrder($shipment->getOrder());
      throw $exception;
    }

    $order = $shipment->getOrder();
    $billing_profile = $order->getBillingProfile();
    $billing_address = $billing_profile->get('address')->first()->getValue();
    $product = $this->getBpostProduct($shipment);

    if ($product === Product::PRODUCT_NAME_BPACK_24_7) {
      // For parcel distributors.
      $destination = new At247();
      $address = new ParcelsDepotAddress();
    }
    else {
      // For BPost post offices or post points,.
      $destination = new AtBpost();
      $address = new PugoAddress();
    }

    $langcode = $shipping_profile->getOwner()->getPreferredLangcode();
    if (!in_array($langcode, ['en', 'fr', 'nl'])) {
      $langcode = 'fr';
    }
    $langcode = strtoupper($langcode);

    $destination->setProduct($product);
    $destination->setWeight((int) $shipment->getWeight()->getNumber());

    $address->setStreetName($point_details->getStreet());
    $address->setNumber($point_details->getNr());
    $address->setPostalCode($point_details->getZip());
    $address->setLocality($point_details->getCity());
    $address->setCountryCode('BE');

    if ($destination instanceof AtBpost) {
      $destination->setPugoId($point_details->getId());
      $destination->setPugoName($point_details->getOffice());
      $destination->setPugoAddress($address);
      $option = new Messaging(Messaging::MESSAGING_TYPE_KEEP_ME_INFORMED, $langcode);
      $option->setEmailAddress($order->getCustomer()->getEmail());
      $destination->addOption($option);
    }
    if ($destination instanceof At247) {
      $destination->setParcelsDepotId($point_details->getId());
      $destination->setParcelsDepotName($point_details->getOffice());
      $destination->setParcelsDepotAddress($address);
      $unregistered = new Unregistered();
      $unregistered->setEmailAddress($order->getCustomer()->getEmail());
      $unregistered->setLanguage($langcode);
      if ($billing_profile->hasField('phone_number') && !$billing_profile->get('phone_number')->isEmpty()) {
        $unregistered->setMobilePhone($billing_profile->get('phone_number')->value);
      }
      $destination->setUnregistered($unregistered);
    }

    $destination->setReceiverName($billing_address['given_name'] . ' ' . $billing_address['family_name']);

    if ($billing_address['organization']) {
      $destination->setReceiverCompany($billing_address['organization']);
    }

    $box = new Box();
    $box->setNationalBox($destination);

    return $box;
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    // We do not support international pickup points.
    $form['supports_international']['#access'] = FALSE;
    $form['international']['#access'] = FALSE;

    $form['allowed_pickup_point_types'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Allowed pickup point types'),
      '#description' => $this->t('Which types of pickup points should be available to search on the map.'),
      '#options' => [
        1 => $this->t('Post office - bpack@bpost'),
        2 => $this->t('Post point - bpack@bpost'),
        4 => $this->t('Parcel distributor - bpack 24/7'),
      ],
      '#default_value' => $this->configuration['allowed_pickup_point_types'],
      '#required' => TRUE,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);
    $this->configuration['allowed_pickup_point_types'] = array_values(array_filter($form_state->getValue(array_merge($form['#parents'], ['allowed_pickup_point_types']))));
  }

  /**
   * {@inheritdoc}
   */
  public function buildCheckoutPaneForm(array $pane_form, FormStateInterface $form_state, array &$complete_form) {
    $context = $form_state->get('bpost_service_checkout_pane_context');
    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = $context['order'];

    if ($form_state->get('shipping_profile')) {
      /** @var \Drupal\profile\Entity\ProfileInterface $shipping_profile */
      $storage = &$form_state->getStorage();
      unset($storage['shipping_profile']);
      $order->shipments = [];
    }

    // Form for searching for the postcode.
    $pane_form['search_wrapper'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['pickup-point-search-wrapper'],
      ],
    ];

    $postal_code = $this->getSelectedPostalCode($order, $form_state);
    $selected_point_details = $this->getSelectedPointDetails($order, $form_state);

    $pane_form['search_wrapper']['postal_code'] = [
      '#type' => 'textfield',
      '#placeholder' => $this->t('Post code'),
      '#description' => $this->t('Please enter your postcode to find the closest pick-up points'),
      '#default_value' => $postal_code,
    ];

    $pane_form['search_wrapper']['search'] = [
      '#type' => 'submit',
      '#value' => $this->t('Search'),
      '#executes_submit_callback' => FALSE,
      '#limit_validation_errors' => [
        array_merge($pane_form['#parents'], [
          'search_wrapper',
          'postal_code',
        ]),
      ],
      '#ajax' => [
        'callback' => [get_class($this), 'ajaxRefreshForm'],
        'element' => $pane_form['#parents'],
      ],
    ];

    if ($postal_code) {
      $points = $this->pointManager->getClosestToPostalCode($postal_code, $this->getAllowedPickupPointType(), 25);

      $pane_form['grid'] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['pickup-point-grid'],
        ],
      ];

      if (!$points) {
        $pane_form['grid']['no_points'] = [
          '#markup' => $this->t('No pickup points have been found for that postal code. Please try again.'),
        ];

        return $pane_form;
      }

      $pane_form['grid']['map_wrapper'] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['pickup-point-grid--left'],
        ],
      ];

      $pane_form['grid']['list_wrapper'] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['pickup-point-grid--right'],
        ],
      ];

      $features = [];
      $list = [];
      $radios = [];
      $point_details = [];
      foreach ($points as $point) {
        /** @var \Bpost\BpostApiClient\Geo6\Poi $poi */
        $poi = $point['poi'];
        $features[] = [
          'type' => 'point',
          'lat' => $poi->getLatitude(),
          'lon' => $poi->getLongitude(),
          'popup' => $poi->getOffice(),
          'label' => 'a label',
          'icon' => [
            'iconType' => 'html',
            'iconSize' => [
              'x' => '20px',
              'y' => '20px',
            ],
            'html' => '<i class="leaflet-marker-icon" data-poi-id="' . $poi->getId() . '"></i>',
          ],
        ];

        $classes = ['bpost-poi-link'];
        if ($selected_point_details instanceof Poi && $poi->getId() === $selected_point_details->getId()) {
          $classes[] = 'selected-poi';
        }

        $list[] = [
          '#type' => 'link',
          '#url' => Url::fromRoute('<current>', [], [
            'attributes' => [
              'class' => $classes,
              'data-poi-id' => $poi->getId(),
              'data-poi-lat' => $poi->getLatitude(),
              'data-poi-lon' => $poi->getLongitude(),
            ],
          ]),
          '#title' => $poi->getOffice(),
        ];

        $radios[$poi->getId()] = [
          '#type' => 'hidden',
          '#default_value' => $selected_point_details instanceof Poi && $selected_point_details->getId() == $poi->getId() ? 1 : 0,
          '#attributes' => [
            'data-poi-id' => $poi->getId(),
            'class' => ['poi-input'],
          ],
        ];

        $point_details[$poi->getId()] = [
          'label' => $poi->getOffice(),
          'address' => $poi->getStreet() . ' ' . $poi->getNr() . ', ' . $poi->getZip() . ' ' . $poi->getCity(),
          'type' => $poi->getType(),
          'id' => $poi->getId(),
          'postal_code' => $poi->getZip(),
        ];
      }

      $form_state->set('point_details', $point_details);

      $center_point = $selected_point_details instanceof Poi ? $selected_point_details : $points[0]['poi'];
      $info = leaflet_map_get_info('OSM Mapnik');
      $info['id'] = 'bpost-map';
      $info['settings']['map_position_force'] = 1;
      $info['settings']['zoom'] = $selected_point_details instanceof Poi ? 17 : 15;
      $info['settings']['zoomFiner'] = 0;
      $info['settings']['minZoom'] = 1;
      $info['settings']['maxZoom'] = 20;
      $info['settings']['center'] = [
        'lat' => $center_point->getLatitude(),
        'lon' => $center_point->getLongitude(),
      ];
      $pane_form['grid']['map_wrapper']['map'] = $this->leafletService->leafletRenderMap($info, $features);
      $pane_form['grid']['map_wrapper']['selection'] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['selected-pickup-point'],
        ],
      ];

      if ($selected_point_details instanceof Poi) {
        $pane_form['grid']['map_wrapper']['selection'][] = [
          '#markup' => '<p>' . $this->t('Your selection:') . '</p>',
        ];
        $pane_form['grid']['map_wrapper']['selection'][] = [
          '#markup' => '<p><strong>' . $selected_point_details->getOffice() . '</strong></p>',
        ];
        $pane_form['grid']['map_wrapper']['selection'][] = [
          '#markup' => '<p>' . $point_details[$selected_point_details->getId()]['address'] . '</p>',
        ];
      }
      else {
        $pane_form['grid']['map_wrapper']['selection']['#attributes']['class'][] = 'hide';
      }

      $pane_form['grid']['list_wrapper']['list'] = [
        '#theme' => 'item_list__bpost_pickup_points',
        '#items' => $list,
        '#attributes' => [
          'class' => [
            'bpost-pickup-point-list',
          ],
        ],
        '#attached' => [
          'library' => [
            'commerce_bpost_pickup/bpost_map',
          ],
          'drupalSettings' => [
            'commerce_bpost_pickup' => [
              'pickup_point_details' => $point_details,
            ],
          ],
        ],
      ];

      $pane_form['list_wrapper']['radios'] = $radios;
    }

    return $pane_form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateCheckoutPaneForm(array &$pane_form, FormStateInterface $form_state, array &$complete_form) {
    $selected = $this->getSubmittedPoint($form_state);
    if (!$selected) {
      $form_state->setErrorByName('bpost_services', $this->t('There was no valid pickup point selection made.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitCheckoutPaneForm(array &$pane_form, FormStateInterface $form_state, array &$complete_form) {
    $context = $form_state->get('bpost_service_checkout_pane_context');
    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = $context['order'];
    $selected = $this->getSubmittedPoint($form_state);
    $details = $form_state->get('point_details');
    $this->clearShippingProfile($order);
    $shipping_profile = $this->getShippingProfile($order, $details[$selected]);

    $shipping_profile->save();

    $order_shipments = $order->get('shipments')->referencedEntities();
    list($packed_shipments, $removed_shipments) = $this->packerManager->packToShipments($order, $shipping_profile, $order_shipments);

    $shipments = [];
    foreach ($packed_shipments as $shipment) {
      /** @var \Drupal\commerce_shipping\Entity\ShipmentInterface $shipment */
      $new_shipment = $shipment;
      $new_shipment->setShippingService($this->getPluginId());
      $new_shipment->setShippingProfile($shipping_profile);
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
    $summary = [];

    $shipping_profile = $this->getShippingProfileFromOrder($order);

    if (!$shipping_profile) {
      return $summary;
    }

    $details = $this->getPointDetails($shipping_profile);
    if (!$details) {
      return $summary;
    }

    $summary[] = [
      '#markup' => $this->t('Shipping to pickup point: <strong>@point</strong>.', ['@point' => $details->getOffice()]),
    ];

    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  protected function getApplicableShippingProfileBundle() {
    return 'bpost_pickup_point';
  }

  /**
   * Gets the shipping profile.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The current order.
   * @param array $details
   *   The details about the selected point.
   *
   * @return \Drupal\profile\Entity\ProfileInterface
   *   The shipping profile.
   */
  protected function getShippingProfile(OrderInterface $order, array $details) {
    $shipping_profile = $this->getShippingProfileFromOrder($order);

    if (!$shipping_profile) {
      $shipping_profile = $this->entityTypeManager->getStorage('profile')->create([
        'type' => $this->getApplicableShippingProfileBundle(),
        'uid' => 0,
      ]);
    }

    $shipping_profile->set('point_id', $details['id']);
    $shipping_profile->set('point_type', $details['type']);
    $shipping_profile->set('postal_code', $details['postal_code']);

    return $shipping_profile;
  }

  /**
   * Returns the submitted pickup point from the form state.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return int|null
   *   The submitted point.
   */
  protected function getSubmittedPoint(FormStateInterface $form_state) {
    $values = $form_state->getValue(['bpost_shipping', 'list_wrapper', 'radios']);
    if (!$values) {
      return NULL;
    }

    $selected = NULL;
    $count = 0;
    foreach ($values as $id => $selection) {
      if ($selection == "1") {
        $selected = $id;
        $count++;
      }
    }

    // Only return a valid selection if only one point was selected.
    return $count === 1 ? (int) $selected : NULL;
  }

  /**
   * {@inheritdoc}
   *
   * Calculates a standard rate that is configured for the pickup point.
   *
   * We do not support international pickup points.
   */
  public function calculateRates(ShipmentInterface $shipment) {
    $rates = [];

    $weight = $shipment->getWeight();
    $amounts = $this->configuration['rate_amounts']['national'];

    if (!$amounts) {
      return $rates;
    }

    $price = $this->determinePriceFromWeightSegment($weight, $amounts);

    $rates[] = new ShippingRate([
      'shipping_method_id' => $this->parentEntity->id(),
      'service' => $this->parentEntity->getPlugin()->getServices()[$this->pluginId],
      'amount' => $price,
    ]);

    return $rates;
  }

  /**
   * Returns the selected point details from a given order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return \Bpost\BpostApiClient\Geo6\Poi|null
   *   The point details object.
   */
  protected function getSelectedPointDetails(OrderInterface $order, FormStateInterface $form_state) {
    $shipping_profile = $this->getShippingProfileFromOrder($order);
    if (!$form_state->getTriggeringElement()) {
      return $shipping_profile ? $this->getPointDetails($shipping_profile) : NULL;
    }

    $complete_form = $form_state->getCompleteForm();
    $radios = NestedArray::getValue($complete_form, [
      'bpost_shipping',
      'list_wrapper',
      'radios',
    ]);
    if (!$radios) {
      return $shipping_profile ? $this->getPointDetails($shipping_profile) : NULL;
    }

    $details = $form_state->get('point_details');
    foreach ($radios as $point_id => $radio_element) {
      if ($radio_element['#value'] == "1") {
        if (!isset($details[$point_id])) {
          return NULL;
        }

        return $this->pointManager->getPointDetails((int) $point_id, (int) $details[$point_id]['type']);
      }
    }

    return NULL;
  }

  /**
   * Returns the postal code that is either default or has been selected.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return string|null
   *   The selected post code.
   */
  protected function getSelectedPostalCode(OrderInterface $order, FormStateInterface $form_state) {
    $postal_code = $form_state->getValue([
      'bpost_shipping',
      'search_wrapper',
      'postal_code',
    ]);
    if ($postal_code) {
      // Priority is the user selection.
      return $postal_code;
    }

    $details = $this->getSelectedPointDetails($order, $form_state);
    return $details instanceof Poi ? $details->getZip() : NULL;
  }

  /**
   * Loads the details of a point from a given shipping profile.
   *
   * @param \Drupal\profile\Entity\ProfileInterface $shipping_profile
   *   The shipping profile.
   *
   * @return \Bpost\BpostApiClient\Geo6\Poi|null
   *   The point details object.
   */
  protected function getPointDetails(ProfileInterface $shipping_profile) {
    $point_id = (int) $shipping_profile->get('point_id')->value;
    $point_type = (int) $shipping_profile->get('point_type')->value;
    return $this->pointManager->getPointDetails($point_id, $point_type);
  }

  /**
   * Returns the number representing the type used to call the nearest points.
   */
  protected function getAllowedPickupPointType() {
    $allowed_pickup_point_types = $this->configuration['allowed_pickup_point_types'];
    if (count($allowed_pickup_point_types) === 1) {
      // If only one is allowed, it's simple: we return it.
      return reset($allowed_pickup_point_types);
    }

    // Otherwise, we might have to add numbers.
    $number = 0;
    foreach ($allowed_pickup_point_types as $type_number) {
      $number += (int) $type_number;
    }

    return $number;
  }

}
