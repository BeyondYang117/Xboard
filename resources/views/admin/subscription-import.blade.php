<style>
  #subscription-import-open { position: fixed; right: 24px; bottom: 24px; z-index: 40; border: 0; border-radius: 9px; padding: 12px 18px; background: #2563eb; color: white; font: 600 14px system-ui; cursor: pointer; box-shadow: 0 4px 16px #0002; }
  #subscription-import-open[hidden] { display: none; }
  #subscription-import-dialog { box-sizing: border-box; width: min(880px, calc(100% - 24px)); max-height: 90vh; padding: 24px; border: 1px solid #cbd5e1; border-radius: 12px; background: #fff; color: #0f172a; font: 14px/1.6 system-ui; }
  #subscription-import-dialog::backdrop { background: #0f172a88; }
  #subscription-import-dialog h2 { font-size: 21px; margin: 0 0 12px; }
  #subscription-import-dialog p { margin: 8px 0; }
  #subscription-import-dialog label { display: block; margin-top: 12px; }
  #subscription-import-dialog input:not([type=checkbox]), #subscription-import-dialog select { box-sizing: border-box; width: 100%; padding: 9px; border: 1px solid #94a3b8; border-radius: 6px; background: white; color: #0f172a; font: inherit; }
  #subscription-import-dialog select[multiple] { min-height: 96px; }
  #subscription-import-dialog .si-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 0 16px; }
  #subscription-import-dialog [hidden] { display: none !important; }
  #subscription-import-dialog input[type=checkbox] { margin-right: 6px; }
  #subscription-import-dialog button { padding: 8px 16px; border: 1px solid #94a3b8; border-radius: 6px; background: white; color: #0f172a; cursor: pointer; font: inherit; }
  #subscription-import-dialog button:disabled { opacity: .5; cursor: wait; }
  #subscription-import-dialog button.si-primary { background: #2563eb; color: white; border-color: #2563eb; }
  #subscription-import-dialog .si-actions { display: flex; gap: 10px; justify-content: flex-end; margin-top: 18px; flex-wrap: wrap; }
  #subscription-import-dialog .si-note { background: #eff6ff; color: #1e40af; padding: 12px; border-radius: 6px; }
  #subscription-import-dialog .si-table { max-height: 260px; overflow: auto; border: 1px solid #e2e8f0; margin-top: 12px; }
  #subscription-import-dialog table { width: 100%; border-collapse: collapse; text-align: left; font-size: 13px; }
  #subscription-import-dialog th, #subscription-import-dialog td { padding: 8px; border-bottom: 1px solid #e2e8f0; overflow-wrap: anywhere; }
  #subscription-import-status { white-space: pre-wrap; overflow-wrap: anywhere; }
  #subscription-import-status[data-error=true] { color: #b91c1c; }
</style>
<button id="subscription-import-open" type="button" hidden>机场分组 / 订阅导入</button>
<dialog id="subscription-import-dialog" aria-labelledby="subscription-import-title">
  <h2 id="subscription-import-title">机场分组与订阅导入</h2>
  <p class="si-note">中转计费模式通过平台统一 TLS 入口连接机场。只需部署一次中转服务，之后导入的机场自动接入，各机场分别计费、统计在线和执行限额。直连模式无法计费或限额，历史直连凭据需要在机场端更换才能失效。</p>
  <p id="subscription-import-gateway-status" class="si-note"></p>
  <details open><summary>已导入的机场分组</summary>
    <div class="si-table"><table><thead><tr><th>机场</th><th>模式 / 节点</th><th>在线 用户 / IP / 连接</th><th>实际用量</th><th>操作</th></tr></thead><tbody id="subscription-import-sources"></tbody></table></div>
    <button id="subscription-import-refresh" type="button">刷新统计</button>
  </details>
  <form id="subscription-import-form">
    <label for="subscription-import-url">机场订阅链接</label>
    <input id="subscription-import-url" name="subscription_url" type="url" required maxlength="4096" autocomplete="off" spellcheck="false" placeholder="https://example.com/subscribe?token=…">
    <p>支持 Base64／明文节点链接和 Clash YAML；支持 SS、VMess、VLESS、Trojan、Hysteria2、AnyTLS。暂不支持的参数会在预览中列出。</p>
    <div class="si-actions"><button id="subscription-import-preview" type="submit" class="si-primary">获取并预览</button></div>
  </form>
  <section id="subscription-import-selection" hidden>
    <p id="subscription-import-summary"></p>
    <label><input id="subscription-import-all" type="checkbox" checked>全选节点</label>
    <div class="si-table"><table>
      <thead><tr><th scope="col">选择</th><th scope="col">名称</th><th scope="col">协议</th><th scope="col">地址</th></tr></thead>
      <tbody id="subscription-import-nodes"></tbody>
    </table></div>
    <details id="subscription-import-skipped" hidden><summary>查看跳过原因</summary><ul></ul></details>
    <label><input id="subscription-import-show" type="checkbox" checked>导入后向所选权限组显示</label>
    <p>预览有效期为 10 分钟。同一链接再次导入更新选中的节点，保留未选节点及历史统计。</p>
  </section>
  <section id="subscription-import-settings" hidden>
    <h3 id="subscription-import-settings-title">机场设置</h3>
    <div class="si-grid">
      <label>机场名称<input id="subscription-import-name" maxlength="100" placeholder="例如：香港专线机场"></label>
      <label>分发模式<select id="subscription-import-mode"><option value="relay">中转计费（推荐）</option><option value="direct">原始凭据直连（无法计费和限额）</option></select></label>
      <label>流量倍率<input id="subscription-import-rate" type="number" min="0.01" max="999999.99" step="0.01" value="1"></label>
      <label>最大在线数（0 不限）<input id="subscription-import-limit" type="number" min="0" max="1000000" step="1" value="0"></label>
      <label>机场共享限制口径<select id="subscription-import-limit-mode"><option value="ip">来源 IP 去重（所有节点共享）</option><option value="user">在线用户去重</option><option value="connection">同时 TCP 连接数</option></select></label>
      <label>中转域名 / IP<input id="subscription-import-gateway" maxlength="253" placeholder="relay.example.com"></label>
      <label>中转 TLS 端口<input id="subscription-import-port" type="number" min="1" max="65535" step="1" value="2443"></label>
      <label>机场周期总流量 GB（0 不限）<input id="subscription-import-quota" type="number" min="0" max="8388607" step="0.01" value="0"></label>
      <label>机场到期时间（可留空）<input id="subscription-import-expiry" type="datetime-local"></label>
    </div>
    <label for="subscription-import-groups">分配权限组（可多选）</label>
    <select id="subscription-import-groups" multiple aria-describedby="subscription-import-group-help"></select>
    <p id="subscription-import-group-help">按住 Ctrl／Command 可选择多个权限组。用户套餐所属的权限组决定其可见节点。</p>
    <p>中转离线时不分发节点。保存权限组和倍率会批量更新此机场的节点；单节点可再调整倍率。IP 数无法区分同一 NAT 后的设备。中转目前支持 TCP，不支持 UDP / QUIC。</p>
  </section>
  <p id="subscription-import-status" role="status" aria-live="polite"></p>
  <div class="si-actions">
    <button id="subscription-import-close" type="button">关闭</button>
    <button id="subscription-import-cancel-edit" type="button" hidden>取消编辑</button>
    <button id="subscription-import-save" type="button" class="si-primary" hidden>保存机场设置</button>
    <button id="subscription-import-confirm" type="button" class="si-primary" hidden>导入所选节点</button>
  </div>
</dialog>
