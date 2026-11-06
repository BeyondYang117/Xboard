(() => {
  'use strict';
  const find = (id) => document.getElementById(`subscription-import-${id}`);
  const dialog = find('dialog');
  const open = find('open');
  const status = find('status');
  const selection = find('selection');
  const confirm = find('confirm');
  const groups = find('groups');
  const nodes = find('nodes');
  let preview = null;
  let busy = false;
  let imported = false;
  let editing = null;
  let sources = [];
  let gateway = { host: null, port: 2443, online: false };
  const number = (id) => Number(find(id).value);
  const gb = (value) => `${(Number(value) / 1073741824).toFixed(2)} GB`;

  function settings(source = {}) {
    find('name').value = source.name || '';
    find('mode').value = source.delivery_mode || 'relay';
    find('rate').value = source.rate ?? 1;
    find('limit').value = source.max_online ?? 0;
    find('limit-mode').value = source.online_limit_mode || 'ip';
    find('gateway').value = gateway.host || source.gateway_host || '';
    find('port').value = gateway.host ? gateway.port : (source.gateway_port || 2443);
    find('gateway').readOnly = Boolean(gateway.host);
    find('port').readOnly = Boolean(gateway.host);
    find('quota').value = Number(source.transfer_enable || 0) / 1073741824;
    const date = source.expired_at ? new Date(source.expired_at * 1000) : null;
    find('expiry').value = date ? new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0, 16) : '';
    for (const option of groups.options) option.selected = (source.group_ids || []).map(String).includes(option.value);
  }
  function options() {
    const data = {
      delivery_mode: find('mode').value, rate: number('rate'), max_online: number('limit'),
      online_limit_mode: find('limit-mode').value, gateway_host: find('gateway').value.trim() || null,
      gateway_port: number('port'), transfer_enable: Math.round(number('quota') * 1073741824),
      expired_at: find('expiry').value ? Math.floor(new Date(find('expiry').value).getTime() / 1000) : null,
      group_ids: [...groups.selectedOptions].map((option) => Number(option.value)),
    };
    if (find('name').value.trim()) data.name = find('name').value.trim();
    if (data.delivery_mode === 'relay' && !data.gateway_host) throw new Error('请先配置统一中转入口，或填写已有中转域名和端口。');
    if (data.delivery_mode === 'direct' && (data.max_online || data.transfer_enable)) throw new Error('直连模式无法统计或限制在线数、机场流量，请改用中转计费。');
    if (!data.group_ids.length) throw new Error('请至少选择一个权限组。');
    for (const id of ['rate', 'limit', 'port', 'quota', 'expiry']) {
      if (!find(id).checkValidity()) throw new Error('请检查倍率、在线数、端口、流量及日期格式。');
    }
    return data;
  }
  async function loadSources() {
    sources = await api('server/manage/subscriptions');
    const body = find('sources'); body.replaceChildren();
    if (!sources.length) { const row = body.insertRow(); const cell = row.insertCell(); cell.colSpan = 5; cell.textContent = '暂无机场，粘贴下方订阅链接开始导入。'; }
    for (const source of sources) {
      const row = body.insertRow();
      const state = !source.enabled ? '已停用' : !source.available ? '到期 / 流量已满' : source.delivery_mode === 'relay' ? (source.gateway_online ? '中转在线' : '中转离线') : '直连';
      for (const value of [`${source.name} (#${source.id})`, `${state} · ${source.nodes_count} 节点`,
        source.delivery_mode === 'direct' ? '无法统计' : `${source.online_users} / ${source.online_ips} / ${source.online_connections}`,
        source.delivery_mode === 'direct' ? '无法统计' : `${gb(source.u + source.d)} / ${source.transfer_enable ? gb(source.transfer_enable) : '不限'}`]) {
        row.insertCell().textContent = value;
      }
      const actions = row.insertCell();
      for (const [action, label] of [['edit', '设置'], ['toggle', source.enabled ? '停用' : '启用'], ['show', '显示全部'], ['hide', '隐藏全部'], ['reset', '重置周期流量'], ['delete', '删除机场及节点']]) {
        const button = document.createElement('button'); button.type = 'button'; button.textContent = label;
        button.dataset.source = source.id; button.dataset.action = action; actions.append(button);
      }
    }
  }

  function token() {
    try {
      const entry = JSON.parse(localStorage.getItem('XBOARD_ACCESS_TOKEN') || 'null');
      return entry && (!entry.expire || entry.expire > Date.now()) ? entry.value : null;
    } catch { return null; }
  }
  function updateEntry() {
    open.hidden = !token() || !/\/server(?:\/|$|\?)/.test(location.pathname + location.hash);
  }
  updateEntry();
  window.addEventListener('popstate', updateEntry);
  window.addEventListener('hashchange', updateEntry);
  setInterval(updateEntry, 1000);

  function message(text, error = false) {
    status.textContent = text;
    status.dataset.error = String(error);
  }
  function setBusy(value) {
    busy = value;
    for (const control of dialog.querySelectorAll('button, input, select')) control.disabled = value;
  }
  function resetPreview() {
    preview = null;
    selection.hidden = true;
    confirm.hidden = true;
    nodes.replaceChildren();
    find('settings').hidden = true;
  }
  async function api(path, data) {
    const auth = token();
    if (!auth) throw new Error('请先登录管理后台。');
    const headers = { Accept: 'application/json', Authorization: auth };
    if (data) headers['Content-Type'] = 'application/json';
    const response = await fetch(`/api/v2/${window.settings.secure_path}/${path}`, {
      method: data ? 'POST' : 'GET', headers, body: data ? JSON.stringify(data) : undefined,
      credentials: 'same-origin', cache: 'no-store',
    });
    const result = await response.json();
    if (!response.ok) {
      const errors = result.errors ? Object.values(result.errors).flat().join('\n') : '';
      const skipped = Array.isArray(result.error) ? result.error.map((item) => `第 ${item.index} 项：${item.reason}`).join('\n') : '';
      throw new Error([result.message || '请求失败', errors, skipped].filter(Boolean).join('\n'));
    }
    return result.data;
  }
  open.addEventListener('click', async () => {
    dialog.showModal();
    message('正在加载权限组…');
    setBusy(true);
    try {
      const data = await api('server/group/fetch');
      groups.replaceChildren(...data.map((group) => {
        const option = document.createElement('option');
        option.value = group.id;
        option.textContent = group.name;
        return option;
      }));
      gateway = await api('server/manage/subscriptionGateway');
      find('gateway-status').textContent = gateway.host
        ? `统一中转：${gateway.host}:${gateway.port} · ${gateway.online ? '服务在线' : '服务离线'}。新机场自动使用此入口，无需单独部署。`
        : '统一中转尚未配置。管理员只需配置一次中转域名、证书并启动服务，之后导入机场自动接入。';
      await loadSources();
      message(data.length ? '' : '请先在后台创建至少一个权限组。');
    } catch (error) { message(error.message, true); }
    finally { setBusy(false); }
  });
  function close() {
    if (busy) return;
    dialog.close();
    resetPreview();
    find('url').value = '';
    editing = null; find('save').hidden = true; find('cancel-edit').hidden = true;
    find('form').hidden = false;
    if (imported) location.reload();
  }
  find('close').addEventListener('click', close);
  dialog.addEventListener('cancel', (event) => { event.preventDefault(); close(); });
  find('url').addEventListener('input', () => { resetPreview(); message(''); });
  find('refresh').addEventListener('click', async () => {
    if (busy) return;
    setBusy(true);
    try { await loadSources(); message('统计已刷新。'); } catch (error) { message(error.message, true); }
    finally { setBusy(false); }
  });
  find('cancel-edit').addEventListener('click', () => {
    editing = null; find('save').hidden = true; find('cancel-edit').hidden = true;
    find('form').hidden = false; resetPreview(); message('');
  });
  find('sources').addEventListener('click', async (event) => {
    const button = event.target.closest('button[data-source]');
    if (!button || busy) return;
    const source = sources.find((item) => item.id === Number(button.dataset.source));
    if (!source) return;
    const action = button.dataset.action;
    if (action === 'edit') {
      resetPreview(); editing = source.id; settings(source);
      find('form').hidden = true; find('settings').hidden = false;
      find('settings-title').textContent = `编辑机场：${source.name}`;
      find('save').hidden = false; find('cancel-edit').hidden = false;
      message('保存后权限组和倍率会应用到此机场全部节点。中转域名或端口变更后需要重启中转服务。'); return;
    }
    if (action === 'delete' && !window.confirm(`删除「${source.name}」及其 ${source.nodes_count} 个节点？历史流量记录会保留；客户端已保存的直连凭据需要在机场端撤销。`)) return;
    if (action === 'reset' && !window.confirm(`重置「${source.name}」的机场周期用量？历史统计与用户已扣流量保留。`)) return;
    setBusy(true);
    try {
      const data = { id: source.id };
      if (action === 'toggle') data.enabled = !source.enabled;
      if (action === 'show' || action === 'hide') data.show = action === 'show';
      if (action === 'reset') data.reset_usage = true;
      await api(`server/manage/${action === 'delete' ? 'deleteSubscription' : 'saveSubscription'}`, data);
      imported = true; await loadSources(); message('机场操作已完成。');
      if (editing === source.id && action === 'delete') {
        editing = null; find('save').hidden = true; find('cancel-edit').hidden = true;
        find('form').hidden = false; resetPreview();
      }
    } catch (error) { message(error.message, true); } finally { setBusy(false); }
  });
  find('save').addEventListener('click', async () => {
    if (busy || !editing) return;
    try {
      const data = options();
      setBusy(true); await api('server/manage/saveSubscription', { id: editing, ...data });
      imported = true; await loadSources(); message('机场设置已保存。');
    } catch (error) { message(error.message, true); } finally { setBusy(false); }
  });
  find('all').addEventListener('change', (event) => {
    for (const input of nodes.querySelectorAll('input')) input.checked = event.target.checked;
  });
  nodes.addEventListener('change', () => {
    const inputs = [...nodes.querySelectorAll('input')];
    find('all').checked = inputs.every((input) => input.checked);
    find('all').indeterminate = inputs.some((input) => input.checked) && !find('all').checked;
  });
  find('form').addEventListener('submit', async (event) => {
    event.preventDefault();
    if (busy) return;
    resetPreview();
    message('正在获取并解析订阅…');
    setBusy(true);
    try {
      preview = await api('server/manage/previewSubscription', { subscription_url: find('url').value.trim() });
      settings(preview.subscription || {});
      find('settings-title').textContent = preview.subscription ? `更新机场：${preview.subscription.name}` : '新机场设置';
      for (const node of preview.nodes) {
        const row = document.createElement('tr');
        const cell = document.createElement('td');
        const input = document.createElement('input');
        input.type = 'checkbox'; input.checked = true; input.value = node.key;
        input.setAttribute('aria-label', `选择 ${node.name}`);
        cell.append(input); row.append(cell);
        for (const value of [node.name, node.type, `${node.host}:${node.port}`]) {
          const td = document.createElement('td'); td.textContent = value; row.append(td);
        }
        nodes.append(row);
      }
      find('summary').textContent = `可导入 ${preview.nodes.length} 个节点，跳过 ${preview.skipped.length} 项。`;
      const skipped = find('skipped');
      skipped.hidden = !preview.skipped.length;
      skipped.querySelector('ul').replaceChildren(...preview.skipped.map((item) => {
        const li = document.createElement('li'); li.textContent = `第 ${item.index} 项：${item.reason}`; return li;
      }));
      find('all').checked = true; find('all').indeterminate = false;
      selection.hidden = false; confirm.hidden = false;
      find('settings').hidden = false;
      message('请选择要导入的节点和权限组。');
    } catch (error) { message(error.message, true); }
    finally { setBusy(false); }
  });
  confirm.addEventListener('click', async () => {
    if (busy || !preview) return;
    const nodeKeys = [...nodes.querySelectorAll('input:checked')].map((input) => input.value);
    const groupIds = [...groups.selectedOptions].map((option) => Number(option.value));
    if (!nodeKeys.length || !groupIds.length) {
      message('请至少选择一个节点和一个权限组。', true); return;
    }
    setBusy(true); message('正在导入节点…');
    try {
      const config = options();
      const data = await api('server/manage/importSubscription', {
        preview_token: preview.preview_token, node_keys: nodeKeys, ...config, show: find('show').checked,
      });
      imported = true;
      resetPreview();
      await loadSources();
      message(`导入完成：新增 ${data.created} 个，更新 ${data.updated} 个。关闭窗口后刷新节点列表。`);
    } catch (error) { message(error.message, true); }
    finally { setBusy(false); }
  });
})();
