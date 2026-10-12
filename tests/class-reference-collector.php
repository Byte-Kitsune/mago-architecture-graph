<?php

declare(strict_types=1);

use ByteKitsune\MagoArchitectureGraph\Analyzer\ClassReferenceCollector;
require __DIR__ . '/source-autoload.php';
$source = <<<'SOURCE'
<?php
namespace Sample\Sketch;
use Sample\Shapes\{OnlyImport, function run, const Marker};
use Sample\Cells\Cedar\Api\Value\{Record as R, Contract as C, function trace, const LIMIT};
use Sample\Cells\Cedar\Api as PublicApi;
#[\Sample\Metadata\Tag]
class Draft extends \Sample\Base\ParentNode implements C {
    use \Sample\Traits\First, \Sample\Traits\Second {
        \Sample\Traits\First::walk insteadof \Sample\Traits\Second;
        \Sample\Traits\Second::walk as mirror;
    }
    public PublicApi\Value\Token|R|null $value;
    public static R $cache;
    public function build(C $input): R {
        $copy = new PublicApi\Value\Envelope();
        R::make(); R::class; R::$cache;
        $copy instanceof C;
        try {} catch (\Sample\Cells\Cedar\Api\Value\Problem $problem) {}
        trace(); LIMIT; 'Sample\Imaginary\Type';
        return new R();
    }
    public function nullable(?PublicApi\Value\Token $token): C&R { throw new \RuntimeException(); }
}
interface Marker extends C {}
enum Flag implements C { case Ready; }
namespace Sample\Elsewhere;
use Sample\Cells\Indigo\Api\Value\Record as R;
function wrap(R $input): R { return $input; }
SOURCE;
$references = ClassReferenceCollector::collect($source);
$names = array_column($references, 'name');
foreach (['Sample\\Shapes\\OnlyImport', 'Sample\\Cells\\Cedar\\Api\\Value\\Record', 'Sample\\Cells\\Cedar\\Api\\Value\\Contract', 'Sample\\Cells\\Cedar\\Api\\Value\\Token', 'Sample\\Cells\\Cedar\\Api\\Value\\Envelope', 'Sample\\Cells\\Cedar\\Api\\Value\\Problem', 'Sample\\Metadata\\Tag', 'Sample\\Base\\ParentNode', 'Sample\\Traits\\First', 'Sample\\Traits\\Second', 'Sample\\Cells\\Indigo\\Api\\Value\\Record'] as $name) {
    if (!in_array($name, $names, true)) throw new RuntimeException('Unresolved class reference: ' . $name);
}
foreach ($references as $reference) {
    if (str_ends_with($reference['name'], '\\trace') || str_ends_with($reference['name'], '\\LIMIT') || $reference['name'] === 'Sample\\Imaginary\\Type') throw new RuntimeException('Function, constant or string was treated as a class.');
    if ($reference['start'] < 0 || $reference['end'] > strlen($source) || $reference['start'] >= $reference['end']) throw new RuntimeException('Invalid byte span.');
    if (str_contains($reference['name'], '\\Indigo\\') && $reference['namespace'] !== 'Sample\\Elsewhere') throw new RuntimeException('Namespace context leaked between declarations.');
}
try { ClassReferenceCollector::collect('<?php final class Broken {'); throw new RuntimeException('Malformed class references were accepted.'); } catch (\PhpParser\Error) {}
try { ClassReferenceCollector::collect(str_repeat(' ', 8 * 1024 * 1024 + 1)); throw new RuntimeException('Oversized source was accepted.'); } catch (LengthException) {}
echo "Literal PHP class reference resolution, contexts and bounds passed\n";
