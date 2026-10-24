<?php

namespace App\Service\Unifi;

/**
 * Topology-map graph: raw stat/device + stat/sta + stat/health rows in, nodes
 * and parent → child edges with live rates and utilisation out. Pure — no I/O,
 * no clock, no state; UnifiMapReader owns fetching and caching.
 *
 * WHY uplinks and not v2/topology: the map fetches stat/device and stat/sta
 * every 10 s anyway for rates, and those two resolved 100 % of devices and
 * clients in the 2026-10-23 probe. v2 would add a console call per poll and a
 * second parser for no observed gain (see the design spec's graph-source note).
 *
 * Shape (null only when stat/device holds no gateway — there is no root):
 *  [
 *    'nodes'   => list<Node>,  // internet, gateway, devices (input order), clients (input order)
 *    'edges'   => list<Edge>,  // one per onMap node except the internet: parent → node
 *    'busiest' => list<Edge>,  // top 5 by util, util > 0, desc — the strip's default view
 *  ]
 *  Node = ['id' => string, 'kind' => 'internet'|'gateway'|'switch'|'ap'|'wired'|'wifi',
 *          'name' => string, 'model' => ?string, 'parentId' => ?string, 'online' => bool,
 *          'onMap' => bool (false only for wifi), 'downBps' => float, 'upBps' => float,
 *          'detail' => array (per kind, see the *Detail() helpers)]
 *  Edge = ['from' => string, 'to' => string, 'linkMbps' => ?int, 'downBps' => float,
 *          'upBps' => float, 'util' => float, 'tier' => 'idle'|'quiet'|'busy'|'saturated',
 *          'underNegotiated' => bool, 'capableMbps' => ?int,
 *          'basis' => null|'plan'|'speedtest'|'port' (internet edge only),
 *          'basisDir' => null|'down'|'up' (internet edge only: the side util, linkMbps
 *                        and basis describe — whichever direction is busier)]
 *
 * Rates are BYTES/s like every other reader; "down" always means away from the
 * internet. Node ids are lowercase MACs without separators so they survive a
 * URL (`?node=`) and a route requirement unescaped.
 *
 * Node order is a stable listing, not a layout: TopologyLayout derives its own
 * DFS order from parentId, so nothing here depends on how children are sorted.
 */
final class UnifiTopologyBuilder
{
    public const INTERNET = 'internet';

    /** The strip's "nothing selected" view. */
    private const BUSIEST = 5;
    /** Under 1 kB/s combined a link is keep-alive noise: drawn idle, no flow dots. */
    private const IDLE_BPS  = 1000.0;
    private const BUSY      = 0.5;
    private const SATURATED = 0.9;

    /** Port/uplink `media` → the fastest that medium negotiates, in Mb/s. */
    private const MEDIA_MBPS = [
        'FE'    => 100,
        'GE'    => 1000,
        '2P5GE' => 2500,  // switch spelling
        '2.5GE' => 2500,  // gateway spelling (probe: UDM ports and WAN)
        '5GE'   => 5000,
        '10GE'  => 10000,
        'SFP+'  => 10000,
    ];

