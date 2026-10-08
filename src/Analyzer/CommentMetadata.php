<?php

declare(strict_types=1);

namespace ByteKitsune\MagoArchitectureGraph\Analyzer;

use PhpParser\Node;
use PhpParser\NodeFinder;

/** Source annotations are data, never executable graph policy or transitive findings. */
final class CommentMetadata
{
    private array $symbols = [];
    private array $duplicates = [];
    private array $relations = [];
    private array $references = [];
    private array $calls = [];
    private readonly array $markers;

    public function __construct(?array $markers = null) { $this->markers = self::validateMarkers($markers); }

    public static function validateMarkers(?array $markers): array
    {
        $markers ??= [
            ['marker' => '[DEV COMMENT]', 'severity' => 'info'],
            ['marker' => '@deprecated', 'severity' => 'warning'],
            ['marker' => '@todo', 'severity' => 'info'],
            ['marker' => '@see', 'severity' => 'reference'],
        ];
        if (!array_is_list($markers) || count($markers) > 32) throw new \InvalidArgumentException('Comment markers must be a list of at most 32 entries.');
        $seen = [];
        foreach ($markers as $rule) {
            if (!is_array($rule) || !is_string($rule['marker'] ?? null) || $rule['marker'] === '' || strlen($rule['marker']) > 128
                || preg_match('//u', $rule['marker']) !== 1 || preg_match('/[\x00-\x1f\x7f]/', $rule['marker']) || !in_array($rule['severity'] ?? null, ['info', 'warning', 'error', 'reference'], true)) {
                throw new \InvalidArgumentException('Invalid comment marker or severity.');
            }
            if (isset($seen[$rule['marker']])) throw new \InvalidArgumentException('Duplicate comment marker.');
            $seen[$rule['marker']] = true;
        }
        return $markers;
    }

    public function index(array $statements, string $path, string $contents): void
    {
        if ($this->markers === []) return;
        $finder = new NodeFinder();
        foreach ($finder->findInstanceOf($statements, Node\Stmt\ClassLike::class) as $class) {
            if (!$class->namespacedName instanceof Node\Name || $class->name === null) continue;
            $symbol = $class->namespacedName->toString();
            $kind = $class instanceof Node\Stmt\Interface_ ? 'interface' : ($class instanceof Node\Stmt\Trait_ ? 'trait' : ($class instanceof Node\Stmt\Enum_ ? 'enum' : 'class'));
            $this->declaration($symbol, $kind, $class, $class->name, $path, $contents);
            foreach ($class->getMethods() as $method) $this->declaration($symbol . '::' . $method->name->toString(), 'method', $method, $method->name, $path, $contents);
            $parents = $class instanceof Node\Stmt\Class_ ? [...($class->extends ? [$class->extends] : []), ...$class->implements]
                : ($class instanceof Node\Stmt\Interface_ ? $class->extends : ($class instanceof Node\Stmt\Enum_ ? $class->implements : []));
            $this->relations[strtolower($symbol)] = array_map(static fn (Node\Name $name): string => $name->toString(), $parents);
        }
        $walk = function (Node $node, ?Node $parent = null, ?string $field = null) use (&$walk, $path, $contents): void {
            if ($node instanceof Node\Stmt\Use_ || $node instanceof Node\Stmt\GroupUse) return;
            $typeReference = $parent instanceof Node\Stmt\ClassLike || $parent instanceof Node\Param || $parent instanceof Node\Stmt\Property
                || $parent instanceof Node\NullableType || $parent instanceof Node\UnionType || $parent instanceof Node\IntersectionType
                || $parent instanceof Node\Stmt\Catch_ || $parent instanceof Node\Expr\New_ || $parent instanceof Node\Expr\Instanceof_
                || $parent instanceof Node\Expr\StaticCall || $parent instanceof Node\Expr\StaticPropertyFetch || $parent instanceof Node\Expr\ClassConstFetch
                || ($field === 'returnType' && ($parent instanceof Node\Stmt\ClassMethod || $parent instanceof Node\Stmt\Function_ || $parent instanceof Node\Expr\Closure || $parent instanceof Node\Expr\ArrowFunction));
            if ($node instanceof Node\Name && $typeReference) {
                $target = $node->toString();
                if (!in_array(strtolower($target), ['self', 'static', 'parent'], true)) {
                    $kind = $field === 'extends' ? 'extends' : ($field === 'implements' ? 'implements' : ($parent instanceof Node\Expr\New_ ? 'new' : 'type'));
                    $this->references[] = ['target_symbol' => $target, 'kind' => $kind, ...self::range($node, $path, $contents)];
                }
            }
            if (($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\StaticCall || $node instanceof Node\Expr\NullsafeMethodCall) && $node->name instanceof Node\Identifier) {
                $range = self::range($node, $path, $contents);
                $this->calls[$path . ':' . $range['line'] . ':' . $range['column']] = self::range($node->name, $path, $contents);
            }
            foreach ($node->getSubNodeNames() as $name) {
                $value = $node->$name;
                if ($value instanceof Node) $walk($value, $node, $name);
                elseif (is_array($value)) foreach ($value as $child) if ($child instanceof Node) $walk($child, $node, $name);
            }
        };
        foreach ($statements as $statement) $walk($statement);
        if (count($this->symbols) > 50000 || count($this->references) > 250000 || count($this->calls) > 250000) throw new \RuntimeException('Comment metadata exceeds source work limit.');
    }

