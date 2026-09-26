<?php

namespace App;

class BaseConstruction
{
    public function __construct()
    {
        Gateway::staticExpensive();
    }
}
