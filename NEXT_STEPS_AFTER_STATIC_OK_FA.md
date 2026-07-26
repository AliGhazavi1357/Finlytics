# مرحله بعد بعد از موفقیت status.html

تست استاتیک شما موفق بود. از اینجا فقط Python باید فعال شود.

## ۱) پیام آماده برای پشتیبانی هاست (کپی کنید)

> موضوع: فعال‌سازی Python برای دامنه finlytics.nesfejahan.com
>
> تست استاتیک دامنه موفق است (status.html باز می‌شود).
> برای اجرای اپلیکیشن FastAPI نیاز داریم:
>
> 1) نصب/فعال‌سازی ماژول IIS به‌نام HttpPlatformHandler
> 2) دسترسی به Python 3.11 یا 3.12 روی همین سرور
> 3) اعلام Document root دقیق دامنه finlytics.nesfejahan.com
> 4) باز بودن امکان استفاده از section مربوط به httpPlatform در web.config
>
> لطفاً تأیید کنید این موارد برای این دامنه فعال است.

بدون تأیید مورد ۱ و ۲، برنامه Finlytics روی این هاست اشتراکی بالا نمی‌آید.

---

## ۲) وقتی پشتیبانی تأیید کرد (روی سرور)

در مسیر Document root دامنه:

```powershell
powershell -ExecutionPolicy Bypass -File .\setup_plesk.ps1
powershell -ExecutionPolicy Bypass -File .\diagnose_plesk.ps1
```

باید در خروجی ببینید: `APP_OK Finlytics`

سپس در File Manager:

1. `web.config` فعلی را Rename کنید به `web.config.static`
2. `web.config.python` را Rename کنید به `web.config`
3. اگر لازم شد داخل `web.config` مسیر کامل python را بگذارید:

```xml
processPath="C:\Inetpub\vhosts\nesfejahan.com\finlytics.nesfejahan.com\python_env\Scripts\python.exe"
```

(مسیر را با Document root واقعی خودتان عوض کنید)

4. App Pool را Recycle کنید
5. سایت را باز کنید: http://finlytics.nesfejahan.com/

ورود آزمایشی:
- موبایل: `09131176583`
- رمز: `123456789`

---

## ۳) SSL (اختیاری ولی توصیه‌شده)

در Plesk برای دامنه، Let’s Encrypt بگیرید تا پیام Not secure رفع شود.

---

## اگر پشتیبانی گفت Python پشتیبانی نمی‌شود

روی این هاست اشتراکی نمی‌توانید Finlytics را اجرا کنید.
گزینه‌ها:
- ارتقا به VPS ویندوزی/لینوکسی
- هاستی که Python + HttpPlatform را رسمی پشتیبانی کند
- اجرای موقت روی سیستم خودتان: http://127.0.0.1:8000
