from datetime import date
from pathlib import Path
from typing import Optional

from fastapi import APIRouter, Depends, File, HTTPException, Query, UploadFile
from fastapi.responses import FileResponse, StreamingResponse
from sqlalchemy.orm import Session

from app.auth import authenticate_user, create_access_token, get_current_user
from app.config import settings
from app.database import get_db
from app.labels import kind_label, localize_category, source_label
from app.models import Employee, ProductService, Sale, Transaction, User, VoiceReport
from app.schemas import (
    AiAskRequest,
    AiAskResponse,
    AiQuotaOut,
    DashboardResponse,
    EmployeeOut,
    EmployeeUpdate,
    ImportResult,
    LoginRequest,
    ProductServiceCreate,
    ProductServiceOut,
    ProductServiceUpdate,
    ReportPeriodResponse,
    SaleCreate,
    SaleOut,
    SaleUpdate,
    TokenResponse,
    TransactionCreate,
    TransactionOut,
    TransactionUpdate,
    VoiceReportOut,
)
from app.services.excel_service import build_template_workbook, import_transactions_excel
from app.services.reports import build_dashboard, build_period_report
from app.services.voice_report import generate_daily_voice_report
from app.services.ai_chat import ask_finance_question, get_quota, list_recent_questions
from app.validation import (
    validate_amount,
    validate_category,
    validate_direction,
    validate_kind,
    validate_required_text,
    validate_salary,
    validate_unit_economics,
)

api_router = APIRouter(prefix="/api")


def _tx_out(t: Transaction) -> TransactionOut:
    return TransactionOut(
        id=t.id,
        txn_date=t.txn_date,
        direction=t.direction,
        category=localize_category(t.category),
        title=t.title,
        amount=t.amount,
        source=t.source,
        source_label=source_label(t.source),
        reference=t.reference,
        note=t.note,
    )


def _product_out(p: ProductService) -> ProductServiceOut:
    return ProductServiceOut(
        id=p.id,
        code=p.code,
        name=p.name,
        kind=p.kind,
        kind_label=kind_label(p.kind),
        unit_price=p.unit_price,
        unit_cost=p.unit_cost,
        is_active=p.is_active,
    )


def _sale_out(s: Sale) -> SaleOut:
    return SaleOut(
        id=s.id,
        item_id=s.item_id,
        item_name=s.item.name if s.item else "",
        sale_date=s.sale_date,
        quantity=s.quantity,
        unit_price=s.unit_price,
        unit_cost=s.unit_cost,
        revenue=s.revenue,
        cost=s.cost,
        profit=s.profit,
        channel=s.channel,
        note=s.note,
    )


def _sync_sale_transactions(db: Session, sale: Sale, item: ProductService) -> None:
    """Keep linked income/COGS transactions in sync for sales-sourced rows."""
    income = (
        db.query(Transaction)
        .filter(
            Transaction.source == "sales",
            Transaction.reference == item.code,
            Transaction.txn_date == sale.sale_date,
            Transaction.direction == "income",
            Transaction.amount == sale.revenue,
        )
        .first()
    )
    if not income:
        income = Transaction(
            txn_date=sale.sale_date,
            direction="income",
            category="فروش محصول" if item.kind == "product" else "ارائه خدمت",
            title=f"فروش {item.name}",
            amount=sale.revenue,
            source="sales",
            reference=item.code,
        )
        db.add(income)
    else:
        income.txn_date = sale.sale_date
        income.amount = sale.revenue
        income.title = f"فروش {item.name}"
        income.category = "فروش محصول" if item.kind == "product" else "ارائه خدمت"

    cogs = (
        db.query(Transaction)
        .filter(
            Transaction.source == "cogs",
            Transaction.reference == item.code,
            Transaction.txn_date == sale.sale_date,
            Transaction.direction == "expense",
            Transaction.amount == sale.cost,
        )
        .first()
    )
    if not cogs:
        cogs = Transaction(
            txn_date=sale.sale_date,
            direction="expense",
            category="بهای تمام‌شده کالا",
            title=f"هزینه کالای فروخته‌شده — {item.name}",
            amount=sale.cost,
            source="cogs",
            reference=item.code,
        )
        db.add(cogs)
    else:
        cogs.txn_date = sale.sale_date
        cogs.amount = sale.cost
        cogs.category = "بهای تمام‌شده کالا"
        cogs.title = f"هزینه کالای فروخته‌شده — {item.name}"


