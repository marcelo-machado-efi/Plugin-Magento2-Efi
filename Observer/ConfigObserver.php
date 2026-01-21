<?php

namespace Gerencianet\Magento2\Observer;

use Efi\EfiPay;
use Gerencianet\Magento2\Helper\Data;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Store\Model\StoreManagerInterface;

class ConfigObserver implements ObserverInterface
{
    /**
     * @var Data
     */
    private Data $helperData;

    /**
     * @var StoreManagerInterface
     */
    private StoreManagerInterface $storeManager;

    /**
     * @var DirectoryList
     */
    private DirectoryList $dir;

    /**
     * @var File
     */
    private File $driver;

    /**
     * @param Data $helperData
     * @param StoreManagerInterface $storeManager
     * @param DirectoryList $dl
     * @param File $driver
     */
    public function __construct(
        Data $helperData,
        StoreManagerInterface $storeManager,
        DirectoryList $dl,
        File $driver
    ) {
        $this->helperData = $helperData;
        $this->storeManager = $storeManager;
        $this->dir = $dl;
        $this->driver = $driver;
    }

    /**
     * @param Observer $observer
     * @return void
     * @throws LocalizedException
     */
    public function execute(Observer $observer)
    {
        if ($this->helperData->isPixActive()) {
            $this->cadastraWebhookPix();
        }

        if ($this->helperData->isOpenFinanceActive()) {
            $this->cadastraWebhookOpenFinance();
        }
    }

    /**
     * @return void
     * @throws LocalizedException
     */
    public function cadastraWebhookPix(): void
    {
        $skipMtls = $this->helperData->getSkipMtls() ? 'false' : 'true';

        $options = $this->helperData->getOptions();
        $options['certificate'] = $this->getCertificadoPath('pix');
        $options['headers'] = ['x-skip-mtls-checking' => $skipMtls];

        $params = ['chave' => $this->helperData->getChavePix()];
        $body = ['webhookUrl' => $this->getNotificationUrlPix()];

        try {
            $api = new EfiPay($options);
            $api->pixConfigWebhook($params, $body);
        } catch (\Throwable $e) {
            $this->helperData->logger($e->getMessage());

            throw new LocalizedException(
                __('Falha ao configurar o webhook do Pix.'),
                $e
            );
        }
    }

    /**
     * @return void
     * @throws LocalizedException
     */
    public function cadastraWebhookOpenFinance(): void
    {
        $options = $this->helperData->getOptions();
        $options['certificate'] = $this->getCertificadoPath('open_finance');

        $callbackUrl = $this->getNotificationUrlOpenFinance();
        $redirectUrl = $this->getRedirectnUrlOpenFinance();
        $hash = hash('sha256', (string) ($options['clientId'] ?? ''));

        $body = [
            'webhookURL' => $callbackUrl,
            'redirectURL' => $redirectUrl,
            'webhookSecurity' => ['type' => 'hmac', 'hash' => $hash],
            'processPayment' => 'async',
        ];

        try {
            $api = new EfiPay($options);
            $api->ofConfigUpdate([], $body);
        } catch (\Throwable $e) {
            $this->helperData->logger($e->getMessage());

            throw new LocalizedException(
                __('Falha ao configurar o webhook do Open Finance.'),
                $e
            );
        }
    }

    /**
     * @param string $paymentMethod
     * @return string
     */
    public function getCertificadoPath(string $paymentMethod): string
    {
        $certName = (string) $this->helperData->getCert($paymentMethod);
        $certName = ltrim($certName, '/\\');

        if ($certName === '') {
            return '';
        }

        $path = rtrim($this->dir->getPath(DirectoryList::MEDIA), '/') . '/test/' . $certName;

        return $this->driver->isFile($path) ? $path : '';
    }

    /**
     * @return string
     */
    public function getNotificationUrlPix(): string
    {
        return $this->storeManager->getStore()->getBaseUrl()
            . 'gerencianet/notification/updatepixstatus';
    }

    /**
     * @return string
     */
    public function getNotificationUrlOpenFinance(): string
    {
        return $this->storeManager->getStore()->getBaseUrl()
            . 'gerencianet/notification/updateopenfinancestatus';
    }

    /**
     * @return string
     */
    public function getRedirectnUrlOpenFinance(): string
    {
        return $this->storeManager->getStore()->getBaseUrl()
            . 'gerencianet/redirect/redirectopenfinance';
    }
}
