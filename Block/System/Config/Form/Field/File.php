<?php

namespace Gerencianet\Magento2\Block\System\Config\Form\Field;

use Magento\Config\Block\System\Config\Form\Field\File as MageFile;
use Magento\Framework\Filesystem\DirectoryList;

class File extends MageFile
{
    /**
     * @var DirectoryList
     */
    protected $directoryList;

    /**
     * @param \Magento\Framework\Data\Form\Element\Factory $factoryElement
     * @param \Magento\Framework\Data\Form\Element\CollectionFactory $factoryCollection
     * @param \Magento\Framework\Escaper $escaper
     * @param DirectoryList $directoryList
     * @param array $data
     */
    public function __construct(
        \Magento\Framework\Data\Form\Element\Factory $factoryElement,
        \Magento\Framework\Data\Form\Element\CollectionFactory $factoryCollection,
        \Magento\Framework\Escaper $escaper,
        DirectoryList $directoryList, // Movido para antes do argumento opcional
        array $data = []
    ) {
        $this->directoryList = $directoryList;
        parent::__construct($factoryElement, $factoryCollection, $escaper, $data);
    }

    protected function _getDeleteCheckbox()
    {
        $html = '';
        $nomeArquivo = (string)$this->getValue();

        // Uso da propriedade explicitamente declarada
        $filepath = $this->directoryList->getPath("media") . "/test/certificate.pem";

        if (file_exists($filepath) && $nomeArquivo) {
            $color = '#006400';
            $html .= '<div><span style="color:' . $color . '">Há um certificado salvo: ' . $nomeArquivo . '</span></div>';
        } else {
            $color = '#8b0000';
            $html .= '<div><span style="color:' . $color . '">Você não possui um certificado!</span></div>';
        }

        return $html;
    }
}
