<?php

namespace Gerencianet\Magento2\Block\Checkout;

use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Framework\App\Filesystem\DirectoryList;
use Efi\Exception\EfiException;
use Efi\EfiPay;

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

    private function getCertificadoOpenFinancePath()
    {
        $baseDir = $this->_directoryList->getRoot();
        $paths = [
            $baseDir . "/media/test/" . $this->_helperData->getCert('open_finance'),
            $baseDir . "/pub/media/test/" . $this->_helperData->getCert('open_finance'),
            $baseDir . "/pub/media/test/" . $this->_helperData->getCert('open_finance'),
            $baseDir . "/media/test/" . $this->_helperData->getCert('open_finance'),
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

        $certificadoPix = $this->getCertificadoOpenFinancePath();


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
