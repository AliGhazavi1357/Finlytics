# راهنمای استقرار Finlytics روی هاست اشتراکی ویندوزی Plesk

تاریخ بسته: نسخه آزمایشی برای آپلود در `httpdocs`

## محتویات ZIP

- کد برنامه (`app`, `static`, `templates`)
- `web.config` برای IIS / HttpPlatformHandler
- `requirements.txt`
- `.env.example` (بعد از آپلود کپی به `.env`)
- `setup_plesk.ps1` اسکریپت آماده‌سازی روی سرور
- پوشه‌های خالی `data` و `logs`

**داخل ZIP نیست:** `.venv`، دیتابیس محلی، فایل‌های صوتی، `.env` واقعی

---

## پیش‌نیاز روی هاست

1. دامنه/ساب‌دامین روی **Plesk Windows**
2. دسترسی به **Python 3.11 یا 3.12** (در Plesk یا نصب سیستمی)
3. ماژول IIS به‌نام **HttpPlatformHandler** (معمولاً روی Plesk ویندوزی هست)
4. دسترسی RDP یا File Manager + امکان اجرای PowerShell/CMD روی سرور

اگر هاست فقط PHP دارد و Python پشتیبانی نمی‌کند، این بسته روی آن اجرا نمی‌شود.

---

## مراحل آپلود (خلاصه)

### ۱) آپلود فایل‌ها

1. محتویات ZIP را در ریشه سایت استخراج کنید؛ معمولاً:

`C:\Inetpub\vhosts\YOUR_DOMAIN\httpdocs\`

2. ساختار باید شبیه این باشد:

```
httpdocs\
  app\
  static\
  templates\
  data\
  logs\
  web.config
  requirements.txt
  .env.example
  setup_plesk.ps1
  passenger_wsgi.py
  run.py
  README.md
```

### ۲) ساخت محیط مجازی و نصب پکیج‌ها

در RDP یا Terminal سرور (PowerShell):

```powershell
cd C:\Inetpub\vhosts\YOUR_DOMAIN\httpdocs
powershell -ExecutionPolicy Bypass -File .\setup_plesk.ps1
```

یا دستی:

```powershell
cd C:\Inetpub\vhosts\YOUR_DOMAIN\httpdocs
py -3.12 -m venv python_env
.\python_env\Scripts\python.exe -m pip install --upgrade pip
.\python_env\Scripts\python.exe -m pip install -r requirements.txt
Copy-Item .env.example .env -Force
```

اگر `py` نبود:

```powershell
python -m venv python_env
```

### ۳) تنظیم `.env`

فایل `.env` را ویرایش کنید:

```
SECRET_KEY=یک-رشته-تصادفی-بلند
DEBUG=false
OPENAI_API_KEY=
AI_DAILY_QUESTION_LIMIT=5
```

### ۴) دسترسی نوشتن پوشه‌ها

به Application Pool سایت، دسترسی **Modify/Write** بدهید روی:

- `data`
- `data\voice`
- `data\uploads`
- `logs`

### ۵) بررسی `web.config`

پیش‌فرض:

```xml
processPath=".\python_env\Scripts\python.exe"
```

اگر venv جای دیگری است، مسیر کامل بگذارید، مثلاً:

```xml
processPath="C:\Inetpub\vhosts\YOUR_DOMAIN\httpdocs\python_env\Scripts\python.exe"
```

### ۶) ری‌استارت سایت

در Plesk: Recycle App Pool یا Restart Web Site

سپس دامنه را باز کنید و با کاربر آزمایشی وارد شوید:

- موبایل: `09131176583`
- رمز: `123456789`

---

## عیب‌یابی سریع

| مشکل | کار |
|------|-----|
| خطای ۵۰۰ | فایل `logs\stdout.log` را بخوانید |
| Python پیدا نشد | مسیر `processPath` در `web.config` را درست کنید |
| DB ساخته نمی‌شود | دسترسی Write روی `data` |
| ویس ساخته نمی‌شود | اینترنت خروجی سرور برای edge-tts/gTTS |
| پکیج نصب نشد | Python 64-bit و نسخه ۳.۱۱+ |

تست دستی روی سرور:

```powershell
cd C:\Inetpub\vhosts\YOUR_DOMAIN\httpdocs
.\python_env\Scripts\python.exe -c "from app.main import app; print(app.title)"
.\python_env\Scripts\python.exe -m uvicorn app.main:app --host 127.0.0.1 --port 8088
```

اگر روی پورت ۸۰۸۸ بالا آمد، مشکل از IIS/HttpPlatformHandler است نه از کد.

---

## نکته امنیتی

بعد از استقرار آزمایشی:

1. رمز کاربر را عوض کنید (فعلاً فقط یک کاربر seed شده)
2. `SECRET_KEY` را حتماً تغییر دهید
3. `DEBUG=false` بگذارید
4. `.env` را عمومی نکنید
