<?php

namespace Doctrine\Tests\ORM\Functional\Ticket;

/**
 * @group DDC-1806
 */
class DDC1806Test extends \Doctrine\Tests\OrmFunctionalTestCase
{
    protected function setUp() {
        parent::setUp();
        $this->_schemaTool->createSchema(array(
            $this->_em->getClassMetadata(__NAMESPACE__ . '\DDC1806A'),
            $this->_em->getClassMetadata(__NAMESPACE__ . '\DDC1806B'),
        ));
    }

    public function testIssue()
    {
        $a1 = new DDC1806A();
        $b1 = new DDC1806B();
        $b2 = new DDC1806B();
        $b1->setName('wrong');
        $b2->setName('correct');
        $a1->setB($b2);

        $this->_em->persist($a1);
        $this->_em->persist($b1);
        $this->_em->persist($b2);
        $this->_em->flush();
        $a1Identifier = $a1->getId();
        $this->_em->clear();

        $dql1 = "SELECT a FROM " . __NAMESPACE__ . "\\DDC1806A a WHERE a.id = :id";
        $dql2 = "SELECT a, b FROM " . __NAMESPACE__ . "\\DDC1806A a LEFT JOIN a.b b WHERE a.id = :id";

        $fetchedA1 = $this->_em->createQuery($dql1)->setParameter('id', $a1Identifier)->getOneOrNullResult()->getB()->getName();
        $this->_em->clear();
        $fetchedA2 = $this->_em->createQuery($dql2)->setParameter('id', $a1Identifier)->getOneOrNullResult()->getB()->getName();

        $this->assertEquals($fetchedA1, $fetchedA2);
    }
}

/**
 * @Table(name="DDC1806A")
 * @Entity
 */
class DDC1806A {

    /**
     * @var integer $id
     *
     * @Column(name="a_id", type="integer", nullable=false)
     * @Id
     * @GeneratedValue(strategy="IDENTITY")
     */
    private $id;

    /**
     * @ManyToOne(targetEntity="DDC1806B")
     * @JoinColumn(name="id", referencedColumnName="id")
     */
    private $b;

    public function getId() {
        return $this->id;
    }

    public function setB($b) {
        $this->b = $b;
    }

    public function getB() {
        return $this->b;
    }
}


/**
 * @Table(name="DDC1806B")
 * @Entity
 */
class DDC1806B {

    /**
     * @var integer $id
     *
     * @Column(name="id", type="integer", nullable=false)
     * @Id
     * @GeneratedValue(strategy="IDENTITY")
     */
    private $id;

    /**
     * @var string $name
     *
     * @Column(name="name", type="string", length=255, nullable=true)
     */
    private $name;

    public function getId() {
        return $this->id;
    }

    public function setName($name) {
        $this->name = $name;
    }

    public function getName() {
        return $this->name;
    }
}