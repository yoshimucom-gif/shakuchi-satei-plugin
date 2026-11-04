<?php
/**
 * ヘッダーのメニュー（下の段）を中央にそろえる（2026-11-04 吉村さん「メニュー中央寄せにできる？」）。
 * re:Diver の「メニューの位置」は 左寄せ／中央／右寄せ／下段 で、下段は左から並ぶ。
 * 「中央」はロゴと同じ段の真ん中に入るため、下段のまま中にそろえる指定だけをここで足す。
 * スマホ（767px以下）は横にスクロールするテーマの作りのままにする。
 */
if (!defined('ABSPATH')) exit;

add_action('wp_head', function () {
    echo '<style id="ssa-header-nav-center">@media (min-width:768px){#header-nav.is-position-bottom .header-nav{justify-content:center;padding-left:0}}</style>' . "\n";
}, 99);
