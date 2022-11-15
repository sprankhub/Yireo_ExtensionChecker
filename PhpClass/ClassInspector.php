<?php declare(strict_types=1);

namespace Yireo\ExtensionChecker\PhpClass;

use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Exception\NotFoundException;
use Magento\Framework\ObjectManager\ConfigInterface;
use ReflectionClass;
use ReflectionException;
use Throwable;
use Yireo\ExtensionChecker\Component\Component;
use Yireo\ExtensionChecker\Component\ComponentFactory;
use Yireo\ExtensionChecker\Exception\ComponentNotFoundException;
use Yireo\ExtensionChecker\Exception\NoClassNameException;
use Yireo\ExtensionChecker\PhpClass\ClassInspector\ClassDetectorListing;
use Yireo\ExtensionChecker\Util\ModuleInfo;

class ClassInspector
{
    private string $className = '';
    private array $registry = [];
    private Tokenizer $tokenizer;
    private ConfigInterface $objectManagerConfig;
    private ComponentFactory $componentFactory;
    private ModuleInfo $moduleInfo;
    private ClassDetectorListing $classDetectorListing;

    /**
     * ClassInspector constructor.
     * @param Tokenizer $tokenizer
     * @param ConfigInterface $objectManagerConfig
     * @param ComponentFactory $componentFactory
     * @param ModuleInfo $moduleInfo
     * @param ClassDetectorListing $classDetectorListing
     */
    public function __construct(
        Tokenizer $tokenizer,
        ConfigInterface $objectManagerConfig,
        ComponentFactory $componentFactory,
        ModuleInfo $moduleInfo,
        ClassDetectorListing $classDetectorListing
    ) {
        $this->tokenizer = $tokenizer;
        $this->objectManagerConfig = $objectManagerConfig;
        $this->componentFactory = $componentFactory;
        $this->moduleInfo = $moduleInfo;
        $this->classDetectorListing = $classDetectorListing;
    }

    /**
     * @param string $className
     * @return $this
     * @throws NoClassNameException
     */
    public function setClassName(string $className): ClassInspector
    {
        if (false === $this->isClassExists($className)) {
            throw new NoClassNameException('Class "' . $className . '" does not exist');
        }

        $this->className = $className;
        return $this;
    }

    /**
     * @param $className
     * @return bool
     */
    private function isClassExists($className): bool
    {
        try {
            return class_exists($className) || interface_exists($className) || trait_exists($className);
        } catch (Throwable $throwable) {
        }

        if (preg_match('/Factory$/', $className)) {
            $className = preg_replace('/Factory$/', '', $className);
        }

        try {
            return class_exists($className) || interface_exists($className) || trait_exists($className);
        } catch (Throwable $throwable) {
        }

        return false;
    }

    /**
     * @return string[]
     * @throws ReflectionException
     */
    public function getDependencies(): array
    {
        if (!$this->isInstantiable($this->className)) {
            return [];
        }

        $object = $this->getReflectionObject();
        $dependencies = [];

        $constructor = $object->getConstructor();
        if ($constructor) {
            $parameters = $constructor->getParameters();
            foreach ($parameters as $parameter) {
                if (!$parameter->getType()) {
                    continue;
                }

                $dependency = $this->normalizeClassName($parameter->getType()->getName());
                if ($this->isClassExists($dependency)) {
                    continue;
                }

                if (in_array($dependency, spl_classes())) {
                    continue;
                }

                if ($dependency === 'array') {
                    continue;
                }

                $dependencies[] = $this->normalizeClassName($parameter->getType()->getName());
            }
        }

        $interfaceNames = $object->getInterfaceNames();
        foreach ($interfaceNames as $interfaceName) {
            if (!$this->isClassExists($interfaceName)) {
                continue;
            }

            // @todo: Check if the name has no slashes and conclude that it is built-in?
            if ($interfaceName === 'ArrayAccess') {
                continue;
            }

            $dependencies[] = $interfaceName;
        }

        $importedClasses = $this->tokenizer->getImportedClassnamesFromFile($this->getFilename());
        foreach ($importedClasses as $importedClass) {
            $dependencies[] = $importedClass;
        }

        $dependencies = array_merge($dependencies, $this->getDependenciesFromFileContents($this->getFilename()));

        return $dependencies;
    }

    /**
     * @param string $fileName
     * @return string[]
     */
    private function getDependenciesFromFileContents(string $fileName): array
    {
        $classNames = [];
        foreach ($this->classDetectorListing->get() as $classDetector) {
            $fileContents = file_get_contents($fileName);
            $classNames = array_merge($classNames, $classDetector->getClassNames($fileContents));
        }

        return $classNames;
    }

    /**
     * @return bool
     */
    public function isDeprecated(): bool
    {
        try {
            $object = $this->getReflectionObject();
        } catch (ReflectionException|Throwable $exception) {
            return false;
        }

        if (strpos((string)$object->getDocComment(), '@deprecated') === false) {
            return false;
        }

        return true;
    }

    /**
     * @return Component
     * @throws ReflectionException
     * @throws FileSystemException
     * @throws NotFoundException
     */
    public function getComponentByClass(): Component
    {
        $parts = explode('\\', $this->className);
        if (count($parts) >= 2) {
            $moduleName = $parts[0] . '_' . $parts[1];
            if ($this->moduleInfo->isKnown($moduleName)) {
                return $this->componentFactory->createByModuleName($moduleName);
            }
        }

        $package = $this->getPackageByClass();
        if (!empty($package)) {
            return $this->componentFactory->createByLibraryName($package);
        }

        throw new ComponentNotFoundException('No component found for class "' . $this->className . '"');
    }

    /**
     * @return string
     * @throws ReflectionException
     */
    public function getPackageByClass(): string
    {
        $object = $this->getReflectionObject();
        $filename = $object->getFileName();
        if (empty($filename)) {
            return '';
        }

        if (!preg_match('/vendor\/([^\/]+)\/([^\/]+)\//', $filename, $match)) {
            return '';
        }

        return $match[1] . '/' . $match[2];
    }

    /**
     * @return string
     */
    public function getFilename(): string
    {
        try {
            $object = $this->getReflectionObject();
        } catch (ReflectionException $e) {
            return '';
        }

        return (string)$object->getFileName();
    }

    /**
     * @param $class
     * @return string
     */
    private function normalizeClassName($class): string
    {
        return is_object($class) ? get_class($class) : (string)$class;
    }

    /**
     * @param $className
     * @return bool
     */
    private function isInstantiable($className): bool
    {
        if (trait_exists($className)) {
            return false;
        }

        $instanceType = $this->objectManagerConfig->getPreference($className);
        if (empty($instanceType)) {
            return class_exists($className) || interface_exists($className);
        }

        $reflectionClass = new ReflectionClass($instanceType);
        if ($reflectionClass->isInterface()) {
            return true;
        }

        if (!$reflectionClass->isInstantiable()) {
            return false;
        }

        if ($reflectionClass->isAbstract()) {
            return false;
        }

        return true;
    }

    /**
     * @throws ReflectionException
     */
    private function getReflectionObject(): ReflectionClass
    {
        if (isset($this->registry[$this->className])) {
            return $this->registry[$this->className];
        }

        if ($this->isInstantiable($this->className) === false) {
            throw new ReflectionException('Class does not exist');
        }

        $object = new ReflectionClass($this->className);
        $this->registry[$this->className] = $object;

        return $object;
    }
}