@api_router.get("/settings/limits")
def business_limits(user: User = Depends(get_current_user)):
    return {
        "max_monthly_salary": settings.max_monthly_salary,
        "min_monthly_salary": settings.min_monthly_salary,
        "max_transaction_amount": settings.max_transaction_amount,
        "labels": {
            "max_monthly_salary": "سقف حقوق ماهانه",
            "min_monthly_salary": "حداقل حقوق ماهانه",
            "max_transaction_amount": "سقف مبلغ تراکنش",
        },
    }


@api_router.post("/auth/login", response_model=TokenResponse)
def login(payload: LoginRequest, db: Session = Depends(get_db)):
    user = authenticate_user(db, payload.phone, payload.normalized_password())
    if not user:
        raise HTTPException(status_code=401, detail="شماره موبایل یا رمز عبور نادرست است")
    token = create_access_token(user.phone)
    return TokenResponse(access_token=token, full_name=user.full_name, phone=user.phone)


@api_router.get("/me")
def me(user: User = Depends(get_current_user)):
    return {"phone": user.phone, "full_name": user.full_name}


@api_router.get("/dashboard", response_model=DashboardResponse)
def dashboard(
    period: str = Query("monthly", pattern="^(daily|monthly|yearly)$"),
    ref: Optional[date] = None,
    db: Session = Depends(get_db),
    user: User = Depends(get_current_user),
):
    return build_dashboard(db, period, ref)  # noqa: ARG001


@api_router.get("/reports/{period}", response_model=ReportPeriodResponse)
def reports(
    period: str,
    ref: Optional[date] = None,
    db: Session = Depends(get_db),
    user: User = Depends(get_current_user),
):
    if period not in {"daily", "monthly", "yearly"}:
        raise HTTPException(status_code=400, detail="بازه نامعتبر")
    return build_period_report(db, period, ref)  # noqa: ARG001


@api_router.get("/transactions", response_model=list[TransactionOut])
def list_transactions(
    direction: Optional[str] = None,
    limit: int = Query(100, ge=1, le=500),
    db: Session = Depends(get_db),
    user: User = Depends(get_current_user),
):
    q = db.query(Transaction).order_by(Transaction.txn_date.desc(), Transaction.id.desc())
    if direction in {"income", "expense"}:
        q = q.filter(Transaction.direction == direction)
    return [_tx_out(t) for t in q.limit(limit).all()]


@api_router.post("/transactions", response_model=TransactionOut)
def create_transaction(
    payload: TransactionCreate,
    db: Session = Depends(get_db),
    user: User = Depends(get_current_user),
):
    direction = validate_direction(payload.direction)
    category = validate_category(payload.category)
    title = validate_required_text(payload.title, "عنوان")
    amount = validate_amount(payload.amount)
    row = Transaction(
        txn_date=payload.txn_date,
        direction=direction,
        category=category,
        title=title,
        amount=amount,
        source=payload.source or "manual",
        reference=payload.reference,
        note=payload.note,
    )
    db.add(row)
    db.commit()
    db.refresh(row)
    return _tx_out(row)


@api_router.put("/transactions/{txn_id}", response_model=TransactionOut)
def update_transaction(
    txn_id: int,
    payload: TransactionUpdate,
    db: Session = Depends(get_db),
    user: User = Depends(get_current_user),
):
    row = db.get(Transaction, txn_id)
    if not row:
        raise HTTPException(status_code=404, detail="تراکنش یافت نشد")
    data = payload.model_dump(exclude_unset=True)
    if "direction" in data:
        data["direction"] = validate_direction(data["direction"])
    if "category" in data:
        data["category"] = validate_category(data["category"])
    if "title" in data:
        data["title"] = validate_required_text(data["title"], "عنوان")
    if "amount" in data:
        data["amount"] = validate_amount(data["amount"])
    for key, value in data.items():
        setattr(row, key, value)
    db.commit()
    db.refresh(row)
    return _tx_out(row)


