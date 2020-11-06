<?php

namespace Drupal\Tests\commerce_bpost\Kernel;

use Drupal\commerce_bpost\BpostServiceInterface;
use Drupal\Tests\commerce_shipping\Kernel\ShippingKernelTestBase;

/**
 * Tests the BPost services.
 *
 * @package Drupal\Tests\commerce_bpost\Kernel
 */
class BpostServiceTest extends ShippingKernelTestBase {

  /**
   * {@inheritdoc}
   */
  public static $modules = [
    'commerce_bpost',
    'commerce_bpost_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installConfig('commerce_bpost_test');
  }

  public function testExample() {
    $this->assertInstanceOf(BpostServiceInterface::class, $this->container->get('plugin.manager.bpost_service')->createInstance('home_delivery'));
  }
}