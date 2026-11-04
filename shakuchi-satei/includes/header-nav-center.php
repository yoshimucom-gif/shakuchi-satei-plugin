<?php
/**
 * ヘッダーのメニュー（下の段）を中央にそろえる（2026-11-04 吉村さん「メニュー中央寄せにできる？」）。
 * re:Diver の「メニューの位置」は 左寄せ／中央／右寄せ／下段 で、下段は左から並ぶ。
 * 「中央」はロゴと同じ段の真ん中に入るため、下段のまま中にそろえる指定だけをここで足す。
 * 2026-11-04 v1.1.11 は justify-content だけで、テーマ側の指定に負けて左端に寄った（吉村さん「おまえなめてんの？」）。
 * メニューの幅を中身ぶんにして左右の余白を自動にする形に変え、!important で確実に効かせる。
 * スマホ（767px以下）は横にスクロールするテーマの作りのままにする。
 */
if (!defined('ABSPATH')) exit;

add_action('wp_head', function () {
    echo '<style id="ssa-header-nav-center">@media (min-width:768px){#header-nav.is-position-bottom .header-nav{width:max-content!important;max-width:100%!important;margin-left:auto!important;margin-right:auto!important;padding-left:0!important;justify-content:center!important}}</style>' . "\n";
}, 99);
