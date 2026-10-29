export interface PortalConfig {
    title: string;
    description: string;
    logo: string;
    version: string;
    appearance: "light" | "dark" | "system";
    accent: string;
    visualStyle: "quiet" | "anime" | "gothic";
    allowRegistration: boolean;
    supportUrl: string;
    coverImage: string;
    clients?: Partial<Record<"Windows" | "macOS" | "iOS" | "Android", string>>;
}
declare global {
    interface Window {
        __XBOARD_PORTAL__?: Partial<PortalConfig>;
    }
}
export const portalBuildVersion = "0.2.8";
export const portalConfig: PortalConfig = {
    title: "Xboard",
    description: "连接更多可能",
    logo: "",
    version: portalBuildVersion,
    appearance: "light",
    accent: "blue",
    visualStyle: "anime",
    allowRegistration: true,
    supportUrl: "",
    coverImage: "",
    ...window.__XBOARD_PORTAL__,
};
export const isDemo =
    import.meta.env.DEV &&
    !window.__XBOARD_PORTAL__ &&
    new URLSearchParams(location.search).get("live") !== "1";
export function readPreference(key: string, fallback: string) {
    try {
        return localStorage.getItem(`xboard-portal-${key}`) ?? fallback;
    } catch {
        return fallback;
    }
}
export function savePreference(key: string, value: string) {
    try {
        localStorage.setItem(`xboard-portal-${key}`, value);
    } catch {
        /* Preferences remain in memory. */
    }
}
