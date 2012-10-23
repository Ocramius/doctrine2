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

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Common\Util\ClassUtils;

/**
 * This factory is used to create proxy objects for entities at runtime.
 *
 * @author Roman Borschel <roman@code-factory.org>
 * @author Giorgio Sironi <piccoloprincipeazzurro@gmail.com>
 * @author Marco Pivetta <ocramius@gmail.com>
 * @since 2.0
 */
class ProxyFactory
{
    /** The EntityManager this factory is bound to. */
    private $_em;
    /** Whether to automatically (re)generate proxy classes. */
    private $_autoGenerate;
    /** The namespace that contains all proxy classes. */
    private $_proxyNamespace;
    /** The directory that contains all proxy classes. */
    private $_proxyDir;

    /**
     * Used to match very simple id methods that don't need
     * to be proxied since the identifier is known.
     *
     * @var string
     */
    const PATTERN_MATCH_ID_METHOD = '((public\s)?(function\s{1,}%s\s?\(\)\s{1,})\s{0,}{\s{0,}return\s{0,}\$this->%s;\s{0,}})i';

    /**
     * Initializes a new instance of the <tt>ProxyFactory</tt> class that is
     * connected to the given <tt>EntityManager</tt>.
     *
     * @param EntityManager $em The EntityManager the new factory works for.
     * @param string $proxyDir The directory to use for the proxy classes. It must exist.
     * @param string $proxyNs The namespace to use for the proxy classes.
     * @param boolean $autoGenerate Whether to automatically generate proxy classes.
     */
    public function __construct(EntityManager $em, $proxyDir, $proxyNs, $autoGenerate = false)
    {
        if ( ! $proxyDir) {
            throw ProxyException::proxyDirectoryRequired();
        }
        if ( ! $proxyNs) {
            throw ProxyException::proxyNamespaceRequired();
        }
        $this->_em = $em;
        $this->_proxyDir = $proxyDir;
        $this->_autoGenerate = $autoGenerate;
        $this->_proxyNamespace = $proxyNs;
    }

