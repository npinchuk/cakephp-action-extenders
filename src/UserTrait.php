<?php

namespace Extender;


use Cake\ORM\Association;
use Cake\ORM\Table;

trait UserTrait
{
    /**
     * Associated entities map
     *
     * @var array|\ArrayAccess
     */
    private $user;

    public function setUser($user) {
        $this->user = $user;

        return $this;
    }

    public function getUser($key = null) {

        return !is_null($key) ? !empty($this->user[$key]) ? $this->user[$key] : null : $this->user;
    }
}