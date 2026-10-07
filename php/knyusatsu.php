<?php
/**
 * Kurage 入札情報ナビ（knyusatsu）― 1ファイルPHP＋SQLite。
 *
 * 官公需情報ポータルサイト（中小企業庁）の検索APIで集めた入札公告を、発注機関ごと・都道府県ごとに一覧にし、
 * キーワードで探せるようにする。各案件は元の公告（発注機関のページ・PDF）へリンクする。
 *
 *   /knyusatsu.php/            検索（キーワード・都道府県・分類）と新着・都道府県の一覧
 *   /knyusatsu.php/p/<01-47>/  都道府県ごとの発注機関と新着
 *   /knyusatsu.php/o/<id>/     発注機関ごとの入札公告の一覧
 *   /knyusatsu.php/about       データの出どころと範囲
 *   /knyusatsu.php/sitemap.xml /robots.txt /llms.txt
 *
 * データは同じ場所の knyusatsu_data/knyusatsu.sqlite（scripts/export.py が毎日組み直す。フォルダの .htaccess で外から読めない）。
 * 官公需ポータルの利用規約: API を使っていることを明記し、ポータルへリンクする。
 */
declare(strict_types=1);
mb_internal_encoding('UTF-8');
date_default_timezone_set('Asia/Tokyo');

