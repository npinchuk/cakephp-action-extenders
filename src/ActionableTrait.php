<?php

namespace Extender;

use Cake\Datasource\EntityInterface;
use Cake\Event\Event;
use Cake\ORM\Association\BelongsTo;
use Cake\ORM\Association\HasOne;
use Cake\Validation\Validator;
use Cake\ORM\Table;
use ArrayObject;

/**
 * @method mixed Create(array|object $data)
 * @method mixed Update(array|object $data)
 */

trait ActionableTrait
{
    /**
     * @var Manager
     */
    private $manager;

    private $belongingType = 'related';

    public function __call($method, $args)
    {
        try {
            /** @see Table::__call */
            return parent::__call($method, $args);
        } catch (\BadMethodCallException $e) {
            $entity = $this->newEntity($args[0] + ['_action' => $method]);

            return $this->save($entity);
        }
    }

    public function save(EntityInterface $entity, $options = []) {
        $this->manager->run();
        $this->patchEntity($entity, $this->manager->getData());
        $result = parent::save($entity);
        $this->manager->setEntity($entity);

        return $result;
    }

    public function beforeMarshal(Event $event, ArrayObject $data, ArrayObject $options)
    {
        if (isset($data['_action'])) {
            $this->manager = new Manager($this, $data['_action'], $data->getArrayCopy());
            $associated = [];

            foreach ($this->associations() as $association) {
                /** @var ActionableTrait | Table $target */
                if (($association instanceof BelongsTo || $association instanceof HasOne) &&
                    method_exists($target = $association->getTarget(), 'getBelongingType')
                ) {
                    switch ($target->getBelongingType()) {
                        case 'incorporated':
                            $associated[] = $target->getAlias();
                            $data[$target->getAlias()] = ['_parent' => $this->manager->getModelName()] + (array)$data;
                            break;
                        case 'related':
                            // do nothing
                    }
                }
            }
            $options['associated'] = $associated;
        }
    }

    public function validationDefault(Validator $validator)
    {
        return $this->manager ? $this->manager->validation($validator) : $validator;
    }

    public function getEntity()
    {
        return $this->manager->getEntity();
    }

    public function getBelongingType()
    {
        return $this->belongingType;
    }
}