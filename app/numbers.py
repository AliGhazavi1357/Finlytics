"""Persian/English digit helpers for display and input normalization."""

from __future__ import annotations

import re

_FA_DIGITS = str.maketrans("0123456789", "۰۱۲۳۴۵۶۷۸۹")
_EN_DIGITS = str.maketrans("۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩", "01234567890123456789")
_SEP_RE = re.compile(r"[\s,٬٫'’]+")


def to_fa_digits(value) -> str:
    return str(value).translate(_FA_DIGITS)


def to_en_digits(value) -> str:
    return str(value).translate(_EN_DIGITS)


def normalize_number_text(raw) -> str:
    """Convert Persian/Arabic digits to English and strip thousand separators."""
    if raw is None:
        return ""
    if isinstance(raw, (int, float)):
        # Keep as plain number text without locale separators
        if isinstance(raw, float) and raw.is_integer():
            return str(int(raw))
        return str(raw)
    text = to_en_digits(str(raw)).strip()
    text = text.replace("٫", ".")  # Arabic decimal separator → dot
    text = _SEP_RE.sub("", text)
    return text


def parse_number(raw, *, field: str = "عدد") -> float:
    from fastapi import HTTPException

    if isinstance(raw, (int, float)):
        return float(raw)
    text = normalize_number_text(raw)
    if text == "" or text in {".", "-", "+"}:
        raise HTTPException(status_code=400, detail=f"{field} الزامی است")
    try:
        return float(text)
    except ValueError as exc:
        raise HTTPException(status_code=400, detail=f"{field} باید عدد معتبر باشد") from exc


def format_fa_int(value, *, with_sep: bool = True) -> str:
    n = int(round(float(value or 0)))
    if with_sep:
        return to_fa_digits(f"{n:,}")
    return to_fa_digits(str(n))


def format_fa_money(value) -> str:
    return f"{format_fa_int(value)} ریال"


def format_fa_pct(value) -> str:
    return f"{to_fa_digits(f'{float(value or 0):g}')}٪"
