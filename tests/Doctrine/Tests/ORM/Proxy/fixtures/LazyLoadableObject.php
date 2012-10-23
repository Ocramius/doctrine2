<?php

/**
 * @author Marco Pivetta <ocramius@gmail.com>
 */
class LazyLoadableObject
{
    public $firstIdentifierField;

    protected $secondIdentifierField;

    public $publicTransitientField          = 'publicTransitientFieldValue';

    public $publicPersistentField           = 'publicPersistentFieldValue';

    protected $protectedTransitientField    = 'protectedTransitientFieldValue';

    protected $protectedPersistentField     = 'protectedPersistentFieldValue';

    public function getSecondIdentifierField()
    {
        return $this->secondIdentifierField;
    }

    public function testInitializationTriggeringMethod()
    {
        return 'testInitializationTriggeringMethod';
    }
}
