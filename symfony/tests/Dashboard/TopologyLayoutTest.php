<?php

namespace App\Tests\Dashboard;

use App\Dashboard\TopologyLayout;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

class TopologyLayoutTest extends TestCase
{
    /** A node shaped like UnifiTopologyBuilder's, reduced to what the layout reads. */
    private function n(string $id, string $kind, ?string $parent, ?string $name = null, bool $onMap = true): array
    {
        return ['id' => $id, 'kind' => $kind, 'name' => $name ?? $id, 'parentId' => $parent, 'onMap' => $onMap];
    }

    private function e(string $from, string $to): array
    {
        return ['from' => $from, 'to' => $to];
    }

    /** One edge per onMap non-root node, parent → node — the builder's contract. */
    private function edgesOf(array $nodes): array
    {
        $edges = [];
        foreach ($nodes as $n) {
            if ($n['onMap'] && $n['parentId'] !== null) $edges[] = $this->e($n['parentId'], $n['id']);
        }
        return $edges;
    }

    private function build(array $nodes): ?array
    {
        return TopologyLayout::build($nodes, $this->edgesOf($nodes));
    }

    public function testEmptyReturnsNull(): void
    {
        $this->assertNull(TopologyLayout::build([], []));
        // Wi-Fi clients are never laid out, so a map of only those is empty.
        $this->assertNull($this->build([$this->n('c1', 'wifi', 'ap', onMap: false)]));
    }

    public function testColumnsFollowDepth(): void
    {
        $l = $this->build([
            $this->n('internet', 'internet', null),
            $this->n('gw', 'gateway', 'internet'),
            $this->n('sw1', 'switch', 'gw'),
            $this->n('sw2', 'switch', 'sw1'),
        ]);

        $this->assertSame(12.0, $l['boxes']['internet']['x']);
        $this->assertSame(12.0 + 210.0, $l['boxes']['gw']['x']);
        $this->assertSame(222.0, $l['boxes']['gw']['x']);
        $this->assertSame(12.0 + 2 * 210.0, $l['boxes']['sw1']['x']);
        // A switch behind a switch (the owner's USWED35 → USWED37).
        $this->assertSame(12.0 + 3 * 210.0, $l['boxes']['sw2']['x']);
        $this->assertSame(160.0, $l['boxes']['sw2']['w']);
        $this->assertSame(36.0, $l['boxes']['sw2']['h']);
    }

    public function testParentCentredOnChildren(): void
    {
        // gw → [ S → [w1, w2], w3 ]  ⇒  w1 row 0, w2 row 1, w3 row 2.
        $l = $this->build([
            $this->n('internet', 'internet', null),
            $this->n('gw', 'gateway', 'internet'),
            $this->n('s', 'switch', 'gw', 'Core'),
            $this->n('w1', 'wired', 's', 'a'),
            $this->n('w2', 'wired', 's', 'b'),
            $this->n('w3', 'wired', 'gw', 'c'),
        ]);

        $this->assertSame(12.0, $l['boxes']['w1']['y']);
        $this->assertSame(58.0, $l['boxes']['w2']['y']);
        $this->assertSame(104.0, $l['boxes']['w3']['y']);
        // S sits midway between its first and last child (12, 58).
        $this->assertSame(35.0, $l['boxes']['s']['y']);
        // The gateway is midway between its first and last child (S 35, w3 104); the
        // internet follows its only child.
        $this->assertSame(69.5, $l['boxes']['gw']['y']);
        $this->assertSame(69.5, $l['boxes']['internet']['y']);
    }

