<?php

namespace Drupal\commerce_sepordeh\PluginForm\OffsiteRedirect;

use Drupal\commerce_payment\PluginForm\PaymentOffsiteForm as BasePaymentOffsiteForm;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\commerce_payment\Exception\InvalidResponseException;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Client;


class SepordehOffsiteForm extends BasePaymentOffsiteForm implements ContainerInjectionInterface {

  use StringTranslationTrait;

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

  public function __construct(EntityTypeManagerInterface $entity_type_manager, Client $http_client, MessengerInterface $messenger) {
    $this->paymentStorage = $entity_type_manager->getStorage('commerce_payment');
    $this->httpClient = $http_client;
    $this->messenger = $messenger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('http_client'),
      $container->get('messenger')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    $payment = $this->entity;
    $order = $payment->getOrder();
    $order_id = $order->id();
    $payment_gateway = $payment->getPaymentGateway();

    /** @var \Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayInterface $payment_gateway_plugin */
    $payment_gateway_plugin = $payment_gateway->getPlugin();
    $gateway_configuration = $payment_gateway_plugin->getConfiguration();

    $merchant = $gateway_configuration['merchant'];

    $amount = (int) $payment->getAmount()->getNumber();

    if ($payment->getAmount()->getCurrencyCode() == 'IRR') {
      // Considers all of currency codes as IRR except TMN (Iranian Toman, an unofficial currency code)
      // If the currency code is 'TMN', converts Iranian Tomans to Iranian Rials by multiplying by 10.
      // This is due to accepting Iranian Rial as the currency code by the gateway.
      $amount = $amount/10;
    }

    $mode = $gateway_configuration['mode'];

    // Customer information
    $name = '';
    $billing_profile = $order->getBillingProfile();
    if ($billing_profile->hasField('address')) {
      /** @var \Drupal\address\AddressInterface|null $address */
      $address = !$billing_profile->get('address')->isEmpty() ? $billing_profile->get('address')->first() : NULL;
      if($address) {
        $name = $address->getGivenName() . ' '. $address->getFamilyName();
      }
    }
    $mail = $order->getEmail();

    $url = 'https://sepordeh.com/merchant/invoices/add';
    $params = [
      'merchant' => $merchant,
      'amount' => $amount,
      'callback' => $form['#return_url'],
      'description' => t('Order number #') . $order_id,
      'orderId' => $order_id
    ];
    try {
      $response = $this->httpClient->request('POST', $url, [
        'form_params' => $params,'verify' => false
      ]);
      $response_content = $response->getBody()->getContents();
      $response_content = json_decode($response_content);
	  
	  if(intval($response_content->status) == 200)
	  {
		  $link = "https://sepordeh.com/merchant/invoices/pay/automatic:true/id:".$response_content->information->invoice_id;

		  // Create a new payment but with state 'Authorization' not completed.
		  // On payment return, if everything is ok, the state of this new payment will be converted to 'Completed'.
		  $new_payment = $this->paymentStorage->create([
			'state' => 'authorization',
			'amount' => $order->getTotalPrice(),
			'payment_gateway' => $payment_gateway,
			'order_id' => $order->id(),
			'remote_id' => $response_content->information->invoice_id,
		  ]);
		  $new_payment->save();
		  return $this->buildRedirectForm($form, $form_state, $link, [], SepordehOffsiteForm::REDIRECT_POST);
	  }else{
		$this->messenger->addError($response_content->message);
        throw new InvalidResponseException("commerce_sepordeh: " . $response_content->message);
	  }
    } catch (RequestException $e) {
        throw new InvalidResponseException("commerce_sepordeh: " . $e->getMessage());
    }
  }

}
