<?php

use Bangpound\Bridge\Drupal\DrupalInterface;

class Druplex implements DrupalInterface
{
    /**
     * @var \Pimple
     */
    protected static $pimple;

    /**
     * {@inheritDoc}
     */
    public static function setPimple(\Pimple $pimple = null)
    {
        self::$pimple = $pimple;
    }

    /**
     * Returns true if the service id is defined.
     *
     * @param string $id The service id
     *
     * @return Boolean true if the service id is defined, false otherwise
     */
    public static function has($id)
    {
        return self::$pimple->offsetExists($id);
    }

    /**
     * Gets a service by id.
     *
     * @param string $id The service id
     *
     * @return object The service
     */
    public static function get($id)
    {
        return self::$pimple->offsetGet($id);
    }

    public static function getResponse()
    {
        return self::$pimple['legacy.response'];
    }

    public static function getSession()
    {
        // TODO: Implement getSession() method.
    }

    public static function getEventDispatcher()
    {
        return self::$pimple['dispatcher'];
    }

    public static function getKernel()
    {
        return self::$pimple['kernel'];
    }

    public static function getLogger()
    {
        return self::$pimple['logger'];
    }
}
