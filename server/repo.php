<?php
/**
 * RootMyS24 仓库统计（数据来自 GitHub 公开 API，服务器无需保存任何凭据）
 *
 *   repo.php?format=json       聚合数据（JSON）
 *   repo.php?svg=overview      仓库概览（Stars / Forks / 下载 / Commits / Issues / PR）
 *   repo.php?svg=stars         Star 增长历史（累计曲线）
 *   repo.php?svg=activity      Issue / PR 历史（累计曲线）
 *   repo.php?svg=downloads     Release 下载量（条形图）
 *
 * Star 历史的取数方式：GitHub 现已要求鉴权才能读取 stargazers 时间戳，
 * 但仓库 events 流（无需鉴权）包含 WatchEvent 加星事件；每次刷新把新事件
 * 并入本地存档（data/stars.json）累积保存，历史部分由部署时一次性导入的
 * 种子文件（data/stars_seed.json，公开数据）补齐。
 * README 用 <img src="…repo.php?svg=stars"> 引用：GitHub 不执行 JS，
 * 但会经 Camo 代理图片，由服务器动态出 SVG 是唯一能自动更新的做法。
 */
declare(strict_types=1);

const REPO = 'NanoTurtle1145/root-my-s24';
const API_BASE = 'https://api.github.com/repos/' . REPO;
const DATA_DIR = __DIR__ . '/data';
const CACHE_TTL = 1800; // 30 分钟：未鉴权配额 60 次/时，一个刷新周期约 8 次请求

const MUTED = '#8b949e';   // GitHub 的灰，明暗两种主题下都能看
const BAR = '#3b82f6';

// ── 基础工具 ─────────────────────────────────────────────────────

function data_dir(): string
{
    if (is_dir(DATA_DIR)) { return DATA_DIR; }
    if (@mkdir(DATA_DIR, 0755, true)) { return DATA_DIR; }
    return sys_get_temp_dir();
}

function read_json_file(string $path): array
{
    if (!is_file($path)) { return []; }
    $j = json_decode((string)file_get_contents($path), true);
    return is_array($j) ? $j : [];
}

function write_json_file(string $path, array $data): void
{
    @file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function r1(float $v): string
{
    return (string)round($v, 1);
}

/** 粗略文本宽度（CJK 按全宽计），用于图例排版。 */
function text_width(string $s, float $size): float
{
    $cjk = (int)preg_match_all('/[\x{2E80}-\x{9FFF}\x{FF00}-\x{FFEF}]/u', $s);
    $n = mb_strlen($s);
    return $cjk * $size + ($n - $cjk) * $size * 0.62;
}

// ── GitHub 取数 ──────────────────────────────────────────────────

/** GET 一个 JSON；失败返回 null。 */
function gh_json(string $url): ?array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_USERAGENT => 'rms24-repo-stats',
        CURLOPT_HTTPHEADER => [
            'Accept: application/vnd.github+json',
            'X-GitHub-Api-Version: 2022-11-28',
        ],
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($body === false || $code < 200 || $code >= 300) { return null; }
    $j = json_decode((string)$body, true);
    return is_array($j) ? $j : null;
}

/** 逐页拉取列表。$stopShort=false 时即使某页不满也继续下一页（events 流的页大小不规律）。 */
function gh_list(string $path, int $maxPages = 3, bool $stopShort = true): ?array
{
    $all = [];
    for ($page = 1; $page <= $maxPages; $page++) {
        $sep = str_contains($path, '?') ? '&' : '?';
        $j = gh_json(API_BASE . $path . $sep . 'per_page=100&page=' . $page);
        if ($j === null) { return $page === 1 ? null : $all; }
        $all = array_merge($all, $j);
        if (count($j) === 0) { break; }
        if ($stopShort && count($j) < 100) { break; }
    }
    return $all;
}

