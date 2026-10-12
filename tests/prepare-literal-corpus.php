<?php

declare(strict_types=1);
$work = $argv[1];
$root = $argv[2];
$policy = json_decode(file_get_contents($work . '/policy.json'), true, 64, JSON_THROW_ON_ERROR);
$policy['dependency_mode'] = 'class-references';
file_put_contents($work . '/policy.json', json_encode($policy, JSON_THROW_ON_ERROR));
file_put_contents($work . '/mago.toml', str_replace('../../vendor', $root . '/vendor', file_get_contents($work . '/mago.toml')));
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($work . '/app', FilesystemIterator::SKIP_DOTS)) as $file) {
    if ($file->getExtension() !== 'php') continue;
    $text = file_get_contents($file->getPathname());
    preg_match_all('/^use ([A-Za-z_\\\\][A-Za-z0-9_\\\\]*) as (Ref[0-9]+);$/m', $text, $matches, PREG_SET_ORDER);
    foreach ($matches as $match) {
        $text = str_replace($match[0], '', $text);
        $text = preg_replace_callback('/\b' . preg_quote($match[2], '/') . '\b/', static fn () => '\\' . $match[1], $text);
    }
    file_put_contents($file->getPathname(), $text);
}
