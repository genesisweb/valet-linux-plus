<?php

namespace Valet\Facades;

use Illuminate\Container\Container;
use Illuminate\Contracts\Container\BindingResolutionException;

/**
 * Class Facade.
 */
class Facade
{
    /**
     * The key for the binding in the container.
     */
    public static function containerKey(): string
    {
        return 'Valet\\'.basename(str_replace('\\', '/', get_called_class()));
    }

    /**
     * Call a non-static method on the facade.
     * @return mixed
     * @throws BindingResolutionException
     */
    public static function __callStatic(string $method, array $parameters)
    {
        $resolvedInstance = Container::getInstance()->make(static::containerKey());

        return call_user_func_array([$resolvedInstance, $method], $parameters);
    }
}
