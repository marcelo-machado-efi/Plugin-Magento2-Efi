<?php

namespace Gerencianet\Magento2\Model\Payment;

use DateTime;
use Efi\EfiPay;
use Gerencianet\Magento2\Helper\Data as GerencianetHelper;
use Magento\Checkout\Model\Session;
use Magento\Framework\Api\AttributeValueFactory;
use Magento\Framework\Api\ExtensionAttributesFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use Magento\Payment\Helper\Data;
use Magento\Payment\Model\InfoInterface;
use Magento\Payment\Model\Method\AbstractMethod;
use Magento\Payment\Model\Method\Logger;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Sales\Model\Order;
use Magento\Store\Model\StoreManagerInterface;
use Saade\Cep\Cep;
use Throwable;

class Billet extends AbstractMethod
{
    /**
     * @var string
     */
    protected $_code = 'gerencianet_boleto';

    /**
     * @var GerencianetHelper
     */
    private GerencianetHelper $helperData;

    /**
     * @var StoreManagerInterface
     */
    private StoreManagerInterface $storeManager;

    /**
     * @var Session
     */
    private Session $checkoutSession;

    /**
     * @param Context $context
     * @param Registry $registry
     * @param ExtensionAttributesFactory $extensionFactory
     * @param AttributeValueFactory $customAttributeFactory
     * @param Data $paymentData
     * @param ScopeConfigInterface $scopeConfig
     * @param Logger $logger
     * @param GerencianetHelper $helperData
     * @param StoreManagerInterface $storeManager
     * @param Session $checkoutSession
     * @param AbstractResource|null $resource
     * @param AbstractDb|null $resourceCollection
     * @param array $data
     */
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
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
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