/** 用 Link 头统计列表总数，不取内容。 */
function gh_count(string $path): ?int
{
    $ch = curl_init(API_BASE . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_USERAGENT => 'rms24-repo-stats',
        CURLOPT_HTTPHEADER => [
            'Accept: application/vnd.github+json',
            'X-GitHub-Api-Version: 2022-11-28',
        ],
    ]);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $hsize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    if ($raw === false || $code !== 200) { return null; }
    $headers = substr((string)$raw, 0, $hsize);
    if (preg_match_all('/^link:\s*(.+)$/mi', $headers, $mm)) {
        $joined = implode(',', $mm[1]);
        if (preg_match('/[?&]page=(\d+)>;\s*rel="last"/i', $joined, $m)) { return (int)$m[1]; }
    }
    $body = json_decode(substr((string)$raw, $hsize), true);
    return is_array($body) ? count($body) : null;
}

// ── 刷新与聚合 ───────────────────────────────────────────────────

/** 拉取全部数据；失败的单项保留旧值。返回更新后的缓存数组。 */
function repo_refresh(array $store): array
{
    $dir = data_dir();
    $ok = false;

    // 基础信息
    $repo = gh_json(API_BASE);
    if ($repo !== null) {
        $store['repo'] = [
            'stars' => (int)($repo['stargazers_count'] ?? 0),
            'forks' => (int)($repo['forks_count'] ?? 0),
            'watchers' => (int)($repo['subscribers_count'] ?? 0),
            'openIssues' => (int)($repo['open_issues_count'] ?? 0),
            'created' => (string)($repo['created_at'] ?? ''),
            'pushed' => (string)($repo['pushed_at'] ?? ''),
        ];
        $ok = true;
    }

    // Issue（含 PR，需过滤）与 PR
    $issues = gh_list('/issues?state=all&sort=created&direction=asc');
    if ($issues !== null) {
        $rows = [];
        foreach ($issues as $it) {
            if (isset($it['pull_request'])) { continue; }
            $rows[] = [
                'c' => (string)($it['created_at'] ?? ''),
                'x' => (string)($it['closed_at'] ?? ''),
            ];
        }
        $store['issues'] = $rows;
        $ok = true;
    }
    $pulls = gh_list('/pulls?state=all&sort=created&direction=asc');
    if ($pulls !== null) {
        $rows = [];
        foreach ($pulls as $it) {
            $rows[] = [
                'c' => (string)($it['created_at'] ?? ''),
                'x' => (string)($it['merged_at'] ?? ''),
                'z' => (string)($it['closed_at'] ?? ''),
            ];
        }
        $store['pulls'] = $rows;
        $ok = true;
    }

    // Releases（下载量按资产求和）
    $releases = gh_list('/releases?sort=published&direction=asc');
    if ($releases !== null) {
        $rows = [];
        foreach ($releases as $it) {
            $dl = 0;
            foreach (($it['assets'] ?? []) as $a) { $dl += (int)($a['download_count'] ?? 0); }
            $rows[] = [
                'tag' => (string)($it['tag_name'] ?? ''),
                'when' => (string)($it['published_at'] ?? ''),
                'dl' => $dl,
            ];
        }
        $store['releases'] = $rows;
        $ok = true;
    }

    // 计数
    $commits = gh_count('/commits?per_page=1');
    if ($commits !== null) { $store['commits'] = $commits; $ok = true; }
    $contributors = gh_count('/contributors?per_page=1');
    if ($contributors !== null) { $store['contributors'] = $contributors; $ok = true; }

    // Star 历史：种子 + 本地存档 + events 流（WatchEvent）合并去重
    $known = [];
    $seed = read_json_file($dir . '/stars_seed.json');
    foreach ($seed as $e) {
        if (isset($e['u'], $e['t'])) { $known[$e['u'] . '@' . $e['t']] = ['u' => $e['u'], 't' => $e['t']]; }
    }
    foreach ((read_json_file($dir . '/stars.json')['list'] ?? []) as $e) {
        if (isset($e['u'], $e['t'])) { $known[$e['u'] . '@' . $e['t']] = ['u' => $e['u'], 't' => $e['t']]; }
    }
    $events = gh_list('/events', 2, false);
    if ($events !== null) {
        $ok = true;
        foreach ($events as $ev) {
            if (($ev['type'] ?? '') !== 'WatchEvent') { continue; }
            $u = (string)($ev['actor']['login'] ?? '');
            $t = (string)($ev['created_at'] ?? '');
            if ($u !== '' && $t !== '') { $known[$u . '@' . $t] = ['u' => $u, 't' => $t]; }
        }
    }
    $list = array_values($known);
    usort($list, static fn(array $a, array $b): int => strcmp($a['t'], $b['t']));
    write_json_file($dir . '/stars.json', ['v' => 1, 'list' => $list]);

    if ($ok) { $store['generated'] = time(); }
    return $store;
}

