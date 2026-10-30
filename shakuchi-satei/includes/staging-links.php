<?php
/**
 * ステージング表示用のリンクの読み替え。
 *
 * 本文やボタンのリンクは、本番のURLの形（/lp/satei/・/sell/・/category/kiso/ のような相対パス）で持っている。
 * サブディレクトリ型のステージング（例 https://cv-n65.jp/voyager/、パーマリンクは ?p= 形式）では、
 * このままだとドメイン直下の本番サイトへ飛んで404になる。
 *
 * そこで、ステージングのときだけ、表示する直前に相対パスをステージングのURLに読み替える。
 * データベースの本文は書き換えないので、本番へ移したあとに戻す作業は要らない。
 * 本番（パーマリンクが「投稿名」などに設定されたサイト）では何もしない。
 */
if (!defined('ABSPATH')) exit;

function ssa_is_subdir_staging() {
    static $on = null;
    if ($on !== null) return $on;
    $path = (string) wp_parse_url(home_url('/'), PHP_URL_PATH);
    $on = (get_option('permalink_structure') === '' && trim($path, '/') !== '');
    return $on;
}

/** 本番の形のパス（/x/・/lp/x/・/category/x/）を、このサイトの実際のURLにする。見つからなければ null */
function ssa_staging_resolve($path) {
    static $cache = array();
    if (isset($cache[$path])) return $cache[$path];
    $p = trim($path, '/');
    $url = null;
    if ($p === '') {
        $url = home_url('/');
    } elseif (preg_match('#^category/([^/]+)$#', $p, $m)) {
        $t = get_term_by('slug', rawurldecode($m[1]), 'category');
        if ($t) { $l = get_term_link($t); if (!is_wp_error($l)) $url = $l; }
    } elseif (preg_match('#^lp/([^/]+)$#', $p, $m)) {
        $o = get_page_by_path(rawurldecode($m[1]), OBJECT, 'lp');
        if ($o && $o->post_status === 'publish') $url = get_permalink($o);
    } elseif (strpos($p, '/') === false) {
        $o = get_page_by_path(rawurldecode($p), OBJECT, array('post', 'page'));
        if ($o && $o->post_status === 'publish') $url = get_permalink($o);
    }
    $cache[$path] = $url;
    return $url;
}

function ssa_staging_rewrite_links($html) {
    if (!is_string($html) || $html === '' || strpos($html, 'href=') === false) return $html;
    // 対象: href="/..."（相対パス）と、本番ドメインの絶対URL（借地権のミカタ・お寺の借地権の旧ドメイン）
    return preg_replace_callback(
        '#href=(["\'])(?:https?://(?:www\.)?(?:shakuchi-mikata\.com|cv-n65\.jp))?(/(?!/)(?!voyager/)[^"\'\#?]*)(\#[^"\']*)?\1#i',
        function ($m) {
            $path = $m[2];
            if (preg_match('#^/(wp-content|wp-admin|wp-json|wp-includes)/#', $path)) return $m[0];
            $url = ssa_staging_resolve($path);
            if (!$url) return $m[0];
            $hash = isset($m[3]) ? $m[3] : '';
            return 'href=' . $m[1] . esc_url($url) . $hash . $m[1];
        },
        $html
    );
}

add_action('init', function () {
    if (!ssa_is_subdir_staging() || is_admin()) return;
    // 本文・ブロック（共通ブロック・ボタン・TOPのブロック）・ウィジェット（ビッグフッター）・メニューの手入力リンク
    add_filter('the_content', 'ssa_staging_rewrite_links', 99);
    add_filter('widget_block_content', 'ssa_staging_rewrite_links', 99);
    add_filter('widget_text_content', 'ssa_staging_rewrite_links', 99);
    add_filter('render_block', function ($html, $block) {
        return empty($block['blockName']) ? $html : ssa_staging_rewrite_links($html);
    }, 99, 2);
    add_filter('nav_menu_link_attributes', function ($atts) {
        if (!empty($atts['href'])) {
            $r = ssa_staging_rewrite_links('href="' . $atts['href'] . '"');
            if (preg_match('#href="([^"]*)"#', $r, $m)) $atts['href'] = html_entity_decode($m[1]);
        }
        return $atts;
    }, 99);
    // ヘッダーのボタン（カスタマイザーの diver_header_buttons。テーマが JSON を配列にしてから通すフィルター）
    add_filter('diver_header_buttons', function ($btns) {
        if (!is_array($btns)) return $btns;
        foreach ($btns as $i => $b) {
            if (empty($b['url'])) continue;
            $r = ssa_staging_rewrite_links('href="' . $b['url'] . '"');
            if (preg_match('#href="([^"]*)"#', $r, $m)) $btns[$i]['url'] = html_entity_decode($m[1]);
        }
        return $btns;
    }, 99);
});
