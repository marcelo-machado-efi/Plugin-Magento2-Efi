<?php

namespace Gerencianet\Magento2\Controller\Notification;

use Exception;
use Efi\EfiPay;
use Gerencianet\Magento2\Helper\Data;
use Efi\Exception\EfiException;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Framework\Filesystem\DirectoryList;

class UpdateOpenFinanceStatus extends Action implements CsrfAwareActionInterface
{
	const ACEITO = 'aceito';
	const REJEITADO = 'rejeitado';
	const EXPIRADO = 'expirado';

	/** @var Data */
	protected $_helperData;

	/** @var OrderRepositoryInterface */
	protected $_orderRepository;

	/** @var SearchCriteriaBuilder */
	protected $_searchCriteriaBuilder;

	/** @var DirectoryList */
	protected $_directoryList;

	public function __construct(
		Context $context,
		Data $helperData,
		SearchCriteriaBuilder $searchCriteriaBuilder,
		OrderRepositoryInterface $orderRepository,
		DirectoryList $directoryList
	) {
		$this->_helperData = $helperData;
		$this->_orderRepository = $orderRepository;
		$this->_searchCriteriaBuilder = $searchCriteriaBuilder;
		$this->_directoryList = $directoryList;

		parent::__construct($context);
	}

	public function execute()
	{
		try {
			$content = $this->getRequest()->getContent();
			$body = json_decode((string)$content, true);

			$this->_helperData->logger($body);

			if (isset($body['identificadorPagamento'])) {
				$identificadorPagamento = $body['identificadorPagamento'];
				$status = $body['status'];

				$searchCriteria = $this->_searchCriteriaBuilder
					->addFilter('gerencianet_transaction_id', $identificadorPagamento, 'eq')
					->create();

				$list = $this->_orderRepository->getList($searchCriteria);
				$orders = $list->getItems();

				/** @var Order $order */
				foreach ($orders as $order) {
					switch ($status) {
						case self::ACEITO:
							$order->setState(Order::STATE_PROCESSING);
							$order->setStatus(Order::STATE_PROCESSING);
							break;

						case self::REJEITADO:
						case self::EXPIRADO:
							$order->cancel();
							break;
					}
					$this->_orderRepository->save($order);
				}
			}
		} catch (EfiException $e) {
			$this->_helperData->logger($e->getMessage());
			throw new Exception("Error Processing Request", 1);
		}
	}

	/** @inheritDoc */
	public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
	{
		return null;
	}

	/** @inheritDoc */
	public function validateForCsrf(RequestInterface $request): ?bool
	{
		return true;
	}
}