    /**
     * @param array      $devices raw stat/device rows
     * @param array      $clients raw stat/sta rows
     * @param ?array     $health  raw stat/health list of subsystem rows (wan, www, lan, …)
     * @param ?float     $planDownMbps optional plan speed; null or ≤ 0 = not set
     * @param ?float     $planUpMbps   optional plan speed; null or ≤ 0 = not set
     */
    public static function build(array $devices, array $clients, ?array $health,
                                 ?float $planDownMbps, ?float $planUpMbps): ?array
    {
        [$devs, $gwId] = self::indexDevices($devices);
        if ($gwId === null) return null;

        $planDown = $planDownMbps !== null && $planDownMbps > 0 ? $planDownMbps : null;
        $planUp   = $planUpMbps !== null && $planUpMbps > 0 ? $planUpMbps : null;
        $sub      = self::subsystems($health);
        $www      = $sub['www'] ?? [];
        $wan      = $sub['wan'] ?? [];

        /** @var array<string, array> $links child id → link facts (rates, speed, port) */
        $links  = [$gwId => self::internetLink($www, $wan, $devs[$gwId], $planDown, $planUp)];
        /** @var array<string, array<int, string>> $onPort parent id → port idx → id of what's in it */
        $onPort = [];

        $parents = self::deviceParents($devs, $gwId);
        foreach ($parents as $id => [$pid, $viaUplink]) {
            $links[$id] = self::deviceLink($devs[$id], $devs[$pid], $viaUplink);
            if ($links[$id]['port'] !== null) $onPort[$pid][$links[$id]['port']] ??= (string) $id;
        }

        // Clients. A MAC that is already a device (or a repeated row) is ignored:
        // ids key the layout's boxes, so a duplicate would silently overwrite one.
        $clientRows = []; // id → ['wired' => bool, 'row', 'parent', …], in stat/sta order
        foreach ($clients as $c) {
            if (!is_array($c)) continue;
            $id = self::idOf($c['mac'] ?? null);
            if ($id === null || isset($devs[$id]) || isset($clientRows[$id])) continue;

            if (($c['is_wired'] ?? false) === true) {
                $sw    = self::idOf($c['sw_mac'] ?? null);
                $known = $sw !== null && isset($devs[$sw]);
                $pid   = $known ? $sw : $gwId;
                $links[$id] = self::wiredLink($c, $known ? $devs[$sw] : null);
                if ($links[$id]['port'] !== null) $onPort[$pid][$links[$id]['port']] ??= $id;
                $clientRows[$id] = ['id' => $id, 'wired' => true, 'row' => $c, 'parent' => $pid,
                                    'known' => $known];
                continue;
            }

            // Any device can host Wi-Fi (an AP, or a gateway with radios); an
            // unknown one leaves nowhere to hang the client, so it's dropped.
            $ap = self::idOf($c['ap_mac'] ?? null);
            if ($ap === null || !isset($devs[$ap])) continue;
            $links[$id] = ['down' => self::rate($c['tx_bytes-r'] ?? null),
                           'up'   => self::rate($c['rx_bytes-r'] ?? null)];
            $clientRows[$id] = ['id' => $id, 'wired' => false, 'row' => $c, 'parent' => $ap];
        }

        // A device's own uplink port leads to its parent: clicking that port in
        // the strip selects the parent. Children claimed their ports first.
        foreach ($parents as $id => [$pid, $viaUplink]) {
            $own = self::int($devs[$id]['uplink']['port_idx'] ?? null);
            if ($viaUplink && $own !== null) $onPort[$id][$own] ??= $pid;
        }

        $nodes = self::assembleNodes($devs, $gwId, $parents, $clientRows, $links, $onPort,
            $www, $wan, $planDown, $planUp);

        $edges = [];
        foreach ($nodes as $n) {
            if (!$n['onMap'] || $n['parentId'] === null) continue;
            $edges[] = self::edge($n['parentId'], $n['id'], $links[$n['id']]);
        }

        $busiest = array_values(array_filter($edges, static fn(array $e): bool => $e['util'] > 0.0));
        usort($busiest, static fn(array $a, array $b): int => $b['util'] <=> $a['util']);

        return [
            'nodes'   => $nodes,
            'edges'   => $edges,
            'busiest' => array_slice($busiest, 0, self::BUSIEST),
        ];
    }

    /**
     * Gateways, switches and APs keyed by id, input order kept. Anything else
     * (Protect, phones, unknown types) has no place on a network map.
     *
     * One gateway per site: the first wins. A second (a shadow-mode standby) is
     * not drawn — hanging it off the internet would double-count the WAN rate.
     *
     * @return array{0: array<string, array>, 1: ?string}
     */
    private static function indexDevices(array $devices): array
    {
        $devs = [];
        $gwId = null;
        foreach ($devices as $d) {
            if (!is_array($d)) continue;
            $kind = self::kindOf(self::str($d['type'] ?? null));
            $id   = self::idOf($d['mac'] ?? null);
            if ($kind === null || $id === null || isset($devs[$id])) continue;
            if ($kind === 'gateway') {
                if ($gwId !== null) continue;
                $gwId = $id;
            }
            $stats = is_array($d['system-stats'] ?? null) ? $d['system-stats'] : [];
            $devs[$id] = [
                'id'     => $id,
                'kind'   => $kind,
                'name'   => self::nameOf($d, $id),
                'model'  => self::str($d['model'] ?? null),
                'online' => is_numeric($d['state'] ?? null) && (int) $d['state'] === 1,
                'uplink' => is_array($d['uplink'] ?? null) ? $d['uplink'] : [],
                'ports'  => self::portsOf($d['port_table'] ?? null),
                'cpu'    => self::num($stats['cpu'] ?? null),
                'mem'    => self::num($stats['mem'] ?? null),
            ];
        }
        return [$devs, $gwId];
    }

