<?php
namespace App;
final class Entry {
    public function map(): void { \array_map(fn() => Gateway::staticExpensive(), [1]); }
    public function immediate(): void { (fn() => Gateway::staticExpensive())(); }
    public function method(): void { \array_walk([1], Gateway::staticExpensive(...)); }
    public function classArray(): void { \call_user_func([Gateway::class, 'staticExpensive']); }
    public function literalString(): void { \call_user_func('App\\Gateway::staticExpensive'); }
    public function stringFunction(): void { \call_user_func('App\\eagerFunction'); }
    public function stringClassArray(): void { \call_user_func(['App\\Gateway', 'staticExpensive']); }
    public function invokable(): void { \array_map(new Invokable(), [1]); }
    public function comparator(): void { \array_udiff([1], [2], fn($a, $b) => Gateway::staticExpensive()); }
    public function regexMapping(): void { \preg_replace_callback_array(['/x/' => fn($m) => Gateway::staticExpensive()], 'x'); }
    public function empty(): void { \array_map(fn() => Gateway::staticExpensive(), []); }
    public function emptyWithSecondArray(): void { \array_map(fn() => Gateway::staticExpensive(), [], [1]); }
    public function deferred(): void { $cb = fn() => Gateway::staticExpensive(); }
    public function registered(): void { \register_shutdown_function(fn() => Gateway::staticExpensive()); }
    public function generator(): void { \array_map(fn() => yield Gateway::staticExpensive(), [1]); }
}
