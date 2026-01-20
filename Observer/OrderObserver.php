<?php

namespace Gerencianet\Magento2\Observer;

use Gerencianet\Magento2\Helper\Data;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;

class OrderObserver implements ObserverInterface
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
     */
    public function execute(Observer $observer)
    {
        /** @var Order $order */
        $order = $observer->getEvent()->getOrder();

        $payment = $order->getPayment();
        $methodCode = (string) $payment->getMethod();

        if (strpos($methodCode, 'gerencianet') !== false) {
            $order->setState('new')->setStatus($this->helperData->getOrderStatus());
            $order->save();
        }
    }
}
