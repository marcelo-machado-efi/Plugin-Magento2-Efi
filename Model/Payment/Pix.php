<?php

namespace Gerencianet\Magento2\Model\Payment;

use Exception;
use Efi\EfiPay;
use Gerencianet\Magento2\Helper\Data as GerencianetHelper;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Sales\Model\Order;
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
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;

class Pix extends AbstractMethod
{
    /** @var string */
    protected $_code = 'gerencianet_pix';

    /** @var GerencianetHelper */
    protected $_helperData;

    /** @var StoreManagerInterface */
    protected $_storeMagerInterface;

    /** @var DirectoryList */
    protected $directoryList;

    /** @var Filesystem */
    protected $filesystem;

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
        $this->directoryList = $directoryList;
        $this->filesystem = $filesystem;
    }

    /**
     * @param InfoInterface $payment
     * @param float $amount
     * @return void
     * @throws LocalizedException
     */
    public function order(InfoInterface $payment, $amount)
    {
        try {
            $paymentInfo = $payment->getAdditionalInformation();
            $this->_helperData->logger(json_encode($paymentInfo));

            /** @var Order $order */
            $order = $payment->getOrder();
            $incrementId = $order->getIncrementId();
            $storeName = $this->_storeMagerInterface->getStore()->getName();

            $rootPath = $this->directoryList->getRoot();
            $mediaReader = $this->filesystem->getDirectoryRead(DirectoryList::MEDIA);

            $certificadoPix = 'test/' . $this->_helperData->getCert('pix');

            if (!$mediaReader->isExist($certificadoPix)) {
                throw new LocalizedException(__('Certificado Pix não encontrado.'));
            }

            $options = $this->_helperData->getOptions();
            $options['certificate'] = $rootPath . '/pub/media/' . $certificadoPix;

            $data = [];
            $data['calendario']['expiracao'] = 3600;

            if ($paymentInfo['documentType'] === 'CPF') {
                $data['devedor']['cpf'] = $paymentInfo['cpfCustomer'];
            } elseif ($paymentInfo['documentType'] === 'CNPJ') {
                $data['devedor']['cnpj'] = $paymentInfo['cpfCustomer'];
                $data['devedor']['nome'] = $paymentInfo['companyName'];
            }

            $data['devedor']['nome'] =
                $order->getCustomerFirstname() . ' ' . $order->getCustomerLastname();

            $data['valor']['original'] = number_format($amount, 2, '.', '');
            $data['chave'] = $this->_helperData->getChavePix();
            $data['infoAdicionais'] = [
                ['nome' => 'Pagamento em', 'valor' => $storeName],
                ['nome' => 'Número do Pedido', 'valor' => $incrementId]
            ];

            $api = new EfiPay($options);
            $pix = $api->pixCreateImmediateCharge([], $data);

            $qrcode = $api->pixGenerateQRCode(['id' => $pix['loc']['id']]);

            $order->setCustomerTaxvat($paymentInfo['cpfCustomer']);
            $order->setGerencianetTransactionId($pix['txid']);
            $order->setGerencianetChavePix($qrcode['qrcode']);
            $order->setGerencianetQrcodePix($qrcode['imagemQrcode']);
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
        $info->setAdditionalInformation('cpfCustomer', $data['additional_data']['cpfCustomer'] ?? null);
        $info->setAdditionalInformation('companyName', $data['additional_data']['companyName'] ?? null);
        $info->setAdditionalInformation('documentType', $data['additional_data']['documentType'] ?? null);

        return $this;
    }

    /**
     * @param CartInterface|null $quote
     * @return bool
     */
    public function isAvailable(CartInterface $quote = null)
    {
        return $this->_helperData->isPixActive();
    }
}
