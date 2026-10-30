<?php
/**
 * Plugin Name: 借地権の目安査定
 * Description: 所在地・土地の面積・借地権割合と連絡先を受け付け、その場で借地権の売却価格の目安を返すフォームです。目安額＝土地の取引価格の中央値（国交省 不動産情報ライブラリ）×面積×借地権割合×掛け率。申し込みは管理画面に保存し、担当者へ通知、申込者へ目安額つきの受付メールを送ります。ショートコード [shakuchi_satei]。
 * Version: 1.1.9
 * Author: ミカタ株式会社
 * License: GPLv2 or later
 * Text Domain: shakuchi-satei
 *
 * ★設計上の決めごと
 *   - 表記は「売却価格の目安（参考価格）」。運営のミカタ株式会社は宅地建物取引業者ではないため、
 *     自動計算の結果を「査定額」と呼ばない。正式な査定は設定の「査定する会社」（監修の宅建業者）が行う。
 *   - 目安額＝更地価格×借地権割合×掛け率（既定70%）。借地権割合が「わからない」は60〜70%の幅で出す。
 *   - 土地の取引データが取れないときも、申し込みは必ず受け付けて保存・通知する（目安が出ないだけ）。
 *   - 濫用対策は fudosan-honki（本気査定）と同じ: ハニーポット・経過時間・IP/メールごとの回数制限・
 *     リンクや他言語の文字の混入判定。弾いた理由はボットに教えない。
 *   - 自動更新は1.0.0から同梱（GitHubの raw に置いた update.json を見る）。
 */

if (!defined('ABSPATH')) exit;

define('SSA_VER', '1.1.9');
define('SSA_DB_VER', '1');
define('SSA_OPT', 'ssa_options');
define('SSA_DIR', plugin_dir_path(__FILE__));
define('SSA_URL', plugin_dir_url(__FILE__));
define('SSA_UPDATE_URL', 'https://raw.githubusercontent.com/yoshimucom-gif/shakuchi-satei-plugin/main/update.json');

/* 更新チェッカー。is_admin() だけで囲わないこと（WP-Cron は管理画面外で走る） */
if (is_admin() || (defined('DOING_CRON') && DOING_CRON) || (defined('WP_CLI') && WP_CLI)) {
    require_once __DIR__ . '/includes/plugin-updater.php';
    new SSA_Satei_Updater(__FILE__, SSA_UPDATE_URL);
}

require_once __DIR__ . '/includes/land.php';
require_once __DIR__ . '/includes/staging-links.php';
require_once __DIR__ . '/includes/temple-eyecatch.php';
require_once __DIR__ . '/includes/sticky-cta.php';

/* =========================================================================
 * 1. 設定
 * ======================================================================= */

function ssa_defaults() {
    return array(
        'api_key'      => '',
        'coef'         => '70',
        'notify_email' => '',
        'from_email'   => '',
        'site_name'    => '',
        'company'      => '株式会社グランクルー（宅地建物取引業 東京都知事免許(2)101165）',
        'tel'          => '03-5860-8391',
        'tel_hours'    => '平日9:00〜18:00',
        'privacy_url'  => '/privacy-policy/',
        'accent'       => '#314a35',
        'button'       => '#8a6f12',
        'autoreply'    => '1',
        'notify_on'    => '1',
        // 申込者に目安額を見せるか。'' にすると「査定依頼だけを受けるフォーム」になる（目安額は担当者あての通知にだけ載る）
        'show_estimate' => '1',
    );
}

function ssa_opt($key = null) {
    $o = wp_parse_args((array) get_option(SSA_OPT, array()), ssa_defaults());
    return $key === null ? $o : (isset($o[$key]) ? $o[$key] : '');
}

function ssa_site_name() {
    $n = trim((string) ssa_opt('site_name'));
    return $n !== '' ? $n : (string) get_option('blogname');
}

/** 掛け率（割合）。設定は％で持つ。0〜200%に収める */
function ssa_coef() {
    $c = (float) str_replace(',', '.', (string) ssa_opt('coef'));
    if ($c <= 0 || $c > 200) $c = 70;
    return $c / 100;
}

/**
 * 国交省APIのキー。空なら、同じサイトの「借地権の計算ツール」「相場データ」のキーを使う（二重に入れずに済むように）。
 */
function ssa_api_key() {
    $k = trim((string) ssa_opt('api_key'));
    if ($k !== '') return $k;
    foreach (array('skt_options', 'fsb_options') as $o) {
        $v = get_option($o);
        if (is_array($v) && !empty($v['api_key'])) return trim((string) $v['api_key']);
    }
    return '';
}

function ssa_sanitize_email_list($v) {
    $out = array();
    foreach (preg_split('/[\s,;、，]+/u', (string) $v) as $e) {
        $e = sanitize_email($e);
        if ($e !== '' && is_email($e)) $out[] = $e;
    }
    return implode(',', array_unique($out));
}

function ssa_notify_recipients() {
    $raw = ssa_opt('notify_email');
    if (trim($raw) === '') $raw = get_option('admin_email');
    return array_values(array_filter(explode(',', ssa_sanitize_email_list($raw))));
}

