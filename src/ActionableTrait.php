<?php

namespace Extender;

use Cake\Datasource\EntityInterface;
use Cake\Event\Event;
use Cake\ORM\Association;
use Cake\ORM\Entity;
use Cake\ORM\Query;
use Cake\Validation\Validator;
use Cake\ORM\Table;
use ArrayObject;
use Migrations\CakeAdapter;

/**
 * @method Entity Create(array | object $data)
 * @method Entity Update(array | object $data, $id)
 */
trait ActionableTrait
{
    /**
     * @var Manager
     */
    private $manager = null;

    /**
     * @var Entity
     */
    private $entity;

    private $entityHiddenFields = [];

    private $entityFieldsAliases = [];

    private $entityDeprecatedFields = [];

    /**
     * @var Association[]
     */
    private $associated;
    private $incorporated;

    public function getAssociated() {
        if (!$this->associated) {
            $this->associated = static::getTableAssociated($this);
        }

        return $this->associated;;
    }

    public function setEntityHiddenFields(array $fieldsList) {
        $this->entityHiddenFields = $fieldsList;

        return $this;
    }

    public function setEntityDeprecatedFields(array $fieldsList) {
        $this->entityDeprecatedFields = $fieldsList;

        return $this;
    }

    public function setEntityFieldsAliases(array $fieldsAliases) {
        $this->entityFieldsAliases = $fieldsAliases;

        return $this;
    }

    /**
     * Action call
     *
     * @param $method
     * @param $args
     *
     * @return EntityInterface|Entity|mixed
     */
    public function __call($method, $args) {
        /** @var Table $this */
        try {
            /** @see Table::__call() */
            return parent::__call($method, $args);
        }
        catch (\BadMethodCallException $e) {
            $data = $args[0] + ['_action' => $method, '_parent' => ''];
            $this->prepareData($data);
            $associated = array_keys($this->getAssociated());

            // create / update
            if (!empty($args[1])) {
                $where = $args[1];
                $query = $this->find();
                if (is_array($where)) {
                    $query->where($where);
                }
                $this->entity = $query->firstOrFail();
                $this->patchEntity($this->entity, $data, compact('associated'));
            }
            else {
                $this->entity = $this->newEntity($data, compact('associated'));
            }

            if ($this->save($this->entity)) {
                $this->entity = $this->get($this->entity->id);
            }

            return $this->entity;
        }
    }

    private function getFieldByAlias($alias, array $path) {
        $path = implode('.', array_diff($path, $this->getIncorporated()));

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

            if (fnmatch($patternPath, $path)) {

                return $fieldName;
            }
        }

