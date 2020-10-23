<?php

namespace Drupal\commerce_bpost;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;

/**
 * BPost service plugin manager.
 */
class BpostServicePluginManager extends DefaultPluginManager {

  /**
   * Constructs BpostServicePluginManager object.
   *
   * @param \Traversable $namespaces
   *   An object that implements \Traversable which contains the root paths
   *   keyed by the corresponding namespace to look for plugin implementations.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache_backend
   *   Cache backend instance to use.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler to invoke the alter hook with.
   */
  public function __construct(\Traversable $namespaces, CacheBackendInterface $cache_backend, ModuleHandlerInterface $module_handler) {
    parent::__construct(
      'Plugin/BpostService',
      $namespaces,
      $module_handler,
      'Drupal\commerce_bpost\BpostServiceInterface',
      'Drupal\commerce_bpost\Annotation\BpostService'
    );
    $this->alterInfo('bpost_service_info');
    $this->setCacheBackend($cache_backend, 'bpost_service_plugins');
  }

}
