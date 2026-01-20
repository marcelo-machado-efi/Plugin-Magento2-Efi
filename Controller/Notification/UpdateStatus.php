<?php

namespace Gerencianet\Magento2\Controller\Notification;

use Exception;
use Efi\EfiPay;
use Gerencianet\Magento2\Helper\Data;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;

class UpdateStatus extends Action implements CsrfAwareActionInterface
{
    public const PAID = 'paid';
    public const UNPAID = 'unpaid';
    public const REFUNDED = 'refunded';
    public const CONTESTED = 'contested';
    public const CANCELED = 'canceled';
    public const SETTLED = 'settled';
    public const WAITING = 'waiting';
    public const NEW = 'new';

    /** @var Data */
    protected $_helperData;

    /** @var OrderRepositoryInterface */
    protected $_orderRepository;

    /** @var SearchCriteriaBuilder */
    protected $_searchCriteriaBuilder;

    /**
     * @param Context $context
     * @param Data $helperData
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param OrderRepositoryInterface $orderRepository
     */
    public function __construct(
        Context $context,
        Data $helperData,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        OrderRepositoryInterface $orderRepository
    ) {
        $this->_helperData = $helperData;
        $this->_orderRepository = $orderRepository;
        $this->_searchCriteriaBuilder = $searchCriteriaBuilder;
        parent::__construct($context);
    }

    /**
     * @return void
     * @throws Exception
     */
    public function execute()
    {
        try {
            $body = $this->getRequest()->getPostValue();
            $this->_helperData->logger($body);

            $params = ['token' => $body['notification']];
            $options = $this->_helperData->getOptions();
            $api = new EfiPay($options);

            $chargeNotification = $api->getNotification($params, []);

            $count = count($chargeNotification['data']);
            $ultimoStatus = $chargeNotification['data'][$count - 1];

            $chargeId = $ultimoStatus['identifiers']['charge_id'];
            $status = $ultimoStatus['status']['current'];

            $searchCriteria = $this->_searchCriteriaBuilder
                ->addFilter(
                    'gerencianet_transaction_id',
                    $chargeId,
                    'eq'
                )
                ->create();

            $collection = $this->_orderRepository->getList($searchCriteria);

            /** @var Order $order */
            foreach ($collection as $order) {
                switch ($status) {
                    case self::NEW:
                    case self::WAITING:
                    case self::UNPAID:
                        $order->setState($this->_helperData->getOrderStatus());
                        $order->setStatus($this->_helperData->getOrderStatus());
                        break;

                    case self::PAID:
                    case self::SETTLED:
                        $order->setState(Order::STATE_PROCESSING);
                        $order->setStatus(Order::STATE_PROCESSING);
                        break;

                    case self::REFUNDED:
                    case self::CANCELED:
                        $order->cancel();
                        break;

                    case self::CONTESTED:
                        $order->setState(Order::STATE_HOLDED);
                        $order->setStatus(Order::STATE_HOLDED);
                        break;
                }

                $this->_orderRepository->save($order);
            }
        } catch (Exception $e) {
            $this->_helperData->logger($e->getMessage());
            throw new Exception('Error Processing Request', 1);
        }
    }

    /**
     * @inheritDoc
     */
    public function createCsrfValidationException(
        RequestInterface $request
    ): ?InvalidRequestException {
        return null;
    }

    /**
     * @inheritDoc
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
