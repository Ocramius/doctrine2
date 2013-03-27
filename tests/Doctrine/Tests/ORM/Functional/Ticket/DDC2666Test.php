<?php

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\ORM\Mapping\ClassMetadataFactory;
use Doctrine\Tests\OrmFunctionalTestCase;

/**
 * @group DDC-2666
 */
class DDC2666Test extends OrmFunctionalTestCase
{
    /**
     * {@inheritDoc}
     */
    protected function setup()
    {
        parent::setup();

        $this->_schemaTool->createSchema(array(
            $this->_em->getClassMetadata(__NAMESPACE__ . '\DDC2666Foo')
        ));
    }

    public function testCanUseEmbeddableIdentifier()
    {
        $foo = new DDC2666Foo();
        $bar = new DDC2666Bar();

        $bar->id  = 123;
        $foo->bar = $bar;

        $this->_em->persist($foo);
        $this->_em->flush();
        $this->_em->clear();

        $fetchedFoo = $this->_em->find(__NAMESPACE__ . '\\DDC2666Foo', $foo->bar->id);

        $this->assertSame($foo->bar->id, $fetchedFoo->bar->id);

        $this->_em->clear();

        $fetchedFoo = $this->_em->find(__NAMESPACE__ . '\\DDC2666Foo', array('bar.id' => $foo->bar->id));

        $this->assertSame($foo->bar->id, $fetchedFoo->bar->id);
    }
}

/** @Entity */
class DDC2666Foo
{
    /**
     * @Id @Embedded(class="DDC2666Bar")
     *
     * @var DDC2666Bar
     */
    public $bar;
}

/** @Embeddable */
class DDC2666Bar
{
    /** @Column(type="integer") */
    public $id;
}
