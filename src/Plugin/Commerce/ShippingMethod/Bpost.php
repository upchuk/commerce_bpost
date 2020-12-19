<?php

namespace Drupal\commerce_bpost\Plugin\Commerce\ShippingMethod;

use Drupal\commerce_bpost\BpostServicePluginManager;
use Drupal\commerce_shipping\Entity\ShipmentInterface;
use Drupal\commerce_shipping\PackageTypeManagerInterface;
use Drupal\commerce_shipping\Plugin\Commerce\ShippingMethod\ShippingMethodBase;
use Drupal\commerce_shipping\ShippingService;
use Drupal\commerce_store\Resolver\StoreResolverInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\state_machine\WorkflowManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the BPost shipping method.
 *
 * @CommerceShippingMethod(
 *  id = "bpost",
 *  label = @Translation("BPost")
 * )
 */
class Bpost extends ShippingMethodBase {

  /**
   * @var \Drupal\commerce_bpost\BpostServicePluginManager
   */
  protected $bpostServicePluginManager;

  /**
   * @var \Drupal\commerce_store\Resolver\StoreResolverInterface
   */
  protected $storeResolver;

  /**
   * The available international weight segments specific to BPost.
   */
  const INTERNATIONAL_WEIGHT_SEGMENTS = [
    0 => 250,
    251 => 500,
    501 => 750,
    751 => 1000,
    1001 => 1250,
    1251 => 1500,
    1501 => 1750,
    1751 => 2000,
    2001 => 3000,
    3001 => 4000,
    4001 => 5000,
    5001 => 6000,
    6001 => 7000,
    7001 => 8000,
    8001 => 9000,
    9001 => 10000,
    10001 => 15000,
    15001 => 20000,
    20001 => 25000,
    25000 => 30000,
  ];

  /**
   * The available national weight segments specific to BPost.
   */
  const NATIONAL_WEIGHT_SEGMENTS = [
    0 => 2000,
    2001 => 5000,
    5001 => 10000,
    10001 => 20000,
    20001 => 30000,
  ];

  /**
   * Constructs a new Bpost object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\commerce_shipping\PackageTypeManagerInterface $package_type_manager
   *   The package type manager.
   * @param \Drupal\state_machine\WorkflowManagerInterface $workflow_manager
   *   The workflow manager.
   * @param \Drupal\commerce_bpost\BpostServicePluginManager $bpostServicePluginManager
   * @param \Drupal\commerce_store\Resolver\StoreResolverInterface $storeResolver
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, PackageTypeManagerInterface $package_type_manager, WorkflowManagerInterface $workflow_manager, BpostServicePluginManager $bpostServicePluginManager, StoreResolverInterface $storeResolver) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $package_type_manager, $workflow_manager);
    foreach ($bpostServicePluginManager->getDefinitions() as $id => $definition) {
      $this->services[$id] = new ShippingService($id, $definition['label']);
    }

    $this->bpostServicePluginManager = $bpostServicePluginManager;
    $this->storeResolver = $storeResolver;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('plugin.manager.commerce_package_type'),
      $container->get('plugin.manager.workflow'),
      $container->get('plugin.manager.bpost_service'),
      $container->get('commerce_store.default_store_resolver')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'service_configuration' => [],
      'api' => [
        'endpoint' => '',
        'username' => '',
        'password' => '',
        'remote_order_creation' => FALSE,
      ],
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    /** @var \Drupal\commerce_shipping\Entity\ShippingMethodInterface $shipping_method */
    $shipping_method = $form_state->getBuildInfo()['callback_object']->getEntity();
    $shipping_countries = [];
    $stores = !$shipping_method->isNew() ? $shipping_method->getStores() : [$this->storeResolver->resolve()];
    if (!$stores) {
      $form['message'] = [
        '#markup' => $this->t('Please configure at least one store on your site.'),
      ];
    }
    foreach ($stores as $store) {
      $store_shipping_countries = array_column($store->get('shipping_countries')->getValue(), 'value') ?? [];
      $shipping_countries = array_merge($shipping_countries, $store_shipping_countries);
    }

    if (!$shipping_countries) {
      $form['message'] = [
        '#markup' => $this->t('Please specify which countries your store can ship to.'),
      ];

      $form_state->set('no_shipping_countries', TRUE);

      return $form;
    }

