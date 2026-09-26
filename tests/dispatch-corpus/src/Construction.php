<?php

namespace App;

final class Construction
{
    public function __construct()
    {
        Gateway::staticExpensive();
    }
}