        return false;
    }

    /**
     * Restructures data according to model associations
     * and adds service underscore prefixed fields
     *
     * @param $data
     */
    private function prepareData(&$data) {
        /** @var Table $this */
        $prepareThis = function ($association, array $path, &$current, &$previous) use ($data) {

            if (!is_scalar($current)) {

                foreach ($current as $k => $v) {

                    if ($fieldName = $this->getFieldByAlias($k, $path)) {
                        $current[$fieldName] = $v;
                        unset($current[$k]);
                    }
                    elseif ($this->isEntityFieldDeprecated($k, $path)) {
                        unset($current[$k]);
                    }
                }

                $key = array_pop($path);
                /** @var Association $association */
                if ($association) {

                    if ($association->belongingType == 'incorporated') {

                        foreach ($previous as $k => &$v) {

                            if ($k != $key) {
                                $current[$k] = &$v;
                            }
                        }
                    }
                }

                // add service fields
                array_unshift($path, $this->getAlias());
                $pathString          = implode('.', $path);
                $current['_current'] = $key;
                $current['_parent']  = $pathString;
                $current['_action']  = $data['_action'];
            }
        };
        $prepareThis(null, [], $data, $data);
        static::comeAlong($data, $this->getAssociated(), $prepareThis);
    }

    /**
     * Restructures entity errors according to model associations
     *
     * @return array
     */
    public function getErrors() {
        /** @var Table $this */
        $errors = $this->entity->getErrors();
        static::comeAlong($errors, array_reverse($this->getAssociated(), true),
            function ($association, $path, &$current, &$previous) {
                $key = array_pop($path);
                foreach ($current as $k => $v) {
                    if ($association->belongingType == 'embedded') {
                        if (is_scalar($v)) {
                            return;
                        }
                        $k = "$key.$k";
                    }
                    $previous[$k] = $v;
                }
                unset($previous[$key]);
            },
            false
        );

        foreach ($errors as &$v) {
            $v = reset($v);
        }

        return $errors;
    }

    private function getIncorporated() {

        if (!$this->incorporated) {

            $this->incorporated = array_keys(
                array_filter($this->getAssociated(), function ($v, $k) { return $v->belongingType == 'incorporated'; }, ARRAY_FILTER_USE_BOTH)
            );
        }

        return $this->incorporated;
    }

    private function isEntityFieldHidden($fieldName, array $path) {
        return $this->isEntityFieldInList($fieldName, $path, $this->entityHiddenFields);
    }

    private function isEntityFieldDeprecated($fieldName, array $path) {
        return $this->isEntityFieldInList($fieldName, $path, array_merge($this->entityDeprecatedFields, $this->entityHiddenFields));
    }

    private function isEntityFieldInList($fieldName, array $path, array $list) {
        $fieldNameFull = trim(implode('.', array_diff($path, $this->getIncorporated())) . '.' . $fieldName, '.');

        foreach ($list as $pattern) {

            if (fnmatch($pattern, $fieldNameFull)) {

                return true;
            }
        }

        return false;
    }

    private function getFieldAlias($fieldName, array $path) {
        $fieldNameFull = trim(implode('.', array_diff($path, $this->getIncorporated())) . '.' . $fieldName, '.');

        foreach ($this->entityFieldsAliases as $pattern => $alias) {

            if (fnmatch($pattern, $fieldNameFull)) {

                return $alias;
            }
        }

        return $fieldName;
    }

    /**
     * Cleans entity from odd fields and
     * restructures it according to model associations
     *
     * @param Entity $entity
     * @param array  $associated
     */
    public function cleanEntity(Entity $entity) {
        $cleanThis = function ($association, $path, $current, $previous = null) {
            $isIncorporated = $association && $association->belongingType == 'incorporated';
            /** @var Entity $current */
            foreach ($current->toArray() as $k => $v) {
                if (substr($k, 0, 1) == '_' || $this->isEntityFieldHidden($k, $path)) {
                    $current->setHidden([$k], true);
                    continue;
                }
                $alias = $this->getFieldAlias($k, $path);
                if ($isIncorporated && $k != 'id') {
                    /** @var Entity $previous */
                    $previous->$alias = $current->$k;
                }
                elseif ($alias != $k) {
                    $current->$alias = $current->$k;
                    $current->setHidden([$k], true);
                }
            }
            if ($isIncorporated) {
                $key = array_pop($path);
                $previous->setHidden([$key], true);
            }
        };
        static::comeAlong($entity, array_reverse($this->getAssociated(), true), $cleanThis, false);
        $cleanThis(null, [], $entity);
    }

    /**
     * Sets extra property Association::belongingType
     * from _type association option
     *
     * @param       $associated
     * @param array $options
     *
     * @return mixed
     */
    public function belongsTo($associated, array $options = []) {
        /** @see Table::belongsTo() */
        $association = parent::belongsTo($associated, $options);

        /** @var Association $association */
        if (isset($options['_type'])) {
            $association->belongingType = $options['_type'];
        }

        return $association;
    }

    /**
     * Model events
     */

    public function beforeFind(Event $event, Query $query, ArrayObject $options, bool $primary) {
        $query->contain(array_keys($this->getAssociated()));
        $query->formatResults(function ($results) {
            /* @var $results \Cake\Datasource\ResultSetInterface|\Cake\Collection\CollectionInterface */
            return $results->map(function ($row) {
                $this->cleanEntity($row);

                return $row;
            });
        });
    }

    /**
     * Before entity creation create manager instance
     *
     * @param Event       $event
     * @param ArrayObject $data
     * @param ArrayObject $options
     */
    public function beforeMarshal(Event $event, ArrayObject $data, ArrayObject $options) {

        if (isset($data['_action']) && !$this->manager) {
            $this->manager = new Manager($this, $data['_action'], $data->getArrayCopy());
        }
    }

    /**
     * Add validation from extenders
     *
     * @param Event     $event
     * @param Validator $validator
     * @param string    $name
     */
    public function buildValidator(Event $event, Validator $validator, string $name) {

        if ($this->manager) {
            $this->manager->validation($validator);
        }
    }

    /**
     * Before saving add calculated by manager data
     *
     * @param Event           $event
     * @param EntityInterface $entity
     * @param ArrayObject     $options
     */
    public function beforeSave(Event $event, EntityInterface $entity, ArrayObject $options) {

        if ($this->manager) {
            $this->manager->setEntity($entity);
            $this->manager->run();
            $this->patchEntity($entity, array_filter($this->manager->getData(), 'is_scalar'), ['validate' => false]);
        }

        // TODO run __save methods of action extenders and execute $event->stopPropagation()
    }

    public function afterSaveCommit(Event $event, EntityInterface $entity, ArrayObject $options) {

    }

    /**
     * Utility static methods
     */

    /**
     * Returns associations array with model.subModel keys
     *
     * @param Table  $object
     * @param string $parent
     *
     * @return Association[]
     */
    private static function getTableAssociated(Table $object, $parent = '') {
        $associated = [];

        /** @var Association $association */
        foreach ($object->associations() as $association) {

            if (isset($association->belongingType)) {
                $target             = $association->getTarget();
                $alias              = $parent . (!$parent ? '' : '.') . $target->getAlias();
                $associated[$alias] = $association;
                $associated         += static::getTableAssociated($target, $alias);
            }
        }

        return $associated;
    }

    /**
     * Iterates over $pathsAndValues with giving access to corresponding paths in $data
     *
     * @param          $data
     * @param array    $pathsAndValues
     * @param callable $modifier - function ($pathsAndValues[$path], $path, &$data[$path], &&$data[$pathPrevious])
     * @param bool     $createOnEmpty
     */
    public static function comeAlong(&$data, array $pathsAndValues, callable $modifier, $createOnEmpty = true) {

        foreach ($pathsAndValues as $path => $value) {
            $previous  = &$data;
            $current   = &$data;
            $pathArray = explode('.', $path);
            $break     = false;

            foreach ($pathArray as $i => $key) {
                $break = false;

                if (is_array($previous) || $previous instanceof ArrayObject) {
                    (isset($previous[$key]) or $createOnEmpty and !$previous[$key] = [] or !$break = true)
                    and (isset($pathArray[$i + 1]) ? $previous = &$previous[$key] : $current = &$previous[$key]);
                }
                elseif (is_object($previous)) {
                    (isset($previous->$key) or $createOnEmpty and $previous->$key = (object)[] or !$break = true)
                    and (isset($pathArray[$i + 1]) ? $previous = &$previous->$key : $current = &$previous->$key);
                }
                else {
                    $break = true;
                }

                if ($break) {
                    break;
                }
            }

            if (!$break) {
                $modifier($value, $pathArray, $current, $previous);
            }
        }
    }
}