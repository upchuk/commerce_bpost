<?php

namespace Drupal\commerce_bpost;

use Drupal\commerce\Plugin\Commerce\Condition\ParentEntityAwareInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_shipping\Entity\Shipment;
use Drupal\commerce_shipping\Entity\ShipmentInterface;
use Drupal\commerce_shipping\Plugin\Commerce\ShippingMethod\ShippingMethodInterface;
use Drupal\Component\Plugin\ConfigurableInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\PluginFormInterface;
use Drupal\profile\Entity\ProfileInterface;

/**
 * Interface for BPost service plugins.
 *
 * BPost service wrap common functionality specific to each individual service
 * BPost offers and that is exposed to Drupal.
 *
 * These are the services that are exposed to the user on the shipping pane.
 *
 * These services map to the services defined in the plugin but which can be
 * configured on the ShippingMethod entity to be enabled/disabled.
 */
interface BpostServiceInterface extends ConfigurableInterface, PluginFormInterface, ParentEntityAwareInterface {

  /**
   * Returns the translated plugin label.
   *
   * @return string
   *   The translated title.
   */
  public function label();

  /**
   * Calculates rates for the given shipment.
   *
   * @param \Drupal\commerce_shipping\Entity\ShipmentInterface $shipment
   *   The shipment.
   *
   * @return \Drupal\commerce_shipping\ShippingRate[]
   *   The rates.
   */
  public function calculateRates(ShipmentInterface $shipment);

  /**
   * Builds elements for the checkout pane form specific to this service.
   *
   * @param array $pane_form
   *  The pane form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param array $complete_form
   *   The complete form.
   *
   * @return array
   *   The form elements.
   */
  public function buildCheckoutPaneForm(array $pane_form, FormStateInterface $form_state, array &$complete_form);

  /**
   * Runs validation on the checkout pane form elements.
   *
   * @param array $pane_form
   *  The pane form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param array $complete_form
   *   The complete form.
   */
  public function validateCheckoutPaneForm(array &$pane_form, FormStateInterface $form_state, array &$complete_form);

  /**
   * Submit callback for the checkout pane form elements.
   *
   * @param array $pane_form
   *  The pane form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param array $complete_form
   *   The complete form.
   */
  public function submitCheckoutPaneForm(array &$pane_form, FormStateInterface $form_state, array &$complete_form);

  /**
   * Builds a summary of the pane values.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return array
   *   A render array containing the summary of the pane values.
   */
  public function buildPaneSummary(OrderInterface $order);

  /**
   * Prepares the box object to be dispatched.
   *
   * @param ShipmentInterface $shipment
   *
   * @return \TijsVerkoyen\Bpost\Bpost\Order\Box
   */
  public function prepareDeliveryBox(ShipmentInterface $shipment);

}
