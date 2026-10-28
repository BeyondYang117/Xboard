import { computed, ref, shallowReactive } from "vue";
import { api } from "./api";
import { subscriptionState } from "./format";

function resource<T>(fetcher: () => Promise<T>) {
    let generation = 0;
    const state = shallowReactive({
        data: null as T | null,
        loading: false,
        error: "",
        updatedAt: 0,
    });
    async function load() {
        const current = ++generation;
        state.loading = true;
        state.error = "";
        try {
            const data = await fetcher();
            if (current === generation) {
                state.data = data;
                state.updatedAt = Date.now();
            }
        } catch (error) {
            if (current === generation)
                state.error =
                    error instanceof Error
                        ? error.message
                        : "加载失败，请重试。";
        } finally {
            if (current === generation) state.loading = false;
        }
    }
    function reset() {
        generation++;
        state.data = null;
        state.error = "";
        state.loading = false;
        state.updatedAt = 0;
    }
    return Object.assign(state, { load, reset });
}
export const portal = {
    user: resource(api.user),
    subscription: resource(api.subscription),
    site: resource(api.siteConfig),
    plans: resource(api.plans),
    traffic: resource(api.traffic),
    articles: resource(api.articles),
    notices: resource(() => api.notices()),
    orders: resource(api.orders),
    tickets: resource(api.tickets),
};
export const accountState = computed(() =>
    subscriptionState(
        portal.subscription.data,
        Boolean(portal.user.data?.banned),
    ),
);
export const currencySymbol = computed(
    () => portal.site.data?.currency_symbol || "¥",
);
export const displayName = computed(
    () => portal.user.data?.email.split("@")[0] || "你好",
);
export const initials = computed(() =>
    displayName.value.slice(0, 1).toUpperCase(),
);
export async function bootstrapPortal() {
    await Promise.all([
        portal.user.load(),
        portal.subscription.load(),
        portal.site.load(),
    ]);
}
export function resetPortal() {
    Object.values(portal).forEach((resource) => resource.reset());
}
export const notification = ref("");
let notificationTimer: ReturnType<typeof setTimeout>;
export function notify(message: string) {
    clearTimeout(notificationTimer);
    notification.value = message;
    notificationTimer = setTimeout(() => {
        notification.value = "";
    }, 5000);
}
