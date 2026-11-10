<?php
/**
 * PCの画面の右下に出す相談の案内（2026-11-04 吉村さん「右下にポップアップ出せる？」→「はいよろしく」）。
 * お寺LP（/lp/otera/）への入口がグロナビ・トップ・カテゴリーページに無かったので、その入口も兼ねる。
 *   お寺（寺院別・お寺が地主の借地の記事とカテゴリー）… お寺LPへ
 *   そのほか … 無料相談へ＋「お寺が地主の借地はこちら」でお寺LPへ
 * LP・無料相談のページには出さない（申し込み先そのもの）。スマホは下の固定バー（sticky-cta.php）があるので出さない。
 * 少しスクロールしてから出す。×で閉じたら、そのタブを閉じるまで出さない。
 */
if (!defined('ABSPATH')) exit;

function ssa_corner_popup_pattern() {
    // お寺の相談ページは 2026-11-10 にLP投稿（/lp/otera/）から固定ページ（/otera-soudan/）へ移した。申し込み先そのものなので出さない
    if (is_admin() || is_singular('lp') || is_page(array('contact-us', 'otera-soudan'))) return null;
    $temple = (is_singular('post') && has_category(array('ichiran', 'otera'))) || is_category(array('ichiran', 'otera'));
    if ($temple) {
        return array('key' => 'temple', 'head' => '毎月お寺の借地相談を受けています',
            'text' => 'お寺が地主の借地の売却は、地主との交渉から書類の準備までお任せいただけます。',
            'btn' => 'お寺の借地を相談する', 'url' => '/otera-soudan/', 'sub' => null);
    }
    return array('key' => 'default', 'head' => '借地のご相談は無料です',
        'text' => '地主との交渉から売却まで、借地の取引100件以上の担当がサポートします。',
        'btn' => '無料で相談する', 'url' => '/contact-us/',
        'sub' => array('label' => 'お寺が地主の借地はこちら', 'url' => '/otera-shakuchi/'));
}

add_action('wp_footer', function () {
    $p = ssa_corner_popup_pattern();
    if (!$p) return;
    $up = wp_upload_dir();
    $img = $up['baseurl'] . '/2023/12/kase2-1024x1024-1-300x300.png';
    $ga = function ($what) use ($p) {
        return ' onclick="window.gtag&&gtag(\'event\',\'corner_popup_click\',{cta:\'' . esc_attr($p['key']) . '\',button:\'' . $what . '\'})"';
    };
    $html = '<aside class="ssa-pop" data-cta="' . esc_attr($p['key']) . '" aria-label="ご相談の案内" hidden>'
        . '<button type="button" class="ssa-pop__x" aria-label="閉じる">&times;</button>'
        . '<p class="ssa-pop__head">' . esc_html($p['head']) . '</p>'
        . '<div class="ssa-pop__body"><img class="ssa-pop__img" src="' . esc_url($img) . '" alt="担当の加瀬" width="64" height="64" loading="lazy">'
        . '<p class="ssa-pop__text">' . esc_html($p['text']) . '</p></div>'
        . '<a class="ssa-pop__btn" href="' . esc_attr($p['url']) . '"' . $ga('main') . '>' . esc_html($p['btn']) . '</a>';
    if ($p['sub']) {
        $html .= '<a class="ssa-pop__sub" href="' . esc_attr($p['sub']['url']) . '"' . $ga('sub') . '>' . esc_html($p['sub']['label']) . '</a>';
    }
    $html .= '</aside>';
    if (function_exists('ssa_is_subdir_staging') && ssa_is_subdir_staging()) $html = ssa_staging_rewrite_links($html);
    echo $html;
    ?>
<style>
.ssa-pop{display:none}
@media (min-width:768px){
  .ssa-pop:not([hidden]){display:block;position:fixed;right:20px;bottom:20px;z-index:998;width:300px;background:#fff;border-radius:8px;
    box-shadow:0 6px 24px rgba(0,0,0,.22);overflow:hidden;font-size:14px;line-height:1.6;color:#2f3437;animation:ssa-pop-in .35s ease-out}
  /* テーマの字間（letter-spacing）が効くと見出しの最後の1文字だけ2行目に落ちる（2026-11-04 吉村さん「なんで す だけ2だんめいくかなー」）。
     字間を戻し、見出しは1行に収める */
  .ssa-pop,.ssa-pop *{letter-spacing:normal!important}
  .ssa-pop__head{margin:0;padding:10px 36px 10px 14px;background:#e8730c;color:#fff;font-weight:700;font-size:14px;line-height:1.4;white-space:nowrap}
  .ssa-pop__x{position:absolute;top:5px;right:6px;width:28px;height:28px;padding:0;border:0;background:none;color:#fff;font-size:22px;line-height:28px;cursor:pointer}
  .ssa-pop__body{display:flex;gap:10px;align-items:center;padding:12px 14px 4px}
  .ssa-pop__img{flex:none;width:64px;height:64px;border-radius:50%;object-fit:cover;border:2px solid #fde3c8}
  .ssa-pop__text{margin:0;font-size:13px}
  .ssa-pop__btn{display:block;margin:10px 14px 0;padding:11px 8px;border-radius:6px;background:#e8730c;color:#fff!important;font-weight:700;font-size:15px;
    text-align:center;text-decoration:none!important;border-bottom:4px solid #a84f04}
  .ssa-pop__btn:hover{opacity:.92}
  .ssa-pop__btn:active{transform:translateY(2px);border-bottom-width:2px}
  .ssa-pop__sub{display:block;margin:8px 14px 0;text-align:center;font-size:13px;color:#c2560a!important;font-weight:700}
  .ssa-pop{padding-bottom:12px}
}
@keyframes ssa-pop-in{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:none}}
@media (prefers-reduced-motion:reduce){.ssa-pop{animation:none!important}}
</style>
<script>
(function(){
  var el=document.querySelector('.ssa-pop');if(!el)return;
  var KEY='ssa_pop_closed';
  try{if(sessionStorage.getItem(KEY))return;}catch(e){}
  function show(){if(window.scrollY>400){el.hidden=false;window.removeEventListener('scroll',show);}}
  window.addEventListener('scroll',show,{passive:true});show();
  el.querySelector('.ssa-pop__x').addEventListener('click',function(){
    el.hidden=true;try{sessionStorage.setItem(KEY,'1');}catch(e){}
  });
})();
</script>
    <?php
}, 50);
