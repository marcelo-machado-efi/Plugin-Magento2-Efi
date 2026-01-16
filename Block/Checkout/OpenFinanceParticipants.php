<?php

namespace Gerencianet\Magento2\Block\Checkout;

use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Framework\App\Filesystem\DirectoryList;
use Efi\Exception\EfiException;
use Efi\EfiPay;;

use Gerencianet\Magento2\Helper\Data as GerencianetHelper;

class OpenFinanceParticipants extends Template
{
    /** @var GerencianetHelper */
    protected $_helperData;

    /** @var DirectoryList */
    protected $_directoryList;

    public function __construct(
        Context $context,
        GerencianetHelper $helperData,
        DirectoryList $directoryList,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->_helperData = $helperData;
        $this->_directoryList = $directoryList;
    }

    private function getCertificadoPixPath()
    {
        $baseDir = $this->_directoryList->getRoot();
        $paths = [
            $baseDir . "/media/test/" . $this->_helperData->getPixCert(),
            $baseDir . "/pub/media/test/" . $this->_helperData->getPixCert(),
            $baseDir . "/pub/media/test/" . $this->_helperData->getPixCert(),
            $baseDir . "/media/test/" . $this->_helperData->getPixCert(),
        ];

        foreach ($paths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        return null;
    }

    public function getParticipants()
    {

        $certificadoPix = $this->getCertificadoPixPath();


        $options = $this->_helperData->getOptions();
        $options['certificate'] = $certificadoPix;

        try {
            $api = new EfiPay($options);
            $response = $api->ofListParticipants($params = []);
            return $response;
        } catch (EfiException $e) {

            return $e->errorDescription;
        }
    }
}
