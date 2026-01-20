<?php

namespace Gerencianet\Magento2\Model;

use Gerencianet\Magento2\Helper\Data;
use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Payment\Model\CcConfig;

class PaymentConfigProvider implements ConfigProviderInterface
{
    /**
     * @var string
     */
    private string $methodCode = Data::METHOD_CODE_CREDIT_CARD;

    /**
     * @var CcConfig
     */
    private CcConfig $ccConfig;

    /**
     * @var Data
     */
    private Data $helperData;

    /**
     * @param CcConfig $ccConfig
     * @param Data $helperData
     */
    public function __construct(CcConfig $ccConfig, Data $helperData)
    {
        $this->ccConfig = $ccConfig;
        $this->helperData = $helperData;
    }

    /**
     * @return array
     */
    public function getConfig(): array
    {
        return [
            'payment' => [
                'cc' => [
                    'availableTypes' => [
                        $this->methodCode => [
                            'AE' => 'American Express',
                            'ELO' => 'Elo',
                            'HC' => 'Hipercard',
                            'MC' => 'Mastercard',
                            'VI' => 'Visa',
                        ],
                    ],
                    'months' => [
                        $this->methodCode => $this->ccConfig->getCcMonths(),
                    ],
                    'years' => [
                        $this->methodCode => $this->ccConfig->getCcYears(),
                    ],
                    'hasVerification' => $this->ccConfig->hasVerification(),
                    'cvvImageUrl' => $this->ccConfig->getCvvImageUrl(),
                    'minPrice' => $this->helperData->getPrecoMinimo(),
                    'identificadorConta' => $this->helperData->getIdentificadorConta(),
                    'urlGerencianet' => $this->helperData->getUrl(),
                ],
            ],
        ];
    }
}
