<?php
/**
 * Base extender class
 */

namespace Extender;

use Cake\Validation\Validator;

class BaseExtender
{
    /**
     * @var Manager
     */
    private $manager;

    /**
     * @var array - default values
     */
    private $defaults = [];

    /**
     * @var array - functions types and other settings
     */
    private $functionsConfig = [];

    /**
     * @return array - default values
     */
    public function getDefaults() {

        return $this->defaults;
    }

    /**
     * @return array - functions types and other settings
     */
    public function getFunctionsConfig() {

        return $this->functionsConfig;
    }

    /**
     * @return array - default settings for a single function
     */
    public function getFunctionConfigDefault() {

        return [
            'type' => 'installer',
            'scope' => [
                'area' => 'row',
                'conditions' => 'any'
            ]
        ];
    }

    /**
     * Checks weather extender should be connected
     * to processing of data passed
     *
     * @param array $data - input data
     * @return bool
     */
    public static function __check(array $data = []) {

        return true;
    }

    /**
     * Any initialization routine
     *
     * @param $data
     */
    public function __init(array $data = []) {}

    /**
     * Validation that will be connected together
     * with current extender
     *
     * @param Validator $validator
     * @return Validator
     */
    public function __validation(Validator $validator) {

        return $validator;
    }

    /**
     * Routine after successful saving data to DB
     *
     * @param array $data
     * @return array
     */
    public function __finalize(array $data = []) {

        return $data;
    }

    /**
     * BaseExtender constructor.
     *
     * @param Manager $manager
     */
    public function __construct(Manager $manager) {
        $this->manager = $manager;
    }

    /**
     * @param $field
     * @return mixed
     */
    final public function __get($field) {

        return $this->manager->$field;
    }

    /**
     * @param $name
     * @return bool
     */
    final public function __isset($name) {

        return isset($this->manager->$name);
    }
}