        $this->helperData = $helperData;
        $this->storeManager = $storeManager;
        $this->checkoutSession = $checkoutSession;
    }

    /**
     * @param InfoInterface $payment
     * @param float|int $amount
     * @return void
     * @throws LocalizedException
     */
    public function order(InfoInterface $payment, $amount): void
    {
        $orderIncrementId = null;

        try {
            $paymentInfo = (array) $payment->getAdditionalInformation();

            $days = (int) $this->_scopeConfig->getValue('payment/gerencianet_boleto/validade');
            $date = new DateTime('+' . $days . ' days');

            /** @var Order|null $order */
            $order = $payment->getOrder();
            $orderIncrementId = $order ? (string) $order->getIncrementId() : null;

            if (!$order) {
                throw new LocalizedException(__('Pedido inválido.'));
            }

            $billingAddress = $order->getBillingAddress();
            if (!$billingAddress) {
                throw new LocalizedException(__('Endereço de cobrança não encontrado.'));
            }

            $options = $this->helperData->getOptions();
            $data = [];

            $i = 0;
            foreach ($order->getAllItems() as $item) {
                if ((string) $item->getProductType() === 'configurable') {
                    continue;
                }

                $price = (float) $item->getPrice();
                if ($price == 0.0) {
                    $parentItem = $item->getParentItem();
                    $price = $parentItem ? (float) $parentItem->getPrice() : 0.0;
                }

                $data['items'][$i]['name'] = (string) $item->getName();
                $data['items'][$i]['value'] = (int) round($price * 100);
                $data['items'][$i]['amount'] = (int) $item->getQtyOrdered();
                $i++;
            }

            $shippingAddress = $order->getShippingAddress();
            $shippingAmount = (float) $order->getShippingAmount();

            if ($shippingAddress && $shippingAmount > 0) {
                $data['shippings'][0]['name'] = (string) ($order->getShippingDescription() ?: 'Frete');
                $data['shippings'][0]['value'] = (int) round($shippingAmount * 100);
            }

            $data['metadata']['notification_url'] = $this->storeManager->getStore()->getBaseUrl()
                . 'gerencianet/notification/updatestatus';

            $data['payment']['banking_billet']['customer']['name'] =
                (string) $order->getCustomerFirstname() . ' ' . (string) $order->getCustomerLastname();

            $documentType = (string) ($paymentInfo['documentType'] ?? '');
            if ($documentType === 'CPF') {
                $data['payment']['banking_billet']['customer']['cpf'] = $paymentInfo['cpfCustomer'] ?? null;
            } elseif ($documentType === 'CNPJ') {
                $data['payment']['banking_billet']['customer']['juridical_person']['corporate_name'] =
                    $paymentInfo['companyName'] ?? null;
                $data['payment']['banking_billet']['customer']['juridical_person']['cnpj'] =
                    $paymentInfo['cpfCustomer'] ?? null;
            }

            $billingAddPhone = $this->formatPhone((string) $billingAddress->getTelephone());
            $data['payment']['banking_billet']['customer']['phone_number'] = $billingAddPhone;
            $data['payment']['banking_billet']['customer']['email'] = (string) $billingAddress->getEmail();

            try {
                $zipcodeRaw = (string) $billingAddress->getPostcode();
                $zipcode = preg_replace('/\D/', '', $zipcodeRaw) ?: '';

                $streetLines = $billingAddress->getStreet() ?? [];

                $houseNumber = $this->extractHouseNumberFromStreet($streetLines);

                $cepData = null;
                if ($zipcode !== '') {
                    $cepData = Cep::get($zipcode);
                }

                $streetName = !empty($cepData?->street) ? $cepData->street : ($streetLines[0] ?? null);
                $neighborhood = !empty($cepData?->neighborhood) ? $cepData->neighborhood : ($streetLines[2] ?? null);
                $city = !empty($cepData?->city) ? $cepData->city : (string) $billingAddress->getCity();
                $state = !empty($cepData?->state) ? $cepData->state : (string) $billingAddress->getRegionCode();
                $normalizedZipcode = !empty($cepData?->cep)
                    ? (preg_replace('/\D/', '', (string) $cepData->cep) ?: '')
                    : $zipcode;

                $complement = $streetLines[3] ?? null;

                if (
                    empty($streetName)
                    || empty($houseNumber)
                    || empty($neighborhood)
                    || empty($city)
                    || empty($state)
                    || empty($normalizedZipcode)
                ) {
                    throw new LocalizedException(__('Erro, por favor verifique seus campos de endereço!'));
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
                $this->helperData->logger(json_encode([
                    'scope' => 'billet_address',
                    'order_id' => $orderIncrementId,
                    'error' => $e->getMessage(),
                    'file' => $e->getFile() . ':' . $e->getLine(),
                ]));

                throw new LocalizedException(__('Erro, por favor verifique seus campos de endereço!'));
            }

            $data['payment']['banking_billet']['expire_at'] = $date->format('Y-m-d');

            $discountValue = (string) $order->getDiscountAmount();
            $discountValue = str_replace('-', '', $discountValue);

            if ((float) $discountValue > 0) {
                $data['payment']['banking_billet']['discount']['type'] = 'currency';
                $data['payment']['banking_billet']['discount']['value'] =
                    (int) round(((float) $discountValue) * 100);
            }

            $message = (string) $this->helperData->getBilletInstructions();
            if ($message !== '') {
                $data['payment']['banking_billet']['message'] = $message;
            }

            $billetConfig = (array) $this->helperData->getBilletSettings();
            if (($billetConfig['fine'] ?? '') !== '') {
                $data['payment']['banking_billet']['configurations']['fine'] = $billetConfig['fine'];
            }
            if (($billetConfig['interest'] ?? '') !== '') {
                $data['payment']['banking_billet']['configurations']['interest'] = $billetConfig['interest'];
            }

            $api = new EfiPay($options);

            try {
                $payCharge = $api->createOneStepCharge([], $data);
            } catch (Throwable $e) {
                $this->helperData->logger(json_encode([
                    'scope' => 'billet_create_charge',
                    'order_id' => $orderIncrementId,
                    'error' => $e->getMessage(),
                    'file' => $e->getFile() . ':' . $e->getLine(),
                ]));

                throw new LocalizedException(__('Erro ao criar a cobrança. Verifique o log para mais detalhes.'));
            }

            $order->setCustomerTaxvat($paymentInfo['cpfCustomer'] ?? null);
            $order->setGerencianetCodigoDeBarras($payCharge['data']['barcode'] ?? null);
            $order->setGerencianetTransactionId($payCharge['data']['charge_id'] ?? null);
            $order->setGerencianetUrlBoleto($payCharge['data']['pdf']['charge'] ?? null);
        } catch (Throwable $e) {
            $this->helperData->logger(json_encode([
                'scope' => 'billet_order',
                'order_id' => $orderIncrementId,
                'error' => $e->getMessage(),
                'file' => $e->getFile() . ':' . $e->getLine(),
            ]));

            throw new LocalizedException(__($e->getMessage()));
        }
    }

    /**
     * @param DataObject $data
     * @return $this
     */
    public function assignData(DataObject $data)
    {
        $info = $this->getInfoInstance();

        $additionalData = (array) ($data['additional_data'] ?? []);
        $info->setAdditionalInformation('cpfCustomer', $additionalData['cpfCustomer'] ?? null);
        $info->setAdditionalInformation('companyName', $additionalData['companyName'] ?? null);
        $info->setAdditionalInformation('documentType', $additionalData['documentType'] ?? null);

        return $this;
    }

    /**
     * @param CartInterface|null $quote
     * @return bool
     */
    public function isAvailable(?CartInterface $quote = null): bool
    {
        return (bool) $this->helperData->isBilletActive();
    }

    /**
     * @param string $phone
     * @return string
     */
    public function formatPhone(string $phone): string
    {
        $formattedPhone = preg_replace('/[^0-9]/', '', $phone) ?: '';
        $matches = [];

        if (strlen($formattedPhone) === 13) {
            preg_match('/^([0-9]{2})([0-9]{2})([0-9]{4,5})([0-9]{4})$/', $formattedPhone, $matches);
            if (!empty($matches)) {
                return '+' . $matches[1] . ' (' . $matches[2] . ')' . $matches[3] . '-' . $matches[4];
            }
        } else {
            preg_match('/^([0-9]{2})([0-9]{4,5})([0-9]{4})$/', $formattedPhone, $matches);
            if (!empty($matches)) {
                return $matches[1] . $matches[2] . $matches[3];
            }
        }

        return $formattedPhone;
    }

    /**
     * @param array $streetLines
     * @return string
     * @throws LocalizedException
     */
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

        throw new LocalizedException(__('Número do endereço inválido: informe uma linha contendo somente o número da casa.'));
    }
}
