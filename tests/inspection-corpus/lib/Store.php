<?php
namespace Storage;
/** [DEV COMMENT] Storage contract */
final class Store {
    /** @deprecated Use search instead */
    public function find(): void { $this->read(); }
    private function read(): void {}
}
final class Unused { public function run(): void {} }
