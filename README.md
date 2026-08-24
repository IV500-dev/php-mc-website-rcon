✨ Features

- Secure OTP Login – An 8-digit verification code is sent inside the Minecraft chat
- Shopping Cart & Online Ordering – Add products with a single click
- Card-to-Card Payment – Upload receipt image for verification
- Professional Admin Panel – Manage products, orders, tickets, and settings
- Ticket System – Contact support
- Configurable via JSON File – Site name, server IP, Discord link, and allowed domains
- Brute Force Protection – IP lockout after 3 failed attempts

📋 Requirements

- PHP 7.4+ with PDO SQLite
- Web Server (Apache/Nginx) with PHP support
- Access to Minecraft Server with RCON enabled (port 25575 typically)
- File Upload (image) for receipts

🛠 Installation & Setup

1. Copy the project files to the web server root (e.g., public_html).
2. Provide write permissions for the project folder (to create db.db and uploads).
3. Edit the config.json file
## ✨ امکانات

- 🔐 **ورود امن با OTP** – کد تأیید ۸ رقمی داخل چت ماینکرفت ارسال می‌شود
- 🛒 **سبد خرید و سفارش آنلاین** – افزودن محصول با کلیک
- 💳 **پرداخت کارت‌به‌کارت** – آپلود تصویر رسید برای تأیید
- ⚙️ **پنل ادمین حرفه‌ای** – مدیریت محصولات، سفارش‌ها، تیکت‌ها و تنظیمات
- 🎫 **سیستم تیکت** – ارتباط با پشتیبانی
- 🌐 **قابل تنظیم از طریق فایل JSON** – نام سایت، IP سرور، لینک دیسکورد و دامنه‌های مجاز
- 🚫 **محافظت در برابر Brute Force** – قفل IP بعد از ۳ تلاش ناموفق

---

## 📋 نیازمندی‌ها

| نیازمندی | توضیح |
|----------|-------|
| PHP 7.4+ | با PDO SQLite |
| وب‌سرور (Apache/Nginx) | با پشتیبانی از PHP |
| دسترسی به سرور ماینکرفت | با RCON فعال (پورت ۲۵۵۷۵ معمولاً) |
|دسترسی آپلود فایل (image) | برای رسیدها |

---

## 🛠 نصب و راه‌اندازی

1. فایل‌های پروژه را در ریشه وب‌سرور (مثلاً `public_html`) کپی کنید.
2. دسترسی نوشتن برای پوشه پروژه فراهم کنید (برای ساخت `db.db` و `uploads`).
3. فایل `config.json` را ویرایش کنید
