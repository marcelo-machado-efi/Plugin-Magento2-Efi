<?php

declare(strict_types=1);

namespace Gerencianet\Magento2\Model\Config\Backend;

use Magento\Config\Model\Config\Backend\File;
use Magento\Config\Model\Config\Backend\File\RequestData\RequestDataInterface;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteFactory;
use Magento\Framework\Filesystem\Io\File as IoFile;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use Magento\MediaStorage\Model\File\UploaderFactory;

class CertificadoUpload extends File
{
    private DirectoryList $dir;
    private WriteFactory $writeFactory;
    private IoFile $ioFile;

    /**
     * @param Context $context
     * @param Registry $registry
     * @param ScopeConfigInterface $config
     * @param TypeListInterface $cacheTypeList
     * @param UploaderFactory $uploaderFactory
     * @param RequestDataInterface $requestData
     * @param Filesystem $filesystem
     * @param DirectoryList $dir
     * @param WriteFactory $writeFactory
     * @param IoFile $ioFile
     * @param AbstractResource|null $resource
     * @param AbstractDb|null $resourceCollection
     */
    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        UploaderFactory $uploaderFactory,
        RequestDataInterface $requestData,
        Filesystem $filesystem,
        DirectoryList $dir,
        WriteFactory $writeFactory,
        IoFile $ioFile,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null
    ) {
        $this->dir = $dir;
        $this->writeFactory = $writeFactory;
        $this->ioFile = $ioFile;

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

    /**
     * @return $this
     * @throws LocalizedException
     */
    public function beforeSave(): self
    {
        $uploadDir = rtrim($this->dir->getPath(DirectoryList::MEDIA), '/') . '/test/';

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

    /**
     * @return string[]
     */
    protected function _getAllowedExtensions(): array
    {
        return ['pem', 'p12'];
    }

    /**
     * @param string $groupId
     * @return bool
     */
    private function isPaymentMethodActive(string $groupId): bool
    {
        $path = 'payment/' . $groupId . '/active';

        return (bool) $this->_config->getValue($path, $this->getScope(), $this->getScopeId());
    }

    /**
     * @param string $uploadDir
     * @return void
     * @throws LocalizedException
     */
    private function ensureDirExists(string $uploadDir): void
    {
        $writer = $this->writeFactory->create($uploadDir);
        $driver = $writer->getDriver();

        if ($driver->isDirectory($uploadDir)) {
            return;
        }

        try {
            $driver->createDirectory($uploadDir, 0755);
        } catch (\Exception $e) {
            throw new LocalizedException(
                __('Falha ao criar o diretório de upload do certificado.'),
                $e
            );
        }
    }

    /**
     * @param string $dir
     * @return void
     */
    private function clearDirectory(string $dir): void
    {
        $writer = $this->writeFactory->create($dir);
        $driver = $writer->getDriver();

        if (!$driver->isDirectory($dir)) {
            return;
        }

        try {
            $items = $driver->readDirectory($dir);
        } catch (\Exception $e) {
            return;
        }

        foreach ($items as $path) {
            if ($driver->isDirectory($path)) {
                $this->clearDirectory($path);
                $driver->deleteDirectory($path);
                continue;
            }

            if ($driver->isFile($path)) {
                $driver->deleteFile($path);
            }
        }
    }

    /**
     * @param string $uploadDir
     * @return bool
     */
    private function hasAnyCertificateFile(string $uploadDir): bool
    {
        $writer = $this->writeFactory->create($uploadDir);
        $driver = $writer->getDriver();

        if (!$driver->isDirectory($uploadDir)) {
            return false;
        }

        try {
            $items = $driver->readDirectory($uploadDir);
        } catch (\Exception $e) {
            return false;
        }

        foreach ($items as $path) {
            if (!$driver->isFile($path)) {
                continue;
            }

            $info = $this->ioFile->getPathInfo($path);
            $ext = strtolower((string) ($info['extension'] ?? ''));

            if ($ext === 'pem' || $ext === 'p12') {
                return true;
            }
        }

        return false;
    }
}
