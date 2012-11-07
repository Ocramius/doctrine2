<?php
/*
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This software consists of voluntary contributions made by many individuals
 * and is licensed under the MIT license. For more information, see
 * <http://www.doctrine-project.org>.
 */

namespace Doctrine\ORM\Proxy;

use Doctrine\Common\Persistence\Mapping\ClassMetadata;
use Doctrine\Common\Util\ClassUtils;

/**
 * This factory is used to generate proxy classes. It builds proxies from given parameters, a template and class
 * metadata.
 *
 * @author Marco Pivetta <ocramius@gmail.com>
 * @since 2.4
 */
class ProxyGenerator
{
    /**
     * Used to match very simple id methods that don't need
     * to be proxied since the identifier is known.
     */
    const PATTERN_MATCH_ID_METHOD = '((public\s)?(function\s{1,}%s\s?\(\)\s{1,})\s{0,}{\s{0,}return\s{0,}\$this->%s;\s{0,}})i';

    /**
     * @var string The namespace that contains all proxy classes.
     */
    private $proxyNamespace;

    /**
     * @var string The directory that contains all proxy classes.
     */
    private $proxyDir;

    /**
     * @var string[]|callable[] map of callables used to fill in placeholders set in the template
     */
    protected $placeholders = array();

    /**
     * @var string template used as a blueprint to generate proxies
     */
    protected $proxyClassTemplate = '<?php

namespace <namespace>;

/**
 * THIS FILE WAS CREATED BY DOCTRINE\'S PROXY GENERATOR. DO NOT EDIT IT!
 */
class <proxyClassName> extends \<className> implements \Doctrine\ORM\Proxy\Proxy
{
    /**
     * @var Callable the callback responsible for loading properties in the proxy object. This callback is called with
     *      three parameters, being respectively the proxy object to be initialized, the method that triggered the
     *      initialization process and an array of ordered parameters that were passed to that method.
     */
    public $__initializer__;

    /**
     * @var Callable the callback responsible of loading properties that need to be copied in the cloned object
     */
    public $__cloner__;

    /**
     * @var bool flag indicating if this object was already initialized
     */
    public $__isInitialized__ = false;

    /**
     * @var array public properties to be lazy loaded (with their default values)
     */
    public static $lazyPublicPropertiesDefaultValues = array(<lazyLoadedPublicPropertiesDefaultValues>);

