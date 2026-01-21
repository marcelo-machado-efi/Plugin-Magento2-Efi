<?php

namespace Gerencianet\Magento2\Model\Payment;

use Efi\EfiPay;
use Exception;
use Gerencianet\Magento2\Helper\Data as GerencianetHelper;
use Magento\Framework\Api\AttributeValueFactory;
use Magento\Framework\Api\ExtensionAttributesFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem;
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

class Pix extends AbstractMethod
{
    /**
     * @var string
     */
    protected $_code = 'gerencianet_pix';

    /**
     * @var GerencianetHelper
     */
    protected GerencianetHelper $_helperData;

    /**
     * @var StoreManagerInterface
     */
    protected StoreManagerInterface $_storeMagerInterface;

    /**
     * @var DirectoryList
     */
    protected DirectoryList $directoryList;

    /**
     * @var Filesystem
     */
    protected Filesystem $filesystem;

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
     * @param DirectoryList $directoryList
     * @param Filesystem $filesystem
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
        DirectoryList $directoryList,
        Filesystem $filesystem,
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

        $this->_helperData = $helperData;
        $this->_storeMagerInterface = $storeManager;
        $this->directoryList = $directoryList;
        $this->filesystem = $filesystem;
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
            $paymentInfo = (array) $payment->getAdditionalInformation();

            /** @var Order|null $order */
            $order = $payment->getOrder();
            if (!$order) {
                throw new LocalizedException(__('Pedido inválido.'));
            }

            $incrementId = (string) $order->getIncrementId();
            $storeName = (string) $this->_storeMagerInterface->getStore()->getName();

            $rootPath = rtrim($this->directoryList->getRoot(), '/');
            $mediaReader = $this->filesystem->getDirectoryRead(DirectoryList::MEDIA);

            $certificadoPix = 'test/' . ltrim((string) $this->_helperData->getCert('pix'), '/\\');

            if (!$mediaReader->isExist($certificadoPix)) {
                throw new LocalizedException(__('Certificado Pix não encontrado.'));
            }

            $options = $this->_helperData->getOptions();
            $options['certificate'] = $rootPath . '/pub/media/' . $certificadoPix;

            $data = [];
            $data['calendario']['expiracao'] = 3600;

            $documentType = (string) ($paymentInfo['documentType'] ?? '');

            if ($documentType === 'CPF') {
                $data['devedor']['cpf'] = $paymentInfo['cpfCustomer'] ?? null;
            } elseif ($documentType === 'CNPJ') {
                $data['devedor']['cnpj'] = $paymentInfo['cpfCustomer'] ?? null;
                $data['devedor']['nome'] = $paymentInfo['companyName'] ?? null;
            }

            $data['devedor']['nome'] = trim(
                (string) $order->getCustomerFirstname() . ' ' . (string) $order->getCustomerLastname()
            );

            $data['valor']['original'] = number_format((float) $amount, 2, '.', '');
            $data['chave'] = $this->_helperData->getChavePix();
            $data['infoAdicionais'] = [
                ['nome' => 'Pagamento em', 'valor' => $storeName],
                ['nome' => 'Número do Pedido', 'valor' => $incrementId],
            ];

            $api = new EfiPay($options);
            $pix = $api->pixCreateImmediateCharge([], $data);
            $qrcode = $api->pixGenerateQRCode(['id' => $pix['loc']['id']]);

            $order->setCustomerTaxvat($paymentInfo['cpfCustomer'] ?? null);
            $order->setGerencianetTransactionId($pix['txid'] ?? null);
            $order->setGerencianetChavePix($qrcode['qrcode'] ?? null);
            $order->setGerencianetQrcodePix($qrcode['imagemQrcode'] ?? null);
        } catch (Exception $e) {
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
        return (bool) $this->_helperData->isPixActive();
    }
}
