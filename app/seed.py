from __future__ import annotations

import random
from datetime import date, timedelta
from calendar import monthrange

from sqlalchemy.orm import Session

from app.auth import hash_password
from app.models import (
    Department,
    Employee,
    Payroll,
    ProductService,
    Sale,
    Transaction,
    User,
)


DEMO_PHONE = "09131176583"
DEMO_PASSWORD = "123456789"


def _d(year: int, month: int, day: int) -> date:
    return date(year, month, day)


def seed_database(db: Session) -> None:
    if db.query(User).first():
        return

    rng = random.Random(42)
    today = date.today()

    user = User(
        phone=DEMO_PHONE,
        password_hash=hash_password(DEMO_PASSWORD),
        full_name="علی قضاوی",
    )
    db.add(user)

    departments = [
        Department(name="تولید"),
        Department(name="فروش و بازاریابی"),
        Department(name="مالی و اداری"),
        Department(name="فناوری اطلاعات"),
        Department(name="خدمات مشتریان"),
    ]
    db.add_all(departments)
    db.flush()

    roles = {
        "تولید": ["سرپرست خط", "اپراتور تولید", "کنترل کیفیت", "انباردار"],
        "فروش و بازاریابی": ["مدیر فروش", "کارشناس فروش", "کارشناس بازاریابی"],
        "مالی و اداری": ["حسابدار", "کارشناس منابع انسانی", "منشی"],
        "فناوری اطلاعات": ["توسعه‌دهنده", "پشتیبان سیستم", "تحلیل‌گر داده"],
        "خدمات مشتریان": ["کارشناس پشتیبانی", "هماهنگ‌کننده پروژه"],
    }
    first_names = ["رضا", "سارا", "محمد", "نرگس", "امیر", "زهرا", "حسین", "مینا", "پارسا", "الهام", "کیان", "نیلوفر"]
    last_names = ["محمدی", "احمدی", "کریمی", "رضایی", "حسینی", "موسوی", "جعفری", "نوری", "کاظمی", "اکبری"]

    employees: list[Employee] = []
    emp_idx = 1
    for dept in departments:
        count = 4 if dept.name != "تولید" else 7
        for _ in range(count):
            role = rng.choice(roles[dept.name])
            salary = {
                "سرپرست خط": 48_000_000,
                "اپراتور تولید": 32_000_000,
                "کنترل کیفیت": 36_000_000,
                "انباردار": 30_000_000,
                "مدیر فروش": 55_000_000,
                "کارشناس فروش": 38_000_000,
                "کارشناس بازاریابی": 35_000_000,
                "حسابدار": 40_000_000,
                "کارشناس منابع انسانی": 37_000_000,
                "منشی": 28_000_000,
                "توسعه‌دهنده": 52_000_000,
                "پشتیبان سیستم": 34_000_000,
                "تحلیل‌گر داده": 45_000_000,
                "کارشناس پشتیبانی": 31_000_000,
                "هماهنگ‌کننده پروژه": 39_000_000,
            }.get(role, 33_000_000)
            salary = int(salary * rng.uniform(0.92, 1.12))
            emp = Employee(
                code=f"EMP{emp_idx:03d}",
                full_name=f"{rng.choice(first_names)} {rng.choice(last_names)}",
                role=role,
                department_id=dept.id,
                monthly_salary=salary,
                hire_date=today - timedelta(days=rng.randint(90, 1600)),
                is_active=True,
            )
            employees.append(emp)
            emp_idx += 1
    db.add_all(employees)
    db.flush()

    catalog = [
        ("PRD-001", "بسته نرم‌افزار مالی سازمانی", "product", 85_000_000, 28_000_000),
        ("PRD-002", "ماژول گزارش‌گیری پیشرفته", "product", 42_000_000, 12_000_000),
        ("PRD-003", "لایسنس داشبورد هوش تجاری", "product", 28_000_000, 7_500_000),
        ("SRV-001", "پیاده‌سازی و استقرار سامانه", "service", 95_000_000, 45_000_000),
        ("SRV-002", "پشتیبانی ماهانه طلایی", "service", 18_000_000, 6_000_000),
        ("SRV-003", "آموزش تخصصی تیم مالی", "service", 24_000_000, 8_000_000),
        ("SRV-004", "مشاوره بهینه‌سازی هزینه", "service", 35_000_000, 10_000_000),
        ("PRD-004", "پکیج موبایل گزارش‌گیری", "product", 22_000_000, 6_500_000),
    ]
    items = [
        ProductService(
            code=code,
            name=name,
            kind=kind,
            unit_price=price,
            unit_cost=cost,
        )
        for code, name, kind, price, cost in catalog
    ]
    db.add_all(items)
    db.flush()

    # Sales + income/expense for last ~14 months
    start = today.replace(day=1) - timedelta(days=400)
    start = start.replace(day=1)
    cursor = start
    while cursor <= today:
        for item in items:
            # fewer weekends
            if cursor.weekday() >= 5 and rng.random() < 0.55:
                continue
            qty = rng.choice([0, 0, 0, 1, 1, 1, 2, 2, 3]) if item.kind == "product" else rng.choice([0, 0, 1, 1, 1, 2])
            if qty == 0:
                continue
            price = item.unit_price * rng.uniform(0.95, 1.08)
            cost = item.unit_cost * rng.uniform(0.97, 1.05)
            revenue = round(qty * price, 0)
            total_cost = round(qty * cost, 0)
            sale = Sale(
                item_id=item.id,
                sale_date=cursor,
                quantity=qty,
                unit_price=round(price, 0),
                unit_cost=round(cost, 0),
                revenue=revenue,
                cost=total_cost,
                profit=revenue - total_cost,
                channel=rng.choice(["فروش مستقیم", "وب‌سایت", "نماینده", "قرارداد سازمانی"]),
            )
            db.add(sale)
            db.add(
                Transaction(
                    txn_date=cursor,
                    direction="income",
                    category="فروش محصول" if item.kind == "product" else "ارائه خدمت",
                    title=f"فروش {item.name}",
                    amount=revenue,
                    source="sales",
                    reference=item.code,
                )
            )
            # بهای تمام‌شده کالا (هزینه کالای فروخته‌شده)
            db.add(
                Transaction(
                    txn_date=cursor,
                    direction="expense",
                    category="بهای تمام‌شده کالا",
                    title=f"هزینه کالای فروخته‌شده — {item.name}",
                    amount=total_cost,
                    source="cogs",
                    reference=item.code,
                )
            )

        # هزینه‌های عملیاتی
        if cursor.weekday() < 5 and rng.random() < 0.45:
            ops = [
                ("اجاره و تاسیسات", "پرداخت اجاره/انرژی", rng.randint(4_000_000, 12_000_000)),
                ("بازاریابی", "کمپین تبلیغاتی دیجیتال", rng.randint(2_000_000, 9_000_000)),
                ("لوازم مصرفی", "خرید ملزومات اداری", rng.randint(800_000, 3_500_000)),
                ("حمل‌ونقل", "هزینه ارسال و ماموریت", rng.randint(1_000_000, 4_500_000)),
                ("نگهداری تجهیزات", "سرویس سخت‌افزار", rng.randint(1_500_000, 6_000_000)),
            ]
            cat, title, amount = rng.choice(ops)
            db.add(
                Transaction(
                    txn_date=cursor,
                    direction="expense",
                    category=cat,
                    title=title,
                    amount=amount,
                    source="ops",
                )
            )
        cursor += timedelta(days=1)

    # Monthly payroll for last 14 months
    y, m = today.year, today.month
    for _ in range(14):
        for emp in employees:
            bonus = 0 if rng.random() < 0.7 else round(emp.monthly_salary * rng.uniform(0.05, 0.15), 0)
            deductions = round(emp.monthly_salary * rng.uniform(0.07, 0.11), 0)
            net = emp.monthly_salary + bonus - deductions
            paid_day = min(28, monthrange(y, m)[1])
            paid_on = _d(y, m, paid_day)
            if paid_on > today:
                continue
            db.add(
                Payroll(
                    employee_id=emp.id,
                    period_year=y,
                    period_month=m,
                    base_salary=emp.monthly_salary,
                    bonus=bonus,
                    deductions=deductions,
                    net_pay=net,
                    paid_on=paid_on,
                )
            )
            db.add(
                Transaction(
                    txn_date=paid_on,
                    direction="expense",
                    category="حقوق و دستمزد",
                    title=f"حقوق {emp.full_name} - {m:02d}/{y}",
                    amount=net,
                    source="payroll",
                    reference=emp.code,
                )
            )
        m -= 1
        if m == 0:
            m = 12
            y -= 1

    # Ensure today has some activity for daily report
    if not db.query(Sale).filter(Sale.sale_date == today).first():
        item = items[0]
        revenue = item.unit_price
        cost = item.unit_cost
        db.add(
            Sale(
                item_id=item.id,
                sale_date=today,
                quantity=1,
                unit_price=revenue,
                unit_cost=cost,
                revenue=revenue,
                cost=cost,
                profit=revenue - cost,
                channel="فروش مستقیم",
            )
        )
        db.add(
            Transaction(
                txn_date=today,
                direction="income",
                category="فروش محصول",
                title=f"فروش {item.name}",
                amount=revenue,
                source="sales",
                reference=item.code,
            )
        )

    db.commit()


