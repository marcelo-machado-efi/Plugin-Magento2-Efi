<?php

declare(strict_types=1);

namespace Gerencianet\Magento2\Controller\Installments;

use Efi\EfiPay;
use Efi\Exception\EfiException;
use Gerencianet\Magento2\Helper\Data;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;

class Index extends Action implements HttpGetActionInterface
{
    /** @var Data */
    private Data $_helperData;

    /** @var JsonFactory */
    private JsonFactory $_jsonResultFactory;

    /**
     * @param Context $context
     * @param Data $helperData
     * @param JsonFactory $jsonResultFactory
     */
    public function __construct(
        Context $context,
        Data $helperData,
        JsonFactory $jsonResultFactory
    ) {
        $this->_helperData = $helperData;
        $this->_jsonResultFactory = $jsonResultFactory;

        parent::__construct($context);
    }

    /**
     * @inheritdoc
     */
    public function execute()
    {
        $brand = $this->getRequest()->getParam('brand');
        $total = $this->getRequest()->getParam('total');
        $options = $this->_helperData->getOptions();

        $params = [
            'total' => $total,
            'brand' => $brand,
        ];

        $result = $this->_jsonResultFactory->create();

        try {
            $api = new EfiPay($options);
            $response = $api->getInstallments($params, []);

            return $result->setData($response);
        } catch (EfiException $e) {
            return $result->setData([
                'error' => true,
                'code' => $e->code,
                'message' => $e->errorDescription ?? $e->error,
            ]);
        } catch (\Exception $e) {
            return $result->setData([
                'error' => true,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
