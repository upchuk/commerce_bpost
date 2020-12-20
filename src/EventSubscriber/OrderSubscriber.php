<?php

namespace Drupal\commerce_bpost\EventSubscriber;

use Bpost\BpostApiClient\Bpost\Order;
use Bpost\BpostApiClient\Bpost\Order\Line;
use Drupal\commerce_bpost\Exception\BpostCheckoutException;
use Drupal\commerce_shipping\Entity\ShipmentInterface;
use Drupal\commerce_shipping\ShippingOrderManagerInterface;
use Drupal\state_machine\Event\WorkflowTransitionEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Subscribes to the order creation and creates the BPost shipping order.
 */
class OrderSubscriber implements EventSubscriberInterface {

  /**
   * The shipping order manager.
   *
   * @var \Drupal\commerce_shipping\ShippingOrderManagerInterface
   */
  protected $shippingOrderManager;

  /**
   * Constructs a new OrderSubscriber object.
   *
   * @param \Drupal\commerce_shipping\ShippingOrderManagerInterface $shipping_order_manager
   *   The shipping order manager.
   */
  public function __construct(ShippingOrderManagerInterface $shipping_order_manager) {
    $this->shippingOrderManager = $shipping_order_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      'commerce_order.place.post_transition' => ['onPlace'],
    ];
  }

  /**
   * Creates the BPost shipping manager order when an order is placed.
   *
   * @param \Drupal\state_machine\Event\WorkflowTransitionEvent $event
   *   The transition event.
   */
  public function onPlace(WorkflowTransitionEvent $event) {
    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = $event->getEntity();
    $to_state = $event->getTransition()->getToState();
    // We are only interested in orders that are set to be fulfilled and have
    // shipments.
    if ($to_state->getId() != 'fulfillment' || !$this->shippingOrderManager->hasShipments($order)) {
      return;
    }

    /** @var \Drupal\commerce_shipping\Entity\ShipmentInterface[] $shipments */
    $shipments = $order->get('shipments')->referencedEntities();
    // Normally, there should only be 1 shipment, but in case there are multiple
    // we create an BPost order for each.
    foreach ($shipments as $shipment) {
      $this->createBpostOrderForShipment($shipment);
    }
  }

  /**
   * Creates a BPost order for a given shipment.
   *
   * @param \Drupal\commerce_shipping\Entity\ShipmentInterface $shipment
   *   The shipment.
   */
  public function createBpostOrderForShipment(ShipmentInterface $shipment) {
    $shipping_method = $shipment->getShippingMethod();
    if ($shipping_method->getPlugin()->getPluginId() !== 'bpost') {
      // We only care about the BPost shipments.
      return;
    }

    /** @var \Drupal\commerce_bpost\BpostServiceInterface $service */
    $service = $shipping_method->getPlugin()->instantiateServicePlugin($shipment->getShippingService());

    /** @var \Bpost\BpostApiClient\Bpost $client */
    $client = $shipping_method->getPlugin()->getBpostClient();

    $orderReference = 'WEYRICH__DRUPAL__' . $shipment->getOrder()->getOrderNumber();
    $order = new Order($orderReference);

    foreach ($shipment->getItems() as $item) {
      $order->addLine(
        new Line($item->getTitle(), (int) $item->getQuantity())
      );
    }

    try {
      // There can be data validation errors in the preparation of the box so
      // we need to catch these.
      $box = $service->prepareDeliveryBox($shipment);
    }
    catch (\Exception $e) {
      $exception = new BpostCheckoutException($e->getMessage());
      $exception->setOrder($shipment->getOrder());
      throw $exception;
    }

    $order->addBox($box);
    $configuration = $shipping_method->getPlugin()->getConfiguration();
    $create = (bool) $configuration['api']['remote_order_creation'];
    if ($create) {
      try {
        $client->createOrReplaceOrder($order);
      }
      catch (\Exception $e) {
        $exception = new BpostCheckoutException($e->getMessage());
        $exception->setOrder($shipment->getOrder());
        throw $exception;
      }
    }
  }

}
