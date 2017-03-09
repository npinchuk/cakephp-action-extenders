<?php
/**
 * Created by PhpStorm.
 * User: nikita
 * Date: 09.03.17
 * Time: 12:50
 */

namespace Extender;


class Manager
{
    // static $result_history = array();

    public $tr_list = array();
    public $init_cond = array();
    //public $current_tr = null;
    //public $scope = 'row';
    public $scope_val;
    public $input_data;
    public $controller;
    // public $_tr_cond;

    public $required = array();
    public $rules = array();
    public $_data;
    public $raw_data;
    public $_model;
    private $finalize = true;
    private $save_model = true;
    private $verbose = true;
    private $conditions_model;
    private $_action;
    private $_fields = array(); //'field' => $object
    private $_default_fields = array();
    private $_extenders_list = array(); //list for call finalize functions
    public function __construct( $model, $action, $data, $init_cond = null ){

        $this->{$model->name} = $model;
        $this->_model = $model;
        $this->_action = $action;
        $model = $model->name;
        $this->_data = isset( $data[ $model ] ) ? $data[ $model ] : $data;

        if( isset( $this->_data['__finalize'] ) && $this->_data['__finalize'] === false ) $this->finalize = false;
        if( isset( $this->_data['__save_model'] ) && $this->_data['__save_model'] === false ) $this->save_model = false;
        if( isset( $this->_data['__verbose'] ) && $this->_data['__verbose'] === false ) $this->verbose = false;

        $this->raw_data = $this->_data;
        $this->input_data = ( $this->_data !== null ) ? $this->_data : array(); //prevent init data to rewrite
        $this->conditions_model = $this->_model;
        if( $init_cond != null){
            // print_r($init_cond);
            $this->init_cond = $init_cond;
            $parts = explode( '.', key($init_cond) );
            $conditions_model = count($parts) > 1 ? $parts[0] : $model;
            //			 debug($conditions_model);

            if ( $conditions_model != $model ){
//                $conditions_model = ClassRegistry::init( $conditions_model );
//                if( $conditions_model->check_test_db() ){
//                    $conditions_model->useDbConfig = 'test';
//                }
            } else {
                $conditions_model = $this->_model;
            }

            $this->tr_list = $conditions_model->find('all', [
                'conditions' => $init_cond,
                'order'      => [$conditions_model->name . '.id' => 'desc'],
            ]);

            // print_r($this->tr_list);
            //            debug($_SESSION);
            //            debug($this->tr_list);
            // $init_transaction = reset($this->tr_list);
            // debug($init_transaction[$this->conditions_model->name]);
            // debug($this->input_data);
            //			 debug($init_cond);

            $this->_data = $this->input_data;
            // print_r($this->_data);
            // die;,
        }

        foreach( $this->$model->belongsTo as $k => $v ){
//            $tmp_class = ClassRegistry::init( $v[ 'className' ] );
//            if( $tmp_class->check_test_db() ){
//                $tmp_class->useDbConfig = 'test';
//            }
//            if ( isset( $this->_data[ $v[ 'foreignKey' ] ] ) && !empty( $this->_data[ $v[ 'foreignKey' ] ] ) ){
//                $cond = array( 'conditions' => array(
//                    $tmp_class->name . '.id' => $this->_data[ $v[ 'foreignKey' ] ]
//                ));
//                $tmp_class->recursive = 2;
//
//                $res = $tmp_class->load( 'first', $cond );
//                // if( $tmp_class->name == 'MerchantPaymentMethod' ){
//                // debug($tmp_class->useDbConfig);
//                // debug($res);
//                // die;
//                // }
//                if ($res)
//                    if ( $model != $v[ 'className' ] ){
//                        foreach( $res as $class => $class_data )
//                            $this->_data[ $class ] = $class_data;
//                    }else{
//                        $this->_data[ $k ] = $res[$model];
//                    }
//            }
        }
        foreach( $this->$model->hasMany as $k => $v ){
//            if (isset($this->_data['id'])){
//                $tmp_class = ClassRegistry::init( $v[ 'className' ] );
//                if( $tmp_class->check_test_db() ){
//                    $tmp_class->useDbConfig = 'test';
//                }
//                $cond = array( 'conditions' => array(
//                    $v[ 'className' ] . '.' . $v[ 'foreignKey' ] => $this->id
//                ));
//                $res = $tmp_class->load( 'all', $cond );
//                if ($res)
//                    $this->_data[ $k ] = $res;
//            }
        }



        if( !($ca = $this->_model->checkAccess( $action, $this->_data )) ){
//            $e = new Exception ( 'Forbidden due user\'s settings ' . $this->_model->name . ' ' . $action );
//            $e->error_code = PaybWebServiceController::ACTION_FORBIDDEN;
//            throw $e;
        }

        $path = APP  . 'Extender' . DS . $model . DS . $action;
        $dir = array_flip( scandir( $path ) );
        $package = 'Extender/' . $model . '/' . $action;
        $className = $model . $action . 'DefaultExtender';

        unset( $dir[ '.' ], $dir[ '..' ], $dir[ $className . '.php' ] );
        $dir[ $className.'.php' ] = '';

        foreach ( array_reverse( $dir ) as $file => $v ){
            if ( !is_dir($path.DS.$file) ){
                $className = basename($file, '.php');
                App::uses( $className, $package );
                $tmp = new $className( $this );
                /**
                 *	Load Extenders
                 */
                if ( $tmp->__check( $this->_data ) ){
                    $this->required = $this->required + $tmp->required;
                    $this->rules = $tmp->rules + $this->rules;

                    $tmp->__init( $this->_data );
                    // $tmp->__init( $init_cond );
                    $this->_extenders_list[$className] = $tmp;
                    $this->_default_fields = $tmp->defaults + $this->_default_fields;
                    $methods = get_class_methods( $className );
                    $parent_methods = get_class_methods( get_parent_class( $className ));
                    $methods = array_diff( $methods, $parent_methods );
                    //setup functions: type, scope
                    $functions_config = $tmp->functions_config;
                    $functions_config_default = $tmp->functions_config_default;
                    foreach( $methods as $k=>$method ){
                        if( preg_match( '/^__/', $method ) ) continue; // for service functions
                        $conf = isset( $functions_config[ $method ] ) ? $functions_config[ $method ] : $functions_config_default ;

                        $f_type = $conf['type'];
                        $f_scope = array(
                            'area' => isset( $conf['scope']['area'] ) ? $conf['scope']['area'] : 'row',
                            'conditions' => isset( $conf['scope']['conditions'] ) ? $conf['scope']['conditions'] : 'any'
                        );

                        $this->_fields[$method] = array(
                            'extender' => $tmp,
                            'type' => $f_type,
                            'scope' => $f_scope
                        );
                    }
                }
            }
        }
    }
    public function getExtenders(){
        return array_keys($this->_extenders_list);
    }

