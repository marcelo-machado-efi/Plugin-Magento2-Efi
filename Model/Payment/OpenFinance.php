<?php

namespace Gerencianet\Magento2\Model\Payment;

use Efi\EfiPay;
use Exception;
use Gerencianet\Magento2\Helper\Data as GerencianetHelper;
use Magento\Framework\Api\AttributeValueFactory;
use Magento\Framework\Api\ExtensionAttributesFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use Magento\Framework\UrlInterface;
use Magento\Payment\Helper\Data;
use Magento\Payment\Model\InfoInterface;
use Magento\Payment\Model\Method\AbstractMethod;
use Magento\Payment\Model\Method\Logger;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Sales\Model\Order;
use Magento\Store\Model\StoreManagerInterface;

class OpenFinance extends AbstractMethod
{
    /**
     * @var Redirect
     */
    protected Redirect $resultRedirect;

    /**
     * @var ResultFactory
     */
    protected ResultFactory $resultRedirectFactory;

    /**
     * @var UrlInterface
     */
    protected UrlInterface $_url;

    /**
     * @var string
     */
    protected $_code = 'gerencianet_open_finance';

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
     * @var File
     */
    protected File $fileDriver;

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
        UrlInterface $url,
        ResultFactory $resultRedirectFactory,
        Redirect $resultRedirect,
        DirectoryList $directoryList,
        File $fileDriver,
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
        $this->_url = $url;
        $this->resultRedirectFactory = $resultRedirectFactory;
        $this->resultRedirect = $resultRedirect;
        $this->directoryList = $directoryList;
        $this->fileDriver = $fileDriver;
    }

    /**
     * @param InfoInterface $payment
     * @param float|int $amount
     * @return void
     * @throws LocalizedException
     */
    public function order(InfoInterface $payment, $amount)
    {
        try {
            $paymentInfo = $payment->getAdditionalInformation();

            /** @var Order $order */
            $order = $payment->getOrder();
            $incrementId = $order->getIncrementId();

            $rootDir = rtrim($this->directoryList->getRoot(), '/');
            $certName = (string) $this->_helperData->getCert('open_finance');

            $certificadoPix = $rootDir . '/media/test/' . $certName;
            if (!$this->fileDriver->isExists($certificadoPix)) {
                $certificadoPix = $rootDir . '/pub/media/test/' . $certName;

                if (!$this->fileDriver->isExists($certificadoPix)) {
                    $certificadoPix = $rootDir . '/media/test/' . $certName;
                }
            }

            $options = $this->_helperData->getOptions();
            $options['certificate'] = $certificadoPix;
            $options['headers'] = [
                'x-idempotency-key' => uniqid($this->uniqidReal(), true),
            ];

            $data = [];

            $data['pagador']['idParticipante'] = $paymentInfo['ofOwnerBanking'];

            if ($paymentInfo['documentType'] === 'CPF') {
                $data['pagador']['cpf'] = $paymentInfo['ofOwnerCpf'];
            } elseif ($paymentInfo['documentType'] === 'CNPJ') {
                $data['pagador']['cpf'] = $paymentInfo['ofOwnerCpf'];
                $data['pagador']['cnpj'] = $paymentInfo['ofOwnerCnpj'];
            }

            $data['favorecido']['contaBanco']['codigoBanco'] = '364';
            $data['favorecido']['contaBanco']['agencia'] = '0001';
            $data['favorecido']['contaBanco']['documento'] = preg_replace(
                '/[^0-9]/',
                '',
                $this->_helperData->getDocumento()
            );
            $data['favorecido']['contaBanco']['nome'] = $this->_helperData->getNome();
            $data['favorecido']['contaBanco']['conta'] = str_replace(
                ' ',
                '',
                $this->_helperData->getNumeroConta()
            );
            $data['favorecido']['contaBanco']['tipoConta'] = 'TRAN';
            $data['valor'] = number_format($amount, 2, '.', '');
            $data['idProprio'] = $incrementId;

            $api = new EfiPay($options);
            $of = $api->ofStartPixPayment([], $data);

            $order->setGerencianetTransactionId($of['identificadorPagamento']);
            $order->setGerencianetRedirectUrl($of['redirectURI']);
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
        $info->setAdditionalInformation('ofOwnerCpf', $additionalData['ofOwnerCpf'] ?? null);
        $info->setAdditionalInformation('ofOwnerCnpj', $additionalData['ofOwnerCnpj'] ?? null);
        $info->setAdditionalInformation('documentType', $additionalData['documentType'] ?? null);
        $info->setAdditionalInformation('ofOwnerBanking', $additionalData['ofOwnerBanking'] ?? null);

        return $this;
    }

    /**
     * @param CartInterface|null $quote
     * @return bool
     */
    public function isAvailable(?CartInterface $quote = null)
    {
        return $this->_helperData->isOpenFinanceActive();
    }

    /**
     * @param int $length
     * @return string
     * @throws Exception
     */
    public function uniqidReal($length = 30)
    {
        if (function_exists('random_bytes')) {
            $bytes = random_bytes((int) ceil($length / 2));
        } elseif (function_exists('openssl_random_pseudo_bytes')) {
            $bytes = openssl_random_pseudo_bytes((int) ceil($length / 2));
        } else {
            throw new Exception('no cryptographically secure random function available');
        }

        return substr(bin2hex($bytes), 0, (int) $length);
    }
}
