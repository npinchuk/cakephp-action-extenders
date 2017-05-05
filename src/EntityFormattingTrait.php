<?php

namespace Extender;

use Cake\Event\Event;
use Cake\ORM\Entity;
use Cake\ORM\Query;

trait EntityFormattingTrait
{
    use EntityAssociationsTrait;

    private $entityHiddenFields = [];

    private $entityFieldsAliases = [];

    private $entityDeprecatedFields = [];

    public function setEntityDeprecatedFields(array $fieldsList) {
        $this->entityDeprecatedFields = $fieldsList;

        return $this;
    }

    public function setEntityHiddenFields(array $fieldsList) {
        $this->entityHiddenFields = $fieldsList;

        return $this;
    }

    public function setEntityFieldsAliases(array $fieldsAliases) {
        $this->entityFieldsAliases = $fieldsAliases;

        return $this;
    }

    public function beforeFind(Event $event, Query $query, \ArrayObject $options, bool $primary) {

        if ($this->getAssociated() ||
            $this->entityHiddenFields ||
            $this->entityFieldsAliases ||
            $this->entityDeprecatedFields
        ) {
            $query->contain(array_keys($this->getAssociated()));
            $query->formatResults(function ($results) {
                /* @var $results \Cake\Datasource\ResultSetInterface|\Cake\Collection\CollectionInterface */
                return $results->map(function ($row) {
                    $this->cleanEntity($row);

                    return $row;
                });
            });
        }
    }

    /**
     * Cleans entity from hidden, deprecated and service fields and
     * restructures it according to associations map
     *
     * @param Entity $entity
     * @param array  $associated
     */
    private function cleanEntity(Entity $entity) {

        $cleanThis = function ($association, array $path, Entity $current, $previous = null) {

            foreach ($current->toArray() as $k => $v) {
                $alias = $this->getAliasByField($k, $path);

                if ($alias == $k && !$current->isDirty($k) && $this->isEntityFieldHidden($k, $path)) {
                    $current->setHidden([$k], true);
                    continue;
                }

                if ($association == 'incorporated' && $k != 'id') {
                    /** @var Entity $previous */
                    $previous->$alias = $current->$k;
                }
                elseif ($alias != $k) {
                    $current->$alias = $current->$k;
                    $current->setHidden([$k], true);
                }
            }

            if ($association == 'incorporated') {
                $key = array_pop($path);
                $previous->setHidden([$key], true);
            }
        };
        $this->walkWithAssociated($entity, $cleanThis);
        $cleanThis(null, [], $entity);
    }

    private function getPathString(array $path) {
        $pathString = '';
        $associated = array_intersect($this->getAssociated(), ['incorporated']);

        foreach ($path as $i => $key) {
            $pathString .= ($pathString ? '.' : '') . $key;

            if (isset($associated[$pathString])) {
                unset($path[$i]);
            }
        }

        return implode('.', $path);
    }

    /**
     * @return array - ["embed1.field1Alias" => ["inc1.inc2.embed1.inc3","field1"], ...]
     */
    public function getFieldsMap() {
        $result = [];

        foreach (static::getTableAssociated($this, false) as $realPath => $association) {
            $shortPath = $this->getPathString(explode('.', $realPath));

            foreach ($association->getSchema()->columns() as $field) {
                $result[$this->getAliasByField(trim("$shortPath.$field", '.'))]
                    = [$realPath, $field, $association->getTarget()];
            }
        }

        foreach ($this->getSchema()->columns() as $field) {
            $result[$this->getAliasByField($field)] = ['', $field, $this];
        }

        return $result;
    }

    private function isEntityFieldHidden($fieldName, array $path) {
        return $this->isEntityFieldInList($fieldName, $path, $this->entityHiddenFields);
    }

    private function isEntityFieldDeprecated($fieldName, array $path) {
        return $this->isEntityFieldInList($fieldName, $path, array_merge($this->entityDeprecatedFields, $this->entityHiddenFields));
    }

    private function isEntityFieldInList($fieldName, array $path, array $list) {
        $fieldNameFull = $this->getPathString(array_merge($path, [$fieldName]));

        foreach ($list as $pattern) {

            if (fnmatch($pattern, $fieldNameFull)) {

                return true;
            }
        }

        return false;
    }

    public function getAliasByField($fieldName, array $path = []) {
        $fieldNamePath = explode('.', $fieldName);
        $fieldNameFull = $this->getPathString(array_merge($path, $fieldNamePath));
        array_pop($fieldNamePath);

        foreach ($this->entityFieldsAliases as $pattern => $alias) {

            if (fnmatch($pattern, $fieldNameFull)) {

                return (count($fieldNamePath) ? implode('.', $fieldNamePath) . '.' : '' ) . $alias;
            }
        }

        return $fieldName;
    }

    public function getFieldByAlias($alias, array $path = []) {

        if ($pattern = array_search($alias, $this->entityFieldsAliases)) {
            $pathinfo = pathinfo($pattern);

            if (!empty($pathinfo['extension'])) {
                $fieldName   = $pathinfo['extension'];
                $patternPath = $pathinfo['filename'];
            }
            else {
                $fieldName   = $pathinfo['filename'];
                $patternPath = '';
            }

            if (fnmatch($patternPath, $this->getPathString($path))) {

                return $fieldName;
            }
        }

        return false;
    }

}