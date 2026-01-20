<?php

namespace Gerencianet\Magento2\Controller\Notification;

use Exception;
use Efi\EfiPay;
use Efi\Exception\EfiException;
use Gerencianet\Magento2\Helper\Data;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Filesystem\DirectoryList;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;

class UpdatePixStatus extends Action implements CsrfAwareActionInterface
{
    public const ATIVA = 'ATIVA';
    public const CONCLUIDA = 'CONCLUIDA';
    public const REMOVIDA_PELO_USUARIO_RECEBEDOR = 'REMOVIDA_PELO_USUARIO_RECEBEDOR';
    public const REMOVIDA_PELO_PSP = 'REMOVIDA_PELO_PSP';

    /** @var Data */
    protected Data $_helperData;

    /** @var OrderRepositoryInterface */
    protected OrderRepositoryInterface $_orderRepository;

    /** @var SearchCriteriaBuilder */
    protected SearchCriteriaBuilder $_searchCriteriaBuilder;

    /** @var DirectoryList */
    protected DirectoryList $_directoryList;

    /** @var File */
    protected File $_fileDriver;

    /**
     * @param Context $context
     * @param Data $helperData
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param OrderRepositoryInterface $orderRepository
     * @param DirectoryList $directoryList
     * @param File $fileDriver
     */
    public function __construct(
        Context $context,
        Data $helperData,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        OrderRepositoryInterface $orderRepository,
        DirectoryList $directoryList,
        File $fileDriver
    ) {
        $this->_helperData = $helperData;
        $this->_orderRepository = $orderRepository;
        $this->_searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->_directoryList = $directoryList;
        $this->_fileDriver = $fileDriver;

        parent::__construct($context);
    }

    /**
     * Processa notificações Pix e atualiza o status do pedido.
     *
     * @return void
     * @throws Exception
     */
    public function execute()
    {
        try {
            $content = $this->getRequest()->getContent();
            $body = json_decode((string) $content, true);

            if ($body) {
                $this->_helperData->logger($body);
            }

            if (!isset($body['pix'][0])) {
                return;
            }

            $pixBody = $body['pix'][0];
            $txId = $pixBody['txid'];
            $params = ['txid' => $txId];

            $certificatePath =
                $this->_directoryList->getPath(DirectoryList::MEDIA)
                . '/test/'
                . $this->_helperData->getCert('pix');

            $options = $this->_helperData->getOptions();

            if ($this->_fileDriver->isExists($certificatePath)) {
                $options['certificate'] = $certificatePath;
            }

            $api = new EfiPay($options);
            $chargeNotification = $api->pixDetailCharge($params, []);
            $status = $chargeNotification['status'] ?? '';

            $searchCriteria = $this->_searchCriteriaBuilder
                ->addFilter('gerencianet_transaction_id', $txId, 'eq')
                ->create();

            $orders = $this->_orderRepository
                ->getList($searchCriteria)
                ->getItems();

            /** @var Order $order */
            foreach ($orders as $order) {
                switch ($status) {
                    case self::ATIVA:
                        $order->setState(Order::STATE_PENDING_PAYMENT);
                        $order->setStatus(Order::STATE_PENDING_PAYMENT);
                        break;

                    case self::CONCLUIDA:
                        $order->setState(Order::STATE_PROCESSING);
                        $order->setStatus(Order::STATE_PROCESSING);
                        break;

                    case self::REMOVIDA_PELO_USUARIO_RECEBEDOR:
                    case self::REMOVIDA_PELO_PSP:
                        $order->cancel();
                        break;
                }

                $this->_orderRepository->save($order);
            }
        } catch (EfiException $e) {
            $this->_helperData->logger($e->getMessage());
            throw new Exception(
                'Error Processing Request: ' . $e->getMessage(),
                1,
                $e
            );
        } catch (\Throwable $t) {
            throw new Exception(
                'Fatal Error: ' . $t->getMessage(),
                1
            );
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
