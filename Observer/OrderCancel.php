<?php

namespace Gerencianet\Magento2\Observer;

use Exception;
use Gerencianet\Magento2\Helper\Data;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use Efi\EfiPay;

use Magento\Sales\Model\Order;

class OrderCancel implements ObserverInterface
{

    /** @var Data */
    protected $_helperData;

    public function __construct(Data $helperData)
    {
        $this->_helperData = $helperData;
    }

    public function execute(Observer $observer)
    {
        /** @var Order */
        $order = $observer->getEvent()->getOrder();
        $payment = $order->getPayment();
        $methodCode = $payment->getMethod();
        if (strpos($methodCode, 'gerencianet') !== false &&  $methodCode !== 'gerencianet_open_finance' &&  $methodCode !== 'gerencianet_pix') {
            $options = $this->_helperData->getOptions();
            $chargeId = $order->getGerencianetTransactionId();
            $params = [
                'id' => $chargeId
            ];
            try {
                $api = new EfiPay($options);
                $charge = $api->cancelCharge($params, []);
                $order->addStatusToHistory(
                    $order->getStatus(),
                    'Campainha recebida do Gerencianet: Transaction ID ' . $chargeId . ' - Status Cancelado',
                    true
                );
                // Usando o método logger() do helper da Gerencianet para salvar as mensagens de log
                $this->_helperData->logger(json_encode($charge));
            } catch (Exception $e) {
                // Usando o método logger() do helper da Gerencianet para salvar as mensagens de log
                $this->_helperData->logger(json_encode($e->getMessage()));
                throw new Exception($e->getMessage(), 1);
            }
        }
    }
}
