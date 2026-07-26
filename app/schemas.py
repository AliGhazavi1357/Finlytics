from datetime import date, datetime
from typing import Annotated, Any, Optional

from pydantic import BaseModel, BeforeValidator, Field

from app.numbers import normalize_number_text, to_en_digits


def _coerce_number(value: Any) -> float:
    if isinstance(value, bool):
        raise ValueError("عدد نامعتبر")
    if isinstance(value, (int, float)):
        return float(value)
    text = normalize_number_text(value)
    if text == "":
        raise ValueError("عدد الزامی است")
    return float(text)


def _coerce_phone(value: Any) -> str:
    return to_en_digits(str(value or "")).strip().replace(" ", "").replace("-", "")


FlexibleNumber = Annotated[float, BeforeValidator(_coerce_number)]
PhoneStr = Annotated[str, BeforeValidator(_coerce_phone)]


class TokenResponse(BaseModel):
    access_token: str
    token_type: str = "bearer"
    full_name: str
    phone: str


class LoginRequest(BaseModel):
    phone: PhoneStr
    password: str

    def normalized_password(self) -> str:
        return to_en_digits(self.password)


class SummaryCard(BaseModel):
    label: str
    value: float
    change_pct: Optional[float] = None
    tone: str = "neutral"


class ChartPoint(BaseModel):
    label: str
    income: float = 0
    expense: float = 0
    profit: float = 0


class CategorySlice(BaseModel):
    category: str
    amount: float


class TomorrowForecast(BaseModel):
    """Period-aware forecast (daily/monthly/yearly). Name kept for API compatibility."""

    period_type: str = "daily"
    target_label: str = "فردا"
    forecast_date: date
    forecast_start: Optional[date] = None
    forecast_end: Optional[date] = None
    predicted_income: float
    predicted_expense: float
    predicted_profit: float
    confidence_note: str
    method: str
    narrative: str


class DashboardResponse(BaseModel):
    period: str
    cards: list[SummaryCard]
    trend: list[ChartPoint]
    expense_breakdown: list[CategorySlice]
    income_breakdown: list[CategorySlice]
    top_products: list[dict]
    recent_transactions: list[dict]
    tomorrow: Optional[TomorrowForecast] = None
    forecast: Optional[TomorrowForecast] = None


class TransactionCreate(BaseModel):
    txn_date: date
    direction: str = Field(pattern="^(income|expense)$")
    category: str = Field(min_length=1, max_length=80)
    title: str = Field(min_length=1, max_length=200)
    amount: FlexibleNumber = Field(gt=0)
    source: str = "manual"
    reference: Optional[str] = None
    note: Optional[str] = None


class TransactionUpdate(BaseModel):
    txn_date: Optional[date] = None
    direction: Optional[str] = Field(default=None, pattern="^(income|expense)$")
    category: Optional[str] = Field(default=None, min_length=1, max_length=80)
    title: Optional[str] = Field(default=None, min_length=1, max_length=200)
    amount: Optional[FlexibleNumber] = Field(default=None, gt=0)
    source: Optional[str] = None
    reference: Optional[str] = None
    note: Optional[str] = None


class TransactionOut(BaseModel):
    id: int
    txn_date: date
    direction: str
    category: str
    title: str
    amount: float
    source: str
    source_label: str = ""
    reference: Optional[str] = None
    note: Optional[str] = None

    class Config:
        from_attributes = True


class ProductServiceCreate(BaseModel):
    code: str = Field(min_length=1, max_length=30)
    name: str = Field(min_length=1, max_length=150)
    kind: str = Field(pattern="^(product|service)$")
    unit_price: FlexibleNumber = Field(ge=0)
    unit_cost: FlexibleNumber = Field(ge=0)
    is_active: bool = True


class ProductServiceUpdate(BaseModel):
    code: Optional[str] = Field(default=None, min_length=1, max_length=30)
    name: Optional[str] = Field(default=None, min_length=1, max_length=150)
    kind: Optional[str] = Field(default=None, pattern="^(product|service)$")
    unit_price: Optional[FlexibleNumber] = Field(default=None, ge=0)
    unit_cost: Optional[FlexibleNumber] = Field(default=None, ge=0)
    is_active: Optional[bool] = None


class ProductServiceOut(BaseModel):
    id: int
    code: str
    name: str
    kind: str
    kind_label: str = ""
    unit_price: float
    unit_cost: float
    is_active: bool

    class Config:
        from_attributes = True


class SaleCreate(BaseModel):
    item_id: int
    sale_date: date
    quantity: FlexibleNumber = Field(gt=0)
    unit_price: Optional[FlexibleNumber] = Field(default=None, ge=0)
    unit_cost: Optional[FlexibleNumber] = Field(default=None, ge=0)
    channel: str = "فروش مستقیم"
    note: Optional[str] = None
    sync_transactions: bool = True


class SaleUpdate(BaseModel):
    item_id: Optional[int] = None
    sale_date: Optional[date] = None
    quantity: Optional[FlexibleNumber] = Field(default=None, gt=0)
    unit_price: Optional[FlexibleNumber] = Field(default=None, ge=0)
    unit_cost: Optional[FlexibleNumber] = Field(default=None, ge=0)
    channel: Optional[str] = None
    note: Optional[str] = None


class SaleOut(BaseModel):
    id: int
    item_id: int
    item_name: str = ""
    sale_date: date
    quantity: float
    unit_price: float
    unit_cost: float
    revenue: float
    cost: float
    profit: float
    channel: str
    note: Optional[str] = None


class EmployeeOut(BaseModel):
    id: int
    code: str
    full_name: str
    role: str
    department: str
    monthly_salary: float
    hire_date: date
    is_active: bool


class EmployeeUpdate(BaseModel):
    full_name: Optional[str] = None
    role: Optional[str] = None
    monthly_salary: Optional[FlexibleNumber] = None
    is_active: Optional[bool] = None


class ReportPeriodResponse(BaseModel):
    period_type: str
    label: str
    start_date: date
    end_date: date
    total_income: float
    total_expense: float
    net_profit: float
    sales_revenue: float
    sales_profit: float
    payroll_cost: float
    cogs_cost: float = 0
    opex_cost: float = 0
    margin_pct: float
    daily_avg_income: float
    daily_avg_expense: float
    narrative: str
    trend: list[ChartPoint]
    tomorrow: Optional[TomorrowForecast] = None
    forecast: Optional[TomorrowForecast] = None


class VoiceReportOut(BaseModel):
    id: int
    report_date: date
    script_text: str
    audio_url: Optional[str] = None
    sample_audio_url: Optional[str] = "/static/audio/ceo_voice_sample.mp3"
    is_sample: bool = False
    sample_note: Optional[str] = None
    duration_hint: str
    generation_mode: str
    created_at: datetime
    speakable_text: Optional[str] = None
    voice_hint: Optional[str] = None


class ImportResult(BaseModel):
    imported: int
    skipped: int
    errors: list[str] = Field(default_factory=list)


class AiAskRequest(BaseModel):
    question: str = Field(min_length=3, max_length=500)


class AiAskResponse(BaseModel):
    id: int
    question: str
    answer: str
    mode: str
    remaining: int
    limit: int
    created_at: datetime


class AiQuotaOut(BaseModel):
    used: int
    remaining: int
    limit: int
    suggestions: list[str]
