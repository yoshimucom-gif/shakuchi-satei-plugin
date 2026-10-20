<?php
/**
 * 土地の単価（国交省 不動産情報ライブラリ XIT001）と目安額の計算。
 *
 * 取得・集計は「借地権の計算ツール」（shakuchi-tools）の skt_land_* と同じ規則にしている:
 *   - 直近8四半期の「宅地(土地)」の取引だけを使う
 *   - 単価は UnitPrice、無ければ TradePrice÷Area（Area=9999 は打ち切り値なので使わない）
 *   - 地区（町名）の事例が5件以上なら地区、足りなければ市区町村全体。絞り込めなかったことは必ず伝える
 *   - 結果は12時間キャッシュ。キャッシュキーにAPIキーの指紋を含める（キーを替えても古い値を配らない）
 *   - APIキーが無いときにダミーの事例へ落とさない（架空の事例を「周辺の取引」と見せない）
 */

if (!defined('ABSPATH')) exit;

define('SSA_ENDPOINT', 'https://www.reinfolib.mlit.go.jp/ex-api/external/XIT001');

/* 借地権割合（国税庁 路線価図の記号）。unknown は東京の住宅地に多い60〜70%の幅で計算する */
function ssa_ratio_options() {
    return array(
        'A' => array('A（90%）', 0.9, 0.9),
        'B' => array('B（80%）', 0.8, 0.8),
        'C' => array('C（70%）', 0.7, 0.7),
        'D' => array('D（60%）', 0.6, 0.6),
        'E' => array('E（50%）', 0.5, 0.5),
        'F' => array('F（40%）', 0.4, 0.4),
        'G' => array('G（30%）', 0.3, 0.3),
        'unknown' => array('わからない（60〜70%で計算）', 0.6, 0.7),
    );
}

function ssa_jp_data() {
    static $d = null;
    if ($d === null) {
        $d = @include __DIR__ . '/jp-cities.php';
        if (!is_array($d) || empty($d['prefs'])) $d = array('prefs' => array(), 'cities' => array());
    }
    return $d;
}

/* 実在する市区町村コードだけ通す（キャッシュキーの汚染と外部APIの濫用を防ぐ） */
function ssa_city_name($code) {
    if ($code === '' || !ctype_digit($code) || strlen($code) !== 5) return '';
    $d = ssa_jp_data();
    $pref = substr($code, 0, 2);
    foreach (isset($d['cities'][$pref]) ? $d['cities'][$pref] : array() as $c) {
        if ((string) $c[0] === $code) return (string) $c[1];
    }
    return '';
}

function ssa_pref_name($code) {
    $d = ssa_jp_data();
    return isset($d['prefs'][$code]) ? (string) $d['prefs'][$code] : '';
}

function ssa_to_int($s) {
    if ($s === null || $s === '') return null;
    if (preg_match('/\d+/', str_replace(',', '', (string) $s), $m)) return intval($m[0]);
    return null;
}

function ssa_unit_price($rec) {
    $up = ssa_to_int(isset($rec['UnitPrice']) ? $rec['UnitPrice'] : '');
    if ($up) return floatval($up);
    $area = ssa_to_int(isset($rec['Area']) ? $rec['Area'] : '');
    if ($area !== null && $area >= 9999) return null;
    $price = ssa_to_int(isset($rec['TradePrice']) ? $rec['TradePrice'] : '');
    if ($price && $area && $area > 0) return $price / $area;
    return null;
}

function ssa_percentile($sorted, $p) {
    $n = count($sorted);
    if ($n === 0) return 0;
    if ($n === 1) return $sorted[0];
    $rank = $p * ($n - 1);
    $lo = (int) floor($rank); $hi = (int) ceil($rank);
    if ($lo === $hi) return $sorted[$lo];
    return $sorted[$lo] + ($rank - $lo) * ($sorted[$hi] - $sorted[$lo]);
}

/** 1市区町村×1四半期。該当なしは仕様上404なので空配列として扱う */
function ssa_api_fetch($city, $year, $quarter) {
    $res = wp_remote_get(SSA_ENDPOINT . '?' . http_build_query(array(
        'year' => (int) $year, 'quarter' => (int) $quarter, 'city' => $city,
    )), array(
        'timeout' => 15,
        'headers' => array('Ocp-Apim-Subscription-Key' => ssa_api_key()),
    ));
    if (is_wp_error($res)) return null;
    $code = (int) wp_remote_retrieve_response_code($res);
    if ($code === 404) return array();
    if ($code !== 200) return null;
    $json = json_decode(wp_remote_retrieve_body($res), true);
    return (is_array($json) && isset($json['data']) && is_array($json['data'])) ? $json['data'] : array();
}