    /**
     * Gets a reference proxy instance for the entity of the given type and identified by
     * the given identifier.
     *
     * @param string $className
     * @param mixed $identifier
     * @return object
     */
    public function getProxy($className, $identifier)
    {
        $fqn = ClassUtils::generateProxyClassName($className, $this->_proxyNamespace);

        if (! class_exists($fqn, false)) {
            $fileName = $this->getProxyFileName($className);
            if ($this->_autoGenerate) {
                $this->_generateProxyClass($this->_em->getClassMetadata($className), $fileName, self::$_proxyClassTemplate);
            }
            require $fileName;
        }

        $entityPersister = $this->_em->getUnitOfWork()->getEntityPersister($className);

        return new $fqn($entityPersister, $identifier);
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
    private function getProxyFileName($className, $baseDir = null)
    {
        $proxyDir = $baseDir ?: $this->_proxyDir;

        return $proxyDir . DIRECTORY_SEPARATOR . '__CG__' . str_replace('\\', '', $className) . '.php';
    }

    /**
     * Generates proxy classes for all given classes.
     *
     * @param array $classes The classes (ClassMetadata instances) for which to generate proxies.
     * @param string $toDir The target directory of the proxy classes. If not specified, the
     *                      directory configured on the Configuration of the EntityManager used
     *                      by this factory is used.
     * @return int Number of generated proxies.
     */
    public function generateProxyClasses(array $classes, $toDir = null)
    {
        $proxyDir = $toDir ?: $this->_proxyDir;
        $proxyDir = rtrim($proxyDir, DIRECTORY_SEPARATOR);
        $num = 0;

        foreach ($classes as $class) {
            /* @var $class ClassMetadata */
            if ($class->isMappedSuperclass || $class->reflClass->isAbstract()) {
                continue;
            }

            $proxyFileName = $this->getProxyFileName($class->name, $proxyDir);

            $this->_generateProxyClass($class, $proxyFileName, self::$_proxyClassTemplate);
            $num++;
        }

        return $num;
    }

    /**
     * Generates a proxy class file.
     *
     * @param ClassMetadata $class Metadata for the original class
     * @param string $fileName Filename (full path) for the generated class
     * @param string $file The proxy class template data
     */
    private function _generateProxyClass(ClassMetadata $class, $fileName, $file)
    {
        $sleepImpl = $this->_generateSleep($class);
        $wakeupImpl = $this->_generateWakeup($class);
        $publicProps = $this->_generatePublicProps($class);
        $ctorImpl = $this->_generateCtor($class);
        $methods = $this->_generateMethods($class);
        $magicGet = $this->_generatemagicGet($class);
        $magicSet = $this->_generatemagicSet($class);
        $cloneImpl = $class->getReflectionClass()->hasMethod('__clone') ? 'parent::__clone();' : ''; // hasMethod() checks case-insensitive

        $placeholders = array(
            '<namespace>',
            '<proxyClassName>',
            '<className>',
            '<publicProps>',
            '<ctorImpl>',
            '<methods>',
            '<magicGet>',
            '<magicSet>',
            '<sleepImpl>',
            '<wakeupImpl>',
            '<cloneImpl>'
        );

        $className = ltrim($class->getName(), '\\');
        $proxyClassName = ClassUtils::generateProxyClassName($className, $this->_proxyNamespace);
        $parts = explode('\\', strrev($proxyClassName), 2);
        $proxyClassNamespace = strrev($parts[1]);
        $proxyClassName = strrev($parts[0]);

        $replacements = array(
            $proxyClassNamespace,
            $proxyClassName,
            $className,
            $publicProps,
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

        $tmpFileName = $fileName . '.' . uniqid("", true);
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
        /* @var $method \ReflectionMethod */
        foreach ($class->getReflectionClass()->getMethods() as $method) {
            if (
                $method->isConstructor()
                || in_array(strtolower($method->getName()), array('__sleep', '__clone', '__wakeup', '__get'))
                || isset($methodNames[$method->getName()])
            ) {
                continue;
            }

            $methodNames[$method->getName()] = true;

            if ($method->isPublic() && ! $method->isFinal() && ! $method->isStatic()) {
                $methods .= "\n" . '    public function ';
                if ($method->returnsReference()) {
                    $methods .= '&';
                }
                $methods .= $method->getName() . '(';
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
                    $identifier = lcfirst(substr($method->getName(), 3));
                    $cast = in_array($class->getTypeOfField($identifier), array('integer', 'smallint')) ? '(int) ' : '';

                    $methods .= '        if ($this->__isInitialized__ === false) {' . "\n";
                    $methods .= '            return ' . $cast . '$this->' . $identifier . ';' . "\n";
                    $methods .= '        }' . "\n";
                }

                // @todo can actually pack method parameters here via reflection
                $methods .= '        $cb = $this->__initializer__;' . "\n";
                $methods .= '        $cb($this, ' . var_export($method->getName(), true) . ', array(' . implode(', ', $parameters) . '));' . "\n";
                $methods .= '        return parent::' . $method->getName() . '(' . $argumentString . ');';
                $methods .= "\n" . '    }' . "\n";
            }
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
        if ($class->getReflectionClass()->hasMethod('__sleep')) {
            $sleepImpl = "\$properties = array_merge(array('__isInitialized__'), parent::__sleep());\n";
            $sleepImpl .= "\n    if(\$this->__isInitialized__) {\n";
            $sleepImpl .= "        \$properties = array_diff(\$properties, self::\$_publicProperties);\n";
            $sleepImpl .= "    }\n";
        } else {
            $allProperties = array('__isInitialized__');

            /* @var $prop \ReflectionProperty */
            foreach ($class->getReflectionClass()->getProperties() as $prop) {
                $allProperties[] = $prop->getName();
            }

            $publicProperties = $this->_getLazyLoadedPublicProperties($class);
            $protectedProperties = array_diff($allProperties, $publicProperties);

            $sleepImpl = "if (\$this->__isInitialized__) {\n";
            $sleepImpl .= "    \$properties = " . var_export($allProperties, true) . ";\n";
            $sleepImpl .= "} else {\n";
            $sleepImpl .= "    \$properties = " . var_export($protectedProperties, true) . ";\n";
            $sleepImpl .= "}\n";
        }

        $sleepImpl .= "\nreturn \$properties;";

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
        $wakeupImpl = "\$this->__initializer__ = function(){};\n";
        $wakeupImpl .= "        \$this->__cloner__      = function(){};";

        $unsetPublicProperties = array();

        foreach ($this->_getLazyLoadedPublicProperties($class) as $persistedPublicProperty) {
            $unsetPublicProperties[] = '$this->' . $persistedPublicProperty;
        }

        if (!empty($unsetPublicProperties)) {
            $wakeupImpl .= "\n        if (!\$this->__isInitialized__) {";
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
        $publicProperties = array();

        foreach ($this->_getLazyLoadedPublicProperties($class) as $persistedPublicProperty) {
            $publicProperties[$persistedPublicProperty] = true;
        }

        return var_export($publicProperties, true);
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
        $toStore = array();
        $lazyLoadedProperties = $this->_getLazyLoadedPublicProperties($class);

        foreach ($lazyLoadedProperties as $persistedPublicProperty) {
            $toStore[] = var_export($persistedPublicProperty, true) . ' => $this->' . $persistedPublicProperty;
            $toUnset[] = '$this->' . $persistedPublicProperty;
        }

        $ctorImpl = '$originalValues = array(' . implode(', ', $toStore) . '); ';
        $ctorImpl .= empty($toUnset) ? '' : 'unset(' . implode(', ', $toUnset) . ');' . "\n";

        foreach($class->getIdentifierFieldNames() as $identifierField) {
            $ctorImpl .= '$this->' . $identifierField . ' = $identifier[' . var_export($identifierField, true) . "];\n";
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

        $publicProperties = array();

        foreach ($class->getReflectionClass()->getProperties(\ReflectionProperty::IS_PUBLIC) as $publicProperty) {
            $publicProperties[$publicProperty->getName()] = true;
        }

        if (!empty($publicProperties)) {
            $magicGet .= "\n\n        if (isset(self::\$_publicProperties[\$name])) {";

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

        return $magicGet;
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

        $publicProperties = array();

        foreach ($class->getReflectionClass()->getProperties(\ReflectionProperty::IS_PUBLIC) as $publicProperty) {
            $publicProperties[$publicProperty->getName()] = true;
        }

        if (!empty($publicProperties) || $class->getReflectionClass()->hasMethod('__set')) {
            $magicSet .= "public function __set(\$name, \$value)\n";
            $magicSet .= "    {";

            if (!empty($publicProperties)) {
                $magicSet .= "\n    if (isset(self::\$_publicProperties[\$name])) {";
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
        $properties = array();

        foreach ($class->getReflectionClass()->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            $name = $property->getName();

            if (($class->hasField($name) || $class->hasAssociation($name)) && !$class->isIdentifier($name)) {
                $properties[] = $name;
            }
        }

        return $properties;
    }

    /** Proxy class code template */
    private static $_proxyClassTemplate = '<?php

namespace <namespace>;

/**
 * THIS CLASS WAS GENERATED BY DOCTRINE ORM. DO NOT EDIT THIS FILE.
 */
class <proxyClassName> extends \<className> implements \Doctrine\ORM\Proxy\Proxy
{
    public $__initializer__;

    public $__cloner__;

    private static $_publicProperties = <publicProps>;

    public $__isInitialized__ = false;

    public function __construct($entityPersister, $identifier)
    {
        $publicProperties = self::$_publicProperties;

        <ctorImpl>

        $this->__initializer__ = function(<proxyClassName> $proxy, $method, $params) use ($entityPersister, $identifier, $originalValues, $publicProperties) {
            $proxy->__initializer__ = $proxy->__cloner__ = function(){};

            if ($proxy->__isInitialized__) {
                return;
            }

            foreach ($publicProperties as $propertyName => $property) {
                if (!isset($proxy->$propertyName)) {
                    $proxy->$propertyName = isset($originalValues[$propertyName]) ? $originalValues[$propertyName] : null;
                }
            }

            $proxy->__isInitialized__ = true;

            if (method_exists($proxy, \'__wakeup\')) {
                $proxy->__wakeup();
            }

            if (null === $entityPersister->load($identifier, $proxy)) {
                throw new \Doctrine\ORM\EntityNotFoundException();
            }
        };

        $this->__cloner__ = function(<proxyClassName> $proxy) use ($entityPersister, $identifier) {
            $proxy->__initializer__ = $proxy->__cloner__ = function(){};

            if ($proxy->__isInitialized__) {
                return;
            }

            $proxy->__isInitialized__ = true;
            $class = $entityPersister->getClassMetadata();
            $original = $entityPersister->load($identifier);

            if (null === $original) {
                throw new \Doctrine\ORM\EntityNotFoundException();
            }

            foreach ($class->getReflectionClass()->getProperties() as $name => $reflProperty) {
                $reflProperty->setValue($proxy, $class->getFieldValue($original, $name));
            }
        };
    }

    /**
     * @private
     * @todo remove
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
    public function __setInitializer($initializer)
    {
        $this->__initializer__ = $initializer;
    }

    public function __get($name)
    {
        <magicGet>
    }

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
