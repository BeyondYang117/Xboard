export interface CaptchaAPI {
    render: (
        element: HTMLElement,
        options: Record<string, unknown>,
    ) => string | number;
    reset: (id?: string | number) => void;
    remove?: (id: string | number) => void;
    ready?: (callback: () => void) => void;
    execute?: (key: string, options: { action: string }) => Promise<string>;
}
export const captchaGlobals = () =>
    window as unknown as {
        grecaptcha?: CaptchaAPI;
        turnstile?: CaptchaAPI;
    };
const pending = new Map<string, Promise<CaptchaAPI>>();
let callbackId = 0;

export function loadCaptcha(type: string, key: string): Promise<CaptchaAPI> {
    const turnstile = type === "turnstile";
    const id = turnstile ? "turnstile" : `recaptcha:${type}:${key}`;
    const existing = pending.get(id);
    if (existing) return existing;
    const loading = new Promise<CaptchaAPI>((resolve, reject) => {
        const script = document.createElement("script");
        const callback = `__xboardCaptchaLoaded${++callbackId}`;
        const callbacks = window as unknown as Record<string, unknown>;
        let settled = false;
        const cleanup = () => {
            clearTimeout(timer);
            script.onerror = null;
            script.onload = null;
            // A late provider callback after a timeout must remain harmless.
            callbacks[callback] = () => {};
        };
        const fail = (message: string) => {
            if (settled) return;
            settled = true;
            cleanup();
            script.remove();
            reject(new Error(message));
        };
        const complete = () => {
            if (settled) return;
            const api = turnstile
                ? captchaGlobals().turnstile
                : captchaGlobals().grecaptcha;
            if (!api?.render) {
                fail("验证码初始化失败，请刷新重试。");
                return;
            }
            const done = () => {
                if (settled) return;
                settled = true;
                cleanup();
                resolve(api);
            };
            try {
                // Turnstile's async loader must use its onload callback.
                // ready() is only used by Google's reCAPTCHA API.
                if (!turnstile && api.ready) api.ready(done);
                else done();
            } catch {
                fail("验证码初始化失败，请刷新重试。");
            }
        };
        const timer = setTimeout(
            () => fail("验证码加载超时，请刷新后重试。"),
            15000,
        );
        const available = turnstile
            ? captchaGlobals().turnstile
            : captchaGlobals().grecaptcha;
        if (available?.render) {
            complete();
            return;
        }
        callbacks[callback] = complete;
        script.src = turnstile
            ? `https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit&onload=${callback}`
            : `https://www.google.com/recaptcha/api.js?render=${type === "recaptcha-v3" ? encodeURIComponent(key) : "explicit"}&onload=${callback}`;
        script.async = true;
        script.onerror = () => fail("验证码暂时无法加载，请检查网络后重试。");
        // reCAPTCHA also supports the load event before ready(). Turnstile
        // intentionally waits for its explicit provider callback instead.
        if (!turnstile) script.onload = complete;
        document.head.appendChild(script);
    });
    pending.set(id, loading);
    void loading.catch(() => {
        if (pending.get(id) === loading) pending.delete(id);
    });
    return loading;
}