    /**
     * Parent of every non-gateway device: its `uplink.uplink_mac` when that is
     * a known device, else the gateway (Review Focus 1: adopting, offline, or
     * behind an unmanaged hop — no orphans).
     *
     * A loop (A ↔ B mid-reconvergence, or a device naming itself) would leave
     * nodes that never reach the internet, which the layout would silently drop.
     * The first device found closing a loop is re-hung off the gateway.
     *
     * @return array<string, array{0: string, 1: bool}> id → [parent id, parent came from the uplink]
     */
    private static function deviceParents(array $devs, string $gwId): array
    {
        $parents = [];
        foreach ($devs as $dev) {
            $id = $dev['id']; // not the array key: an all-digit MAC key is an int
            if ($id === $gwId) continue;
            $pid   = self::idOf($dev['uplink']['uplink_mac'] ?? null);
            $known = $pid !== null && $pid !== $id && isset($devs[$pid]);
            $parents[$id] = [$known ? $pid : $gwId, $known];
        }

        foreach (array_keys($parents) as $id) {
            $id   = (string) $id;
            $seen = [$id => true];
            $cur  = $parents[$id][0];
            while ($cur !== $gwId) {
                if (isset($seen[$cur])) {
                    $parents[$cur] = [$gwId, false];
                    break;
                }
                $seen[$cur] = true;
                $cur = $parents[$cur][0];
            }
        }
        return $parents;
    }

    /**
     * WAN link. Down/up are the www subsystem's rx/tx (wan's as a fallback, as
     * UnifiLiveReader does). Each direction's denominator is the first that
     * exists of: plan speed → last speedtest → the gateway's WAN port speed.
     *
     * util is the busier direction against ITS denominator, so linkMbps, basis
     * and basisDir all describe that side: on a 1000/40 plan a saturated
     * upload reads "95 % of 40 Mb/s upload plan", not "of 1000 Mb/s plan". A
     * tie (an idle WAN included) keeps the download side, the headline figure.
     */
    private static function internetLink(array $www, array $wan, array $gw, ?float $planDown, ?float $planUp): array
    {
        $down = max(0.0, self::num($www['rx_bytes-r'] ?? null) ?? self::num($wan['rx_bytes-r'] ?? null) ?? 0.0);
        $up   = max(0.0, self::num($www['tx_bytes-r'] ?? null) ?? self::num($wan['tx_bytes-r'] ?? null) ?? 0.0);
        $port = self::mbps($gw['uplink']['speed'] ?? null);

        [$denDown, $basisDown] = self::denominator($planDown, self::positive($www['xput_down'] ?? null), $port);
        [$denUp, $basisUp]     = self::denominator($planUp, self::positive($www['xput_up'] ?? null), $port);

        // Can exceed 1.0 (a plan slower than real throughput) — kept raw; the tier caps it.
        $utilDown = $denDown === null ? null : $down * 8 / 1e6 / $denDown;
        $utilUp   = $denUp === null ? null : $up * 8 / 1e6 / $denUp;
        $dir = match (true) {
            $utilDown === null && $utilUp === null => null,
            $utilDown === null                     => 'up',
            $utilUp === null                       => 'down',
            default                                => $utilUp > $utilDown ? 'up' : 'down',
        };
        [$den, $basis, $util] = match ($dir) {
            'down'  => [$denDown, $basisDown, $utilDown],
            'up'    => [$denUp, $basisUp, $utilUp],
            default => [null, null, 0.0],
        };

        return [
            'down'     => $down,
            'up'       => $up,
            'linkMbps' => $den === null ? null : (int) round($den),
            'util'     => $util ?? 0.0,
            // The internet is not a UniFi device: never under-negotiated.
            'capable'  => null,
            'under'    => false,
            'basis'    => $basis,
            'basisDir' => $dir,
            'port'     => null,
            'row'      => null,
        ];
    }

