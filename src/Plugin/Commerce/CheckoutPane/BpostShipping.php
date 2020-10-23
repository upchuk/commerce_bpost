<?php


namespace Drupal\commerce_bpost\Plugin\Commerce\CheckoutPane;

use Drupal\commerce_shipping\Plugin\Commerce\CheckoutPane\ShippingInformation;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;

/**
 * Provides the shipping pane for using Bpost.
 *
 * @CommerceCheckoutPane(
 *   id = "bpost_shipping",
 *   label = @Translation("BPost Shipping"),
 *   wrapper_element = "fieldset",
 * )
 */
class BpostShipping extends ShippingInformation implements ContainerFactoryPluginInterface {

  /**
   * {@inheritdoc}
   */
  public function isVisible() {
    if (!$this->order->hasField('shipments')) {
      return FALSE;
    }

    // The order must contain at least one shippable purchasable entity.
    foreach ($this->order->getItems() as $order_item) {
      $purchased_entity = $order_item->getPurchasedEntity();
      if ($purchased_entity && $purchased_entity->hasField('weight')) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function buildPaneSummary() {
    if ($this->isVisible()) {
      $existing_shipments = $this->order->shipments->referencedEntities();
      if (!$existing_shipments) {
        return NULL;
      }

      /** @var \Drupal\commerce_shipping\Entity\ShipmentInterface $shipment */
      $shipment = reset($existing_shipments);
      $service = $shipment->getShippingService();
      if (!$service) {
        return [];
      }

      /** @var \Drupal\commerce_bpost\BpostServiceInterface $service_plugin */
      $service_plugin = $this->getShippingMethod()->getPlugin()->instantiateServicePlugin($service);
      return $service_plugin->buildPaneSummary($this->order);
    }

    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function buildPaneForm(array $pane_form, FormStateInterface $form_state, array &$complete_form) {
    // First, check if there are any shipping methods configured to use the Bpost
    // shipping services.
    $shipping_method = $this->getShippingMethod();
    if (!$shipping_method) {
      $pane_form['message'] = [
        '#markup' => $this->t('There are no Bpost shipping methods configured.')
      ];

      return $pane_form;
    }

    $services = $shipping_method->getPlugin()->getServices();
    $options = [];
    foreach ($services as $service) {
      $options[$service->getId()] = $service->getLabel();
    }

    if (!$options) {
      return $pane_form;
    }

    if (count($options) === 1) {
      $pane_form['bpost_services'] = [
        '#type' => 'value',
        '#value' => $service->getId()
      ];

      $form_state->setValue(['bpost_shipping', 'bpost_services'], $service->getId());
    }
    else {
      $pane_form['bpost_services'] = [
        '#title' => $this->t('Please select your delivery choice'),
        '#type' => 'select',
        '#options' => $options,
        '#empty_option' => $this->t('Select'),
        '#default_value' => $this->getSelectedShippingService($form_state),
        '#ajax' => [
          'callback' => [get_class($this), 'ajaxRefreshForm'],
          'element' => $pane_form['#parents'],
        ],
        '#required' => TRUE,
        '#limit_validation_errors' => [
          array_merge($pane_form['#parents'], ['bpost_services']),
        ],
      ];
    }

    $pane_form += $this->buildPaneForService($pane_form, $form_state, $complete_form);

    return $pane_form;
  }

  /**
   * Builds the specific pane for the chosen service.
   */
  protected function buildPaneForService(array $pane_form, FormStateInterface $form_state, array $complete_form) {
    $service = $this->getSelectedShippingService($form_state);
    if (!$service) {
      return [];
    }

    /** @var \Drupal\commerce_bpost\BpostServiceInterface $service_plugin */
    $service_plugin = $this->getShippingMethod()->getPlugin()->instantiateServicePlugin($service);
    $context = [
      'order' => $this->order,
    ];

    $form_state->set('bpost_service_checkout_pane_context', $context);
    // Keep track of the selected service because on the service form there can
    // be other Ajax based elements which would remove the selection.
    $form_state->set('bpost_service', $service);
    return $service_plugin->buildCheckoutPaneForm($pane_form, $form_state, $complete_form);
  }

  /**
   * {@inheritdoc}
   */
  public function validatePaneForm(array &$pane_form, FormStateInterface $form_state, array &$complete_form) {
    if (isset($form_state->getTriggeringElement()['#ajax'])) {
      return;
    }

    $selected_service = $form_state->getValue(['bpost_shipping', 'bpost_services']);
    if (!$selected_service) {
      $form_state->setError($pane_form['bpost_services'], $this->t('Please select a delivery type.'));
      return;
    }

    /** @var \Drupal\commerce_bpost\BpostServiceInterface $service_plugin */
    $service_plugin = $this->getShippingMethod()->getPlugin()->instantiateServicePlugin($selected_service);
    $context = [
      'order' => $this->order,
    ];

    $form_state->set('bpost_service_checkout_pane_context', $context);
    $service_plugin->validateCheckoutPaneForm($pane_form, $form_state, $complete_form);
  }

  /**
   * {@inheritdoc}
   */
  public function submitPaneForm(array &$pane_form, FormStateInterface $form_state, array &$complete_form) {
    $selected_service = $form_state->getValue(['bpost_shipping', 'bpost_services']);

    /** @var \Drupal\commerce_bpost\BpostServiceInterface $service_plugin */
    $service_plugin = $this->getShippingMethod()->getPlugin()->instantiateServicePlugin($selected_service);
    $context = [
      'order' => $this->order,
    ];

    $form_state->set('bpost_service_checkout_pane_context', $context);
    $service_plugin->submitCheckoutPaneForm($pane_form, $form_state, $complete_form);
  }

  /**
   * Returns the shipping service that is on the current order.
   *
   * This service can be overridden by the Ajax selection.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *
   * @return mixed|string|null
   */
  protected function getSelectedShippingService(FormStateInterface $form_state) {
    $selected_service = $form_state->getValue(['bpost_shipping', 'bpost_services']);
    if ($selected_service) {
      // Ajax choice gets priority.
      return $selected_service;
    }

    // Check if we don't have the selected service in storage.
    $selected_service = $form_state->get('bpost_service');
    if ($selected_service) {
      return $selected_service;
    }

    $existing_shipments = $this->order->shipments->referencedEntities();
    if (!$existing_shipments) {
      return NULL;
    }

    /** @var \Drupal\commerce_shipping\Entity\ShipmentInterface $shipment */
    $shipment = reset($existing_shipments);
    return $shipment->getShippingService();
  }

  /**
   * Returns the shipping method entity that uses the BPost plugin.
   *
   * @return \Drupal\commerce_shipping\Entity\ShippingMethodInterface|null
   */
  protected function getShippingMethod() {
    // First, check if there are any shipping methods configured to use the Bpost
    // shipping services.
    $shipping_methods = $this->entityTypeManager->getStorage('commerce_shipping_method')->loadByProperties(['plugin__target_plugin_id' => 'bpost']);
    if (!$shipping_methods) {
      return NULL;
    }

    if (count($shipping_methods) !== 1) {
      // @todo handle the case where there are multiple methods.
      return NULL;
    }

    return reset($shipping_methods);
  }


}
