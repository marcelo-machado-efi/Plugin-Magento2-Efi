<?php

namespace Gerencianet\Magento2\Block\System\Config\Form\Field;

use Gerencianet\Magento2\Helper\Data as GnHelper;
use Magento\Config\Block\System\Config\Form\Field\File as MageFile;
use Magento\Framework\Filesystem\DirectoryList;

class File extends MageFile
{
    protected DirectoryList $directoryList;
    protected GnHelper $helper;

    public function __construct(
        \Magento\Framework\Data\Form\Element\Factory $factoryElement,
        \Magento\Framework\Data\Form\Element\CollectionFactory $factoryCollection,
        \Magento\Framework\Escaper $escaper,
        DirectoryList $directoryList,
        GnHelper $helper,
        array $data = []
    ) {
        $this->directoryList = $directoryList;
        $this->helper = $helper;
        parent::__construct($factoryElement, $factoryCollection, $escaper, $data);
    }

    protected function _getDeleteCheckbox()
    {
        $nomeArquivo = (string)$this->getValue();
        $nomeArquivo = ltrim($nomeArquivo, '/\\');

        $filepath = rtrim($this->directoryList->getPath('media'), '/') . '/test/' . $nomeArquivo;

        $this->helper->logger([
            'context' => 'system_config_file_field',
            'value' => $nomeArquivo,
            'filepath' => $filepath,
            'exists' => is_file($filepath),
        ]);

        if ($nomeArquivo !== '' && is_file($filepath)) {
            return '<div><span style="color:#006400">Há um certificado salvo: ' . $nomeArquivo . '</span></div>';
        }

        return '<div><span style="color:#8b0000">Você não possui um certificado!</span></div>';
    }
}
