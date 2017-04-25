<?php

namespace Extender;

use Cake\Database\Exception\NestedTransactionRollbackException;
use Cake\Datasource\EntityInterface;
use Cake\Event\Event;
use Cake\ORM\Association;
use Cake\ORM\Entity;
use Cake\ORM\Query;
use Cake\Validation\Validator;
use Cake\ORM\Table;
use ArrayObject;

/**
 * @method Entity Create(array | object $data)
 * @method Entity Update(array | object $data, $id)
 */
trait ActionableTrait
{
    use EntityFormattingTrait;

    /**
     * @var Manager
     */
    private $manager = null;

    /**
     * @var Entity
     */
    private $entity;

    /**
     * @var string
     */
    private $currentActionName;

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
            $data                    = $args[0] + ['_action' => $method, '_parent' => ''];
            $this->currentActionName = $method;
            $this->prepareData($data);
            $associated = array_keys($this->getAssociated());

            // create / update
            if (!empty($args[1])) {
                $this->entity = $this->find()
                    ->where($args[1])
                    ->firstOrFail();
                $this->patchEntity($this->entity, $data, compact('associated'));

                if ($this->processSave()) {
                    $this->cleanEntity($this->entity);
                }
            }
            else {
                $this->entity = $this->newEntity($data, compact('associated'));

                if ($this->processSave()) {
                    $this->entity = $this->get($this->entity->{$this->getPrimaryKey()});
                }
            }

            return $this->entity;
        }
    }

    private function processSave() {

        try {
            $result = $this->save($this->entity);
            $this->manager->finalize();
        } catch (NestedTransactionRollbackException $e) {
            throw $e;
        }

        return $result;
    }

    /**
     * Restructures data according to model associations
     * and adds service underscore prefixed fields
     *
     * @param $data
     */
    private function prepareData(&$data) {
        /** @var Table $this */
        $prepareThis = function ($association, array $path, &$current, &$previous) {

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

                if ($association == 'incorporated') {
                    $key = array_pop($path);

                    foreach ($previous as $k => &$v) {

                        if ($k != $key && substr($k, 0, 1) != '_') {
                            $current[$k] = &$v;
                        }
                    }
                }

                // add service fields
                $current['_parent']  = &$previous;
                $current['_manager'] = new Manager($this->currentActionName, $current);
            }
        };
        $prepareThis(null, [], $data, $data);
        $this->walkWithAssociated($data, $prepareThis, true, false);
    }

    /**
     * Restructures entity errors according to model associations
     *
     * @return array
     */
    public function getErrors() {
        /** @var Table $this */
        $errors = $this->entity->getErrors();

        $getThis = function ($association, $path, &$current, &$previous) {
            $key = array_pop($path);

            foreach ($current as $k => $v) {

                if ($association == 'embedded') {

                    if (is_scalar($v)) {
                        return;
                    }
                    $k = "$key.$k";
                }
                $previous[$k] = $v;
            }
            unset($previous[$key]);
        };

        $this->walkWithAssociated($errors, $getThis);
        $result = [];

        foreach ($errors as $k => &$v) {
            $result[$this->getAliasByField($k)] = reset($v);
        }

        return $result;
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

        if (isset($data['_manager'])) {
            $this->manager = $data['_manager']->setTable($this);
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

            if (!$this->manager->needSave()) {

                return $event->stopPropagation();
            }
        }
    }

}