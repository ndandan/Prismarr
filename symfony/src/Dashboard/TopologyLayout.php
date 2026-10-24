<?php
namespace App\Dashboard;

/**
 * Pure geometry helper for the /unifi topology map: UnifiTopologyBuilder's
 * nodes + edges in, SVG-ready boxes and curve paths out, so the Twig fragment
 * only prints numbers. No I/O, no clock, no state.
 *
 * Layout is a left-to-right tree. Column = depth below the internet
 * (x = PAD + depth·COL_W), rows are handed out to LEAVES in DFS order
 * (y = PAD + row·ROW_H), and every parent sits midway between its first and
 * last child. A subtree therefore never overlaps its neighbour, however deep
 * a switch-behind-a-switch chain goes — depth only costs width.
 *
 *   internet ─ gateway ─┬─ switch ─┬─ NAS
 *                       │          └─ desktop
 *                       ├─ AP
 *                       └─ printer
 *
 * Only on-map nodes are laid out. Wi-Fi clients (onMap = false) never get a
 * box: an AP is one leaf however many clients it serves.
 *
 * Tree order is derived here from `parentId`, not from the order of `$nodes`
 * (internet, gateway, devices, clients — a listing, not a layout). Siblings
 * go infrastructure first (switch, then AP), then wired clients, each group
 * by case-insensitive name; a tie falls back to the id so the order — and so
 * the picture — never reshuffles between polls.
 *
 * Edges are drawn as a cubic from the parent's right-edge midpoint to the
 * child's left-edge midpoint, both control points at the horizontal halfway
 * line so the curve leaves and arrives level.
 *
 * Box ids: PHP turns an all-digit array KEY ('784558000004' — a real Ubiquiti
 * OUI) into an int. `boxes` is keyed by id, so such a key IS an int; looking
 * it up with the string id still works (PHP casts it the same way — as does
 * Twig's `boxes[id]`), but never read an id out of `array_keys($boxes)`: take
 * it from a path's `from`/`to` (or the node), whose values stay strings.
 *
 * A node whose parent is missing from the on-map set is drawn as its own root
 * rather than dropped; nodes caught in a parent loop are unreachable from any
 * root and are not drawn (the builder never produces either).
 */
final class TopologyLayout
{
    public const COL_W  = 210.0; // column pitch: NODE_W plus a 50 px gutter for the curves
    public const ROW_H  = 46.0;  // row pitch: NODE_H plus a 10 px gap
    public const NODE_W = 160.0;
    public const NODE_H = 36.0;
    public const PAD    = 12.0;

    /** Sibling order: infrastructure before wired endpoints, anything unknown last. */
    private const KIND_RANK = ['internet' => 0, 'gateway' => 0, 'switch' => 0, 'ap' => 1, 'wired' => 2];
    private const OTHER_RANK = 3;

