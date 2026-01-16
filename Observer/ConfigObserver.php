<?php

namespace Gerencianet\Magento2\Observer;

use Exception;
use Efi\EfiPay;
use Gerencianet\Magento2\Helper\Data;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Config\Model\ResourceModel\Config;
use Magento\Framework\Filesystem\DirectoryList;

class ConfigObserver implements ObserverInterface
{
    /** @var Data */
    private $_helperData;

    /** @var StoreManagerInterface */
    private $_storeManagerInterface;

    /** @var Config */
    private $_resourceConfig;

    /** @var DirectoryList */
    private $_dir;

    public function __construct(
        Data $helperData,
        StoreManagerInterface $storeManager,
        Config $resourceConfig,
        DirectoryList $dl
    ) {
        $this->_helperData = $helperData;
        $this->_storeManagerInterface = $storeManager;
        $this->_resourceConfig = $resourceConfig;
        $this->_dir = $dl;
    }

    public function execute(Observer $observer)
    {
        if ($this->_helperData->isPixActive()) {
            $this->cadastraWebhookPix($observer);
        }
        if ($this->_helperData->isOpenFinanceActive()) {
            $this->cadastraWebhookOpenFinance($observer);
        }
    }

    public function cadastraWebhookPix(Observer $observer)
    {
        $this->defaultName($observer);

        // PHP 8.2+: Simplificação de lógica booleana para string
        $skipMtls = $this->_helperData->getSkipMtls() ? 'false' : 'true';

        $options = $this->_helperData->getOptions();
        $options['certificate'] = $this->getCertificadoPath();
        $options['headers'] = ['x-skip-mtls-checking' => $skipMtls];

        $params = ['chave' => $this->_helperData->getChavePix()];
        $body = ['webhookUrl' => $this->getNotificationUrlPix()];

        try {
            $api = new EfiPay($options);
            $api->pixConfigWebhook($params, $body);
        } catch (Exception $e) {
            $this->_helperData->logger($e->getMessage());
            throw new Exception($e->getMessage(), 0, $e);
        }
    }

    public function cadastraWebhookOpenFinance(Observer $observer)
    {
        $this->defaultName($observer);

        $options = $this->_helperData->getOptions();
        $options['certificate'] = $this->getCertificadoPath();

        $callbackUrl = $this->getNotificationUrlOpenFinance();
        $redirectUrl = $this->getRedirectnUrlOpenFinance();
        $hash = hash('sha256', (string)($options['clientId'] ?? ''));

        $webhookSecurity = ["type" => "hmac", "hash" => $hash];

        $body = [
            'webhookURL' => $callbackUrl,
            'redirectURL' => $redirectUrl,
            'webhookSecurity' => $webhookSecurity,
            'processPayment' => 'async'
        ];

        try {
            $api = new EfiPay($options);
            $api->ofConfigUpdate([], $body);
        } catch (Exception $e) {
            $this->_helperData->logger($e->getMessage());
            throw new Exception($e->getMessage(), 0, $e);
        }
    }

    public function defaultName(Observer $observer)
    {
        $path = "payment/gerencianet_pix/certificado";
        $value = "certificate.pem";
        $scope = "default";
        $scopeId = 0;

        $eventData = $observer->getEvent()->getData();
        $changedPaths = $eventData['changed_paths'] ?? [];

        if ($this->getCertificadoPath() !== "" && in_array($path, $changedPaths)) {
            $this->_resourceConfig->saveConfig($path, $value, $scope, $scopeId);
        }
    }

    /**
     * Retorna o path ou string vazia se não existir para respeitar tipagem string do PHP 8
     */
    public function getCertificadoPath(): string
    {
        $certName = $this->_helperData->getPixCert();
        if (!$certName) return "";

        $certificadopath = $this->_dir->getPath('media') . "/test/" . $certName;
        return file_exists($certificadopath) ? $certificadopath : "";
    }

    public function getNotificationUrlPix(): string
    {
        return $this->_storeManagerInterface->getStore()->getBaseUrl() . 'gerencianet/notification/updatepixstatus';
    }

    public function getNotificationUrlOpenFinance(): string
    {
        return $this->_storeManagerInterface->getStore()->getBaseUrl() . 'gerencianet/notification/updateopenfinancestatus';
    }

    public function getRedirectnUrlOpenFinance(): string
    {
        return $this->_storeManagerInterface->getStore()->getBaseUrl() . 'gerencianet/redirect/redirectopenfinance';
    }
}
