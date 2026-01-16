<?php

namespace Gerencianet\Magento2\Model\Config\Backend;

use Exception;
use Gerencianet\Magento2\Helper\Data;
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
    protected DirectoryList $_dir;
    private Data $_gHelper;

    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        UploaderFactory $uploaderFactory,
        RequestDataInterface $requestData,
        Filesystem $filesystem,
        DirectoryList $dl,
        Data $gHelper,
        AbstractResource $resource = null,
        AbstractDb $resourceCollection = null
    ) {
        $this->_dir = $dl;
        $this->_gHelper = $gHelper;

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
        $this->log('cert_upload.beforeSave.start', [
            'path' => (string)$this->getPath(),
            'scope' => (string)$this->getScope(),
            'scope_id' => (string)$this->getScopeId(),
        ]);

        $name = 'certificate.pem';
        $uploadDir = $this->_dir->getPath('media') . '/test/';

        $fileData = $this->getFileData();
        $hasUpload = is_array($fileData) && !empty($fileData['tmp_name']);

        $this->log('cert_upload.fileData', [
            'hasUpload' => $hasUpload,
            'is_array' => is_array($fileData),
            'keys' => is_array($fileData) ? array_keys($fileData) : 'not_array',
            'tmp_name_set' => is_array($fileData) && array_key_exists('tmp_name', $fileData),
            'name' => is_array($fileData) ? (string)($fileData['name'] ?? '') : '',
            'error' => is_array($fileData) ? ($fileData['error'] ?? null) : null,
            'size' => is_array($fileData) ? ($fileData['size'] ?? null) : null,
        ]);

        if ($hasUpload) {
            $originalName = (string)($fileData['name'] ?? '');
            $extName = $this->getExtensionName($originalName);

            if ($this->isValidExtension($extName)) {
                $this->log('cert_upload.invalid_extension', ['ext' => $extName, 'name' => $originalName]);
                throw new Exception("Problema ao gravar esta configuração: Extensão Inválida! $extName", 1);
            }

            $fileId = $this->buildFileIdFromPath();

            $this->log('cert_upload.uploader.create', [
                'fileId' => $fileId,
                'uploadDir' => $uploadDir,
            ]);

            $this->makeUpload($fileId, $uploadDir);

            $this->log('cert_upload.convert.start', [
                'originalName' => $originalName,
                'uploadDir' => $uploadDir,
                'target' => $name,
            ]);

            $this->convertToPem($originalName, $uploadDir, $name);
            $this->removeUnusedCertificates($uploadDir);

            $this->log('cert_upload.done', ['saved_as' => $name]);
        }

        $this->setValue($name);

        $this->log('cert_upload.beforeSave.end', ['value_set' => $name]);

        return parent::beforeSave();
    }

    private function log(string $event, array $context = []): void
    {
        try {
            $this->_gHelper->logger([
                'event' => $event,
                'context' => $context,
            ]);
        } catch (Exception $e) {
        }
    }

    private function buildFileIdFromPath(): string
    {
        $path = (string)$this->getPath();
        $parts = explode('/', $path);
        $groupId = $parts[1] ?? '';

        if ($groupId === '') {
            $groupId = 'gerencianet_pix';
        }

        return 'groups[' . $groupId . '][fields][certificado][value]';
    }

    public function makeUpload($fileId, $uploadDir)
    {
        try {
            $uploader = $this->_uploaderFactory->create(['fileId' => $fileId]);
            $uploader->setAllowedExtensions($this->getAllowedExtensions());
            $uploader->setAllowRenameFiles(true);
            $uploader->addValidateCallback('size', $this, 'validateMaxSize');
            $uploader->save($uploadDir);
        } catch (Exception $e) {
            $this->log('cert_upload.uploader.exception', [
                'message' => $e->getMessage(),
                'type' => get_class($e),
            ]);
            throw new LocalizedException(__('%1', $e->getMessage()));
        }
    }

    public function convertToPem($fileName, $uploadDir, $newFilename)
    {
        $filePath = $uploadDir . $fileName;

        if (!file_exists($filePath)) {
            $this->log('cert_upload.convert.missing_source', ['filePath' => $filePath]);
            return;
        }

        $pkcs12 = file_get_contents($filePath);

        if ($this->getExtensionName($fileName) === 'p12') {
            $certificate = [];

            if (openssl_pkcs12_read($pkcs12, $certificate, '')) {
                $pem = '';
                $cert = '';
                $extracert1 = '';
                $extracert2 = '';

                if (isset($certificate['pkey'])) {
                    openssl_pkey_export($certificate['pkey'], $pem, null);
                }

                if (isset($certificate['cert'])) {
                    openssl_x509_export($certificate['cert'], $cert);
                }

                if (isset($certificate['extracerts'][0])) {
                    openssl_x509_export($certificate['extracerts'][0], $extracert1);
                }

                if (isset($certificate['extracerts'][1])) {
                    openssl_x509_export($certificate['extracerts'][1], $extracert2);
                }

                file_put_contents($uploadDir . $newFilename, $cert . $pem . $extracert1 . $extracert2);
                return;
            }

            $this->log('cert_upload.convert.pkcs12_read_failed', ['fileName' => $fileName]);
            return;
        }

        file_put_contents($uploadDir . $newFilename, $pkcs12);
    }

    public function getAllowedExtensions(): array
    {
        return ['pem', 'p12'];
    }

    public function getExtensionName($fileName): string
    {
        if (empty($fileName)) {
            return '';
        }

        return strtolower(pathinfo((string)$fileName, PATHINFO_EXTENSION));
    }

    public function isValidExtension($extName): bool
    {
        return !empty($extName) && !in_array($extName, $this->getAllowedExtensions(), true);
    }

    public function removeUnusedCertificates($uploadDir)
    {
        if (!is_dir($uploadDir)) {
            return;
        }

        $files = array_diff(scandir($uploadDir), ['.', '..', 'certificate.pem']);

        foreach ($files as $f) {
            $path = $uploadDir . DIRECTORY_SEPARATOR . $f;
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
