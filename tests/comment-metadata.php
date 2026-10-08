<?php

declare(strict_types=1);

require __DIR__ . '/source-autoload.php';

use ByteKitsune\MagoArchitectureGraph\Analyzer\CommentMetadata;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

function check(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
$source = <<<'CODE'
<?php
namespace Demo;
/**
 * [DEV COMMENT] Contract warning
 * More context
 *
 * @see OtherClass
 * @deprecated Prefer V2
 */
interface Port {
    /** @todo Handle batching
     * safely
     * @return void
     */
    public function run(): void;
}
class Adapter implements Port {
    public function run(): void {}
    // [DEV COMMENT] Own note
    // with continuation
    public function execute(): void { $this->run(); }
}
class Caller {
    public function call(Adapter $adapter): void { $adapter->run(); new Adapter(); }
}
// [DEV COMMENT] Unrelated
class Separate {}
CODE;
$parse = static fn (string $source): array => (new NodeTraverser(new NameResolver()))->traverse((new ParserFactory())->createForNewestSupportedVersion()->parse($source));
$collector = new CommentMetadata();
$collector->index($parse($source), 'src/Example.php', $source);
$line = 24;
$callColumn = strpos(explode("\n", $source)[$line - 1], '$adapter->run') + 1;
$graph = $collector->export([
    ['id' => 'caller', 'symbol' => 'Demo\\Caller::call'], ['id' => 'target', 'symbol' => 'Demo\\Adapter::run'],
], [['from' => 'caller', 'to' => 'target', 'path' => 'src/Example.php', 'line' => $line, 'column' => $callColumn]]);
$metadata = array_column($graph['symbol_metadata'], null, 'symbol');
check($metadata['Demo\\Port']['annotations'][0]['message'] === "Contract warning\nMore context", 'Multiline block boundary lost.');
check(count($metadata['Demo\\Port']['annotations']) === 3, 'Default tags missing.');
check($metadata['Demo\\Port::run']['annotations'][0]['message'] === "Handle batching\nsafely", 'Next tag was swallowed.');
check($metadata['Demo\\Adapter::execute']['annotations'][0]['message'] === "Own note\nwith continuation", 'Consecutive line comments lost.');
$implementations = array_values(array_filter($graph['annotation_sites'], static fn ($site) => $site['kind'] === 'implementation'));
check(count($implementations) === 2, 'Interface metadata must target class and matching method separately.');
check($implementations[0]['symbol'] === 'Demo\\Adapter' && $implementations[0]['target_symbol'] === 'Demo\\Port', 'Interface class warning target wrong.');
check($implementations[1]['symbol'] === 'Demo\\Adapter::run' && $implementations[1]['target_symbol'] === 'Demo\\Port::run', 'Interface method warning target wrong.');
$calls = array_values(array_filter($graph['annotation_sites'], static fn ($site) => $site['kind'] === 'call'));
check(count($calls) === 1 && $calls[0]['target_symbol'] === 'Demo\\Port::run', 'Resolved interface implementation call metadata missing.');
check(substr(explode("\n", $source)[$line - 1], $calls[0]['column'] - 1, $calls[0]['end_column'] - $calls[0]['column']) === 'run', 'Call range is not exact method name.');
$disabled = new CommentMetadata([]); $disabled->index($parse($source), 'src/Example.php', $source);
check($disabled->export([], [])['annotation_sites'] === [], 'Explicit disable ignored.');
$custom = new CommentMetadata([['marker' => '[DEV COMMENT]', 'severity' => 'error']]); $custom->index($parse($source), 'src/Example.php', $source);
check($custom->export([], [])['symbol_metadata'][0]['annotations'][0]['severity'] === 'error', 'Custom severity ignored.');
try { new CommentMetadata([['marker' => '', 'severity' => 'warning']]); throw new RuntimeException('Invalid marker accepted.'); } catch (InvalidArgumentException) {}
$duplicate = new CommentMetadata(); $duplicate->index($parse($source), 'src/Example.php', $source); $duplicate->index($parse($source), 'src/Duplicate.php', $source);
check($duplicate->export([], [])['annotation_sites'] === [], 'Ambiguous declarations leaked annotations.');
$unicodeSource = "<?php\r\n/** [DEV COMMENT] Grüße\r\n * Zweite Zeile\r\n */\r\nclass Note {}\r\nfunction call(): void { Note(); new Note(); }\r\n";
$unicode = new CommentMetadata(); $unicode->index($parse($unicodeSource), 'src/Unicode.php', $unicodeSource);
$unicodeGraph = $unicode->export([], []);
check($unicodeGraph['symbol_metadata'][0]['annotations'][0]['message'] === "Grüße\nZweite Zeile", 'CRLF or Unicode comment damaged: ' . json_encode($unicodeGraph['symbol_metadata'][0]['annotations']));
$references = array_values(array_filter($unicodeGraph['annotation_sites'], static fn ($site) => $site['kind'] !== 'declaration'));
check(count($references) === 1 && $references[0]['kind'] === 'new', 'Function name was mistaken for a class use.');
echo "Comment metadata parsing, implementation placement, calls and configuration passed\n";
