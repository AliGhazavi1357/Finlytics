from __future__ import annotations

from fastapi import HTTPException

from app.config import settings
from app.labels import localize_category
from app.numbers import format_fa_money, parse_number


ALLOWED_DIRECTIONS = {"income", "expense"}
ALLOWED_KINDS = {"product", "service"}

# Common expense categories used in seed / forms (guidance, not a hard whitelist for custom)
KNOWN_EXPENSE_CATEGORIES = {
    "بهای تمام‌شده کالا",
    "بهای تمام‌شده",
    "هزینه کالای فروخته‌شده",
    "هزینه‌های عملیاتی",
    "اجاره و تاسیسات",
    "بازاریابی",
    "لوازم مصرفی",
    "حمل‌ونقل",
    "نگهداری تجهیزات",
    "حقوق و دستمزد",
}


def validate_amount(amount, *, field: str = "مبلغ") -> float:
    value = parse_number(amount, field=field)
    if value < 0:
        raise HTTPException(status_code=400, detail=f"{field} نمی‌تواند منفی باشد")
    if value > settings.max_transaction_amount:
        raise HTTPException(
            status_code=400,
            detail=f"{field} از سقف مجاز ({format_fa_money(settings.max_transaction_amount)}) بیشتر است",
        )
    return value


def validate_salary(salary) -> float:
    value = validate_amount(salary, field="حقوق ماهانه")
    if value > settings.max_monthly_salary:
        raise HTTPException(
            status_code=400,
            detail=f"حقوق ماهانه از سقف حقوق ({format_fa_money(settings.max_monthly_salary)}) بیشتر است",
        )
    if value < settings.min_monthly_salary:
        raise HTTPException(
            status_code=400,
            detail=f"حقوق ماهانه کمتر از حداقل مجاز ({format_fa_money(settings.min_monthly_salary)}) است",
        )
    return value


def validate_direction(direction: str | None) -> str:
    if not direction or direction not in ALLOWED_DIRECTIONS:
        raise HTTPException(status_code=400, detail="نوع تراکنش باید ورودی یا خروجی باشد")
    return direction


def validate_kind(kind: str | None) -> str:
    if not kind or kind not in ALLOWED_KINDS:
        raise HTTPException(status_code=400, detail="نوع آیتم باید محصول یا خدمت باشد")
    return kind


def validate_required_text(value: str | None, field: str, *, max_len: int = 200) -> str:
    text = (value or "").strip()
    if not text:
        raise HTTPException(status_code=400, detail=f"{field} الزامی است")
    if len(text) > max_len:
        raise HTTPException(status_code=400, detail=f"{field} بیش از حد طولانی است")
    return text


def validate_category(category: str | None) -> str:
    text = validate_required_text(category, "دسته", max_len=80)
    return localize_category(text)


def validate_unit_economics(unit_price: float, unit_cost: float) -> None:
    if unit_cost > unit_price * 1.5 and unit_price > 0:
        if unit_cost > unit_price * 3:
            raise HTTPException(
                status_code=400,
                detail="بهای تمام‌شده واحد نباید بیش از ۳ برابر قیمت فروش باشد",
            )
