<script setup lang="ts">
import { computed, onMounted, ref, watch } from "vue";
import { ArrowRight, Check, CreditCard, ShieldCheck } from "@lucide/vue";
import { currencySymbol, portal } from "../store";
import { formatDate, money, periods, priceFor, safeRichText } from "../format";
import type { Period, Plan } from "../types";
import {
    mergePlans,
    planDescription,
    planPeriod,
    plansForCategory,
    planResetRule,
    purchasePeriods,
    type PlanCategory,
} from "../plans";
import OrderDialog from "./OrderDialog.vue";
import ResourceState from "./ResourceState.vue";
const props = defineProps<{ initialTrade?: string }>();
const tab = ref<"plans" | "bills">(props.initialTrade ? "bills" : "plans");
const category = ref<PlanCategory>("recurring");
const cardPeriods = ref<Record<number, Period>>({});
const selectedPlan = ref<Plan>(),
    selectedPeriod = ref<Period>(),
    tradeNo = ref<string>(),
    showOrder = ref(false);
const availablePlans = computed(() =>
    mergePlans(portal.plans.data ?? [], portal.subscription.data?.plan),
);
const categoryPlans = computed(() => ({
    recurring: plansForCategory(availablePlans.value, "recurring"),
    onetime: plansForCategory(availablePlans.value, "onetime"),
}));
const displayedPlans = computed(() => categoryPlans.value[category.value]);
const categoryTitle = computed(() =>
    category.value === "recurring" ? "订阅套餐" : "长期流量",
);
const labels: Record<number, string> = {
    0: "待支付",
    1: "正在开通",
    2: "已取消",
    3: "已完成",
    4: "已折抵",
};
watch(
    categoryPlans,
    (items) => {
        if (!items[category.value].length) {
            if (items.recurring.length) category.value = "recurring";
            else if (items.onetime.length) category.value = "onetime";
        }
    },
    { immediate: true },
);
watch(
    () => props.initialTrade,
    (value) => {
        if (value) openOrder(value);
    },
);
function selectPlan(plan: Plan) {
    selectedPlan.value = plan;
    selectedPeriod.value = periodFor(plan);
    tradeNo.value = undefined;
    showOrder.value = true;
}
function periodFor(plan: Plan) {
    return planPeriod(plan, category.value, cardPeriods.value[plan.id]);
}
function choosePeriod(plan: Plan, event: Event) {
    const value = (event.target as HTMLSelectElement).value as Period;
    if (
        purchasePeriods(plan, category.value).some((item) => item.key === value)
    )
        cardPeriods.value[plan.id] = value;
}
function openOrder(value: string) {
    selectedPlan.value = undefined;
    tradeNo.value = value;
    showOrder.value = true;
    tab.value = "bills";
}
function canBuy(plan: Plan) {
    if (portal.subscription.data?.plan_id === plan.id) return plan.renew;
    return (
        plan.sell &&
        (plan.capacity_limit == null ||
            (typeof plan.capacity_limit === "number" &&
                plan.capacity_limit >= 0))
    );
}
function changed() {
    void portal.orders.load();
}
onMounted(() => {
    if (!portal.plans.data) void portal.plans.load();
    void portal.orders.load();
    if (props.initialTrade) openOrder(props.initialTrade);
});
</script>
<template>
    <div class="page-heading">
        <div>
            <div class="eyebrow">MADE FOR YOUR EVERYDAY</div>
            <h1>刚刚好的连接，自由选择。</h1>
            <p>从轻量浏览到高频使用，找到适合你的节奏。</p>
        </div>
    </div>
    <div class="plans-toolbar">
        <div class="tabs" aria-label="套餐与账单">
            <button
                :class="{ selected: tab === 'plans' }"
                @click="tab = 'plans'"
            >
                套餐选购</button
            ><button
                :class="{ selected: tab === 'bills' }"
                @click="tab = 'bills'"
            >
                我的账单
            </button>
        </div>
        <button
            v-if="tab === 'plans'"
            class="text-button"
            :disabled="portal.plans.loading"
            @click="portal.plans.load"
        >
            刷新套餐
        </button>
        <button
            v-if="tab === 'bills'"
            class="text-button"
            @click="portal.orders.load"
        >
            刷新账单
        </button>
    </div>
    <template v-if="tab === 'plans'">
        <div class="plan-categories tabs" role="group" aria-label="套餐分类">
            <button
                :class="{ selected: category === 'recurring' }"
                :aria-pressed="category === 'recurring'"
                @click="category = 'recurring'"
            >
                订阅套餐
                <span class="plan-category-count">{{
                    categoryPlans.recurring.length
                }}</span>
            </button>
            <button
                :class="{ selected: category === 'onetime' }"
                :aria-pressed="category === 'onetime'"
                @click="category = 'onetime'"
            >
                长期流量
                <span class="plan-category-count">{{
                    categoryPlans.onetime.length
                }}</span>
            </button>
        </div>
        <div class="plan-category-intro" aria-live="polite">
            <h2>{{ categoryTitle }}</h2>
            <p>
                {{
                    category === "recurring"
                        ? "按月、季或年等周期购买，适合持续使用。"
                        : "一次性付费、长期有效，流量与重置方式以套餐实际规则为准。"
                }}
            </p>
        </div>
        <ResourceState
            :loading="portal.plans.loading"
            :error="portal.plans.error"
            @retry="portal.plans.load"
        />
        <div
            v-if="portal.plans.data && !portal.plans.error"
            class="plan-grid live-plan-grid"
        >
            <article
                v-for="plan in displayedPlans"
                :key="plan.id"
                :class="[
                    'plan-card',
                    {
                        recommended:
                            plan.id === portal.subscription.data?.plan_id,
                    },
                ]"
            >
                <div
                    v-if="plan.id === portal.subscription.data?.plan_id"
                    class="recommend-label"
                >
                    当前套餐
                </div>
                <div class="plan-name">
                    {{ plan.tags?.join(" · ") || "SUBSCRIPTION" }}
                </div>
                <h2>{{ plan.name }}</h2>
                <div class="plan-price">
                    <strong>{{
                        money(priceFor(plan, periodFor(plan)), currencySymbol)
                    }}</strong
                    ><small
                        >/
                        {{
                            periodFor(plan) === "onetime_price"
                                ? "一次性付费"
                                : periods.find(
                                      (item) => item.key === periodFor(plan),
                                  )?.label
                        }}</small
                    >
                </div>
                <select
                    v-if="purchasePeriods(plan, category).length > 1"
                    :value="periodFor(plan)"
                    class="form-select"
                    :aria-label="`${plan.name}的计费周期`"
                    @change="choosePeriod(plan, $event)"
                >
                    <option
                        v-for="item in purchasePeriods(plan, category)"
                        :key="item.key"
                        :value="item.key"
                    >
                        {{
                            item.key === "onetime_price"
                                ? "长期流量（一次性）"
                                : item.label
                        }}
                    </option>
                </select>
                <p
                    v-if="periodFor(plan) === 'onetime_price'"
                    class="price-note"
                >
                    长期有效，流量规则以套餐说明为准
                </p>
                <p class="price-note">
                    {{ planResetRule(plan, portal.site.data) }}
                </p>
                <div class="price-note">实际折扣与折抵以结算订单为准</div>
                <button
                    class="button button-brand"
                    :disabled="!canBuy(plan)"
                    @click="selectPlan(plan)"
                >
                    {{
                        !canBuy(plan)
                            ? "暂不可购买"
                            : plan.id === portal.subscription.data?.plan_id
                              ? category === "onetime"
                                  ? "再次购买"
                                  : "续费此套餐"
                              : "选择此套餐"
                    }}<ArrowRight :size="16" />
                </button>
                <div class="plan-divider"></div>
                <ul class="feature-list">
                    <li>
                        <Check :size="16" />{{ plan.transfer_enable }} GB
                        流量额度
                    </li>
                    <li>
                        <Check :size="16" />{{
                            plan.device_limit == null
                                ? "不限设备数量"
                                : `${plan.device_limit} 台设备同时使用`
                        }}
                    </li>
                    <li>
                        <Check :size="16" />{{
                            plan.speed_limit == null
                                ? "不限速"
                                : `最高 ${plan.speed_limit} Mbps`
                        }}
                    </li>
                </ul>
                <div
                    v-if="plan.content"
                    class="rich-text plan-rich-text"
                    v-html="
                        safeRichText(planDescription(plan, portal.site.data))
                    "
                ></div>
            </article>
        </div>
        <div
            v-if="
                portal.plans.data &&
                !portal.plans.error &&
                !displayedPlans.length
            "
            class="panel empty-state"
        >
            <CreditCard :size="35" />
            <h2>暂时没有{{ categoryTitle }}</h2>
            <p>可以切换另一类套餐，或联系支持了解详情。</p>
            <button
                v-if="
                    categoryPlans[
                        category === 'recurring' ? 'onetime' : 'recurring'
                    ].length
                "
                class="button button-secondary"
                @click="
                    category =
                        category === 'recurring' ? 'onetime' : 'recurring'
                "
            >
                查看{{ category === "recurring" ? "长期流量" : "订阅套餐" }}
            </button>
        </div>
        <div class="plan-disclaimer">
            <ShieldCheck :size="17" /><span
                >流量重置、套餐升级、剩余价值折抵等规则以套餐说明与系统结算结果为准。</span
            >
        </div>
        <section
            v-if="
                portal.subscription.data?.plan?.reset_price != null ||
                portal.subscription.data?.plan?.prices?.reset_traffic != null
            "
            class="panel padded"
        >
            <div class="section-heading">
                <div>
                    <h2>本期流量不够用了？</h2>
                    <p>流量重置包仅重置流量，不延长订阅有效期。</p>
                </div>
                <button
                    class="button button-secondary"
                    @click="
                        selectedPlan = portal.subscription.data!.plan;
                        selectedPeriod = 'reset_price';
                        tradeNo = undefined;
                        showOrder = true;
                    "
                >
                    查看重置费用
                </button>
            </div>
        </section></template
    >
    <section v-else class="panel bills-panel">
        <ResourceState
            :loading="portal.orders.loading"
            :error="portal.orders.error"
            @retry="portal.orders.load"
        />
        <div v-if="portal.orders.data?.length === 0" class="empty-state">
            <CreditCard :size="36" />
            <h3>还没有账单</h3>
            <p>购买订阅后，账单会显示在这里。</p>
            <button class="button button-brand" @click="tab = 'plans'">
                查看套餐
            </button>
        </div>
        <template v-if="portal.orders.data?.length"
            ><div class="bill-row bill-header">
                <span>套餐 / 订单</span><span>日期</span><span>应付金额</span
                ><span>状态</span>
            </div>
            <button
                v-for="order in portal.orders.data"
                :key="order.trade_no"
                class="bill-row live-bill-row"
                @click="openOrder(order.trade_no)"
            >
                <span
                    ><strong>{{ order.plan?.name || "订阅套餐" }}</strong
                    ><small>{{ order.trade_no }}</small></span
                ><span>{{ formatDate(order.created_at) }}</span
                ><strong>{{ money(order.total_amount, currencySymbol) }}</strong
                ><span class="pill"
                    >{{ labels[order.status] || "未知状态"
                    }}<ArrowRight :size="11"
                /></span></button
        ></template>
    </section>
    <OrderDialog
        v-if="showOrder"
        :plan="selectedPlan"
        :period="selectedPeriod"
        :trade-no="tradeNo"
        @close="showOrder = false"
        @changed="changed"
    />
</template>
