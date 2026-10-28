<style>
  #subscription-import-open { position: fixed; right: 24px; bottom: 24px; z-index: 40; border: 0; border-radius: 9px; padding: 12px 18px; background: #2563eb; color: white; font: 600 14px system-ui; cursor: pointer; box-shadow: 0 4px 16px #0002; }
  #subscription-import-open[hidden] { display: none; }
  #subscription-import-dialog { box-sizing: border-box; width: min(880px, calc(100% - 24px)); max-height: 90vh; padding: 24px; border: 1px solid #cbd5e1; border-radius: 12px; background: #fff; color: #0f172a; font: 14px/1.6 system-ui; }
  #subscription-import-dialog::backdrop { background: #0f172a88; }
  #subscription-import-dialog h2 { font-size: 21px; margin: 0 0 12px; }
  #subscription-import-dialog p { margin: 8px 0; }
  #subscription-import-dialog label { display: block; margin-top: 12px; }
  #subscription-import-dialog input[type=url], #subscription-import-dialog select { box-sizing: border-box; width: 100%; padding: 9px; border: 1px solid #94a3b8; border-radius: 6px; background: white; color: #0f172a; font: inherit; }
  #subscription-import-dialog select { min-height: 96px; }
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
<button id="subscription-import-open" type="button" hidden>导入机场订阅</button>
<dialog id="subscription-import-dialog" aria-labelledby="subscription-import-title">
  <h2 id="subscription-import-title">导入机场订阅</h2>
  <p class="si-note">保留机场原有认证信息，所有使用者共享机场账号额度。平台无法按用户统计外部节点流量，也无法撤销客户端已保存的连接信息。导入后可在节点列表管理。</p>
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
    <label for="subscription-import-groups">分配权限组（可多选）</label>
    <select id="subscription-import-groups" multiple aria-describedby="subscription-import-group-help"></select>
    <p id="subscription-import-group-help">按住 Ctrl／Command 可选择多个权限组。用户套餐所属的权限组决定其可见节点。</p>
    <label><input id="subscription-import-show" type="checkbox" checked>导入后向所选权限组显示</label>
    <p>预览有效期为 10 分钟。同一链接再次导入会更新同名、同协议、同地址和端口的节点及其权限组与显示设置；不会删除旧节点。当前为手动导入。</p>
  </section>
  <p id="subscription-import-status" role="status" aria-live="polite"></p>
  <div class="si-actions">
    <button id="subscription-import-close" type="button">关闭</button>
    <button id="subscription-import-confirm" type="button" class="si-primary" hidden>导入所选节点</button>
  </div>
</dialog>
