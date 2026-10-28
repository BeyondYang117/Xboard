import { clients, type Device } from "../data";
import type { SiteConfig } from "./types";

const settingPrefixes = {
    Windows: "windows",
    macOS: "macos",
    Android: "android",
} as const;

function downloadUrl(value: string): string | null {
    try {
        const url = new URL(value);
        return ["http:", "https:"].includes(url.protocol) ? url.href : null;
    } catch {
        return null;
    }
}

export function resolveClient(
    device: Device,
    site: SiteConfig | null,
    overrides?: Partial<Record<Device, string>>,
) {
    const prefix = device === "iOS" ? null : settingPrefixes[device];
    const label = prefix ? site?.[`${prefix}_version`]?.trim() || "" : "";
    const address = prefix
        ? site?.[`${prefix}_download_url`]?.trim() || ""
        : "";
    // Backend settings are authoritative. A partially configured client must not
    // silently inherit a different application's download link or import scheme.
    if (label || address) {
        return {
            name: label || `${device} 客户端`,
            url: downloadUrl(address),
            note: `下载适用于 ${device} 的客户端安装包，请选择与你的系统架构匹配的版本。`,
        };
    }
    const override = overrides?.[device]?.trim();
    if (override) {
        return {
            name: `${device} 客户端`,
            url: downloadUrl(override),
            note: `下载适用于 ${device} 的客户端，安装后可复制订阅地址进行配置。`,
        };
    }
    return { ...clients[device], url: downloadUrl(clients[device].url) };
}

export function clientImportUrl(
    device: Device,
    name: string,
    subscription: string,
    title: string,
): string | null {
    const url = downloadUrl(subscription);
    if (!url) return null;
    const suffix = `?url=${encodeURIComponent(url)}&name=${encodeURIComponent(title)}`;
    if (
        ["Windows", "macOS"].includes(device) &&
        /^clash\s*verge(?:\s*rev)?(?:\s+v?\d[\w.-]*)?$/i.test(name)
    )
        return `clash-verge://install-config${suffix}`;
    if (device === "Android" && /^flclash(?:\s+v?\d[\w.-]*)?$/i.test(name))
        return `flclash://install-config${suffix}`;
    if (device === "iOS" && /^shadowrocket(?:\s+v?\d[\w.-]*)?$/i.test(name))
        return `shadowrocket://add/${url}`;
    return null;
}
