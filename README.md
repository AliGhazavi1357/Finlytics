# Finlytics

سامانه تحلیل و گزارش امور مالی (نسخه آزمایشی) با داشبورد، نمودار، گزارش روزانه/ماهانه/سالانه، ورود اکسل، و ویس گزارش فارسی برای مدیرعامل.

## اجرای محلی (Windows / PowerShell)

```powershell
cd "d:\Python Projects\Projects One\Finlytics"
python -m venv .venv
.\.venv\Scripts\Activate.ps1
pip install -r requirements.txt
copy .env.example .env
python run.py
```

سپس مرورگر را باز کنید: `http://127.0.0.1:8000`

### کاربر آزمایشی

- موبایل: `09131176583`
- رمز: `123456789`

### کلید اختیاری OpenAI

اگر `OPENAI_API_KEY` در `.env` تنظیم شود، متن ویس گزارش با مدل مشخص‌شده روان‌سازی می‌شود. بدون کلید، متن قالب‌محور فارسی تولید و با `edge-tts` (صدای `fa-IR-DilaraNeural`) به MP3 تبدیل می‌شود.

## قابلیت‌ها

- داده نمونه خودکار: پرسنل، حقوق، فروش محصول/خدمت، هزینه عملیاتی
- داشبورد و نمودارهای درآمد/هزینه/سود
- گزارش روزانه، ماهانه، سالانه
- لیست ورودی و خروجی
- دانلود قالب اکسل و بارگذاری تراکنش‌ها
- تولید ویس گزارش روزانه فارسی (~۱ دقیقه)
- رابط RTL و نسخه موبایل

## استقرار آزمایشی روی Plesk ویندوزی (IIS / HttpPlatformHandler)

### پیش‌نیازها

1. دامنه یا ساب‌دامین روی Plesk Windows
2. Python 3.11 یا 3.12 روی سرور
3. نصب `HttpPlatformHandler` روی IIS (اگر موجود نیست)

### مراحل

1. کل پروژه را در `httpdocs` (یا پوشه دامنه) آپلود کنید.
2. روی سرور یک venv بسازید:

```powershell
cd C:\Inetpub\vhosts\YOUR_DOMAIN\httpdocs
python -m venv python_env
.\python_env\Scripts\python.exe -m pip install -r requirements.txt
copy .env.example .env
```

3. در `.env` مقدار `SECRET_KEY` را عوض کنید.
4. مطمئن شوید `web.config` مسیر Python را درست اشاره می‌کند:

```xml
processPath="%HOME%\python_env\Scripts\python.exe"
arguments="-m uvicorn app.main:app --host 127.0.0.1 --port %HTTP_PLATFORM_PORT%"
```

اگر venv را خارج از `%HOME%` ساخته‌اید، مسیر را اصلاح کنید.

5. پوشه‌های قابل نوشتن:

- `data\`
- `data\voice\`
- `data\uploads\`
- `logs\`

به کاربر Application Pool اجازه Write بدهید.

6. در Plesk، اگر لازم است، Python App را روی همین پوشه تنظیم کنید یا فقط به `web.config` اتکا کنید.

7. سایت را باز کنید و با کاربر آزمایشی وارد شوید.

### نکته مهم درباره TTS روی سرور

`edge-tts` برای ساخت صدا به اینترنت خروجی نیاز دارد. اگر فایروال سرور خروجی را محدود کرده، ویس ساخته نمی‌شود؛ متن گزارش همچنان تولید می‌شود.

### حالت جایگزین بدون HttpPlatformHandler

اگر هاست فقط WSGI می‌پذیرد:

```powershell
.\python_env\Scripts\python.exe -m pip install a2wsgi
```

و `passenger_wsgi.py` را به‌عنوان entrypoint معرفی کنید (`application`).

## API Docs

پس از اجرا: `http://127.0.0.1:8000/api/docs`