@api_router.delete("/transactions/{txn_id}")
def delete_transaction(
    txn_id: int,
    db: Session = Depends(get_db),
    user: User = Depends(get_current_user),
):
    row = db.get(Transaction, txn_id)
    if not row:
        raise HTTPException(status_code=404, detail="تراکنش یافت نشد")
    db.delete(row)
    db.commit()
    return {"ok": True}


@api_router.get("/products", response_model=list[ProductServiceOut])
def list_products(
    active_only: bool = False,
    db: Session = Depends(get_db),
    user: User = Depends(get_current_user),
):
    q = db.query(ProductService).order_by(ProductService.code.asc())
    if active_only:
        q = q.filter(ProductService.is_active.is_(True))
    return [_product_out(p) for p in q.all()]


@api_router.post("/products", response_model=ProductServiceOut)
def create_product(
    payload: ProductServiceCreate,
    db: Session = Depends(get_db),
    user: User = Depends(get_current_user),
):
    code = validate_required_text(payload.code, "کد", max_len=30)
    name = validate_required_text(payload.name, "نام", max_len=150)
    kind = validate_kind(payload.kind)
    unit_price = validate_amount(payload.unit_price, field="قیمت فروش")
    unit_cost = validate_amount(payload.unit_cost, field="بهای تمام‌شده واحد")
    validate_unit_economics(unit_price, unit_cost)
    if db.query(ProductService).filter(ProductService.code == code).first():
        raise HTTPException(status_code=400, detail="کد محصول/خدمت تکراری است")
    row = ProductService(
        code=code,
        name=name,
        kind=kind,
        unit_price=unit_price,
        unit_cost=unit_cost,
        is_active=payload.is_active,
    )
    db.add(row)
    db.commit()
    db.refresh(row)
    return _product_out(row)


@api_router.put("/products/{item_id}", response_model=ProductServiceOut)
def update_product(
    item_id: int,
    payload: ProductServiceUpdate,
    db: Session = Depends(get_db),
    user: User = Depends(get_current_user),
):
    row = db.get(ProductService, item_id)
    if not row:
        raise HTTPException(status_code=404, detail="آیتم یافت نشد")
    data = payload.model_dump(exclude_unset=True)
    if "code" in data:
        data["code"] = validate_required_text(data["code"], "کد", max_len=30)
        if data["code"] != row.code:
            exists = db.query(ProductService).filter(ProductService.code == data["code"]).first()
            if exists:
                raise HTTPException(status_code=400, detail="کد محصول/خدمت تکراری است")
    if "name" in data:
        data["name"] = validate_required_text(data["name"], "نام", max_len=150)
    if "kind" in data:
        data["kind"] = validate_kind(data["kind"])
    if "unit_price" in data:
        data["unit_price"] = validate_amount(data["unit_price"], field="قیمت فروش")
    if "unit_cost" in data:
        data["unit_cost"] = validate_amount(data["unit_cost"], field="بهای تمام‌شده واحد")
    price = data.get("unit_price", row.unit_price)
    cost = data.get("unit_cost", row.unit_cost)
    validate_unit_economics(price, cost)
    for key, value in data.items():
        setattr(row, key, value)
    db.commit()
    db.refresh(row)
    return _product_out(row)


@api_router.delete("/products/{item_id}")
def delete_product(
    item_id: int,
    db: Session = Depends(get_db),
    user: User = Depends(get_current_user),
):
    row = db.get(ProductService, item_id)
    if not row:
        raise HTTPException(status_code=404, detail="آیتم یافت نشد")
    has_sales = db.query(Sale).filter(Sale.item_id == item_id).first()
    if has_sales:
        row.is_active = False
        db.commit()
        return {"ok": True, "soft_deleted": True, "message": "به‌خاطر سابقه فروش، غیرفعال شد"}
    db.delete(row)
    db.commit()
    return {"ok": True, "soft_deleted": False}


@api_router.get("/sales", response_model=list[SaleOut])
def list_sales(
    limit: int = Query(100, ge=1, le=500),
    db: Session = Depends(get_db),
    user: User = Depends(get_current_user),
):
    rows = db.query(Sale).order_by(Sale.sale_date.desc(), Sale.id.desc()).limit(limit).all()
    return [_sale_out(s) for s in rows]


