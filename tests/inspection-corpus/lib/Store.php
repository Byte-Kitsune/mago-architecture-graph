<?php
namespace Storage;
final class Store {
    public function find(): void { $this->read(); }
    private function read(): void {}
}
final class Unused { public function run(): void {} }
