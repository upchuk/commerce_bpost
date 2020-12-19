<?php

namespace Drupal\commerce_bpost_pickup_test;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;

/**
 * Overrides the Pickup point manager service.
 */
class CommerceBpostPickupTestServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container) {
    $definition = $container->getDefinition('commerce_bpost_pickup.points_manager');
    $definition->setClass(TestPickupPointManager::class);
  }
}