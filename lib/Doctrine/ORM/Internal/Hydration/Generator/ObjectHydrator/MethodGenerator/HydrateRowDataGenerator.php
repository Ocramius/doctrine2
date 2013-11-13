<?php

namespace Doctrine\ORM\Internal\Generator\ObjectHydrator\MethodGenerator;


use Doctrine\ORM\Query\ResultSetMapping;

class HydrateRowDataGenerator
{
    public function generateCrap($idTemplate)
    {

    }

    public function generateGatherRowData()
    {
        /* @var $rsm ResultSetMapping */
        $cache = array();

        foreach ($rsm->fieldMappings as $key => $fieldName) {

        }
    }

    private function generateScalarsExtraction($rowDataName)
    {
        return <<<PHP
        // Extract scalar values. They're appended at the end.
        if (isset(\${$rowDataName}['scalars'])) {
            \$scalars = \${$rowDataName}['scalars'];

            unset(\${$rowDataName}['scalars']);

            if (empty(\${$rowDataName})) {
                ++\$this->resultCounter;
            }
        }

PHP;
    }

    private function generateNewObjectsExtraction($rowDataName)
    {
        return <<<PHP
        // Extract "new" object constructor arguments. They're appended at the end.
        if (isset(\${$rowDataName}['newObjects'])) {
            \$newObjects = \${$rowDataName}['newObjects'];

            unset(\${$rowDataName}['newObjects']);

            if (empty(\${$rowDataName})) {
                ++\$this->resultCounter;
            }
        }

PHP;
    }

    private function generateRootResultElement()
    {
        return <<<PHP
                // PATH C: Its a root result element
                \$this->rootAliases[\$dqlAlias] = true; // Mark as root alias
                \$entityKey = \$this->_rsm->entityMappings[\$dqlAlias] ?: 0;

                // if this row has a NULL value for the root result id then make it a null result.
                if ( ! isset(\$nonemptyComponents[\$dqlAlias]) ) {
                    if (\$this->_rsm->isMixed) {
                        \$result[] = array(\$entityKey => null);
                    } else {
                        \$result[] = null;
                    }
                    \$resultKey = \$this->resultCounter;
                    ++\$this->resultCounter;
                    continue;
                }

                // check for existing result from the iterations before
                if ( ! isset(\$this->identifierMap[\$dqlAlias][\$id[\$dqlAlias]])) {
                    \$element = \$this->getEntity(\$rowData[\$dqlAlias], \$dqlAlias);

                    if (\$this->_rsm->isMixed) {
                        \$element = array(\$entityKey => \$element);
                    }

                    if (isset(\$this->_rsm->indexByMap[\$dqlAlias])) {
                        \$resultKey = \$row[\$this->_rsm->indexByMap[\$dqlAlias]];

                        if (isset(\$this->_hints['collection'])) {
                            \$this->_hints['collection']->hydrateSet(\$resultKey, \$element);
                        }

                        \$result[\$resultKey] = \$element;
                    } else {
                        \$resultKey = \$this->resultCounter;
                        ++\$this->resultCounter;

                        if (isset(\$this->_hints['collection'])) {
                            \$this->_hints['collection']->hydrateAdd(\$element);
                        }

                        \$result[] = \$element;
                    }

                    \$this->identifierMap[\$dqlAlias][\$id[\$dqlAlias]] = \$resultKey;

                    // Update result pointer
                    \$this->resultPointers[\$dqlAlias] = \$element;

                } else {
                    // Update result pointer
                    \$index = \$this->identifierMap[\$dqlAlias][\$id[\$dqlAlias]];
                    \$this->resultPointers[\$dqlAlias] = \$result[\$index];
                    \$resultKey = \$index;
                    /*if (\$this->_rsm->isMixed) {
                        \$result[] = \$result[\$index];
                        ++\$this->_resultCounter;
                    }*/
                }
PHP;

    }

    private function generateAppendScalars()
    {
        return <<<PHP
        // Append scalar values to mixed result sets
        if (isset(\$scalars)) {
            if ( ! isset(\$resultKey) ) {
                if (isset(\$this->_rsm->indexByMap['scalars'])) {
                    \$resultKey = \$row[\$this->_rsm->indexByMap['scalars']];
                } else {
                    \$resultKey = \$this->resultCounter - 1;
                }
            }

            foreach (\$scalars as \$name => \$value) {
                \$result[\$resultKey][\$name] = \$value;
            }
        }
PHP;
    }