function ssa_sanitize_options($in) {
    $d = ssa_defaults();
    $in = is_array($in) ? $in : array();
    $out = array();
    $out['api_key']      = isset($in['api_key']) ? preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $in['api_key']) : '';
    $c = isset($in['coef']) ? (float) str_replace(',', '.', (string) $in['coef']) : 70;
    $out['coef']         = (string) (($c > 0 && $c <= 200) ? $c : 70);
    $out['notify_email'] = ssa_sanitize_email_list(isset($in['notify_email']) ? $in['notify_email'] : '');
    $fe = sanitize_email(isset($in['from_email']) ? $in['from_email'] : '');
    $out['from_email']   = is_email($fe) ? $fe : '';
    foreach (array('site_name', 'company', 'tel', 'tel_hours') as $k) {
        $out[$k] = isset($in[$k]) ? sanitize_text_field($in[$k]) : $d[$k];
    }
    $out['privacy_url']  = isset($in['privacy_url']) ? esc_url_raw($in['privacy_url']) : $d['privacy_url'];
    foreach (array('accent', 'button') as $k) {
        $hex = sanitize_hex_color(isset($in[$k]) ? $in[$k] : '');
        $out[$k] = $hex ? $hex : $d[$k];
    }
    $out['autoreply'] = !empty($in['autoreply']) ? '1' : '';
    $out['notify_on'] = !empty($in['notify_on']) ? '1' : '';
    $out['show_estimate'] = !empty($in['show_estimate']) ? '1' : '';
    return $out;
}

/* =========================================================================
 * 2. 申し込みの保存先（専用テーブル）
 * ======================================================================= */

function ssa_table() {
    global $wpdb;
    return $wpdb->prefix . 'shakuchi_satei_leads';
}

register_activation_hook(__FILE__, 'ssa_install');
function ssa_install() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $t = ssa_table();
    $cs = $wpdb->get_charset_collate();
    dbDelta("CREATE TABLE $t (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created_at datetime NOT NULL,
  name varchar(100) NOT NULL DEFAULT '',
  tel varchar(30) NOT NULL DEFAULT '',
  email varchar(191) NOT NULL DEFAULT '',
  pref varchar(10) NOT NULL DEFAULT '',
  city varchar(60) NOT NULL DEFAULT '',
  town varchar(100) NOT NULL DEFAULT '',
  area_m2 varchar(20) NOT NULL DEFAULT '',
  ratio varchar(40) NOT NULL DEFAULT '',
  jinushi varchar(20) NOT NULL DEFAULT '',
  kibou varchar(40) NOT NULL DEFAULT '',
  note text NOT NULL,
  est_lo bigint(20) NOT NULL DEFAULT 0,
  est_hi bigint(20) NOT NULL DEFAULT 0,
  basis text NOT NULL,
  page_url varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY created_at (created_at)
) $cs;");
    update_option('ssa_db_ver', SSA_DB_VER);
}

/* 自動更新で入れ替わったときは有効化フックが走らないので、ここでテーブルを確かめる */
add_action('plugins_loaded', function () {
    if (get_option('ssa_db_ver') !== SSA_DB_VER) ssa_install();
});

/* =========================================================================
 * 3. 入力の選択肢
 * ======================================================================= */

function ssa_jinushi_options() { return array('お寺・神社', '個人', '会社', 'わからない'); }
function ssa_kibou_options()   { return array('売却の査定', 'まず目安を知りたい', '相続した借地の相談', '更新料・承諾料の相談'); }

function ssa_to_hankaku($s) {
    $s = (string) $s;
    if ($s === '') return $s;
    if (function_exists('mb_convert_kana')) $s = mb_convert_kana($s, 'a', 'UTF-8');
    return strtr($s, array('０'=>'0','１'=>'1','２'=>'2','３'=>'3','４'=>'4','５'=>'5','６'=>'6','７'=>'7','８'=>'8','９'=>'9',
        'ー'=>'-','－'=>'-','−'=>'-','．'=>'.','　'=>' ', '，'=>','));
}

function ssa_trim_len($s, $max) {
    $s = (string) $s;
    if ($s === '') return $s;
    if (function_exists('mb_substr')) return mb_substr($s, 0, $max);
    if (preg_match_all('/./us', $s, $m)) return implode('', array_slice($m[0], 0, $max));
    return substr($s, 0, $max);
}

function ssa_tel_valid($tel) {
    $d = preg_replace('/[^0-9]/', '', ssa_to_hankaku($tel));
    return ($d !== null && strlen($d) >= 9 && strlen($d) <= 11);
}

/* =========================================================================
 * 4. 濫用対策（fudosan-honki と同じ考え方）
 * ======================================================================= */