/** 读缓存，过期则刷新。 */
function repo_data(): array
{
    $dir = data_dir();
    $cacheFile = $dir . '/repo_cache.json';
    $cache = read_json_file($cacheFile);
    $generated = (int)($cache['generated'] ?? 0);
    if ($generated === 0 || (time() - $generated) >= CACHE_TTL) {
        $cache = repo_refresh($cache);
        write_json_file($cacheFile, $cache);
    }
    $cache['starsHistory'] = read_json_file($dir . '/stars.json')['list'] ?? [];
    return $cache;
}

// ── SVG 绘制（与 stats.php 同一视觉语言）──────────────────────────

function svg_open(int $w, int $h, string $title): string
{
    return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $w . '" height="' . $h . '"'
        . ' viewBox="0 0 ' . $w . ' ' . $h . '" role="img" aria-label="' . htmlspecialchars($title) . '">';
}

function svg_title(string $title): string
{
    return '<text x="0" y="20" font-family="ui-sans-serif,system-ui,-apple-system,Segoe UI,Roboto,sans-serif"'
        . ' font-size="15" fill="' . MUTED . '">' . htmlspecialchars($title) . '</text>';
}

function svg_placeholder(string $title): string
{
    $w = 880;
    $h = 120;
    return svg_open($w, $h, $title) . svg_title($title)
        . '<text x="0" y="64" font-family="ui-sans-serif,system-ui,sans-serif" font-size="14" fill="' . MUTED . '">'
        . htmlspecialchars('数据正在初始化，或 GitHub API 暂时不可用；服务器会自动重试。')
        . '</text></svg>';
}

/** shields.io 同款 flat-square 徽章（自渲染：shields 的 GitHub 动态徽章依赖其令牌池，容易失效） */
function svg_badge(string $label, string $value, string $color = '#1E88E5'): string
{
    $lw = (int)round(mb_strlen($label) * 5.8 + 10);
    $rw = (int)round(mb_strlen($value) * 5.8 + 10);
    $w = $lw + $rw;
    $tx1 = (int)($lw * 5);
    $tx2 = (int)($lw * 10 + $rw * 5);
    $tl1 = (int)($lw * 10 - 100);
    $tl2 = (int)($rw * 10 - 100);
    $title = $label . ': ' . $value;
    $le = htmlspecialchars($label);
    $ve = htmlspecialchars($value);
    return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $w . '" height="20" role="img" aria-label="' . htmlspecialchars($title) . '">'
        . '<title>' . htmlspecialchars($title) . '</title>'
        . '<g shape-rendering="crispEdges">'
        . '<rect width="' . $lw . '" height="20" fill="#555"/>'
        . '<rect x="' . $lw . '" width="' . $rw . '" height="20" fill="' . $color . '"/>'
        . '</g>'
        . '<g fill="#fff" text-anchor="middle" font-family="Verdana,Geneva,DejaVu Sans,sans-serif" text-rendering="geometricPrecision" font-size="110">'
        . '<text x="' . $tx1 . '" y="140" transform="scale(.1)" textLength="' . $tl1 . '">' . $le . '</text>'
        . '<text x="' . $tx2 . '" y="140" transform="scale(.1)" textLength="' . $tl2 . '">' . $ve . '</text>'
        . '</g></svg>';
}

