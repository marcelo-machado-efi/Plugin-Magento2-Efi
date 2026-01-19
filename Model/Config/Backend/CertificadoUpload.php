<?php

namespace Gerencianet\Magento2\Model\Config\Backend;

use Magento\Config\Model\Config\Backend\File;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem\DirectoryList;

class CertificadoUpload extends File
{
    private DirectoryList $dir;

    public function __construct(
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\App\Config\ScopeConfigInterface $config,
        \Magento\Framework\App\Cache\TypeListInterface $cacheTypeList,
        \Magento\MediaStorage\Model\File\UploaderFactory $uploaderFactory,
        \Magento\Config\Model\Config\Backend\File\RequestData\RequestDataInterface $requestData,
        \Magento\Framework\Filesystem $filesystem,
        DirectoryList $dir,
        \Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
        \Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null
    ) {
        $this->dir = $dir;

        parent::__construct(
            $context,
            $registry,
            $config,
            $cacheTypeList,
            $uploaderFactory,
            $requestData,
            $filesystem,
            $resource,
            $resourceCollection
        );
    }

    public function beforeSave()
    {
        $uploadDir = rtrim($this->dir->getPath('media'), '/') . '/test/';

        $pixActive = $this->isPaymentMethodActive('gerencianet_pix');
        $openFinanceActive = $this->isPaymentMethodActive('gerencianet_open_finance');
        $needsCertificate = $pixActive || $openFinanceActive;

        $file = $this->getFileData();

        if (!empty($file) && is_array($file)) {
            $this->ensureDirExists($uploadDir);
            $this->clearDirectory($uploadDir);
            $this->setValue('');
            return parent::beforeSave();
        }

        if ($needsCertificate && !$this->hasAnyCertificateFile($uploadDir)) {
            throw new LocalizedException(
                __('Certificado obrigatório: faça upload do certificado (.pem ou .p12) para Pix ou Open Finance.')
            );
        }

        return parent::beforeSave();
    }

    protected function _getAllowedExtensions()
    {
        return ['pem', 'p12'];
    }

    private function isPaymentMethodActive(string $groupId): bool
    {
        $path = 'payment/' . $groupId . '/active';
        return (bool)$this->_config->getValue($path, $this->getScope(), $this->getScopeId());
    }

    private function ensureDirExists(string $uploadDir): void
    {
        if (is_dir($uploadDir)) {
            return;
        }

        if (!@mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            throw new LocalizedException(__('Falha ao criar o diretório de upload do certificado.'));
        }
    }

    private function clearDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if (!is_array($items)) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $item;

            if (is_dir($path)) {
                $this->clearDirectory($path);
                @rmdir($path);
                continue;
            }

            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    private function hasAnyCertificateFile(string $uploadDir): bool
    {
        if (!is_dir($uploadDir)) {
            return false;
        }

        $items = scandir($uploadDir);
        if (!is_array($items)) {
            return false;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $uploadDir . DIRECTORY_SEPARATOR . $item;

            if (!is_file($path)) {
                continue;
            }

            $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
            if ($ext === 'pem' || $ext === 'p12') {
                return true;
            }
        }

        return false;
    }
}
