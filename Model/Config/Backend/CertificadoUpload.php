<?php

namespace Gerencianet\Magento2\Model\Config\Backend;

use Exception;
use Magento\Config\Model\Config\Backend\File;
use Magento\Config\Model\Config\Backend\File\RequestData\RequestDataInterface;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\DirectoryList;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use Magento\MediaStorage\Model\File\UploaderFactory;

class CertificadoUpload extends File
{
    private DirectoryList $dir;

    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        UploaderFactory $uploaderFactory,
        RequestDataInterface $requestData,
        Filesystem $filesystem,
        DirectoryList $dir,
        AbstractResource $resource = null,
        AbstractDb $resourceCollection = null
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

        if (!empty($file)) {
            $this->ensureDirExists($uploadDir);
            $this->clearDirectory($uploadDir);
            $this->setValue('');

            $uploadedName = $this->uploadAndGetUploadedFilename((string)$file['name'], $uploadDir);
            $this->removeUnusedFiles($uploadDir, $uploadedName);

            $this->setValue($uploadedName);
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

    private function uploadAndGetUploadedFilename(string $fileId, string $uploadDir): string
    {
        try {
            $this->ensureDirExists($uploadDir);

            $uploader = $this->_uploaderFactory->create(['fileId' => $fileId]);
            $uploader->setAllowedExtensions($this->_getAllowedExtensions());
            $uploader->setAllowRenameFiles(true);
            $uploader->addValidateCallback('size', $this, 'validateMaxSize');

            $result = $uploader->save($uploadDir);

            $fileName = is_array($result) ? (string)($result['file'] ?? '') : '';
            if ($fileName === '') {
                throw new LocalizedException(__('Falha ao salvar o arquivo do certificado.'));
            }

            return ltrim($fileName, '/\\');
        } catch (LocalizedException $e) {
            throw $e;
        } catch (Exception $e) {
            throw new LocalizedException(__('%1', $e->getMessage()));
        }
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

    private function removeUnusedFiles(string $uploadDir, string $keepFilename): void
    {
        if (!is_dir($uploadDir)) {
            return;
        }

        $items = scandir($uploadDir);
        if (!is_array($items)) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $uploadDir . DIRECTORY_SEPARATOR . $item;

            if (is_dir($path)) {
                continue;
            }

            if ($item === $keepFilename) {
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

    public function getAllowedExtensions(): array
    {
        return ['pem', 'p12'];
    }
}
