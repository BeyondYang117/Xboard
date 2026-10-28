<style>
  #node-probe-dialog { box-sizing:border-box; width:min(1000px,calc(100% - 24px)); max-height:92vh; overflow:auto; padding:24px; border:1px solid #cbd5e1; border-radius:10px; background:#fff; color:#0f172a; font:14px/1.6 system-ui; }
  #node-probe-dialog [hidden] { display:none !important; }
  #node-probe-dialog::backdrop { background:#0007; }
  #node-probe-dialog h2 { font-size:20px; font-weight:600; }
  #node-probe-dialog p { margin:8px 0; }
  #node-probe-dialog .np-controls { display:flex; align-items:end; flex-wrap:wrap; gap:12px; margin:12px 0; }
  #node-probe-dialog label { display:flex; flex-direction:column; gap:4px; }
  #node-probe-dialog input, #node-probe-dialog select, #node-probe-dialog button { border:1px solid #cbd5e1; border-radius:5px; padding:6px 8px; font:inherit; background:#fff; color:#0f172a; }
  #node-probe-dialog button { cursor:pointer; }
  #node-probe-dialog button:disabled { opacity:.5; cursor:default; }
  #node-probe-dialog [data-start] { background:#2563eb; color:white; }
  #node-probe-dialog .np-list { max-height:360px; overflow:auto; border:1px solid #e2e8f0; }
  #node-probe-dialog table { width:100%; text-align:left; border-collapse:collapse; }
  #node-probe-dialog td, #node-probe-dialog th { padding:8px; border-bottom:1px solid #e2e8f0; overflow-wrap:anywhere; }
  #node-probe-dialog [data-state=ok] { color:#15803d; }
  #node-probe-dialog [data-state=failed], #node-probe-dialog [data-state=error] { color:#b91c1c; }
  #node-probe-dialog .np-note { color:#64748b; }
  #node-probe-dialog [data-user-id] { width:170px; }
</style>
<dialog id="node-probe-dialog" aria-labelledby="node-probe-title">
  <h2 id="node-probe-title">节点连通性测试</h2>
  <p>从面板服务器发起，范围为本面板高级筛选命中的节点（最多 200 个）。勾选下方节点后开始测试。</p>
  <div class="np-controls">
    <label>测试方式<select data-mode><option value="proxy">代理实测（认证 + HTTPS 请求）</option><option value="tcp">TCP 端口连接</option></select></label>
    <label data-target-label>测试网站<select data-target></select></label>
    <label data-user-label>自建节点测试用户 ID<input data-user-id type="number" min="1" step="1" placeholder="外部订阅无需填写"></label>
  </div>
  <p class="np-note">代理实测成功需返回 HTTP 204，会消耗机场/测试账号少量流量。TCP 可连接仅说明端口开放；UDP/QUIC 节点请使用代理实测。若节点使用端口范围，测试首个端口。结果仅代表测试时面板服务器的网络情况。</p>
  <p data-environment role="status"></p>
  <div class="np-controls"><button type="button" data-all>全选</button><button type="button" data-none>取消全选</button><button type="button" data-failed>选择失败项</button><span data-selected></span></div>
  <div class="np-list"><table><thead><tr><th>选择</th><th>节点 / 协议</th><th>测试结果</th><th>耗时</th></tr></thead><tbody data-rows></tbody></table></div>
  <p data-progress role="status" aria-live="polite"></p>
  <div class="np-controls"><button type="button" data-start>测试已选节点</button><button type="button" data-stop disabled>停止后续测试</button><button type="button" data-close>关闭</button></div>
</dialog>
