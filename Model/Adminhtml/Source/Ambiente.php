<?php

namespace Gerencianet\Magento2\Model\Adminhtml\Source;

/**
 * Source model para seleção de ambiente da aplicação.
 */
class Ambiente
{
    public const PRODUCTION = 'production';
    public const DEVELOPER = 'developer';

    /**
     * @inheritdoc
     */
    public function toOptionArray()
    {
        return [
            [
                'value' => self::DEVELOPER,
                'label' => __('Desenvolvimento'),
            ],
            [
                'value' => self::PRODUCTION,
                'label' => __('Produção'),
            ],
        ];
    }
}
