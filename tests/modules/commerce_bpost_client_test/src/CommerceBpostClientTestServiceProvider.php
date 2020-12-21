<?php

namespace Drupal\commerce_bpost_client_test;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;

/**
 * Overrides the BPost client service factory.
 */
class CommerceBpostClientTestServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container) {
    $definition = $container->getDefinition('commerce_bpost.client_factory');
    $definition->setClass(TestBpostClientFactory::class);
  }

}
