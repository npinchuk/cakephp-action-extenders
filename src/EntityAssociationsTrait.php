<?php
/**
 * Created by PhpStorm.
 * User: nikita
 * Date: 24.04.17
 * Time: 5:25
 */

namespace Extender;


use Cake\ORM\Table;

trait EntityAssociationsTrait
{
    /**
     * @var string[] - ['assoc1' => 'incorpoarted', 'assoc1.assoc2' => 'embedded', ...]
     */
    private $associated;

    public function getAssociated() {
        /** @var Table $this */
        if (!$this->associated) {
            $this->associated = static::getTableAssociated($this);
        }

        return $this->associated;
    }

    /**
     * Sets extra property Association::belongingType
     * from _type association option
     *
     * @param       $associated
     * @param array $options
     *
     * @return mixed
     */
    public function belongsTo($associated, array $options = []) {
        /** @see Table::belongsTo() */
        $association = parent::belongsTo($associated, $options);

        /** @var Association $association */
        if (isset($options['_type'])) {
            $association->belongingType = $options['_type'];
        }

        return $association;
    }

    /**
     * Returns associations array with model.subModel keys
     *
     * @param Table  $object
     * @param string $parent
     *
     * @return Association[]
     */
    private static function getTableAssociated(Table $object, $parent = '') {
        $associated = [];

        /** @var Association $association */
        foreach ($object->associations() as $association) {

            if (isset($association->belongingType)) {
                $target             = $association->getTarget();
                $alias              = $parent . (!$parent ? '' : '.') . $target->getAlias();
                $associated[$alias] = $association->belongingType;
                $associated         += static::getTableAssociated($target, $alias);
            }
        }

        return $associated;
    }

    /**
     * Iterates over associated entities map with giving access to corresponding paths in $data
     *
     * @param          $data
     * @param array    $pathsAndValues
     * @param callable $modifier - function ($pathsAndValues[$path], $path, &$data[$path], &&$data[$pathPrevious])
     * @param bool     $createOnEmpty
     */
    private function walkWithAssociated(&$data, callable $modifier, $createOnEmpty = false) {
        $pathsAndValues = array_reverse($this->getAssociated(), true);

        foreach ($pathsAndValues as $path => $value) {
            $previous  = &$data;
            $current   = &$data;
            $pathArray = explode('.', $path);
            $break     = false;

            foreach ($pathArray as $i => $key) {
                $break = false;

                if (is_array($previous) || $previous instanceof ArrayObject) {
                    (isset($previous[$key]) or $createOnEmpty and !$previous[$key] = [] or !$break = true)
                    and (isset($pathArray[$i + 1]) ? $previous = &$previous[$key] : $current = &$previous[$key]);
                }
                elseif (is_object($previous)) {
                    (isset($previous->$key) or $createOnEmpty and $previous->$key = (object)[] or !$break = true)
                    and (isset($pathArray[$i + 1]) ? $previous = &$previous->$key : $current = &$previous->$key);
                }
                else {
                    $break = true;
                }

                if ($break) {
                    break;
                }
            }

            if (!$break) {
                $modifier($value, $pathArray, $current, $previous);
            }
        }
    }
}