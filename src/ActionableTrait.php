<?php

namespace Extender;

use Cake\Datasource\EntityInterface;
use Cake\Event\Event;
use Cake\ORM\Association;
use Cake\Validation\Validator;
use Cake\ORM\Table;
use ArrayObject;

/**
 * @method mixed Create(array | object $data)
 * @method mixed Update(array | object $data)
 */
trait ActionableTrait
{
    /**
     * @var Manager
     */
    private $manager = null;

    public function __call($method, $args) {
        /** @var Table $this */
        try {
            /** @see Table::__call */
            return parent::__call($method, $args);
        }
        catch (\BadMethodCallException $e) {
            $entity = $this->newEntity($args[0] + ['_action' => $method, '_parent' => '']);

            return $this->save($entity, ['associated' => self::getAssociated($this)]);
        }
    }

    public function save(EntityInterface $entity, $options = []) {

        if ($this->manager) {
            $this->manager->run();
            $this->patchEntity($entity, $this->manager->getData(), $options);
        }
        $result = parent::save($entity, $options);

        if ($this->manager) {
            $this->manager->setEntity($entity);
        }

        return $result;
    }

    public function belongsTo($associated, array $options = []) {
        $association = parent::belongsTo($associated, $options);

        if (isset($options['_type'])) {
            $association->belongingType = $options['_type'];
        }

        return $association;
    }

    /**
     * @param Table  $object
     * @param string $parent
     *
     * @return string[]
     */
    private static function getAssociated($object, $parent = '') {
        $associated = [];

        /** @var Association $association */
        foreach ($object->associations() as $association) {

            if (isset($association->belongingType)) {
                $target = $association->getTarget();

                switch ($association->belongingType) {

                    case 'incorporated':
                    case 'embedded':
                        $associated[] = $alias = $parent . (!$parent ? '' : '.') . $target->getAlias();
                        $associated   = array_merge($associated, self::getAssociated($target, $alias));
                }
            }
        }

        return $associated;
    }

    public function beforeMarshal(Event $event, ArrayObject $data, ArrayObject $options) {
        /** @var Table $this */

        if (isset($data['_action']) && !$this->manager) {
            $this->manager = new Manager($this, $data['_action'], $data->getArrayCopy());

            /** @var Association $association */
            foreach ($this->associations() as $association) {

                /** @var ActionableTrait | Table $target */
                if (isset($association->belongingType)) {
                    $alias = $association->getTarget()->getAlias();

                    switch ($association->belongingType) {

                        case 'incorporated':
                            $data[$alias]            = $data->getArrayCopy();
                            $data[$alias]['_parent'] = $this->manager->getModelName();
                            $data[$alias]['_type']   = $association->belongingType;
                            break;

                        case 'embedded':

                            if (isset($data[$alias])) {
                                //$data[$alias] = (array)$data[$alias];
                                $data[$alias]['_parent'] = $this->manager->getModelName();
                                $data[$alias]['_action'] = $data['_action'];
                                $data[$alias]['_type']   = $association->belongingType;
                                $data[$alias]            = $association->getTarget()->newEntity($data[$alias]);
                            }
                            break;

                        case 'related':
                            // do nothing
                    }
                }
            }
        }
    }

    public function validationDefault(Validator $validator) {

        if (method_exists($this, '__validation')) {
            $validator = $this->__validation($validator);
        }

        return $this->manager ? $this->manager->validation($validator) : $validator;
    }

    private static function cleanEntity(EntityInterface &$entity, EntityInterface &$parent = null, $parentKey = '') {
        $entity->setHidden(['id'], 1);

        $belongingType = null;

        if (isset($entity->_type) && $parent) {
            $belongingType = $entity->_type;
        }

        foreach ($entity->toArray() as $k => $v) {

            if (substr($k, 0, 1) == '_') {
                $entity->setHidden([$k], true);
                continue;
            }

            if ($entity->$k instanceof EntityInterface) {

                if (isset($entity->$k->_type)) {
                    $entity->setHidden([$k . '_id'], true);
                }
                self::cleanEntity($entity->$k, $entity, $k);
            }

            if ($belongingType == 'incorporated' && $k != 'id') {
                $parent->$k = $entity->$k;
            }
        }

        if ($belongingType == 'incorporated') {
            $parent->setHidden([$parentKey], true);
            self::cleanEntity($parent);
        }

        return $entity;
    }

    public function getEntity() {
        $entity = $this->manager->getEntity();
        self::cleanEntity($entity);

        return $entity;
    }
}