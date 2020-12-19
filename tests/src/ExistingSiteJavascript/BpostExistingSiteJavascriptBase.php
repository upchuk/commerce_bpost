<?php

namespace Drupal\Tests\commerce_bpost\ExistingSiteJavascript;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Database\Database;
use Drupal\Core\Site\Settings;
use Drupal\Tests\commerce_bpost\Traits\EntityManagerTrait;
use Drupal\Tests\commerce_bpost\Traits\ExistingSiteCleanupTrait;
use Drupal\Tests\commerce_bpost\Traits\HelperTrait;
use weitzman\DrupalTestTraits\ExistingSiteSelenium2DriverTestBase;

/**
 * Base class for existing site JS tests.
 */
class BpostExistingSiteJavascriptBase extends ExistingSiteSelenium2DriverTestBase {

  use EntityManagerTrait;
  use ExistingSiteCleanupTrait;
  use HelperTrait;

  /**
   * Whether to restore the test database.
   *
   * @var bool
   */
  protected $refreshDatabase = FALSE;

  /**
   * The extra modules to install.
   *
   * @var array
   */
  protected $modules = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    if ($this->refreshDatabase) {
      if (isset($GLOBALS['conf']['container_service_providers']['InstallerServiceProvider'])) {
        unset($GLOBALS['conf']['container_service_providers']['InstallerServiceProvider']);
      }
      $directory = DRUPAL_ROOT . '/sites/default';
      file_put_contents($directory . '/settings.testing.php', "<?php\n\$databases['default']['default']['database'] = 'drupal_test';", FILE_APPEND);
      $info = Database::getConnectionInfo('default');
      $info['default']['database'] = Settings::get('test_database_name');
      Database::closeConnection('default');
      Database::removeConnection('default');
      Database::addConnectionInfo('default', 'default', $info['default']);
      $this->restoreDatabase();
      // Rebuild the test environment container.
      $this->kernel->rebuildContainer();
      if ($this->modules) {
        $this->container->get('module_installer')->install($this->modules);
      }
      // We need to force truncate the container cache table so the "prod" env
      // container gets rebuilt.
      if (isset($GLOBALS['conf']['container_service_providers']['InstallerServiceProvider'])) {
        unset($GLOBALS['conf']['container_service_providers']['InstallerServiceProvider']);
      }
      $this->kernel->rebuildContainer();
      \Drupal::database()->truncate('cache_container')->execute();
    }

    $this->cleanupEntityType('profile');
    $this->cleanupEntityType('commerce_order');
    $this->cleanupEntityType('commerce_order_item');
  }

  /**
   * {@inheritdoc}
   */
  public function tearDown(): void {
    $this->deleteExtraEntities();

    parent::tearDown();

    if ($this->refreshDatabase) {
      $directory = DRUPAL_ROOT . '/sites/default';
      unlink($directory . '/settings.testing.php');
      $this->removeDatabase();
    }
  }

  /**
   * Restores database structure and contents of test site.
   */
  protected function restoreDatabase() {
    $connection_info = Database::getConnectionInfo('default');

    $user = $connection_info['default']['username'];
    $password = $connection_info['default']['password'];
    $host = $connection_info['default']['host'];
    $location = Settings::get('test_database_location');
    $db = Settings::get('test_database_name');

    switch ($connection_info['default']['driver']) {
      case 'mysql':
        exec("echo 'drop database if exists $db;' | mysql -h $host -u$user -p$password");
        exec("echo 'create database $db;' | mysql -h $host -u$user -p$password", $output);
        exec("mysql -h $host -u$user -p$password $db < $location/test.sql;");
        break;

      default:
        throw new \LogicException('This database driver is not supported yet.');
    }

    foreach (Cache::getBins() as $service_id => $cache_backend) {
      $cache_backend->deleteAll();
    }
  }

  /**
   * Removes the test database.
   */
  protected function removeDatabase() {
    $connection_info = Database::getConnectionInfo('default');

    $user = $connection_info['default']['username'];
    $password = $connection_info['default']['password'];
    $host = $connection_info['default']['host'];
    $db = Settings::get('test_database_name');

    switch ($connection_info['default']['driver']) {
      case 'mysql':
        exec("echo 'drop database if exists $db;' | mysql -h $host -u$user -p$password");
        break;

      default:
        throw new \LogicException('This database driver is not supported yet.');
    }
  }

}
