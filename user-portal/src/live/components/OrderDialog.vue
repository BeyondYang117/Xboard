<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from "vue";
import {
    ArrowRight,
    CheckCheck,
    CreditCard,
    ExternalLink,
    RefreshCw,
} from "@lucide/vue";
import QRCode from "qrcode";
import { api } from "../api";
import { currencySymbol, portal } from "../store";
import { formatDate, httpUrl, money, periods, priceFor } from "../format";
import type {
    Coupon as CouponData,
    Order,
    PaymentMethod,
    Period,
    Plan,
} from "../types";
import PortalDialog from "./PortalDialog.vue";
import ResourceState from "./ResourceState.vue";
const props = defineProps<{ plan?: Plan; period?: Period; tradeNo?: string }>();
const emit = defineEmits<{ close: []; changed: [] }>();
const order = ref<Order | null>(null),
    trade = ref(props.tradeNo || ""),
    coupon = ref(""),
    verifiedCoupon = ref<CouponData | null>(null),
    couponError = ref(""),
    error = ref(""),
    paymentError = ref("");
const loading = ref(Boolean(props.tradeNo)),
    busy = ref(false),
    checking = ref(false),
    checkingCoupon = ref(false),
    confirmCancel = ref(false);
const methods = ref<PaymentMethod[]>([]),
    method = ref<number>(),
    qr = ref(""),
    paymentLink = ref("");
const paid = computed(() => order.value?.status === 3);
const couponDiscount = computed(() => {
    if (!verifiedCoupon.value || !props.plan || !props.period) return 0;
    const price = priceFor(props.plan, props.period) ?? 0;
    const coupon = verifiedCoupon.value;
    const discount =
        coupon.type === 1
            ? Number(coupon.value)
            : coupon.type === 2
              ? Math.round((price * Number(coupon.value)) / 100)
              : 0;
    return Math.min(price, Math.max(0, discount));
});
const couponPrice = computed(() =>
    Math.max(
        0,
        (priceFor(props.plan!, props.period!) ?? 0) - couponDiscount.value,
    ),
);
const methodInfo = computed(() =>
    methods.value.find((item) => item.id === method.value),
);
const fee = computed(() =>
    Math.round(
        (Number(order.value?.total_amount || 0) *
            Number(methodInfo.value?.handling_fee_percent || 0)) /
            100 +
            Number(methodInfo.value?.handling_fee_fixed || 0),
    ),
);
const total = computed(
    () => Number(order.value?.total_amount || 0) + fee.value,
);
let pollTimer: ReturnType<typeof setTimeout> | undefined,
    disposed = false,
    polls = 0,
    previousStatus: number | undefined;
