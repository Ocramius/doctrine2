<?php

namespace Doctrine\Tests\ORM\Proxy;

use Doctrine\ORM\Proxy\ProxyFactory;
use Doctrine\Common\Proxy\ProxyGenerator;
use Doctrine\Tests\Mocks\ConnectionMock;
use Doctrine\Tests\Mocks\EntityManagerMock;
use Doctrine\Tests\Mocks\UnitOfWorkMock;

require_once __DIR__ . '/../../TestInit.php';

/**
 * Test the proxy generator. Its work is generating on-the-fly subclasses of a given model, which implement the Proxy pattern.
 * @author Giorgio Sironi <piccoloprincipeazzurro@gmail.com>
 */
class ProxtFactoryTest extends \Doctrine\Tests\OrmTestCase
{
    private $_connectionMock;
    private $_uowMock;
    private $_emMock;

    /**
     * @var \Doctrine\ORM\Proxy\ProxyFactory
     */
    private $_proxyFactory;

    /**
     * {@inheritDoc}
     */
    protected function setUp()
    {
        parent::setUp();
        $this->_connectionMock = new ConnectionMock(array(), new \Doctrine\Tests\Mocks\DriverMock());
        $this->_emMock = EntityManagerMock::create($this->_connectionMock);
        $this->_uowMock = new UnitOfWorkMock($this->_emMock);
        $this->_emMock->setUnitOfWork($this->_uowMock);
        $this->_proxyFactory = new ProxyFactory($this->_emMock, __DIR__ . '/generated', 'Proxies', true);
    }

    /**
     * {@inheritDoc}
     */
    public static function tearDownAfterClass()
    {
        foreach (new \DirectoryIterator(__DIR__ . '/generated') as $file) {
            if (strstr($file->getFilename(), '.php')) {
                unlink($file->getPathname());
            }
        }
    }

    public function testReferenceProxyDelegatesLoadingToThePersister()
    {
        $identifier = array('id' => 42);
        $proxyClass = 'Proxies\__CG__\Doctrine\Tests\Models\ECommerce\ECommerceFeature';
        $persister = $this->_getMockPersister();
        $this->_uowMock->setEntityPersister('Doctrine\Tests\Models\ECommerce\ECommerceFeature', $persister);

        $proxy = $this->_proxyFactory->getProxy('Doctrine\Tests\Models\ECommerce\ECommerceFeature', $identifier);

        $persister->expects($this->atLeastOnce())
                  ->method('load')
                  ->with($this->equalTo($identifier), $this->isInstanceOf($proxyClass))
                  ->will($this->returnValue(new \stdClass())); // fake return of entity instance

        $proxy->getDescription();
    }

    /**
     * @group DDC-1771
     */
    public function testSkipAbstractClassesOnGeneration()
    {
        $cm = new \Doctrine\ORM\Mapping\ClassMetadata(__NAMESPACE__ . '\\AbstractClass');
        $cm->initializeReflection(new \Doctrine\Common\Persistence\Mapping\RuntimeReflectionService);
        $this->assertNotNull($cm->reflClass);

        $num = $this->_proxyFactory->generateProxyClasses(array($cm));

        $this->assertEquals(0, $num, "No proxies generated.");
    }

    protected function _getMockPersister()
    {
        $persister = $this->getMock('Doctrine\ORM\Persisters\BasicEntityPersister', array('load'), array(), '', false);
        return $persister;
    }
}

abstract class AbstractClass
{

}