    public function testChildrenAreOrderedInfrastructureFirstThenWiredEachByName(): void
    {
        $l = $this->build([
            $this->n('internet', 'internet', null),
            $this->n('gw', 'gateway', 'internet'),
            $this->n('w-a', 'wired', 'gw', 'alpha'),
            $this->n('ap1', 'ap', 'gw', 'Zed AP'),
            $this->n('sw', 'switch', 'gw', 'beta switch'),
            $this->n('w-b', 'wired', 'gw', 'Bravo'),   // 'B' sorts before 'a' by byte: must be case-insensitive
            $this->n('ap0', 'ap', 'gw', 'attic AP'),
            $this->n('w-c2', 'wired', 'gw', 'charlie'),
            $this->n('w-c1', 'wired', 'gw', 'Charlie'), // a tie on name: broken by id
        ]);

        $order = ['sw', 'ap0', 'ap1', 'w-a', 'w-b', 'w-c1', 'w-c2'];
        foreach ($order as $row => $id) {
            $this->assertSame(12.0 + $row * 46.0, $l['boxes'][$id]['y'], "$id should take row $row");
        }
    }

    public function testWifiNodesAreNotLaidOut(): void
    {
        $nodes = [
            $this->n('internet', 'internet', null),
            $this->n('gw', 'gateway', 'internet'),
            $this->n('ap', 'ap', 'gw', 'Lounge'),
            $this->n('c1', 'wifi', 'ap', 'phone', onMap: false),
            $this->n('c2', 'wifi', 'ap', 'tablet', onMap: false),
            $this->n('c3', 'wifi', 'gw', 'laptop', onMap: false), // a gateway can host Wi-Fi too
        ];
        $l = $this->build($nodes);

        $this->assertSame(['internet', 'gw', 'ap'], array_keys($l['boxes']));
        // The AP has no on-map children, so it is one leaf: one row, however many clients.
        $this->assertSame(12.0 * 2 + 46.0, $l['height']);
        $this->assertSame(12.0, $l['boxes']['ap']['y']);
        $this->assertCount(2, $l['paths']); // internet→gw, gw→ap — nothing for Wi-Fi
    }

    public function testEveryEdgeBetweenLaidOutNodesGetsAPath(): void
    {
        $nodes = [
            $this->n('internet', 'internet', null),
            $this->n('gw', 'gateway', 'internet'),
            $this->n('sw', 'switch', 'gw'),
            $this->n('w1', 'wired', 'sw', 'a'),
            $this->n('w2', 'wired', 'sw', 'b'),
        ];
        $l = $this->build($nodes);

        $this->assertCount(4, $l['paths']);
        foreach ($l['paths'] as $p) {
            $this->assertStringStartsWith('M', $p['d']);
            $this->assertStringContainsString('C', $p['d']);
        }
        $this->assertSame(
            [['internet', 'gw'], ['gw', 'sw'], ['sw', 'w1'], ['sw', 'w2']],
            array_map(static fn(array $p): array => [$p['from'], $p['to']], $l['paths']),
        );
    }

    public function testPathIsACubicFromParentRightEdgeToChildLeftEdge(): void
    {
        $l = $this->build([
            $this->n('internet', 'internet', null),
            $this->n('gw', 'gateway', 'internet'),
            $this->n('w1', 'wired', 'gw', 'a'),
            $this->n('w2', 'wired', 'gw', 'b'),
        ]);

        // internet (12,35) and gw (222,35) are level: a straight-looking cubic.
        // Parent right edge 12+160=172, centre y 35+18=53; child left edge 222; mx 197.
        $this->assertSame('M172 53 C197 53, 197 53, 222 53', $l['paths'][0]['d']);
        // gw (222,35) → w1 (432,12): starts at (382, 53), ends at (432, 30).
        $this->assertSame('M382 53 C407 53, 407 30, 432 30', $l['paths'][1]['d']);
        $this->assertSame('M382 53 C407 53, 407 76, 432 76', $l['paths'][2]['d']);
    }

    public function testEdgesToUnplacedNodesGetNoPath(): void
    {
        $nodes = [
            $this->n('internet', 'internet', null),
            $this->n('gw', 'gateway', 'internet'),
        ];
        $l = TopologyLayout::build($nodes, [
            $this->e('internet', 'gw'),
            $this->e('gw', 'ghost'),
            $this->e('ghost', 'gw'),
        ]);

        $this->assertCount(1, $l['paths']);
    }

