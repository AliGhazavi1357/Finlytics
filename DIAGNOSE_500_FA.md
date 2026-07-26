# راهنمای رفع خطای 500 روی Plesk ویندوزی — Finlytics
# دامنه: http://finlytics.nesfejahan.com/

خطای «500 - Internal server error» از IIS است و علت دقیق را نشان نمی‌دهد.
این مراحل را **به ترتیب** انجام دهید.

---

## مرحله ۱ — بررسی فایل‌های آپلودشده

در File Manager پلسک، داخل ریشه دامنه `finlytics.nesfejahan.com` باید این‌ها باشد:

```
app/
static/
templates/
data/
logs/
python_env/          ← خیلی مهم (اگر نیست، علت ۵۰۰ همین است)
web.config
requirements.txt
.env
setup_plesk.ps1
```

اگر `python_env` ندارید، برنامه هنوز نصب نشده.

---

## مرحله ۲ — ساخت venv روی سرور (الزامی)

با RDP یا Terminal پلسک وارد پوشه سایت شوید، مثلاً:

```powershell
cd C:\Inetpub\vhosts\nesfejahan.com\finlytics.nesfejahan.com
dir
```

اگر مسیر فرق دارد، از Plesk → Hosting Settings → Document root مسیر را بردارید.

سپس:

```powershell
powershell -ExecutionPolicy Bypass -File .\setup_plesk.ps1
```

یا دستی:

```powershell
python -m venv python_env
.\python_env\Scripts\python.exe -m pip install --upgrade pip
.\python_env\Scripts\python.exe -m pip install -r requirements.txt
copy .env.example .env
.\python_env\Scripts\python.exe -c "from app.main import app; print(app.title)"
```

اگر آخرین دستور `Finlytics` چاپ کرد، کد سالم است.

---

## مرحله ۳ — دسترسی نوشتن

روی این پوشه‌ها برای Application Pool دامنه، دسترسی Modify بدهید:

- `data`
- `data\voice`
- `data\uploads`
- `logs`

---

## مرحله ۴ — خواندن لاگ

بعد از یک بار باز کردن سایت، این فایل را باز کنید:

`logs\stdout.log`

- اگر خطا درباره `No module named ...` است → دوباره `pip install -r requirements.txt`
- اگر `can't open file` / مسیر python است → مسیر `processPath` در `web.config` را کامل کنید
- اگر فایل اصلاً ساخته نشده → معمولاً HttpPlatformHandler نصب نیست یا `web.config` قفل است

---

## مرحله ۵ — اصلاح مسیر Python در web.config

اگر مسیر نسبی کار نکرد، در `web.config` مقدار `processPath` را به مسیر کامل تغییر دهید، مثلاً:

```xml
processPath="C:\Inetpub\vhosts\nesfejahan.com\finlytics.nesfejahan.com\python_env\Scripts\python.exe"
```

سپس در Plesk: Recycle App Pool / Restart website

---

## مرحله ۶ — تست بدون IIS

روی سرور:

```powershell
cd C:\Inetpub\vhosts\nesfejahan.com\finlytics.nesfejahan.com
.\python_env\Scripts\python.exe -m uvicorn app.main:app --host 127.0.0.1 --port 8088
```

اگر `http://127.0.0.1:8088` روی خود سرور باز شد، مشکل از IIS/HttpPlatform است نه کد.

---

## علت‌های رایج روی هاست اشتراکی

| علت | نشانه | راه حل |
|-----|--------|--------|
| نبود `python_env` | ۵۰۰ فوری | اجرای `setup_plesk.ps1` |
| نبود HttpPlatformHandler | ۵۰۰ و لاگ خالی | از پشتیبانی بخواهید ماژول را نصب کند |
| مسیر غلط python | لاگ: cannot find python | مسیر کامل در web.config |
| نبود دسترسی Write | خطا هنگام ساخت DB | دسترسی Modify روی data/logs |
| Python روی هاست نیست | venv ساخته نمی‌شود | درخواست فعال‌سازی Python 3.11+ از پشتیبانی |

---

## از پشتیبانی هاست این را بپرسید

> روی دامنه finlytics.nesfejahan.com خطای ۵۰۰ IIS داریم.
> لطفاً تأیید کنید:
> 1) HttpPlatformHandler روی IIS نصب است؟
> 2) Python 3.11/3.12 در دسترس است؟
> 3) مسیر Document root دامنه چیست؟
> 4) آیا قفل configuration برای httpPlatform برداشته شده؟

---

## بعد از رفع مشکل

در `web.config` این خط را برگردانید تا جزئیات خطا عمومی نباشد:

```xml
<httpErrors errorMode="DetailedLocalOnly" existingResponse="PassThrough" />
```