function svg_overview(array $d): string
{
    $repo = $d['repo'] ?? null;
    if ($repo === null) { return svg_placeholder('RootMyS24 仓库概览'); }

    $issues = $d['issues'] ?? [];
    $pulls = $d['pulls'] ?? [];
    $openIssues = 0;
    foreach ($issues as $i) { if (($i['x'] ?? '') === '') { $openIssues++; } }
    $merged = 0;
    foreach ($pulls as $p) { if (($p['x'] ?? '') !== '') { $merged++; } }
    $dlTotal = 0;
    foreach (($d['releases'] ?? []) as $r) { $dlTotal += (int)$r['dl']; }

    $cards = [
        ['Stars（GitHub 星标）', (string)$repo['stars']],
        ['Forks（派生）', (string)$repo['forks']],
        ['Release 下载总数', number_format($dlTotal)],
        ['Commits（主分支）', (string)($d['commits'] ?? '—')],
        ['Contributors（贡献者）', (string)($d['contributors'] ?? '—')],
        ['Watchers（关注）', (string)$repo['watchers']],
        ['Issues（开放 / 总数）', $openIssues . ' / ' . count($issues)],
        ['Pull Requests（合并 / 总数）', $merged . ' / ' . count($pulls)],
    ];

    $w = 880;
    $gap = 12;
    $cardW = (int)(($w - 3 * $gap) / 4);
    $cardH = 70;
    $rowTop = 32;
    $rowGap = 14;
    $h = $rowTop + 2 * $cardH + $rowGap + 48;

    $title = 'RootMyS24 仓库概览（数据来自 GitHub 公开 API，约每 30 分钟自动刷新）';
    $out = svg_open($w, $h, $title) . svg_title($title);

    foreach ($cards as $idx => [$k, $v]) {
        $row = intdiv($idx, 4);
        $col = $idx % 4;
        $x = $col * ($cardW + $gap);
        $y = $rowTop + $row * ($cardH + $rowGap);
        $out .= '<rect x="' . $x . '" y="' . $y . '" width="' . $cardW . '" height="' . $cardH . '" rx="10" fill="none" stroke="' . MUTED . '" stroke-opacity="0.35"/>';
        $out .= '<text x="' . ($x + 16) . '" y="' . ($y + 26) . '" font-family="ui-sans-serif,system-ui,sans-serif" font-size="12" fill="' . MUTED . '">' . htmlspecialchars($k) . '</text>';
        $out .= '<text x="' . ($x + 16) . '" y="' . ($y + 56) . '" font-family="ui-sans-serif,system-ui,sans-serif" font-size="26" font-weight="600" fill="' . BAR . '">' . htmlspecialchars($v) . '</text>';
    }

    $out .= '<text x="0" y="' . ($h - 14) . '" font-family="ui-sans-serif,system-ui,sans-serif" font-size="12" fill="' . MUTED . '">'
        . htmlspecialchars('统计范围：' . REPO . ' · 仅仓库公开指标')
        . '</text>';
    return $out . '</svg>';
}

/** 生成累计台阶折线的数据点：[起点,0] + 每个事件一段。$startAtFirst=true 时从首个事件处起步，画布更干净。 */
function cumulative_points(array $times, int $t0, bool $startAtFirst = false): array
{
    $ts = [];
    foreach ($times as $t) {
        $v = strtotime((string)$t);
        if ($v !== false) { $ts[] = $v; }
    }
    sort($ts);
    $pts = ($startAtFirst && $ts) ? [[$ts[0], 0.0]] : [[$t0, 0.0]];
    $i = 0;
    foreach ($ts as $v) {
        $i++;
        $pts[] = [$v, (float)$i];
    }
    return $pts;
}

function step_path(array $pts, callable $x, callable $y): string
{
    $d = 'M' . r1($x($pts[0][0])) . ' ' . r1($y($pts[0][1]));
    $n = count($pts);
    for ($k = 1; $k < $n; $k++) {
        $d .= ' H' . r1($x($pts[$k][0])) . ' V' . r1($y($pts[$k][1]));
    }
    return $d;
}

/** 坐标轴：网格线 + y 标签，外加 x 轴三个日期。 */
function axes(array $grid, callable $y, int $L, int $R, int $H, int $t0, int $t1, callable $x): string
{
    $out = '';
    foreach ($grid as $g) {
        $yy = $y($g);
        $out .= '<line x1="' . $L . '" y1="' . r1($yy) . '" x2="' . $R . '" y2="' . r1($yy) . '" stroke="' . MUTED . '" stroke-opacity="0.2"/>';
        $out .= '<text x="' . ($L - 8) . '" y="' . r1($yy + 4) . '" text-anchor="end" font-family="ui-sans-serif,system-ui,sans-serif" font-size="12" fill="' . MUTED . '">' . $g . '</text>';
    }
    $font = 'font-family="ui-sans-serif,system-ui,sans-serif" font-size="12" fill="' . MUTED . '"';
    $mid = (int)(($t0 + $t1) / 2);
    $out .= '<text x="' . $L . '" y="' . ($H - 8) . '" ' . $font . '>' . gmdate('Y-m-d', $t0) . '</text>';
    $out .= '<text x="' . r1(($L + $R) / 2) . '" y="' . ($H - 8) . '" text-anchor="middle" ' . $font . '>' . gmdate('Y-m-d', $mid) . '</text>';
    $out .= '<text x="' . $R . '" y="' . ($H - 8) . '" text-anchor="end" ' . $font . '>' . gmdate('Y-m-d', $t1) . '</text>';
    return $out;
}