    /**
     * @param list<array<string, mixed>> $nodes UnifiTopologyBuilder::build()['nodes']
     * @param list<array<string, mixed>> $edges UnifiTopologyBuilder::build()['edges']
     * @return ?array{width: float, height: float,
     *                boxes: array<string, array{x: float, y: float, w: float, h: float}>,
     *                paths: list<array{from: string, to: string, d: string}>}
     */
    public static function build(array $nodes, array $edges): ?array
    {
        // On-map nodes by id; first one wins, so a repeated id is drawn once.
        $byId = [];
        foreach ($nodes as $n) {
            if (($n['onMap'] ?? true) === false) continue;
            $id = isset($n['id']) ? (string) $n['id'] : '';
            if ($id === '' || isset($byId[$id])) continue;
            $byId[$id] = [
                'id'     => $id,
                'kind'   => (string) ($n['kind'] ?? ''),
                'name'   => mb_strtolower((string) ($n['name'] ?? '')),
                'parent' => isset($n['parentId']) ? (string) $n['parentId'] : null,
            ];
        }
        if ($byId === []) return null;

        // strcmp, not <=>: two numeric-looking strings ('10', '9', or ids like
        // '0123' / '123') would compare as numbers and could tie unpredictably.
        $cmp = static function (string $a, string $b) use ($byId): int {
            $x = $byId[$a];
            $y = $byId[$b];
            return (self::KIND_RANK[$x['kind']] ?? self::OTHER_RANK) <=> (self::KIND_RANK[$y['kind']] ?? self::OTHER_RANK)
                ?: strcmp($x['name'], $y['name'])
                ?: strcmp($x['id'], $y['id']);
        };

        /** @var list<string> $roots */
        $roots = [];
        /** @var array<string, list<string>> $children parent id → child ids, sorted below */
        $children = [];
        foreach ($byId as $n) {
            $p = $n['parent'];
            if ($p === null || $p === $n['id'] || !isset($byId[$p])) {
                $roots[] = $n['id'];
            } else {
                $children[$p][] = $n['id'];
            }
        }
        usort($roots, $cmp);
        foreach ($children as &$kids) {
            usort($kids, $cmp);
        }
        unset($kids);

        $s = ['row' => 0, 'maxDepth' => 0, 'order' => [], 'depth' => [], 'y' => []];
        foreach ($roots as $root) {
            self::place($root, 0, $children, $s);
        }

        $boxes = [];
        foreach ($s['order'] as $id) {
            $boxes[$id] = [
                'x' => round(self::PAD + $s['depth'][$id] * self::COL_W, 1),
                'y' => round($s['y'][$id], 1),
                'w' => self::NODE_W,
                'h' => self::NODE_H,
            ];
        }

        $paths = [];
        foreach ($edges as $e) {
            if (!isset($e['from'], $e['to'])) continue;
            $from = (string) $e['from'];
            $to   = (string) $e['to'];
            if (!isset($boxes[$from], $boxes[$to])) continue;
            $paths[] = ['from' => $from, 'to' => $to, 'd' => self::curve($boxes[$from], $boxes[$to])];
        }

        return [
            'width'  => round(self::PAD * 2 + $s['maxDepth'] * self::COL_W + self::NODE_W, 1),
            'height' => round(self::PAD * 2 + $s['row'] * self::ROW_H, 1),
            'boxes'  => $boxes,
            'paths'  => $paths,
        ];
    }

    /**
     * DFS. A node with no on-map children is a leaf and takes the next row; a
     * parent takes the midpoint of its first and last child. `order` records
     * pre-order, which is the order boxes (and so SVG elements) come out in.
     * Recursion depth is the tree depth — a handful of switches at most.
     *
     * @param array<string, list<string>> $children
     * @param array{row: int, maxDepth: int, order: list<string>, depth: array<string, int>, y: array<string, float>} $s
     */
    private static function place(string $id, int $depth, array $children, array &$s): float
    {
        $s['maxDepth'] = max($s['maxDepth'], $depth);
        $s['order'][]  = $id;
        $s['depth'][$id] = $depth;

        $kids = $children[$id] ?? [];
        if ($kids === []) {
            $y = self::PAD + $s['row']++ * self::ROW_H;
        } else {
            $first = $last = 0.0;
            foreach ($kids as $i => $kid) {
                $ky = self::place($kid, $depth + 1, $children, $s);
                if ($i === 0) $first = $ky;
                $last = $ky;
            }
            $y = ($first + $last) / 2;
        }
        return $s['y'][$id] = $y;
    }

    /**
     * @param array{x: float, y: float, w: float, h: float} $from
     * @param array{x: float, y: float, w: float, h: float} $to
     */
    private static function curve(array $from, array $to): string
    {
        $x1 = $from['x'] + $from['w'];
        $y1 = $from['y'] + $from['h'] / 2;
        $x2 = $to['x'];
        $y2 = $to['y'] + $to['h'] / 2;
        $mx = ($x1 + $x2) / 2;

        return 'M' . self::n($x1) . ' ' . self::n($y1)
            . ' C' . self::n($mx) . ' ' . self::n($y1)
            . ', ' . self::n($mx) . ' ' . self::n($y2)
            . ', ' . self::n($x2) . ' ' . self::n($y2);
    }

    /** 172.0 → '172', 197.5 → '197.5': one decimal at most, trailing .0 dropped. */
    private static function n(float $v): string
    {
        return rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.');
    }
}
