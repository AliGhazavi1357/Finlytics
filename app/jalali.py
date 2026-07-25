"""Jalali (Persian) calendar helpers for fiscal-year UI labels."""

from __future__ import annotations

from datetime import date


_MONTHS = (
    "فروردین",
    "اردیبهشت",
    "خرداد",
    "تیر",
    "مرداد",
    "شهریور",
    "مهر",
    "آبان",
    "آذر",
    "دی",
    "بهمن",
    "اسفند",
)


def gregorian_to_jalali(gy: int, gm: int, gd: int) -> tuple[int, int, int]:
    g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334]
    if gy > 1600:
        jy = 979
        gy -= 1600
    else:
        jy = 0
        gy -= 621
    gy2 = gy + 1 if gm > 2 else gy
    days = (
        365 * gy
        + (gy2 + 3) // 4
        - (gy2 + 99) // 100
        + (gy2 + 399) // 400
        - 80
        + gd
        + g_d_m[gm - 1]
    )
    jy += 33 * (days // 12053)
    days %= 12053
    jy += 4 * (days // 1461)
    days %= 1461
    if days > 365:
        jy += (days - 1) // 365
        days = (days - 1) % 365
    if days < 186:
        jm = 1 + days // 31
        jd = 1 + days % 31
    else:
        jm = 7 + (days - 186) // 30
        jd = 1 + (days - 186) % 30
    return jy, jm, jd


def to_jalali(d: date) -> tuple[int, int, int]:
    return gregorian_to_jalali(d.year, d.month, d.day)


def to_persian_digits(value: str | int) -> str:
    en = "0123456789"
    fa = "۰۱۲۳۴۵۶۷۸۹"
    text = str(value)
    return text.translate(str.maketrans(en, fa))


def format_jalali(d: date, *, with_month_name: bool = False) -> str:
    jy, jm, jd = to_jalali(d)
    if with_month_name:
        return to_persian_digits(f"{jd} {_MONTHS[jm - 1]} {jy}")
    return to_persian_digits(f"{jy}/{jm:02d}/{jd:02d}")


def jalali_year(d: date) -> int:
    return to_jalali(d)[0]


def jalali_year_label(d: date) -> str:
    return to_persian_digits(jalali_year(d))


def jalali_month_year(d: date) -> str:
    jy, jm, _ = to_jalali(d)
    return to_persian_digits(f"{_MONTHS[jm - 1]} {jy}")
