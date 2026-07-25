from __future__ import annotations

from calendar import monthrange
from datetime import date, timedelta
from typing import Literal

from sqlalchemy import func
from sqlalchemy.orm import Session

from app.jalali import format_jalali, jalali_month_year, jalali_year_label
from app.labels import localize_category, source_label
from app.models import Payroll, ProductService, Sale, Transaction
from app.numbers import format_fa_money, format_fa_pct
from app.schemas import (
    CategorySlice,
    ChartPoint,
    DashboardResponse,
    ReportPeriodResponse,
    SummaryCard,
    TomorrowForecast,
)


PeriodType = Literal["daily", "monthly", "yearly"]

COGS_CATEGORIES = {"بهای تمام‌شده کالا", "بهای تمام‌شده", "هزینه کالای فروخته‌شده"}
OPEX_CATEGORIES = {
    "هزینه‌های عملیاتی",
    "اجاره و تاسیسات",
    "بازاریابی",
    "لوازم مصرفی",
    "حمل‌ونقل",
    "نگهداری تجهیزات",
}


def _period_bounds(period: PeriodType, ref: date | None = None) -> tuple[date, date, str]:
    ref = ref or date.today()
    if period == "daily":
        return ref, ref, f"گزارش روزانه {format_jalali(ref)}"
    if period == "monthly":
        start = ref.replace(day=1)
        end = ref.replace(day=monthrange(ref.year, ref.month)[1])
        return start, end, f"گزارش ماهانه {jalali_month_year(ref)}"
    start = date(ref.year, 1, 1)
    end = date(ref.year, 12, 31)
    return start, end, f"گزارش سال مالی {jalali_year_label(ref)}"


def _sum_tx(db: Session, start: date, end: date, direction: str) -> float:
    value = (
        db.query(func.coalesce(func.sum(Transaction.amount), 0.0))
        .filter(
            Transaction.txn_date >= start,
            Transaction.txn_date <= end,
            Transaction.direction == direction,
        )
        .scalar()
    )
    return float(value or 0)


def _sum_tx_categories(db: Session, start: date, end: date, categories: set[str]) -> float:
    if not categories:
        return 0.0
    value = (
        db.query(func.coalesce(func.sum(Transaction.amount), 0.0))
        .filter(
            Transaction.txn_date >= start,
            Transaction.txn_date <= end,
            Transaction.direction == "expense",
            Transaction.category.in_(list(categories)),
        )
        .scalar()
    )
    return float(value or 0)


def _breakdown(db: Session, start: date, end: date, direction: str) -> list[CategorySlice]:
    rows = (
        db.query(Transaction.category, func.sum(Transaction.amount))
        .filter(
            Transaction.txn_date >= start,
            Transaction.txn_date <= end,
            Transaction.direction == direction,
        )
        .group_by(Transaction.category)
        .order_by(func.sum(Transaction.amount).desc())
        .all()
    )
    return [CategorySlice(category=localize_category(c), amount=float(a or 0)) for c, a in rows]


def _sales_stats(db: Session, start: date, end: date) -> tuple[float, float]:
    revenue, profit = (
        db.query(
            func.coalesce(func.sum(Sale.revenue), 0.0),
            func.coalesce(func.sum(Sale.profit), 0.0),
        )
        .filter(Sale.sale_date >= start, Sale.sale_date <= end)
        .one()
    )
    return float(revenue or 0), float(profit or 0)


def _payroll_cost(db: Session, start: date, end: date) -> float:
    value = (
        db.query(func.coalesce(func.sum(Payroll.net_pay), 0.0))
        .filter(Payroll.paid_on >= start, Payroll.paid_on <= end)
        .scalar()
    )
    return float(value or 0)


