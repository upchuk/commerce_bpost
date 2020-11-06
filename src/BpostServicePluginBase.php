<?php

namespace Drupal\commerce_bpost;

use CommerceGuys\Addressing\Country\CountryRepositoryInterface;
use Drupal\commerce_bpost\Plugin\Commerce\ShippingMethod\Bpost;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_price\Price;
use Drupal\commerce_shipping\Entity\ShipmentInterface;
use Drupal\commerce_shipping\OrderShipmentSummaryInterface;
use Drupal\commerce_shipping\PackerManagerInterface;
use Drupal\commerce_shipping\ShipmentManagerInterface;
use Drupal\commerce_shipping\ShippingRate;
use Drupal\Component\Plugin\PluginBase;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\physical\Weight;
use Drupal\physical\WeightUnit;
use Drupal\profile\Entity\ProfileInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base class for BPost service plugins.
 */
abstract class BpostServicePluginBase extends PluginBase implements BpostServiceInterface, ContainerFactoryPluginInterface {

  use StringTranslationTrait;
  use DependencySerializationTrait;

  /**
   * @var \Drupal\commerce_shipping\Entity\ShippingMethodInterface
   */
  protected $parentEntity;

  /**
   * @var \Drupal\commerce_shipping\ShipmentManagerInterface
   */
  protected $shipmentManager;

  /**
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * @var \Drupal\commerce_shipping\OrderShipmentSummaryInterface
   */
  protected $shipmentSummary;

  /**
   * @var \Drupal\commerce_shipping\PackerManagerInterface
   */
  protected $packerManager;

  /**
   * @var \CommerceGuys\Addressing\Country\CountryRepositoryInterface
   */
  protected $countryRepository;

  /**
   * BpostServicePluginBase constructor.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   * @param \Drupal\commerce_shipping\ShipmentManagerInterface $shipmentManager
   * @param \Drupal\commerce_shipping\OrderShipmentSummaryInterface $shipmentSummary
   * @param \Drupal\commerce_shipping\PackerManagerInterface $packerManager
   * @param \CommerceGuys\Addressing\Country\CountryRepositoryInterface $countryRepository
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entityTypeManager, ShipmentManagerInterface $shipmentManager, OrderShipmentSummaryInterface $shipmentSummary, PackerManagerInterface $packerManager, CountryRepositoryInterface $countryRepository) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->setConfiguration($configuration);
    $this->entityTypeManager = $entityTypeManager;
    $this->shipmentManager = $shipmentManager;
    $this->shipmentSummary = $shipmentSummary;
    $this->packerManager = $packerManager;
    $this->countryRepository = $countryRepository;
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
      $container->get('address.country_repository')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function label() {
    // Cast the label to a string since it is a TranslatableMarkup object.
    return (string) $this->pluginDefinition['label'];
  }

  /**
   * {@inheritdoc}
   */
  public function setParentEntity(EntityInterface $parent_entity) {
    $this->parentEntity = $parent_entity;
  }

  /**
   * {@inheritdoc}
   */
  public function getConfiguration() {
    return $this->configuration;
  }

  /**
   * {@inheritdoc}
   */
  public function setConfiguration(array $configuration) {
    $this->configuration = $configuration + $this->defaultConfiguration();
  }

