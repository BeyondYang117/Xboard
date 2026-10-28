(() => {
  'use strict';
  const keys = ['keyword', 'show', 'source', 'status', 'deployment', 'tag', 'port', 'rate_min', 'rate_max', 'online_min', 'online_max', 'protocols', 'region', 'management_group', 'provider', 'subscription', 'transport', 'tag_exact'];
  const dimensions = { protocol: 'protocols', region: 'region', management_group: 'management_group', provider: 'provider', subscription: 'subscription', transport: 'transport', tag: 'tag_exact' };
  const base = `/api/v2/${window.settings.secure_path}/server/manage/`;
  const storageKey = `xboard.nodeFilters.${window.settings.secure_path}`;
  const viewsKey = `${storageKey}.views`;
  const onNodePage = () => /\/server\/manage\/?$/.test((location.hash ? location.hash.slice(1) : location.pathname).split('?')[0]);
  function clean(input) {
    const result = {};
    for (const key of keys) {
      if (key === 'protocols' && Array.isArray(input?.[key])) result[key] = input[key].filter((value) => typeof value === 'string');
      else if (typeof input?.[key] === 'string' && input[key] !== '') result[key] = input[key];
    }
    return result;
  }
  let filters = {}, dimension = 'protocol';
  try {
    filters = clean(JSON.parse(sessionStorage.getItem(storageKey) || '{}'));
    const saved = sessionStorage.getItem(`${storageKey}.dimension`);
    if (saved in dimensions) dimension = saved;
  } catch { /* Ignore invalid saved state. */ }
  function paramsFor(values) {
    const params = new URLSearchParams();
    for (const [key, value] of Object.entries(values)) {
      if (Array.isArray(value)) value.forEach((item) => params.append(`node_filter[${key}][]`, item));
      else params.set(`node_filter[${key}]`, value);
    }
    return params;
  }
  // Adapt only this list's GET request; leave native table selection and actions intact.
  function withFilters(input) {
    if (!onNodePage()) return input;
    const url = new URL(input, location.href);
    if (url.origin !== location.origin || url.pathname !== `${base}getNodes`) return input;
    for (const [key, value] of paramsFor(filters)) url.searchParams.append(key, value);
    return url.href;
  }
  const originalOpen = XMLHttpRequest.prototype.open;
  XMLHttpRequest.prototype.open = function (method, url, ...args) {
    return originalOpen.call(this, method, String(method).toUpperCase() === 'GET' ? withFilters(String(url)) : url, ...args);
  };
  const originalFetch = window.fetch;
  window.fetch = function (input, init) {
    const method = init?.method || (input instanceof Request ? input.method : 'GET');
    if (String(method).toUpperCase() === 'GET') {
      const url = input instanceof Request ? input.url : String(input), filtered = withFilters(url);
      if (filtered !== url) input = input instanceof Request ? new Request(filtered, input) : filtered;
    }
    return originalFetch.call(this, input, init);
  };
  function token() {
    const entry = JSON.parse(localStorage.getItem('XBOARD_ACCESS_TOKEN') || 'null');
    if (!entry || (entry.expire && entry.expire <= Date.now())) throw new Error('请先登录管理后台');
    return entry.value;
  }
  async function api(path, data) {
    const headers = { Accept: 'application/json', Authorization: token() };
    if (data) headers['Content-Type'] = 'application/json';
    const response = await originalFetch(`${base}${path}`, { method: data ? 'POST' : 'GET', headers, body: data ? JSON.stringify(data) : undefined, credentials: 'same-origin', cache: 'no-store' });
    const result = await response.json();
    if (!response.ok) throw new Error([result.message || '请求失败', ...Object.values(result.errors || {}).flat()].join('\n'));
    return result.data;
  }
  function metadata() { return api(`dimensions?${paramsFor(filters)}&dimension=${encodeURIComponent(dimension)}`); }
  function option(key, label) { const item = document.createElement('option'); item.value = key; item.textContent = label; return item; }
  function save(next) {
    sessionStorage.setItem(storageKey, JSON.stringify(clean(next)));
    sessionStorage.setItem(`${storageKey}.dimension`, dimension);
    location.reload();
  }
  function readFilters(form) {
    const next = {};
    for (const key of keys) {
      const control = form.elements.namedItem(key);
      control.setCustomValidity('');
      const value = key === 'protocols' ? [...control.selectedOptions].map((item) => item.value) : control.value.trim();
      if (value.length) next[key] = value;
    }
    for (const prefix of ['rate', 'online']) {
      const min = next[`${prefix}_min`], max = next[`${prefix}_max`];
      if (min !== undefined && max !== undefined && Number(max) < Number(min)) {
        form.querySelector('details').open = true;
        const control = form.elements.namedItem(`${prefix}_max`);
        control.setCustomValidity('上限不能小于下限'); control.reportValidity(); return null;
      }
    }
    return form.reportValidity() ? next : null;
  }
  function loadViews() {
    try { const views = JSON.parse(localStorage.getItem(viewsKey) || '[]'); return Array.isArray(views) ? views.filter((item) => typeof item.name === 'string').slice(0, 20) : []; }
    catch { return []; }
  }
  function mount() {
    const existing = document.getElementById('node-filters');
    if (!onNodePage()) { existing?.remove(); return; }
    if (existing) return;
    const table = document.querySelector('#root table'), template = document.getElementById('node-filters-template');
    if (!table || !template) return;
    const form = template.content.firstElementChild.cloneNode(true);
    const status = form.querySelector('[role=status]');
    const showError = (error) => { status.textContent = error.message; };
    let metadataVersion = 0;
    function restore() {
      for (const key of keys) {
        const control = form.elements.namedItem(key);
        if (key === 'protocols') for (const item of control.options) item.selected = (filters.protocols || []).includes(item.value);
        else {
          if (filters[key] && control.tagName === 'SELECT' && ![...control.options].some((item) => item.value === filters[key])) control.append(option(filters[key], `${filters[key]}（当前条件）`));
          control.value = filters[key] || '';
        }
      }
      form.querySelector('[data-dimension]').value = dimension;
    }
    function renderGroups(data) {
      const cards = form.querySelector('[data-cards]'); cards.replaceChildren();
      const key = dimensions[dimension];
      const selected = (value) => key === 'protocols' ? (filters.protocols || []).includes(value) : filters[key] === value;
      function add(label, value, active) {
        const button = document.createElement('button'); button.type = 'button'; button.textContent = label; button.setAttribute('aria-pressed', String(active));
        button.addEventListener('click', () => {
          const next = { ...filters };
          if (value === null) delete next[key];
          else if (key === 'protocols') next[key] = selected(value) ? (next[key] || []).filter((item) => item !== value) : [...(next[key] || []), value];
          else if (selected(value)) delete next[key];
          else next[key] = value;
          try { save(next); } catch (error) { showError(error); }
        }); cards.append(button);
      }
      add('全部分组', null, !filters[key]?.length);
      for (const group of data.groups) add(`${group.label} · ${group.count}`, group.key, selected(group.key));
      form.querySelector('[data-total]').textContent = `共 ${data.total} 个节点 · 高级条件命中 ${data.matched} 个`;
    }
    async function refreshMetadata() {
      const version = ++metadataVersion;
      status.textContent = '正在加载分组…';
      try {
        const data = await metadata(); if (!form.isConnected || version !== metadataVersion) return;
        const protocols = form.elements.namedItem('protocols');
        protocols.replaceChildren(...Object.entries(data.protocols).map(([key, label]) => option(key, label)));
        for (const key of ['region', 'management_group', 'provider', 'subscription', 'transport', 'tag_exact']) {
          const control = form.elements.namedItem(key), axis = key === 'tag_exact' ? 'tag' : key;
          control.replaceChildren(option('', '全部'), ...data.options[axis].map((item) => option(item.key, `${item.label} (${item.count})`)));
        }
        restore(); renderGroups(data);
        form.querySelector('[data-probe]').disabled = !data.matched || data.matched > 200;
        form.querySelector('[data-classify]').disabled = !data.matched || data.matched > data.selection_limit;
        status.textContent = data.matched > data.selection_limit ? '批量分类最多 5000 个节点，请缩小筛选范围。' : `已应用 ${Object.keys(filters).filter((key) => filters[key].length).length} 个条件${data.matched > 200 ? ' · 连通性测试最多 200 个节点，请缩小范围' : ''}`;
      } catch (error) { if (version === metadataVersion) showError(error); }
    }
    form.addEventListener('submit', (event) => { event.preventDefault(); const next = readFilters(form); if (next) try { save(next); } catch (error) { showError(error); } });
    form.addEventListener('input', (event) => event.target.setCustomValidity?.(''));
    form.querySelector('[data-reset]').addEventListener('click', () => { try { save({}); } catch (error) { showError(error); } });
    form.querySelector('[data-dimension]').addEventListener('change', async (event) => { dimension = event.target.value; try { sessionStorage.setItem(`${storageKey}.dimension`, dimension); await refreshMetadata(); } catch (error) { showError(error); } });
    form.querySelector('[data-classify]').addEventListener('click', async () => {
      try { status.textContent = '正在核对分类范围…'; const current = await metadata(); openClassification(current); status.textContent = ''; }
      catch (error) { showError(error); }
    });
    const viewSelect = form.querySelector('[data-views]');
    function renderViews() { viewSelect.replaceChildren(option('', '选择已保存的视图'), ...loadViews().map((item, index) => option(String(index), item.name))); }
    renderViews();
    viewSelect.addEventListener('change', () => { const view = loadViews()[Number(viewSelect.value)]; if (viewSelect.value !== '' && view) { dimension = view.dimension in dimensions ? view.dimension : 'protocol'; try { save(view.filters); } catch (error) { showError(error); } } });
    form.querySelector('[data-save-view]').addEventListener('click', () => {
      const name = form.querySelector('[data-view-name]').value.trim(), next = readFilters(form); if (!next) return;
      if (!name) { status.textContent = '请填写视图名称'; return; }
      const views = loadViews(), index = views.findIndex((item) => item.name === name);
      if (index < 0 && views.length >= 20) { status.textContent = '最多保存 20 个视图'; return; }
      const view = { name, filters: next, dimension }; if (index < 0) views.push(view); else views[index] = view;
      try { localStorage.setItem(viewsKey, JSON.stringify(views)); renderViews(); status.textContent = `已保存视图：${name}`; } catch (error) { showError(error); }
    });
    form.querySelector('[data-delete-view]').addEventListener('click', () => { if (viewSelect.value === '') return; const views = loadViews(); views.splice(Number(viewSelect.value), 1); try { localStorage.setItem(viewsKey, JSON.stringify(views)); renderViews(); status.textContent = '已删除所选视图'; } catch (error) { showError(error); } });
    const container = table.parentElement; container.parentElement.insertBefore(form, container);
    restore(); refreshMetadata();
  }
  let busy = false;
  function openClassification(data) {
    if (!data.matched || data.matched > data.selection_limit) throw new Error('请将筛选结果控制在 1 至 5000 个节点');
    const dialog = document.getElementById('node-classify-dialog'), form = dialog.querySelector('form'), list = dialog.querySelector('[data-node-list]'), status = dialog.querySelector('[data-classify-status]');
    form.reset(); status.textContent = ''; status.dataset.error = 'false'; list.replaceChildren();
    const region = form.elements.namedItem('region');
    region.replaceChildren(option('', '自动识别（清除人工覆盖）'), option('ZZ', '未识别地区'), ...data.regions.map((item) => option(item.key, item.label)));
    for (const key of ['region', 'management_group', 'provider']) form.elements.namedItem(key).disabled = true;
    for (const node of data.nodes) {
      const label = document.createElement('label'), input = document.createElement('input'); input.type = 'checkbox'; input.value = node.id; input.dataset.nodeId = node.id;
      label.append(input, document.createTextNode(`#${node.id} ${node.name} · ${node.protocol} · ${node.region}${node.region_origin === 'name' ? '（推测）' : ''} · ${node.management_group || '未分组'} · ${node.provider || '未标运营商'}`)); list.append(label);
    }
    for (const [key, id] of [['management_group', 'nf-management-groups'], ['provider', 'nf-providers']]) document.getElementById(id).replaceChildren(...data.options[key].filter((item) => item.key !== '__unclassified__').map((item) => option(item.key, item.label)));
    function updateCount() { dialog.querySelector('[data-selection-count]').textContent = `已选 ${list.querySelectorAll('input:checked').length} / ${data.nodes.length} 个节点`; }
    list.onchange = updateCount;
    dialog.querySelector('[data-select-all]').onclick = () => { list.querySelectorAll('input').forEach((input) => { input.checked = true; }); updateCount(); };
    dialog.querySelector('[data-select-none]').onclick = () => { list.querySelectorAll('input').forEach((input) => { input.checked = false; }); updateCount(); };
    for (const key of ['region', 'management_group', 'provider']) form.elements.namedItem(`set_${key}`).onchange = (event) => { form.elements.namedItem(key).disabled = !event.target.checked; };
    dialog.querySelector('[data-classify-close]').onclick = () => { if (!busy) dialog.close(); };
    dialog.oncancel = (event) => { if (busy) event.preventDefault(); };
    form.onsubmit = async (event) => {
      event.preventDefault(); if (busy) return;
      const body = { ids: [...list.querySelectorAll('input:checked')].map((input) => Number(input.value)) };
      if (!body.ids.length) { status.textContent = '请先勾选节点'; return; }
      for (const key of ['region', 'management_group', 'provider']) if (form.elements.namedItem(`set_${key}`).checked) body[key] = form.elements.namedItem(key).value.trim() || null;
      for (const key of ['add_tags', 'remove_tags']) { const values = [...new Set(form.elements.namedItem(key).value.split(/[,，\n]/).map((value) => value.trim()).filter(Boolean))]; if (values.length) body[key] = values; }
      if (Object.keys(body).length === 1) { status.textContent = '请至少选择一个修改字段或填写标签'; return; }
      busy = true; form.querySelectorAll('button, input, select').forEach((control) => { control.disabled = true; }); status.textContent = '正在保存分类…';
      try { const result = await api('batchClassify', body); status.textContent = `已更新 ${result.updated} 个节点，正在刷新列表…`; location.reload(); }
      catch (error) { status.textContent = error.message; status.dataset.error = 'true'; }
      finally { busy = false; form.querySelectorAll('button, input, select').forEach((control) => { control.disabled = false; }); for (const key of ['region', 'management_group', 'provider']) form.elements.namedItem(key).disabled = !form.elements.namedItem(`set_${key}`).checked; }
    };
    updateCount(); dialog.showModal();
  }
  window.XboardNodeManagement = Object.freeze({ api, metadata });
  document.addEventListener('DOMContentLoaded', () => {
    let scheduled = false;
    new MutationObserver(() => { if (scheduled) return; scheduled = true; requestAnimationFrame(() => { scheduled = false; mount(); }); }).observe(document.getElementById('root'), { childList: true, subtree: true });
    mount();
  });
  window.addEventListener('popstate', mount); window.addEventListener('hashchange', mount);
})();