function svg_stars(array $d): string
{
    $list = $d['starsHistory'] ?? [];
    $n = count($list);
    $title = 'RootMyS24 Star 增长历史（累计 ' . $n . '）';
    if ($n === 0) { return svg_placeholder($title); }

    $w = 880;
    $h = 300;
    $L = 46;
    $R = $w - 20;
    $T = 56;
    $B = $h - 28;

    $t0 = (int)(strtotime((string)($d['repo']['created'] ?? '')) ?: strtotime((string)$list[0]['t']));
    $t1 = max(time(), (int)strtotime((string)$list[$n - 1]['t']));
    $span = max(1, $t1 - $t0);

    $maxN = max(1, $n);
    $x = static fn(int $ts): float => $L + ($ts - $t0) / $span * ($R - $L);
    $y = static fn(float $v): float => $B - $v / $maxN * ($B - $T);

    $out = svg_open($w, $h, $title) . svg_title($title);
    $out .= axes([0, (int)floor($maxN / 2), $maxN], $y, $L, $R, $h, $t0, $t1, $x);

    $pts = cumulative_points(array_column($list, 't'), $t0);
    $line = step_path($pts, $x, $y);
    $lastX = $x($pts[count($pts) - 1][0]);
    $out .= '<path d="' . $line . ' L' . r1($lastX) . ' ' . r1($B) . ' L' . r1($x($t0)) . ' ' . r1($B) . ' Z" fill="' . BAR . '" fill-opacity="0.12"/>';
    $out .= '<path d="' . $line . '" fill="none" stroke="' . BAR . '" stroke-width="2" stroke-linejoin="round"/>';
    $out .= '<circle cx="' . r1($lastX) . '" cy="' . r1($y((float)$n)) . '" r="3.5" fill="' . BAR . '"/>';

    return $out . '</svg>';
}

function svg_activity(array $d): string
{
    $issues = $d['issues'] ?? [];
    $pulls = $d['pulls'] ?? [];
    if (!$issues && !$pulls) { return svg_placeholder('RootMyS24 Issue / PR 历史'); }

    $labels = ['Issues 开启', 'Issues 关闭', 'PR 创建', 'PR 合并'];
    $colors = ['#3b82f6', '#22c55e', '#a855f7', '#f59e0b'];
    $seriesTimes = [
        array_column($issues, 'c'),
        array_filter(array_column($issues, 'x'), static fn($s): bool => $s !== ''),
        array_column($pulls, 'c'),
        array_filter(array_column($pulls, 'x'), static fn($s): bool => $s !== ''),
    ];

    $w = 880;
    $h = 300;
    $L = 46;
    $R = $w - 20;
    $T = 62;
    $B = $h - 28;

    $t0 = (int)(strtotime((string)($d['repo']['created'] ?? '')) ?: time() - 86400 * 30);
    $t1 = time();
    $span = max(1, $t1 - $t0);

    $maxN = 1;
    foreach ($seriesTimes as $ts) { $maxN = max($maxN, count($ts)); }
    $x = static fn(int $ts): float => $L + ($ts - $t0) / $span * ($R - $L);
    $y = static fn(float $v): float => $B - $v / $maxN * ($B - $T);

    $title = 'RootMyS24 Issue / PR 历史（累计）';
    $out = svg_open($w, $h, $title) . svg_title($title);
    $out .= axes([0, (int)floor($maxN / 2), $maxN], $y, $L, $R, $h, $t0, $t1, $x);

    foreach ($seriesTimes as $k => $times) {
        if (!$times) { continue; }
        $pts = cumulative_points($times, $t0, true);
        $out .= '<path d="' . step_path($pts, $x, $y) . '" fill="none" stroke="' . $colors[$k] . '" stroke-width="2" stroke-linejoin="round"/>';
    }

    // 图例（含最终计数）
    $lx = 0;
    $ly = 42;
    $font = 'font-family="ui-sans-serif,system-ui,sans-serif" font-size="12" fill="' . MUTED . '"';
    foreach ($seriesTimes as $k => $times) {
        $text = $labels[$k] . ' ' . count($times);
        $out .= '<rect x="' . $lx . '" y="' . ($ly - 4) . '" width="18" height="3" rx="1.5" fill="' . $colors[$k] . '"/>';
        $out .= '<text x="' . ($lx + 24) . '" y="' . $ly . '" ' . $font . '>' . htmlspecialchars($text) . '</text>';
        $lx += 24 + (int)ceil(text_width($text, 12)) + 28;
    }

    return $out . '</svg>';
}