    if (!in_array('BE', $shipping_countries)) {
      $form['message'] = [
        '#markup' => $this->t('Please include Belgium in the list of countries your store can ship to.'),
      ];

      $form_state->set('belgium_missing_shipping_countries', TRUE);

      return $form;
    }

    $form_state->set('shipping_countries', $shipping_countries);

    $definitions = $this->bpostServicePluginManager->getDefinitions();
    $configuration = $this->configuration['service_configuration'];

    $form['api_wrapper'] = [
      '#type' => 'details',
      '#title' => $this->t('API connection information'),
      '#open' => TRUE,
    ];

    $form['api_wrapper']['username'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Username'),
      '#default_value' => $this->configuration['api']['username'] ?? '',
    ];

    $form['api_wrapper']['password'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Password'),
      '#default_value' => $this->configuration['api']['password'] ?? '',
    ];

    $form['api_wrapper']['remote_order_creation'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable remote order creation'),
      '#description' => $this->t('Check this box if you would like the orders to be created in the BPost shipping manager when checking out successfully.'),
      '#default_value' => $this->configuration['api']['remote_order_creation'] ?? '',
    ];

    $form['rate_amounts_wrapper'] = [
      '#type' => 'details',
      '#title' => $this->t('Rate amounts'),
      '#open' => TRUE,
    ];

    foreach ($definitions as $id => $definition) {
      $form['rate_amounts_wrapper'][$id] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Rate amounts for @label', ['@label' => $definition['label']]),
      ];

      $form['rate_amounts_wrapper'][$id]['configuration'] = [
        '#type' => 'commerce_plugin_configuration',
        '#plugin_type' => 'bpost_service',
        '#plugin_id' => $id,
        '#default_value' => $configuration[$id] ?? [],
      ];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::validateConfigurationForm($form, $form_state);
    if ($form_state->get('no_shipping_countries')) {
      $form_state->setErrorByName('services', $this->t('Please specify which countries your store can ship to.'));
    }

    if ($form_state->get('belgium_missing_shipping_countries')) {
      $form_state->setErrorByName('services', $this->t('Please include Belgium in the list of countries your store can ship to.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);

    if (!$form_state->getErrors()) {
      $values = $form_state->getValue(array_merge($form['#parents'], ['rate_amounts_wrapper']));
      $service_configuration = [];
      foreach ($values as $plugin_id => $value) {
        $service_configuration[$plugin_id] = $value['configuration'];
      }

      $this->configuration['service_configuration'] = $service_configuration;
      $this->configuration['api'] = $form_state->getValue(array_merge($form['#parents'], ['api_wrapper']));
    }
  }

  /**
   * @inheritDoc
   */
  public function calculateRates(ShipmentInterface $shipment) {
    $rates = [];
    if (!$shipment->getShippingService()) {
      return $rates;
    }

    /** @var \Drupal\commerce_bpost\BpostServiceInterface $plugin */
    $plugin = $this->bpostServicePluginManager->createInstance($shipment->getShippingService(), $this->configuration['service_configuration'][$shipment->getShippingService()]);
    $plugin->setParentEntity($this->parentEntity);
    return $plugin->calculateRates($shipment);
  }

  /**
   * Instantiates a service plugin based on the ID.
   *
   * It also passes the parent entity and the configuration from the Shipping
   * method entity,
   *
   * @param $plugin_id
   *
   * @return \Drupal\commerce_bpost\BpostServiceInterface
   */
  public function instantiateServicePlugin($plugin_id) {
    /** @var \Drupal\commerce_bpost\BpostServiceInterface $plugin */
    $plugin = $this->bpostServicePluginManager->createInstance($plugin_id, $this->configuration['service_configuration'][$plugin_id]);
    $plugin->setParentEntity($this->parentEntity);
    return $plugin;
  }

  /**
   * Instantiates a BPost API client to make requests to the Shipping manager.
   *
   * @return \Bpost\BpostApiClient\Bpost
   */
  public function getBpostClient() {
    $config = $this->configuration['api'];

    return new \Bpost\BpostApiClient\Bpost($config['username'], $config['password']);
  }

}