def build_trend(db: Session, period: PeriodType, start: date, end: date) -> list[ChartPoint]:
    points: list[ChartPoint] = []
    if period == "daily":
        cursor = end - timedelta(days=13)
        while cursor <= end:
            income = _sum_tx(db, cursor, cursor, "income")
            expense = _sum_tx(db, cursor, cursor, "expense")
            points.append(
                ChartPoint(
                    label=f"{cursor.month}/{cursor.day}",
                    income=income,
                    expense=expense,
                    profit=income - expense,
                )
            )
            cursor += timedelta(days=1)
        return points

    if period == "monthly":
        cursor = start
        while cursor <= end:
            income = _sum_tx(db, cursor, cursor, "income")
            expense = _sum_tx(db, cursor, cursor, "expense")
            points.append(
                ChartPoint(
                    label=str(cursor.day),
                    income=income,
                    expense=expense,
                    profit=income - expense,
                )
            )
            cursor += timedelta(days=1)
        if len(points) > 16:
            bucket = max(1, len(points) // 12)
            compressed: list[ChartPoint] = []
            for i in range(0, len(points), bucket):
                chunk = points[i : i + bucket]
                compressed.append(
                    ChartPoint(
                        label=chunk[0].label,
                        income=sum(p.income for p in chunk),
                        expense=sum(p.expense for p in chunk),
                        profit=sum(p.profit for p in chunk),
                    )
                )
            return compressed
        return points

    for month in range(1, 13):
        m_start = date(start.year, month, 1)
        m_end = date(start.year, month, monthrange(start.year, month)[1])
        if m_start > end:
            break
        income = _sum_tx(db, m_start, min(m_end, end), "income")
        expense = _sum_tx(db, m_start, min(m_end, end), "expense")
        points.append(
            ChartPoint(
                label=f"{month}",
                income=income,
                expense=expense,
                profit=income - expense,
            )
        )
    return points


def previous_period(start: date, end: date) -> tuple[date, date]:
    length = (end - start).days + 1
    prev_end = start - timedelta(days=1)
    prev_start = prev_end - timedelta(days=length - 1)
    return prev_start, prev_end


def pct_change(current: float, previous: float) -> float | None:
    if previous == 0:
        return None if current == 0 else 100.0
    return round(((current - previous) / abs(previous)) * 100, 1)


def predict_tomorrow(db: Session, ref: date | None = None) -> TomorrowForecast:
    """Weighted average of last 7 days (recent days count more)."""
    ref = ref or date.today()
    tomorrow = ref + timedelta(days=1)
    incomes: list[float] = []
    expenses: list[float] = []
    weights: list[float] = []
    for i in range(7):
        day = ref - timedelta(days=i)
        incomes.append(_sum_tx(db, day, day, "income"))
        expenses.append(_sum_tx(db, day, day, "expense"))
        weights.append(7 - i)

    w_sum = sum(weights) or 1
    pred_income = round(sum(v * w for v, w in zip(incomes, weights)) / w_sum, 0)
    pred_expense = round(sum(v * w for v, w in zip(expenses, weights)) / w_sum, 0)
    pred_profit = pred_income - pred_expense

    # mild trend nudge from last 3 vs prior 3 days
    recent_avg = sum(incomes[:3]) / 3 if incomes else 0
    prior_avg = sum(incomes[3:6]) / 3 if len(incomes) >= 6 else recent_avg
    if prior_avg > 0:
        trend_factor = max(0.85, min(1.15, recent_avg / prior_avg))
        pred_income = round(pred_income * trend_factor, 0)
        pred_profit = pred_income - pred_expense

    jalali_tomorrow = format_jalali(tomorrow)
    if pred_profit >= 0:
        outlook = (
            f"پیش‌بینی فردا ({jalali_tomorrow}): درآمد حدود {format_fa_money(pred_income)}، "
            f"هزینه حدود {format_fa_money(pred_expense)} و سود خالص تقریبی {format_fa_money(pred_profit)}. "
            "روند اخیر از تداوم وضعیت سودآور حمایت می‌کند."
        )
    else:
        outlook = (
            f"پیش‌بینی فردا ({jalali_tomorrow}): درآمد حدود {format_fa_money(pred_income)}، "
            f"هزینه حدود {format_fa_money(pred_expense)} و زیان تقریبی {format_fa_money(abs(pred_profit))}. "
            "توصیه می‌شود هزینه‌های عملیاتی و بهای تمام‌شده کالا کنترل شود."
        )

    return TomorrowForecast(
        forecast_date=tomorrow,
        predicted_income=pred_income,
        predicted_expense=pred_expense,
        predicted_profit=pred_profit,
        confidence_note="بر اساس میانگین وزنی ۷ روز اخیر با تعدیل روند کوتاه‌مدت",
        method="weighted_7d_trend",
        narrative=outlook,
    )


def build_dashboard(db: Session, period: PeriodType = "monthly", ref: date | None = None) -> DashboardResponse:
    start, end, label = _period_bounds(period, ref)
    income = _sum_tx(db, start, end, "income")
    expense = _sum_tx(db, start, end, "expense")
    profit = income - expense
    sales_rev, sales_profit = _sales_stats(db, start, end)
    payroll = _payroll_cost(db, start, end)
    tomorrow = predict_tomorrow(db, ref or date.today())

    p_start, p_end = previous_period(start, end)
    prev_income = _sum_tx(db, p_start, p_end, "income")
    prev_expense = _sum_tx(db, p_start, p_end, "expense")
    prev_profit = prev_income - prev_expense

    top_products = (
        db.query(
            ProductService.name,
            func.sum(Sale.revenue).label("revenue"),
            func.sum(Sale.profit).label("profit"),
            func.sum(Sale.quantity).label("qty"),
        )
        .join(Sale, Sale.item_id == ProductService.id)
        .filter(Sale.sale_date >= start, Sale.sale_date <= end)
        .group_by(ProductService.id)
        .order_by(func.sum(Sale.revenue).desc())
        .limit(5)
        .all()
    )

    recent = (
        db.query(Transaction)
        .filter(Transaction.txn_date >= start, Transaction.txn_date <= end)
        .order_by(Transaction.txn_date.desc(), Transaction.id.desc())
        .limit(12)
        .all()
    )

    cards = [
        SummaryCard(label="کل درآمد", value=income, change_pct=pct_change(income, prev_income), tone="positive"),
        SummaryCard(label="کل هزینه", value=expense, change_pct=pct_change(expense, prev_expense), tone="warning"),
        SummaryCard(label="سود خالص", value=profit, change_pct=pct_change(profit, prev_profit), tone="accent"),
        SummaryCard(label="درآمد فروش", value=sales_rev, tone="positive"),
        SummaryCard(label="سود فروش", value=sales_profit, tone="accent"),
        SummaryCard(label="هزینه حقوق", value=payroll, tone="warning"),
        SummaryCard(label="پیش‌بینی درآمد فردا", value=tomorrow.predicted_income, tone="positive"),
        SummaryCard(label="پیش‌بینی سود فردا", value=tomorrow.predicted_profit, tone="accent"),
    ]

    return DashboardResponse(
        period=label,
        cards=cards,
        trend=build_trend(db, period, start, end),
        expense_breakdown=_breakdown(db, start, end, "expense")[:8],
        income_breakdown=_breakdown(db, start, end, "income")[:8],
        top_products=[
            {
                "name": name,
                "revenue": float(revenue or 0),
                "profit": float(profit_v or 0),
                "quantity": float(qty or 0),
            }
            for name, revenue, profit_v, qty in top_products
        ],
        recent_transactions=[
            {
                "id": t.id,
                "txn_date": t.txn_date.isoformat(),
                "direction": t.direction,
                "category": localize_category(t.category),
                "title": t.title,
                "amount": t.amount,
                "source": t.source,
                "source_label": source_label(t.source),
            }
            for t in recent
        ],
        tomorrow=tomorrow,
    )


def build_period_report(db: Session, period: PeriodType, ref: date | None = None) -> ReportPeriodResponse:
    start, end, label = _period_bounds(period, ref)
    income = _sum_tx(db, start, end, "income")
    expense = _sum_tx(db, start, end, "expense")
    profit = income - expense
    sales_rev, sales_profit = _sales_stats(db, start, end)
    payroll = _payroll_cost(db, start, end)
    cogs = _sum_tx_categories(db, start, end, COGS_CATEGORIES)
    opex = _sum_tx_categories(db, start, end, OPEX_CATEGORIES)
    days = max(1, (end - start).days + 1)
    margin = round((profit / income) * 100, 1) if income else 0.0
    tomorrow = predict_tomorrow(db, ref or date.today())

    if profit >= 0:
        status = "عملکرد مالی در وضعیت مطلوب و سودآور قرار دارد"
    else:
        status = "عملکرد مالی زیان‌ده بوده و نیازمند بازنگری هزینه است"

    narrative = (
        f"{label}. {status}. "
        f"جمع درآمد {format_fa_money(income)} و جمع هزینه {format_fa_money(expense)} ثبت شده است. "
        f"سود خالص برابر {format_fa_money(profit)} با حاشیه سود {format_fa_pct(margin)} است. "
        f"درآمد حاصل از فروش محصول و خدمت {format_fa_money(sales_rev)} و سود فروش {format_fa_money(sales_profit)} بوده است. "
        f"بهای تمام‌شده کالا {format_fa_money(cogs)} و هزینه‌های عملیاتی {format_fa_money(opex)} بوده است. "
        f"هزینه حقوق و دستمزد در این بازه {format_fa_money(payroll)} محاسبه شده است. "
        f"{tomorrow.narrative}"
    )

    return ReportPeriodResponse(
        period_type=period,
        label=label,
        start_date=start,
        end_date=end,
        total_income=income,
        total_expense=expense,
        net_profit=profit,
        sales_revenue=sales_rev,
        sales_profit=sales_profit,
        payroll_cost=payroll,
        cogs_cost=cogs,
        opex_cost=opex,
        margin_pct=margin,
        daily_avg_income=round(income / days, 0),
        daily_avg_expense=round(expense / days, 0),
        narrative=narrative,
        trend=build_trend(db, period, start, end),
        tomorrow=tomorrow,
    )


def build_ceo_script(db: Session, report_date: date | None = None) -> str:
    report_date = report_date or date.today()
    daily = build_period_report(db, "daily", report_date)
    monthly = build_period_report(db, "monthly", report_date)
    tomorrow = daily.tomorrow or predict_tomorrow(db, report_date)

    top = (
        db.query(ProductService.name, func.sum(Sale.revenue))
        .join(Sale, Sale.item_id == ProductService.id)
        .filter(Sale.sale_date == report_date)
        .group_by(ProductService.id)
        .order_by(func.sum(Sale.revenue).desc())
        .limit(2)
        .all()
    )
    top_line = "، ".join(f"{name}" for name, _ in top) if top else "بدون فروش برجسته"

    outlook = (
        "پیشنهاد می‌شود تمرکز روی خطوط سودآور و کنترل هزینه‌های عملیاتی ادامه یابد."
        if daily.net_profit >= 0
        else "پیشنهاد می‌شود هزینه‌های غیرضروری امروز بررسی و فروش روزهای آینده تقویت شود."
    )

    script = (
        f"با سلام و احترام، گزارش عملکرد مالی روز {format_jalali(report_date)} خدمت مدیرعامل محترم ارائه می‌شود. "
        f"امروز مجموع درآمد {format_fa_money(daily.total_income)} و مجموع هزینه‌ها {format_fa_money(daily.total_expense)} بوده است. "
        f"نتیجه خالص روز برابر {format_fa_money(daily.net_profit)} ثبت شده است. "
        f"درآمد فروش محصول و خدمت امروز {format_fa_money(daily.sales_revenue)} و سود فروش {format_fa_money(daily.sales_profit)} بوده است. "
        f"بهای تمام‌شده کالا امروز {format_fa_money(daily.cogs_cost)} و هزینه‌های عملیاتی {format_fa_money(daily.opex_cost)} بوده است. "
        f"اقلام پرفروش امروز شامل {top_line} می‌باشد. "
        f"در مقیاس ماه جاری، درآمد تجمیعی {format_fa_money(monthly.total_income)}، هزینه {format_fa_money(monthly.total_expense)} "
        f"و سود خالص {format_fa_money(monthly.net_profit)} با حاشیه سود {format_fa_pct(monthly.margin_pct)} است. "
        f"هزینه حقوق در ماه جاری حدود {format_fa_money(monthly.payroll_cost)} برآورد شده است. "
        f"{tomorrow.narrative} "
        f"{outlook} "
        f"پایان گزارش روزانه Finlytics. با سپاس."
    )
    return script
