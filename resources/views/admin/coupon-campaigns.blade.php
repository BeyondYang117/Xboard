<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{{ $title }}</title>
  <style>
    :root { color-scheme: light; font-family: Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; color: #1e2b28; background: #f4f7f6; font-synthesis: none; }
    * { box-sizing: border-box; }
    body { margin: 0; min-width: 320px; }
    button, input, select, textarea { font: inherit; }
    button { cursor: pointer; }
    .shell { max-width: 1440px; margin: 0 auto; padding: 24px 28px 48px; }
    .topbar { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin-bottom: 26px; }
    .topbar a { color: #49645d; text-decoration: none; font-size: 13px; }
    h1 { margin: 0; font-size: 22px; line-height: 1.25; font-weight: 680; }
    .subhead { margin-top: 6px; color: #70817c; font-size: 13px; }
    .primary, .secondary, .quiet, .danger { min-height: 36px; border-radius: 5px; padding: 0 13px; font-size: 13px; font-weight: 600; }
    .primary { color: white; background: #14745e; border: 1px solid #14745e; }
    .primary:hover { background: #105c4c; }
    .secondary { color: #28594d; background: white; border: 1px solid #cbd9d5; }
    .quiet { color: #38534c; background: transparent; border: 1px solid transparent; }
    .danger { color: #a73333; background: white; border: 1px solid #e8caca; }
    .toolbar { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 0 0 12px; }
    .toolbar .count { color: #70817c; font-size: 13px; }
    .table-wrap { overflow: auto; border: 1px solid #dce5e2; border-radius: 6px; background: #fff; }
    table { width: 100%; border-collapse: collapse; text-align: left; }
    th { height: 40px; padding: 0 14px; color: #6c7c77; background: #f8faf9; font-size: 11px; font-weight: 650; white-space: nowrap; }
    td { height: 58px; padding: 9px 14px; border-top: 1px solid #edf1ef; vertical-align: middle; font-size: 13px; }
    .main-text { font-weight: 600; }
    .muted { margin-top: 4px; color: #7b8985; font-size: 12px; }
    .status { display: inline-flex; align-items: center; gap: 6px; color: #54736a; font-size: 12px; white-space: nowrap; }
    .status::before { width: 7px; height: 7px; border-radius: 50%; background: #23a47c; content: ""; }
    .status.off::before { background: #9aa8a3; }
    .row-actions { display: flex; align-items: center; justify-content: flex-end; gap: 4px; white-space: nowrap; }
    .row-actions button { min-height: 30px; padding: 0 8px; }
    .empty, .notice { padding: 38px 18px; color: #74837e; text-align: center; font-size: 13px; }
    .notice { display: none; margin: 0 0 16px; padding: 12px 14px; border-radius: 5px; text-align: left; }
    .notice.error { display: block; color: #8d3232; background: #fff0f0; border: 1px solid #f0d2d2; }
    .notice.success { display: block; color: #27614e; background: #edf8f3; border: 1px solid #cde9db; }
    .pager { display: flex; align-items: center; justify-content: flex-end; gap: 12px; padding-top: 14px; color: #71807b; font-size: 12px; }
    dialog { width: min(680px, calc(100vw - 28px)); max-height: min(88vh, 900px); padding: 0; border: 1px solid #d7e0dd; border-radius: 8px; color: #24322e; box-shadow: 0 18px 65px #15292333; }
    dialog::backdrop { background: #10221d66; }
    .dialog-head { display: flex; justify-content: space-between; align-items: center; padding: 17px 20px; border-bottom: 1px solid #e7edeb; }
    .dialog-head h2 { margin: 0; font-size: 17px; }
    .dialog-body { overflow: auto; max-height: calc(88vh - 130px); padding: 18px 20px 22px; }
    .dialog-foot { display: flex; justify-content: flex-end; gap: 8px; padding: 13px 20px; border-top: 1px solid #e7edeb; background: #fbfcfc; }
    .close { width: 30px; height: 30px; border: 0; border-radius: 4px; color: #63736e; background: transparent; font-size: 20px; }
    .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px 16px; }
    .field { min-width: 0; }
    .field.full { grid-column: 1 / -1; }
    .field label, .field-label { display: block; margin-bottom: 6px; color: #445650; font-size: 12px; font-weight: 650; }
    .field input:not([type=checkbox]):not([type=radio]), .field select, .field textarea { width: 100%; min-height: 38px; padding: 8px 10px; border: 1px solid #cfdad6; border-radius: 4px; color: #24322e; background: #fff; outline: none; }
    .field textarea { min-height: 70px; resize: vertical; }
    .field input:focus, .field select:focus, .field textarea:focus { border-color: #55a18a; box-shadow: 0 0 0 3px #55a18a1c; }
    .field .hint { margin-top: 5px; color: #7b8985; font-size: 11px; line-height: 1.45; }
    .choice-row { display: flex; gap: 8px; }
    .choice { display: inline-flex; align-items: center; gap: 7px; min-height: 36px; padding: 0 11px; border: 1px solid #d7e1dd; border-radius: 4px; background: white; color: #42554e; font-size: 12px; }
    .choice:has(input:checked) { border-color: #58a18a; background: #eff8f4; color: #21634f; }
    .choice input, .check-option input { accent-color: #14745e; }
    .checks { display: grid; grid-template-columns: repeat(3, minmax(0,1fr)); gap: 7px; }
    .check-option { display: flex; align-items: center; gap: 7px; min-height: 32px; color: #53645e; font-size: 12px; }
    .modal-table { min-width: 650px; }
    .code { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; color: #315b4f; font-size: 12px; }
    .loading { color: #70817c; }
    @media (max-width: 700px) {
      .shell { padding: 18px 14px 32px; }
      .topbar { align-items: flex-start; }
      .topbar .primary { flex: 0 0 auto; }
      .form-grid { grid-template-columns: 1fr; }
      .field.full { grid-column: auto; }
      .checks { grid-template-columns: repeat(2, minmax(0,1fr)); }
      .table-wrap { margin-right: -2px; }
    }
  </style>
</head>
<body>
  <main class="shell">
    <header class="topbar">
      <div>
        <a href="/{{ $secure_path }}">‹ 返回管理后台</a>
        <h1 style="margin-top:12px">Telegram 优惠活动</h1>
        <div class="subhead">管理领取规则、专属优惠券和核销情况</div>
      </div>
      <button class="primary" id="create">＋ 新建活动</button>
    </header>
    <div id="notice" class="notice" role="status"></div>
    <div class="toolbar"><span class="count" id="summary">正在加载活动…</span><button class="secondary" id="refresh">刷新</button></div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>活动</th><th>优惠内容</th><th>领取时间</th><th>名额 / 领取</th><th>已核销</th><th>状态</th><th style="text-align:right">操作</th></tr></thead>
        <tbody id="campaigns"><tr><td colspan="7" class="empty">正在加载…</td></tr></tbody>
      </table>
    </div>
    <div class="pager"><span id="page-info"></span><button class="secondary" id="prev">上一页</button><button class="secondary" id="next">下一页</button></div>
  </main>

  <dialog id="editor">
    <form id="campaign-form">
      <div class="dialog-head"><h2 id="form-title">新建活动</h2><button type="button" class="close" data-close="editor" aria-label="关闭">×</button></div>
      <div class="dialog-body">
        <input type="hidden" name="id">
        <div class="form-grid">
          <div class="field full"><label for="name">活动名称</label><input id="name" name="name" required maxlength="128" placeholder="例如：TG 新用户优惠"></div>
          <div class="field full"><label for="description">Bot 展示说明</label><textarea id="description" name="description" maxlength="2000" placeholder="用户可在活动列表看到的补充说明"></textarea></div>
          <div class="field full"><span class="field-label">优惠类型</span><div class="choice-row"><label class="choice"><input type="radio" name="type" value="1" checked> 立减金额</label><label class="choice"><input type="radio" name="type" value="2"> 折扣比例</label></div></div>
          <div class="field"><label id="value-label" for="value">立减金额（元）</label><input id="value" name="value" type="number" min="0.01" step="0.01" required value="5"><div class="hint" id="value-hint">按人民币金额填写，系统会换算为分。</div></div>
          <div class="field"><label for="coupon_valid_days">领取后有效天数</label><input id="coupon_valid_days" name="coupon_valid_days" type="number" min="1" max="365" step="1" required value="7"></div>
          <div class="field"><label for="claim_started_at">开始领取</label><input id="claim_started_at" name="claim_started_at" type="datetime-local" required></div>
          <div class="field"><label for="claim_ended_at">结束领取</label><input id="claim_ended_at" name="claim_ended_at" type="datetime-local" required></div>
          <div class="field"><label for="limit_claim">领取总名额</label><input id="limit_claim" name="limit_claim" type="number" min="1" step="1" placeholder="留空表示不限"></div>
          <div class="field"><span class="field-label">活动状态</span><label class="choice"><input type="checkbox" name="enabled" checked> 启用领取</label></div>
          <div class="field full"><span class="field-label">适用套餐</span><div class="checks" id="plan-options"></div><div class="hint">不勾选表示不限制套餐。</div></div>
          <div class="field full"><span class="field-label">适用周期</span><div class="checks" id="period-options"></div><div class="hint">不勾选表示不限制周期。</div></div>
          <div class="field full"><div id="form-error" class="notice"></div></div>
        </div>
      </div>
      <div class="dialog-foot"><button type="button" class="secondary" data-close="editor">取消</button><button class="primary" type="submit" id="save-button">保存活动</button></div>
    </form>
  </dialog>

  <dialog id="claims-dialog">
    <div class="dialog-head"><h2 id="claims-title">领取记录</h2><button type="button" class="close" data-close="claims-dialog" aria-label="关闭">×</button></div>
    <div class="dialog-body"><div class="table-wrap"><table class="modal-table"><thead><tr><th>用户</th><th>专属优惠码</th><th>领取时间</th><th>有效期至</th><th>状态</th></tr></thead><tbody id="claim-rows"><tr><td colspan="5" class="empty">正在加载…</td></tr></tbody></table></div><div class="pager"><span id="claim-page-info"></span><button class="secondary" id="claim-prev">上一页</button><button class="secondary" id="claim-next">下一页</button></div></div>
  </dialog>

  <script>
    (() => {
      const securePath = @json($secure_path);
      const apiRoot = `${location.origin}/api/v2/${encodeURIComponent(securePath)}/coupon-campaign`;
      const state = { page: 1, lastPage: 1, campaigns: [], options: { plans: [], periods: [] }, claimCampaignId: null, claimPage: 1, claimLastPage: 1 };
      const $ = (selector, root = document) => root.querySelector(selector);
      const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];
      const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[char]);
      const dateText = value => value ? new Date(Number(value) * 1000).toLocaleString('zh-CN', { hour12: false }) : '—';
      const money = cents => `¥${(Number(cents || 0) / 100).toFixed(2)}`;
      const token = () => {
        for (const storage of [localStorage, sessionStorage]) {
          for (const key of ['Xboard_access_token', 'access_token']) {
            const raw = storage.getItem(key);
            if (!raw) continue;
            try {
              const entry = JSON.parse(raw);
              const value = typeof entry === 'string' ? entry : entry?.value;
              if (typeof value === 'string' && value) return value;
            } catch {
              return raw;
            }
          }
        }
        return '';
      };
      async function request(path, options = {}) {
        const accessToken = token();
        if (!accessToken) throw new Error('管理员登录信息已失效，请返回后台重新登录。');
        const response = await fetch(`${apiRoot}${path}`, {
          ...options,
          headers: { Authorization: accessToken, 'Content-Type': 'application/json', ...(options.headers || {}) },
        });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok || payload.status === 'fail') throw new Error(payload.message || `请求失败（${response.status}）`);
        return payload;
      }
      function notice(message, kind = 'success') {
        const node = $('#notice'); node.textContent = message; node.className = `notice ${kind}`;
        if (kind === 'success') setTimeout(() => { if (node.textContent === message) node.className = 'notice'; }, 3600);
      }
      function errorMessage(error) { notice(error.message || '请求失败', 'error'); }
      function benefit(item) { return Number(item.type) === 1 ? `${money(item.value)} 立减` : `优惠 ${Number(item.value)}%`; }
      function renderCampaigns(payload) {
        const rows = payload.data || [];
        state.campaigns = rows;
        state.lastPage = Math.max(1, Number(payload.last_page || 1));
        $('#summary').textContent = `共 ${payload.total || 0} 个活动`;
        $('#page-info').textContent = `${payload.current_page || 1} / ${state.lastPage}`;
        $('#prev').disabled = state.page <= 1; $('#next').disabled = state.page >= state.lastPage;
        if (!rows.length) { $('#campaigns').innerHTML = '<tr><td colspan="7" class="empty">暂无活动，创建一个 TG 领取活动开始运营。</td></tr>'; return; }
        $('#campaigns').innerHTML = rows.map(item => {
          const claimed = Number(item.claimed_count || item.claims_count || 0);
          const limit = item.limit_claim == null ? '不限' : item.limit_claim;
          const active = item.enabled && Date.now() / 1000 >= item.claim_started_at && Date.now() / 1000 <= item.claim_ended_at;
          const status = !item.enabled ? '已停用' : active ? '领取中' : Date.now() / 1000 < item.claim_started_at ? '未开始' : '已结束';
          return `<tr>
            <td><div class="main-text">${escapeHtml(item.name)}</div><div class="muted">${escapeHtml(item.description || 'Telegram 优惠活动')}</div></td>
            <td>${escapeHtml(benefit(item))}<div class="muted">领取后 ${item.coupon_valid_days} 天有效</div></td>
            <td>${dateText(item.claim_started_at)}<div class="muted">至 ${dateText(item.claim_ended_at)}</div></td>
            <td>${limit} / ${claimed}</td><td>${item.redeemed_count || 0}</td>
            <td><span class="status ${item.enabled ? '' : 'off'}">${status}</span></td>
            <td><div class="row-actions"><button class="quiet" data-action="claims" data-id="${item.id}">领取记录</button><button class="quiet" data-action="edit" data-id="${item.id}">编辑</button><button class="quiet" data-action="toggle" data-id="${item.id}" data-enabled="${item.enabled ? '1' : '0'}">${item.enabled ? '停用' : '启用'}</button></div></td>
          </tr>`;
        }).join('');
      }
      async function loadCampaigns() {
        $('#campaigns').innerHTML = '<tr><td colspan="7" class="empty">正在加载…</td></tr>';
        try { renderCampaigns(await request(`/fetch?current=${state.page}&pageSize=10`)); }
        catch (error) { $('#campaigns').innerHTML = `<tr><td colspan="7" class="empty">${escapeHtml(error.message)}</td></tr>`; }
      }
      async function loadOptions() {
        const response = await request('/options'); state.options = response.data;
        $('#plan-options').innerHTML = state.options.plans.map(plan => `<label class="check-option"><input type="checkbox" name="limit_plan_ids" value="${plan.id}">${escapeHtml(plan.name)}</label>`).join('') || '<span class="muted">暂无套餐</span>';
        $('#period-options').innerHTML = state.options.periods.map(period => `<label class="check-option"><input type="checkbox" name="limit_period" value="${escapeHtml(period.key)}">${escapeHtml(period.label)}</label>`).join('');
      }
      const toLocalInput = timestamp => {
        const date = new Date(Number(timestamp) * 1000 - new Date().getTimezoneOffset() * 60000);
        return date.toISOString().slice(0, 16);
      };
      const fromLocalInput = value => Math.floor(new Date(value).getTime() / 1000);
      function updateValueLabel() {
        const percent = $('[name="type"]:checked').value === '2';
        $('#value-label').textContent = percent ? '优惠比例（%）' : '立减金额（元）';
        $('#value-hint').textContent = percent ? '例如填写 20 表示优惠原价的 20%，实际支付 80%。' : '按人民币金额填写，系统会换算为分。';
        $('#value').min = percent ? '1' : '0.01'; $('#value').max = percent ? '100' : '';
        $('#value').step = percent ? '1' : '0.01';
      }
      function setChecks(name, values) {
        const selected = new Set((values || []).map(String));
        $$(`[name="${name}"]`).forEach(input => { input.checked = selected.has(input.value); });
      }
      function openEditor(item = null) {
        const form = $('#campaign-form'); form.reset(); $('#form-error').className = 'notice';
        $('#form-title').textContent = item ? '编辑活动' : '新建活动';
        $('[name="id"]').value = item?.id || '';
        $('[name="name"]').value = item?.name || '';
        $('[name="description"]').value = item?.description || '';
        $(`[name="type"][value="${item?.type || 1}"]`).checked = true;
        $('#value').value = item ? (Number(item.type) === 1 ? (Number(item.value) / 100).toFixed(2) : item.value) : '5';
        $('#coupon_valid_days').value = item?.coupon_valid_days || 7;
        const now = Math.floor(Date.now() / 60000) * 60;
        $('#claim_started_at').value = toLocalInput(item?.claim_started_at || now);
        $('#claim_ended_at').value = toLocalInput(item?.claim_ended_at || now + 7 * 86400);
        $('#limit_claim').value = item?.limit_claim ?? '';
        $('[name="enabled"]').checked = item ? Boolean(item.enabled) : true;
        setChecks('limit_plan_ids', item?.limit_plan_ids); setChecks('limit_period', item?.limit_period);
        updateValueLabel(); $('#editor').showModal();
      }
      async function saveCampaign(event) {
        event.preventDefault();
        const form = new FormData(event.currentTarget); const type = Number(form.get('type'));
        const valueInput = Number(form.get('value'));
        const start = fromLocalInput(form.get('claim_started_at')); const end = fromLocalInput(form.get('claim_ended_at'));
        if (!Number.isFinite(start) || !Number.isFinite(end) || end <= start) return showFormError('结束时间必须晚于开始时间。');
        if (!Number.isFinite(valueInput) || valueInput <= 0 || (type === 2 && valueInput > 100)) return showFormError('请填写有效的优惠额度。');
        const selectedPlans = $$('[name="limit_plan_ids"]:checked').map(input => Number(input.value));
        const selectedPeriods = $$('[name="limit_period"]:checked').map(input => input.value);
        const limitClaim = form.get('limit_claim');
        const body = {
          name: form.get('name').trim(), description: form.get('description').trim() || null,
          type, value: type === 1 ? Math.round(valueInput * 100) : Math.round(valueInput),
          claim_started_at: start, claim_ended_at: end,
          coupon_valid_days: Number(form.get('coupon_valid_days')),
          limit_claim: limitClaim ? Number(limitClaim) : null,
          limit_plan_ids: selectedPlans.length ? selectedPlans : null,
          limit_period: selectedPeriods.length ? selectedPeriods : null,
          enabled: form.get('enabled') === 'on',
        };
        if (form.get('id')) body.id = Number(form.get('id'));
        $('#save-button').disabled = true;
        try { await request('/save', { method: 'POST', body: JSON.stringify(body) }); $('#editor').close(); notice('活动已保存'); await loadCampaigns(); }
        catch (error) { showFormError(error.message); }
        finally { $('#save-button').disabled = false; }
      }
      function showFormError(message) { const node = $('#form-error'); node.textContent = message; node.className = 'notice error'; }
      async function saveEnabled(item, enabled) {
        const body = {
          id: Number(item.id), name: item.name, description: item.description || null,
          type: Number(item.type), value: Number(item.value), claim_started_at: Number(item.claim_started_at),
          claim_ended_at: Number(item.claim_ended_at), coupon_valid_days: Number(item.coupon_valid_days),
          limit_claim: item.limit_claim, limit_plan_ids: item.limit_plan_ids, limit_period: item.limit_period, enabled,
        };
        await request('/save', { method: 'POST', body: JSON.stringify(body) });
      }
      async function loadClaims(page = 1) {
        state.claimPage = page;
        $('#claim-rows').innerHTML = '<tr><td colspan="5" class="empty">正在加载…</td></tr>';
        const payload = await request(`/claims?id=${state.claimCampaignId}&current=${page}&pageSize=20`);
        state.claimLastPage = Math.max(1, Number(payload.last_page || 1));
        $('#claim-page-info').textContent = `${payload.current_page || 1} / ${state.claimLastPage}`;
        $('#claim-prev').disabled = page <= 1; $('#claim-next').disabled = page >= state.claimLastPage;
        $('#claim-rows').innerHTML = payload.data.length ? payload.data.map(claim => {
          const status = claim.redeemed_at ? '已核销' : Date.now() / 1000 > claim.expires_at ? '已过期' : '未使用';
          return `<tr><td>${escapeHtml(claim.user?.email || `用户 #${claim.user_id}`)}<div class="muted">TG ${escapeHtml(claim.telegram_id)}</div></td><td><span class="code">${escapeHtml(claim.coupon?.code || '—')}</span></td><td>${dateText(claim.claimed_at)}</td><td>${dateText(claim.expires_at)}</td><td>${status}</td></tr>`;
        }).join('') : '<tr><td colspan="5" class="empty">暂无领取记录</td></tr>';
      }
      async function init() {
        try { await loadOptions(); await loadCampaigns(); }
        catch (error) { errorMessage(error); }
      }
      $('#create').addEventListener('click', () => openEditor());
      $('#refresh').addEventListener('click', loadCampaigns);
      $('#prev').addEventListener('click', () => { if (state.page > 1) { state.page--; loadCampaigns(); } });
      $('#next').addEventListener('click', () => { if (state.page < state.lastPage) { state.page++; loadCampaigns(); } });
      $('#campaign-form').addEventListener('submit', saveCampaign);
      $$('[name="type"]').forEach(input => input.addEventListener('change', updateValueLabel));
      $$('[data-close]').forEach(button => button.addEventListener('click', () => $(`#${button.dataset.close}`).close()));
      $('#campaigns').addEventListener('click', async event => {
        const button = event.target.closest('[data-action]'); if (!button) return;
        const id = Number(button.dataset.id);
        try {
          if (button.dataset.action === 'edit') {
            const item = state.campaigns.find(row => Number(row.id) === id);
            if (item) openEditor(item); else throw new Error('找不到该活动');
          } else if (button.dataset.action === 'toggle') {
            const item = state.campaigns.find(row => Number(row.id) === id);
            if (!item) throw new Error('找不到该活动');
            await saveEnabled(item, button.dataset.enabled !== '1'); await loadCampaigns(); notice('活动状态已更新');
          } else if (button.dataset.action === 'claims') {
            const item = state.campaigns.find(row => Number(row.id) === id);
            $('#claims-title').textContent = `${item?.name || '活动'} · 领取记录`;
            state.claimCampaignId = id; $('#claims-dialog').showModal(); await loadClaims(1);
          }
        } catch (error) { errorMessage(error); }
      });
      $('#claim-prev').addEventListener('click', () => { if (state.claimPage > 1) loadClaims(state.claimPage - 1).catch(errorMessage); });
      $('#claim-next').addEventListener('click', () => { if (state.claimPage < state.claimLastPage) loadClaims(state.claimPage + 1).catch(errorMessage); });
      init();
    })();
  </script>
</body>
</html>
