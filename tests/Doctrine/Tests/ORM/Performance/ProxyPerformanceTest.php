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

namespace Doctrine\Tests\ORM\Performance;

use PHPUnit_Framework_TestCase;
use Doctrine\Tests\OrmPerformanceTestCase;
use Doctrine\Common\Proxy\Proxy;

/**
 * Performance test used to measure performance of proxy instantiation
 *
 * @author Marco Pivetta <ocramius@gmail.com>
 */
class ProxyPerformanceTest extends OrmPerformanceTestCase
{
    public function testProxyInstantiationPerformance()
    {
        $em = $this->_getEntityManager();
        $this->setMaxRunningTime(20);
        $start = microtime(true);

        for ($i = 0; $i < 100000; $i += 1) {
            $user = $em->getReference('Doctrine\Tests\Models\CMS\CmsUser', array('id' => $i));
        }

        echo __FUNCTION__ . " - " . (microtime(true) - $start) . " seconds" . PHP_EOL;
    }

    public function testProxyForcedInitializationPerformance()
    {
        $em          = $this->_getEntityManager();
        $identifier  = array('id' => 1);

        // @todo emulating what is happening in the proxy factory here (should mock the persister)
        $initializer = function (Proxy $proxy) use ($identifier) {
            $proxy->__setInitializer(function () {});
            $proxy->__setCloner(function () {});

            if ($proxy->__isInitialized()) {
                return;
            }

            $properties = $proxy->__getLazyLoadedPublicProperties();

            foreach ($properties as $propertyName => $property) {
                if (!isset($proxy->$propertyName)) {
                    $proxy->$propertyName = $properties[$propertyName];
                }
            }

            $proxy->__setInitialized(true);

            if (method_exists($proxy, '__wakeup')) {
                $proxy->__wakeup();
            }
        };

        /* @var $user \Doctrine\Common\Proxy\Proxy */
        $user = $em->getReference('Doctrine\Tests\Models\CMS\CmsUser', array('id' => 1));

        $this->setMaxRunningTime(2);
        $start = microtime(true);

        for ($i = 0; $i < 100000;  $i += 1) {
            $user->__setInitializer($initializer);
            $user->__load();
        }

        echo __FUNCTION__ . " - " . (microtime(true) - $start) . " seconds" . PHP_EOL;
    }
}