    /** @return array{0: ?float, 1: ?string} */
    private static function denominator(?float $plan, ?float $speedtest, ?int $port): array
    {
        if ($plan !== null) return [$plan, 'plan'];
        if ($speedtest !== null) return [$speedtest, 'speedtest'];
        if ($port !== null) return [(float) $port, 'port'];
        return [null, null];
    }

    /**
     * A device's uplink. The parent's port row is the truth: stat/device's own
     * `uplink.*_bytes-r` is mostly 0 on switches (the probe's known trap). The
     * parent's tx is what it sends down the cable.
     *
     * With no such row (a mesh hop, a parent without a port_table), the child's
     * own uplink counters are used — MIRRORED, because they are the child's
     * port: what it receives came from upstream. When the parent is only the
     * gateway fallback, `uplink_remote_port` names a port on some unknown box,
     * so no row is looked up and the speed is unknown (null).
     *
     * An offline device moves nothing: stale counters are zeroed so its edge
     * is idle (Review Focus 2) and it is never flagged under-negotiated.
     */
    private static function deviceLink(array $child, array $parent, bool $viaUplink): array
    {
        $up  = $child['uplink'];
        $k   = $viaUplink ? self::int($up['uplink_remote_port'] ?? null) : null;
        $row = $k === null ? null : ($parent['ports'][$k] ?? null);

        if ($row !== null) {
            $down   = self::rate($row['tx_bytes-r'] ?? null);
            $upBps  = self::rate($row['rx_bytes-r'] ?? null);
            $linkMb = self::mbps($row['speed'] ?? null);
        } else {
            $down   = self::rate($up['rx_bytes-r'] ?? null);
            $upBps  = self::rate($up['tx_bytes-r'] ?? null);
            $linkMb = $viaUplink ? self::mbps($up['speed'] ?? null) : null;
        }
        if (!$child['online']) {
            $down = $upBps = 0.0;
        }

        // Both ends' capability must be known: the parent port's medium, and
        // the child's max_speed (else its uplink medium).
        $capable = null;
        if ($row !== null) {
            $parentCap = self::capOf($row['media'] ?? null);
            $childCap  = self::mbps($up['max_speed'] ?? null) ?? self::capOf($up['media'] ?? null);
            if ($parentCap !== null && $childCap !== null) $capable = min($parentCap, $childCap);
        }

        return [
            'down'     => $down,
            'up'       => $upBps,
            'linkMbps' => $linkMb,
            'util'     => self::utilOf($down, $upBps, $linkMb),
            'capable'  => $capable,
            'under'    => $child['online'] && $capable !== null && $linkMb !== null && $linkMb < $capable,
            'basis'    => null,
            'basisDir' => null,
            'port'     => $k,
            'row'      => $row,
        ];
    }

    /**
     * A wired client's link: the parent's port row at `sw_port` when there is
     * one, else the client's own stat/sta counters (Review Focus 3 — a port
     * the switch doesn't list, or no known switch at all). stat/sta speaks from
     * the network's side, so the client's `wired-tx` is down.
     *
     * Never under-negotiated: client NIC capability isn't exposed, and flagging
     * every 1G box on a 2.5G port would be noise (the spec's call).
     */
    private static function wiredLink(array $c, ?array $parent): array
    {
        $k   = $parent === null ? null : self::int($c['sw_port'] ?? null);
        $row = $k === null ? null : ($parent['ports'][$k] ?? null);

        if ($row !== null) {
            $down   = self::rate($row['tx_bytes-r'] ?? null);
            $up     = self::rate($row['rx_bytes-r'] ?? null);
            $linkMb = self::mbps($row['speed'] ?? null);
        } else {
            $down   = self::rate($c['wired-tx_bytes-r'] ?? null);
            $up     = self::rate($c['wired-rx_bytes-r'] ?? null);
            $linkMb = self::mbps($c['wired_rate_mbps'] ?? null);
        }

        return [
            'down'     => $down,
            'up'       => $up,
            'linkMbps' => $linkMb,
            'util'     => self::utilOf($down, $up, $linkMb),
            'capable'  => null,
            'under'    => false,
            'basis'    => null,
            'basisDir' => null,
            'port'     => $k,
            'row'      => $row,
        ];
    }

