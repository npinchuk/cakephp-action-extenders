<?php
/**
 * Created by PhpStorm.
 * User: nikita
 * Date: 13.03.17
 * Time: 5:36
 */

namespace Extender;

use Cake\Validation\Validator;

trait ActionableTrait
{
    /**
     * @var Manager
     */
    private $manager;

    public function __call($method, $args) {
        try {
            /** @see Table parent */
            return parent::__call($method, $args);
        } catch (\BadMethodCallException $e) {
            $this->manager = new Manager($this, $method, $args);

            return $this->manager->run();
        }
    }

    public function validationDefault(Validator $validator) {

        return $this->manager->validation($validator);
    }

    public function getEntity() {

        return $this->manager->getEntity();
    }

}