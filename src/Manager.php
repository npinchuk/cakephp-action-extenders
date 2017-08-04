<?php

namespace Extender;

use Cake\Datasource\EntityInterface;
use Cake\ORM\Association\BelongsTo;
use Cake\ORM\Entity;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;
use Cake\Utility\Inflector;
use Cake\Validation\Validation;
use Cake\Validation\Validator;

class Manager
{
    /**
     * @var Table|ActionableTrait - cake table object
     */
    private $table;

    /**
     * @var string - action name
     */
    private $action;

    /**
     * @var BaseExtender[] - list of extenders
     */
    private $extendersList = [];

    /**
     * @var array - fields config aggregated from extenders
     */
    private $fieldsConfig = [];

    /**
     * @var array - fields default values aggregated from extenders
     */
    private $fieldsDefaults = [];

    /**
     * @var array - data to be processed
     */
    private $data = [];

    /**
     * @var En`tity
     */
    private $entity;

    /**
     * Manager constructor.
     *
     * @param string|Table $table  - table name or table object
     * @param string       $action - action to be executed
     * @param object|array $data   - data to be processed
     */
    public function __construct($action, &$data) {

        $this->action = $action;

        $this->data = &$data;

    }

    public function getAction() {

        return $this->action;
    }

    public function setTable($table) {
        $this->table = is_string($table) ? TableRegistry::get($table) : $table;

        $this->buildExtendersList();
        $this->buildFieldsConfig();
        $this->buildFieldsDefaults();

        return $this;
    }

    /**
     * Gather validation rules from extenders list
     *
     * @param Validator $validator
     *
     * @return Validator
     */
    public function validation(Validator $validator) {

        /** @var BaseExtender $extender */
        foreach ($this->extendersList as $extender) {

            if ($required = $extender->__getRequired()) {

                foreach ($required as $field) {
                    $validator->requirePresence($field)->add($field, 'is-here', [

                        'rule'    => function ($value, $context) {

                            if (is_scalar($value)) {

                                return Validation::notBlank($value);
                            }

                            return true;
                        },
                        'message' => 'This field cannot be left empty',
                    ]);
                }
            }
            $validator = $extender->__validation($validator);
        }

        return $validator;
    }

    public function getModelName() {

        return substr(strrchr(get_class($this->table), "\\"), 1, -5);
    }

    /**
     * @return string - corresponding Model/Action/$table/$action directory path
     */
    private function getExtendersDir() {

        return dirname((new \ReflectionClass($this->table))->getFileName())
            . DS . '..' . DS . 'Action' . DS . $this->getModelName() . DS . $this->action;
    }

    /**
     * @return string - namespace for extenders according to model and action
     */
    private function getExtendersNamespace() {

        return '\\' . preg_replace('~Table$~', 'Action\\' . $this->getModelName() . '\\' . $this->action,
                (new \ReflectionClass($this->table))->getNamespaceName()) . '\\';
    }

    /**
     * Creates extenders objects based on __check() calls
     */
    private function buildExtendersList() {
        $extendersDir                           = $this->getExtendersDir();
        $extendersNamespace                     = $this->getExtendersNamespace();
        $this->extendersList['DefaultExtender'] = ''; // default extender at first position

        foreach (array_diff(scandir($extendersDir), ['.', '..']) as $file) {
            $extenderName = pathinfo($file, PATHINFO_FILENAME);
            /** @var BaseExtender $extenderClassName */
            $extenderClassName = $extendersNamespace . $extenderName;

            if ($extenderClassName::__check($this->data)) {
                /** @var BaseExtender $extender */
                $extender = new $extenderClassName($this);
                $extender->__init($this->data);
                $this->extendersList[$extenderName] = $extender;
            }
        }
    }

    /**
     * Glue resulting config of extenders configs
     */
    private function buildFieldsConfig() {

        foreach ($this->extendersList as $extender) {
            $functionsConfig       = $extender->__getFunctionsConfig();
            $functionConfigDefault = $extender->__getFunctionConfigDefault();

            foreach ((new \ReflectionClass($extender))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                $method = $method->getName();

                if (preg_match('/^__/', $method)) {
                    continue;
                } // for service functions

                $config = isset($functionsConfig[$method]) ? $functionsConfig[$method] : $functionConfigDefault;

                $type = $config['type'];

                $this->fieldsConfig[$method] = compact('extender', 'type');
            }
        }
    }

