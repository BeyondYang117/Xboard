<style>
  #node-filters { margin: 12px 0; padding: 14px; border: 1px solid #e2e8f0; border-radius: 8px; background: #f8fafc; color: #0f172a; font: 13px/1.5 system-ui; }
  #node-filters .nf-fields { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 10px; align-items: end; }
  #node-filters label { display: flex; flex-direction: column; gap: 4px; }
  #node-filters input, #node-filters select, #node-filters button { box-sizing: border-box; min-height: 34px; border: 1px solid #cbd5e1; border-radius: 6px; padding: 6px 9px; background: white; color: #0f172a; font: inherit; }
  #node-filters input[type=number] { width: 100px; }
  #node-filters input[type=search] { width: min(240px, 100%); }
  #node-filters button { cursor: pointer; }
  #node-filters button[type=submit] { background: #2563eb; color: white; border-color: #2563eb; }
  #node-filters .nf-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; margin-top: 12px; }
  #node-filters .nf-hint { margin: 8px 0 0; color: #64748b; }
  #node-filters [role=status] { color: #1d4ed8; }
  .dark #node-filters { background: #18181b; color: #fafafa; border-color: #3f3f46; }
  .dark #node-filters input, .dark #node-filters select, .dark #node-filters button[type=button] { background: #27272a; color: #fafafa; border-color: #52525b; }
  #node-filters .nf-heading { display:flex; align-items:center; flex-wrap:wrap; gap:12px; }
  #node-filters .nf-heading label { flex-direction:row; align-items:center; }
  #node-filters .nf-cards { display:flex; gap:8px; flex-wrap:wrap; max-height:160px; overflow:auto; margin:12px 0; }
  #node-filters .nf-cards button[aria-pressed=true] { background:#dbeafe; border-color:#2563eb; color:#1e40af; }
  #node-filters summary { cursor:pointer; margin-top:12px; }
  #node-filters select[multiple] { min-width:170px; }
  #node-filters button:disabled { opacity:.5; cursor:default; }
  #node-classify-dialog { box-sizing:border-box; width:min(920px,calc(100% - 24px)); max-height:90vh; overflow:auto; padding:24px; border:1px solid #cbd5e1; border-radius:10px; background:#fff; color:#0f172a; font:14px/1.6 system-ui; }
  #node-classify-dialog::backdrop { background:#0007; }
  #node-classify-dialog h2 { font-size:20px; font-weight:600; }
  #node-classify-dialog input, #node-classify-dialog select, #node-classify-dialog button { border:1px solid #cbd5e1; border-radius:5px; padding:6px 8px; font:inherit; background:white; color:#0f172a; }
  #node-classify-dialog label { display:flex; align-items:center; gap:8px; margin:8px 0; flex-wrap:wrap; }
  #node-classify-dialog .nf-node-list { max-height:240px; overflow:auto; margin:12px 0; border:1px solid #e2e8f0; padding:8px; }
  #node-classify-dialog fieldset { border:1px solid #e2e8f0; padding:12px; }
  #node-classify-dialog .nf-actions { display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
  #node-classify-dialog button { cursor:pointer; }
  #node-classify-dialog button[type=submit] { background:#2563eb; color:white; }
  #node-classify-dialog input:disabled, #node-classify-dialog select:disabled { opacity:.5; }
  #node-classify-dialog [data-error=true] { color:#b91c1c; }
  @media (max-width: 640px) { #node-filters label { flex: 1 1 130px; } #node-filters input[type=search] { width: 100%; } }
</style>
<template id="node-filters-template">
  <form id="node-filters" aria-label="节点高级筛选">
    <div class="nf-heading"><strong>节点多维管理</strong><label>分组浏览<select data-dimension><option value="protocol">按协议</option><option value="region">按地区</option><option value="management_group">按管理分组</option><option value="provider">按运营商 / 机场</option><option value="subscription">按订阅来源</option><option value="transport">按传输方式</option><option value="tag">按标签</option></select></label><span data-total></span></div>
    <div class="nf-cards" data-cards aria-label="节点分组"></div>
    <div class="nf-fields">
      <label>支持的协议（可多选）<select name="protocols" multiple size="4"></select></label>
      <label>地区<select name="region"><option value="">全部地区</option></select></label>
      <label>管理分组<select name="management_group"><option value="">全部分组</option></select></label>
      <label>运营商 / 机场<select name="provider"><option value="">全部运营商 / 机场</option></select></label>
      <label>订阅来源<select name="subscription"><option value="">全部来源</option></select></label>
      <label>传输方式<select name="transport"><option value="">全部方式</option></select></label>
      <label>精确标签<select name="tag_exact"><option value="">全部标签</option></select></label>
    </div>
    <details><summary>更多筛选条件</summary>
    <div class="nf-fields">
      <label>名称 / 地址 / 节点 ID<input type="search" name="keyword" maxlength="200" placeholder="例如：新加坡、aws、46"></label>
      <label>显隐<select name="show"><option value="">全部</option><option value="1">显示</option><option value="0">隐藏</option></select></label>
      <label>节点来源<select name="source"><option value="">全部</option><option value="external">订阅导入</option><option value="local">自建节点</option></select></label>
      <label>心跳状态<select name="status"><option value="">全部</option><option value="2">在线且有推送</option><option value="1">在线未推送</option><option value="0">离线</option><option value="external">外部订阅（无心跳数据）</option></select></label>
      <label>部署方式<select name="deployment"><option value="">全部</option><option value="standalone">独立部署</option><option value="managed">机器托管 · 已启用</option><option value="disabled">机器托管 · 已停用</option></select></label>
      <label>标签包含<input type="search" name="tag" maxlength="100" placeholder="例如：专线"></label>
      <label>连接端口<input type="number" name="port" min="1" max="65535" placeholder="如 443"></label>
      <label>最低倍率<input type="number" name="rate_min" min="0" step="any" placeholder="不限"></label>
      <label>最高倍率<input type="number" name="rate_max" min="0" step="any" placeholder="不限"></label>
      <label>最少在线人数<input type="number" name="online_min" min="0" step="1" placeholder="不限"></label>
      <label>最多在线人数<input type="number" name="online_max" min="0" step="1" placeholder="不限"></label>
    </div>
    </details>
    <div class="nf-actions"><button type="submit">应用筛选</button><button type="button" data-reset>清空高级筛选</button><button type="button" data-probe disabled title="按高级筛选结果测试，最多 200 个节点">测试连通性</button><button type="button" data-classify disabled>批量分类高级筛选结果</button><span role="status" aria-live="polite"></span></div>
    <div class="nf-fields"><label>常用视图<select data-views><option value="">选择已保存的视图</option></select></label><label>视图名称<input data-view-name maxlength="40" placeholder="例如：日本低倍率 VLESS"></label><button type="button" data-save-view>保存当前条件</button><button type="button" data-delete-view>删除所选视图</button></div>
    <p class="nf-hint">应用后刷新列表，条件在当前标签页保留。可继续使用上方类型、服务器、权限组缩小范围。地区从名称/国旗推测，可批量手动修正；管理分组不影响权限组。外部订阅无心跳数据。批量分类以本面板高级条件为范围，请在弹窗勾选确认。常用视图保存在当前浏览器。</p>
  </form>
</template>

<dialog id="node-classify-dialog" aria-labelledby="node-classify-title">
  <form data-classify-form>
    <h2 id="node-classify-title">批量分类节点</h2>
    <p>范围按本面板已应用的高级筛选条件计算，不读取原表格勾选或上方原有筛选。只修改下方勾选节点的分类字段。</p>
    <div class="nf-actions"><button type="button" data-select-all>全选筛选结果</button><button type="button" data-select-none>取消全选</button><span data-selection-count></span></div>
    <div class="nf-node-list" data-node-list></div>
    <fieldset><legend>需要修改的字段</legend>
      <label class="nf-edit"><input type="checkbox" name="set_region">设置地区<select name="region" disabled><option value="">自动识别（清除人工覆盖）</option><option value="ZZ">未识别地区</option></select></label>
      <label class="nf-edit"><input type="checkbox" name="set_management_group">设置管理分组<input name="management_group" maxlength="64" disabled placeholder="如：亚洲专线；留空清除" list="nf-management-groups"></label>
      <label class="nf-edit"><input type="checkbox" name="set_provider">设置运营商 / 机场<input name="provider" maxlength="64" disabled placeholder="如：AWS、机场名称；留空清除" list="nf-providers"></label>
      <label>追加标签<input name="add_tags" placeholder="逗号分隔，如：专线,流媒体"></label>
      <label>移除标签<input name="remove_tags" placeholder="逗号分隔；保留其他标签"></label>
    </fieldset>
    <p data-classify-status role="status" aria-live="polite"></p>
    <div class="nf-actions"><button type="button" data-classify-close>取消</button><button type="submit">应用到已选节点</button></div>
  </form>
  <datalist id="nf-management-groups"></datalist><datalist id="nf-providers"></datalist>
</dialog>
