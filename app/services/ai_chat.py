from __future__ import annotations

import re
from datetime import date, datetime, timedelta

import httpx
from fastapi import HTTPException
from sqlalchemy import func
from sqlalchemy.orm import Session

from app.config import settings
from app.models import AiQuestion, Employee, ProductService, Sale, User
from app.numbers import format_fa_money, format_fa_pct, to_fa_digits
from app.services.reports import build_period_report, predict_for_period

SUGGESTED_QUESTIONS = [
    "سود امروز چقدر است؟",
    "وضعیت مالی ماه جاری چگونه است؟",
    "بیشترین هزینه مربوط به چیست؟",
    "پیش‌بینی فردا برای درآمد و هزینه چیست؟",
    "پیش‌بینی ماه آینده چیست؟",
]


def _day_bounds(ref: date | None = None) -> tuple[datetime, datetime]:
    ref = ref or date.today()
    start = datetime(ref.year, ref.month, ref.day)
    end = start + timedelta(days=1)
    return start, end


def count_today_questions(db: Session, user_id: int) -> int:
    start, end = _day_bounds()
    return (
        db.query(func.count(AiQuestion.id))
        .filter(
            AiQuestion.user_id == user_id,
            AiQuestion.created_at >= start,
            AiQuestion.created_at < end,
        )
        .scalar()
        or 0
    )


def get_quota(db: Session, user: User) -> dict:
    used = count_today_questions(db, user.id)
    limit = settings.ai_daily_question_limit
    return {
        "used": used,
        "remaining": max(0, limit - used),
        "limit": limit,
        "suggestions": SUGGESTED_QUESTIONS,
    }


def build_finance_context(db: Session) -> str:
    today = date.today()
    daily = build_period_report(db, "daily", today)
    monthly = build_period_report(db, "monthly", today)
    yearly = build_period_report(db, "yearly", today)

    emp_count = db.query(func.count(Employee.id)).filter(Employee.is_active.is_(True)).scalar() or 0
    salary_sum = (
        db.query(func.coalesce(func.sum(Employee.monthly_salary), 0.0))
        .filter(Employee.is_active.is_(True))
        .scalar()
        or 0
    )
    top = (
        db.query(ProductService.name, func.sum(Sale.revenue))
        .join(Sale, Sale.item_id == ProductService.id)
        .filter(Sale.sale_date >= monthly.start_date, Sale.sale_date <= monthly.end_date)
        .group_by(ProductService.id)
        .order_by(func.sum(Sale.revenue).desc())
        .limit(3)
        .all()
    )
    top_line = "، ".join(f"{n} ({format_fa_money(float(r or 0))})" for n, r in top) or "بدون فروش"

    tomorrow_line = ""
    daily_f = predict_for_period(db, "daily", today)
    monthly_f = predict_for_period(db, "monthly", today)
    yearly_f = predict_for_period(db, "yearly", today)
    tomorrow_line = (
        f"{daily_f.narrative} "
        f"{monthly_f.narrative} "
        f"{yearly_f.narrative}"
    )

    return (
        f"تاریخ امروز: {today.isoformat()}\n"
        f"گزارش روزانه: درآمد {format_fa_money(daily.total_income)}، هزینه {format_fa_money(daily.total_expense)}، "
        f"سود {format_fa_money(daily.net_profit)}، فروش {format_fa_money(daily.sales_revenue)}.\n"
        f"گزارش ماهانه: درآمد {format_fa_money(monthly.total_income)}، هزینه {format_fa_money(monthly.total_expense)}، "
        f"سود {format_fa_money(monthly.net_profit)}، حاشیه {format_fa_pct(monthly.margin_pct)}، "
        f"حقوق {format_fa_money(monthly.payroll_cost)}، بهای تمام‌شده {format_fa_money(monthly.cogs_cost)}، "
        f"هزینه عملیاتی {format_fa_money(monthly.opex_cost)}.\n"
        f"گزارش سال مالی: درآمد {format_fa_money(yearly.total_income)}، هزینه {format_fa_money(yearly.total_expense)}، "
        f"سود {format_fa_money(yearly.net_profit)}.\n"
        f"پرسنل فعال: {to_fa_digits(emp_count)} نفر، مجموع حقوق ماهانه اسمی {format_fa_money(float(salary_sum))}.\n"
        f"پرفروش‌های ماه: {top_line}\n"
        f"{tomorrow_line}\n"
        f"خلاصه ماه: {monthly.narrative}"
    )


