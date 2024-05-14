<?php

namespace Valet;

class Request extends \Httpful\Request
{
    public function __construct($attrs = null)
    {
        parent::__construct($attrs);
    }
}
