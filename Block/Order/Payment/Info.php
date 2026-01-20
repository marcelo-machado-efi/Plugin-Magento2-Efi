<?php

namespace Gerencianet\Magento2\Block\Order\Payment;

use Magento\Framework\View\Element\Template;
use Magento\Sales\Api\OrderRepositoryInterface;

class Info extends Template
{
    /**
     * @var OrderRepositoryInterface
     */
    private OrderRepositoryInterface $orderRepository;

    /**
     * @param Template\Context $context
     * @param OrderRepositoryInterface $orderRepository
     * @param array|null $data
     */
    public function __construct(
        Template\Context $context,
        OrderRepositoryInterface $orderRepository,
        ?array $data = null
    ) {
        parent::__construct($context, $data ?? []);
        $this->orderRepository = $orderRepository;
    }

    /**
     * @return string
     */
    public function getPaymentMethod(): string
    {
        $orderId = (int) $this->getRequest()->getParam('order_id');
        $order = $this->orderRepository->get($orderId);

        $payment = $order->getPayment();
        return (string) $payment->getMethod();
    }

    /**
     * @return array|false
     */
    public function getPaymentInfo()
    {
        $orderId = (int) $this->getRequest()->getParam('order_id');
        $order = $this->orderRepository->get($orderId);

        $payment = $order->getPayment();
        if (!$payment) {
            return false;
        }

        $paymentMethod = (string) $payment->getMethod();

        switch ($paymentMethod) {
            case 'gerencianet_boleto':
                return [
                    'tipo' => 'Boleto',
                    'url' => $order->getGerencianetUrlBoleto(),
                    'texto' => 'Clique aqui para visualizar seu boleto.',
                    'linha-digitavel' => $order->getGerencianetCodigoDeBarras(),
                ];

            case 'gerencianet_pix':
                return [
                    'tipo' => 'Pix',
                    'url' => $order->getGerencianetQrcodePix(),
                    'texto' => 'Clique aqui para ver seu QRCode.',
                    'chavepix' => $order->getGerencianetChavePix(),
                ];
        }

        return false;
    }
}
