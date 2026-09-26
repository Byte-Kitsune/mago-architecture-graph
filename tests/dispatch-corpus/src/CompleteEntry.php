<?php

namespace App;

use function App\Sub\aliasTarget as importedTarget;

class CompleteEntry
{
    private readonly Port $port;

    public function __construct(Port $port)
    {
        $this->port = $port;
    }

    public function run(string $text): void
    {
        if (\strlen($text) === 0) return;
        $this->fixed();
    }

    final public function fixed(): void
    {
        $this->port->expensive();
    }

    public function callback(): void
    {
        \call_user_func([$this->port, 'expensive']);
    }

    public function arrayCallback(): void
    {
        \call_user_func_array([$this->port, 'expensive'], []);
    }

    public function staticCallback(): void
    {
        \call_user_func([Gateway::class, 'staticExpensive']);
    }

    public function construction(): void
    {
        new Construction();
    }

    public function inheritedConstruction(): void
    {
        new ChildConstruction();
    }

    public function functionChain(): void
    {
        bridge();
    }

    public function importedFunction(): void
    {
        importedTarget();
    }

    public function functionConstruction(): void
    {
        constructGateway();
    }
}