    /**
     * Ids are read from the values, never from array keys: PHP turns an
     * all-digit key ('784558000004' — a real Ubiquiti OUI) into an int.
     *
     * @param array<string, array>                     $devs
     * @param array<string, array{0: string, 1: bool}> $parents
     * @param array<string, array>                     $clientRows in stat/sta order
     * @param array<string, array>                     $links
     * @param array<string, array<int, string>>        $onPort
     */
    private static function assembleNodes(array $devs, string $gwId, array $parents, array $clientRows,
                                          array $links, array $onPort, array $www, array $wan,
                                          ?float $planDown, ?float $planUp): array
    {
        $gw     = $devs[$gwId];
        $wanRow = $links[$gwId];
        $status = self::str($www['status'] ?? null) ?? self::str($wan['status'] ?? null);
        $wanDetail = [
            'latencyMs'    => self::int($www['latency'] ?? null),
            'drops'        => self::int($www['drops'] ?? null),
            'isp'          => self::str($wan['isp_name'] ?? null),
            'basis'        => $wanRow['basis'],
            'planDownMbps' => $planDown,
            'planUpMbps'   => $planUp,
        ];
        // The device row's system-stats first (same source as the infra card);
        // stat/health's gw_system-stats only when the device row lacks them.
        $gwStats = is_array($wan['gw_system-stats'] ?? null) ? $wan['gw_system-stats'] : [];

        $nodes = [
            self::node(self::INTERNET, 'internet', 'Internet', null, null,
                // 'warning' (latency, loss) and 'unknown' still pass traffic.
                $status !== 'error', $wanRow, $wanDetail),
            self::node($gwId, 'gateway', $gw['name'], $gw['model'], self::INTERNET, $gw['online'], $wanRow,
                $wanDetail + [
                    'cpuPercent' => $gw['cpu'] ?? self::num($gwStats['cpu'] ?? null),
                    'memPercent' => $gw['mem'] ?? self::num($gwStats['mem'] ?? null),
                ]),
        ];

        // Wi-Fi clients per host, busiest first; ties keep stat/sta order.
        $byHost = [];
        foreach ($clientRows as $c) {
            if ($c['wired']) continue;
            $rate = $links[$c['id']];
            $byHost[$c['parent']][] = ['id' => $c['id'], 'bps' => $rate['down'] + $rate['up']];
        }
        foreach ($byHost as &$list) {
            usort($list, static fn(array $a, array $b): int => $b['bps'] <=> $a['bps']);
        }
        unset($list);

        foreach ($parents as $id => [$pid]) {
            $dev    = $devs[$id];
            $hosted = $byHost[$dev['id']] ?? [];
            $detail = match ($dev['kind']) {
                'switch' => ['ports' => self::switchPorts($dev, $onPort[$id] ?? [])],
                'ap'     => ['clientIds' => array_column($hosted, 'id'), 'clientCount' => count($hosted)],
                default  => [],
            };
            $nodes[] = self::node($dev['id'], $dev['kind'], $dev['name'], $dev['model'], $pid, $dev['online'],
                $links[$id], $detail);
        }

        foreach ($clientRows as $w) {
            $id  = $w['id'];
            $c   = $w['row'];
            if ($w['wired']) {
                $row = $links[$id]['row'];
                $nodes[] = self::node($id, 'wired', self::nameOf($c, $id), null, $w['parent'], true,
                    $links[$id], [
                        'switchName'      => $w['known'] ? $devs[$w['parent']]['name'] : null,
                        'port'            => $links[$id]['port'],
                        'linkMbps'        => $links[$id]['linkMbps'],
                        // Informational ("port supports 2.5G, linked at 1G") — never a flag.
                        'portCapableMbps' => $row === null ? null : self::capOf($row['media'] ?? null),
                        'uptime'          => self::int($c['uptime'] ?? null),
                        'errors'          => $row === null ? null : self::errorsOf($row),
                    ]);
                continue;
            }
            $nodes[] = self::node($id, 'wifi', self::nameOf($c, $id), null, $w['parent'], true,
                $links[$id], [
                    'apName'   => $devs[$w['parent']]['name'],
                    'ssid'     => self::str($c['essid'] ?? null),
                    'band'     => match (strtolower(self::str($c['radio'] ?? null) ?? '')) {
                        'ng'    => '2.4 GHz',
                        'na'    => '5 GHz',
                        '6e'    => '6 GHz',
                        default => null,
                    },
                    'channel'  => self::int($c['channel'] ?? null),
                    'proto'    => self::str($c['radio_proto'] ?? null),
                    'signal'   => self::int($c['signal'] ?? null),
                    'retryPct' => self::num($c['wifi_tx_retries_percentage'] ?? null),
                    'roams'    => self::int($c['roam_count'] ?? null),
                    'uptime'   => self::int($c['uptime'] ?? null),
                ]);
        }
        return $nodes;
    }