const labels: Record<number, string> = {
    0: "待支付",
    1: "正在开通",
    2: "已取消",
    3: "已完成",
    4: "已折抵",
};
function schedulePoll() {
    clearTimeout(pollTimer);
    if (
        !disposed &&
        order.value &&
        [0, 1].includes(order.value.status) &&
        polls < 60
    )
        pollTimer = setTimeout(() => void checkStatus(false), 5000);
}
async function loadOrder() {
    loading.value = true;
    error.value = "";
    try {
        const result = await api.order(trade.value);
        if (disposed) return;
        order.value = result;
        previousStatus = result.status;
        if (result.status === 0 && result.total_amount > 0) {
            try {
                methods.value = await api.paymentMethods();
                if (!method.value)
                    method.value = methods.value.find(
                        (item) => item.payment !== "StripeCredit",
                    )?.id;
            } catch (cause) {
                paymentError.value = (cause as Error).message;
            }
        }
        schedulePoll();
    } catch (cause) {
        if (!disposed) error.value = (cause as Error).message;
    } finally {
        if (!disposed) loading.value = false;
    }
}
async function createOrder() {
    if (busy.value || !props.plan || !props.period) return;
    if (
        coupon.value.trim() &&
        verifiedCoupon.value?.code !== coupon.value.trim()
    ) {
        couponError.value = "请先核验优惠码。";
        return;
    }
    busy.value = true;
    error.value = "";
    try {
        trade.value = await api.createOrder(
            props.plan.id,
            props.period,
            coupon.value.trim(),
        );
        emit("changed");
        await loadOrder();
    } catch (cause) {
        error.value = `${(cause as Error).message} 如订单已生成，可在账单中继续支付。`;
    } finally {
        busy.value = false;
    }
}
async function verifyCoupon() {
    if (checkingCoupon.value || busy.value || !props.plan || !props.period)
        return;
    const code = coupon.value.trim();
    if (!code) {
        couponError.value = "请输入优惠码。";
        return;
    }
    checkingCoupon.value = true;
    couponError.value = "";
    verifiedCoupon.value = null;
    try {
        const result = await api.checkCoupon(code, props.plan.id, props.period);
        if (coupon.value.trim() === code) verifiedCoupon.value = result;
    } catch (cause) {
        if (coupon.value.trim() === code)
            couponError.value = (cause as Error).message;
    } finally {
        checkingCoupon.value = false;
    }
}
watch(coupon, () => {
    verifiedCoupon.value = null;
    couponError.value = "";
});
async function checkStatus(manual = true) {
    if (checking.value || !order.value || disposed) return;
    checking.value = true;
    polls++;
    try {
        const status = await api.orderStatus(order.value.trade_no);
        if (disposed) return;
        order.value.status = status;
        if (status !== previousStatus) {
            previousStatus = status;
            emit("changed");
            if (status === 3) {
                await Promise.all([
                    portal.subscription.load(),
                    portal.user.load(),
                ]);
            }
        }
        paymentError.value =
            manual && status === 0 ? "暂未确认付款，请稍后再检查。" : "";
    } catch (cause) {
        if (!disposed) paymentError.value = (cause as Error).message;
    } finally {
        checking.value = false;
        schedulePoll();
    }
}
async function pay() {
    if (busy.value || !order.value) return;
    busy.value = true;
    paymentError.value = "";
    qr.value = "";
    paymentLink.value = "";
    try {
        if (order.value.total_amount > 0 && !method.value)
            throw new Error("请选择可用的支付方式。");
        const result = await api.checkout(order.value.trade_no, method.value);
        if (disposed) return;
        if (result.type === -1) {
            await checkStatus();
            return;
        }
        if (typeof result.data !== "string" || !result.data)
            throw new Error("支付响应缺少有效信息，请重试或更换支付方式。");
        if (result.type === 0)
            qr.value = await QRCode.toDataURL(result.data, {
                width: 240,
                margin: 2,
                errorCorrectionLevel: "M",
            });
        else if (result.type === 1) {
            const link = httpUrl(result.data);
            if (!link) throw new Error("支付地址无效，请联系支持。");
            paymentLink.value = link;
        } else throw new Error("此支付方式暂不受支持，请选择其他方式。");
        schedulePoll();
    } catch (cause) {
        if (!disposed) paymentError.value = (cause as Error).message;
    } finally {
        busy.value = false;
    }
}
async function cancelOrder() {
    if (busy.value || !order.value) return;
    busy.value = true;
    paymentError.value = "";
    try {
        await api.cancelOrder(order.value.trade_no);
        order.value.status = 2;
        clearTimeout(pollTimer);
        confirmCancel.value = false;
        emit("changed");
        await portal.user.load();
    } catch (cause) {
        paymentError.value = (cause as Error).message;
    } finally {
        busy.value = false;
    }
}
onMounted(() => {
    if (trade.value) void loadOrder();
});
onBeforeUnmount(() => {
    disposed = true;
    clearTimeout(pollTimer);
});
</script>
<template>
    <PortalDialog
        :title="paid ? '订阅已就绪' : order ? '订单详情' : '确认套餐'"
        @close="emit('close')"
        ><ResourceState
            :loading="loading"
            :error="error && trade ? error : undefined"
            @retry="loadOrder"
        /><template v-if="!loading && !order && !trade && plan && period"
            ><span class="pill">确认套餐与计费周期</span>
            <h3 class="checkout-name">{{ plan.name }}</h3>
            <div class="checkout-summary">
                <div>
                    <span>计费周期</span
                    ><strong>{{
                        periods.find((item) => item.key === period)?.label
                    }}</strong>
                </div>
                <div>
                    <span>套餐流量额度</span
                    ><strong>{{ plan.transfer_enable }} GB</strong>
                </div>
                <div>
                    <span>同时使用设备</span
                    ><strong>{{ plan.device_limit ?? "不限" }}</strong>
                </div>
                <div class="checkout-total">
                    <span>套餐标价</span
                    ><strong v-if="verifiedCoupon">{{
                        money(couponPrice, currencySymbol)
                    }}</strong>
                    <strong v-else>{{
                        money(priceFor(plan, period), currencySymbol)
                    }}</strong>
                    <s v-if="verifiedCoupon" class="checkout-original-price">{{
                        money(priceFor(plan, period), currencySymbol)
                    }}</s>
                </div>
            </div>
            <label for="coupon-code" class="form-label">优惠码（选填）</label>
            <div class="coupon-entry">
                <input
                    id="coupon-code"
                    v-model="coupon"
                    class="form-input"
                    maxlength="100"
                    placeholder="输入优惠码"
                    :disabled="busy"
                />
                <button
                    class="button button-secondary"
                    type="button"
                    :disabled="busy || checkingCoupon || !coupon.trim()"
                    @click="verifyCoupon"
                >
                    {{ checkingCoupon ? "正在核验…" : "核验优惠码" }}
                </button>
            </div>
            <p v-if="couponError" class="field-error" role="alert">
                {{ couponError }}
            </p>
            <div
                v-if="verifiedCoupon"
                class="coupon-preview"
                aria-live="polite"
            >
                <div class="coupon-preview-status">
                    <CheckCheck :size="16" /> 优惠码核验成功
                </div>
                <div class="coupon-preview-row">
                    <span>本次优惠</span>
                    <strong
                        >-{{ money(couponDiscount, currencySymbol) }}</strong
                    >
                </div>
            </div>
            <p class="dialog-note order-note">
                优惠预览按套餐标价计算。创建订单时还会核算会员折扣、套餐折抵和余额；最终应付金额以订单结算页为准。
            </p>
            <p v-if="error" class="field-error" role="alert">{{ error }}</p>
            <button
                class="button button-brand full-width"
                :disabled="
                    busy ||
                    checkingCoupon ||
                    (Boolean(coupon.trim()) &&
                        verifiedCoupon?.code !== coupon.trim())
                "
                @click="createOrder"
            >
                {{
                    busy
                        ? "正在创建订单…"
                        : verifiedCoupon?.code === coupon.trim()
                          ? `确认并创建订单 · ${money(couponPrice, currencySymbol)}`
                          : "确认价格并创建订单"
                }}<ArrowRight :size="16" /></button></template
        ><template v-else-if="order && !loading"
            ><template v-if="paid"
                ><div class="purchase-complete">
                    <span class="success-icon"><CheckCheck :size="30" /></span
                    ><span class="pill">服务已开通</span>
                    <h3>订阅已就绪，开始连接吧。</h3>
                    <p>选择设备，安装客户端，再导入你的订阅。</p>
                </div>
                <a
                    href="#guide"
                    class="button button-brand full-width"
                    @click="emit('close')"
                    >配置我的设备 <ArrowRight :size="16" /></a></template
            ><template v-else
                ><span class="pill">{{
                    labels[order.status] || "状态更新中"
                }}</span>
                <h3 class="checkout-name">
                    {{ order.plan?.name || "订阅套餐" }}
                </h3></template
            >
            <div class="checkout-summary">
                <div>
                    <span>订单编号</span
                    ><strong class="trade-number">{{ order.trade_no }}</strong>
                </div>
                <div>
                    <span>创建日期</span
                    ><strong>{{ formatDate(order.created_at, true) }}</strong>
                </div>
                <div>
                    <span>计费周期</span
                    ><strong>{{
                        periods.find((item) => item.key === order!.period)
                            ?.label || order.period
                    }}</strong>
                </div>
                <div v-if="order.discount_amount">
                    <span>优惠折扣</span
                    ><strong
                        >−{{
                            money(order.discount_amount, currencySymbol)
                        }}</strong
                    >
                </div>
                <div v-if="order.surplus_amount">
                    <span>套餐折抵</span
                    ><strong
                        >−{{
                            money(order.surplus_amount, currencySymbol)
                        }}</strong
                    >
                </div>
                <div v-if="order.balance_amount">
                    <span>余额抵扣</span
                    ><strong
                        >−{{
                            money(order.balance_amount, currencySymbol)
                        }}</strong
                    >
                </div>
                <div v-if="order.surplus_credit">
                    <span>退回余额</span
                    ><strong>{{
                        money(order.surplus_credit, currencySymbol)
                    }}</strong>
                </div>
                <div>
                    <span>订单应付</span
                    ><strong>{{
                        money(order.total_amount, currencySymbol)
                    }}</strong>
                </div>
            </div>
            <template v-if="order.status === 0"
                ><label
                    v-if="order.total_amount > 0"
                    for="payment-method"
                    class="form-label"
                    >支付方式</label
                ><select
                    v-if="order.total_amount > 0"
                    id="payment-method"
                    v-model="method"
                    class="form-input"
                    :disabled="busy"
                    @change="
                        qr = '';
                        paymentLink = '';
                    "
                >
                    <option :value="undefined" disabled>请选择支付方式</option>
                    <option
                        v-for="item in methods"
                        :key="item.id"
                        :value="item.id"
                        :disabled="item.payment === 'StripeCredit'"
                    >
                        {{ item.name
                        }}{{
                            item.payment === "StripeCredit"
                                ? "（暂不支持）"
                                : ""
                        }}
                    </option>
                </select>
                <p
                    v-if="order.total_amount > 0 && !methods.length"
                    class="dialog-note"
                >
                    暂无可用支付方式，请稍后重试或联系支持。
                </p>
                <div class="checkout-summary">
                    <div v-if="fee">
                        <span>支付手续费</span
                        ><strong>{{ money(fee, currencySymbol) }}</strong>
                    </div>
                    <div class="checkout-total">
                        <span>合计支付</span
                        ><strong>{{ money(total, currencySymbol) }}</strong>
                    </div>
                </div>
                <div v-if="qr" class="payment-qr">
                    <img
                        :src="qr"
                        width="240"
                        height="240"
                        alt="当前订单的支付二维码"
                    />
                    <p>请使用所选支付方式扫码，金额以支付页面为准。</p>
                </div>
                <a
                    v-if="paymentLink"
                    :href="paymentLink"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="button button-brand full-width"
                    >打开支付页面 <ExternalLink :size="16" /></a
                ><button
                    v-else-if="!qr"
                    class="button button-brand full-width"
                    :disabled="busy || (order.total_amount > 0 && !method)"
                    @click="pay"
                >
                    <CreditCard :size="16" />{{
                        busy
                            ? "准备支付中…"
                            : order.total_amount === 0
                              ? "确认余额 / 零元结算"
                              : "获取支付信息"
                    }}</button
                ><button
                    v-if="qr || paymentLink"
                    class="button button-secondary full-width check-payment"
                    :disabled="checking || busy"
                    @click="checkStatus()"
                >
                    <RefreshCw :size="15" />{{
                        checking ? "正在检查…" : "我已支付，检查状态"
                    }}
                </button>
                <div class="dialog-footer">
                    <span>支付结果以服务端确认为准。</span
                    ><button
                        class="text-button"
                        :disabled="busy"
                        @click="confirmCancel = !confirmCancel"
                    >
                        取消订单
                    </button>
                </div>
                <div v-if="confirmCancel" class="inline-confirm">
                    <p>确认取消该未支付订单？已抵扣余额由系统按规则退回。</p>
                    <button
                        class="button button-secondary"
                        :disabled="busy"
                        @click="cancelOrder"
                    >
                        确认取消
                    </button>
                </div></template
            >
            <div v-else-if="order.status === 1" class="processing-order">
                <RefreshCw :size="24" class="spin" />
                <h3>付款已确认，正在开通。</h3>
                <p>请稍候。页面将自动检查服务状态。</p>
                <button
                    class="button button-secondary"
                    :disabled="checking"
                    @click="checkStatus()"
                >
                    检查开通状态
                </button>
            </div>
            <p v-if="paymentError" class="field-error" role="alert">
                {{ paymentError }}
            </p>
            <button
                v-if="paymentError && order.status === 0 && !methods.length"
                class="text-button"
                @click="loadOrder"
            >
                重新加载支付方式
            </button>
            <p
                v-if="polls >= 60 && [0, 1].includes(order.status)"
                class="dialog-note"
            >
                自动检查已暂停，可手动检查状态或稍后从账单继续。
            </p></template
        ></PortalDialog
    >
</template>