    /**
     * Glue default values array from ones defined in extenders
     */
    private function buildFieldsDefaults() {

        foreach ($this->extendersList as $extender) {

            $this->fieldsDefaults = $extender->__getDefaults() + $this->fieldsDefaults;
        }
    }

    /**
     * Just touch each field that results calculation
     */
    private function calculateFields() {

        foreach (array_keys($this->fieldsConfig) as $field) {
            $this->get($field);
        }

        foreach (array_keys($this->fieldsDefaults) as $field) {
            $this->get($field);
        }
    }

    /**
     * Calculate single field based on fields config
     *
     * @param $name
     *
     * @return bool
     */
    private function calculateField($name) {
        $result = false;
        /** @var BaseExtender $extender */
        /** @var array $type */
        extract($this->fieldsConfig[$name]);
        unset($this->fieldsConfig[$name]);
        $result = $this->{'calculate' . ucfirst($type)}($name, $extender);

        return $result;
    }

    /**
     * Converter function is run just
     * in case when corresponding field passed in data array
     *
     * @param $name
     *
     * @return mixed
     */
    private function calculateConverter($name, $extender) {
        $res = null;

        if (isset($this->data[$name])) {
            $res = $extender->{$name}();
        }

        return $res;
    }

    /**
     * Installer function is run anyway
     *
     * @param $name
     *
     * @return mixed
     */
    private function calculateInstaller($name, $extender) {

        return $extender->{$name}();
    }

    /**
     * Filler function is run just
     * in case when corresponding field is not passed in data array
     *
     * @param $name
     *
     * @return mixed
     */
    private function calculateFiller($name, $extender) {

        if (isset($this->data[$name])) {
            $res = $this->data[$name];
        }
        else {
            $res = $extender->{$name}();
        }

        return $res;
    }

    /**
     * @param $name
     *
     * @return bool
     */
    public function __isset($name) {

        return !empty($this->data[$name]) || isset($this->fieldsConfig[$name]) && !empty($this->fieldsConfig[$name]['default']);
    }

    /**
     * @param $name
     *
     * @return mixed
     * @throws \Exception
     */
    public function get($name) {

        if ($name == $this->getModelName()) {

            return $this->getTable();
        }

        if ($association = $this->getTable()->association($name)) {

            if ($association instanceof BelongsTo) {

                if (empty($this->$name)) {
                    $bindingKey  = Inflector::camelize($association->getBindingKey());
                    $foreignKey  = $association->getForeignKey();
                    $this->$name = $association->getTarget()->{"getBy$bindingKey"}($this->get($foreignKey));
                }

                return $this->$name;
            }
        }

        if (isset($this->fieldsConfig[$name])) {
            $this->data[$name] = $this->calculateField($name);
        }
        else {

            if (!isset($this->data[$name])) {

                if (!isset($this->fieldsDefaults[$name])) {

                    throw new \Exception('Undefined property ' . $this->table->getAlias() . '::' . $this->action . '->' . $name);
                }
                $this->data[$name] = $this->fieldsDefaults[$name];
            }
        }

        return $this->data[$name];
    }

    /**
     * Process data with extenders logic, generate entity and save
     *
     * @param array $options
     *
     * @return bool|\Cake\Datasource\EntityInterface|mixed
     */
    public function run($options = []) {
        $this->calculateFields();
    }

    public function needSave() {

        /** @var BaseExtender $extender */
        foreach ($this->extendersList as $extender) {

            if (!$extender->__save($this->data)) {
                return false;
            };
        }

        return true;
    }

    public function executeAll($func = '__finalize') {
        $data = &$this->data;

        /** @var BaseExtender $extender */
        foreach ($this->extendersList as $extender) {
            $data = $extender->$func($data);
        }

        return $data;
    }

    /**
     * Entity object will be available after run()
     * function been executed
     *
     * @return Entity
     */
    public function getEntity() {

        return $this->entity;
    }

    public function setEntity(EntityInterface $entity) {
        $this->entity = $entity;

        return $this;
    }

    /**
     * @return Table|ActionableTrait
     */
    public function getTable() {

        return $this->table;
    }

    /**
     * @param string|null $key
     *
     * @return array|\ArrayAccess|mixed|null
     */
    public function getUser($key = null) {

        return $this->getTable()->getUser($key);
    }

    public function getData($key = null) {

        return !is_null($key) ? !isset($this->data[$key]) ? null : $this->data[$key] : $this->data;
    }
}