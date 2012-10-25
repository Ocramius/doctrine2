<?php

namespace Doctrine\Tests\ORM\Proxy;

use Doctrine\ORM\Proxy\ProxyFactory;
use Doctrine\ORM\Mapping\ClassMetadata;
use PHPUnit_Framework_TestCase;
use ReflectionClass;
use LazyLoadableObject;
use Doctrine\Tests\ORM\ProxyProxy\__CG__\LazyLoadableObject as LazyLoadableObjectProxy;

require_once __DIR__ . '/../../TestInit.php';
require_once __DIR__ . '/fixtures/LazyLoadableObject.php';

/**
 * Test the generated proxies behavior. These tests make assumptions about the structure of LazyLoadableObject
 * @author Marco Pivetta <ocramius@gmail.com>
 */
class ProxyLogicTest extends PHPUnit_Framework_TestCase
{
    /**
     * @var ProxyFactory
     */
    protected $proxyFactory;

    /**
     * @var \Doctrine\ORM\Persisters\BasicEntityPersister|\PHPUnit_Framework_MockObject_MockObject
     */
    protected $persisterMock;

    /**
     * @var ClassMetadata|\PHPUnit_Framework_MockObject_MockObject
     */
    protected $lazyLoadableObjectMetadata;

    /**
     * @var LazyLoadableObjectProxy
     */
    protected $lazyObject;

    /**
     * @var bool flag used to avoid re-generating proxy classes at every test
     */
    protected $generated = false;

    /**
     * {@inheritDoc}
     */
    public function setUp()
    {
        // @todo move proxy generation to static::setUpBeforeClass
        $this->proxyFactory = new ProxyFactory(
            $this->getMock('Doctrine\ORM\EntityManager', array(), array(), '', false),
            __DIR__ . '/generated',
            __NAMESPACE__ . 'Proxy'
        );

        // mocking a lot of classmetadata details. This helps ensuring that we won't have more requirements than needed
        // in future
        $metadata = $this->getMock('Doctrine\Common\Persistence\Mapping\ClassMetadata');
        $reflClass = new ReflectionClass('LazyLoadableObject');
        $metadata
            ->expects($this->any())
            ->method('getReflectionClass')
            ->will($this->returnValue($reflClass));
        $metadata
            ->expects($this->any())
            ->method('getName')
            ->will($this->returnValue('LazyLoadableObject'));
        $metadata
            ->expects($this->any())
            ->method('getTypeOfField')
            ->will($this->returnValue('string'));
        $metadata
            ->expects($this->any())
            ->method('getIdentifier')
            ->will($this->returnValue(array('publicIdentifierField', 'protectedIdentifierField')));
        $metadata
            ->expects($this->any())
            ->method('getIdentifierFieldNames')
            ->will($this->returnValue(array('publicIdentifierField', 'protectedIdentifierField')));
        $metadata
            ->expects($this->any())
            ->method('hasField')
            ->will($this->returnCallback(function($field){
                $fields = array(
                    'publicIdentifierField' => true,
                    'protectedIdentifierField' => true,
                    'publicPersistentField' => true,
                    'protectedPersistentField' => true,
                );

                return isset($fields[$field]);
            }));
        $metadata
            ->expects($this->any())
            ->method('hasAssociation')
            ->will($this->returnCallback(function($association){
                $associations = array(
                    'publicAssociation' => true,
                    'protectedAssociation' => true,
                );

                return isset($associations[$association]);
            }));
        $metadata
            ->expects($this->any())
            ->method('isIdentifier')
            ->will($this->returnCallback(function($field){
                $identifiers = array(
                    'publicIdentifierField' => true,
                    'protectedIdentifierField' => true,
                );

                return isset($identifiers[$field]);
            }));
        // @todo to be removed, since it is not part of the interface
        $metadata->isMappedSuperclass = false;
        $this->lazyLoadableObjectMetadata = $metadata;

        $this->persisterMock = $this->getMock('Doctrine\ORM\Persisters\BasicEntityPersister', array(), array(), '', false);
        $this->persisterMock
            ->expects($this->any())
            ->method('getClassMetadata')
            ->will($this->returnValue($this->lazyLoadableObjectMetadata));

        if (!$this->generated) {
            $this->proxyFactory->generateProxyClasses(array($metadata));
            require_once __DIR__ . '/generated/__CG__LazyLoadableObject.php';
            $this->generated = true;
        }

        $this->lazyObject = $proxy = new LazyLoadableObjectProxy(
            $this->persisterMock,
            array(
                'publicIdentifierField' => 'publicIdentifierFieldValue',
                'protectedIdentifierField' => 'protectedIdentifierFieldValue',
            )
        );
        $this->assertFalse($this->lazyObject->__isInitialized());
    }

