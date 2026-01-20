<?php

namespace Gerencianet\Magento2\Block\System\Config\Form\Field;

use Magento\Config\Block\System\Config\Form\Field\File as MageFile;
use Magento\Framework\Data\Form\Element\CollectionFactory;
use Magento\Framework\Data\Form\Element\Factory;
use Magento\Framework\Escaper;
use Magento\Framework\Filesystem\DirectoryList;
use Magento\Framework\Filesystem\DriverInterface;

class File extends MageFile
{
    /**
     * @var DirectoryList
     */
    private DirectoryList $directoryList;

    /**
     * @var DriverInterface
     */
    private DriverInterface $driver;

    /**
     * @param Factory $factoryElement
     * @param CollectionFactory $factoryCollection
     * @param Escaper $escaper
     * @param DirectoryList $directoryList
     * @param DriverInterface $driver
     * @param array $data
     */
    public function __construct(
        Factory $factoryElement,
        CollectionFactory $factoryCollection,
        Escaper $escaper,
        DirectoryList $directoryList,
        DriverInterface $driver,
        array $data = []
    ) {
        $this->directoryList = $directoryList;
        $this->driver = $driver;
        parent::__construct($factoryElement, $factoryCollection, $escaper, $data);
    }

    /**
     * @return string
     */
    protected function _getDeleteCheckbox(): string
    {
        $nomeArquivo = (string) $this->getValue();
        $nomeArquivo = ltrim($nomeArquivo, '/\\');

        $filepath = rtrim($this->directoryList->getPath('media'), '/') . '/test/' . $nomeArquivo;

        if (
            $nomeArquivo !== ''
            && $this->driver->isExists($filepath)
            && $this->driver->isFile($filepath)
        ) {
            return '<div><span style="color:#006400">Há um certificado salvo: ' . $this->escapeHtml($nomeArquivo) . '</span></div>';
        }

        return '<div><span style="color:#8b0000">Você não possui um certificado!</span></div>';
    }
}
