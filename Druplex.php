<?php

class Druplex implements \Bangpound\Bridge\Drupal\DrupalInterface
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
}