    private function declaration(string $symbol, string $kind, Node $node, Node $name, string $path, string $contents): void
    {
        $key = strtolower($symbol);
        if (isset($this->symbols[$key]) || isset($this->duplicates[$key])) {
            unset($this->symbols[$key]);
            $this->duplicates[$key] = true;
            return;
        }
        $annotations = [];
        $groups = [];
        foreach ($node->getComments() as $comment) {
            $last = array_key_last($groups);
            if ($last !== null && $groups[$last]['end_line'] + 1 === $comment->getStartLine() && str_starts_with($comment->getText(), '//') && str_starts_with($groups[$last]['raw'], '//')) {
                $groups[$last]['raw'] = substr($contents, $groups[$last]['position'], $comment->getEndFilePos() - $groups[$last]['position'] + 1);
                $groups[$last]['end_line'] = $comment->getEndLine();
            } else $groups[] = ['raw' => $comment->getText(), 'line' => $comment->getStartLine(), 'position' => $comment->getStartFilePos(), 'end_line' => $comment->getEndLine()];
        }
        foreach ($groups as $group) {
            $raw = $group['raw'];
            if (strlen($raw) > 65536 || preg_match('//u', $raw) !== 1) continue;
            $parts = preg_split('/\R/', $raw, -1, PREG_SPLIT_OFFSET_CAPTURE);
            $offsets = array_column($parts, 1);
            $lines = array_column($parts, 0);
            foreach ($lines as $index => $line) {
                $clean = self::cleanLine($line);
                foreach ($this->markers as $rule) {
                    $at = strpos($clean, $rule['marker']);
                    if ($at === false || ($at > 0 && !ctype_space($clean[$at - 1]))) continue;
                    $tail = substr($clean, $at + strlen($rule['marker']));
                    if (str_starts_with($rule['marker'], '@') && $tail !== '' && !ctype_space($tail[0])) continue;
                    $message = trim($tail);
                    for ($next = $index + 1; $next < count($lines); $next++) {
                        $continuation = self::cleanLine($lines[$next]);
                        if ($continuation === '' || str_starts_with($continuation, '@')) break;
                        foreach ($this->markers as $other) if (str_contains($continuation, $other['marker'])) break 2;
                        $message .= ($message === '' ? '' : "\n") . $continuation;
                    }
                    if (strlen($message) > 4096) {
                        $message = substr($message, 0, 4096);
                        while ($message !== '' && preg_match('//u', $message) !== 1) $message = substr($message, 0, -1);
                    }
                    $position = $group['position'] + $offsets[$index];
                    $position += strpos($line, $rule['marker']);
                    $annotations[] = ['marker' => $rule['marker'], 'severity' => $rule['severity'], 'message' => $message, 'path' => $path, 'line' => $group['line'] + $index, 'column' => self::column($contents, $position)];
                    if (count($annotations) >= 64) break 3;
                }
            }
        }
        $this->symbols[$key] = ['symbol' => $symbol, 'kind' => $kind, ...self::range($name, $path, $contents), 'annotations' => $annotations];
    }

    private static function cleanLine(string $line): string
    {
        return trim(preg_replace('~^\s*(?://|/\*\*?|\*)\s?~', '', preg_replace('~\*/\s*$~', '', $line)));
    }