    private function generateAppendNewObjects()
    {
        return <<<PHP
        // Append new object to mixed result sets
        if (isset(\$newObjects)) {
            if ( ! isset(\$resultKey) ) {
                \$resultKey = \$this->resultCounter - 1;
            }

            \$count = count(\$newObjects);

            foreach (\$newObjects as \$objIndex => \$newObject) {
                \$class  = \$newObject['class'];
                \$args   = \$newObject['args'];
                \$obj    = \$class->newInstanceArgs(\$args);

                if (\$count === 1) {
                    \$result[\$resultKey] = \$obj;

                    continue;
                }

                \$result[\$resultKey][\$objIndex] = \$obj;
            }
        }
PHP;
    }

    private function generateCollectionValuedAssociationHydration()
    {
        return <<<PHP
                    \$reflFieldValue = \$reflField->getValue(\$parentObject);
                    // PATH A: Collection-valued association
                    if (isset(\$nonemptyComponents[\$dqlAlias])) {
                        \$collKey = \$oid . \$relationField;
                        if (isset(\$this->initializedCollections[\$collKey])) {
                            \$reflFieldValue = \$this->initializedCollections[\$collKey];
                        } else if ( ! isset(\$this->existingCollections[\$collKey])) {
                            \$reflFieldValue = \$this->initRelatedCollection(\$parentObject, \$parentClass, \$relationField, \$parentAlias);
                        }

                        \$indexExists    = isset(\$this->identifierMap[\$path][\$id[\$parentAlias]][\$id[\$dqlAlias]]);
                        \$index          = \$indexExists ? \$this->identifierMap[\$path][\$id[\$parentAlias]][\$id[\$dqlAlias]] : false;
                        \$indexIsValid   = \$index !== false ? isset(\$reflFieldValue[\$index]) : false;

                        if ( ! \$indexExists || ! \$indexIsValid) {
                            if (isset(\$this->existingCollections[\$collKey])) {
                                // Collection exists, only look for the element in the identity map.
                                if (\$element = \$this->getEntityFromIdentityMap(\$entityName, \$data)) {
                                    \$this->resultPointers[\$dqlAlias] = \$element;
                                } else {
                                    unset(\$this->resultPointers[\$dqlAlias]);
                                }
                            } else {
                                \$element = \$this->getEntity(\$data, \$dqlAlias);

                                if (isset(\$this->_rsm->indexByMap[\$dqlAlias])) {
                                    \$indexValue = \$row[\$this->_rsm->indexByMap[\$dqlAlias]];
                                    \$reflFieldValue->hydrateSet(\$indexValue, \$element);
                                    \$this->identifierMap[\$path][\$id[\$parentAlias]][\$id[\$dqlAlias]] = \$indexValue;
                                } else {
                                    \$reflFieldValue->hydrateAdd(\$element);
                                    \$reflFieldValue->last();
                                    \$this->identifierMap[\$path][\$id[\$parentAlias]][\$id[\$dqlAlias]] = \$reflFieldValue->key();
                                }
                                // Update result pointer
                                \$this->resultPointers[\$dqlAlias] = \$element;
                            }
                        } else {
                            // Update result pointer
                            \$this->resultPointers[\$dqlAlias] = \$reflFieldValue[\$index];
                        }
                    } else if ( ! \$reflFieldValue) {
                        \$reflFieldValue = \$this->initRelatedCollection(\$parentObject, \$parentClass, \$relationField, \$parentAlias);
                    } else if (\$reflFieldValue instanceof PersistentCollection && \$reflFieldValue->isInitialized() === false) {
                        \$reflFieldValue->setInitialized(true);
                    }

PHP;
    }