def _rules_answer(question: str, db: Session) -> str:
    q = question.strip().lower()
    today = date.today()
    daily = build_period_report(db, "daily", today)
    monthly = build_period_report(db, "monthly", today)
    yearly = build_period_report(db, "yearly", today)

    if any(k in q for k in ("امروز", "روزانه", "روز جاری")) and any(
        k in q for k in ("سود", "وضعیت", "عملکرد", "چقدر")
    ):
        return (
            f"امروز درآمد {format_fa_money(daily.total_income)} و هزینه {format_fa_money(daily.total_expense)} بوده است. "
            f"سود خالص روز برابر {format_fa_money(daily.net_profit)} ثبت شده است."
        )

    if any(k in q for k in ("حقوق", "دستمزد", "پرسنل")):
        return (
            f"هزینه حقوق و دستمزد ماه جاری حدود {format_fa_money(monthly.payroll_cost)} است. "
            f"این رقم از مجموع پرداخت‌های حقوق ثبت‌شده در بازه ماه محاسبه شده است."
        )

    if any(k in q for k in ("پیش‌بینی", "فردا", "فردای", "ماه آینده", "سال آینده")):
        if any(k in q for k in ("سال", "سالانه", "سال آینده")):
            t = predict_for_period(db, "yearly", today)
        elif any(k in q for k in ("ماه", "ماهانه", "ماه آینده")):
            t = predict_for_period(db, "monthly", today)
        else:
            t = predict_for_period(db, "daily", today)
        return (
            f"{t.narrative} "
            f"جزئیات: درآمد {format_fa_money(t.predicted_income)}، "
            f"هزینه {format_fa_money(t.predicted_expense)}، "
            f"سود/زیان {format_fa_money(t.predicted_profit)}."
        )

    if any(k in q for k in ("هزینه", "خرج")) and any(k in q for k in ("بیشتر", "بیشترین", "اصلی", "چیست")):
        from app.labels import localize_category
        from app.models import Transaction

        rows = (
            db.query(Transaction.category, func.sum(Transaction.amount))
            .filter(
                Transaction.direction == "expense",
                Transaction.txn_date >= monthly.start_date,
                Transaction.txn_date <= monthly.end_date,
            )
            .group_by(Transaction.category)
            .order_by(func.sum(Transaction.amount).desc())
            .limit(3)
            .all()
        )
        if not rows:
            return "در ماه جاری هزینه قابل‌توجهی ثبت نشده است."
        parts = [f"{localize_category(c)} با {format_fa_money(float(a or 0))}" for c, a in rows]
        return "بیشترین هزینه‌های ماه جاری به‌ترتیب مربوط به " + "؛ ".join(parts) + " است."

    if any(k in q for k in ("سال", "سالانه", "سال مالی")):
        return (
            f"در سال مالی جاری درآمد {format_fa_money(yearly.total_income)}، "
            f"هزینه {format_fa_money(yearly.total_expense)} و سود خالص {format_fa_money(yearly.net_profit)} است "
            f"(حاشیه سود {format_fa_pct(yearly.margin_pct)})."
        )

    if any(k in q for k in ("ماه", "ماهانه", "ماه جاری", "وضعیت")):
        return (
            f"وضعیت ماه جاری: درآمد {format_fa_money(monthly.total_income)}، "
            f"هزینه {format_fa_money(monthly.total_expense)}، سود خالص {format_fa_money(monthly.net_profit)} "
            f"و حاشیه سود {format_fa_pct(monthly.margin_pct)}. درآمد فروش {format_fa_money(monthly.sales_revenue)} بوده است."
        )

    if any(k in q for k in ("فروش", "محصول", "خدمت")):
        return (
            f"درآمد فروش محصول و خدمت ماه جاری {format_fa_money(monthly.sales_revenue)} و "
            f"سود فروش {format_fa_money(monthly.sales_profit)} است."
        )

    return (
        f"بر اساس داده‌های فعلی Finlytics: امروز سود {format_fa_money(daily.net_profit)} و "
        f"ماه جاری سود {format_fa_money(monthly.net_profit)} با حاشیه {format_fa_pct(monthly.margin_pct)} است. "
        f"می‌توانید درباره سود امروز، هزینه حقوق، بیشترین هزینه، پیش‌بینی فردا یا وضعیت سال مالی بپرسید."
    )


