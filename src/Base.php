<?php
/**
 * Created by PhpStorm.
 * User: nikita
 * Date: 09.03.17
 * Time: 12:46
 */

namespace Extender;


class Base
{
    /**
     * @var Manager
     */
    public $manager;
    public $defaults = array();
    public $required = array();
    public $rules = array();
    public $onDemand = true;
    public $functions_config = array();
    public $functions_config_default = array(
        'type' => 'installer',
        'scope' => array(
            'area' => 'row',
            'conditions' => 'any'
        )
    );

    public function __construct(Manager $manager) {
        $this->manager = $manager;
    }

    public function __check($data) {
        return true;
    }

    public function __get($field) {
        if (substr($field, 0, 1) == strtoupper(substr($field, 0, 1))) {
            if (isset($this->manager->_model->belongsTo[$field])) {
//                $v = $this->manager->_model->belongsTo[$field];
//                $tmp_class = ClassRegistry::init($v['className']);
//                if ($tmp_class->check_test_db()) {
//                    $tmp_class->useDbConfig = 'test';
//                }
//                if (isset($this->manager->_data[$v['foreignKey']]) && !empty($this->manager->_data[$v['foreignKey']])) {
//                    $cond = array('conditions' => array(
//                        $tmp_class->name . '.id' => $this->manager->_data[$v['foreignKey']]
//                    ));
//
//                    $tmp_class->recursive = 2;
//
//                    $res = $tmp_class->load('first', $cond);
//                    // if( $tmp_class->name == 'MerchantPaymentMethod' ){
//                    // debug($tmp_class->useDbConfig);
//                    // debug($res);
//                    // die;
//                    // }
//                    if ($res)
//                        if ($this->manager->_model->name != $v['className']) {
//                            foreach ($res as $class => $class_data)
//                                if($class == $field)
//                                    $return[$field] = $class_data;
//                                else
//                                    $return[$field][$class] = $class_data;
//                            return $return[$field];
//                        } else {
//                            foreach($res as $class => $class_data)
//                                if($class == $field)
//                                    continue;
//                                else
//                                    $return[$field][$class] = $class_data;
//                            return $return[$field];
//                        }
//                }
            }
        }
        if (isset($this->manager->scope_val[$field]))
            return $this->manager->scope_val[$field];
        if (isset($this->manager->_data[$field]))
            return $this->manager->_data[$field];
        return @$this->manager->$field;
    }

    public function __isset($name) {
        return isset($this->manager->$name);
    }

    public function __init($data) {
        return true;
    }

    public function __save($data) {
        return true;
    }

    public function __finalize($data, $cond) { // you can access to 'id' saved model like $this->model_name->id
        return $data;
    }

    protected function _getInput($field) {
        return $this->manager->getData($field);
    }

}