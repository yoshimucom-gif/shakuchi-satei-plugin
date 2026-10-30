<?php
/**
 * スマホの画面の下に固定する相談のバー（2026-10-30 吉村さん「スマホビューでフッターにCTAがずっと固定されている形にしたい。
 * お寺ならお寺、それぞれの記事に適したCTA」）。
 *
 * テーマ（re:Diver）の固定フッターボタンはサイト全体で1種類しか出せないので、ここで記事のカテゴリを見て出し分ける。
 * 記事のCTA（最初の見出しの上・途中・まとめの後）と同じ6つの型にそろえる。
 *   お寺（寺院別・お寺が地主の借地）… 電話＋無料相談
 *   更新料・地代 … 更新料の計算＋無料相談 ／ 承諾料・建て替え … 承諾料の計算＋無料相談
 *   返還・立ち退き・トラブル … 電話＋無料相談 ／ そのほか … 売却額の計算＋無料相談
 * 無料相談のページには出さない。幅768px以上（PC・タブレット）では出さない。
 */
if (!defined('ABSPATH')) exit;

function ssa_sticky_cta_pattern() {
    if (is_admin() || is_page('contact-us')) return null;
    $tel = array('label' => '電話する', 'url' => 'tel:0358608391', 'kind' => 'tel');
    $soudan = array('label' => '無料で相談する', 'url' => '/contact-us/', 'kind' => 'main');
    $calc = function ($label, $url) { return array('label' => $label, 'url' => $url, 'kind' => 'sub'); };
    if (is_page(array('keisan', 'keisan-koushinryou', 'keisan-shoudakuryou'))) {
        return array('note' => '借地のご相談は無料です', 'btns' => array($tel, $soudan), 'key' => 'tool');
    }
    if (is_singular('post')) {
        if (has_category(array('ichiran', 'otera'))) {
            // お寺の記事の相談先はお寺LPのフォーム（2026-10-30 吉村さん「つなぎ替えて」）
            return array('note' => '毎月お寺の借地相談を受けています', 'btns' => array($tel, array('label' => '無料で相談する', 'url' => '/lp/otera/#form', 'kind' => 'main')), 'key' => 'temple');
        }
        if (has_category('koushin')) {
            return array('note' => '更新料が相場かどうか目安を出せます', 'btns' => array($calc('更新料を計算', '/keisan-koushinryou/'), $soudan), 'key' => 'koushin');
        }
        if (has_category('shoudaku')) {
            return array('note' => '承諾料の目安をその場で出せます', 'btns' => array($calc('承諾料を計算', '/keisan-shoudakuryou/'), $soudan), 'key' => 'shoudaku');
        }
        if (has_category(array('henkan', 'trouble'))) {
            return array('note' => '地主に返事をする前にご相談ください', 'btns' => array($tel, $soudan), 'key' => 'trouble');
        }
        if (has_category('souzoku')) {
            return array('note' => '相続した借地のご相談も無料です', 'btns' => array($calc('売却額を計算', '/keisan/'), $soudan), 'key' => 'souzoku');
        }
    }
    return array('note' => '借地権の売却額は所在地と面積で目安が出せます', 'btns' => array($calc('売却額を計算', '/keisan/'), $soudan), 'key' => 'default');
}

add_action('wp_footer', function () {
    $p = ssa_sticky_cta_pattern();
    if (!$p) return;
    $html = '<div class="ssa-sticky" data-cta="' . esc_attr($p['key']) . '"><p class="ssa-sticky__note">' . esc_html($p['note']) . '</p><div class="ssa-sticky__btns">';
    foreach ($p['btns'] as $b) {
        $icon = $b['kind'] === 'tel' ? '&#x260E; ' : '';
        $html .= '<a class="ssa-sticky__btn is-' . esc_attr($b['kind']) . '" href="' . esc_attr($b['url']) . '"'
            . ' onclick="window.gtag&&gtag(\'event\',\'sticky_cta_click\',{cta:\'' . esc_attr($p['key']) . '\',button:\'' . esc_attr($b['kind']) . '\'})">'
            . $icon . esc_html($b['label']) . '</a>';
    }
    $html .= '</div></div>';
    if (function_exists('ssa_is_subdir_staging') && ssa_is_subdir_staging()) $html = ssa_staging_rewrite_links($html);
    echo $html;
    ?>
<style>
.ssa-sticky{display:none}
@media (max-width:767px){
  .ssa-sticky{display:block;position:fixed;left:0;right:0;bottom:0;z-index:999;background:#fff;
    padding:6px 10px calc(8px + env(safe-area-inset-bottom));box-shadow:0 -2px 10px rgba(0,0,0,.15)}
  .ssa-sticky__note{margin:0 0 5px;text-align:center;font-size:12px;font-weight:700;color:#c2560a;line-height:1.4}
  .ssa-sticky__btns{display:flex;gap:8px}
  .ssa-sticky__btn{flex:1;display:flex;align-items:center;justify-content:center;min-height:46px;border-radius:6px;
    font-size:15px;font-weight:700;text-decoration:none!important;line-height:1.2;text-align:center}
  .ssa-sticky__btn.is-main{flex:1.4;background:#e8730c;color:#fff!important;border-bottom:4px solid #a84f04}
  .ssa-sticky__btn.is-tel{background:#3a8ee6;color:#fff!important;border-bottom:4px solid #2a6cc0}
  .ssa-sticky__btn.is-sub{background:#fff;color:#2f3437!important;border:1px solid #c3cad0;border-bottom:4px solid #c3cad0}
  .ssa-sticky__btn:active{transform:translateY(2px);border-bottom-width:2px}
  body{padding-bottom:96px}
}
</style>
    <?php
}, 50);
