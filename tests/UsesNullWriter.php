<?php

namespace Valet\Tests;

use Illuminate\Container\Container;

trait UsesNullWriter
{
    public function setNullWriter()
    {
        Container::getInstance()->instance('writer', new NullWriter());
    }
}