async def _openai_answer(question: str, context: str) -> str | None:
    if not settings.openai_api_key:
        return None
    try:
        async with httpx.AsyncClient(timeout=45.0) as client:
            resp = await client.post(
                f"{settings.openai_base_url.rstrip('/')}/chat/completions",
                headers={
                    "Authorization": f"Bearer {settings.openai_api_key}",
                    "Content-Type": "application/json",
                },
                json={
                    "model": settings.openai_model,
                    "temperature": 0.3,
                    "messages": [
                        {
                            "role": "system",
                            "content": (
                                "تو دستیار تحلیل مالی فارسی Finlytics هستی. "
                                "فقط بر اساس داده‌های داده‌شده پاسخ کوتاه، دقیق و رسمی بده. "
                                "اگر داده کافی نیست، صادقانه بگو. واحد پول ریال است."
                            ),
                        },
                        {
                            "role": "user",
                            "content": f"داده‌های مالی:\n{context}\n\nسؤال کاربر:\n{question}",
                        },
                    ],
                },
            )
            resp.raise_for_status()
            text = resp.json()["choices"][0]["message"]["content"].strip()
            return text or None
    except Exception:
        return None


async def ask_finance_question(db: Session, user: User, question: str) -> AiQuestion:
    question = " ".join(question.strip().split())
    if len(question) < 3:
        raise HTTPException(status_code=400, detail="سؤال خیلی کوتاه است")

    # Reject mojibake / encoding-broken payloads like "???? ????"
    persian_letters = sum(1 for ch in question if "\u0600" <= ch <= "\u06FF")
    qmark_noise = question.count("?")
    if persian_letters < 2 or qmark_noise >= 3:
        raise HTTPException(
            status_code=400,
            detail="متن سؤال نامعتبر است. لطفاً سؤال را به فارسی وارد کنید.",
        )

    used = count_today_questions(db, user.id)
    limit = settings.ai_daily_question_limit
    if used >= limit:
        raise HTTPException(
            status_code=429,
            detail=(
                f"سقف پرسش روزانه ({to_fa_digits(limit)} سؤال) به پایان رسیده است. "
                "فردا دوباره تلاش کنید."
            ),
        )

    context = build_finance_context(db)
    ai_text = await _openai_answer(question, context)
    if ai_text:
        answer, mode = to_fa_digits(ai_text), "openai"
    else:
        answer, mode = _rules_answer(question, db), "rules"
        # rules answers already use format_fa_*; ensure any leftover Latin digits convert
        answer = to_fa_digits(answer)

    row = AiQuestion(user_id=user.id, question=question, answer=answer, mode=mode)
    db.add(row)
    db.commit()
    db.refresh(row)
    return row


def _is_garbled_ai_text(text: str) -> bool:
    q = (text or "").strip()
    if not q:
        return True
    if re.fullmatch(r"[\?？\s\.,!:;\-_]+", q):
        return True
    compact = re.sub(r"\s+", "", q)
    marks = len(re.findall(r"[\?？]", compact))
    if compact and marks / len(compact) >= 0.4:
        return True
    has_fa = bool(re.search(r"[\u0600-\u06FF]", q))
    if not has_fa and marks >= 3:
        return True
    return False


def list_recent_questions(db: Session, user_id: int, limit: int = 10) -> list[AiQuestion]:
    rows = (
        db.query(AiQuestion)
        .filter(AiQuestion.user_id == user_id)
        .order_by(AiQuestion.id.desc())
        .limit(limit * 3)
        .all()
    )
    clean: list[AiQuestion] = []
    for row in rows:
        if _is_garbled_ai_text(row.question or ""):
            db.delete(row)
            continue
        clean.append(row)
        if len(clean) >= limit:
            break
    db.commit()
    return clean

