<?php

namespace Gerencianet\Magento2\Observer;

use Efi\EfiPay;
use Gerencianet\Magento2\Helper\Data;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order;

class OrderCancel implements ObserverInterface
{
    /**
     * @var Data
     */
    private Data $helperData;

    /**
     * @param Data $helperData
     */
    public function __construct(Data $helperData)
    {
        $this->helperData = $helperData;
    }

    /**
     * @param Observer $observer
     * @return void
     * @throws LocalizedException
     */
    public function execute(Observer $observer)
    {
        /** @var Order $order */
        $order = $observer->getEvent()->getOrder();

        $payment = $order->getPayment();
        $methodCode = (string) $payment->getMethod();

        if (
            strpos($methodCode, 'gerencianet') !== false
            && $methodCode !== 'gerencianet_open_finance'
            && $methodCode !== 'gerencianet_pix'
        ) {
            $options = $this->helperData->getOptions();
            $chargeId = $order->getGerencianetTransactionId();

            $params = [
                'id' => $chargeId,
            ];

            try {
                $api = new EfiPay($options);
                $charge = $api->cancelCharge($params, []);

                $order->addStatusToHistory(
                    $order->getStatus(),
                    'Campainha recebida do Gerencianet: Transaction ID ' . $chargeId
                    . ' - Status Cancelado',
                    true
                );

                $this->helperData->logger(json_encode($charge));
            } catch (\Throwable $e) {
                $this->helperData->logger(json_encode($e->getMessage()));

                throw new LocalizedException(
                    __('Falha ao cancelar a cobrança na Efí.'),
                    $e
                );
            }
        }
    }
}