    public function __isset( $name ){
        return isset( $this->_data[ $name ] ) && ( $this->_data[ $name ] != '' || isset( $this->_default_fields[ $name ] ) );
    }
    public function __get( $name ){
        // echo 'this is Manager`s __get: '.$name." for action ".$this->_action."<br>";
        /*if($name = 'provider_settlement_amount')
        debug($this->scope_val);*/
        if (isset($this->scope_val[$name]) ){
            return $this->scope_val[$name];
        }
        if( isset( $this->_fields[ $name ] ) ){
            $config = $this->_fields[ $name ];
            $data_val = $this->function_exec( $name, $config );
            if( $data_val !== false )
                if( empty( $this->init_cond ) ){
                    $this->_data[ $name ] = $data_val;
                    unset( $this->_fields[ $name ] );
                }else{
                    if ( $config['scope']['area'] == 'all' ){
                        $this->scope_val[ $name ] = $data_val;
                    }
                    return $data_val;
                }
        } else {
            if( !isset( $this->_data[ $name ] ) && !isset( $this->_default_fields[ $name ] ) )
                throw new \Exception( 'Undefined property ' . $this->_model->name . '::' . $this->_action . '->' . $name );
            if( ( !isset( $this->_data[ $name ] ) || empty( $this->_data[ $name ] ) ) && isset( $this->_default_fields[ $name ] ) )
                $this->_data[ $name ] = $this->_default_fields[ $name ];
        }
        return isset( $this->_data[ $name ] ) ? $this->_data[ $name ] : false;
    }

    public function unsetData( $field ){
        unset($this->_data[$field]);
        return true;
    }

