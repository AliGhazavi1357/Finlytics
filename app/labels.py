"""Persian user-facing labels for internal codes and English finance jargon."""

SOURCE_LABELS = {
    "manual": "ثبت دستی",
    "sales": "فروش",
    "cogs": "بهای تمام‌شده کالا",
    "ops": "هزینه‌های عملیاتی",
    "payroll": "حقوق و دستمزد",
    "excel": "ورود از اکسل",
}

CATEGORY_ALIASES = {
    "COGS": "بهای تمام‌شده کالا",
    "cogs": "بهای تمام‌شده کالا",
    "بهای تمام‌شده": "بهای تمام‌شده کالا",
    "OPS": "هزینه‌های عملیاتی",
    "OpEx": "هزینه‌های عملیاتی",
    "OPEX": "هزینه‌های عملیاتی",
    "Revenue": "درآمد",
    "Margin": "حاشیه سود",
    "EBITDA": "سود عملیاتی قبل از بهره و مالیات و استهلاک",
}

KIND_LABELS = {
    "product": "محصول",
    "service": "خدمت",
}


def source_label(source: str | None) -> str:
    if not source:
        return "ثبت دستی"
    return SOURCE_LABELS.get(source, source)


def kind_label(kind: str | None) -> str:
    if not kind:
        return ""
    return KIND_LABELS.get(kind, kind)


def localize_category(category: str | None) -> str:
    if not category:
        return "عمومی"
    return CATEGORY_ALIASES.get(category, category)
