<?php

namespace Drupal\commerce_bpost\EventSubscriber;

use Drupal\commerce_bpost\Exception\BpostCheckoutException;
use Drupal\commerce_bpost\Exception\BpostException;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\Messenger;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\Core\Utility\Error;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Subscribes to exceptions thrown by the module and not caught anywhere else.
 */
class BpostExceptionSubscriber implements EventSubscriberInterface {

  use StringTranslationTrait;

  /**
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * BpostExceptionSubscriber constructor.
   *
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerChannelFactory
   */
  public function __construct(LoggerChannelFactoryInterface $loggerChannelFactory, Messenger $messenger) {
    $this->logger = $loggerChannelFactory->get('commerce_bpost');
    $this->messenger = $messenger;
  }

  /**
   * Handles exceptions for this subscriber.
   *
   * @param \Symfony\Component\HttpKernel\Event\ExceptionEvent $event
   *   The event to process.
   */
  public function onException(ExceptionEvent $event) {
    $exception = $event->getThrowable();
    if (!$exception instanceof BpostException) {
      $exception = $exception->getPrevious();
      if (!$exception instanceof BpostException) {
        return;
      }
    }

    // For exceptions that happen during a checkout, we want to redirect the
    // user back to the review process. When this exception is thrown, the
    // transaction is rolled back so the order doesn't get updated (placed).
    if ($exception instanceof BpostCheckoutException) {
      $this->logException($exception);
      $this->messenger->addError($this->t('There was a problem with placing the order. Please contact the site administrator.'));
      $order = $exception->getOrder();
      $url = Url::fromRoute('commerce_checkout.form', ['commerce_order' => $order->id(), 'step' => 'review'])->toString();
      $response = new RedirectResponse($url);
      $event->setResponse($response);
      return;
    }

  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events[KernelEvents::EXCEPTION][] = ['onException'];
    return $events;
  }

  /**
   * Logs the exception message and values.
   *
   * @param \Drupal\commerce_bpost\Exception\BpostException $exception
   */
  protected function logException(BpostException $exception) {
    $error = Error::renderExceptionSafe($exception);
    $this->logger->error($error);
  }

}