function svg_downloads(array $d): string
{
    $all = $d['releases'] ?? [];
    $total = 0;
    foreach ($all as $r) { $total += (int)$r['dl']; }

    $rows = array_values(array_filter($all, static fn(array $r): bool => (int)$r['dl'] > 0));
    usort($rows, static fn(array $a, array $b): int => $b['dl'] <=> $a['dl']);
    $rows = array_slice($rows, 0, 12);
    $title = 'RootMyS24 Release 下载量（下载最多的 ' . count($rows) . ' 个版本，总计 ' . number_format($total) . ' 次）';
    if (!$rows) { return svg_placeholder('RootMyS24 Release 下载量'); }

    $rowH = 26;
    $top = 46;
    $left = 240;
    $w = 880;
    $h = $top + count($rows) * $rowH + 16;
    $max = 1;
    foreach ($rows as $r) { $max = max($max, (int)$r['dl']); }

    $out = svg_open($w, $h, $title) . svg_title($title);
    $barMax = $w - $left - 90;
    $barY = $top;
    foreach ($rows as $r) {
        $label = (string)$r['tag'];
        if (mb_strlen($label) > 18) { $label = mb_substr($label, 0, 17) . '…'; }
        $val = (int)$r['dl'];
        $bw = max(3, (int)round($barMax * $val / $max));
        $out .= '<text x="0" y="' . ($barY + 12) . '" font-family="ui-sans-serif,system-ui,sans-serif" font-size="13" fill="' . MUTED . '">' . htmlspecialchars($label) . '</text>';
        $out .= '<rect x="' . $left . '" y="' . ($barY + 1) . '" width="' . $bw . '" height="12" rx="3" fill="' . BAR . '"/>';
        $out .= '<text x="' . ($left + $bw + 8) . '" y="' . ($barY + 12) . '" font-family="ui-sans-serif,system-ui,sans-serif" font-size="12" fill="' . MUTED . '">' . number_format($val) . '</text>';
        $barY += $rowH;
    }
    return $out . '</svg>';
}

// ── 输出 ─────────────────────────────────────────────────────────

$data = repo_data();
header('Cache-Control: public, max-age=300');

if (($_GET['format'] ?? '') === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// 徽章：?badge=stars / ?badge=downloads（README 顶部徽章行用）
$badge = (string)($_GET['badge'] ?? '');
if ($badge !== '') {
    header('Content-Type: image/svg+xml; charset=utf-8');
    $dlTotal = 0;
    foreach (($data['releases'] ?? []) as $r) { $dlTotal += (int)$r['dl']; }
    if ($badge === 'stars') {
        echo svg_badge('stars', (string)($data['repo']['stars'] ?? 0));
    } elseif ($badge === 'downloads') {
        echo svg_badge('downloads', number_format($dlTotal));
    } else {
        echo svg_badge('badge', 'n/a');
    }
    exit;
}

$svg = (string)($_GET['svg'] ?? 'overview');
header('Content-Type: image/svg+xml; charset=utf-8');

switch ($svg) {
    case 'stars':
        echo svg_stars($data);
        break;
    case 'activity':
        echo svg_activity($data);
        break;
    case 'downloads':
        echo svg_downloads($data);
        break;
    default:
        echo svg_overview($data);
        break;
}
