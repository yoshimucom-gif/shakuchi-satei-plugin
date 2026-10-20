/* 借地権の目安査定 — フォームの動き（ステップ切替・市区町村と町名の候補・送信・結果表示） */
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
    fetch(CFG.rest + 'cities?pref=' + encodeURIComponent(pref), { credentials: 'same-origin' })
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
    fetch(CFG.rest + 'towns?city=' + encodeURIComponent(city), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (list) {
        (list || []).forEach(function (t) { var o = document.createElement('option'); o.value = t; dl.appendChild(o); });
      })
      .catch(function () {});
  }

  function setStep(root, n) {
    $$(root, '.ssa-step').forEach(function (fs) { fs.hidden = String(fs.getAttribute('data-step')) !== String(n); });
    $$(root, '.ssa-steps li').forEach(function (li, i) { li.classList.toggle('is-on', i < n); });
  }

  function check1(form) {
    var e = [];
    if (!val(form, 'pref')) e.push('都道府県を選んでください。');
    else if (!val(form, 'city')) e.push('市区町村を選んでください。');
    var a = parseFloat(hankaku(val(form, 'area')).replace(/,/g, ''));
    if (!(a > 0)) e.push('土地の面積を数字で入力してください。');
    if (!val(form, 'ratio')) e.push('借地権割合を選んでください。');
    if (!val(form, 'jinushi')) e.push('地主を選んでください。');
    return e;
  }

  function check2(form) {
    var e = [];
    if (!val(form, 'name')) e.push('お名前を入力してください。');
    var d = hankaku(val(form, 'tel')).replace(/[^0-9]/g, '');
    if (d.length < 9 || d.length > 11) e.push('電話番号の形式が正しくありません（例：090-1234-5678）。');
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(val(form, 'email'))) e.push('メールアドレスの形式が正しくありません。');
    if (!val(form, 'kibou')) e.push('ご希望を選んでください。');
    if (!val(form, 'agree')) e.push('個人情報の取り扱いへの同意が必要です。');
    return e;
  }

  function showResult(root, res) {
    var box = $(root, '.ssa-result');
    var v = $(root, '.ssa-result-value'), f = $(root, '.ssa-result-formula'), b = $(root, '.ssa-result-basis');
    if (res.estimate) {
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
    $$(root, '.ssa-steps li').forEach(function (li) { li.classList.add('is-on'); });
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

    $(root, '.ssa-next').addEventListener('click', function () {
      var e = check1(form);
      showErrors($(root, '[data-step="1"] .ssa-errors'), e);
      if (!e.length) { setStep(root, 2); root.scrollIntoView({ block: 'start', behavior: 'smooth' }); }
    });
    $(root, '.ssa-back').addEventListener('click', function () { setStep(root, 1); });

    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var box = $(root, '[data-step="2"] .ssa-errors');
      var e = check2(form);
      showErrors(box, e);
      if (e.length) return;
      var btn = $(root, '.ssa-submit');
      btn.disabled = true; btn.textContent = '計算しています…';
      var reset = function () { btn.disabled = false; btn.textContent = '目安の金額を見る'; };
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
