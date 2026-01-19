<?php

namespace Gerencianet\Magento2\Model\Payment;

use DateTime;
use Exception;
use Throwable;
use Efi\EfiPay;
use Saade\Cep\Cep;
use Gerencianet\Magento2\Helper\Data as GerencianetHelper;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Sales\Model\Order;
use Magento\Catalog\Model\Product;
use Magento\Payment\Model\Method\AbstractMethod;
use Magento\Payment\Helper\Data;
use Magento\Payment\Model\Method\Logger;
use Magento\Payment\Model\InfoInterface;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use Magento\Framework\Api\ExtensionAttributesFactory;
use Magento\Framework\Api\AttributeValueFactory;
use Magento\Framework\DataObject;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Checkout\Model\Session;

class Billet extends AbstractMethod
{
  protected $_code = 'gerencianet_boleto';

  protected $_helperData;

  protected $_storeMagerInterface;

  protected $_checkoutSession;

  public function __construct(
    Context $context,
    Registry $registry,
    ExtensionAttributesFactory $extensionFactory,
    AttributeValueFactory $customAttributeFactory,
    Data $paymentData,
    ScopeConfigInterface $scopeConfig,
    Logger $logger,
    GerencianetHelper $helperData,
    StoreManagerInterface $storeManager,
    Session $checkoutSession,
    AbstractResource $resource = null,
    AbstractDb $resourceCollection = null,
    array $data = []
  ) {
    parent::__construct(
      $context,
      $registry,
      $extensionFactory,
      $customAttributeFactory,
      $paymentData,
      $scopeConfig,
      $logger,
      $resource,
      $resourceCollection,
      $data
    );

    $this->_helperData = $helperData;
    $this->_storeMagerInterface = $storeManager;
    $this->_checkoutSession = $checkoutSession;
  }

  public function order(InfoInterface $payment, $amount)
  {
    $orderIncrementId = null;

    try {
      $paymentInfo = $payment->getAdditionalInformation();
      $days = $this->_scopeConfig->getValue('payment/gerencianet_boleto/validade');
      $date = new DateTime("+$days days");

      /** @var Order */
      $order = $payment->getOrder();
      $orderIncrementId = $order ? $order->getIncrementId() : null;

      $billingaddress = $order->getBillingAddress();

      $options = $this->_helperData->getOptions();
      $data = [];

      $i = 0;
      $items = $order->getAllItems();

      /** @var Product */
      foreach ($items as $item) {
        if ($item->getProductType() != 'configurable') {
          if ($item->getPrice() == 0) {
            $parentItem = $item->getParentItem();
            $price = $parentItem->getPrice();
          } else {
            $price = $item->getPrice();
          }

          $data['items'][$i]['name'] = $item->getName();
          $data['items'][$i]['value'] = $price * 100;
          $data['items'][$i]['amount'] = $item->getQtyOrdered();
          $i++;
        }
      }

      $shippingAddress = $order->getShippingAddress();
      $shippingAmount = (float) $order->getShippingAmount();

      if ($shippingAddress && $shippingAmount > 0) {
        $data['shippings'][0]['name'] = $order->getShippingDescription() ?: 'Frete';
        $data['shippings'][0]['value'] = $shippingAmount * 100;
      }


      $data['metadata']['notification_url'] = $this->_storeMagerInterface->getStore()->getBaseUrl() . 'gerencianet/notification/updatestatus';

      $data['payment']['banking_billet']['customer']['name'] = $order->getCustomerFirstname() . ' ' . $order->getCustomerLastname();

      if (($paymentInfo['documentType'] ?? null) === "CPF") {
        $data['payment']['banking_billet']['customer']['cpf'] = $paymentInfo['cpfCustomer'] ?? null;
      } elseif (($paymentInfo['documentType'] ?? null) === "CNPJ") {
        $data['payment']['banking_billet']['customer']['juridical_person']['corporate_name'] = $paymentInfo['companyName'] ?? null;
        $data['payment']['banking_billet']['customer']['juridical_person']['cnpj'] = $paymentInfo['cpfCustomer'] ?? null;
      }

      $billingAddPhone = $this->formatPhone($billingaddress->getTelephone());
      $data['payment']['banking_billet']['customer']['phone_number'] = $billingAddPhone;
      $data['payment']['banking_billet']['customer']['email'] = $billingaddress->getEmail();

      try {
        $zipcodeRaw = (string) $billingaddress->getPostcode();
        $zipcode = preg_replace('/\D/', '', $zipcodeRaw);

        $streetLines = $billingaddress->getStreet() ?? [];
        $this->_helperData->logger('Endereço do cliente (street lines): ' . json_encode($streetLines));

        $houseNumber = $this->extractHouseNumberFromStreet($streetLines);

        $cepData = null;
        if (!empty($zipcode)) {
          $cepData = Cep::get($zipcode);
        }

        $streetName = !empty($cepData?->street) ? $cepData->street : ($streetLines[0] ?? null);
        $neighborhood = !empty($cepData?->neighborhood) ? $cepData->neighborhood : ($streetLines[2] ?? null);
        $city = !empty($cepData?->city) ? $cepData->city : $billingaddress->getCity();
        $state = !empty($cepData?->state) ? $cepData->state : $billingaddress->getRegionCode();
        $normalizedZipcode = !empty($cepData?->cep) ? preg_replace('/\D/', '', (string) $cepData->cep) : $zipcode;
        $complement = $streetLines[3] ?? null;

        if (empty($streetName) || empty($houseNumber) || empty($neighborhood) || empty($city) || empty($state) || empty($normalizedZipcode)) {
          throw new Exception("Erro, por favor verifique seus campos de endereço!", 1);
        }

        $data['payment']['banking_billet']['customer']['address']['street'] = $streetName;
        $data['payment']['banking_billet']['customer']['address']['number'] = $houseNumber;
        $data['payment']['banking_billet']['customer']['address']['neighborhood'] = $neighborhood;
        $data['payment']['banking_billet']['customer']['address']['city'] = $city;
        $data['payment']['banking_billet']['customer']['address']['state'] = $state;
        $data['payment']['banking_billet']['customer']['address']['zipcode'] = $normalizedZipcode;

        if (!empty($complement)) {
          $data['payment']['banking_billet']['customer']['address']['complement'] = $complement;
        }
      } catch (Throwable $e) {
        $this->_helperData->logger(json_encode([
          'scope' => 'billet_address',
          'order_id' => $orderIncrementId,
          'error' => $e->getMessage(),
          'file' => $e->getFile() . ':' . $e->getLine(),
          'trace' => $e->getTraceAsString()
        ]));
        throw new Exception("Erro, por favor verifique seus campos de endereço!", 1);
      }

      $data['payment']['banking_billet']['expire_at'] = $date->format('Y-m-d');

      $discountValue = str_replace("-", "", $order->getDiscountAmount());
      if ($discountValue > 0) {
        $data['payment']['banking_billet']['discount']['type'] = 'currency';
        $data['payment']['banking_billet']['discount']['value'] = $discountValue * 100;
      }

      $message = $this->_helperData->getBilletInstructions();
      if ($message !== "") {
        $data['payment']['banking_billet']['message'] = $message;
      }

      $billetConfig = $this->_helperData->getBilletSettings();
      if (($billetConfig['fine'] ?? "") !== "") {
        $data['payment']['banking_billet']['configurations']['fine'] = $billetConfig['fine'];
      }
      if (($billetConfig['interest'] ?? "") !== "") {
        $data['payment']['banking_billet']['configurations']['interest'] = $billetConfig['interest'];
      }

      $api = new EfiPay($options);

      try {
        $payCharge = $api->createOneStepCharge([], $data);
      } catch (Throwable $e) {
        $this->_helperData->logger(json_encode([
          'scope' => 'billet_create_charge',
          'order_id' => $orderIncrementId,
          'error' => $e->getMessage(),
          'file' => $e->getFile() . ':' . $e->getLine(),
          'trace' => $e->getTraceAsString()
        ]));
        throw new Exception('Erro ao criar a cobrança. Verifique o log para mais detalhes.', 1);
      }

      $order->setCustomerTaxvat($paymentInfo['cpfCustomer'] ?? null);
      $order->setGerencianetCodigoDeBarras($payCharge['data']['barcode'] ?? null);
      $order->setGerencianetTransactionId($payCharge['data']['charge_id'] ?? null);
      $order->setGerencianetUrlBoleto($payCharge['data']['pdf']['charge'] ?? null);
    } catch (Throwable $e) {
      $this->_helperData->logger(json_encode([
        'scope' => 'billet_order',
        'order_id' => $orderIncrementId,
        'error' => $e->getMessage(),
        'file' => $e->getFile() . ':' . $e->getLine(),
        'trace' => $e->getTraceAsString()
      ]));
      throw new LocalizedException(__($e->getMessage()));
    }
  }