@api_router.post("/sales", response_model=SaleOut)
def create_sale(
    payload: SaleCreate,
    db: Session = Depends(get_db),
    user: User = Depends(get_current_user),
):
    item = db.get(ProductService, payload.item_id)
    if not item or not item.is_active:
        raise HTTPException(status_code=404, detail="محصول/خدمت فعال یافت نشد")
    qty = validate_amount(payload.quantity, field="مقدار")
    if qty <= 0:
        raise HTTPException(status_code=400, detail="مقدار باید بزرگ‌تر از صفر باشد")
    unit_price = (
        validate_amount(payload.unit_price, field="قیمت فروش")
        if payload.unit_price is not None
        else item.unit_price
    )
    unit_cost = (
        validate_amount(payload.unit_cost, field="بهای تمام‌شده واحد")
        if payload.unit_cost is not None
        else item.unit_cost
    )
    validate_unit_economics(unit_price, unit_cost)
    revenue = round(qty * unit_price, 0)
    cost = round(qty * unit_cost, 0)
    validate_amount(revenue, field="درآمد فروش")
    sale = Sale(
        item_id=item.id,
        sale_date=payload.sale_date,
        quantity=qty,
        unit_price=unit_price,
        unit_cost=unit_cost,
        revenue=revenue,
        cost=cost,
        profit=revenue - cost,
        channel=payload.channel or "فروش مستقیم",
        note=payload.note,
    )
    db.add(sale)
    db.flush()
    if payload.sync_transactions:
        _sync_sale_transactions(db, sale, item)
    db.commit()
    db.refresh(sale)
    return _sale_out(sale)


@api_router.put("/sales/{sale_id}", response_model=SaleOut)
def update_sale(
    sale_id: int,
    payload: SaleUpdate,
    db: Session = Depends(get_db),
    user: User = Depends(get_current_user),
):
    sale = db.get(Sale, sale_id)
    if not sale:
        raise HTTPException(status_code=404, detail="فروش یافت نشد")
    data = payload.model_dump(exclude_unset=True)
    if "item_id" in data:
        item = db.get(ProductService, data["item_id"])
        if not item:
            raise HTTPException(status_code=404, detail="محصول/خدمت یافت نشد")
        sale.item_id = item.id
    for key in ("sale_date", "quantity", "unit_price", "unit_cost", "channel", "note"):
        if key in data:
            setattr(sale, key, data[key])
    sale.revenue = round(sale.quantity * sale.unit_price, 0)
    sale.cost = round(sale.quantity * sale.unit_cost, 0)
    sale.profit = sale.revenue - sale.cost
    db.commit()
    db.refresh(sale)
    return _sale_out(sale)


@api_router.delete("/sales/{sale_id}")
def delete_sale(
    sale_id: int,
    db: Session = Depends(get_db),
    user: User = Depends(get_current_user),
):
    sale = db.get(Sale, sale_id)
    if not sale:
        raise HTTPException(status_code=404, detail="فروش یافت نشد")
    db.delete(sale)
    db.commit()
    return {"ok": True}


@api_router.get("/employees", response_model=list[EmployeeOut])
def list_employees(db: Session = Depends(get_db), user: User = Depends(get_current_user)):
    rows = db.query(Employee).order_by(Employee.code.asc()).all()
    return [
        EmployeeOut(
            id=e.id,
            code=e.code,
            full_name=e.full_name,
            role=e.role,
            department=e.department.name if e.department else "",
            monthly_salary=e.monthly_salary,
            hire_date=e.hire_date,
            is_active=e.is_active,
        )
        for e in rows
    ]


