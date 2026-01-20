<?php
declare(strict_types=1);

namespace Gerencianet\Magento2\Controller\Redirect;

use Gerencianet\Magento2\Helper\Data;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\View\Result\PageFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Framework\View\Element\Template;

class RedirectOpenFinance extends Action implements CsrfAwareActionInterface
{
    /** @var PageFactory */
    protected $resultPageFactory;

    /** @var SearchCriteriaBuilder */
    protected $_searchCriteriaBuilder;

    /** @var OrderRepositoryInterface */
    protected $_orderRepository;

    /** @var Data */
    protected $_helperData;

    /**
     * @param Context $context
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param OrderRepositoryInterface $orderRepository
     * @param Data $helperData
     * @param PageFactory $resultPageFactory
     */
    public function __construct(
        Context $context,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        OrderRepositoryInterface $orderRepository,
        Data $helperData,
        PageFactory $resultPageFactory
    ) {
        parent::__construct($context);
        $this->resultPageFactory = $resultPageFactory;
        $this->_orderRepository = $orderRepository;
        $this->_searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->_helperData = $helperData;
    }

    /**
     * @return \Magento\Framework\App\ResponseInterface
     */
    public function execute()
    {
        $identificadorPagamento = $this->getRequest()->getParam('identificadorPagamento');

        $resultPage = $this->resultPageFactory->create(true);
        $resultPage->getConfig()->getTitle()->prepend(__('Redirect to Open Finance Page'));

        $block = $resultPage->getLayout()->createBlock(Template::class);
        $block->setTemplate('Gerencianet_Magento2::redirect/redirectopenfinance.phtml');
        $block->setData('identificadorPagamento', $identificadorPagamento);

        return $this->getResponse()->setBody($block->toHtml());
    }

    /**
     * @param RequestInterface $request
     * @return InvalidRequestException|null
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /**
     * @param RequestInterface $request
     * @return bool|null
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
