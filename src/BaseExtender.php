<?php
/**
 * Base extender class
 */

namespace Extender;

use Cake\ORM\Association\BelongsTo;
use Cake\Utility\Inflector;
use Cake\Validation\Validator;

class BaseExtender
{
    /**
     * @var Manager
     */
    protected $manager;

    /**
     * @var array - default values
     */
    protected $defaults = [];

    /**
     * @var array - required fields
     */
    protected $required = [];

    /**
     * @var array - functions types and other settings
     */
    protected $functionsConfig = [];

    /**
     * @return array - default values
     */
    public function __getDefaults() {

        return $this->defaults;
    }

    /**
     * @return array - required fields
     */
    public function __getRequired() {

        return $this->required;
    }

    /**
     * @return array - functions types and other settings
     */
    public function __getFunctionsConfig() {

        return $this->functionsConfig;
    }

    /**
     * @return array - default settings for a single function
     */
    public function __getFunctionConfigDefault() {

        return ['type' => 'installer'];
    }

    /**
     * Checks weather extender should be connected
     * to processing of data passed
     *
     * @param array $data - input data
     *
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
    public function __init(array $data = []) { }

    /**
     * Validation that will be connected together
     * with current extender
     *
     * @param Validator $validator
     *
     * @return Validator
     */
    public function __validation(Validator $validator) {

        return $validator;
    }

    /**
     * Routine after successful saving data to DB
     *
     * @param array $data
     *
     * @return array
     */
    public function __finalize(array $data = []) {

        return $data;
    }

    /**
     * Routine before commiting save data to DB
     *
     * @param array $data
     *
     * @return array
     */
    public function __afterSave(array $data = []) {

        return $data;
    }

    /**
     * Routine after successful saving data to DB
     *
     * @param array $data
     *
     * @return array
     */
    public function __save(array $data = []) {

        return true;
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
     *
     * @return mixed
     */
    final public function __get($field) {

        return $this->manager->get($field);
    }

    /**
     * @param $name
     *
     * @return bool
     */
    final public function __isset($name) {

        return isset($this->manager->$name);
    }

    protected function getEntity() {

        return $this->manager->getEntity();
    }

    protected function getTable() {

        return $this->manager->getTable();
    }

    /**
     * @return null|Manager
     */
    protected function getParentManager() {

        if ($parentData = $this->manager->getData('_parent')) {

            return $parentData['_manager'];
        }

        return null;
    }

    protected function getParentData($key = null) {

        if ($parentManager = $this->getParentManager()) {

            return $parentManager->getData($key);
        }

        return $this->manager->getData($key);
    }

    protected function getData($key = null) {

        return $this->manager->getData($key);
    }

    protected function getUser($key = null) {

        return $this->manager->getUser($key);
    }
}