    private function generateSingleValuedAssociationHydration()
    {
        return <<<PHP

                    // PATH B: Single-valued association
                    \$reflFieldValue = \$reflField->getValue(\$parentObject);
                    if ( ! \$reflFieldValue || isset(\$this->_hints[Query::HINT_REFRESH]) || (\$reflFieldValue instanceof Proxy && !\$reflFieldValue->__isInitialized__)) {
                        // we only need to take action if this value is null,
                        // we refresh the entity or its an unitialized proxy.
                        if (isset(\$nonemptyComponents[\$dqlAlias])) {
                            \$element = \$this->getEntity(\$data, \$dqlAlias);
                            \$reflField->setValue(\$parentObject, \$element);
                            \$this->_uow->setOriginalEntityProperty(\$oid, \$relationField, \$element);
                            \$targetClass = \$this->ce[\$relation['targetEntity']];

                            if (\$relation['isOwningSide']) {
                                //TODO: Just check hints['fetched'] here?
                                // If there is an inverse mapping on the target class its bidirectional
                                if (\$relation['inversedBy']) {
                                    \$inverseAssoc = \$targetClass->associationMappings[\$relation['inversedBy']];
                                    if (\$inverseAssoc['type'] & ClassMetadata::TO_ONE) {
                                        \$targetClass->reflFields[\$inverseAssoc['fieldName']]->setValue(\$element, \$parentObject);
                                        \$this->_uow->setOriginalEntityProperty(spl_object_hash(\$element), \$inverseAssoc['fieldName'], \$parentObject);
                                    }
                                } else if (\$parentClass === \$targetClass && \$relation['mappedBy']) {
                                    // Special case: bi-directional self-referencing one-one on the same class
                                    \$targetClass->reflFields[\$relationField]->setValue(\$element, \$parentObject);
                                }
                            } else {
                                // For sure bidirectional, as there is no inverse side in unidirectional mappings
                                \$targetClass->reflFields[\$relation['mappedBy']]->setValue(\$element, \$parentObject);
                                \$this->_uow->setOriginalEntityProperty(spl_object_hash(\$element), \$relation['mappedBy'], \$parentObject);
                            }
                            // Update result pointer
                            \$this->resultPointers[\$dqlAlias] = \$element;
                        } else {
                            \$this->_uow->setOriginalEntityProperty(\$oid, \$relationField, null);
                            \$reflField->setValue(\$parentObject, null);
                        }
                        // else leave \$reflFieldValue null for single-valued associations
                    } else {
                        // Update result pointer
                        \$this->resultPointers[\$dqlAlias] = \$reflFieldValue;
                    }
PHP;

    }


    // IGNORE - template
    protected function hydrateRowData(array $row, array &$cache, array &$result)
    {

        // Initialize
        $id = $this->idTemplate; // initialize the id-memory
        $nonemptyComponents = array();
        // Split the row data into chunks of class data.
        $rowData = $this->gatherRowData($row, $cache, $id, $nonemptyComponents);

        eval($this->generateScalarsExtraction('rowData'));
        eval($this->generateNewObjectsExtraction('rowData'));

        // Hydrate the data chunks
        foreach ($rowData as $dqlAlias => $data) {
            $entityName = $this->_rsm->aliasMap[$dqlAlias];

            if (isset($this->_rsm->parentAliasMap[$dqlAlias])) {
                // It's a joined result

                $parentAlias = $this->_rsm->parentAliasMap[$dqlAlias];
                // we need the $path to save into the identifier map which entities were already
                // seen for this parent-child relationship
                $path = $parentAlias . '.' . $dqlAlias;

                // We have a RIGHT JOIN result here. Doctrine cannot hydrate RIGHT JOIN Object-Graphs
                if ( ! isset($nonemptyComponents[$parentAlias])) {
                    // TODO: Add special case code where we hydrate the right join objects into identity map at least
                    continue;
                }

                // Get a reference to the parent object to which the joined element belongs.
                if ($this->_rsm->isMixed && isset($this->rootAliases[$parentAlias])) {
                    $first = reset($this->resultPointers);
                    $parentObject = $first[key($first)];
                } else if (isset($this->resultPointers[$parentAlias])) {
                    $parentObject = $this->resultPointers[$parentAlias];
                } else {
                    // Parent object of relation not found, so skip it.
                    continue;
                }

                $parentClass    = $this->ce[$this->_rsm->aliasMap[$parentAlias]];
                $oid            = spl_object_hash($parentObject);
                $relationField  = $this->_rsm->relationMap[$dqlAlias];
                $relation       = $parentClass->associationMappings[$relationField];
                $reflField      = $parentClass->reflFields[$relationField];

                // Check the type of the relation (many or single-valued)
                if ( ! ($relation['type'] & ClassMetadata::TO_ONE)) {
                    eval($this->generateCollectionValuedAssociationHydration());
                } else {
                    eval($this->generateSingleValuedAssociationHydration());
                }
            } else {
                eval($this->generateRootResultElement());
            }
        }

        eval($this->generateAppendScalars());
        eval($this->generateAppendNewObjects());
    }

}
 