def ensure_today_financial_data(db: Session) -> None:
    """If today has no transactions, generate sample sales/expenses for the daily dashboard."""
    today = date.today()
    has_today = (
        db.query(Transaction)
        .filter(Transaction.txn_date == today)
        .first()
        is not None
    )
    if has_today:
        return

    items = db.query(ProductService).filter(ProductService.is_active.is_(True)).all()
    if not items:
        return

    rng = random.Random(int(today.strftime("%Y%m%d")))
    for item in items:
        qty = rng.choice([1, 1, 2, 2, 3]) if item.kind == "product" else rng.choice([1, 1, 1, 2])
        price = item.unit_price * rng.uniform(0.96, 1.06)
        cost = item.unit_cost * rng.uniform(0.97, 1.04)
        revenue = round(qty * price, 0)
        total_cost = round(qty * cost, 0)
        db.add(
            Sale(
                item_id=item.id,
                sale_date=today,
                quantity=qty,
                unit_price=round(price, 0),
                unit_cost=round(cost, 0),
                revenue=revenue,
                cost=total_cost,
                profit=revenue - total_cost,
                channel="فروش مستقیم",
            )
        )
        db.add(
            Transaction(
                txn_date=today,
                direction="income",
                category="فروش محصول" if item.kind == "product" else "ارائه خدمت",
                title=f"فروش {item.name}",
                amount=revenue,
                source="sales",
                reference=item.code,
            )
        )
        db.add(
            Transaction(
                txn_date=today,
                direction="expense",
                category="بهای تمام‌شده کالا",
                title=f"هزینه کالای فروخته‌شده — {item.name}",
                amount=total_cost,
                source="cogs",
                reference=item.code,
            )
        )

    ops = [
        ("بازاریابی", "فعالیت بازاریابی روز جاری", rng.randint(2_500_000, 6_000_000)),
        ("لوازم مصرفی", "خرید ملزومات روز جاری", rng.randint(700_000, 2_200_000)),
        ("حمل‌ونقل", "هزینه ارسال روز جاری", rng.randint(1_000_000, 3_500_000)),
    ]
    cat, title, amount = rng.choice(ops)
    db.add(
        Transaction(
            txn_date=today,
            direction="expense",
            category=cat,
            title=title,
            amount=amount,
            source="ops",
        )
    )
    db.commit()


def migrate_legacy_labels(db: Session) -> None:
    """Rename legacy English/short categories to clear Persian labels."""
    renames = {
        "بهای تمام‌شده": "بهای تمام‌شده کالا",
        "COGS": "بهای تمام‌شده کالا",
        "OpEx": "هزینه‌های عملیاتی",
        "OPS": "هزینه‌های عملیاتی",
        "OPEX": "هزینه‌های عملیاتی",
    }
    changed = False
    for old, new in renames.items():
        rows = db.query(Transaction).filter(Transaction.category == old).all()
        for row in rows:
            row.category = new
            changed = True
    if changed:
        db.commit()
