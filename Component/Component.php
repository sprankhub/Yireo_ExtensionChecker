<?php declare(strict_types=1);

namespace Yireo\ExtensionChecker\Component;

use Magento\Framework\Component\ComponentRegistrar;

class Component
{
    /**
     * @var string
     */
    private $componentName;
    
    /**
     * @var string
     */
    private $componentType;
    
    /**
     * @var string
     */
    private $packageName;
    
    /**
     * @var string
     */
    private $packageVersion;
    
    /**
     * @param string $componentName
     * @param string $componentType
     * @param string $packageName
     * @param string $packageVersion
     */
    public function __construct(
        string $componentName = '',
        string $componentType = ComponentRegistrar::MODULE,
        string $packageName = '',
        string $packageVersion = ''
    ) {
        $this->componentName = $componentName;
        $this->componentType = $componentType;
        $this->packageName = $packageName;
        $this->packageVersion = $packageVersion;
    }
    
    /**
     * @return string
     */
    public function getComponentName(): string
    {
        return $this->componentName;
    }
    
    /**
     * @return string
     */
    public function getComponentType(): string
    {
        return $this->componentType;
    }
    
    /**
     * @return string
     */
    public function getPackageName(): string
    {
        return $this->packageName;
    }
    
    /**
     * @return string
     */
    public function getPackageVersion(): string
    {
        return $this->packageVersion;
    }
    
    /**
     * @return string
     */
    public function __toString()
    {
        return $this->componentName;
    }
}