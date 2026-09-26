<?php

namespace App;

function hidden(): void
{
    Gateway::staticExpensive();
}

function bridge(): void
{
    hidden();
}

function constructGateway(): void
{
    new Construction();
}

function dynamicHelper(): void
{
    $callback = 'strlen';
    $callback('example');
}