    private static function column(string $contents, int $position): int
    {
        $prefix = substr($contents, 0, max(0, $position));
        $newline = strrpos($prefix, "\n");
        return strlen($prefix) - ($newline === false ? -1 : $newline);
    }

    private static function range(Node $node, string $path, string $contents): array
    {
        return ['path' => $path, 'line' => $node->getStartLine(), 'column' => self::column($contents, $node->getStartFilePos()), 'end_line' => $node->getEndLine(), 'end_column' => self::column($contents, $node->getEndFilePos() + 1)];
    }

    public function export(array $nodes, array $edges): array
    {
        $sites = [];
        $append = function (array $location, string $symbol, string $target, string $kind) use (&$sites): void {
            $annotations = $this->symbols[strtolower($target)]['annotations'] ?? [];
            if ($annotations === []) return;
            $site = ['symbol' => $symbol, 'target_symbol' => $target, 'kind' => $kind, ...$location, 'annotations' => $annotations];
            $key = json_encode([$location, strtolower($target), $kind], JSON_THROW_ON_ERROR);
            $sites[$key] = $site;
            if (count($sites) > 250000) throw new \RuntimeException('Comment sites exceed 250000 entries.');
        };
        foreach ($this->symbols as $declaration) {
            $location = array_intersect_key($declaration, array_flip(['path', 'line', 'column', 'end_line', 'end_column']));
            $append($location, $declaration['symbol'], $declaration['symbol'], 'declaration');
            // Only class/interface relationships transfer interface metadata; unrelated callers do not.
            $class = explode('::', $declaration['symbol'], 2)[0];
            $suffix = strstr($declaration['symbol'], '::');
            $pending = $this->relations[strtolower($class)] ?? [];
            $seen = [];
            while ($pending !== []) {
                $parent = array_pop($pending);
                $key = strtolower($parent);
                if (isset($seen[$key])) continue;
                $seen[$key] = true;
                if (count($seen) > 1024) throw new \RuntimeException('Comment inheritance exceeds 1024 ancestors.');
                if (($this->symbols[$key]['kind'] ?? null) === 'interface') $append($location, $declaration['symbol'], $parent . ($suffix ?: ''), 'implementation');
                foreach ($this->relations[$key] ?? [] as $ancestor) $pending[] = $ancestor;
            }
        }
        foreach ($this->references as $reference) {
            $target = $reference['target_symbol'];
            $kind = $reference['kind'];
            unset($reference['target_symbol'], $reference['kind']);
            $append($reference, $target, $target, $kind);
        }
        $byId = array_column($nodes, 'symbol', 'id');
        foreach ($edges as $edge) {
            $range = $this->calls[$edge['path'] . ':' . $edge['line'] . ':' . $edge['column']] ?? null;
            if ($range === null) continue; // Never guess a symbol range for synthetic callback edges.
            $target = $byId[$edge['to']] ?? null;
            if ($target === null) continue;
            $append($range, $byId[$edge['from']] ?? $target, $target, 'call');
            [$class, $method] = explode('::', $target, 2) + [1 => null];
            if ($method !== null) {
                $pending = $this->relations[strtolower($class)] ?? [];
                $seen = [];
                while ($pending !== []) {
                    $parent = array_pop($pending); $key = strtolower($parent);
                    if (isset($seen[$key])) continue;
                    $seen[$key] = true;
                    if (count($seen) > 1024) throw new \RuntimeException('Comment inheritance exceeds 1024 ancestors.');
                    if (($this->symbols[$key]['kind'] ?? null) === 'interface') $append($range, $byId[$edge['from']] ?? $target, $parent . '::' . $method, 'call');
                    foreach ($this->relations[$key] ?? [] as $ancestor) $pending[] = $ancestor;
                }
            }
        }
        $metadata = array_values(array_filter($this->symbols, static fn (array $declaration): bool => $declaration['annotations'] !== []));
        usort($metadata, static fn ($a, $b) => [$a['path'], $a['line'], $a['column']] <=> [$b['path'], $b['line'], $b['column']]);
        $sites = array_values($sites);
        usort($sites, static fn ($a, $b) => [$a['path'], $a['line'], $a['column'], $a['target_symbol']] <=> [$b['path'], $b['line'], $b['column'], $b['target_symbol']]);
        return ['comment_column_encoding' => 'utf8_bytes', 'symbol_metadata' => $metadata, 'annotation_sites' => $sites];
    }
}