    /** A node's rates are its uplink's: `$link` carries `down` / `up` in BYTES/s. */
    private static function node(string $id, string $kind, string $name, ?string $model, ?string $parentId,
                                 bool $online, array $link, array $detail): array
    {
        return [
            'id'       => $id,
            'kind'     => $kind,
            'name'     => $name,
            'model'    => $model,
            'parentId' => $parentId,
            'online'   => $online,
            'onMap'    => $kind !== 'wifi',
            'downBps'  => $link['down'],
            'upBps'    => $link['up'],
            'detail'   => $detail,
        ];
    }

    private static function edge(string $from, string $to, array $link): array
    {
        return [
            'from'            => $from,
            'to'              => $to,
            'linkMbps'        => $link['linkMbps'],
            'downBps'         => $link['down'],
            'upBps'           => $link['up'],
            'util'            => $link['util'],
            'tier'            => self::tierOf($link['down'], $link['up'], $link['util']),
            'underNegotiated' => $link['under'],
            'capableMbps'     => $link['capable'],
            'basis'           => $link['basis'],
            'basisDir'        => $link['basisDir'],
        ];
    }

    /**
     * A switch's physical ports by index, each coloured by its own load. An
     * offline switch's counters are stale, so its ports read idle.
     *
     * @param array<int, string> $onPort port idx → id of the node plugged in
     */
    private static function switchPorts(array $dev, array $onPort): array
    {
        $out = [];
        foreach ($dev['ports'] as $idx => $p) {
            $down   = $dev['online'] ? self::rate($p['tx_bytes-r'] ?? null) : 0.0;
            $up     = $dev['online'] ? self::rate($p['rx_bytes-r'] ?? null) : 0.0;
            $linkMb = self::mbps($p['speed'] ?? null);
            $out[] = [
                'idx'      => $idx,
                'up'       => ($p['up'] ?? false) === true,
                'linkMbps' => $linkMb,
                'tier'     => self::tierOf($down, $up, self::utilOf($down, $up, $linkMb)),
                // poe_power arrives as a string ("14.20"); only meaningful when PoE is on.
                'poeW'     => ($p['poe_enable'] ?? false) === true ? self::num($p['poe_power'] ?? null) : null,
                'nodeId'   => $onPort[$idx] ?? null,
            ];
        }
        return $out;
    }

    /**
     * port_table rows keyed by port_idx, ascending. Rows without a usable
     * index can't be matched to anything and are dropped.
     *
     * @return array<int, array>
     */
    private static function portsOf(mixed $table): array
    {
        if (!is_array($table)) return [];
        $out = [];
        foreach ($table as $p) {
            if (!is_array($p)) continue;
            $idx = self::int($p['port_idx'] ?? null);
            if ($idx !== null && !isset($out[$idx])) $out[$idx] = $p;
        }
        ksort($out);
        return $out;
    }

