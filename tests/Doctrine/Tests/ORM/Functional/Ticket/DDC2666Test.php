<?php

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\Common\Collections\ArrayCollection;

/**
 * @group DDC-2666
 */
class DDC2666Test extends \Doctrine\Tests\OrmFunctionalTestCase
{
    /**
     * {@inheritDoc}
     */
    protected function setup()
    {
        parent::setup();

        $this->_schemaTool->createSchema(array(
            $this->_em->getClassMetadata(__NAMESPACE__ . '\DDC2666Foo'),
            $this->_em->getClassMetadata(__NAMESPACE__ . '\DDC2666Bar'),
        ));
    }

    /**
     * @todo description
     */
    public function testIssue()
    {
        $foo = new DDC2666Foo();

        $this->_em->persist($foo);
        $this->_em->flush();
        $this->_em->clear();

        /* @var $fetchedFoo \Doctrine\Tests\ORM\Functional\Ticket\DDC2666Foo */
        $fetchedFoo = $this->_em->find(__NAMESPACE__ . '\DDC2666Foo', $foo->id);

        $fetchedFoo->bars->add(new DDC2666Bar());

        $this->assertCount(1, $fetchedFoo->bars, 'The collection was filled with one element');

        $this->_em->refresh($fetchedFoo);

        $this->assertCount(0, $fetchedFoo->bars, 'The collection was reset');
    }
}

/** @Entity */
class DDC2666Foo
{
    /** @Id @Column(type="integer") @GeneratedValue */
    public $id;

    /**
     * @var DDC2666Bar[]|\Doctrine\Common\Collections\Collection
     *
     * @ManyToMany(targetEntity="DDC2666Bar")
     */
    public $bars;

    /** Constructor */
    public function __construct() {
        $this->bars = new ArrayCollection();
    }
}

/**
 * @Entity
 */
class DDC2666Bar
{
    /** @Id @Column(type="integer") @GeneratedValue */
    public $id;
}