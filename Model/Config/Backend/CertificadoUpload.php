<?php

namespace Gerencianet\Magento2\Model\Config\Backend;

use Exception;
use Magento\Framework\Registry;
use Magento\Framework\Filesystem;
use Magento\Framework\Model\Context;
use Gerencianet\Magento2\Helper\Data;
use Magento\Config\Model\Config\Backend\File;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\MediaStorage\Model\File\UploaderFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Config\Model\Config\Backend\File\RequestData\RequestDataInterface;
use Magento\Framework\Filesystem\DirectoryList;

class CertificadoUpload extends File
{
    /** @var Data */
    private $_gHelper;

    /** @var DirectoryList */
    protected $_dir;

    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        UploaderFactory $uploaderFactory,
        RequestDataInterface $requestData,
        Filesystem $filesystem,
        AbstractResource $resource = null,
        AbstractDb $resourceCollection = null,
        Data $gHelper,
        DirectoryList $dl
    ) {
        $this->_gHelper = $gHelper;
        $this->_dir = $dl;

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
        $name = 'certificate.pem';
        $uploadDir = $this->_dir->getPath('media') . '/test/';
        $certificatePath = $uploadDir . $name;

        $pixActive = (bool)$this->_gHelper->isPixActive();
        $openFinanceActive = (bool)$this->_gHelper->isOpenFinanceActive();

        $fileData = $this->getFileData();
        $hasUpload = !empty($fileData['tmp_name']);

        if ($hasUpload) {
            $originalName = (string)($fileData['name'] ?? '');
            $extName = $this->getExtensionName($originalName);

            if ($this->isValidExtension($extName)) {
                throw new Exception("Problema ao gravar esta configuração: Extensão Inválida! $extName", 1);
            }

            $fileId = $this->buildFileIdFromPath();

            $this->makeUpload($fileId, $uploadDir);
            $this->convertToPem($originalName, $uploadDir, $name);
            $this->removeUnusedCertificates($uploadDir);
        }

        if (($pixActive || $openFinanceActive) && !file_exists($certificatePath)) {
            throw new LocalizedException(__('É necessário realizar o upload do certificado (.pem ou .p12) para utilizar Pix e/ou Open Finance.'));
        }

        $this->setValue($name);
        return parent::beforeSave();
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
            throw new LocalizedException(__('%1', $e->getMessage()));
        }
    }

    public function convertToPem($fileName, $uploadDir, $newFilename)
    {
        $certificate = [];
        $filePath = $uploadDir . $fileName;

        if (!file_exists($filePath)) {
            return;
        }

        $pkcs12 = file_get_contents($filePath);

        if ($this->getExtensionName($fileName) === 'p12') {
            if (openssl_pkcs12_read($pkcs12, $certificate, '')) {
                $pem = $cert = $extracert1 = $extracert2 = '';

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

                $pemFileContents = $cert . $pem . $extracert1 . $extracert2;
                file_put_contents($uploadDir . $newFilename, $pemFileContents);
            }
        } else {
            file_put_contents($uploadDir . $newFilename, $pkcs12);
        }
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
        return !empty($extName) && !in_array($extName, $this->getAllowedExtensions());
    }

    public function removeUnusedCertificates($uploadDir)
    {
        if (!is_dir($uploadDir)) {
            return;
        }

        $files = array_diff(scandir($uploadDir), array('.', '..', 'certificate.pem'));

        foreach ($files as $f) {
            $path = $uploadDir . DIRECTORY_SEPARATOR . $f;
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
