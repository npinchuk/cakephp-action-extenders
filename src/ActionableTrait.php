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
            $data       = $args[0] + ['_action' => $method, '_parent' => ''];
            $associated = $contain = array_keys(static::getAssociated($this));

            // create / update
            if (!empty($args[1])) {
                $id     = $args[1];
                $entity = $this->get($id, compact('contain'));
                $this->prepareData($data);
                $this->patchEntity($entity, $data, compact('associated'));
            }
            else {
                $this->prepareData($data);
                $entity = $this->newEntity($data, compact('associated'));
            }
            $this->save($entity);
            self::cleanEntity($entity);

            return $entity;
        }
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
                    $current = (array)$previous;
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
     * Pass entity instance to manager
     *
     * @param Event           $event
     * @param EntityInterface $entity
     * @param ArrayObject     $options
     * @param string          $operation
     */
    public function beforeRules(Event $event, EntityInterface $entity, ArrayObject $options, string $operation) {

        if ($this->manager) {
            $this->manager->setEntity($entity);
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
            $current  = &$data;
            $previous = null;
            $path     = explode('.', $path);

            foreach ($path as $i => $key) {
                $previous = $current;

                if (is_array($current) || $current instanceof ArrayObject) {
                    (isset($current[$key]) or $createOnEmpty and !$current[$key] = []) and $current = &$current[$key];
                }
                elseif (is_object($current)) {
                    (isset($current->$key) or $createOnEmpty and $current->$key = (object)[]) and $current = &$current->$key;
                }
            }
            $modifier($value, $path, $current, $previous);
        }
    }

    /**
     * Clean entity from odd fields and restructuring it according to model associations
     *
     * @param EntityInterface      $entity    - entity object to be cleaned
     * @param EntityInterface|null $parent    - for recursion
     * @param string               $parentKey - for recursion
     *
     * @return EntityInterface
     */
    private static function cleanEntity(EntityInterface $entity, EntityInterface $parent = null, $parentKey = '') {
        $entity->setHidden(['id'], true);

        $belongingType = null;

        if (isset($entity->_type) && $parent) {
            $belongingType = $entity->_type;
        }

        foreach ($entity->toArray() as $k => $v) {

            if (substr($k, 0, 1) == '_' || substr($k, -3) == '_id') {
                $entity->setHidden([$k], true);
                continue;
            }

            if ($entity->$k instanceof EntityInterface) {
                static::cleanEntity($entity->$k, $entity, $k);
            }

            if ($belongingType == 'incorporated' && $k != 'id') {
                $parent->$k = $entity->$k;
            }
        }

        if ($belongingType == 'incorporated') {
            $parent->setHidden([$parentKey], true);
            static::cleanEntity($parent);
        }

        return $entity;
    }
}