function ssa_land_records($city) {
    $latest = intval(current_time('Y')) - 1;   // 前年の4Qから遡る（当年分は公開が遅れる）
    $ck = 'ssa_land_' . substr(md5(ssa_api_key()), 0, 8) . '_' . $city . '_' . $latest;
    $cached = get_transient($ck);
    if (is_array($cached)) return $cached;

    $rows = array(); $failed = 0;
    $y = $latest; $q = 4;
    for ($i = 0; $i < 8; $i++) {
        $part = ssa_api_fetch($city, $y, $q);
        if ($part === null) { $failed++; }
        else {
            foreach ($part as $r) {
                if ((isset($r['Type']) ? $r['Type'] : '') === '宅地(土地)') {
                    $rows[] = array('d' => trim(isset($r['DistrictName']) ? $r['DistrictName'] : ''), 'u' => ssa_unit_price($r));
                }
            }
        }
        if (--$q === 0) { $q = 4; $y--; }
    }
    $out = array('ok' => $failed < 8, 'rows' => $rows, 'from' => ($latest - 1) . '年1月', 'to' => $latest . '年12月');
    if ($out['ok']) set_transient($ck, $out, 12 * HOUR_IN_SECONDS);   // 全滅（キー不正・API停止）はキャッシュしない
    return $out;
}

/** 町名の候補（取引事例のある地区名を件数の多い順） */
function ssa_districts($city) {
    $rec = ssa_land_records($city);
    if (!$rec['ok']) return array();
    $counts = array();
    foreach ($rec['rows'] as $r) {
        if ($r['d'] === '' || !$r['u']) continue;
        $counts[$r['d']] = (isset($counts[$r['d']]) ? $counts[$r['d']] : 0) + 1;
    }
    arsort($counts);
    return array_keys($counts);
}

/**
 * 町名の入力（例「豪徳寺2丁目」「豪徳寺」）を取引データの地区名（例「豪徳寺」）に寄せる。
 * 丁目・番地・数字を落として前方一致で探す。見つからなければ空。
 */
function ssa_match_district($city, $town, $list = null) {
    if ($list === null) $list = ssa_districts($city);
    // 「2丁目」「２－３」「二丁目」以降を落とす。「一番町」のような町名の漢数字は残す
    $t = preg_replace('/([0-9０-９]+|[一二三四五六七八九十]+丁目).*$/u', '', (string) $town);
    $t = trim(preg_replace('/\s+/u', '', $t));
    if ($t === '') return '';
    foreach ($list as $d) {
        if ($d === $t) return $d;
    }
    foreach ($list as $d) {
        if ($d !== '' && (strpos($t, $d) === 0 || strpos($d, $t) === 0)) return $d;
    }
    return '';
}

function ssa_land_stats($city, $district) {
    $rec = ssa_land_records($city);
    if (!$rec['ok']) return array('ok' => false, 'reason' => '国の取引データに接続できませんでした。');
    $scope = 'city'; $warn = '';
    $pool = $rec['rows'];
    if ($district !== '') {
        $dp = array_values(array_filter($pool, function ($r) use ($district) { return $r['d'] === $district; }));
        if (count($dp) >= 5) { $pool = $dp; $scope = 'district'; }
        else $warn = sprintf('「%s」の土地の取引が5件未満のため、市区町村全体の取引で計算しています。', $district);
    }
    $units = array();
    foreach ($pool as $r) if ($r['u'] && $r['u'] > 0) $units[] = $r['u'];
    if (count($units) < 5) return array('ok' => false, 'reason' => 'この地域は土地の取引事例が5件未満のため、自動では計算できません。');
    sort($units);
    return array(
        'ok' => true, 'n' => count($units),
        'p25' => (int) round(ssa_percentile($units, 0.25)),
        'med' => (int) round(ssa_percentile($units, 0.5)),
        'p75' => (int) round(ssa_percentile($units, 0.75)),
        'scope' => $scope, 'district' => $scope === 'district' ? $district : '',
        'warn' => $warn, 'period' => $rec['from'] . '〜' . $rec['to'],
    );
}

/** 10万円単位に切り下げる（目安なので細かい端数を見せない） */
function ssa_round_lakh($yen) {
    // 先に円で丸める。1億3,000万×0.7×0.7 が浮動小数で 63,699,999.99… になり、6,360万円に落ちるのを防ぐ
    return (int) (floor(round($yen) / 100000) * 100000);
}

/**
 * 目安額＝更地価格（取引事例の㎡単価の中央値×面積）×借地権割合×掛け率。
 * 借地権割合が「わからない」ときは60〜70%の幅で出す（下限と上限が分かれる）。
 */
function ssa_estimate($med, $area, $ratio_key, $coef) {
    $opts = ssa_ratio_options();
    if (!isset($opts[$ratio_key]) || $med <= 0 || $area <= 0 || $coef <= 0) return null;
    $sar = $med * $area;
    $r = $opts[$ratio_key];
    return array(
        'sarachi' => ssa_round_lakh($sar),
        'ratio_lo' => $r[1], 'ratio_hi' => $r[2], 'ratio_label' => $r[0],
        'coef' => $coef,
        'lo' => ssa_round_lakh($sar * $r[1] * $coef),
        'hi' => ssa_round_lakh($sar * $r[2] * $coef),
    );
}

/** 円 → 「5,880万円」「1億2,000万円」 */
function ssa_yen($yen) {
    $man = (int) round($yen / 10000);
    if ($man >= 10000) {
        $oku = intdiv($man, 10000); $rest = $man % 10000;
        return $oku . '億' . ($rest ? number_format($rest) . '万' : '') . '円';
    }
    return number_format($man) . '万円';
}

function ssa_yen_range($lo, $hi) {
    return $lo === $hi ? ssa_yen($lo) : ssa_yen($lo) . '〜' . ssa_yen($hi);
}
