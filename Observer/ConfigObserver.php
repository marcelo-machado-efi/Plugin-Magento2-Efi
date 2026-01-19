<?php

namespace Gerencianet\Magento2\Observer;

use Exception;
use Efi\EfiPay;
use Gerencianet\Magento2\Helper\Data;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\Filesystem\DirectoryList;

class ConfigObserver implements ObserverInterface
{
    private Data $_helperData;
    private StoreManagerInterface $_storeManagerInterface;
    private DirectoryList $_dir;

    public function __construct(
        Data $helperData,
        StoreManagerInterface $storeManager,
        DirectoryList $dl
    ) {
        $this->_helperData = $helperData;
        $this->_storeManagerInterface = $storeManager;
        $this->_dir = $dl;
    }

    public function execute(Observer $observer)
    {
        if ($this->_helperData->isPixActive()) {
            $this->cadastraWebhookPix();
        }

        if ($this->_helperData->isOpenFinanceActive()) {
            $this->cadastraWebhookOpenFinance();
        }
    }

    public function cadastraWebhookPix(): void
    {
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

    public function cadastraWebhookOpenFinance(): void
    {
        $options = $this->_helperData->getOptions();
        $options['certificate'] = $this->getCertificadoPath();

        $callbackUrl = $this->getNotificationUrlOpenFinance();
        $redirectUrl = $this->getRedirectnUrlOpenFinance();
        $hash = hash('sha256', (string)($options['clientId'] ?? ''));

        $body = [
            'webhookURL' => $callbackUrl,
            'redirectURL' => $redirectUrl,
            'webhookSecurity' => ['type' => 'hmac', 'hash' => $hash],
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

    public function getCertificadoPath(): string
    {
        $certName = (string)$this->_helperData->getPixCert();
        $certName = ltrim($certName, '/\\');

        if ($certName === '') {
            return '';
        }

        $path = rtrim($this->_dir->getPath('media'), '/') . '/test/' . $certName;
        return is_file($path) ? $path : '';
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
