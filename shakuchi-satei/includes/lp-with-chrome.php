<?php
/**
 * 指定したLP（投稿タイプ lp）を、記事・固定ページと同じテーマの枠で表示する＝ヘッダー・グロナビ・フッターを出す
 * （2026-11-10 吉村さん「グロナビ。フッターはしっかりだそう」。re:Diver の LP テンプレートはヘッダーとフッターを出さず、
 *  切り替える設定も無かった）。URL（/lp/otera/）はそのまま。本文・記事幅（全幅）・タイトル非表示の設定もそのまま効く。
 * 対象はフィルター ssa_lp_with_chrome のスラッグだけ（既定はお寺LP otera）。
 */
if (!defined('ABSPATH')) exit;

add_filter('template_include', function ($template) {
    if (!is_singular('lp')) return $template;
    $post = get_queried_object();
    $targets = apply_filters('ssa_lp_with_chrome', array('otera'));
    if (!$post || !in_array($post->post_name, $targets, true)) return $template;
    $alt = locate_template(array('singular.php', 'single.php', 'page.php'));
    return $alt ? $alt : $template;
}, 99);
