<?php

declare(strict_types=1);

namespace Gerencianet\Magento2\Controller\Ajax;

use Gerencianet\Magento2\Helper\Data;
use Gerencianet\Magento2\Block\Checkout\OpenFinanceParticipants;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;

class ListParticipantsOpenFinance extends Action implements HttpGetActionInterface
{
    /** @var Data */
    private Data $_helperData;

    /**
     * @var OpenFinanceParticipants
     */
    protected OpenFinanceParticipants $openFinanceParticipants;

    /** @var JsonFactory */
    private JsonFactory $_jsonResultFactory;

    /**
     * @param Context $context
     * @param Data $helperData
     * @param JsonFactory $jsonResultFactory
     * @param OpenFinanceParticipants $openFinanceParticipants
     */
    public function __construct(
        Context $context,
        Data $helperData,
        JsonFactory $jsonResultFactory,
        OpenFinanceParticipants $openFinanceParticipants
    ) {
        $this->_helperData = $helperData;
        $this->_jsonResultFactory = $jsonResultFactory;
        $this->openFinanceParticipants = $openFinanceParticipants;

        parent::__construct($context);
    }

    /**
     * @inheritdoc
     */
    public function execute()
    {
        $participants = $this->openFinanceParticipants->getParticipants();
        $this->getResponse()->setBody(json_encode($participants));
    }
}
