<?php
namespace App;
final class Invokable { public function __invoke(): void { Gateway::staticExpensive(); } }
function eagerFunction(): void { Gateway::staticExpensive(); }