    /** stat/health is a list of subsystem rows; index it by subsystem name. */
    private static function subsystems(?array $health): array
    {
        $sub = [];
        foreach ($health ?? [] as $row) {
            if (!is_array($row)) continue;
            $name = self::str($row['subsystem'] ?? null);
            if ($name !== null) $sub[$name] ??= $row;
        }
        return $sub;
    }

    /** Lowercase MAC, separators stripped. Anything not hex is no MAC (and no id). */
    private static function idOf(mixed $mac): ?string
    {
        $s = self::str($mac);
        if ($s === null) return null;
        $id = strtolower(str_replace([':', '-'], '', trim($s)));
        return $id !== '' && ctype_xdigit($id) ? $id : null;
    }

    /** name → hostname → model → aa:bb:cc:dd:ee:ff. Never empty: it labels a box. */
    private static function nameOf(array $row, string $id): string
    {
        return self::str($row['name'] ?? null)
            ?? self::str($row['hostname'] ?? null)
            ?? self::str($row['model'] ?? null)
            ?? (strlen($id) === 12 ? implode(':', str_split($id, 2)) : $id);
    }

    /** Same vocabulary as UnifiInfraReader::kindOf(), minus 'other' — those aren't drawn. */
    private static function kindOf(?string $type): ?string
    {
        return match (strtolower($type ?? '')) {
            'udm', 'ugw', 'uxg', 'ucg' => 'gateway',
            'usw'                      => 'switch',
            'uap'                      => 'ap',
            default                    => null,
        };
    }

    /** Fastest speed a port/uplink medium can negotiate, or null when unknown. */
    private static function capOf(mixed $media): ?int
    {
        $m = self::str($media);
        return $m === null ? null : (self::MEDIA_MBPS[strtoupper(trim($m))] ?? null);
    }

    /**
     * max(down, up) against the link speed. No speed (offline, port at 0,
     * unknown) → 0: never a division by zero (Review Focus 2).
     */
    private static function utilOf(float $down, float $up, ?int $linkMbps): float
    {
        if ($linkMbps === null || $linkMbps <= 0) return 0.0;
        return max($down, $up) * 8 / ($linkMbps * 1e6);
    }

    private static function tierOf(float $down, float $up, float $util): string
    {
        if ($down + $up < self::IDLE_BPS) return 'idle';
        return match (true) {
            $util >= self::SATURATED => 'saturated',
            $util >= self::BUSY      => 'busy',
            default                  => 'quiet',
        };
    }

    /** Sum of a port's error and drop counters; null when it reports none of them. */
    private static function errorsOf(array $row): ?int
    {
        $sum = null;
        foreach (['rx_errors', 'tx_errors', 'rx_dropped', 'tx_dropped'] as $k) {
            $v = self::num($row[$k] ?? null);
            if ($v !== null) $sum = ($sum ?? 0) + (int) $v;
        }
        return $sum;
    }

    private static function num(mixed $v): ?float
    {
        return is_numeric($v) ? (float) $v : null;
    }

    private static function int(mixed $v): ?int
    {
        $n = self::num($v);
        return $n === null ? null : (int) $n;
    }

    /** Empty strings become null so callers can chain `??` fallbacks. */
    private static function str(mixed $v): ?string
    {
        return is_string($v) && $v !== '' ? $v : null;
    }

    /** A BYTES/s counter: missing or garbage is 0, never negative. */
    private static function rate(mixed $v): float
    {
        return max(0.0, self::num($v) ?? 0.0);
    }

    /**
     * A link speed in Mb/s: anything that rounds to 0 means "no link", so null —
     * never a 0 that a WAN denominator would divide by.
     */
    private static function mbps(mixed $v): ?int
    {
        $n = self::num($v);
        if ($n === null) return null;
        $r = (int) round($n);
        return $r > 0 ? $r : null;
    }

    private static function positive(mixed $v): ?float
    {
        $n = self::num($v);
        return $n === null || $n <= 0 ? null : $n;
    }
}
