<?php

namespace Extender;

use Cake\ORM\Entity;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;
use Cake\Validation\Validator;

class Manager
{
    /**
     * @var \Cake\ORM\Table - cake table object
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
     * @var Entity
     */
    private $entity;

    /**
     * Manager constructor.
     *
     * @param string|Table $table - table name or table object
     * @param string $action - action to be executed
     * @param object|array $data - data to be processed
     */
    public function __construct($table, $action, $data)
    {
        $this->table = is_string($table) ? TableRegistry::get($table) : $table;

        $this->action = $action;

        $this->data = (array) $data;

        $this->buildExtendersList();

        $this->buildFieldsConfig();

        $this->buildFieldsDefaults();

    }

    /**
     * Gather validation rules from extenders list
     *
     * @param Validator $validator
     * @return Validator
     */
    public function validation(Validator $validator) {

        foreach ($this->extendersList as $extender) {
            $validator = $extender->__validation($validator);
        }

        return $validator;
    }

    /**
     * @return string - corresponding Model/Action/$table/$action directory path
     */
    private function getExtendersDir() {

        return dirname((new \ReflectionClass($this->table))->getFileName())
            . DS . '..' . DS .'Action' . DS . $this->table->getAlias() . DS . $this->action;
    }

    /**
     * @return string - namespace for extenders according to model and action
     */
    private function getExtendersNamespace() {

        return '\\' . preg_replace('~Table$~', 'Action\\' . $this->table->getAlias() . '\\' . $this->action,
            (new \ReflectionClass($this->table))->getNamespaceName()) . '\\';
    }

    /**
     * Creates extenders objects based on __check() calls
     */
    private function buildExtendersList() {
        $extendersDir = $this->getExtendersDir();
        $extendersNamespace = $this->getExtendersNamespace();
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
            $methods = get_class_methods(get_class($extender));
            $functionsConfig = $extender->getFunctionsConfig();
            $functionConfigDefault = $extender->functionConfigDefault;

            foreach ($methods as $method){

                if (preg_match( '/^__/', $method)) continue; // for service functions

                $config = isset($functionsConfig[$method]) ? $functionsConfig[$method] : $functionConfigDefault ;

                $type = $config['type'];
                $scope = array(
                    'area' => isset($config['scope']['area']) ? $config['scope']['area'] : 'row',
                    'conditions' => isset($config['scope']['conditions']) ? $config['scope']['conditions'] : 'any'
                );

                $this->fieldsConfig[$method] = compact('extender', 'type', 'scope');
            }

        }
    }

    /**
     * Glue default values array from ones defined in extenders
     */
    private function buildFieldsDefaults() {

        foreach ($this->extendersList as $extender) {

            $this->fieldsDefaults = $extender->getDefaults() + $this->fieldsDefaults;
        }
    }

    /**
     * Just touch each field that results calculation
     */
    private function calculateFields() {

        foreach (array_keys($this->fieldsConfig) as $field){
            $this->$field;
        }

        foreach (array_keys($this->fieldsDefaults) as $field){
            $this->$field;
        }
    }

    /**
     * Calculate single field based on fields config
     *
     * @param $name
     * @return bool
     */
    private function calculateField($name) {
        $result = false;
        /** @var BaseExtender $extender */
        /** @var array $scope */
        /** @var array $type */
        extract($this->fieldsConfig[$name]);

        if ( ( $scope['conditions'] == 'any' ) || ( $extender->{$scope['conditions']}( $this->_data ) ) ){
            if ( $scope['area'] == 'all' ){
                // bulk processing
            } else {
                /** @see calculateConverter, calculateInstaller, calculateFiller */
                $result = $this->{'calculate' . ucfirst($type)}($name);
            }
        }
        return $result;
    }

    /**
     * Converter function is run just
     * in case when corresponding field passed in data array
     *
     * @param $name
     * @return mixed
     */
    private function calculateConverter($name) {
        $res = false;

        if (isset($this->data[$name])){
            $res = $this->fieldsConfig[$name]['extender']->{$name}();
        }

        return $res;
    }

    /**
     * Installer function is run anyway
     *
     * @param $name
     * @return mixed
     */
    private function calculateInstaller($name) {

        return $this->fieldsConfig[$name]['extender']->{$name}();
    }

    /**
     * Filler function is run just
     * in case when corresponding field is not passed in data array
     *
     * @param $name
     * @return mixed
     */
    private function calculateFiller($name){

        if (isset($this->data[$name])) {
            $res = $this->data[$name];
        } else {
            $res = $this->fieldsConfig[$name]['extender']->{$name}();
        }

        return $res;
    }

    /**
     * @param $name
     * @return bool
     */
    public function __isset($name){

        return !empty($this->data[$name]) || isset($this->fieldsConfig[$name]) && !empty($this->fieldsConfig[$name]['default']);
    }

    /**
     * @param $name
     * @return mixed
     * @throws \Exception
     */
    public function __get($name){

        if(isset($this->fieldsConfig[$name] ) ){
            $this->data[$name] = $this->calculateField($name);
            unset($this->fieldsConfig[$name]);
        } else {

            if (!isset($this->data[$name])) {

                if(!isset($this->fieldsDefaults[$name] ) )
                    throw new \Exception( 'Undefined property ' . $this->table->getAlias() . '::' . $this->action . '->' . $name );
                $this->data[$name] = $this->fieldsDefaults[$name];
            }
        }

        return $this->data[$name];
    }

    /**
     * Process data with extenders logic, generate entity and save
     *
     * @param array $options
     * @return bool|\Cake\Datasource\EntityInterface|mixed
     */
    public function run($options = []) {
        $this->calculateFields();
        $this->entity = $this->table->newEntity();
        $this->table->patchEntity($this->entity, $this->data);

        if ($result = $this->table->save($this->entity)) {

            foreach ($this->extendersList as $extender) {
                $extender->__finalize($this->data);
            }
        }

        return $result;
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
}