    public function testCanvasSizeFollowsDeepestColumnAndLeafCount(): void
    {
        $l = $this->build([
            $this->n('internet', 'internet', null),
            $this->n('gw', 'gateway', 'internet'),
            $this->n('w1', 'wired', 'gw', 'a'),
            $this->n('w2', 'wired', 'gw', 'b'),
            $this->n('w3', 'wired', 'gw', 'c'),
        ]);

        // 24 + 2·210 + 160  /  24 + 3·46
        $this->assertSame(604.0, $l['width']);
        $this->assertSame(162.0, $l['height']);
    }

    public function testASingleNodeIsOneRowOneColumn(): void
    {
        $l = $this->build([$this->n('internet', 'internet', null)]);

        $this->assertSame(184.0, $l['width']);
        $this->assertSame(70.0, $l['height']);
        $this->assertSame([], $l['paths']);
    }

    public function testAllDigitIdsStayUsableAsBoxKeysAndPathEndpoints(): void
    {
        // A real Ubiquiti OUI: PHP turns an all-digit array KEY into an int.
        $id = '784558000004';
        $l = $this->build([
            $this->n('internet', 'internet', null),
            $this->n('gw', 'gateway', 'internet'),
            $this->n($id, 'wired', 'gw', 'nas'),
        ]);

        // Lookups by the string id work (PHP casts the key the same way both ways)…
        $this->assertTrue(isset($l['boxes'][$id]));
        $this->assertSame(432.0, $l['boxes'][$id]['x']);
        // …while ids themselves are always read from values, which stay strings.
        $this->assertSame($id, $l['paths'][1]['to']);
        $this->assertSame('gw', $l['paths'][1]['from']);
        $this->assertSame($l['boxes'][$l['paths'][1]['to']], $l['boxes'][$id]);

        // And the template-side lookup the map fragment will use.
        $twig = new Environment(new ArrayLoader([
            't' => '{% for p in paths %}{{ boxes[p.to].x }};{% endfor %}',
        ]));
        $this->assertSame('222;432;', $twig->render('t', $l));
    }

    public function testDuplicateIdsAreDrawnOnce(): void
    {
        $l = $this->build([
            $this->n('internet', 'internet', null),
            $this->n('gw', 'gateway', 'internet'),
            $this->n('w1', 'wired', 'gw', 'first'),
            $this->n('w1', 'wired', 'gw', 'second'),
        ]);

        // First wins: one box and one row, not two boxes stacked on the same id.
        $this->assertCount(3, $l['boxes']);
        $this->assertSame(70.0, $l['height']);
    }

    public function testNodesCaughtInAParentLoopAreDroppedNotLooped(): void
    {
        // The builder re-hangs loops off the gateway, so this cannot reach the
        // layout in practice — but a hang here would stall the whole poll.
        $l = $this->build([
            $this->n('internet', 'internet', null),
            $this->n('gw', 'gateway', 'internet'),
            $this->n('a', 'switch', 'b'),
            $this->n('b', 'switch', 'a'),
        ]);

        $this->assertSame(['internet', 'gw'], array_keys($l['boxes']));
    }

    public function testAnOrphanedNodeIsStillDrawnAsItsOwnRoot(): void
    {
        $l = $this->build([
            $this->n('internet', 'internet', null),
            $this->n('gw', 'gateway', 'internet'),
            $this->n('lost', 'wired', 'nowhere', 'lost'),
        ]);

        $this->assertSame(['internet', 'gw', 'lost'], array_keys($l['boxes']));
        $this->assertSame(12.0, $l['boxes']['lost']['x']);
        $this->assertSame(58.0, $l['boxes']['lost']['y']);
    }
}
