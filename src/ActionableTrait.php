<?php

namespace Extender;

use Cake\Datasource\EntityInterface;
use Cake\Event\Event;
use Cake\ORM\Association;
use Cake\ORM\Entity;
use Cake\Validation\Validator;
use Cake\ORM\Table;
use ArrayObject;

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
            $associated = array_keys(static::getAssociated($this));

            // create / update
            if (!empty($args[1])) {
                $id           = $args[1];
                $this->entity = $this->get($id, ['contain' => $associated]);
                $this->patchEntity($this->entity, $data, compact('associated'));
            }
            else {
                $this->entity = $this->newEntity($data, compact('associated'));
            }

            if ($this->save($this->entity)) {
                $this->cleanEntity();
            }

            return $this->entity;
        }
    }

    public function getErrors() {
        /** @var Table $this */
        $errors = $this->entity->getErrors();
        static::comeAlong($errors, array_reverse(static::getAssociated($this), true),
            function ($association, $path, &$current, &$previous) use (&$errors) {
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

        return $errors;
    }

    /**
     * Restructures data according to model associations
     * and adds service underscore prefixed fields
     *
     * @param $data
     */
    private function prepareData(&$data) {
        /** @var Table $this */
        static::comeAlong($data, static::getAssociated($this),
            function ($association, $path, &$current, &$previous) use ($data) {
                /** @var Association $association */
                $key = array_pop($path);
                array_unshift($path, $this->getAlias());
                $path = implode('.', $path);

                if ($association->belongingType == 'incorporated') {
                    foreach ($previous as $k => &$v) {
                        if ($k != $key) {
                            $current[$k] = &$v;
                        }
                    }
                }

                if ($current) {
                    $current['_parent']  = $path;
                    $current['_action']  = $data['_action'];
                    $current['_current'] = $key;
                    $current['_type']    = $association->belongingType;
                }
            }
        );
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
    private static function getAssociated(Table $object, $parent = '') {
        $associated = [];

        /** @var Association $association */
        foreach ($object->associations() as $association) {

            if (isset($association->belongingType)) {
                $target             = $association->getTarget();
                $alias              = $parent . (!$parent ? '' : '.') . $target->getAlias();
                $associated[$alias] = $association;
                $associated         += static::getAssociated($target, $alias);
            }
        }

        return $associated;
    }

    /**
     * Iteration over $pathsAndValues with giving access to corresponding paths in $data
     *
     * @param          $data
     * @param array    $pathsAndValues
     * @param callable $modifier - function ($pathsAndValues[$path], $path, &$data[$path], &&$data[$pathPrevious])
     * @param bool     $createOnEmpty
     */
    private static function comeAlong(&$data, array $pathsAndValues, callable $modifier, $createOnEmpty = true) {

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

    /**
     * Clean entity from odd fields and restructuring it according to model associations
     *
     * @param EntityInterface      $entity    - entity object to be cleaned
     * @param EntityInterface|null $parent    - for recursion
     * @param string               $parentKey - for recursion
     */
    private function cleanEntity() {
        /** @var Table $this */
        $cleanThis = function ($association, $path, $current, $previous) {
            /** @var Entity $current */
            $current->setHidden(['id'], true);
            foreach ($current->toArray() as $k => $v) {
                if (substr($k, 0, 1) == '_' || substr($k, -3) == '_id') {
                    $current->setHidden([$k], true);
                    continue;
                }
                if ($association && $association->belongingType == 'incorporated' && $k != 'id') {
                    /** @var Entity $previous */
                    $previous->$k = $current->$k;
                }
            }
            if ($association && $association->belongingType == 'incorporated') {
                $key = array_pop($path);
                $previous->setHidden([$key], true);
            }
        };
        static::comeAlong($this->entity, array_reverse(static::getAssociated($this), true), $cleanThis, false);
        $cleanThis(null, null, $this->entity, null);
    }
}