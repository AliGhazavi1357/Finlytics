Finlytics — استقرار روی هاست اشتراکی Plesk (مثل Attendance)
============================================================
نسخه: 1.0.0-php

این بسته با PHP + SQLite کار می‌کند. نیازی به Python / HttpPlatformHandler نیست.

1) محتویات ZIP را در Document Root دامنه (مثلاً finlytics.nesfejahan.com) استخراج کنید
2) تست‌ها:
   - https://YOUR-DOMAIN/test.php
   - https://YOUR-DOMAIN/api/index.php?path=version
3) سایت را باز کنید و Ctrl+F5 بزنید
4) ورود دمو:
   موبایل: 09131176583
   رمز: 123456789

نکته مهم:
- پوشه data باید برای کاربر وب قابل نوشتن باشد (Write/Modify)
- اگر test.php گفت NOT writable، دسترسی پوشه data را در File Manager اصلاح کنید
- API فقط از مسیر مستقیم استفاده می‌کند: /api/index.php?path=...

صفحات:
- / یا /login.html → ورود
- /app.html → پنل

نیازی به VPS نیست (همان الگوی پروژه Attendance).
