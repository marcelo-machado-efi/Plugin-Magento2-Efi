<?php

namespace Gerencianet\Magento2\Block\Checkout;

use Efi\EfiPay;
use Efi\Exception\EfiException;
use Gerencianet\Magento2\Helper\Data as GerencianetHelper;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem\DriverInterface;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;

class OpenFinanceParticipants extends Template
{
    /**
     * @var GerencianetHelper
     */
    private GerencianetHelper $helperData;

    /**
     * @var DirectoryList
     */
    private DirectoryList $directoryList;

    /**
     * @var DriverInterface
     */
    private DriverInterface $driver;

    /**
     * @param Context $context
     * @param GerencianetHelper $helperData
     * @param DirectoryList $directoryList
     * @param DriverInterface $driver
     * @param array $data
     */
    public function __construct(
        Context $context,
        GerencianetHelper $helperData,
        DirectoryList $directoryList,
        DriverInterface $driver,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->helperData = $helperData;
        $this->directoryList = $directoryList;
        $this->driver = $driver;
    }

    /**
     * @return string|null
     */
    private function getCertificadoOpenFinancePath(): ?string
    {
        $baseDir = $this->directoryList->getRoot();
        $certName = (string) $this->helperData->getCert('open_finance');
        $certName = ltrim($certName, '/\\');

        if ($certName === '') {
            return null;
        }

        $paths = [
            $baseDir . '/media/test/' . $certName,
            $baseDir . '/pub/media/test/' . $certName
        ];

        foreach ($paths as $path) {
            if ($this->driver->isExists($path) && $this->driver->isFile($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * @return array|string
     */
    public function getParticipants()
    {
        $certificadoOpenFinance = $this->getCertificadoOpenFinancePath();

        $options = $this->helperData->getOptions();
        $options['certificate'] = $certificadoOpenFinance;

        try {
            $api = new EfiPay($options);
            return $api->ofListParticipants([]);
        } catch (EfiException $e) {
            return (string) $e->errorDescription;
        }
    }
}
