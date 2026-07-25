from datetime import date, datetime
from typing import Optional

from sqlalchemy import Boolean, Date, DateTime, Float, ForeignKey, Integer, String, Text
from sqlalchemy.orm import Mapped, mapped_column, relationship

from app.database import Base


class User(Base):
    __tablename__ = "users"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    phone: Mapped[str] = mapped_column(String(20), unique=True, index=True)
    password_hash: Mapped[str] = mapped_column(String(255))
    full_name: Mapped[str] = mapped_column(String(120), default="مدیر سیستم")
    is_active: Mapped[bool] = mapped_column(Boolean, default=True)
    created_at: Mapped[datetime] = mapped_column(DateTime, default=datetime.utcnow)


class Department(Base):
    __tablename__ = "departments"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    name: Mapped[str] = mapped_column(String(100), unique=True)
    employees: Mapped[list["Employee"]] = relationship(back_populates="department")


class Employee(Base):
    __tablename__ = "employees"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    code: Mapped[str] = mapped_column(String(20), unique=True)
    full_name: Mapped[str] = mapped_column(String(120))
    role: Mapped[str] = mapped_column(String(100))
    department_id: Mapped[int] = mapped_column(ForeignKey("departments.id"))
    monthly_salary: Mapped[float] = mapped_column(Float)
    hire_date: Mapped[date] = mapped_column(Date)
    is_active: Mapped[bool] = mapped_column(Boolean, default=True)

    department: Mapped["Department"] = relationship(back_populates="employees")
    payrolls: Mapped[list["Payroll"]] = relationship(back_populates="employee")


class Payroll(Base):
    __tablename__ = "payrolls"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    employee_id: Mapped[int] = mapped_column(ForeignKey("employees.id"))
    period_year: Mapped[int] = mapped_column(Integer)
    period_month: Mapped[int] = mapped_column(Integer)
    base_salary: Mapped[float] = mapped_column(Float)
    bonus: Mapped[float] = mapped_column(Float, default=0)
    deductions: Mapped[float] = mapped_column(Float, default=0)
    net_pay: Mapped[float] = mapped_column(Float)
    paid_on: Mapped[Optional[date]] = mapped_column(Date, nullable=True)

    employee: Mapped["Employee"] = relationship(back_populates="payrolls")


class ProductService(Base):
    __tablename__ = "products_services"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    code: Mapped[str] = mapped_column(String(30), unique=True)
    name: Mapped[str] = mapped_column(String(150))
    kind: Mapped[str] = mapped_column(String(20))  # product | service
    unit_price: Mapped[float] = mapped_column(Float)
    unit_cost: Mapped[float] = mapped_column(Float)
    is_active: Mapped[bool] = mapped_column(Boolean, default=True)

    sales: Mapped[list["Sale"]] = relationship(back_populates="item")


class Sale(Base):
    __tablename__ = "sales"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    item_id: Mapped[int] = mapped_column(ForeignKey("products_services.id"))
    sale_date: Mapped[date] = mapped_column(Date, index=True)
    quantity: Mapped[float] = mapped_column(Float)
    unit_price: Mapped[float] = mapped_column(Float)
    unit_cost: Mapped[float] = mapped_column(Float)
    revenue: Mapped[float] = mapped_column(Float)
    cost: Mapped[float] = mapped_column(Float)
    profit: Mapped[float] = mapped_column(Float)
    channel: Mapped[str] = mapped_column(String(50), default="فروش مستقیم")
    note: Mapped[Optional[str]] = mapped_column(String(255), nullable=True)

    item: Mapped["ProductService"] = relationship(back_populates="sales")


class Transaction(Base):
    __tablename__ = "transactions"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    txn_date: Mapped[date] = mapped_column(Date, index=True)
    direction: Mapped[str] = mapped_column(String(10))  # income | expense
    category: Mapped[str] = mapped_column(String(80))
    title: Mapped[str] = mapped_column(String(200))
    amount: Mapped[float] = mapped_column(Float)
    source: Mapped[str] = mapped_column(String(50), default="manual")
    reference: Mapped[Optional[str]] = mapped_column(String(100), nullable=True)
    note: Mapped[Optional[str]] = mapped_column(Text, nullable=True)
    created_at: Mapped[datetime] = mapped_column(DateTime, default=datetime.utcnow)


class VoiceReport(Base):
    __tablename__ = "voice_reports"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    report_date: Mapped[date] = mapped_column(Date, index=True)
    script_text: Mapped[str] = mapped_column(Text)
    audio_path: Mapped[Optional[str]] = mapped_column(String(255), nullable=True)
    duration_hint: Mapped[str] = mapped_column(String(20), default="~60s")
    created_at: Mapped[datetime] = mapped_column(DateTime, default=datetime.utcnow)
    generation_mode: Mapped[str] = mapped_column(String(30), default="template")


class AiQuestion(Base):
    __tablename__ = "ai_questions"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    user_id: Mapped[int] = mapped_column(ForeignKey("users.id"), index=True)
    question: Mapped[str] = mapped_column(Text)
    answer: Mapped[str] = mapped_column(Text)
    mode: Mapped[str] = mapped_column(String(30), default="rules")
    created_at: Mapped[datetime] = mapped_column(DateTime, default=datetime.utcnow, index=True)