  /**
   * @inheritDoc
   */
  public function defaultConfiguration() {
    return [
      'rate_amounts' => [],
      'supports_international' => FALSE,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $amounts = $this->configuration['rate_amounts'];

    $form['national'] = [
      '#type' => 'details',
      '#title' => $this->t('National rates'),
    ];

    $shipping_countries = $form_state->get('shipping_countries');

    foreach (Bpost::NATIONAL_WEIGHT_SEGMENTS as $start => $end) {
      $segment = $start . '_' . $end;

      $form['national'][$segment] = [
        '#type' => 'commerce_price',
        '#title' => $this->t('@start grams to @end grams', ['@start' => $start, '@end' => $end]),
        '#available_currencies' => ['EUR'],
        '#default_value' => $amounts['national'][$segment] ?? NULL,
        '#suffix' => '<br />'
      ];
    }

    $form['supports_international'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Supports international shipping rates'),
      '#default_value' => $this->configuration['supports_international'],
    ];

    $parents = $form['#parents'];
    $first_parent = array_shift($parents);

    $name = $first_parent . '[' . implode('][', array_merge($parents, ['supports_international'])) . ']';
    $form['international'] = [
      '#type' => 'details',
      '#title' => $this->t('International rates'),
      '#states' => [
        'visible' => [
          'input[name="' . $name . '"]' => ['checked' => TRUE],
        ],
      ],
    ];

    foreach ($shipping_countries as $country_code) {
      if ($country_code === 'BE') {
        continue;
      }

      $form['international'][$country_code] = [
        '#type' => 'fieldset',
        '#title' => $this->countryRepository->get($country_code)->getName(),
      ];

      foreach (Bpost::INTERNATIONAL_WEIGHT_SEGMENTS as $start => $end) {
        $segment = $start . '_' . $end;

        $form['international'][$country_code][$segment] = [
          '#type' => 'commerce_price',
          '#title' => $this->t('@start grams to @end grams', ['@start' => $start, '@end' => $end]),
          '#available_currencies' => ['EUR'],
          '#default_value' => $amounts['international'][$country_code][$segment] ?? NULL,
          '#suffix' => '<br />'
        ];
      }
    }


    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    // Nothing for now.
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    if (!$form_state->getErrors()) {
      $values = $form_state->getValue($form['#parents']);
      $this->configuration['rate_amounts'] = [];

      $national_service_rates = array_filter($values['national'], function ($rate) {
        return $rate['number'] != "";
      });

      $this->configuration['rate_amounts']['national'] = $national_service_rates;

      $international_service_rates = [];
      foreach ($values['international'] as $country_code => $rates) {
        $rates = array_filter($rates, function ($rate) {
          return $rate['number'] != "";
        });

        $international_service_rates[$country_code] = $rates;
      }

      $this->configuration['rate_amounts']['international'] = $international_service_rates;

      $this->configuration['supports_international'] = $values['supports_international'];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function calculateRates(ShipmentInterface $shipment) {
    $rates = [];

    $shipping_profile = $shipment->getShippingProfile();
    $address = $shipping_profile->get('address')->first()->getValue();
    $country = $address['country_code'];
    if ($country === 'BE') {
      return $this->calculateNationalRates($shipment);
    }

    // For international rates, we need to check if we have it enabled on this
    // service type.
    if (!$this->configuration['supports_international']) {
      return $rates;
    }

    $amounts = $this->configuration['rate_amounts']['international'];
    if (!$amounts || !isset($amounts[$country]) || !$amounts[$country]) {
      return $rates;
    }

    $weight = $shipment->getWeight();
    $price = $this->determinePriceFromWeightSegment($weight, $amounts[$country]);

    $rates[] = new ShippingRate([
      'shipping_method_id' => $this->parentEntity->id(),
      'service' => $this->parentEntity->getPlugin()->getServices()[$this->pluginId],
      'amount' => $price,
    ]);

    return $rates;
  }

  /**
   * {@inheritdoc}
   */
  public function validateCheckoutPaneForm(array &$pane_form, FormStateInterface $form_state, array &$complete_form) {
    // Do nothing for now.
  }

  /**
   * Calculates the rates for a national shipment.
   *
   * @param \Drupal\commerce_shipping\Entity\ShipmentInterface $shipment
   *
   * @return array
   */
  protected function calculateNationalRates(ShipmentInterface $shipment) {
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
   * Fishes out the rate based on the weight segment.
   *
   * @param \Drupal\physical\Weight $weight
   * @param array $rate_amounts
   *
   * @return Price
   *   The price.
   */
  protected function determinePriceFromWeightSegment(Weight $weight, array $rate_amounts) {
    // @todo convert incoming weight to gram.
    foreach ($rate_amounts as $segment => $price) {
      [$start, $end] = explode('_', $segment);
      $start_weight = new Weight($start, WeightUnit::GRAM);
      $end_weight = new Weight($end, WeightUnit::GRAM);

      if ($weight->greaterThanOrEqual($start_weight) && $weight->lessThan($end_weight)) {
        return Price::fromArray($price);
      }
    }

    // If we don't find any rates, we default to the first one.
    // @todo throw event to resolve a default rate.
    $first_amount = reset($rate_amounts);
    return Price::fromArray($first_amount);
  }

  /**
   * Deletes the shipping profile from the order if it's not the correct type.
   *
   * We do this every time we submit the shipping pane as the user might change
   * from one service plugin to another, in which case, the old shipping
   * profile would not be appropriate anymore.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *
   */
  protected function clearShippingProfile(OrderInterface $order) {
    /** @var \Drupal\commerce_shipping\Entity\ShipmentInterface $shipment */
    foreach ($order->get('shipments')->referencedEntities() as $shipment) {
      $shipping_profile = $shipment->getShippingProfile();
      if ($shipping_profile instanceof ProfileInterface && $shipping_profile->bundle() !== $this->getApplicableShippingProfileBundle()) {
        $shipping_profile->delete();
      }
    }
  }

  /**
   * Returns the shipping profile from a given order.
   *
   * This includes only the applicable type (bundle) of shipping profile.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *
   * @return \Drupal\profile\Entity\ProfileInterface|null
   */
  protected function getShippingProfileFromOrder(OrderInterface $order) {
    /** @var \Drupal\commerce_shipping\Entity\ShipmentInterface $shipment */
    foreach ($order->get('shipments')->referencedEntities() as $shipment) {
      $shipping_profile = $shipment->getShippingProfile();
      if ($shipping_profile instanceof ProfileInterface && $shipping_profile->bundle() === $this->getApplicableShippingProfileBundle()) {
        return $shipping_profile;
      }
    }

    return NULL;
  }

  /**
   * Returns the shipping profile bundle this service uses.
   *
   * @return string
   */
  abstract protected function getApplicableShippingProfileBundle();

  /**
   * Returns the exact Bpost product type to use for this shipment.
   *
   * The shipment is already expected to use the current service, but within
   * each service plugin, there can be different types of more specific BPost
   * services.
   *
   * @param \Drupal\commerce_shipping\Entity\ShipmentInterface $shipment
   *   The shipment.
   *
   * @return string
   */
  abstract protected function getBpostProduct(ShipmentInterface $shipment);

}
