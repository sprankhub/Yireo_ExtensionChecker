<?php

declare(strict_types=1);

namespace Yireo\ExtensionChecker\Scan;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\NotFoundException;
use Magento\Framework\Filesystem\File\ReadFactory;
use Magento\Framework\Serialize\SerializerInterface;
use RuntimeException;

class Composer
{
    /**
     * @var DirectoryList
     */
    private $directoryList;

    /**
     * @var ReadFactory
     */
    private $readFactory;

    /**
     * @var SerializerInterface
     */
    private $serializer;

    /**
     * Composer constructor.
     * @param DirectoryList $directoryList
     * @param ReadFactory $readFactory
     * @param SerializerInterface $serializer
     */
    public function __construct(
        DirectoryList $directoryList,
        ReadFactory $readFactory,
        SerializerInterface $serializer
    ) {
        $this->directoryList = $directoryList;
        $this->readFactory = $readFactory;
        $this->serializer = $serializer;
    }

    /**
     * @param string $composerFile
     * @return array
     * @throws NotFoundException
     */
    public function getDataFromFile(string $composerFile): array
    {
        if (empty($composerFile) || !file_exists($composerFile)) {
            throw new NotFoundException(__('Composer file "' . $composerFile . '" does not exists'));
        }

        $read = $this->readFactory->create($composerFile, 'file');
        $composerContents = $read->readAll();
        $extensionData = $this->serializer->unserialize($composerContents);
        if (empty($extensionData)) {
            throw new RuntimeException('Empty contents after decoding file "' . $composerFile . '"');
        }

        return $extensionData;
    }

    /**
     * @param string $composerFile
     * @return array
     * @throws NotFoundException
     * @throws RuntimeException
     */
    public function getRequirementsFromFile(string $composerFile): array
    {
        if (empty($composerFile) || !file_exists($composerFile)) {
            throw new NotFoundException(__('Composer file "' . $composerFile . '" does not exists'));
        }

        $extensionData = $this->getDataFromFile($composerFile);
        if (!isset($extensionData['require'])) {
            throw new RuntimeException('File "' . $composerFile . '" does not have a "require" section');
        }

        $extensionDeps = $extensionData['require'];
        return $extensionDeps;
    }

    /**
     * @param string $package
     * @return string
     */
    public function getVersionByPackage(string $package): string
    {
        $installedPackages = $this->getInstalledPackages();

        foreach ($installedPackages as $installedPackage) {
            if ($installedPackage['name'] === $package) {
                return $installedPackage['version'];
            }
        }

        return '';
    }

    /**
     * @return array
     */
    public function getInstalledPackages(): array
    {
        static $installedPackages = [];

        if (!empty($installedPackages)) {
            return $installedPackages;
        }

        chdir($this->directoryList->getRoot());
        exec('composer show --format=json', $output);
        $packages = json_decode(implode('', $output), true);
        $installedPackages = $packages['installed'];
        return $installedPackages;
    }
}