    public function calculate ( $tr = null ){
        // debug(array_keys($this->_fields));//die('rip');
        if( $tr !== null ){
            foreach ( $this->_fields as $field => $extender ){
                $tr[$field] = $this->$field;
            }
            foreach ( $this->_default_fields as $field => $extender ){
                $tr[$field] = $this->$field;
            }
            return $tr;
        }else{
            foreach ( $this->_fields as $field => $extender ){
                $this->$field;
            }
            foreach ( $this->_default_fields as $field => $extender ){
                $this->$field;
            }
        }

    }
    public function save_model( $model, $result ){
        //if( $model->name == 'Transaction' )


        if( isset( $result['id'] ) && $this->_action == 'New' ){
            unset( $result['id'] );
        }
        $m = $model;

        if( $m->name != $this->conditions_model->name ){
            if( isset($result['id']) ) unset($result['id']);
        }
        $m->create();

        $model_validate = $m->validate;
        foreach( $this->required as $r_field ){
            $m->validate[$r_field]['required'] = true;
            $m->validate[$r_field]['allowEmpty'] = false;
        }
        foreach( $this->rules as $rule_field => $rule ){
            $m->validate[$rule_field]['rule'] = $rule['rule'];
            if (isset($rule['message'])) {
                $m->validate[$rule_field]['message'] = $rule['message'];
            }
        }
        $m->set( $result );
        if (!$m->validates()){
            return false;
        }
        //$save_res = $result ;
        $save_res = $m->save( $result );
        if ( $save_res ){
            $save_res = $save_res[$m->name];

            if(empty($this->tr_list)){
                $save_res = $this->exec_finalize( $save_res );
                $save_res = isset( $save_res[$m->name] ) ? $save_res[$m->name] : $save_res;
            }
        }else{
            throw new \Exception( 'model save failed ');
        }
        return $save_res;
    }
    public function manage( $data = null ){
        $m = $this->_model;
        $cond_m = $this->conditions_model;
        $manage_result = array();
        if( !empty($this->tr_list) ){
            foreach( $this->tr_list as &$tr ){
                $tr = $tr[$cond_m->name];
                $this->_data = $this->input_data + $tr + $this->_data;
                $tr = $this->calculate( $tr );
            }
            foreach( $this->tr_list as &$tr ){
                $this->_data = $tr+$this->input_data;
                if( $this->save_model )
                    array_push( $manage_result, $this->save_model( $m, $tr ) );
                else
                    array_push( $manage_result, $tr );
            }
            if( $this->finalize )
                $this->exec_finalize( array(), $this->init_cond );
        }else{
            $tr = $this->calculate();
            $result = $this->getData() + $this->input_data;
            // debug($this->input_data);
            // debug($result);
            $result['id'] = $this->checkSave( $result );
            // debug($this->save_model);
            // debug($this->_action);
            if( $this->save_model ){
                $manage_result = $this->save_model( $m, $result );
            }else{
                $manage_result = $this->getData();
            }
        }
        if( !$this->verbose ){
            return $this->cutData( $manage_result );
        }
        return $manage_result;
    }
    public function checkSave( $data ){
        $this->save_model = true;
        foreach ( $this->_extenders_list as $v )
            if( !$this->save_model = $v->__save( $data ) )
                break;
        return isset( $v->id ) ? $v->id : false;
    }
    public function exec_finalize( $data, $cond = null ){
        // echo'<pre>';
        // var_dump($this->_action);
        // var_dump($this->_model->name);
        // var_dump($data);
        // var_dump($this->input_data);

        // debug($data);
        // debug($this->input_data);
        // die;
        $input = $this->input_data;
        if( $data == null ) $data = array();
        if( $input == null ) $input = array();
        $res = $data + $input;
        // debug($res);
        // die;
        //file_put_contents('\tmp\cond' ,json_encode($cond),JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        foreach ( $this->_extenders_list as $v ){
            $res = $v->__finalize( $res, $cond );
        }
        return $res;
    }
    public function getData( $field = null ){
        // debug($field);
        if ( ($field !== null) && isset($this->_data[ $field ]) ){
            return $this->_data[ $field ];
        }else{
            if ( ($field !== null) && !isset($this->_data[ $field ])) return false;
            return $this->_data;
        }
    }
    public function cutData( $data ){
        $res = array();
        foreach ( $data as $k => $v ){
            if ( $k != ucfirst($k) ){
                $res[$k] = $data[$k];
            }
        }
        return $res;
    }
    public function getShortData(  ){
        $res = array();
        foreach ( $this->_data as $k => $v ){
            if ( $k != ucfirst($k) ){
                $res[$k] = $this->_data[$k];
            }
        }
        return $res;
    }

    public function set_controller( $controller ){
        $this->controller = $controller;
    }
    public function function_exec( $fname, $fconfig ){
        $result = false;
        $ext = $fconfig['extender'];
        if ( ( $fconfig['scope']['conditions'] == 'any' ) || ( $ext->{$fconfig['scope']['conditions']}( $this->_data ) ) ){
            if ( $fconfig['scope']['area'] == 'all' ){
                foreach( $this->tr_list as $tr )
                    if(!isset($this->scope_val[$fname] )){
                        //	$this->_data = $this->input_data + $tr[$this->conditions_model->name] + $this->_data;
                        $this->scope_val[$fname] = $this->{'function_'.$fconfig['type'].'_exec'}( $fname, $fconfig);
                    }
                $result = $this->scope_val[$fname];
            }else{
                $result = $this->{'function_'.$fconfig['type'].'_exec'}( $fname, $fconfig );
            }
        }
        return $result;
    }
    public function function_converter_exec( $fname, $fconfig ){
        $res = false;
        if( isset( $this->_data[ $fname ] ) ){
            $ext = $fconfig['extender'];
            $res = $ext->{$fname}( );
        }
        return $res;
    }
    public function function_installer_exec( $fname, $fconfig ){
        $res = false;
        $ext = $fconfig['extender'];
        $res = $ext->{$fname}();
        return $res;
    }
    public function function_filler_exec( $fname, $fconfig ){
        $res = false;
        if( isset( $this->_data[ $fname ] ) ){
            $res = $this->_data[ $fname ];
        }else{
            $ext = $fconfig['extender'];
            $res = $ext->{$fname}();
        }
        return $res;
    }

}