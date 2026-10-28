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
      message(data.length ? '' : '请先在后台创建至少一个权限组。');
    } catch (error) { message(error.message, true); }
    finally { setBusy(false); }
  });
  function close() {
    if (busy) return;
    dialog.close();
    resetPreview();
    find('url').value = '';
    if (imported) location.reload();
  }
  find('close').addEventListener('click', close);
  dialog.addEventListener('cancel', (event) => { event.preventDefault(); close(); });
  find('url').addEventListener('input', () => { resetPreview(); message(''); });
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
      const data = await api('server/manage/importSubscription', {
        preview_token: preview.preview_token, node_keys: nodeKeys, group_ids: groupIds, show: find('show').checked,
      });
      imported = true;
      resetPreview();
      message(`导入完成：新增 ${data.created} 个，更新 ${data.updated} 个。关闭窗口后刷新节点列表。`);
    } catch (error) { message(error.message, true); }
    finally { setBusy(false); }
  });
})();
