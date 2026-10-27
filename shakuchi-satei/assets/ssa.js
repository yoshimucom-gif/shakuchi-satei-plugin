/* 借地権の目安査定 — フォームの動き（1画面1問のステップ式・市区町村と町名の候補・送信・結果表示） */
(function () {
  'use strict';
  var CFG = window.SSA_CFG || {};

  function $(root, sel) { return root.querySelector(sel); }
  function $$(root, sel) { return Array.prototype.slice.call(root.querySelectorAll(sel)); }
  function val(form, name) {
    var el = form.elements[name];
    if (!el) return '';
    if (el.length !== undefined && el.tagName !== 'SELECT') {
      for (var i = 0; i < el.length; i++) if (el[i].checked) return el[i].value;
      return '';
    }
    if (el.type === 'checkbox') return el.checked ? el.value : '';
    return (el.value || '').trim();
  }
  // パーマリンクが ?p= 形式のサイトでは rest_url が「…?rest_route=/ssa/v1/」になるので、2つ目の?は&にする
  function api(path) {
    var base = CFG.rest || '/wp-json/ssa/v1/';
    return base + (base.indexOf('?') >= 0 ? path.replace('?', '&') : path);
  }
  function hankaku(s) {
    return String(s || '').replace(/[０-９．，]/g, function (c) { return String.fromCharCode(c.charCodeAt(0) - 0xFEE0); });
  }
  function showErrors(box, list) {
    if (!list.length) { box.hidden = true; box.textContent = ''; return; }
    box.innerHTML = list.map(function (t) { return '<span>' + t.replace(/[<>&]/g, '') + '</span>'; }).join('');
    box.hidden = false;
    box.scrollIntoView({ block: 'center', behavior: 'smooth' });
  }

  function loadCities(form, pref, keep) {
    var sel = form.elements.city;
    sel.innerHTML = '<option value="">市区町村</option>';
    if (!pref) return;
    fetch(api('cities?pref=' + encodeURIComponent(pref)), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (list) {
        (list || []).forEach(function (c) {
          var o = document.createElement('option');
          o.value = c[0]; o.textContent = c[1];
          if (keep && keep === String(c[0])) o.selected = true;
          sel.appendChild(o);
        });
      })
      .catch(function () {});
  }

  function loadTowns(root, form, city) {
    var dl = $(root, 'datalist');
    if (!dl) return;
    dl.innerHTML = '';
    if (!CFG.auto || !city) return;
    fetch(api('towns?city=' + encodeURIComponent(city)), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (list) {
        (list || []).forEach(function (t) { var o = document.createElement('option'); o.value = t; dl.appendChild(o); });
      })
      .catch(function () {});
  }

  var LAST = 6;

  function current(root) {
    var on = $$(root, '.ssa-step').filter(function (fs) { return !fs.hidden; })[0];
    return on ? parseInt(on.getAttribute('data-step'), 10) : 1;
  }

  function setStep(root, n) {
    $$(root, '.ssa-step').forEach(function (fs) { fs.hidden = String(fs.getAttribute('data-step')) !== String(n); });
    $(root, '.ssa-progress-bar').style.width = Math.round((n - 1) / LAST * 100) + '%';
    $(root, '.ssa-progress-now').textContent = n;
    var top = root.getBoundingClientRect().top;
    if (top < 0 || top > window.innerHeight * 0.4) root.scrollIntoView({ block: 'start', behavior: 'smooth' });
    var first = $(root, '.ssa-step[data-step="' + n + '"] input:not([type=hidden]):not([tabindex="-1"]), .ssa-step[data-step="' + n + '"] select');
    if (first && n !== 1 && first.type !== 'radio') { try { first.focus({ preventScroll: true }); } catch (e) {} }
    try { (window.dataLayer = window.dataLayer || []).push({ event: 'shakuchi_satei_step', satei_step: n }); } catch (e) {}
  }

  // ステップごとの入力チェック（サーバー側でも同じ内容をもう一度確かめる）
  function check(form, n) {
    var e = [];
    if (n === 1) {
      if (!val(form, 'pref')) e.push('都道府県を選んでください。');
      else if (!val(form, 'city')) e.push('市区町村を選んでください。');
    } else if (n === 2) {
      var a = parseFloat(hankaku(val(form, 'area')).replace(/,/g, ''));
      if (!(a > 0)) e.push('土地の面積を数字で入力してください。');
    } else if (n === 3) {
      if (!val(form, 'ratio')) e.push('借地権割合を選んでください。わからなければ「わからない」を選んでください。');
    } else if (n === 4) {
      if (!val(form, 'jinushi')) e.push('地主を選んでください。');
    } else if (n === 5) {
      if (!val(form, 'kibou')) e.push('ご希望を選んでください。');
    } else if (n === 6) {
      if (!val(form, 'name')) e.push('お名前を入力してください。');
      var d = hankaku(val(form, 'tel')).replace(/[^0-9]/g, '');
      if (d.length < 9 || d.length > 11) e.push('電話番号の形式が正しくありません（例：090-1234-5678）。');
      if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(val(form, 'email'))) e.push('メールアドレスの形式が正しくありません。');
      if (!val(form, 'agree')) e.push('個人情報の取り扱いへの同意が必要です。');
    }
    return e;
  }

  function errBox(root, n) { return $(root, '.ssa-step[data-step="' + n + '"] .ssa-errors'); }

  function next(root, form) {
    var n = current(root);
    var e = check(form, n);
    showErrors(errBox(root, n), e);
    if (!e.length && n < LAST) setStep(root, n + 1);
  }

  function showResult(root, res) {
    var box = $(root, '.ssa-result');
    var v = $(root, '.ssa-result-value'), f = $(root, '.ssa-result-formula'), b = $(root, '.ssa-result-basis');
    if (res.mode === 'request') {
      // 査定依頼だけのフォーム：目安額は出さず、受付の完了だけを伝える
      $(root, '.ssa-result-label').textContent = '無料査定のお申し込みを受け付けました';
      v.textContent = 'ありがとうございました';
      f.textContent = ''; b.textContent = '';
      var note = $(root, '.ssa-result-note'); if (note) note.hidden = true;
    } else if (res.estimate) {
      v.textContent = res.estimate.text;
      f.textContent = '更地価格の目安 ' + res.estimate.sarachi + ' × 借地権割合 ' + res.estimate.ratio + ' × ' + res.estimate.coef;
      b.textContent = '土地の価格は、国土交通省「不動産情報ライブラリ」の' + res.basis.scope + 'の土地の取引事例（' +
        res.basis.period + '・' + res.basis.n + '件）の㎡単価の中央値（' + res.basis.med + '円/㎡）から計算しています。' +
        (res.basis.warn ? ' ' + res.basis.warn : '');
    } else {
      v.textContent = '担当者からお伝えします';
      f.textContent = res.reason || '';
      b.textContent = '';
    }
    $(root, '.ssa-result-company').textContent = (res.company ? res.company.replace(/（.*$/, '') : '担当者') +
      'が、借地契約の内容や地主との関係もうかがったうえで、無料で正式な査定をいたします。' +
      (res.mail_ok ? '受付メールを ' + res.email + ' にお送りしました。' : '') + '担当者からのご連絡をお待ちください。';
    $(root, '.ssa-form').hidden = true;
    box.hidden = false;
    box.scrollIntoView({ block: 'start', behavior: 'smooth' });
    try { (window.dataLayer = window.dataLayer || []).push({ event: 'shakuchi_satei_submit', satei_has_estimate: !!res.estimate }); } catch (e) {}
  }

  function init(root) {
    var form = $(root, '.ssa-form');
    var started = Date.now();
    var pref = form.elements.pref;
    pref.addEventListener('change', function () { loadCities(form, pref.value); loadTowns(root, form, ''); });
    form.elements.city.addEventListener('change', function () { loadTowns(root, form, form.elements.city.value); });
    if (pref.value) loadCities(form, pref.value);
    setStep(root, 1);

    $$(root, '.ssa-next').forEach(function (b) { b.addEventListener('click', function () { next(root, form); }); });
    $$(root, '.ssa-back').forEach(function (b) {
      b.addEventListener('click', function () { var n = current(root); if (n > 1) setStep(root, n - 1); });
    });
    // 選ぶだけの質問は、選んだら次へ進む（同じものを押し直したときも進む）
    $$(root, '.ssa-step[data-auto] .ssa-tile input').forEach(function (r) {
      var go = function () { setTimeout(function () { if (current(root) === parseInt(r.closest('.ssa-step').getAttribute('data-step'), 10)) next(root, form); }, 220); };
      r.addEventListener('change', go);
      r.addEventListener('click', function () { if (r.checked) go(); });
    });

    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var n = current(root);
      if (n < LAST) { next(root, form); return; }          // 入力中の Enter は「次へ」として扱う
      var box = errBox(root, LAST);
      for (var i = 1; i <= LAST; i++) {                       // 送る前に全ステップを確かめ、抜けがあればそのステップへ戻す
        var e = check(form, i);
        if (e.length) { setStep(root, i); showErrors(errBox(root, i), e); return; }
      }
      showErrors(box, []);
      var btn = $(root, '.ssa-submit');
      btn.disabled = true; btn.textContent = '送信しています…';
      var reset = function () { btn.disabled = false; btn.textContent = '無料査定を申し込む'; };
      var body0 = new FormData(); body0.append('action', 'ssa_nonce');
      fetch(CFG.ajax, { method: 'POST', body: body0, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (n) {
          var fd = new FormData(form);
          fd.append('action', 'ssa_submit');
          fd.append('nonce', n.nonce);
          fd.append('ssa_elapsed', String(Date.now() - started));
          fd.append('page_url', location.href.split('#')[0]);
          return fetch(CFG.ajax, { method: 'POST', body: fd, credentials: 'same-origin' });
        })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          if (res && res.ok) { showResult(root, res); return; }
          reset();
          showErrors(box, (res && res.errors) || ['送信できませんでした。時間をおいてもう一度お試しください。']);
        })
        .catch(function () { reset(); showErrors(box, ['送信できませんでした。通信状況を確かめて、もう一度お試しください。']); });
    });
  }

  function boot() { $$(document, '.ssa').forEach(init); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
