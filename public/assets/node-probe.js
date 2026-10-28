(() => {
  'use strict';
  const names = { ok: '成功', failed: '失败', unsupported: '不支持', blocked: '已跳过', skipped: '已跳过', error: '环境 / 请求异常' };
  const stages = { address: '地址解析', tcp: 'TCP 连接', credentials: '认证账号', config: '协议配置', engine: '测试内核', proxy: '代理请求', http: '网站响应', target: '测试目标' };
  let running = false, stopped = false, rows = [], history = {}, targets = {};
  document.addEventListener('DOMContentLoaded', () => {
    const dialog = document.getElementById('node-probe-dialog');
    const get = (name) => dialog.querySelector(`[data-${name}]`);
    const management = window.XboardNodeManagement;
    const mode = () => get('mode').value;
    function selectedCount() { get('selected').textContent = `已选 ${rows.filter((row) => row.input.checked).length} / ${rows.length} 个`; }
    function display(row, result) {
      row.result = result;
      row.state.dataset.state = result?.state || '';
      row.time.textContent = result?.latency_ms != null ? `${result.latency_ms} ms` : '—';
      row.state.replaceChildren();
      if (!result) { row.state.textContent = '未测试'; return; }
      const text = document.createElement('div'); text.textContent = `${names[result.state] || result.state}${result.stage ? ` · ${stages[result.stage] || result.stage}` : ''}：${result.message}`; row.state.append(text);
      if (result.tested_at) { const stamp = document.createElement('small'); stamp.textContent = `${new Date(result.tested_at * 1000).toLocaleString()}${result.target ? ` · ${targets[result.target] || result.target}` : ''}`; row.state.append(stamp); }
    }
    function modeChanged() {
      get('target-label').hidden = mode() === 'tcp'; get('user-label').hidden = mode() === 'tcp';
      for (const row of rows) display(row, history[row.node.id]?.[mode()] || null);
    }
    function setRunning(value) {
      running = value;
      dialog.querySelectorAll('input, select, button').forEach((control) => { control.disabled = value; });
      get('stop').disabled = !value || stopped;
    }
    get('mode').addEventListener('change', modeChanged);
    get('all').addEventListener('click', () => { rows.forEach((row) => { row.input.checked = true; }); selectedCount(); });
    get('none').addEventListener('click', () => { rows.forEach((row) => { row.input.checked = false; }); selectedCount(); });
    get('failed').addEventListener('click', () => { rows.forEach((row) => { row.input.checked = ['failed', 'error'].includes(row.result?.state); }); selectedCount(); });
    get('close').addEventListener('click', () => { if (!running) dialog.close(); });
    dialog.addEventListener('cancel', (event) => { if (running) event.preventDefault(); });
    get('stop').addEventListener('click', () => { stopped = true; get('stop').disabled = true; get('progress').textContent = '已停止派发，等待正在测试的节点完成…'; });
    get('start').addEventListener('click', async () => {
      if (running) return;
      const selected = rows.filter((row) => row.input.checked);
      if (!selected.length) { get('progress').textContent = '请先勾选要测试的节点'; return; }
      if (mode() === 'proxy' && !get('user-id').reportValidity()) return;
      const selectedMode = mode(), target = get('target').value, userId = get('user-id').value;
      stopped = false; setRunning(true);
      let next = 0, completed = 0, passed = 0, failed = 0, skipped = 0;
      const progress = () => { get('progress').textContent = `${stopped ? '已停止后续测试 · ' : ''}完成 ${completed}/${selected.length} · 成功 ${passed} · 失败/异常 ${failed} · 跳过 ${skipped}`; };
      progress();
      async function worker() {
        while (!stopped && next < selected.length) {
          const row = selected[next++]; row.state.textContent = '正在测试…'; row.state.dataset.state = ''; row.time.textContent = '—';
          let result;
          try { result = await management.api('probe', { id: row.node.id, mode: selectedMode, target, ...(selectedMode === 'proxy' && userId ? { user_id: Number(userId) } : {}) }); }
          catch (error) { result = { state: 'error', message: error.message }; }
          display(row, result);
          history[row.node.id] ||= {}; history[row.node.id][selectedMode] = result;
          completed++;
          if (result.state === 'ok') passed++; else if (['failed', 'error'].includes(result.state)) failed++; else skipped++;
          progress();
        }
      }
      try { await Promise.all([worker(), worker()]); }
      finally { setRunning(false); progress(); }
    });
    document.addEventListener('click', async (event) => {
      const button = event.target.closest('[data-probe]'); if (!button || running) return;
      button.disabled = true;
      try {
        const metadata = await management.metadata();
        if (!metadata.matched || metadata.matched > 200) throw new Error('请将高级筛选结果控制在 1 至 200 个节点');
        const data = await management.api('probeHistory', { ids: metadata.nodes.map((node) => node.id) });
        history = data.results; targets = Object.fromEntries(data.targets.map((item) => [item.key, item.name]));
        get('target').replaceChildren(...data.targets.map((item) => { const option = document.createElement('option'); option.value = item.key; option.textContent = item.name; return option; }));
        get('mode').value = data.proxy_available ? 'proxy' : 'tcp'; get('user-id').value = '';
        get('environment').textContent = data.proxy_available ? '代理测试内核可用；自建节点请填写有权限、未到期且有额度的测试账号 ID。' : '此运行环境缺少代理测试内核，可先测试 TCP 端口。代理实测需要升级镜像。';
        get('progress').textContent = ''; get('rows').replaceChildren();
        rows = metadata.nodes.map((node) => {
          const tr = document.createElement('tr'), choose = document.createElement('td'), input = document.createElement('input'), name = document.createElement('td'), state = document.createElement('td'), time = document.createElement('td');
          input.type = 'checkbox'; input.setAttribute('aria-label', `测试节点 ${node.id} ${node.name}`); input.addEventListener('change', selectedCount); choose.append(input);
          name.textContent = `#${node.id} ${node.name} · ${node.protocol}`; tr.append(choose, name, state, time); get('rows').append(tr);
          return { node, input, state, time, result: null };
        });
        setRunning(false); modeChanged(); selectedCount(); dialog.showModal();
      } catch (error) { const status = document.querySelector('#node-filters [role=status]'); if (status) status.textContent = error.message; }
      finally { button.disabled = false; }
    });
  });
})();
