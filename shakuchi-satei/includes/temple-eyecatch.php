<?php
/**
 * 寺院別の記事（カテゴリ ichiran）は、記事の上にアイキャッチを出さない。
 * 本文の一番上に寺の写真があるので、同じ写真が2回並ぶ（2026-10-30 吉村さん「お寺の記事に関してはアイキャッチ不要。ページ内にあるから」）。
 * アイキャッチ画像の設定そのものは残す（一覧のカード・SNSの画像に使う）。re:Diver の diver_single_eyecatch フィルターで出力だけ消す。
 */
if (!defined('ABSPATH')) exit;

add_filter('diver_single_eyecatch', function ($html, $post_id) {
    return has_category('ichiran', $post_id) ? '' : $html;
}, 10, 2);
