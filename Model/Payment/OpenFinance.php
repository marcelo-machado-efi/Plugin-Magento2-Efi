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
use Magento\Framework\UrlInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\Result\Redirect;

class OpenFinance extends AbstractMethod
{
	/** @var Redirect */
	protected $resultRedirect;

	/** @var ResultFactory */
	protected $resultRedirectFactory;

	/** @var UrlInterface */
	protected $_url;

	/** @var string */
	protected $_code = 'gerencianet_open_finance';

	/** @var GerencianetHelper */
	protected $_helperData;

	/** @var StoreManagerInterface */
	protected $_storeMagerInterface;

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
		$this->_url = $url;
		$this->resultRedirectFactory = $resultRedirectFactory;
		$this->resultRedirect = $resultRedirect;
	}

	public function order(InfoInterface $payment, $amount)
	{
		try {

			$paymentInfo = $payment->getAdditionalInformation();

			/** @var Order */
			$order = $payment->getOrder();
			$incrementId = $order->getIncrementId();
			$storeName = $this->_storeMagerInterface->getStore()->getName();

			$certificadoPix = $_SERVER['DOCUMENT_ROOT'] . "media/test/" . $this->_helperData->getCert('open_finance');
			if (!file_exists($certificadoPix)) {
				$certificadoPix = $_SERVER['DOCUMENT_ROOT'] . "pub/media/test/" . $this->_helperData->getCert('open_finance');
				if (!file_exists($certificadoPix)) {
					$certificadoPix = $_SERVER['DOCUMENT_ROOT'] . "/pub/media/test/" . $this->_helperData->getCert('open_finance');
					if (!file_exists($certificadoPix)) {
						$certificadoPix = $_SERVER['DOCUMENT_ROOT'] . "/media/test/" . $this->_helperData->getCert('open_finance');
					}
				}
			}

			$options = $this->_helperData->getOptions();
			$options['certificate'] = $certificadoPix;
			$options["headers"] = [
				"x-idempotency-key" => uniqid($this->uniqidReal(), true)
			];
			$data = [];

			$data['pagador']['idParticipante'] = $paymentInfo['ofOwnerBanking'];
			if ($paymentInfo['documentType'] == "CPF") {
				$data['pagador']['cpf'] = $paymentInfo['ofOwnerCpf'];
			} else if ($paymentInfo['documentType'] == "CNPJ") {
				$data['pagador']['cpf'] = $paymentInfo['ofOwnerCpf'];
				$data['pagador']['cnpj'] = $paymentInfo['ofOwnerCnpj'];
			}
			$data['favorecido']['contaBanco']['codigoBanco'] = "364";
			$data['favorecido']['contaBanco']['agencia'] = "0001";
			$data['favorecido']['contaBanco']['documento'] =  preg_replace("/[^0-9]/", "", $this->_helperData->getDocumento());
			$data['favorecido']['contaBanco']['nome'] = $this->_helperData->getNome();
			$data['favorecido']['contaBanco']['conta'] = str_replace(' ', '', $this->_helperData->getNumeroConta());
			$data['favorecido']['contaBanco']['tipoConta'] = "CACC";
			$data['valor'] = number_format($amount, 2, ".", "");
			$data['idProprio'] = $incrementId;

			$api = new EfiPay($options);
			$of = $api->ofStartPixPayment([], $data);




			$order->setGerencianetTransactionId($of['identificadorPagamento']);
			$order->setGerencianetRedirectUrl($of['redirectURI']);
		} catch (Exception $e) {
			throw new LocalizedException(__($e->getMessage()));
		}
	}

	public function assignData(DataObject $data)
	{
		$info = $this->getInfoInstance();
		$info->setAdditionalInformation('ofOwnerCpf', $data['additional_data']['ofOwnerCpf'] ?? null);
		$info->setAdditionalInformation('ofOwnerCnpj', $data['additional_data']['ofOwnerCnpj'] ?? null);
		$info->setAdditionalInformation('documentType', $data['additional_data']['documentType'] ?? null);
		$info->setAdditionalInformation('ofOwnerBanking', $data['additional_data']['ofOwnerBanking'] ?? null);
		return $this;
	}

	public function isAvailable(CartInterface $quote = null)
	{
		return $this->_helperData->isOpenFinanceActive() ? true : false;
	}

	public function uniqidReal($lenght = 30)
	{
		if (function_exists("random_bytes")) {
			$bytes = random_bytes(ceil($lenght / 2));
		} elseif (function_exists("openssl_random_pseudo_bytes")) {
			$bytes = openssl_random_pseudo_bytes(ceil($lenght / 2));
		} else {
			throw new Exception("no cryptographically secure random function available");
		}
		return substr(bin2hex($bytes), 0, $lenght);
	}
}
