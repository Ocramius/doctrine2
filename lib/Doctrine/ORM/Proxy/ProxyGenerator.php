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
     * @var string The namespace that contains all proxy classes.
     */
    private $proxyNamespace;

    /**
     * @var string The directory that contains all proxy classes.
     */
    private $proxyDir;

    /**
     * Used to match very simple id methods that don't need
     * to be proxied since the identifier is known.
     */
    const PATTERN_MATCH_ID_METHOD = '((public\s)?(function\s{1,}%s\s?\(\)\s{1,})\s{0,}{\s{0,}return\s{0,}\$this->%s;\s{0,}})i';

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
        $fileName = $fileName ? $fileName : $this->getProxyFileName($class->getName());
        $file = $file ? $file : self::$_proxyClassTemplate;
        $sleepImpl = $this->_generateSleep($class);
        $wakeupImpl = $this->_generateWakeup($class);
        $lazyLoadedPublicPropertiesDefaultValues = $this->_generateLazyLoadedPublicPropertiesDefaultValues($class);
        $ctorImpl = $this->_generateCtor($class);
        $methods = $this->_generateMethods($class);
        $magicGet = $this->_generatemagicGet($class);
        $magicSet = $this->_generatemagicSet($class);
        $cloneImpl = $class->getReflectionClass()->hasMethod('__clone') ? 'parent::__clone();' : '';

        $placeholders = array(
            '<namespace>',
            '<proxyClassName>',
            '<className>',
            '<lazyLoadedPublicPropertiesDefaultValues>',
            '<ctorImpl>',
            '<methods>',
            '<magicGet>',
            '<magicSet>',
            '<sleepImpl>',
            '<wakeupImpl>',
            '<cloneImpl>'
        );

        $className = ltrim($class->getName(), '\\');
        $proxyClassName = ClassUtils::generateProxyClassName($className, $this->proxyNamespace);
        $parts = explode('\\', strrev($proxyClassName), 2);
        $proxyClassNamespace = strrev($parts[1]);
        $proxyClassName = strrev($parts[0]);
        $replacements = array(
            $proxyClassNamespace,
            $proxyClassName,
            $className,
            $lazyLoadedPublicPropertiesDefaultValues,
            $ctorImpl,
            $methods,
            $magicGet,
            $magicSet,
            $sleepImpl,
            $wakeupImpl,
            $cloneImpl
        );

        $file = str_replace($placeholders, $replacements, $file);

        $parentDirectory = dirname($fileName);

        if ( ! is_dir($parentDirectory)) {
            if (false === @mkdir($parentDirectory, 0775, true)) {
                throw ProxyException::proxyDirectoryNotWritable();
            }
        } else if ( ! is_writable($parentDirectory)) {
            throw ProxyException::proxyDirectoryNotWritable();
        }

        $tmpFileName = $fileName . '.' . uniqid('', true);
        file_put_contents($tmpFileName, $file);
        rename($tmpFileName, $fileName);
    }

    /**
     * Generates the methods of a proxy class.
     *
     * @param ClassMetadata $class
     * @return string The code of the generated methods.
     */
    private function _generateMethods(ClassMetadata $class)
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

    /**
     * Generates the code for the __sleep method for a proxy class.
     *
     * @param $class
     * @return string
     */
    private function _generateSleep(ClassMetadata $class)
    {
        $sleepImpl = '';

        if ($class->getReflectionClass()->hasMethod('__sleep')) {
            $sleepImpl .= "\$lazyPublicProperties = array(" . $this->_generatePublicProps($class) . ");\n";
            $sleepImpl .= "        \$properties = array_merge(array('__isInitialized__'), parent::__sleep());\n\n";

            $sleepImpl .= "        if(\$this->__isInitialized__) {\n";
            $sleepImpl .= "            \$properties = array_diff(\$properties, \$lazyPublicProperties);\n";
            $sleepImpl .= "        }\n";

            $sleepImpl .= "\n        return \$properties;";

            return $sleepImpl;
        }

        $allProperties = array('__isInitialized__');

        /* @var $prop \ReflectionProperty */
        foreach ($class->getReflectionClass()->getProperties() as $prop) {
            $allProperties[] = $prop->getName();
        }

        $lazyPublicProperties = array_keys($this->_getLazyLoadedPublicProperties($class));
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

    /**
     * Generates the code for the __wakeup method for a proxy class.
     *
     * @param ClassMetadata $class
     * @return string
     */
    private function _generateWakeup(ClassMetadata $class)
    {
        $unsetPublicProperties = array();

        foreach (array_keys($this->_getLazyLoadedPublicProperties($class)) as $lazyPublicProperty) {
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

    private function _generatePublicProps(ClassMetadata $class)
    {
        $lazyPublicProperties = array();

        foreach (array_keys($this->_getLazyLoadedPublicProperties($class)) as $lazyPublicProperty) {
            $lazyPublicProperties[] = var_export($lazyPublicProperty, true) . ' => true';
        }

        return implode(', ', $lazyPublicProperties);
    }

    /**
     * Generates the code for the construct method for a proxy class.
     *
     * @param ClassMetadata $class
     * @return string
     */
    private function _generateCtor(ClassMetadata $class)
    {
        $toUnset = array();

        foreach (array_keys($this->_getLazyLoadedPublicProperties($class)) as $lazyPublicProperty) {
            $toUnset[] = '$this->' . $lazyPublicProperty;
        }

        $ctorImpl = empty($toUnset) ? '' : 'unset(' . implode(', ', $toUnset) . ");\n";

        foreach($class->getIdentifierFieldNames() as $identifierField) {
            $ctorImpl .= '        $this->' . $identifierField . ' = $identifier[' . var_export($identifierField, true) . "];\n";
        }

        return $ctorImpl;
    }

    /**
     * Generates the code for the __set method for a proxy class.
     *
     * @param ClassMetadata $class
     * @return string
     */
    private function _generateMagicGet(ClassMetadata $class)
    {
        $magicGet = '';
        $lazyPublicProperties = array_keys($this->_getLazyLoadedPublicProperties($class));

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

    /**
     * Generates the code for the __get method for a proxy class.
     *
     * @param ClassMetadata $class
     * @return string
     */
    private function _generateMagicSet(ClassMetadata $class)
    {
        $magicSet = '';

        $lazyPublicProperties = array();

        foreach ($class->getReflectionClass()->getProperties(\ReflectionProperty::IS_PUBLIC) as $publicProperty) {
            $lazyPublicProperties[$publicProperty->getName()] = true;
        }

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

    private function _getLazyLoadedPublicProperties(ClassMetadata $class)
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

    private function _generateLazyLoadedPublicPropertiesDefaultValues(ClassMetadata $class)
    {
        $lazyPublicProperties = $this->_getLazyLoadedPublicProperties($class);

        $values = [];

        foreach ($lazyPublicProperties as $key => $value) {
            $values[] = var_export($key, true) . ' => ' . var_export($value, true);
        }

        return implode(', ', $values);
    }

    /** Proxy class code template */
    private static $_proxyClassTemplate = '<?php

namespace <namespace>;

/**
 * THIS CLASS WAS GENERATED BY DOCTRINE ORM. DO NOT EDIT THIS FILE.
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
}
