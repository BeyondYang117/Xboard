export type Page =
    "overview" | "subscription" | "plans" | "guide" | "help" | "account";
export type Device = "Windows" | "macOS" | "iOS" | "Android";
export type Scenario = "active" | "new" | "expiring" | "empty";

// Deliberately isolated fixtures. No credentials, subscription URLs or payment APIs.
export const subscription = {
    name: "Explorer 探索者",
    total: 200,
    used: 64.8,
    expiresAt: "2026-11-28",
    resetsAt: "2026-11-01",
};

export const plans = [
    {
        id: "starter",
        name: "Starter",
        title: "轻量起步",
        description: "日常浏览，轻松连接",
        price: 15,
        traffic: 80,
        devices: 2,
        features: [
            "80 GB 月度流量",
            "2 台设备同时使用",
            "标准线路",
            "工单支持",
        ],
    },
    {
        id: "explorer",
        name: "Explorer",
        title: "自在探索",
        description: "工作与生活，恰到好处",
        price: 29,
        traffic: 200,
        devices: 5,
        features: [
            "200 GB 月度流量",
            "5 台设备同时使用",
            "全部标准与优选线路",
            "工单支持",
        ],
    },
    {
        id: "voyager",
        name: "Voyager",
        title: "尽兴远行",
        description: "为更高频的连接而准备",
        price: 59,
        traffic: 500,
        devices: 8,
        features: [
            "500 GB 月度流量",
            "8 台设备同时使用",
            "全部标准与优选线路",
            "优先工单支持",
        ],
    },
];

export const usage = [
    18, 28, 23, 45, 37, 26, 54, 32, 22, 40, 65, 38, 49, 72, 45, 28, 52, 37, 24,
    47, 33, 60, 42, 30, 45, 69, 38, 50,
];

export const articles = [
    {
        id: 1,
        category: "开始使用",
        title: "第一次使用，如何导入订阅？",
        description: "从选择客户端到完成配置，只需几个步骤。",
        body: "在「开始使用」中选择你的设备，下载兼容客户端。安装后回到本页，使用一键导入或复制订阅地址，在客户端粘贴导入。随后在客户端选择可用线路并启用连接。导入完成不代表已经成功连接，请在客户端检查连接结果。此原型不提供真实订阅地址。",
    },
    {
        id: 2,
        category: "订阅与流量",
        title: "流量什么时候重置？和到期有什么区别？",
        description: "了解流量周期、订阅有效期和用量统计。",
        body: "流量重置日表示当前周期的已用流量归零，到期日表示订阅服务的有效期结束。两者可能不同。请以「我的订阅」显示的日期和实际套餐规则为准；充值余额不等于续费订阅。",
    },
    {
        id: 3,
        category: "连接排查",
        title: "导入后无法连接，应该怎么排查？",
        description: "按顺序检查配置、订阅状态与网络环境。",
        body: "先检查订阅是否有效、流量是否充足，再在客户端更新订阅并确认系统时间正确。尝试切换可用线路，检查是否有其他网络工具冲突。仍无法使用时，请提交设备系统、客户端版本、发生时间及脱敏后的错误信息。不要提交完整订阅链接或账号密码。",
    },
    {
        id: 4,
        category: "订阅与流量",
        title: "如何选择适合自己的套餐？",
        description: "根据流量和同时使用设备数量做选择。",
        body: "可以参考最近一个周期的实际流量和同时使用设备数。高分辨率视频、文件下载等场景通常消耗更多流量。套餐页可以切换月付和年付比较总价；购买前请确认计费周期和套餐限制。",
    },
    {
        id: 5,
        category: "开始使用",
        title: "可以在多台设备上使用吗？",
        description: "查看设备限制与配置方式。",
        body: "不同套餐有不同的同时使用设备限制，请以实际套餐说明为准。在自己的各台设备上分别安装客户端并导入订阅即可。不要向他人分享订阅链接。",
    },
];

export const clients: Record<
    Device,
    { name: string; url: string; note: string }
> = {
    Windows: {
        name: "Clash Verge Rev",
        url: "https://github.com/clash-verge-rev/clash-verge-rev/releases",
        note: "下载与你的系统架构匹配的 Windows 安装包。",
    },
    macOS: {
        name: "Clash Verge Rev",
        url: "https://github.com/clash-verge-rev/clash-verge-rev/releases",
        note: "根据你的 Mac 芯片选择 Apple Silicon 或 Intel 版本。",
    },
    iOS: {
        name: "Shadowrocket",
        url: "https://apps.apple.com/app/shadowrocket/id932747118",
        note: "App Store 的地区可用性与价格以商店页面为准，客户端可能需要单独购买。",
    },
    Android: {
        name: "FlClash",
        url: "https://github.com/chen08209/FlClash/releases",
        note: "从官方发布页选择与你的设备匹配的 Android 安装包。",
    },
};