    public function testFetchingPublicIdentifierDoesNotCauseLazyLoading()
    {
        $cb = $this->getMock('stdClass', array('cb'));
        $cb->expects($this->never())->method('cb');

        $this->lazyObject->__setInitializer(function() use ($cb) {
            $cb->cb();
        });

        $this->assertSame('publicIdentifierFieldValue', $this->lazyObject->publicIdentifierField);
    }

    public function testFetchingIdentifiersViaPublicGetterDoesNotCauseLazyLoading()
    {
        $cb = $this->getMock('stdClass', array('cb'));
        $cb->expects($this->never())->method('cb');

        $this->lazyObject->__setInitializer(function() use ($cb) {
            $cb->cb();
        });

        $this->assertSame('protectedIdentifierFieldValue', $this->lazyObject->getProtectedIdentifierField());
    }

    public function testCallingMethodCausesLazyLoading()
    {
        $cb = $this->getMock('stdClass', array('cb'));
        $cb->expects($this->once())->method('cb');
        $lazyObject = $this->lazyObject;
        $test = $this;

        $this->lazyObject->__setInitializer(function(LazyLoadableObjectProxy $proxy, $method, array $parameters) use ($cb, $test, $lazyObject) {
            $test->assertSame($lazyObject, $proxy, 'Passed in proxy corresponds with lazy loaded instance');
            $test->assertSame('testInitializationTriggeringMethod', $method, 'testInitializationTriggeringMethod is used to trigger lazy loading');
            $test->assertSame(array(), $parameters, 'no parameters passed to testInitializationTriggeringMethod');
            $cb->cb();
        });

        $this->lazyObject->testInitializationTriggeringMethod();
    }

    public function testFetchingPublicFieldsCausesLazyLoading()
    {
        $cb = $this->getMock('stdClass', array('cb'));
        $cb->expects($this->once())->method('cb');
        $lazyObject = $this->lazyObject;
        $test = $this;

        $this->lazyObject->__setInitializer(function(LazyLoadableObjectProxy $proxy, $method, array $parameters) use ($cb, $test, $lazyObject) {
            $test->assertSame($lazyObject, $proxy, 'Passed in proxy corresponds with lazy loaded instance');
            $test->assertSame('__get', $method, '__get is used to trigger lazy loading');
            $test->assertSame(array('publicPersistentField'), $parameters, 'field "publicPersistentField" is passed to __get');
            $proxy->publicPersistentField = 'loadedValue';
            $cb->cb();
        });

        $this->assertSame('loadedValue', $this->lazyObject->publicPersistentField);
    }

    public function testFetchingPublicAssociationCausesLazyLoading()
    {
        $cb = $this->getMock('stdClass', array('cb'));
        $cb->expects($this->once())->method('cb');
        $lazyObject = $this->lazyObject;
        $test = $this;

        $this->lazyObject->__setInitializer(function(LazyLoadableObjectProxy $proxy, $method, array $parameters) use ($cb, $test, $lazyObject) {
            $test->assertSame($lazyObject, $proxy, 'Passed in proxy corresponds with lazy loaded instance');
            $test->assertSame('__get', $method, '__get is used to trigger lazy loading');
            $test->assertSame(array('publicAssociation'), $parameters, 'field "publicAssociation" is passed to __get');
            $proxy->publicAssociation = 'loadedAssociation';
            $cb->cb();
        });

        $this->assertSame('loadedAssociation', $this->lazyObject->publicAssociation);
    }