const SITE = 'https://kurage.exbridge.jp';
const BASE = SITE . '/knyusatsu.php';
const NAME = 'Kurage 入札情報ナビ';
const KKJ = 'https://www.kkj.go.jp/s/';
const BUY = 'https://kappstore.exbridge.jp/app.php?id=464a65ef3e6a3646';
const PREFS = ['01'=>'北海道','02'=>'青森県','03'=>'岩手県','04'=>'宮城県','05'=>'秋田県','06'=>'山形県','07'=>'福島県','08'=>'茨城県','09'=>'栃木県','10'=>'群馬県','11'=>'埼玉県','12'=>'千葉県','13'=>'東京都','14'=>'神奈川県','15'=>'新潟県','16'=>'富山県','17'=>'石川県','18'=>'福井県','19'=>'山梨県','20'=>'長野県','21'=>'岐阜県','22'=>'静岡県','23'=>'愛知県','24'=>'三重県','25'=>'滋賀県','26'=>'京都府','27'=>'大阪府','28'=>'兵庫県','29'=>'奈良県','30'=>'和歌山県','31'=>'鳥取県','32'=>'島根県','33'=>'岡山県','34'=>'広島県','35'=>'山口県','36'=>'徳島県','37'=>'香川県','38'=>'愛媛県','39'=>'高知県','40'=>'福岡県','41'=>'佐賀県','42'=>'長崎県','43'=>'熊本県','44'=>'大分県','45'=>'宮崎県','46'=>'鹿児島県','47'=>'沖縄県'];
const CATS = ['工事', '役務', '物品'];

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function u(string $p): string { return BASE . $p; }
function jd(string $ymd): string { if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $ymd, $m)) return h($ymd); return (int)$m[1] . '年' . (int)$m[2] . '月' . (int)$m[3] . '日'; }
function ours(): bool { return ($_SERVER['HTTP_HOST'] ?? '') === 'kurage.exbridge.jp'; }
function db(): PDO {
    static $p = null;
    if ($p === null) { $p = new PDO('sqlite:' . __DIR__ . '/knyusatsu_data/knyusatsu.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]); }
    return $p;
}
function q(string $sql, array $a = []): array { $s = db()->prepare($sql); $s->execute($a); return $s->fetchAll(); }
function meta(string $k): string { $r = q('SELECT v FROM meta WHERE k=?', [$k]); return (string)($r[0]['v'] ?? ''); }
function pc(string $c): string { return sprintf('%02d', (int)$c); }

// ---------------- 画面の骨格 ----------------
function page(string $title, string $desc, string $url, string $body, array $ld = [], array $crumbs = [], bool $noindex = false): void {
    $ld[] = ['@context' => 'https://schema.org', '@type' => 'WebSite', 'name' => NAME, 'url' => BASE . '/',
             'publisher' => ['@type' => 'Organization', 'name' => '株式会社エクスブリッジ', 'url' => 'https://exbridge.jp/']];
    if ($crumbs) {
        $items = [['@type' => 'ListItem', 'position' => 1, 'name' => NAME, 'item' => BASE . '/']];
        foreach ($crumbs as $i => [$n, $l]) { $items[] = ['@type' => 'ListItem', 'position' => $i + 2, 'name' => $n, 'item' => $l]; }
        $ld[] = ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $items];
    }
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html lang="ja"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . h($title) . '</title><meta name="description" content="' . h($desc) . '"><link rel="canonical" href="' . h($url) . '">' . ($noindex ? '<meta name="robots" content="noindex">' : '');
    echo '<meta property="og:type" content="website"><meta property="og:site_name" content="' . h(NAME) . '"><meta property="og:title" content="' . h($title) . '"><meta property="og:description" content="' . h($desc) . '"><meta property="og:url" content="' . h($url) . '"><meta property="og:image" content="' . SITE . '/images/ogp/knyusatsu.png"><meta property="og:locale" content="ja_JP"><meta name="twitter:card" content="summary_large_image"><meta name="color-scheme" content="light">';
    echo '<link rel="icon" href="https://exbridge.jp/images/logo-mark-128.png">';
    foreach ($ld as $j) { echo '<script type="application/ld+json">' . json_encode($j, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>'; }
    echo '<style>
:root{--navy:#1b2a3a;--blue:#0f5f8c;--ink:#1f2933;--sub:#5b6676;--line:#dde3ea;--bg:#f6f8fa;--acc:#2f6f4f;--acc-l:#e8f3ed}
*{box-sizing:border-box}html{color-scheme:light}body{margin:0;background:#fff;color:var(--ink);font:15.5px/1.75 "Noto Sans JP","Hiragino Sans","Yu Gothic",system-ui,sans-serif}
a{color:var(--blue);overflow-wrap:anywhere}.wrap{max-width:1000px;margin:0 auto;padding:0 16px;min-width:0}
header.top{border-bottom:1px solid var(--line);background:#fff}header.top .wrap{display:flex;align-items:center;gap:6px 16px;flex-wrap:wrap;min-height:58px;padding-top:6px;padding-bottom:6px}
.brand{display:flex;align-items:center;gap:10px;color:var(--navy);text-decoration:none;font-weight:800;font-size:16px;line-height:1.3}.brand img{width:34px;height:34px;object-fit:contain;flex:none}.brand small{display:block;color:var(--acc);font-size:11.5px;font-weight:700}
nav.menu{display:flex;flex-wrap:wrap;gap:2px 14px;font-size:14px;font-weight:700}nav.menu a{color:var(--sub);text-decoration:none}
.promo{margin-left:auto;display:flex;flex-wrap:wrap;gap:2px 12px;font-size:13px;font-weight:800}.promo a{color:#b45309;text-decoration:none}
main{padding:10px 0 40px}h1{font-size:clamp(22px,4vw,30px);line-height:1.4;color:var(--navy);margin:18px 0 8px;text-wrap:balance}
h2{font-size:19px;color:var(--navy);margin:30px 0 10px;border-left:5px solid var(--acc);padding-left:10px}
.lead{color:var(--sub);margin:0 0 14px}.panel{background:var(--bg);border:1px solid var(--line);border-radius:12px;padding:14px 16px;margin:12px 0}
.src{font-size:12.5px;color:var(--sub)}.note{background:#fff6e0;border-left:4px solid #9a6700;border-radius:8px;padding:10px 12px;font-size:14px;margin:10px 0}
form.s{display:flex;flex-wrap:wrap;gap:8px;align-items:end}form.s label{display:block;font-size:13px;font-weight:700;color:var(--sub)}
input,select{font:inherit;font-size:16px;padding:8px 10px;border:2px solid var(--line);border-radius:10px;background:#fff;max-width:100%}
.btn{display:inline-block;background:var(--acc);color:#fff;border:0;border-radius:10px;padding:9px 16px;font-weight:700;text-decoration:none;cursor:pointer;font-size:15px;font-family:inherit}
.list{display:grid;gap:8px}.case{border:1px solid var(--line);border-radius:12px;padding:10px 14px;min-width:0}
.case .t{font-weight:700;color:var(--navy);line-height:1.5}.case .m{font-size:13px;color:var(--sub);display:flex;flex-wrap:wrap;gap:2px 12px;margin-top:2px}
.case .d{font-size:13.5px;color:#3e4a5e;margin-top:4px;overflow-wrap:anywhere}.tag{display:inline-block;background:var(--acc-l);color:var(--acc);border-radius:999px;padding:0 8px;font-size:12px;font-weight:800}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,150px),1fr));gap:8px}.grid a{display:block;background:#fff;border:1px solid var(--line);border-radius:10px;padding:7px 10px;text-decoration:none;color:var(--ink)}.grid a b{color:var(--navy)}.grid a small{display:block;color:var(--sub);font-size:12px}
.tbl{overflow-x:auto}table{border-collapse:collapse;width:100%;font-size:14px}th,td{border-bottom:1px solid var(--line);padding:7px 8px;text-align:left;vertical-align:top}th{color:var(--sub);white-space:nowrap}td.n{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
details{border-bottom:1px solid var(--line);padding:8px 0}summary{cursor:pointer;font-weight:700}
footer{margin-top:40px;border-top:1px solid var(--line);padding:18px 0;font-size:13px;color:var(--sub)}footer p{margin:6px 0}
</style></head><body>';
    echo '<header class="top"><div class="wrap"><a class="brand" href="' . u('/') . '"><img src="https://exbridge.jp/images/logo-mark-128.png" width="34" height="34" alt="株式会社エクスブリッジ"><span>' . h(NAME) . '<small>官公需ポータルの入札公告を、発注機関ごとに</small></span></a>';
    echo '<nav class="menu"><a href="' . u('/') . '">探す</a><a href="' . u('/#pref') . '">都道府県</a><a href="' . u('/about') . '">データについて</a></nav>';
    if (ours()) echo '<!--kurage-only--><span class="promo"><a href="https://exbridge.jp/ai-it-komon.html?ref=knyusatsu-head-komon" target="_blank" rel="noopener">AI-IT顧問</a><a href="https://kurage.exbridge.jp/reseller.html?ref=knyusatsu-head-reseller" target="_blank" rel="noopener">販売代理店募集</a></span><!--/kurage-only-->';
    echo '</div></header><main class="wrap">' . $body . '</main><footer><div class="wrap">';
    echo '<p>このサイトは、中小企業庁「<a href="' . KKJ . '" rel="noopener">官公需情報ポータルサイト</a>」の検索APIを利用して作っています。ポータルに登録された入札公告だけを載せています（発注機関の公告のすべてではありません）。入札の参加資格・期限・仕様は、必ず元の公告で確認してください。</p>';
    echo '<p><a href="https://exbridge.jp/?ref=knyusatsu">株式会社エクスブリッジ</a>（名古屋・業務システム開発）・<a href="' . SITE . '/khojokin.php/?ref=knyusatsu">Kurage 補助金ナビ</a>・<a href="' . SITE . '/kkensetsu.php/?ref=knyusatsu">建設業許可 更新期限チェック</a>・<a href="' . SITE . '/kseidocal.php/?ref=knyusatsu">経営者の制度カレンダー</a></p>';
    echo '</div></footer>';
    if (ours()) {
        echo '<img src="' . SITE . '/simpletrack.php?t=img&url=' . rawurlencode($url) . '&ref=' . rawurlencode((string)($_GET['ref'] ?? '')) . '" width="1" height="1" alt="" aria-hidden="true" style="position:absolute;left:-9999px">';
        echo '<script src="https://kurage.exbridge.jp/partner-bar.js" defer></script>';
    }
    echo '</body></html>';
}

function cases_html(array $rows, bool $showOrg = true): string {
    if (!$rows) return '<p class="panel">該当する入札公告はありません。</p>';
    $o = '<div class="list">';
    foreach ($rows as $r) {
        $o .= '<div class="case"><div class="t"><a href="' . h($r['url']) . '" rel="noopener nofollow" target="_blank">' . h($r['name']) . '</a></div><div class="m">'
            . '<span>公告 ' . jd($r['issue_date']) . '</span>'
            . ($showOrg ? '<a href="' . u('/o/' . $r['org_id'] . '/') . '">' . h($r['org']) . '</a>' : '')
            . ($r['category'] ? '<span class="tag">' . h($r['category']) . '</span>' : '')
            . ($r['procedure'] ? '<span>' . h($r['procedure']) . '</span>' : '')
            . ($r['filetype'] ? '<span>' . h(strtoupper($r['filetype'])) . '</span>' : '') . '</div>'
            . ($r['summary'] ? '<div class="d">' . h(mb_substr($r['summary'], 0, 140)) . (mb_strlen($r['summary']) > 140 ? '…' : '') . '</div>' : '') . '</div>';
    }
    return $o . '</div>';
}

function search_form(string $qv = '', string $pref = '', string $cat = ''): string {
    $po = '<option value="">全国</option>';
    foreach (PREFS as $c => $n) { $c = pc((string)$c); $po .= '<option value="' . $c . '"' . ($c === $pref ? ' selected' : '') . '>' . h($n) . '</option>'; }
    $co = '<option value="">すべて</option>';
    foreach (CATS as $c) { $co .= '<option' . ($c === $cat ? ' selected' : '') . '>' . $c . '</option>'; }
    return '<form class="s panel" method="get" action="' . u('/') . '"><div style="flex:1;min-width:200px"><label for="q">キーワード（件名・機関名・本文）</label><input id="q" name="q" value="' . h($qv) . '" placeholder="例：システム 保守、清掃、測量" style="width:100%"></div>'
        . '<div><label for="pref">都道府県</label><select id="pref" name="pref">' . $po . '</select></div><div><label for="cat">分類</label><select id="cat" name="cat">' . $co . '</select></div><div><button class="btn">探す</button></div></form>';
}

// ---------------- ルーティング ----------------
$path = $_SERVER['PATH_INFO'] ?? '';
if ($path === '' && !str_ends_with((string)($_SERVER['REQUEST_URI'] ?? ''), '/') && !str_contains((string)($_SERVER['REQUEST_URI'] ?? ''), '?')) { header('Location: ' . BASE . '/', true, 302); exit; }
if ($path === '/robots.txt') { header('Content-Type: text/plain; charset=UTF-8'); echo "User-agent: *\nAllow: /\nSitemap: " . u('/sitemap.xml') . "\n"; exit; }
if ($path === '/sitemap.xml') {
    header('Content-Type: application/xml; charset=UTF-8');
    $lm = substr(meta('latest_issue'), 0, 10);
    echo '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
    echo '<url><loc>' . u('/') . '</loc><lastmod>' . h($lm) . '</lastmod></url><url><loc>' . u('/about') . '</loc><lastmod>2026-10-07</lastmod></url>';
    foreach (q('SELECT pref_code, MAX(issue_date) l FROM cases GROUP BY pref_code') as $r) { echo '<url><loc>' . u('/p/' . pc($r['pref_code']) . '/') . '</loc><lastmod>' . h($r['l']) . '</lastmod></url>'; }
    foreach (q('SELECT id, latest FROM orgs WHERE n>=3') as $r) { echo '<url><loc>' . u('/o/' . $r['id'] . '/') . '</loc><lastmod>' . h($r['latest']) . '</lastmod></url>'; }
    echo '</urlset>'; exit;
}
if ($path === '/llms.txt') {
    header('Content-Type: text/plain; charset=UTF-8');
    $n = q('SELECT COUNT(*) c, COUNT(DISTINCT org_id) o FROM cases')[0];
    echo "# " . NAME . "\n\n> 中小企業庁「官公需情報ポータルサイト」の検索APIで集めた入札公告（直近120日・{$n['c']}件・発注機関{$n['o']}）を、発注機関ごと・都道府県ごとに一覧にし、キーワードで探せるサイト。各案件は元の公告へリンクする。ポータルに登録された公告だけで、発注機関の公告のすべてではない。株式会社エクスブリッジ製。\n\n## ページ\n- 検索: " . u('/') . "\n- 都道府県ごと: " . u('/p/23/') . "（愛知県）など\n- 発注機関ごと: " . u('/o/<id>/') . "\n- データについて: " . u('/about') . "\n";
    exit;
}
$seg = array_values(array_filter(explode('/', $path), fn($x) => $x !== ''));

if (($seg[0] ?? '') === 'o' && isset($seg[1]) && preg_match('/^[0-9a-f]{10}$/', $seg[1])) {
    $o = q('SELECT * FROM orgs WHERE id=?', [$seg[1]]);
    if (!$o) { http_response_code(404); page('見つかりません｜' . NAME, '', BASE . '/', '<h1>見つかりません</h1>', [], [], true); exit; }
    $o = $o[0]; $url = u('/o/' . $o['id'] . '/'); $pn = PREFS[pc((string)$o['pref_code'])] ?? '';
    $rows = q('SELECT * FROM cases WHERE org_id=? ORDER BY issue_date DESC, name LIMIT 300', [$o['id']]);
    $cat = [];
    foreach ($rows as $r) { if ($r['category']) { $cat[$r['category']] = ($cat[$r['category']] ?? 0) + 1; } }
    $title = $o['name'] . 'の入札情報・入札公告一覧（直近' . $o['n'] . '件）';
    $desc = $o['name'] . 'が官公需情報ポータルサイトに出した入札公告' . $o['n'] . '件（最新 ' . jd($o['latest']) . '）を新しい順に一覧。件名・公告日・分類と、元の公告へのリンク。';
    $body = '<h1>' . h($o['name']) . 'の入札公告</h1><p class="lead">' . h($pn) . '・直近120日に官公需情報ポータルサイトへ登録された入札公告 <b>' . (int)$o['n'] . '件</b>（最新 ' . jd($o['latest']) . '）' . ($cat ? '。分類：' . h(implode('・', array_map(fn($k, $v) => "{$k} {$v}件", array_keys($cat), $cat))) : '') . '</p>';
    $body .= cases_html($rows, false);
    $sib = q('SELECT id, name, n FROM orgs WHERE pref_code=? AND id<>? ORDER BY n DESC LIMIT 18', [$o['pref_code'], $o['id']]);
    if ($sib) { $body .= '<h2>' . h($pn) . 'のほかの発注機関</h2><div class="grid">' . implode('', array_map(fn($s) => '<a href="' . u('/o/' . $s['id'] . '/') . '"><b>' . h($s['name']) . '</b><small>' . (int)$s['n'] . '件</small></a>', $sib)) . '<a href="' . u('/p/' . pc((string)$o['pref_code']) . '/') . '"><b>' . h($pn) . 'の一覧</b><small>発注機関と新着</small></a></div>'; }
    $body .= '<p class="note">ここに載るのは官公需情報ポータルサイトに登録された公告だけです。' . h($o['name']) . 'の公告のすべてではないので、最新の公告は発注機関の入札情報のページでも確認してください。</p>';
    page($title, $desc, $url, $body, [], [[$pn, u('/p/' . pc((string)$o['pref_code']) . '/')], [$o['name'], $url]]);
    exit;
}

if (($seg[0] ?? '') === 'p' && isset($seg[1]) && isset(PREFS[$seg[1]])) {
    $c = pc($seg[1]); $n = PREFS[$c]; $url = u('/p/' . $c . '/');
    $orgs = q('SELECT id, name, n, latest FROM orgs WHERE pref_code=? ORDER BY n DESC', [$c]);
    $tot = array_sum(array_column($orgs, 'n'));
    $title = $n . 'の入札情報・入札公告一覧｜発注機関' . count($orgs) . '・直近' . $tot . '件';
    $desc = $n . 'の国・自治体・独立行政法人などの入札公告' . $tot . '件を、発注機関' . count($orgs) . 'ごとに一覧。官公需情報ポータルサイトの公告から、件名・公告日・分類と元の公告へのリンク。';
    $body = '<h1>' . h($n) . 'の入札公告</h1><p class="lead">直近120日に官公需情報ポータルサイトへ登録された' . h($n) . 'の入札公告 <b>' . $tot . '件</b>、発注機関 <b>' . count($orgs) . '</b>。</p>' . search_form('', $c);
    $body .= '<h2>発注機関（公告の多い順）</h2><div class="tbl"><table><tr><th>発注機関</th><th>件数</th><th>最新の公告</th></tr>';
    foreach ($orgs as $o) { $body .= '<tr><td><a href="' . u('/o/' . $o['id'] . '/') . '">' . h($o['name']) . '</a></td><td class="n">' . (int)$o['n'] . '</td><td class="n">' . jd($o['latest']) . '</td></tr>'; }
    $body .= '</table></div><h2>新着の入札公告</h2>' . cases_html(q('SELECT * FROM cases WHERE pref_code=? ORDER BY issue_date DESC, org LIMIT 40', [$c]));
    page($title, $desc, $url, $body, [], [[$n, $url]]);
    exit;
}

if (($seg[0] ?? '') === 'about') {
    $url = u('/about'); $m = q('SELECT COUNT(*) c, COUNT(DISTINCT org_id) o, MIN(issue_date) a, MAX(issue_date) b FROM cases')[0];
    $body = '<h1>データについて</h1><div class="panel"><p>出典は中小企業庁「<a href="' . KKJ . '" rel="noopener">官公需情報ポータルサイト</a>」の検索APIです。国の機関・独立行政法人・地方公共団体などが公開した入札公告を、ポータルが集めたものを毎日取り込んでいます。</p>'
        . '<p>いま載っているのは、公告日 ' . jd($m['a']) . '〜' . jd($m['b']) . ' の ' . (int)$m['c'] . '件・発注機関 ' . (int)$m['o'] . 'です（直近120日）。最終取り込み：' . h(str_replace('T', ' ', meta('collected_at'))) . '</p></div>'
        . '<h2>載っていないもの</h2><ul><li>官公需情報ポータルサイトに登録されていない公告（たとえば名古屋市の公告は、いまのところポータルに載っていません）</li><li>入札の締切・開札日（ポータルのデータにほとんど入っていないため）。期限は元の公告で確認してください</li><li>入札結果（落札者・落札額）</li></ul>'
        . '<h2>自社で使う</h2><p>キーワード・地域・分類に合う新しい公告を毎朝知らせる仕組みを、自社のサーバーに置く形で作れます（バイブカスタマイズ）。一式を自社サイトに置く形は<a href="' . BUY . '&amp;ref=knyusatsu-about">導入版（商品ページ）</a>。</p>';
    page('データについて｜' . NAME, '官公需情報ポータルサイトの検索APIで集めた入札公告の範囲（直近120日）と、載っていないもの（ポータル未登録の公告・締切日・入札結果）。', $url, $body, [], [['データについて', $url]]);
    exit;
}

if ($seg) { http_response_code(404); page('見つかりません｜' . NAME, '', BASE . '/', '<h1>見つかりません</h1><p><a class="btn" href="' . u('/') . '">入口へ戻る</a></p>', [], [], true); exit; }

// トップ（検索）
$qv = trim((string)($_GET['q'] ?? '')); $pref = (string)($_GET['pref'] ?? ''); $cat = (string)($_GET['cat'] ?? '');
if ($pref !== '' && !isset(PREFS[$pref])) { $pref = ''; }
if (!in_array($cat, CATS, true)) { $cat = ''; }
$searching = $qv !== '' || $pref !== '' || $cat !== '';
$w = []; $a = [];
foreach (preg_split('/[\s　]+/u', $qv, -1, PREG_SPLIT_NO_EMPTY) as $t) { $w[] = '(name LIKE ? OR org LIKE ? OR summary LIKE ?)'; array_push($a, "%$t%", "%$t%", "%$t%"); }
if ($pref !== '') { $w[] = 'pref_code=?'; $a[] = $pref; }
if ($cat !== '') { $w[] = 'category=?'; $a[] = $cat; }
$rows = q('SELECT * FROM cases' . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY issue_date DESC, org LIMIT 60', $a);
$cnt = $searching ? (int)q('SELECT COUNT(*) c FROM cases' . ($w ? ' WHERE ' . implode(' AND ', $w) : ''), $a)[0]['c'] : 0;
$m = q('SELECT COUNT(*) c, COUNT(DISTINCT org_id) o FROM cases')[0];
$title = $searching ? '「' . trim($qv . ' ' . (PREFS[$pref] ?? '') . ' ' . $cat) . '」の入札公告｜' . NAME : '入札情報を発注機関ごとに一覧・検索｜国・自治体の入札公告' . number_format((int)$m['c']) . '件';
$desc = '国・自治体・独立行政法人の入札公告' . number_format((int)$m['c']) . '件（直近120日・発注機関' . (int)$m['o'] . '）を、発注機関ごと・都道府県ごとに一覧し、キーワードと分類で探せます。中小企業庁「官公需情報ポータルサイト」のデータ。';
$body = '<h1>' . ($searching ? h(trim($qv . ' ' . (PREFS[$pref] ?? '') . ' ' . $cat)) . 'の入札公告 ' . number_format($cnt) . '件' : '国・自治体の入札公告を<br>発注機関ごとに探す') . '</h1>';
if (!$searching) { $body .= '<p class="lead">直近120日に官公需情報ポータルサイトへ登録された入札公告 <b>' . number_format((int)$m['c']) . '件</b>、発注機関 <b>' . number_format((int)$m['o']) . '</b>。件名・機関名・本文のことばと、都道府県・分類で探せます。</p>'; }
$body .= search_form($qv, $pref, $cat);
$body .= '<h2>' . ($searching ? '新しい順（最大60件）' : '新着の入札公告') . '</h2>' . cases_html($rows);
if (!$searching) {
    $g = '';
    foreach (q('SELECT pref_code, COUNT(*) n FROM cases GROUP BY pref_code') as $r) { $pcd = pc($r['pref_code']); $g .= '<a href="' . u('/p/' . $pcd . '/') . '"><b>' . h(PREFS[$pcd] ?? $pcd) . '</b><small>' . (int)$r['n'] . '件</small></a>'; }
    $body .= '<h2 id="pref">都道府県から探す</h2><div class="grid">' . $g . '</div>';
    $body .= '<h2>新しい公告を毎朝知らせる仕組み</h2><p>キーワード・地域・分類に合う新しい入札公告だけを毎朝知らせる仕組みを、自社のサーバーに置く形で作れます（バイブカスタマイズ）。一式を自社サイトに置く形は<a href="' . BUY . '&amp;ref=knyusatsu-top">導入版（商品ページ）</a>。</p>';
}
page($title, $desc, $searching ? BASE . '/' : BASE . '/', $body, [], [], $searching);