@api_router.put("/employees/{emp_id}", response_model=EmployeeOut)
def update_employee(
    emp_id: int,
    payload: EmployeeUpdate,
    db: Session = Depends(get_db),
    user: User = Depends(get_current_user),
):
    emp = db.get(Employee, emp_id)
    if not emp:
        raise HTTPException(status_code=404, detail="پرسنل یافت نشد")
    data = payload.model_dump(exclude_unset=True)
    if "full_name" in data:
        data["full_name"] = validate_required_text(data["full_name"], "نام", max_len=120)
    if "role" in data:
        data["role"] = validate_required_text(data["role"], "سمت", max_len=100)
    if "monthly_salary" in data:
        data["monthly_salary"] = validate_salary(data["monthly_salary"])
    for key, value in data.items():
        setattr(emp, key, value)
    db.commit()
    db.refresh(emp)
    return EmployeeOut(
        id=emp.id,
        code=emp.code,
        full_name=emp.full_name,
        role=emp.role,
        department=emp.department.name if emp.department else "",
        monthly_salary=emp.monthly_salary,
        hire_date=emp.hire_date,
        is_active=emp.is_active,
    )


@api_router.get("/excel/template")
def excel_template(user: User = Depends(get_current_user)):
    stream = build_template_workbook()
    return StreamingResponse(
        stream,
        media_type="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
        headers={"Content-Disposition": "attachment; filename=finlytics_transactions_template.xlsx"},
    )


@api_router.post("/excel/import", response_model=ImportResult)
async def excel_import(
    file: UploadFile = File(...),
    db: Session = Depends(get_db),
    user: User = Depends(get_current_user),
):
    if not file.filename or not file.filename.lower().endswith((".xlsx", ".xlsm")):
        raise HTTPException(status_code=400, detail="فقط فایل اکسل .xlsx پذیرفته می‌شود")
    dest = settings.upload_dir / f"import_{file.filename}"
    content = await file.read()
    dest.write_bytes(content)
    return import_transactions_excel(db, dest)


@api_router.post("/voice/daily", response_model=VoiceReportOut)
async def voice_daily(
    ref: Optional[date] = None,
    force: bool = True,
    db: Session = Depends(get_db),
    user: User = Depends(get_current_user),
):
    report = await generate_daily_voice_report(db, ref, force=force)
    audio_url = f"/api/voice/audio/{report.id}" if report.audio_path else None
    is_sample = not bool(audio_url)
    sample_note = (
        "توجه: تولید زنده ویس در دسترس نبود؛ این یک فایل صوتی نمونه تولیدشده توسط هوش مصنوعی است."
        if is_sample
        else None
    )
    return VoiceReportOut(
        id=report.id,
        report_date=report.report_date,
        script_text=report.script_text,
        audio_url=audio_url,
        sample_audio_url="/static/audio/ceo_voice_sample.mp3",
        is_sample=is_sample,
        sample_note=sample_note,
        duration_hint=report.duration_hint,
        generation_mode="sample-ai-demo" if is_sample else report.generation_mode,
        created_at=report.created_at,
    )


@api_router.get("/voice/audio/{report_id}")
def voice_audio(report_id: int, db: Session = Depends(get_db), user: User = Depends(get_current_user)):
    report = db.get(VoiceReport, report_id)
    if not report or not report.audio_path or not Path(report.audio_path).exists():
        raise HTTPException(status_code=404, detail="فایل صوتی یافت نشد")
    return FileResponse(report.audio_path, media_type="audio/mpeg", filename=f"ceo_report_{report_id}.mp3")


@api_router.get("/ai/quota", response_model=AiQuotaOut)
def ai_quota(db: Session = Depends(get_db), user: User = Depends(get_current_user)):
    data = get_quota(db, user)
    return AiQuotaOut(**data)


@api_router.get("/ai/history")
def ai_history(db: Session = Depends(get_db), user: User = Depends(get_current_user)):
    rows = list_recent_questions(db, user.id, limit=10)
    return [
        {
            "id": r.id,
            "question": r.question,
            "answer": r.answer,
            "mode": r.mode,
            "created_at": r.created_at.isoformat(),
        }
        for r in rows
    ]


@api_router.post("/ai/ask", response_model=AiAskResponse)
async def ai_ask(
    payload: AiAskRequest,
    db: Session = Depends(get_db),
    user: User = Depends(get_current_user),
):
    row = await ask_finance_question(db, user, payload.question)
    quota = get_quota(db, user)
    return AiAskResponse(
        id=row.id,
        question=row.question,
        answer=row.answer,
        mode=row.mode,
        remaining=quota["remaining"],
        limit=quota["limit"],
        created_at=row.created_at,
    )