    public function testFetchingProtectedAssociationViaPublicGetterCausesLazyLoading()
    {
        $cb = $this->getMock('stdClass', array('cb'));
        $cb->expects($this->once())->method('cb');
        $lazyObject = $this->lazyObject;
        $test = $this;

        $this->lazyObject->__setInitializer(function(LazyLoadableObjectProxy $proxy, $method, array $parameters) use ($cb, $test, $lazyObject) {
            $test->assertSame($lazyObject, $proxy, 'Passed in proxy corresponds with lazy loaded instance');
            $test->assertSame('getProtectedAssociation', $method, 'getProtectedAssociation is used to trigger lazy loading');
            $test->assertSame(array(), $parameters, 'no parameters passed to getProtectedAssociation');
            $proxy->publicAssociation = 'loadedAssociation';
            $cb->cb();
        });

        $this->assertSame('protectedAssociationValue', $this->lazyObject->getProtectedAssociation());
    }

    public function testLazyLoadingTriggeredOnlyAtFirstPublicPropertyAccess()
    {
        $cb = $this->getMock('stdClass', array('cb'));
        $cb->expects($this->once())->method('cb');
        $lazyObject = $this->lazyObject;
        $test = $this;

        $this->lazyObject->__setInitializer(function(LazyLoadableObjectProxy $proxy, $method, array $parameters) use ($cb, $test, $lazyObject) {
            $test->assertSame($lazyObject, $proxy, 'Passed in proxy corresponds with lazy loaded instance');
            $test->assertSame('__get', $method, '__get is used to trigger lazy loading');
            $test->assertSame(array('publicPersistentField'), $parameters, 'field "publicPersistentField" is passed to __get');
            $proxy->publicPersistentField = 'loadedValue';
            $proxy->publicAssociation = 'publicAssociationValue';
            $cb->cb();
        });

        $this->assertSame('loadedValue', $this->lazyObject->publicPersistentField);
        $this->assertSame('publicAssociationValue', $this->lazyObject->publicAssociation);

        $this->markTestIncomplete('this actually fakes the loader logic by assuming the loader will set this value - not correct?');
    }

    public function testErrorWhenAccessingNonExistentPublicProperties()
    {
        $cb = $this->getMock('stdClass', array('cb'));
        $cb->expects($this->never())->method('cb');

        $this->lazyObject->__setInitializer(function() use ($cb) {
            $cb->cb();
        });
        $this->setExpectedException('BadMethodCallException');

        $this->lazyObject->non_existing_property;

        $this->markTestIncomplete('better exception needed - define in doctrine/common');
    }

    public function testCloningCallsClonerWithClonedObject()
    {
        $cb = $this->getMock('stdClass', array('cb'));
        $cb->expects($this->once())->method('cb');
        $lazyObject = $this->lazyObject;
        $test = $this;

        $this->lazyObject->__cloner__ = function(LazyLoadableObjectProxy $proxy) use ($cb, $lazyObject, $test) {
            $this->assertNotSame($proxy, $lazyObject, 'a clone of the lazy object is passed to the cloner callback');
            $cb->cb();
            $proxy->publicAssociation = 'loadedAssociation';
        };

        $cloned = clone $this->lazyObject;
        $this->assertSame('loadedAssociation', $cloned->publicAssociation);
        $this->assertNotSame($cloned, $lazyObject, 'a clone of the lazy object is retrieved');
    }

