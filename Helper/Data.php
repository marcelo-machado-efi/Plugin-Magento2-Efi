<?php
declare(strict_types=1);

namespace Gerencianet\Magento2\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

/**
 * Class Data
 */
class Data extends AbstractHelper
{
    public const METHOD_CODE_CREDIT_CARD = 'gerencianet_cc';
    public const METHOD_CODE_BILLET = 'gerencianet_boleto';

    public const URL_SANDBOX = 'https://sandbox.gerencianet.com.br';
    public const URL_PRODUCTION = 'https://api.gerencianet.com.br';

    /**
     * @var EncryptorInterface
     */
    protected EncryptorInterface $_encryptor;

    /**
     * Data constructor.
     *
     * @param Context $context
     * @param EncryptorInterface $encryptor
     */
    public function __construct(
        Context $context,
        EncryptorInterface $encryptor
    ) {
        parent::__construct($context);
        $this->_encryptor = $encryptor;
    }

    /**
     * Retorna uma configuração pelo path informado.
     *
     * @param string $path
     * @return mixed
     */
    protected function getConfig(string $path)
    {
        return $this->scopeConfig->getValue(
            $path,
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Retorna as opções de autenticação da API.
     *
     * @return array
     */
    public function getOptions(): array
    {
        $options = [];

        if ($this->getConfig('payment/gerencianet_configuracoes/ambiente') === 'developer') {
            $options = [
                'clientId' => $this->getConfig(
                    'payment/gerencianet_configuracoes/gerencianet_credenciais_develop/client_id'
                ),
                'clientSecret' => $this->getConfig(
                    'payment/gerencianet_configuracoes/gerencianet_credenciais_develop/client_secret'
                ),
                'sandbox' => true,
            ];
        } elseif ($this->getConfig('payment/gerencianet_configuracoes/ambiente') === 'production') {
            $options = [
                'clientId' => $this->getConfig(
                    'payment/gerencianet_configuracoes/gerencianet_credenciais_production/client_id'
                ),
                'clientSecret' => $this->getConfig(
                    'payment/gerencianet_configuracoes/gerencianet_credenciais_production/client_secret'
                ),
                'sandbox' => false,
            ];
        }

        $partnerToken = $this->getConfig(
            'payment/gerencianet_configuracoes/partner_token'
        );

        if (!empty($partnerToken)) {
            $options['partner_token'] = $partnerToken;
        }

        return $options;
    }

    /**
     * Indica se o mTLS deve ser ignorado.
     *
     * @return mixed
     */
    public function getSkipMtls()
    {
        return $this->getConfig('payment/gerencianet_configuracoes/mtls');
    }

    /**
     * Retorna as instruções do boleto.
     *
     * @return string
     */
    public function getBilletInstructions(): string
    {
        $data = [];

        for ($i = 1; $i <= 4; $i++) {
            $data[] = $this->getConfig(
                "payment/gerencianet_boleto/gerencianet_instrucoes_boleto/linha{$i}"
            );
        }

        return implode("\n", array_filter($data));
    }

    /**
     * Retorna as configurações de multa e juros do boleto.
     *
     * @return array
     */
    public function getBilletSettings(): array
    {
        return [
            'fine' => (float)$this->getConfig('payment/gerencianet_boleto/multa') * 100,
            'interest' => (float)$this->getConfig('payment/gerencianet_boleto/juros') * 100,
        ];
    }

    /**
     * Verifica se boleto está ativo.
     *
     * @return mixed
     */
    public function isBilletActive()
    {
        return $this->getConfig('payment/gerencianet_boleto/active');
    }

    /**
     * Verifica se cartão de crédito está ativo.
     *
     * @return mixed
     */
    public function isCreditCardActive()
    {
        return $this->getConfig('payment/gerencianet_cc/active');
    }

    /**
     * Verifica se Pix está ativo.
     *
     * @return mixed
     */
    public function isPixActive()
    {
        return $this->getConfig('payment/gerencianet_pix/active');
    }

    /**
     * Verifica se Open Finance está ativo.
     *
     * @return mixed
     */
    public function isOpenFinanceActive()
    {
        return $this->getConfig('payment/gerencianet_open_finance/active');
    }

    /**
     * Registra log no Magento.
     *
     * @param mixed $data
     * @return void
     */
    public function logger($data): void
    {
        $logger = new Logger('gerencianet');
        $logger->pushHandler(
            new StreamHandler(
                BP . '/var/log/gerencianet_magento2.log',
                Logger::INFO
            )
        );

        $logger->info(json_encode($data));
    }

    /**
     * Retorna o preço mínimo do cartão.
     *
     * @return mixed
     */
    public function getPrecoMinimo()
    {
        return $this->getConfig('payment/gerencianet_cc/price_min');
    }

    /**
     * Retorna o certificado configurado.
     *
     * @param string $paymentMethod
     * @return mixed
     */
    public function getCert(string $paymentMethod)
    {
        return $this->getConfig(
            "payment/gerencianet_{$paymentMethod}/certificado"
        );
    }

    /**
     * Retorna a chave Pix.
     *
     * @return mixed
     */
    public function getChavePix()
    {
        return $this->getConfig('payment/gerencianet_pix/chave_pix');
    }

    /**
     * Retorna o nome do titular Open Finance.
     *
     * @return mixed
     */
    public function getNome()
    {
        return $this->getConfig('payment/gerencianet_open_finance/nome');
    }

    /**
     * Retorna o documento Open Finance.
     *
     * @return mixed
     */
    public function getDocumento()
    {
        return $this->getConfig('payment/gerencianet_open_finance/documento');
    }

    /**
     * Retorna o número da conta Open Finance.
     *
     * @return mixed
     */
    public function getNumeroConta()
    {
        return $this->getConfig('payment/gerencianet_open_finance/numero_conta');
    }

    /**
     * Retorna o nome da loja.
     *
     * @return mixed
     */
    public function getStoreName()
    {
        return $this->getConfig('general/store_information/name');
    }

    /**
     * Retorna o identificador da conta.
     *
     * @return mixed
     */
    public function getIdentificadorConta()
    {
        return $this->getConfig(
            'payment/gerencianet_configuracoes/identificador_conta'
        );
    }

    /**
     * Retorna a URL da API conforme o ambiente.
     *
     * @return string
     */
    public function getUrl(): string
    {
        return $this->getConfig('payment/gerencianet_configuracoes/ambiente') === 'developer'
            ? self::URL_SANDBOX
            : self::URL_PRODUCTION;
    }

    /**
     * Retorna o status padrão do pedido.
     *
     * @return string
     */
    public function getOrderStatus(): string
    {
        return $this->getConfig(
            'payment/gerencianet_configuracoes/order_status'
        ) ?? 'pending';
    }
}
