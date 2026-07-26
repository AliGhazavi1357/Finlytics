# رفع فوری 500 برای finlytics.nesfejahan.com

الان روی هاست اشتراکی ویندوزی، خیلی وقت‌ها خودِ تنظیمات پایتون (`HttpPlatformHandler`) باعث 500 می‌شود.
حتی قبل از اجرای برنامه.

## کار فوری (۵ دقیقه) — اول این را تست کنید

در File Manager پلسک، داخل ریشه سایت:

1. فایل فعلی `web.config` را Rename کنید به `web.config.broken`
2. فایل `web.config.static-test` را Rename/Copy کنید به `web.config`
3. مطمئن شوید `status.html` هم در همان پوشه هست
4. سایت را دوباره باز کنید: http://finlytics.nesfejahan.com/

### نتیجه الف) صفحه سبز «تست استاتیک هاست موفق بود» آمد
یعنی دامنه سالم است. مشکل از پایتون/HttpPlatform است.

بعدش:
1. از پشتیبانی بخواهید این پیام را بفرستید (پایین)
2. وقتی تأیید کردند، `web.config.python` را به‌جای `web.config` بگذارید
3. روی سرور `setup_plesk.ps1` را اجرا کنید تا `python_env` ساخته شود

### نتیجه ب) هنوز 500 است
یعنی حتی فایل استاتیک هم لود نمی‌شود:
- مسیر Document root اشتباه است، یا
- دسترسی فایل‌ها مشکل دارد، یا
- یک `web.config` بالاتر قفل است

در این حالت اسکرین از File Manager ریشه سایت (لیست فایل‌ها) بفرستید.

---

## پیام آماده برای پشتیبانی هاست

> دامنه: finlytics.nesfejahan.com  
> خطای فعلی: IIS 500 Internal Server Error  
> لطفاً بررسی کنید:
> 1) آیا ماژول HttpPlatformHandler روی این سرور نصب و برای دامنه مجاز است؟
> 2) آیا Python 3.11 یا 3.12 برای این دامنه قابل استفاده است؟
> 3) Document root دقیق دامنه چیست؟
> 4) آیا section مربوط به httpPlatform در applicationHost.config قفل نیست؟
>
> هدف ما اجرای FastAPI با uvicorn از مسیر:
> `python_env\Scripts\python.exe -m uvicorn app.main:app`

---

## اگر پشتیبانی Python را فعال کرد

روی سرور در Document root:

```powershell
powershell -ExecutionPolicy Bypass -File .\setup_plesk.ps1
powershell -ExecutionPolicy Bypass -File .\diagnose_plesk.ps1
```

سپس:

1. `web.config.python` → نامش را بگذارید `web.config`
2. اگر لازم شد مسیر کامل python را داخلش بگذارید
3. App Pool را Recycle کنید

---

## نکته مهم

روی بعضی هاست‌های اشتراکی ویندوزی، اجرای Python اصلاً پشتیبانی نمی‌شود.
در آن صورت باید:
- هاست Python/VPS بگیرید، یا
- از پشتیبانی بخواهید HttpPlatform + Python را فعال کنند.

تا وقتی `python_env` ساخته نشود و HttpPlatform فعال نباشد، برنامه Finlytics روی این دامنه بالا نمی‌آید.
