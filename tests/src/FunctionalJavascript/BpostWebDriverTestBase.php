<?php

namespace Drupal\Tests\commerce_bpost\FunctionalJavascript;

use Drupal\commerce_store\Entity\Store;
use Drupal\Tests\commerce\FunctionalJavascript\CommerceWebDriverTestBase;
use Drupal\Tests\commerce_bpost\Traits\EntityManagerTrait;
use Drupal\Tests\commerce_bpost\Traits\HelperTrait;

/**
 * Base class for BPost web driver tests.
 */
class BpostWebDriverTestBase extends CommerceWebDriverTestBase {

  use EntityManagerTrait;
  use HelperTrait;

  /**
   * {@inheritdoc}
   */
  public static $modules = [
    'commerce_bpost_test',
    'commerce_bpost_client_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // Delete the store the parent created.
    /** @var \Drupal\commerce_store\Entity\StoreInterface[] $stores */
    $stores = Store::loadMultiple();
    foreach ($stores as $store) {
      if ($store->label() !== 'Test store') {
        $store->delete();
        continue;
      }

      $store->setDefault(TRUE);
      $store->save();
    }
  }

}
