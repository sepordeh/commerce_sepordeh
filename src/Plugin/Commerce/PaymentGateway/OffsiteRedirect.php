<?php

namespace Drupal\commerce_sepordeh\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\commerce_payment\Exception\InvalidResponseException;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\PaymentMethodTypeManager;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\PaymentTypeManager;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Client;


/**
 * Provides the Off-site Redirect payment gateway.
 *
 * @CommercePaymentGateway(
 *   id = "sepordeh_offsite_redirect",
 *   label = "Sepordeh (Off-site redirect)",
 *   display_label = "Sepordeh",
 *   forms = {
 *     "offsite-payment" =
 *   "Drupal\commerce_sepordeh\PluginForm\OffsiteRedirect\SepordehOffsiteForm",
 *   },
 *   payment_method_types = {"credit_card"}
 * )
 */
class OffsiteRedirect extends OffsitePaymentGatewayBase {

  /**
   * The payment storage.
   *
   * @var \Drupal\commerce_payment\PaymentStorageInterface
   */
  protected $paymentStorage;

  /**
   * The http client.
   *
   * @var \GuzzleHttp\Client
   */
  protected $httpClient;

  /**
   * The messenger.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * OffsiteRedirect constructor.
   * @param array $configuration
   * @param $plugin_id
   * @param $plugin_definition
   * @param EntityTypeManagerInterface $entity_type_manager
   * @param PaymentTypeManager $payment_type_manager
   * @param PaymentMethodTypeManager $payment_method_type_manager
   * @param TimeInterface $time
   * @param Client $http_client
   * @param MessengerInterface $messenger
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, PaymentTypeManager $payment_type_manager, PaymentMethodTypeManager $payment_method_type_manager, TimeInterface $time, Client $http_client, MessengerInterface $messenger) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $entity_type_manager, $payment_type_manager, $payment_method_type_manager, $time);
    $this->paymentStorage = $entity_type_manager->getStorage('commerce_payment');
    $this->httpClient = $http_client;
    $this->messenger = $messenger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('plugin.manager.commerce_payment_type'),
      $container->get('plugin.manager.commerce_payment_method_type'),
      $container->get('datetime.time'),
      $container->get('http_client'),
      $container->get('messenger')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['merchant'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Merchant'),
      '#default_value' => $this->configuration['merchant'],
      '#description' => $this->t('You can obtain an Merchant from https://sepordeh.ir/panel'),
      '#required' => TRUE,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);
    if (!$form_state->getErrors()) {
      $values = $form_state->getValue($form['#parents']);
      $this->configuration['merchant'] = trim($values['merchant']);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function onReturn(OrderInterface $order, Request $request) {
    parent::onReturn($order, $request);

    $method = ($request->getMethod() == 'GET') ? 'query' : 'request';

    $authority = $request->{$method}->get('authority');
    $order_id = $request->{$method}->get('orderId');

    if ($order->id() != $order_id || empty($authority)) {
      throw new PaymentGatewayException('Abuse of transaction callback.');
    }

	$url = 'https://sepordeh.com/merchant/invoices/verify';
	$params = [
	  'merchant' => $this->configuration['merchant'],
	  'authority' => $authority
	];

	try {
		$response = $this->httpClient->request('POST', $url, [
			'form_params' => $params,'verify' => false
		]);

		$response_contents = $response->getBody()->getContents();
		$response_contents = json_decode($response_contents);
		
		if (intval($response_contents->status)==200) {
			$payment = $this->get_payment($response_contents->information->invoice_id,$order_id);
			if ($payment) {
				$payment->setState('completed');
				$payment->setRemoteState('invoice_id: ' . $response_contents->information->invoice_id . ' / status: ' . $response_contents->status . ' / card: ' . $response_contents->information->card);
				$payment->save();
			}else{
				throw new PaymentGatewayException($this->t("commerce_sepordeh: Paid but can not found order payment"));
			}
		}
		else {
			throw new PaymentGatewayException($this->t("commerce_sepordeh: Payment failed with message: %message", [
				'%message' => $response_contents->message,
			]));
		}
	} catch (RequestException $e) {
		throw new InvalidResponseException("commerce_sepordeh: " . $this->t('Payment failed. This is due to an error with http code: %http_code, error_code: %error_code and error_message: "@error_message" when accessing the inquiry endpoint: @url', [
			'%http_code' => $e->getCode(),
			'%error_code' => 400,
			'@error_message' => $response_contents->message,
			'@url' => $e->getRequest()->getUri(),
		]));
		throw new InvalidResponseException('commerce_sepordeh: ' . $e->getMessage());
	}
  }
  private function get_payment($remote_id,$order_id) {
    $payments = $this->paymentStorage->loadByProperties([
      'remote_id' => $remote_id,
      'order_id' => $order_id,
      'state' => 'authorization',
    ]);
    if (count($payments) == 1) {
      $payment_id = array_keys($payments)[0];
      /** @var \Drupal\commerce_payment\Entity\Payment $payment */
      $payment = $payments[$payment_id];

      return $payment;
    }
    else {
      return FALSE;
    }
  }
}