    public function __construct($entityPersister, $identifier)
    {
        <ctorImpl>
        $this->__initializer__ = function(<proxyClassName> $proxy, $method, $params) use ($entityPersister, $identifier) {
            $proxy->__setInitializer(function(){});
            $proxy->__setCloner(function(){});

            if ($proxy->__isInitialized()) {
                return;
            }

            foreach ($proxy::$lazyPublicPropertiesDefaultValues as $propertyName => $property) {
                if (!isset($proxy->$propertyName)) {
                    $proxy->$propertyName = $proxy::$lazyPublicPropertiesDefaultValues[$propertyName];
                }
            }

            $proxy->__setInitialized(true);

            if (method_exists($proxy, \'__wakeup\')) {
                $proxy->__wakeup();
            }

            if (null === $entityPersister->load($identifier, $proxy)) {
                throw new \Doctrine\ORM\EntityNotFoundException();
            }
        };

        $this->__cloner__ = function(<proxyClassName> $proxy) use ($entityPersister, $identifier) {
            if ($proxy->__isInitialized()) {
                return;
            }

            $proxy->__setInitialized(true);
            $proxy->__setInitializer(function(){});
            $class = $entityPersister->getClassMetadata();
            $original = $entityPersister->load($identifier);

            if (null === $original) {
                throw new \Doctrine\ORM\EntityNotFoundException();
            }

            foreach ($class->getReflectionClass()->getProperties() as $reflProperty) {
                $propertyName = $reflProperty->getName();

                if ($class->hasField($propertyName) || $class->hasAssociation($propertyName)) {
                    $reflProperty->setAccessible(true);
                    $reflProperty->setValue($proxy, $reflProperty->getValue($original));
                }
            }
        };
    }

    /**
     * @private
     * @deprecated please do not call this method directly anymore.
     */
    public function __load()
    {
        $cb = $this->__initializer__;
        $cb($this, \'__load\', array());
    }

    /**
     * {@inheritDoc}
     * @private
     */
    public function __isInitialized()
    {
        return $this->__isInitialized__;
    }

    /**
     * {@inheritDoc}
     * @private
     */
    public function __setInitialized($initialized)
    {
        $this->__isInitialized__ = $initialized;
    }

    /**
     * {@inheritDoc}
     * @private
     */
    public function __setInitializer($initializer)
    {
        $this->__initializer__ = $initializer;
    }

    /**
     * {@inheritDoc}
     * @private
     */
    public function __setCloner($cloner)
    {
        $this->__cloner__ = $cloner;
    }

    <magicGet>

    /*<magicSet>*/

    public function __sleep()
    {
        <sleepImpl>
    }

    public function __wakeup()
    {
        <wakeupImpl>
    }

    public function __clone()
    {
        $cb = $this->__cloner__;
        $cb($this, \'__clone\', array());
        <cloneImpl>
    }

    <methods>
}
';

    /**
     * Initializes a new instance of the <tt>ProxyFactory</tt> class that is
     * connected to the given <tt>EntityManager</tt>.
     *
     * @param string $proxyDir The directory to use for the proxy classes. It must exist.
     * @param string $proxyNs The namespace to use for the proxy classes.
     */
    public function __construct($proxyDir, $proxyNs)
    {
        if ( ! $proxyDir) {
            throw ProxyException::proxyDirectoryRequired();
        }

        if ( ! $proxyNs) {
            throw ProxyException::proxyNamespaceRequired();
        }

        $this->proxyDir        = $proxyDir;
        $this->proxyNamespace  = $proxyNs;
        $this->setPlaceholders(array(
            '<namespace>'       => array($this, 'generateNamespace'),
            '<proxyClassName>'  => array($this, 'generateProxyClassName'),
            '<className>'       => array($this, 'generateClassName'),
            '<lazyLoadedPublicPropertiesDefaultValues>' => array($this, 'generateLazyLoadedPublicPropertiesDefaultValues'),
            '<ctorImpl>'        => array($this, 'generateCtorImpl'),
            '<magicGet>'        => array($this, 'generateMagicGet'),
            '<magicSet>'        => array($this, 'generateMagicSet'),
            '<sleepImpl>'       => array($this, 'generateSleepImpl'),
            '<wakeupImpl>'      => array($this, 'generateWakeupImpl'),
            '<cloneImpl>'       => array($this, 'generateCloneImpl'),
            '<methods>'         => array($this, 'generateMethods'),
        ));
    }

    /**
     * Set the placeholders to be replaced in the template
     *
     * @param string[]|callable[] $placeholders
     */
    public function setPlaceholders(array $placeholders)
    {
        foreach ($placeholders as $name => $value) {
            $this->setPlaceholder($name, $value);
        }
    }

    /**
     * Set a placeholder to be replaced in the template
     *
     * @param string $name
     * @param string|callable $placeholders
     */
    public function setPlaceholder($name, $placeholder)
    {
        if (!is_string($placeholder) && !is_callable($placeholder)) {
            throw ProxyException::invalidPlaceholder($name);
        }

        $this->placeholders[$name] = $placeholder;
    }

    /**
     * Generate the Proxy class name
     *
     * @param string $originalClassName
     * @return string the FQCN class name of the proxy. If the proxy does not exist, it is generated
     */
    public function getProxyClassName($originalClassName)
    {
        return ClassUtils::generateProxyClassName($originalClassName, $this->proxyNamespace);
    }

    public function generateProxyClassName(ClassMetadata $class)
    {
        $proxyClassName = ClassUtils::generateProxyClassName($class->getName(), $this->proxyNamespace);
        $parts = explode('\\', strrev($proxyClassName), 2);

        return strrev($parts[0]);
    }

    public function generateNamespace(ClassMetadata $class)
    {
        $proxyClassName = ClassUtils::generateProxyClassName($class->getName(), $this->proxyNamespace);
        $parts = explode('\\', strrev($proxyClassName), 2);

        return strrev($parts[1]);
    }

    public function generateClassName(ClassMetadata $class)
    {
        return ltrim($class->getName(), '\\');
    }

    public function generateLazyLoadedPublicPropertiesDefaultValues(ClassMetadata $class)
    {
        $lazyPublicProperties = $this->getLazyLoadedPublicProperties($class);
        $values = [];

        foreach ($lazyPublicProperties as $key => $value) {
            $values[] = var_export($key, true) . ' => ' . var_export($value, true);
        }

        return implode(', ', $values);
    }

    public function generateCtorImpl(ClassMetadata $class)
    {
        $toUnset = array();

        foreach (array_keys($this->getLazyLoadedPublicProperties($class)) as $lazyPublicProperty) {
            $toUnset[] = '$this->' . $lazyPublicProperty;
        }

        $ctorImpl = empty($toUnset) ? '' : 'unset(' . implode(', ', $toUnset) . ");\n";

        foreach($class->getIdentifierFieldNames() as $identifierField) {
            $ctorImpl .= '        $this->' . $identifierField . ' = $identifier[' . var_export($identifierField, true) . "];\n";
        }

        return $ctorImpl;
    }

    public function generateMagicGet(ClassMetadata $class)
    {
        $magicGet = '';
        $lazyPublicProperties = array_keys($this->getLazyLoadedPublicProperties($class));

        if (!empty($lazyPublicProperties)) {
            $magicGet .= "        if (array_key_exists(\$name, self::\$lazyPublicPropertiesDefaultValues)) {";
            $magicGet .= "\n            \$cb = \$this->__initializer__;";
            $magicGet .= "\n            \$cb(\$this, '__get', array(\$name));";
            $magicGet .= "\n\n            return \$this->\$name;";
            $magicGet .= "\n        }\n";
        }

        if ($class->getReflectionClass()->hasMethod('__get')) {
            $magicGet .= "\n        return parent::__get(\$name)";
        } else {
            $magicGet .= "\n        throw new \\BadMethodCallException('Undefined property \"\$name\"');";
        }

        if (!empty($magicGet)) {
            return "public function __get(\$name)\n    {\n" . $magicGet . "    }\n";
        }

        return '';
    }

    public function generateMagicSet(ClassMetadata $class)
    {
        $magicSet = '';
        $lazyPublicProperties = $this->getLazyLoadedPublicProperties($class);

        if (!empty($lazyPublicProperties) || $class->getReflectionClass()->hasMethod('__set')) {
            $magicSet .= "public function __set(\$name, \$value)\n";
            $magicSet .= "    {";

            if (!empty($lazyPublicProperties)) {
                $magicSet .= "        if (array_key_exists(\$name, self::\$lazyPublicPropertiesDefaultValues)) {";
                $magicSet .= "\n            \$cb = \$this->__initializer__;";
                $magicSet .= "\n            \$cb(\$this, '__set', array(\$name, \$value));";
                $magicSet .= "\n            \$this->\$name = \$value;";
                $magicSet .= "\n\n            return;";
                $magicSet .= "\n        }";
            } else {
                $magicSet .= "\n            \$cb = \$this->__initializer__;";
                $magicSet .= "\n            \$cb(\$this, '__set', array(\$name, \$value));";
            }

            if ($class->getReflectionClass()->hasMethod('__set')) {
                $magicSet .= "\n\n        return parent::__set(\$name, \$value)";
            } else {
                $magicSet .= "\n        throw new \\BadMethodCallException('Undefined property \"' . \$name . '\"');";
            }

            $magicSet .= "\n    }";
        }

        return $magicSet;
    }

    public function generateSleepImpl(ClassMetadata $class)
    {
        $sleepImpl = '';

        if ($class->getReflectionClass()->hasMethod('__sleep')) {
            $sleepImpl .= "        \$properties = array_merge(array('__isInitialized__'), parent::__sleep());\n";

            $sleepImpl .= "\n        if(\$this->__isInitialized__) {\n";
            $sleepImpl .= "            \$properties = array_diff(\$properties, array_keys(self::\$lazyPublicPropertiesDefaultValues));\n";
            $sleepImpl .= "        }\n";

            $sleepImpl .= "\n        return \$properties;";

            return $sleepImpl;
        }

        $allProperties = array('__isInitialized__');

        /* @var $prop \ReflectionProperty */
        foreach ($class->getReflectionClass()->getProperties() as $prop) {
            $allProperties[] = $prop->getName();
        }

        $lazyPublicProperties = array_keys($this->getLazyLoadedPublicProperties($class));
        $protectedProperties = array_diff($allProperties, $lazyPublicProperties);

        foreach ($allProperties as &$property) {
            $property = var_export($property, true);
        }

        foreach ($protectedProperties as &$property) {
            $property = var_export($property, true);
        }

        $sleepImpl .= "if (\$this->__isInitialized__) {\n";
        $sleepImpl .= "            return array(" . implode(', ', $allProperties) . ");\n";
        $sleepImpl .= "        }\n";
        $sleepImpl .= "\n        return array(" . implode(', ', $protectedProperties) . ");\n";

        return $sleepImpl;
    }

    public function generateWakeupImpl(ClassMetadata $class)
    {
        $unsetPublicProperties = array();

        foreach (array_keys($this->getLazyLoadedPublicProperties($class)) as $lazyPublicProperty) {
            $unsetPublicProperties[] = '$this->' . $lazyPublicProperty;
        }

        $wakeupImpl = "\$this->__initializer__ = function(\$proxy){\n";
        $wakeupImpl .= "            \$proxy->__setInitializer(function(){});\n";
        $wakeupImpl .= "            \$proxy->__setCloner(function(){});\n";
        $wakeupImpl .= "            \$existingProperties = get_object_vars(\$proxy);\n\n";
        $wakeupImpl .= "            foreach (self::\$lazyPublicPropertiesDefaultValues as \$lazyPublicProperty => \$defaultValue) {\n";
        $wakeupImpl .= "                if (!array_key_exists(\$lazyPublicProperty, \$existingProperties)) {\n";
        $wakeupImpl .= "                    \$proxy->\$lazyPublicProperty = \$defaultValue;\n";
        $wakeupImpl .= "                }\n";
        $wakeupImpl .= "            }\n";
        $wakeupImpl .= "        };\n";
        $wakeupImpl .= "        \$this->__cloner__ = function(){};";

        if (!empty($unsetPublicProperties)) {
            $wakeupImpl .= "\n\n        if (!\$this->__isInitialized__) {";
            $wakeupImpl .= "\n            unset(" . implode(', ', $unsetPublicProperties) . ");";
            $wakeupImpl .= "\n        }";
        }

        if ($class->getReflectionClass()->hasMethod('__wakeup')) {
            $wakeupImpl .= "\n        parent::__wakeup();";
        }

        return $wakeupImpl;
    }

    public function generateCloneImpl(ClassMetadata $class)
    {
        return $class->getReflectionClass()->hasMethod('__clone') ? 'parent::__clone();' : '';
    }


    public function generateMethods(ClassMetadata $class)
    {
        $methods = '';

        $methodNames = array();
        $reflectionMethods = $class
            ->getReflectionClass()
            ->getMethods(\ReflectionMethod::IS_PUBLIC);
        $skippedMethods = array(
            '__sleep'   => true,
            '__clone'   => true,
            '__wakeup'  => true,
            '__get'     => true,
            //'__set'     => true,
        );

        foreach ($reflectionMethods as $method) {
            $name = $method->getName();

            if (
                $method->isConstructor()
                || isset($skippedMethods[strtolower($name)])
                || isset($methodNames[$name])
                || $method->isFinal()
                || $method->isStatic()
                || ! $method->isPublic()
            ) {
                continue;
            }

            $methodNames[$name] = true;

            $methods .= "\n" . '    public function ';
            if ($method->returnsReference()) {
                $methods .= '&';
            }
            $methods .= $name . '(';
            $firstParam = true;
            $parameterString = $argumentString = '';
            $parameters = array();

            foreach ($method->getParameters() as $param) {
                if ($firstParam) {
                    $firstParam = false;
                } else {
                    $parameterString .= ', ';
                    $argumentString  .= ', ';
                }

                // We need to pick the type hint class too
                if (($paramClass = $param->getClass()) !== null) {
                    $parameterString .= '\\' . $paramClass->getName() . ' ';
                } else if ($param->isArray()) {
                    $parameterString .= 'array ';
                }

                if ($param->isPassedByReference()) {
                    $parameterString .= '&';
                }

                $parameters[] = '$' . $param->getName();
                $parameterString .= '$' . $param->getName();
                $argumentString  .= '$' . $param->getName();

                if ($param->isDefaultValueAvailable()) {
                    $parameterString .= ' = ' . var_export($param->getDefaultValue(), true);
                }
            }

            $methods .= $parameterString . ')';
            $methods .= "\n" . '    {' . "\n";
            if ($this->isShortIdentifierGetter($method, $class)) {
                $identifier = lcfirst(substr($name, 3));
                $cast = in_array($class->getTypeOfField($identifier), array('integer', 'smallint')) ? '(int) ' : '';

                $methods .= '        if ($this->__isInitialized__ === false) {' . "\n";
                $methods .= '            return ' . $cast . '$this->' . $identifier . ';' . "\n";
                $methods .= '        }' . "\n\n";
            }

            $methods .= '        $cb = $this->__initializer__;' . "\n";
            $methods .= '        $cb($this, ' . var_export($name, true) . ', array(' . implode(', ', $parameters) . '));' . "\n\n";
            $methods .= '        return parent::' . $name . '(' . $argumentString . ');';
            $methods .= "\n" . '    }' . "\n";
        }

        return $methods;
    }

    /**
     * Generate the Proxy file name
     *
     * @param string $className
     * @param string $baseDir Optional base directory for proxy file name generation.
     *                        If not specified, the directory configured on the Configuration of the
     *                        EntityManager will be used by this factory.
     * @return string
     */
    public function getProxyFileName($className, $baseDir = null)
    {
        $baseDir = $baseDir ?: $this->proxyDir;

        return rtrim($baseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '__CG__' . str_replace('\\', '', $className) . '.php';
    }

    /**
     * Generates a proxy class file.
     *
     * @param ClassMetadata $class Metadata for the original class
     * @param string $fileName Filename (full path) for the generated class
     * @param string $file The proxy class template data
     */
    public function generateProxyClass(ClassMetadata $class, $fileName = null, $file = null)
    {
        $placeholders = array();

        foreach ($this->placeholders as $name => $placeholder) {
            $placeholders[$name] = is_callable($placeholder) ? $placeholder($class) : $placeholder;
        }

        $fileName = $fileName ?: $this->getProxyFileName($class->getName());
        $file = $file ? $file : $this->proxyClassTemplate;
        $file = strtr($file, $placeholders);
        //$file = str_replace($placeholders, $replacements, $file);

        $parentDirectory = dirname($fileName);

        if ( ! is_dir($parentDirectory) && (false === @mkdir($parentDirectory, 0775, true))) {
            throw ProxyException::proxyDirectoryNotWritable();
        } else if ( ! is_writable($parentDirectory)) {
            throw ProxyException::proxyDirectoryNotWritable();
        }

        $tmpFileName = $fileName . '.' . uniqid('', true);
        file_put_contents($tmpFileName, $file);
        rename($tmpFileName, $fileName);
    }

    /**
     * Check if the method is a short identifier getter.
     *
     * What does this mean? For proxy objects the identifier is already known,
     * however accessing the getter for this identifier usually triggers the
     * lazy loading, leading to a query that may not be necessary if only the
     * ID is interesting for the userland code (for example in views that
     * generate links to the entity, but do not display anything else).
     *
     * @param \ReflectionMethod $method
     * @param ClassMetadata $class
     * @return bool
     */
    private function isShortIdentifierGetter($method, ClassMetadata $class)
    {
        $identifier = lcfirst(substr($method->getName(), 3));
        $cheapCheck = (
            $method->getNumberOfParameters() == 0 &&
                substr($method->getName(), 0, 3) == "get" &&
                in_array($identifier, $class->getIdentifier(), true) &&
                $class->hasField($identifier) &&
                (($method->getEndLine() - $method->getStartLine()) <= 4)
                && in_array($class->getTypeOfField($identifier), array('integer', 'bigint', 'smallint', 'string'))
        );

        if ($cheapCheck) {
            $code = file($method->getDeclaringClass()->getFileName());
            $code = trim(implode(" ", array_slice($code, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1)));

            $pattern = sprintf(self::PATTERN_MATCH_ID_METHOD, $method->getName(), $identifier);

            if (preg_match($pattern, $code)) {
                return true;
            }
        }

        return false;
    }

    private function getLazyLoadedPublicProperties(ClassMetadata $class)
    {
        $defaultProperties = $class->getReflectionClass()->getDefaultProperties();
        $properties = array();

        foreach ($class->getReflectionClass()->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            $name = $property->getName();

            if (($class->hasField($name) || $class->hasAssociation($name)) && !$class->isIdentifier($name)) {
                $properties[$name] = $defaultProperties[$name];
            }
        }

        return $properties;
    }
}
