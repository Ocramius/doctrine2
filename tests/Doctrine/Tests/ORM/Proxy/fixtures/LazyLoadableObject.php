<?php

/**
 * @author Marco Pivetta <ocramius@gmail.com>
 */
class LazyLoadableObject
{
    public $publicIdentifierField;

    protected $protectedIdentifierField;

    public $publicTransientField            = 'publicTransientFieldValue';

    protected $protectedTransientField      = 'protectedTransientFieldValue';

    public $publicPersistentField           = 'publicPersistentFieldValue';

    protected $protectedPersistentField     = 'protectedPersistentFieldValue';

    public $publicAssociation               = 'publicAssociationValue';

    protected $protectedAssociation         = 'protectedAssociationValue';

    public function getProtectedIdentifierField()
    {
        return $this->protectedIdentifierField;
    }

    public function testInitializationTriggeringMethod()
    {
        return 'testInitializationTriggeringMethod';
    }

    public function getProtectedAssociation()
    {
        return $this->protectedAssociation;
    }
}
