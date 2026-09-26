<?php

declare(strict_types=1);

namespace ByteKitsune\MagoArchitectureGraph\Analyzer;

/** Iterative strongly connected components; a long call chain cannot exhaust PHP's stack. */
final class GraphCycles
{
    /** @param array<string, list<string>> $adjacency @return list<list<string>> */
    public static function find(array $adjacency): array
    {
        ksort($adjacency);
        $reverse = array_fill_keys(array_keys($adjacency), []);
        foreach ($adjacency as $from => $targets) {
            $targets = array_values(array_unique(array_filter($targets, static fn (string $to): bool => isset($adjacency[$to]))));
            sort($targets);
            $adjacency[$from] = $targets;
            foreach ($targets as $to) $reverse[$to][] = $from;
        }
        foreach ($reverse as &$sources) sort($sources);
        unset($sources);

        $visited = $order = [];
        foreach (array_keys($adjacency) as $start) {
            if (isset($visited[$start])) continue;
            $visited[$start] = true;
            $stack = [[$start, 0]];
            while ($stack !== []) {
                $last = count($stack) - 1;
                [$node, $index] = $stack[$last];
                if ($index === count($adjacency[$node])) {
                    $order[] = $node;
                    array_pop($stack);
                    continue;
                }
                $stack[$last][1]++;
                $next = $adjacency[$node][$index];
                if (isset($visited[$next])) continue;
                $visited[$next] = true;
                $stack[] = [$next, 0];
            }
        }

        $visited = $cycles = [];
        foreach (array_reverse($order) as $start) {
            if (isset($visited[$start])) continue;
            $component = [];
            $stack = [$start];
            $visited[$start] = true;
            while ($stack !== []) {
                $node = array_pop($stack);
                $component[] = $node;
                foreach ($reverse[$node] as $next) {
                    if (isset($visited[$next])) continue;
                    $visited[$next] = true;
                    $stack[] = $next;
                }
            }
            sort($component);
            if (count($component) > 1 || in_array($start, $adjacency[$start], true)) $cycles[] = $component;
        }
        usort($cycles, static fn (array $a, array $b): int => strcmp($a[0], $b[0]));
        return $cycles;
    }
}
