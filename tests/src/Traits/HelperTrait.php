<?php

namespace Drupal\Tests\commerce_bpost\Traits;

use Behat\Mink\Element\NodeElement;

/**
 * Contains helper methods for the existing site tests.
 */
trait HelperTrait {

  /**
   * Retrieves the options of a select field.
   *
   * @param \Behat\Mink\Element\NodeElement $select
   *   The select element.
   *
   * @return array
   *   The options text keyed by option value.
   */
  protected function getSelectOptions(NodeElement $select): array {
    $options = [];
    foreach ($select->findAll('xpath', '//option') as $element) {
      /** @var \Behat\Mink\Element\NodeElement $element */
      $options[$element->getValue()] = trim($element->getText());
    }

    return $options;
  }

}