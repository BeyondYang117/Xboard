# Telegram 优惠券活动

管理员可从管理后台右下角的“TG 优惠活动”进入配置页，也可访问 `/{管理员安全路径}/coupon-campaigns`。页面支持活动创建/编辑/启停、套餐和周期选择、领取记录、专属券码及核销状态查看。优惠金额以分为单位；`type=1` 表示立减，`type=2` 表示优惠比例，例如 `20` 表示优惠原价的 20%。`limit_period` 使用系统套餐周期键。

## 活动管理 API

以下路径完整形式为 `/api/v2/{管理员安全路径}/coupon-campaign/...`，并要求管理员登录：

- `GET /coupon-campaign/fetch?current=1&pageSize=10`：分页查看活动和领取数。
- `GET /coupon-campaign/options`：获取套餐和套餐周期选项。
- `POST /coupon-campaign/save`：新建或更新活动。
- `GET /coupon-campaign/claims?id={活动ID}&current=1&pageSize=20`：查看领取记录和对应专属券码。
- `POST /coupon-campaign/drop`：删除尚无人领取的活动。已有领取记录的活动只能停用。

创建活动请求示例：

```json
{
  "name": "TG 新用户优惠",
  "description": "通过 Telegram 领取",
  "type": 1,
  "value": 500,
  "claim_started_at": 1795766400,
  "claim_ended_at": 1798368399,
  "coupon_valid_days": 7,
  "limit_claim": 200,
  "limit_plan_ids": [1, 2],
  "limit_period": ["month_price", "quarter_price"],
  "enabled": true
}
```

`coupon_valid_days` 是用户领取后的有效天数；`limit_claim` 为空表示不限制领取总量。每个 Xboard 用户在每个活动中只能领取一次。已产生领取记录后，不允许修改优惠额度、适用范围或领取后有效期。

## 领取与核销

用户需先绑定 Telegram 账号，然后在 Bot 私聊中发送 `/coupon` 并点击活动按钮；`/mycoupons` 可查看个人优惠码及状态。每次领取产生专属随机优惠码，领取操作幂等。网页端核验和订单创建都会检查优惠券归属、适用套餐/周期、有效期和使用状态；创建订单时在同一事务内扣减券次数并记录核销订单。
