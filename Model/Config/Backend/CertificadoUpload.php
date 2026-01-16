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
    private const CERTIFICATE_FILENAME = 'certificate.pem';

    private const FILE_ID_PIX = 'groups[gerencianet_pix][fields][certificado][value]';
    private const FILE_ID_OPEN_FINANCE = 'groups[gerencianet_open_finance][fields][certificado][value]';

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

        $fileId = $this->detectUploadedFileId();

        if ($fileId !== null) {
            $uploadedName = $this->uploadAndGetUploadedFilename($fileId, $uploadDir);
            $this->convertToPem($uploadedName, $uploadDir, self::CERTIFICATE_FILENAME);
            $this->removeUnusedCertificates($uploadDir);

            $this->setValue(self::CERTIFICATE_FILENAME);
            return parent::beforeSave();
        }

        if ($needsCertificate && !is_file($uploadDir . self::CERTIFICATE_FILENAME)) {
            throw new LocalizedException(
                __('Certificado obrigatório: faça upload do certificado (.pem ou .p12) para Pix ou Open Finance.')
            );
        }

        $this->setValue(self::CERTIFICATE_FILENAME);
        return parent::beforeSave();
    }

    private function isPaymentMethodActive(string $groupId): bool
    {
        $path = 'payment/' . $groupId . '/active';
        return (bool)$this->_config->getValue($path, $this->getScope(), $this->getScopeId());
    }

    private function detectUploadedFileId(): ?string
    {
        if ($this->hasFileTmpName(self::FILE_ID_PIX)) {
            return self::FILE_ID_PIX;
        }

        if ($this->hasFileTmpName(self::FILE_ID_OPEN_FINANCE)) {
            return self::FILE_ID_OPEN_FINANCE;
        }

        return null;
    }

    private function hasFileTmpName(string $fileId): bool
    {
        $tmpName = $this->getNestedFilesValue($fileId, 'tmp_name');
        return is_string($tmpName) && $tmpName !== '';
    }

    private function getNestedFilesValue(string $fileId, string $leafKey)
    {
        if (!isset($_FILES) || !is_array($_FILES)) {
            return null;
        }

        $segments = $this->parseFileIdToSegments($fileId);

        $cursor = $_FILES;
        foreach ($segments as $seg) {
            if (!is_array($cursor) || !array_key_exists($seg, $cursor)) {
                return null;
            }
            $cursor = $cursor[$seg];
        }

        if (!is_array($cursor) || !array_key_exists($leafKey, $cursor)) {
            return null;
        }

        return $cursor[$leafKey];
    }

    private function parseFileIdToSegments(string $fileId): array
    {
        $normalized = str_replace(['][', '[', ']'], ['.', '.', ''], $fileId);
        $normalized = trim($normalized, '.');

        return $normalized === '' ? [] : explode('.', $normalized);
    }

    private function uploadAndGetUploadedFilename(string $fileId, string $uploadDir): string
    {
        try {
            $uploader = $this->_uploaderFactory->create(['fileId' => $fileId]);
            $uploader->setAllowedExtensions($this->getAllowedExtensions());
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

    public function convertToPem($fileName, $uploadDir, $newFilename)
    {
        $fileName = (string)$fileName;
        $uploadDir = (string)$uploadDir;
        $newFilename = (string)$newFilename;

        $filePath = $uploadDir . $fileName;

        if (!file_exists($filePath)) {
            return;
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            return;
        }

        if ($this->getExtensionName($fileName) === 'p12') {
            $certificate = [];

            if (openssl_pkcs12_read($content, $certificate, '')) {
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

            return;
        }

        file_put_contents($uploadDir . $newFilename, $content);
    }

    public function getAllowedExtensions(): array
    {
        return ['pem', 'p12'];
    }

    public function getExtensionName($fileName): string
    {
        $fileName = (string)$fileName;

        if ($fileName === '') {
            return '';
        }

        return strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    }

    public function removeUnusedCertificates($uploadDir)
    {
        $uploadDir = (string)$uploadDir;

        if (!is_dir($uploadDir)) {
            return;
        }

        $files = array_diff(scandir($uploadDir), ['.', '..', self::CERTIFICATE_FILENAME]);

        foreach ($files as $f) {
            $path = $uploadDir . DIRECTORY_SEPARATOR . $f;
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