  public function assignData(DataObject $data)
  {
    $info = $this->getInfoInstance();
    $info->setAdditionalInformation('cpfCustomer', $data['additional_data']['cpfCustomer'] ?? null);
    $info->setAdditionalInformation('companyName', $data['additional_data']['companyName'] ?? null);
    $info->setAdditionalInformation('documentType', $data['additional_data']['documentType'] ?? null);
    return $this;
  }

  public function isAvailable(CartInterface $quote = null)
  {
    $total = $this->_checkoutSession->getQuote()->getGrandTotal();
    return $this->_helperData->isBilletActive();
  }

  public function formatPhone($phone)
  {
    $formatedPhone = preg_replace('/[^0-9]/', '', $phone);
    $matches = [];

    if (strlen($formatedPhone) == 13) {
      preg_match('/^([0-9]{2})([0-9]{2})([0-9]{4,5})([0-9]{4})$/', $formatedPhone, $matches);
      if ($matches) {
        return '+' . $matches[1] . ' (' . $matches[2] . ')' . $matches[3] . '-' . $matches[4];
      }
    } else {
      preg_match('/^([0-9]{2})([0-9]{4,5})([0-9]{4})$/', $formatedPhone, $matches);
      if ($matches) {
        return $matches[1] . $matches[2] . $matches[3];
      }
    }

    return $formatedPhone;
  }

  private function extractHouseNumberFromStreet(array $streetLines): string
  {
    foreach ($streetLines as $line) {
      $value = trim((string) $line);
      if ($value !== '' && preg_match('/^\d+$/', $value)) {
        return $value;
      }
    }

    $fallback = trim((string) ($streetLines[1] ?? ''));
    if ($fallback !== '' && preg_match('/^\d+$/', $fallback)) {
      return $fallback;
    }

    throw new Exception('Número do endereço inválido: informe uma linha contendo somente o número da casa.');
  }
}
