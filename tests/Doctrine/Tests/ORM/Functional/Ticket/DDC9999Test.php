<?php

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\DiscriminatorColumn;
use Doctrine\ORM\Mapping\DiscriminatorMap;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\InheritanceType;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\MappedSuperclass;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Tools\SchemaValidator;
use Doctrine\ORM\Tools\ToolsException;
use Doctrine\Tests\OrmFunctionalTestCase;

class DDC9999Test extends OrmFunctionalTestCase
{
    /**
     * {@inheritDoc}
     */
    protected function setUp() {
        parent::setUp();

        $metadata = [
            $this->_em->getClassMetadata(DDC9999Device::class),
            $this->_em->getClassMetadata(DDC9999Hardware::class),
            $this->_em->getClassMetadata(DDC9999Loner::class),
            $this->_em->getClassMetadata(DDC9999Atex::class),
            $this->_em->getClassMetadata(DDC9999Firmware::class),
        ];

        try {
            $this->_schemaTool->createSchema($metadata);
        } catch (ToolsException $e) {
            // schema already in place
        }

        if (array_filter(array_map([(new SchemaValidator($this->_em)), 'validateClass'], $metadata))) {
            $this->fail();
        }
    }

    public function testIssue()
    {
        $firmware = new DDC9999Firmware();
        $hardware = new DDC9999Atex();

        $hardware->firmware  = $firmware;
        $firmware->devices[] = $hardware;

        $this->_em->persist($hardware);
        $this->_em->persist($firmware);
        $this->_em->flush();

        $hardwareId = $hardware->id;
        $firmwareId = $firmware->id;

        $this->_em->clear();

        /* @var $hardware DDC9999Atex */
        $hardware = $this->_em->getRepository(DDC9999Device::class)->findOneBy(['id' => $hardwareId]);

        self::assertInstanceOf(DDC9999Atex::class, $hardware);
        self::assertInstanceOf(DDC9999Firmware::class, $hardware->firmware);
        self::assertSame($firmwareId, $hardware->firmware->id);
        self::assertSame($hardwareId, $hardware->id);
    }
}


/**
 * @MappedSuperclass()
 * @Entity
 * @InheritanceType("SINGLE_TABLE")
 * @DiscriminatorColumn(name="device_type", type="string")
 * @DiscriminatorMap({
 *     DDC9999Device::class   = DDC9999Device::class,
 *     DDC9999Hardware::class = DDC9999Hardware::class,
 *     DDC9999Loner::class    = DDC9999Loner::class,
 *     DDC9999Atex::class     = DDC9999Atex::class,
 * })
 */
abstract class DDC9999Device
{
    /**
     * @var int|null
     * @Id
     * @GeneratedValue(strategy="AUTO")
     * @Column(type="integer")
     */
    public $id;
}

/**
 * @Entity
 */
abstract class DDC9999Hardware extends DDC9999Device
{
    /**
     * @var DDC9999Firmware
     *
     * @ManyToOne(targetEntity=DDC9999Firmware::class, inversedBy="devices")
     * @JoinColumn(name="firmware_id", referencedColumnName="id")
     */
    public $firmware;
}

/**
 * @Entity
 */
abstract class DDC9999Loner extends DDC9999Hardware
{
}

/**
 * @Entity
 */
class DDC9999Atex extends DDC9999Loner
{
}

/**
 * @Entity
 */
class DDC9999Firmware
{
    /**
     * @var int|null
     * @Id
     * @GeneratedValue(strategy="AUTO")
     * @Column(type="integer")
     */
    public $id;

    /**
     * The devices that have this firmware
     *
     * @var Collection|DDC9999Hardware[]
     *
     * @OneToMany(targetEntity=DDC9999Hardware::class, mappedBy="firmware")
     */
    public $devices;

    public function __construct()
    {
        $this->devices = new ArrayCollection();
    }
}
