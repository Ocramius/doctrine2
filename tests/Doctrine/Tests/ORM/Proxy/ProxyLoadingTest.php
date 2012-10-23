<?php

namespace Doctrine\Tests\ORM\Proxy;

use Doctrine\ORM\Proxy\ProxyFactory;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Common\Persistence\Mapping\RuntimeReflectionService;
use PHPUnit_Framework_TestCase;
use LazyLoadableObject;
use Doctrine\Tests\ORM\ProxyProxy\__CG__\LazyLoadableObject as LazyLoadableObjectProxy;

require_once __DIR__ . '/../../TestInit.php';
require_once __DIR__ . '/fixtures/LazyLoadableObject.php';

/**
 * Test the generated proxies behavior. These tests make assumptions about the structure of LazyLoadableObject
 * @author Marco Pivetta <ocramius@gmail.com>
 */
class ProxyLoadingTest extends PHPUnit_Framework_TestCase
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
     * @var ClassMetadata
     */
    protected $lazyLoadableObjectMetadata;

    public function setUp()
    {
        $this->proxyFactory = new ProxyFactory(
            $this->getMock('Doctrine\ORM\EntityManager', array(), array(), '', false),
            __DIR__ . '/generated',
            __NAMESPACE__ . 'Proxy'
        );
        $this->persisterMock = $this->getMock('Doctrine\ORM\Persisters\BasicEntityPersister', array(), array(), '', false);

        $reflectionService = new RuntimeReflectionService();
        $this->lazyLoadableObjectMetadata = new ClassMetadata('LazyLoadableObject');
        $this->lazyLoadableObjectMetadata->initializeReflection($reflectionService);
        $this->lazyLoadableObjectMetadata->setIdentifier(array('firstIdentifierField', 'secondIdentifierField'));
        // @todo mock class metadata instead of building all this

        $this->proxyFactory->generateProxyClasses(array($this->lazyLoadableObjectMetadata));

        require_once __DIR__ . '/generated/__CG__LazyLoadableObject.php';
    }

    public function testFetchingIdentifiersDoesNotCauseLazyLoading()
    {
        $proxy = new LazyLoadableObjectProxy(
            $this->persisterMock,
            array(
                'firstIdentifierField' => 'firstIdentifierFieldValue',
                'secondIdentifierField' => 'secondIdentifierFieldValue',
            )
        );

        $proxy->__setInitializer(function(){
            throw new \BadMethodCallException('Should not be called');
        });
        $this->assertSame('firstIdentifierFieldValue', $proxy->firstIdentifierField);
        $this->assertSame('secondIdentifierFieldValue', $proxy->getSecondIdentifierField());
    }
}