    public function testFetchingTransientPropertiesWillNotTriggerLazyLoading()
    {
        $cb = $this->getMock('stdClass', array('cb'));
        $cb->expects($this->never())->method('cb');

        $this->lazyObject->__setInitializer(function() use ($cb) {
            $cb->cb();
        });

        $this->assertSame(
            'publicTransientFieldValue',
            $this->lazyObject->publicTransientField,
            'fetching public transient field won\'t trigger lazy loading'
        );
        $property = $this
            ->lazyLoadableObjectMetadata
            ->getReflectionClass()
            ->getProperty('protectedTransientField');
        $property->setAccessible(true);
        $this->assertSame(
            'protectedTransientFieldValue',
            $property->getValue($this->lazyObject),
            'fetching protected transient field via reflection won\'t trigger lazy loading'
        );
    }

    /**
     * Provided to guarantee backwards compatibility
     */
    public function testLoadProxyMethod()
    {
        $cb = $this->getMock('stdClass', array('cb'));
        $cb->expects($this->once())->method('cb')->with($this->lazyObject);

        $this->lazyObject->__setInitializer(function($proxy, $method, $parameters) use ($cb) {
            $this->assertSame('__load', $method);
            $this->assertSame(array(), $parameters);
            $cb->cb($proxy);
        });

        $this->lazyObject->__load();
    }

    public function testLoadingWithPersisterWillBeTriggeredOnlyOnce()
    {
        $this
            ->persisterMock
            ->expects($this->once())
            ->method('load')
            ->with(
            array(
                'publicIdentifierField' => 'publicIdentifierFieldValue',
                'protectedIdentifierField' => 'protectedIdentifierFieldValue',
            ),
            $this->lazyObject
        )
            ->will($this->returnCallback(function($id, LazyLoadableObjectProxy $lazyObject) {
            // setting a value to verify that the persister can actually set something in the object
            $lazyObject->publicAssociation = $id['publicIdentifierField'] . '-test';
            return true;
        }));

        $this->lazyObject->__load();
        $this->lazyObject->__load();
    }

    public function testFailedLoadingWillThrowOrmException()
    {
        $this
            ->persisterMock
            ->expects($this->any())
            ->method('load')
            ->will($this->returnValue(null));

        $this->setExpectedException('Doctrine\ORM\EntityNotFoundException');
        $this->lazyObject->__load();
    }

    public function testCloningWithPersister()
    {
        $this->lazyObject->publicTransientField = 'should-not-change';
        $this
            ->persisterMock
            ->expects($this->exactly(2))
            ->method('load')
            ->with(array(
                'publicIdentifierField' => 'publicIdentifierFieldValue',
                'protectedIdentifierField' => 'protectedIdentifierFieldValue',
            ))
            ->will($this->returnCallback(function() {
                $blueprint = new LazyLoadableObject();
                $blueprint->publicPersistentField = 'checked-persistent-field';
                $blueprint->publicAssociation = 'checked-association-field';
                $blueprint->publicTransientField = 'checked-transient-field';

                return $blueprint;
            }));

        $firstClone = clone $this->lazyObject;
        $this->assertSame('checked-persistent-field', $firstClone->publicPersistentField, 'Persistent fields are cloned correctly');
        $this->assertSame('checked-association-field', $firstClone->publicAssociation, 'Associations are cloned correctly');
        $this->assertSame('should-not-change', $firstClone->publicTransientField, 'Transitient fields are not overwritten');

        $secondClone = clone $this->lazyObject;
        $this->assertSame('checked-persistent-field', $secondClone->publicPersistentField, 'Persistent fields are cloned correctly');
        $this->assertSame('checked-association-field', $secondClone->publicAssociation, 'Associations are cloned correctly');
        $this->assertSame('should-not-change', $secondClone->publicTransientField, 'Transitient fields are not overwritten');

        // those should not trigger lazy loading
        $firstClone->__load();
        $secondClone->__load();
    }
}
