<?php

declare(strict_types=1);

namespace Gerencianet\Magento2\Controller\Ajax;

use Gerencianet\Magento2\Helper\Data;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;

class OfPaymentStatusAjaxHandler extends Action implements HttpGetActionInterface
{
    /** @var Data */
    private Data $_helperData;

    /** @var JsonFactory */
    private JsonFactory $_jsonResultFactory;

    /** @var SearchCriteriaBuilder */
    private SearchCriteriaBuilder $_searchCriteriaBuilder;

    /** @var OrderRepositoryInterface */
    private OrderRepositoryInterface $_orderRepository;

    /**
     * @param Context $context
     * @param Data $helperData
     * @param JsonFactory $jsonResultFactory
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param OrderRepositoryInterface $orderRepository
     */
    public function __construct(
        Context $context,
        Data $helperData,
        JsonFactory $jsonResultFactory,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        OrderRepositoryInterface $orderRepository
    ) {
        $this->_helperData = $helperData;
        $this->_jsonResultFactory = $jsonResultFactory;
        $this->_searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->_orderRepository = $orderRepository;

        parent::__construct($context);
    }

    /**
     * @inheritdoc
     */
    public function execute()
    {
        $identificadorPagamento = $this->getRequest()->getParam('identificadorPagamento');

        $searchCriteria = $this->_searchCriteriaBuilder
            ->addFilter(
                'gerencianet_transaction_id',
                $identificadorPagamento,
                'eq'
            )
            ->create();

        $collection = $this->_orderRepository->getList($searchCriteria);

        $status = false;
        $orderId = null;

        /** @var Order $order */
        foreach ($collection->getItems() as $order) {
            $status = $order->getStatus() === 'processing';
            $orderId = $order->getId();
        }

        $result = $this->_jsonResultFactory->create();

        return $result->setData([
            'paid' => $status,
            'order_id' => $orderId,
        ]);
    }
}
