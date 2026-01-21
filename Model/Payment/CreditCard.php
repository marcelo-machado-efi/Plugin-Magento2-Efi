<?php

namespace Gerencianet\Magento2\Model\Payment;

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
use Throwable;

class CreditCard extends AbstractMethod
{
    /**
     * @var string
     */
    protected $_code = 'gerencianet_cc';

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

        $this->checkoutSession = $checkoutSession;
        $this->helperData = $helperData;
        $this->storeManager = $storeManager;
    }

    /**
     * @param InfoInterface $payment
     * @param float|int $amount
     * @return void
     * @throws LocalizedException
     */
    public function order(InfoInterface $payment, $amount): void
    {
        try {
            $options = $this->helperData->getOptions();
            $paymentInfo = (array) $payment->getAdditionalInformation();

            /** @var Order|null $order */
            $order = $payment->getOrder();
            if (!$order) {
                throw new LocalizedException(__('Pedido inválido.'));
            }

            $billingAddress = $order->getBillingAddress();
            if (!$billingAddress) {
                throw new LocalizedException(__('Endereço de cobrança não encontrado.'));
            }

            $shippingAddress = $order->getShippingAddress();

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

            $data['metadata']['notification_url'] = $this->storeManager->getStore()->getBaseUrl()
                . 'gerencianet/notification/updatestatus';

            $shippingAmount = (float) $order->getShippingAmount();
            if ($shippingAddress && $shippingAmount > 0) {
                $shippingDescription = (string) $order->getShippingDescription();

                if ($shippingDescription === '' && method_exists($shippingAddress, 'getShippingDescription')) {
                    $shippingDescription = (string) $shippingAddress->getShippingDescription();
                }

                $data['shippings'][0]['name'] = $shippingDescription !== '' ? $shippingDescription : 'Frete';
                $data['shippings'][0]['value'] = (int) round($shippingAmount * 100);
            }

            $data['payment']['credit_card']['customer']['name'] =
                (string) $order->getCustomerFirstname() . ' ' . (string) $order->getCustomerLastname();
            $data['payment']['credit_card']['customer']['email'] = (string) $billingAddress->getEmail();

            $documentType = (string) ($paymentInfo['documentType'] ?? '');
            if ($documentType === 'CPF') {
                $data['payment']['credit_card']['customer']['cpf'] = $paymentInfo['cpfCustomer'] ?? null;
            } elseif ($documentType === 'CNPJ') {
                $data['payment']['credit_card']['customer']['juridical_person']['corporate_name'] =
                    $paymentInfo['companyName'] ?? null;
                $data['payment']['credit_card']['customer']['juridical_person']['cnpj'] =
                    $paymentInfo['cpfCustomer'] ?? null;
            }

            $billingAddPhone = $this->formatPhone((string) $billingAddress->getTelephone());
            $data['payment']['credit_card']['customer']['phone_number'] = $paymentInfo['phone'] ?? $billingAddPhone;

            $discountValue = (string) $order->getDiscountAmount();
            $discountValue = str_replace('-', '', $discountValue);

            if ((float) $discountValue > 0) {
                $data['payment']['credit_card']['discount']['type'] = 'currency';
                $data['payment']['credit_card']['discount']['value'] =
                    (int) round(((float) $discountValue) * 100);
            }

            $data['payment']['credit_card']['installments'] = (int) ($paymentInfo['installments'] ?? 1);
            $data['payment']['credit_card']['payment_token'] = $paymentInfo['cardHash'] ?? null;

            $api = new EfiPay($options);
            $payCharge = $api->createOneStepCharge([], $data);

            $order->setCustomerTaxvat($paymentInfo['cpfCustomer'] ?? null);
            $order->setGerencianetTransactionId($payCharge['data']['charge_id'] ?? null);
        } catch (Throwable $e) {
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
        $info->setAdditionalInformation('cardHash', $additionalData['cc_card_hash'] ?? null);
        $info->setAdditionalInformation('companyName', $additionalData['companyName'] ?? null);
        $info->setAdditionalInformation('documentType', $additionalData['documentType'] ?? null);
        $info->setAdditionalInformation('installments', $additionalData['cc_installments'] ?? 1);
        $info->setAdditionalInformation('phone', $additionalData['cc_phone'] ?? null);

        return $this;
    }

    /**
     * @param CartInterface|null $quote
     * @return bool
     */
    public function isAvailable(?CartInterface $quote = null): bool
    {
        $grandTotal = 0.0;

        if ($quote) {
            $grandTotal = (float) $quote->getGrandTotal();
        } else {
            $sessionQuote = $this->checkoutSession->getQuote();
            $grandTotal = $sessionQuote ? (float) $sessionQuote->getGrandTotal() : 0.0;
        }

        return (bool) ($this->helperData->isCreditCardActive() && $grandTotal >= 3);
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
}