function ssa_client_ip() {
    foreach (array('HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR') as $h) {
        if (!empty($_SERVER[$h])) {
            $parts = explode(',', $_SERVER[$h]);
            $ip = trim($parts[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
}

function ssa_rate_ok($bucket, $id, $limit, $window) {
    if ($id === '') return true;
    $k = 'ssa_rl_' . $bucket . '_' . md5(strtolower($id));
    $n = (int) get_transient($k);
    if ($n >= $limit) return false;
    set_transient($k, $n + 1, $window);
    return true;
}

function ssa_spam_hit($values) {
    foreach ($values as $v) {
        $v = (string) $v;
        if (trim($v) === '') continue;
        if (preg_match('#https?://|www\.[a-z0-9-]+\.|\[url|\[link|</?a\s#iu', $v)) return true;
        if (preg_match('/[\x{0400}-\x{04FF}\x{0600}-\x{06FF}\x{0E00}-\x{0E7F}]/u', $v)) return true;
    }
    return false;
}

function ssa_csv_safe($s) {
    $s = (string) $s;
    if ($s === '' || is_numeric($s)) return $s;
    return (strpos("=+-@\t\r", $s[0]) !== false) ? "'" . $s : $s;
}

/* =========================================================================
 * 5. 公開エンドポイント（市区町村・町名の候補）
 * ======================================================================= */

add_action('rest_api_init', function () {
    register_rest_route('ssa/v1', '/cities', array(
        'methods' => 'GET', 'permission_callback' => '__return_true',
        'callback' => function ($req) {
            $pref = preg_replace('/\D/', '', (string) $req->get_param('pref'));
            $d = ssa_jp_data();
            return rest_ensure_response(isset($d['cities'][$pref]) ? $d['cities'][$pref] : array());
        },
    ));
    register_rest_route('ssa/v1', '/towns', array(
        'methods' => 'GET', 'permission_callback' => '__return_true',
        'callback' => function ($req) {
            $city = preg_replace('/\D/', '', (string) $req->get_param('city'));
            if (ssa_api_key() === '' || ssa_city_name($city) === '') return rest_ensure_response(array());
            if (!ssa_rate_ok('towns', ssa_client_ip(), 60, 10 * MINUTE_IN_SECONDS)) return rest_ensure_response(array());
            return rest_ensure_response(array_slice(ssa_districts($city), 0, 300));
        },
    ));
});

/* =========================================================================
 * 6. 申し込みの受付（admin-ajax）
 * ======================================================================= */

add_action('wp_ajax_ssa_nonce', 'ssa_ajax_nonce');
add_action('wp_ajax_nopriv_ssa_nonce', 'ssa_ajax_nonce');
function ssa_ajax_nonce() {
    // ページキャッシュに古い nonce が残らないよう、送信の直前に取りに来させる
    if (!headers_sent()) {
        header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
        header('Pragma: no-cache');
    }
    wp_send_json(array('nonce' => wp_create_nonce('shakuchi_satei')));
}

function ssa_fail($errors) {
    wp_send_json(array('ok' => false, 'errors' => (array) $errors));
}

add_action('wp_ajax_ssa_submit', 'ssa_submit');
add_action('wp_ajax_nopriv_ssa_submit', 'ssa_submit');
function ssa_submit() {
    check_ajax_referer('shakuchi_satei', 'nonce');

    if (!empty($_POST['ssa_website'])) ssa_fail('送信を受け付けられませんでした。');
    if ((isset($_POST['ssa_elapsed']) ? intval($_POST['ssa_elapsed']) : 0) < 3000) ssa_fail('入力が早すぎます。もう一度お試しください。');

    $p = function ($k) { return isset($_POST[$k]) ? wp_unslash($_POST[$k]) : ''; };
    $pref_code = preg_replace('/\D/', '', $p('pref'));
    $city_code = preg_replace('/\D/', '', $p('city'));
    $town  = ssa_trim_len(sanitize_text_field($p('town')), 100);
    $area_raw = trim(ssa_to_hankaku(sanitize_text_field($p('area'))));
    $unit  = $p('unit') === 'tsubo' ? 'tsubo' : 'm2';
    $ratio = sanitize_text_field($p('ratio'));
    $jinushi = sanitize_text_field($p('jinushi'));
    $kibou = sanitize_text_field($p('kibou'));
    $name  = ssa_trim_len(sanitize_text_field($p('name')), 100);
    $tel   = trim(ssa_to_hankaku(sanitize_text_field($p('tel'))));
    $email = sanitize_email($p('email'));
    $note  = ssa_trim_len(sanitize_textarea_field($p('note')), 2000);
    $page  = esc_url_raw($p('page_url'));

    $errors = array();
    $pref = ssa_pref_name($pref_code);
    $city = (substr($city_code, 0, 2) === $pref_code) ? ssa_city_name($city_code) : '';
    if ($pref === '') $errors[] = '都道府県を選んでください。';
    elseif ($city === '') $errors[] = '市区町村を選んでください。';
    $area = str_replace(',', '', $area_raw);
    if ($area === '' || !is_numeric($area) || (float) $area <= 0 || (float) $area > 100000) $errors[] = '土地の面積を数字で入力してください。';
    $area_m2 = ($area !== '' && is_numeric($area)) ? round((float) $area * ($unit === 'tsubo' ? 3.305785 : 1), 2) : 0;
    if (!isset(ssa_ratio_options()[$ratio])) $errors[] = '借地権割合を選んでください（わからなければ「わからない」）。';
    if (!in_array($jinushi, ssa_jinushi_options(), true)) $errors[] = '地主を選んでください。';
    if (!in_array($kibou, ssa_kibou_options(), true)) $errors[] = 'ご希望を選んでください。';
    if ($name === '') $errors[] = 'お名前を入力してください。';
    if (!ssa_tel_valid($tel)) $errors[] = '電話番号の形式が正しくありません（例：090-1234-5678）。';
    if (!is_email($email)) $errors[] = 'メールアドレスの形式が正しくありません。';
    if (empty($_POST['agree'])) $errors[] = '個人情報の取り扱いへの同意が必要です。';
    if ($errors) ssa_fail($errors);

    if (ssa_spam_hit(array($town, $name, $note))) ssa_fail('送信を受け付けられませんでした。');
    // 入力が正しいときだけ回数を数える（入力ミスの方を先に締め出さない）
    if (!ssa_rate_ok('ip', ssa_client_ip(), 5, HOUR_IN_SECONDS)) ssa_fail('送信が集中しています。しばらく時間をおいてからお試しください。');
    if (!ssa_rate_ok('email', $email, 3, DAY_IN_SECONDS)) ssa_fail('このメールアドレスでのお申し込みが続いています。24時間ほどおいてからお試しください。');

    /* 目安額。取れなくても申し込みは受け付ける */
    $est = null; $basis = array(); $reason = '';
    if (ssa_api_key() === '') {
        $reason = '自動計算の設定がまだのため、目安は担当者からお伝えします。';
    } else {
        $district = ssa_match_district($city_code, $town);
        $st = ssa_land_stats($city_code, $district);
        if (empty($st['ok'])) {
            $reason = $st['reason'] . ' 目安は担当者からお伝えします。';
        } else {
            $est = ssa_estimate($st['med'], $area_m2, $ratio, ssa_coef());
            $basis = array(
                'scope'  => $st['scope'] === 'district' ? $city . $st['district'] : $city . '全体',
                'n'      => $st['n'], 'period' => $st['period'],
                'med'    => $st['med'], 'p25' => $st['p25'], 'p75' => $st['p75'],
                'warn'   => $st['warn'],
            );
        }
    }

    global $wpdb;
    $basis_text = $est ? sprintf("土地の取引価格：%s（%s・%d件の㎡単価の中央値 %s円/㎡）\n更地価格の目安：%s\n借地権割合：%s\n掛け率：%s%%",
        $basis['scope'], $basis['period'], $basis['n'], number_format($basis['med']), ssa_yen($est['sarachi']),
        $est['ratio_label'], rtrim(rtrim(number_format($est['coef'] * 100, 1), '0'), '.')) . ($basis['warn'] ? "\n※" . $basis['warn'] : '')
        : '目安なし：' . $reason;
    $row = array(
        'created_at' => current_time('mysql'), 'name' => $name, 'tel' => ssa_trim_len($tel, 30), 'email' => ssa_trim_len($email, 191),
        'pref' => $pref, 'city' => $city, 'town' => $town, 'area_m2' => (string) $area_m2,
        'ratio' => ssa_ratio_options()[$ratio][0], 'jinushi' => $jinushi, 'kibou' => $kibou, 'note' => $note,
        'est_lo' => $est ? $est['lo'] : 0, 'est_hi' => $est ? $est['hi'] : 0, 'basis' => $basis_text,
        'page_url' => ssa_trim_len($page, 255),
    );
    $ins = $wpdb->insert(ssa_table(), $row);
    if ($ins === false) {
        ssa_install();
        $ins = $wpdb->insert(ssa_table(), $row);
    }
    if ($ins === false) {
        update_option('ssa_last_db_error', current_time('mysql') . ' ' . $wpdb->last_error, false);
        ssa_fail('申し訳ありません。ただいま受付処理でエラーが発生しました。お手数ですが時間をおいて再度お試しください。');
    }

    $ctx = array_merge($row, array('est' => $est, 'reason' => $reason, 'basis' => $basis, 'basis_text' => $basis_text));
    $site = ssa_site_name();
    $from = ssa_opt('from_email');
    $headers = array('Content-Type: text/plain; charset=UTF-8');
    if ($from) $headers[] = 'From: ' . $site . ' <' . $from . '>';

    $mail_ok = true;
    if (ssa_opt('autoreply')) {
        $subj_c = ssa_opt('show_estimate') ? '借地権の目安査定のお申し込みを受け付けました' : '借地権の無料査定のお申し込みを受け付けました';
        $mail_ok = wp_mail($email, '【' . $site . '】' . $subj_c, ssa_mail_customer($ctx), $headers);
    }
    if (ssa_opt('notify_on')) {
        $subj = '【借地権の目安査定】' . $name . '様 / ' . $pref . $city . $town . ($est ? ' / 目安 ' . ssa_yen_range($est['lo'], $est['hi']) : '');
        $h = $headers; $h[] = 'Reply-To: ' . $name . ' <' . $email . '>';
        foreach (ssa_notify_recipients() as $to) wp_mail($to, $subj, ssa_mail_admin($ctx), $h);   // 宛先どうしが見えないよう1通ずつ
    }

    if (!ssa_opt('show_estimate')) {   // 査定依頼だけのフォーム：申込者には目安を返さない
        wp_send_json(array('ok' => true, 'mode' => 'request', 'mail_ok' => (bool) $mail_ok, 'name' => $name, 'email' => $email,
            'estimate' => null, 'basis' => null, 'reason' => '', 'company' => ssa_opt('company')));
    }
    wp_send_json(array(
        'ok' => true, 'mail_ok' => (bool) $mail_ok, 'name' => $name, 'email' => $email,
        'estimate' => $est ? array(
            'text' => ssa_yen_range($est['lo'], $est['hi']),
            'sarachi' => ssa_yen($est['sarachi']),
            'ratio' => $est['ratio_label'],
            'coef' => rtrim(rtrim(number_format($est['coef'] * 100, 1), '0'), '.') . '%',
        ) : null,
        'basis' => $est ? array('scope' => $basis['scope'], 'n' => $basis['n'], 'period' => $basis['period'],
            'med' => number_format($basis['med']), 'warn' => $basis['warn']) : null,
        'reason' => $reason,
        'company' => ssa_opt('company'),
    ));
}

/* =========================================================================
 * 7. メール
 * ======================================================================= */

function ssa_disclaimer($ctx) {
    $src = $ctx['est'] ? sprintf('国土交通省「不動産情報ライブラリ」の土地の取引事例（%s・%s・%d件の㎡単価の中央値）',
        $ctx['basis']['scope'], $ctx['basis']['period'], $ctx['basis']['n']) : '土地の取引事例';
    return 'この金額は、' . $src . 'と、ご入力いただいた土地の面積・借地権割合から自動で計算した参考価格の目安です。'
        . '不動産鑑定評価や、宅地建物取引業者による価格査定ではありません。'
        . '借地契約の内容・建物の状態・地主のご意向などで実際の金額は変わります。';
}

function ssa_mail_customer($ctx) {
    $b  = $ctx['name'] . " 様\n\n";
    if (!ssa_opt('show_estimate')) {
        // 査定依頼だけのフォーム：受付メールに目安額は載せない
        $b .= ssa_site_name() . "です。借地権の無料査定のお申し込みを受け付けました。\n\n";
    } else {
        $b .= ssa_site_name() . "です。借地権の目安査定のお申し込みを受け付けました。\n\n";
        $b .= "■ 売却価格の目安\n";
        if ($ctx['est']) {
            $b .= ssa_yen_range($ctx['est']['lo'], $ctx['est']['hi']) . "\n";
            $b .= '（更地価格の目安 ' . ssa_yen($ctx['est']['sarachi']) . ' × 借地権割合 ' . $ctx['est']['ratio_label'] . ' × ' . rtrim(rtrim(number_format($ctx['est']['coef'] * 100, 1), '0'), '.') . "%）\n\n";
            $b .= ssa_disclaimer($ctx) . "\n\n";
        } else {
            $b .= $ctx['reason'] . "\n\n";
        }
    }
    $b .= "■ 正式な査定について\n";
    $b .= ssa_opt('company') . "が、借地契約の内容や地主との関係もうかがったうえで、無料で正式な査定をいたします。担当者からご連絡しますので、お待ちください。\n";
    if (ssa_opt('tel')) $b .= 'お急ぎの方はお電話でもお受けしています：' . ssa_opt('tel') . (ssa_opt('tel_hours') ? '（' . ssa_opt('tel_hours') . '）' : '') . "\n";
    $b .= "\n■ ご入力内容\n" . ssa_lines($ctx) . "\n\n-- \n" . ssa_site_name() . "\n" . home_url('/') . "\n";
    return $b;
}

function ssa_mail_admin($ctx) {
    $b  = ssa_opt('show_estimate') ? "借地権の目安査定に申し込みがありました。\n\n" : "借地権の無料査定に申し込みがありました。\n\n";
    $b .= (ssa_opt('show_estimate') ? "■ 目安（申込者にも表示・送信済み）\n" : "■ 目安（担当者用。申込者には見せていません）\n") . ($ctx['est'] ? ssa_yen_range($ctx['est']['lo'], $ctx['est']['hi']) . "\n" . $ctx['basis_text'] : $ctx['reason']) . "\n\n";
    $b .= "■ 申込内容\n" . ssa_lines($ctx) . "\n\n";
    if ($ctx['page_url']) $b .= "■ 申込ページ\n" . $ctx['page_url'] . "\n\n";
    $b .= "申込一覧：" . admin_url('admin.php?page=shakuchi-satei') . "\n";
    return $b;
}

function ssa_lines($ctx) {
    $l = array(
        'お名前' => $ctx['name'], '電話番号' => $ctx['tel'], 'メールアドレス' => $ctx['email'],
        '借地の所在地' => $ctx['pref'] . $ctx['city'] . $ctx['town'], '土地の面積' => $ctx['area_m2'] . '㎡',
        '借地権割合' => $ctx['ratio'], '地主' => $ctx['jinushi'], 'ご希望' => $ctx['kibou'], 'ご相談内容' => $ctx['note'],
    );
    $out = array();
    foreach ($l as $k => $v) if ((string) $v !== '') $out[] = $k . '：' . $v;
    return implode("\n", $out);
}

/* =========================================================================
 * 8. ショートコード [shakuchi_satei]
 * ======================================================================= */

add_action('wp_enqueue_scripts', function () {
    wp_register_style('ssa', SSA_URL . 'assets/ssa.css', array(), SSA_VER);
    wp_register_script('ssa', SSA_URL . 'assets/ssa.js', array(), SSA_VER, true);
});

add_shortcode('shakuchi_satei', 'ssa_shortcode');
function ssa_shortcode($atts = array()) {
    static $n = 0;
    $n++;
    $id = 'ssa-' . $n;
    wp_enqueue_style('ssa');
    wp_enqueue_script('ssa');
    wp_localize_script('ssa', 'SSA_CFG', array(
        'ajax' => admin_url('admin-ajax.php'),
        'rest' => esc_url_raw(rest_url('ssa/v1/')),
        'auto' => ssa_api_key() !== '',
    ));
    $d = ssa_jp_data();
    $o = ssa_opt();
    ob_start(); ?>
<div class="ssa" id="<?php echo esc_attr($id); ?>" style="--ssa-accent:<?php echo esc_attr($o['accent']); ?>;--ssa-button:<?php echo esc_attr($o['button']); ?>">
  <form class="ssa-form" novalidate>
    <div class="ssa-progress" aria-hidden="true"><div class="ssa-progress-bar"></div></div>
    <p class="ssa-progress-text"><span class="ssa-progress-now">1</span> / 6</p>

    <fieldset class="ssa-step" data-step="1">
      <legend class="ssa-q">借地はどこにありますか？</legend>
      <div class="ssa-addr">
        <select name="pref" required aria-label="都道府県"><option value="">都道府県</option>
          <?php foreach ($d['prefs'] as $c => $nm) : ?><option value="<?php echo esc_attr($c); ?>"<?php selected($c, '13'); ?>><?php echo esc_html($nm); ?></option><?php endforeach; ?>
        </select>
        <select name="city" required aria-label="市区町村"><option value="">市区町村</option></select>
        <input type="text" name="town" list="<?php echo esc_attr($id); ?>-towns" placeholder="町名（例：豪徳寺2丁目）※わかれば" autocomplete="off" aria-label="町名">
        <datalist id="<?php echo esc_attr($id); ?>-towns"></datalist>
      </div>
      <p class="ssa-hint">都道府県と市区町村は必須です。町名まで入れると、近くの取引事例で計算します。</p>
      <p class="ssa-errors" role="alert" hidden></p>
      <div class="ssa-actions"><button type="button" class="ssa-btn ssa-next">次へ</button></div>
    </fieldset>

    <fieldset class="ssa-step" data-step="2" hidden>
      <legend class="ssa-q">土地の広さはどのくらいですか？</legend>
      <div class="ssa-area"><input type="text" name="area" inputmode="decimal" placeholder="例：100" required aria-label="土地の面積">
        <select name="unit" aria-label="単位"><option value="m2">㎡</option><option value="tsubo">坪</option></select></div>
      <p class="ssa-hint">借地契約書や固定資産税の通知書（土地）に書かれています。だいたいの広さで大丈夫です。</p>
      <p class="ssa-errors" role="alert" hidden></p>
      <div class="ssa-actions"><button type="button" class="ssa-btn is-ghost ssa-back">戻る</button><button type="button" class="ssa-btn ssa-next">次へ</button></div>
    </fieldset>

    <fieldset class="ssa-step" data-step="3" data-auto="1" hidden>
      <legend class="ssa-q">借地権割合はわかりますか？</legend>
      <div class="ssa-tiles">
        <?php foreach (ssa_ratio_options() as $k => $r) : ?>
          <label class="ssa-tile<?php echo $k === 'unknown' ? ' is-wide' : ''; ?>"><input type="radio" name="ratio" value="<?php echo esc_attr($k); ?>"><span><?php echo esc_html($r[0]); ?></span></label>
        <?php endforeach; ?>
      </div>
      <p class="ssa-hint">国税庁の<a href="https://www.rosenka.nta.go.jp/" target="_blank" rel="noopener" class="ssa-ext">路線価図</a>で、土地の前の道路の数字の後ろにあるアルファベットです（例「350C」ならC）。わからなければ「わからない」を選んでください。</p>
      <p class="ssa-errors" role="alert" hidden></p>
      <div class="ssa-actions"><button type="button" class="ssa-btn is-ghost ssa-back">戻る</button></div>
    </fieldset>

    <fieldset class="ssa-step" data-step="4" data-auto="1" hidden>
      <legend class="ssa-q">借地の地主はどなたですか？</legend>
      <div class="ssa-tiles is-list">
        <?php foreach (ssa_jinushi_options() as $v) : ?>
          <label class="ssa-tile"><input type="radio" name="jinushi" value="<?php echo esc_attr($v); ?>"><span><?php echo esc_html($v); ?></span></label>
        <?php endforeach; ?>
      </div>
      <p class="ssa-errors" role="alert" hidden></p>
      <div class="ssa-actions"><button type="button" class="ssa-btn is-ghost ssa-back">戻る</button></div>
    </fieldset>

    <fieldset class="ssa-step" data-step="5" data-auto="1" hidden>
      <legend class="ssa-q">どのようにお考えですか？</legend>
      <div class="ssa-tiles is-list">
        <?php foreach (ssa_kibou_options() as $v) : ?>
          <label class="ssa-tile"><input type="radio" name="kibou" value="<?php echo esc_attr($v); ?>"><span><?php echo esc_html($v); ?></span></label>
        <?php endforeach; ?>
      </div>
      <p class="ssa-errors" role="alert" hidden></p>
      <div class="ssa-actions"><button type="button" class="ssa-btn is-ghost ssa-back">戻る</button></div>
    </fieldset>

    <fieldset class="ssa-step" data-step="6" hidden>
      <legend class="ssa-q">査定のご連絡先</legend>
      <p class="ssa-lead">送信すると、担当者から無料査定のご連絡をします。<?php if ($o['show_estimate']) : ?>土地の取引事例から計算した目安の金額も、送信のあとこの画面でご確認いただけます。<?php endif; ?></p>
      <div class="ssa-row"><label class="ssa-label"><span class="ssa-req">必須</span>お名前</label><input type="text" name="name" autocomplete="name" placeholder="鈴木 花子" required></div>
      <div class="ssa-row"><label class="ssa-label"><span class="ssa-req">必須</span>電話番号</label><input type="tel" name="tel" autocomplete="tel" placeholder="090-0000-0000" required></div>
      <div class="ssa-row"><label class="ssa-label"><span class="ssa-req">必須</span>メールアドレス</label><input type="email" name="email" autocomplete="email" placeholder="xxxxx@gmail.com" required>
        <p class="ssa-hint"><?php echo $o['show_estimate'] ? '受付の内容と目安の金額を、このアドレスにお送りします。' : '受付の内容を、このアドレスにお送りします。'; ?></p></div>
      <div class="ssa-row"><label class="ssa-label"><span class="ssa-opt">任意</span>ご相談内容</label><textarea name="note" rows="3" placeholder="地主とのやり取りの状況や、連絡のつきやすい時間帯などがあればご記入ください。"></textarea></div>
      <div class="ssa-hp" aria-hidden="true"><input type="text" name="ssa_website" tabindex="-1" autocomplete="off"></div>
      <label class="ssa-agree"><input type="checkbox" name="agree" value="1"><span><a href="<?php echo esc_url($o['privacy_url']); ?>" target="_blank" rel="noopener">個人情報の取り扱い</a>に同意し、<?php echo esc_html(preg_replace('/（.*$/u', '', $o['company'])); ?>から査定のご連絡を受けることに同意します</span></label>
      <p class="ssa-errors" role="alert" hidden></p>
      <div class="ssa-actions">
        <button type="button" class="ssa-btn is-ghost ssa-back">戻る</button>
        <button type="submit" class="ssa-btn ssa-submit">無料査定を申し込む</button>
      </div>
    </fieldset>
  </form>

  <div class="ssa-result" hidden>
    <p class="ssa-result-label">借地権の売却価格の目安</p>
    <p class="ssa-result-value"></p>
    <p class="ssa-result-formula"></p>
    <p class="ssa-result-basis"></p>
    <p class="ssa-result-note">この金額は自動で計算した参考価格の目安で、不動産鑑定評価や宅地建物取引業者による価格査定ではありません。借地契約の内容・建物の状態・地主のご意向などで実際の金額は変わります。</p>
    <div class="ssa-result-next">
      <p class="ssa-result-company"></p>
      <?php if ($o['tel']) : ?><p>お急ぎの方はお電話でも：<a href="tel:<?php echo esc_attr(preg_replace('/\D/', '', $o['tel'])); ?>"><?php echo esc_html($o['tel']); ?></a><?php echo $o['tel_hours'] ? '（' . esc_html($o['tel_hours']) . '）' : ''; ?></p><?php endif; ?>
    </div>
  </div>
</div>
    <?php
    return ob_get_clean();
}

/* =========================================================================
 * 9. 管理画面（申込一覧・CSV・設定）
 * ======================================================================= */

add_action('admin_menu', function () {
    add_menu_page('借地権の目安査定', '借地権の目安査定', 'manage_options', 'shakuchi-satei', 'ssa_leads_page', 'dashicons-calculator', 58);
    add_submenu_page('shakuchi-satei', '申込一覧', '申込一覧', 'manage_options', 'shakuchi-satei', 'ssa_leads_page');
    add_submenu_page('shakuchi-satei', '設定', '設定', 'manage_options', 'shakuchi-satei-settings', 'ssa_settings_page');
});

add_action('admin_init', function () {
    register_setting('ssa_settings', SSA_OPT, array('sanitize_callback' => 'ssa_sanitize_options'));
});

add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
    array_unshift($links, '<a href="' . esc_url(admin_url('admin.php?page=shakuchi-satei-settings')) . '">設定</a>');
    return $links;
});

function ssa_leads_page() {
    if (!current_user_can('manage_options')) return;
    global $wpdb;
    $rows = $wpdb->get_results('SELECT * FROM ' . ssa_table() . ' ORDER BY id DESC LIMIT 300', ARRAY_A);
    $csv = wp_nonce_url(admin_url('admin-post.php?action=ssa_csv'), 'ssa_csv');
    echo '<div class="wrap"><h1>借地権の目安査定 申込一覧</h1>';
    $err = get_option('ssa_last_db_error');
    if ($err) echo '<div class="notice notice-error"><p>保存に失敗した申し込みがあります：' . esc_html($err) . '</p></div>';
    if (ssa_api_key() === '') echo '<div class="notice notice-warning"><p>国交省APIのキーが未設定のため、目安額は計算されません（申し込みは受け付けます）。<a href="' . esc_url(admin_url('admin.php?page=shakuchi-satei-settings')) . '">設定</a></p></div>';
    echo '<p><a class="button" href="' . esc_url($csv) . '">CSVで書き出す</a></p>';
    echo '<table class="widefat striped"><thead><tr><th>受付日時</th><th>お名前</th><th>電話・メール</th><th>所在地</th><th>面積</th><th>借地権割合</th><th>地主</th><th>ご希望</th><th>目安</th></tr></thead><tbody>';
    if (!$rows) echo '<tr><td colspan="9">まだ申し込みはありません。</td></tr>';
    foreach ((array) $rows as $r) {
        echo '<tr><td>' . esc_html($r['created_at']) . '</td><td>' . esc_html($r['name']) . '</td><td>' . esc_html($r['tel']) . '<br>' . esc_html($r['email']) . '</td><td>'
            . esc_html($r['pref'] . $r['city'] . $r['town']) . '</td><td>' . esc_html($r['area_m2']) . '㎡</td><td>' . esc_html($r['ratio']) . '</td><td>' . esc_html($r['jinushi'])
            . '</td><td>' . esc_html($r['kibou']) . '</td><td>' . ($r['est_hi'] ? esc_html(ssa_yen_range((int) $r['est_lo'], (int) $r['est_hi'])) : '—') . '</td></tr>';
    }
    echo '</tbody></table></div>';
}

add_action('admin_post_ssa_csv', function () {
    if (!current_user_can('manage_options')) wp_die('権限がありません。');
    check_admin_referer('ssa_csv');
    global $wpdb;
    $rows = $wpdb->get_results('SELECT * FROM ' . ssa_table() . ' ORDER BY id DESC', ARRAY_A);
    nocache_headers();
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename=shakuchi-satei-' . date('Ymd') . '.csv');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    $cols = array('id' => 'ID', 'created_at' => '受付日時', 'name' => 'お名前', 'tel' => '電話番号', 'email' => 'メール', 'pref' => '都道府県',
        'city' => '市区町村', 'town' => '町名', 'area_m2' => '面積（㎡）', 'ratio' => '借地権割合', 'jinushi' => '地主', 'kibou' => 'ご希望',
        'est_lo' => '目安（下限・円）', 'est_hi' => '目安（上限・円）', 'basis' => '計算の根拠', 'note' => 'ご相談内容', 'page_url' => '申込ページ');
    fputcsv($out, array_values($cols));
    foreach ((array) $rows as $r) {
        $line = array();
        foreach (array_keys($cols) as $k) $line[] = ssa_csv_safe(isset($r[$k]) ? $r[$k] : '');
        fputcsv($out, $line);
    }
    fclose($out);
    exit;
});

function ssa_settings_page() {
    if (!current_user_can('manage_options')) return;
    $o = ssa_opt();
    $key_src = trim($o['api_key']) !== '' ? '' : (ssa_api_key() !== '' ? '（空欄のため、同じサイトの計算ツール・相場データのキーを使っています）' : '');
    ?>
<div class="wrap"><h1>借地権の目安査定 設定</h1>
  <p>置き方：固定ページやLPに <code>[shakuchi_satei]</code> と書くとフォームが出ます。</p>
  <form method="post" action="options.php"><?php settings_fields('ssa_settings'); ?>
    <table class="form-table" role="presentation">
      <tr><th>掛け率（％）</th><td><input name="<?php echo SSA_OPT; ?>[coef]" value="<?php echo esc_attr($o['coef']); ?>" class="small-text">％
        <p class="description">目安額＝更地価格（土地の取引価格の中央値×面積）×借地権割合×この掛け率。既定は70％。</p></td></tr>
      <tr><th>国交省APIキー</th><td><input name="<?php echo SSA_OPT; ?>[api_key]" value="<?php echo esc_attr($o['api_key']); ?>" class="regular-text" autocomplete="off">
        <p class="description">不動産情報ライブラリのAPIキー。<?php echo esc_html($key_src); ?>空欄で、ほかのプラグインにも無ければ目安は計算せず、申し込みだけ受け付けます。</p></td></tr>
      <tr><th>通知先メールアドレス</th><td><input name="<?php echo SSA_OPT; ?>[notify_email]" value="<?php echo esc_attr($o['notify_email']); ?>" class="large-text">
        <p class="description">カンマ区切りで複数可。空欄なら管理者のアドレス。1通ずつ送るので、宛先どうしは見えません。</p>
        <label><input type="checkbox" name="<?php echo SSA_OPT; ?>[notify_on]" value="1"<?php checked($o['notify_on'], '1'); ?>> 申し込みがあったら通知する</label></td></tr>
      <tr><th>送信元メールアドレス</th><td><input name="<?php echo SSA_OPT; ?>[from_email]" value="<?php echo esc_attr($o['from_email']); ?>" class="regular-text">
        <p class="description">サイトのドメインのアドレスにすると迷惑メールに入りにくくなります。</p></td></tr>
      <tr><th>受付メール</th><td><label><input type="checkbox" name="<?php echo SSA_OPT; ?>[autoreply]" value="1"<?php checked($o['autoreply'], '1'); ?>> 申込者に受付メールを送る</label></td></tr>
      <tr><th>目安額の表示</th><td><label><input type="checkbox" name="<?php echo SSA_OPT; ?>[show_estimate]" value="1"<?php checked($o['show_estimate'], '1'); ?>> 送信後の画面と受付メールに目安額を出す</label>
        <p class="description">外すと、査定依頼だけを受けるフォームになります（目安額は担当者あての通知メールにだけ載ります）。目安は計算ツールのページで見せる構成のときに外します。</p></td></tr>
      <tr><th>サイト名</th><td><input name="<?php echo SSA_OPT; ?>[site_name]" value="<?php echo esc_attr($o['site_name']); ?>" class="regular-text" placeholder="<?php echo esc_attr(get_option('blogname')); ?>"></td></tr>
      <tr><th>査定する会社</th><td><input name="<?php echo SSA_OPT; ?>[company]" value="<?php echo esc_attr($o['company']); ?>" class="large-text">
        <p class="description">正式な査定をする宅地建物取引業者。免許番号まで書いてください。</p></td></tr>
      <tr><th>電話番号・受付時間</th><td><input name="<?php echo SSA_OPT; ?>[tel]" value="<?php echo esc_attr($o['tel']); ?>" class="regular-text">
        <input name="<?php echo SSA_OPT; ?>[tel_hours]" value="<?php echo esc_attr($o['tel_hours']); ?>" class="regular-text"></td></tr>
      <tr><th>個人情報の取り扱いのページ</th><td><input name="<?php echo SSA_OPT; ?>[privacy_url]" value="<?php echo esc_attr($o['privacy_url']); ?>" class="regular-text"></td></tr>
      <tr><th>色</th><td>見出し・選択中 <input name="<?php echo SSA_OPT; ?>[accent]" value="<?php echo esc_attr($o['accent']); ?>" class="small-text">
        ボタン <input name="<?php echo SSA_OPT; ?>[button]" value="<?php echo esc_attr($o['button']); ?>" class="small-text"></td></tr>
    </table>
    <?php submit_button(); ?>
  </form>
</div>
    <?php
}
