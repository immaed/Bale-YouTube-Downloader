<div align="center">
  <img src="https://raw.githubusercontent.com/khashayardev/Bale-YouTube-Downloader/main/assets/banner.svg" alt="Bale YouTube Downloader" width="600">
  
  <h3>🎬 ربات دانلودر یوتیوب برای پیام‌رسان بله</h3>
  <p><b>بدون سرور • بدون هزینه • با قدرت GitHub Actions</b></p>
  
  <p>
    <a href="#-نصب-و-راهاندازی"><img src="https://img.shields.io/badge/📦-نصب_راه_اندازی-green" alt="نصب"></a>
    <a href="LICENSE"><img src="https://img.shields.io/badge/License-MIT-yellow.svg" alt="License"></a>
    <img src="https://img.shields.io/badge/Powered_by-GitHub_Actions-blue" alt="GitHub Actions">
  </p>
</div>

<hr>

<table align="center">
  <tr>
    <td align="center"><b>📥 دانلود ویدیو</b></td>
    <td align="center"><b>🎵 فقط صدا</b></td>
    <td align="center"><b>📝 زیرنویس</b></td>
    <td align="center"><b>4K تا 480p</b></td>
    <td align="center"><b>🚫 دور زدن تحریم</b></td>
  </tr>
</table>

<hr>

<h2>🎯 چطور کار می‌کنه؟</h2>

<div align="center">
  <table>
    <tr>
      <td align="center" style="font-size: 24px;">1️⃣</td>
      <td>لینک یوتیوب رو برای ربات بله می‌فرستی</td>
    </tr>
    <tr>
      <td align="center" style="font-size: 24px;">2️⃣</td>
      <td>گیت‌هاب ویدیو رو با بهترین کیفیت دانلود می‌کنه</td>
    </tr>
    <tr>
      <td align="center" style="font-size: 24px;">3️⃣</td>
      <td>چند دقیقه بعد، فایل‌ها رو توی ربات تحویل می‌گیری</td>
    </tr>
  </table>
</div>

<hr>

<h2>📋 نیازمندی‌ها (۳ تا چیز ساده)</h2>

<table>
  <tr>
    <th>نیاز</th>
    <th>توضیح</th>
    <th>هزینه</th>
  </tr>
  <tr>
    <td>🟢 <b>هاست اشتراکی</b></td>
    <td>PHP 7.4+ و SQLite</td>
    <td>رایگان تا ۵۰ تومن</td>
  </tr>
  <tr>
    <td>🟢 <b>ربات بله</b></td>
    <td>از @botfather بسازید</td>
    <td>رایگان</td>
  </tr>
  <tr>
    <td>🟢 <b>اکانت گیت‌هاب</b></td>
    <td><a href="https://github.com">github.com</a> ثبت‌نام</td>
    <td>رایگان</td>
  </tr>
</table>

<hr>

<h2>📦 نصب و راه‌اندازی</h2>

<details open>
  <summary style="font-size: 18px; font-weight: bold; cursor: pointer;">قدم ۱: ساخت ربات بله</summary>
  <br>
  <ul>
    <li>توی بله با <code>@botfather</code> چت کن</li>
    <li>دستور <code>/newbot</code> رو بفرست</li>
    <li>اسم و username انتخاب کن</li>
    <li><b>توکن رو کپی کن</b> (مثلاً <code>123456789:abcd...</code>)</li>
  </ul>
</details>

<br>

<details>
  <summary style="font-size: 18px; font-weight: bold; cursor: pointer;">قدم ۲: فورک کردن پروژه</summary>
  <br>
  <ul>
    <li>دکمه <b>Fork</b> رو بزن (بالا سمت راست)</li>
    <li>حالا یه کپی از پروژه توی اکانت خودت داری</li>
  </ul>
</details>

<br>

<details>
  <summary style="font-size: 18px; font-weight: bold; cursor: pointer;">قدم ۳: تنظیم GitHub Secrets</summary>
  <br>
  <p>برو به <b>Settings > Secrets and variables > Actions</b> و یه Secret جدید بساز:</p>
  <table>
    <tr>
      <th>نام</th>
      <th>مقدار</th>
    </tr>
    <tr>
      <td><code>GATEWAY_SECRET</code></td>
      <td>یه رمز تصادفی (مثلاً <code>MySecret123!</code>)</td>
    </tr>
  </table>
</details>

<br>

<details>
  <summary style="font-size: 18px; font-weight: bold; cursor: pointer;">قدم ۴: ساخت GitHub Token</summary>
  <br>
  <ul>
    <li>برو به <a href="https://github.com/settings/tokens">github.com/settings/tokens</a></li>
    <li>Generate new token (classic)</li>
    <li>تیک ✅ <code>workflow</code> رو بزن</li>
    <li>توکن رو کپی کن</li>
  </ul>
</details>

<br>

<details>
  <summary style="font-size: 18px; font-weight: bold; cursor: pointer;">قدم ۵: آپلود gateway.php روی هاست</summary>
  <br>
  <ul>
    <li>فایل <code>gateway.php</code> رو توی هاست آپلود کن</li>
    <li>با ویرایشگر بازش کن و اینارو عوض کن:</li>
  </ul>

<pre style="background-color: #f6f8fa; padding: 16px; border-radius: 6px; overflow-x: auto;">
<span style="color: #d73a49;">define</span><span style="color: #24292e;">(</span><span style="color: #032f62;">'BALE_BOT_TOKEN'</span><span style="color: #24292e;">, </span><span style="color: #032f62;">'123456789:abcd...'</span><span style="color: #24292e;">);</span>  <span style="color: #6a737d;">// توکن ربات رو بذار</span>
<span style="color: #d73a49;">define</span><span style="color: #24292e;">(</span><span style="color: #032f62;">'GITHUB_PAT'</span><span style="color: #24292e;">, </span><span style="color: #032f62;">'ghp_xxxxxxxxxxxxx'</span><span style="color: #24292e;">);</span>      <span style="color: #6a737d;">// توکن گیت‌هاب رو بذار</span>
<span style="color: #d73a49;">define</span><span style="color: #24292e;">(</span><span style="color: #032f62;">'GITHUB_OWNER'</span><span style="color: #24292e;">, </span><span style="color: #032f62;">'your-username'</span><span style="color: #24292e;">);</span>        <span style="color: #6a737d;">// نام کاربری گیت‌هاب</span>
<span style="color: #d73a49;">define</span><span style="color: #24292e;">(</span><span style="color: #032f62;">'GATEWAY_SECRET'</span><span style="color: #24292e;">, </span><span style="color: #032f62;">'MySecret123!'</span><span style="color: #24292e;">);</span>       <span style="color: #6a737d;">// همون رمز مرحله ۳</span>
</pre>
</details>

<br>

<details>
  <summary style="font-size: 18px; font-weight: bold; cursor: pointer;">قدم ۶: تنظیم Webhook</summary>
  <br>
  <p>این لینک رو یه بار توی مرورگر باز کن:</p>
  
<pre style="background-color: #f6f8fa; padding: 16px; border-radius: 6px; overflow-x: auto;">
https://tapi.bale.ai/bot<TOKEN>/setWebhook?url=https://your-domain.com/gateway.php
</pre>

  <p>باید پیام <code>{"ok":true}</code> رو ببینی</p>
</details>

<br>

<details>
  <summary style="font-size: 18px; font-weight: bold; cursor: pointer;">قدم ۷: تست نهایی 🎉</summary>
  <br>
  <ol>
    <li>برو توی ربات بله و <b>Start</b> رو بزن</li>
    <li>یه لینک یوتیوب بفرست (مثلاً <code>https://youtu.be/dQw4w9WgXcQ</code>)</li>
    <li>منتظر پیام "درخواست شما دریافت شد" باش</li>
    <li>۲ تا ۵ دقیقه صبر کن</li>
    <li>فایل‌ها برات ارسال میشه! 🥳</li>
  </ol>
</details>

<hr>

<h2>🎮 روش استفاده</h2>

<div align="center">
  <table>
    <tr>
      <th>دکمه</th>
      <th>کاربرد</th>
    </tr>
    <tr>
      <td>📥 <b>لینک بفرست</b></td>
      <td>دانلود با بهترین کیفیت</td>
    </tr>
    <tr>
      <td>⚙️ <b>تنظیمات</b></td>
      <td>تغییر کیفیت و زیرنویس</td>
    </tr>
    <tr>
      <td>ℹ️ <b>راهنما</b></td>
      <td>توضیحات کامل</td>
    </tr>
  </table>
</div>

<br>

<table>
  <tr>
    <th style="width: 250px;">کار</th>
    <th>روش انجام</th>
  </tr>
  <tr>
    <td><b>🎬 دانلود با کیفیت دلخواه</b></td>
    <td>برو به ⚙️ تنظیمات → 🎬 کیفیت ویدیو → کیفیت رو انتخاب کن</td>
  </tr>
  <tr>
    <td><b>📝 دانلود با زیرنویس</b></td>
    <td>برو به ⚙️ تنظیمات → 📝 تنظیمات زیرنویس → فعال کن</td>
  </tr>
  <tr>
    <td><b>🎵 دانلود فقط صدا</b></td>
    <td>کیفیت رو بذار روی Audio Only → لینک رو بفرست</td>
  </tr>
  <tr>
    <td><b>🔒 دانلود با رمز</b></td>
    <td>توی لینک اینطوری بفرست: <code>لینک --- رمز</code></td>
  </tr>
</table>

<p style="background-color: #fff3cd; padding: 12px; border-radius: 6px; border-right: 4px solid #ffc107;">
  ⏱ <b>محدودیت:</b> هر کاربر می‌تونه هر ۳ دقیقه یه درخواست بده.
</p>

<hr>

<h2>❓ مشکلات رایج</h2>

<table>
  <tr>
    <th>مشکل</th>
    <th>دلیل</th>
    <th>راه حل</th>
  </tr>
  <tr>
    <td>ربات جواب نمیده</td>
    <td>Webhook تنظیم نشده</td>
    <td>قدم ۶ رو دوباره انجام بده</td>
  </tr>
  <tr>
    <td>خطای Unauthorized</td>
    <td>توکن اشتباهه</td>
    <td>توکن رو از @botfather دوباره بگیر</td>
  </tr>
  <tr>
    <td>دانلود شروع نمیشه</td>
    <td>PAT یا Secrets مشکل دارن</td>
    <td>قدم ۳ و ۴ رو چک کن</td>
  </tr>
  <tr>
    <td>خطای 404</td>
    <td>آدرس Webhook اشتباهه</td>
    <td>آدرس gateway.php رو چک کن</td>
  </tr>
  <tr>
    <td>فایل ارسال نشد</td>
    <td>حجم فایل زیاده</td>
    <td>به صورت خودکار تکه میشه، صبر کن</td>
  </tr>
  <tr>
    <td>"لطفاً صبر کنید"</td>
    <td>محدودیت ۳ دقیقه‌ای</td>
    <td>۳ دقیقه صبر کن</td>
  </tr>
</table>

<hr>

<h2>🔒 نکات امنیتی</h2>

<table>
  <tr>
    <td>⚠️</td>
    <td><b>هیچوقت</b> توکن ربات رو توی گیت‌هاب (حتی تو کد) نذارید!</td>
  </tr>
  <tr>
    <td>✅</td>
    <td>همیشه از GitHub Secrets برای اطلاعات حساس استفاده کنید</td>
  </tr>
  <tr>
    <td>✅</td>
    <td>هر چند وقت یه بار PAT گیت‌هاب رو عوض کنید</td>
  </tr>
</table>

<hr>

<h2>🤝 مشارکت</h2>

<p>این پروژه رو دوست داری و می‌خوای بهترش کنی؟</p>

<ol>
  <li>مخزن رو <b>Fork</b> کن</li>
  <li>تغییراتت رو اعمال کن</li>
  <li><b>Pull Request</b> بفرست</li>
</ol>

<p>هر نوع مشارکتی (حتی اصلاح یه غلط املایی) خیلی خوشحالم می‌کنه! 🎉</p>

<hr>

<h2>📄 مجوز</h2>

<p>این پروژه تحت <a href="LICENSE">MIT License</a> منتشر شده:</p>

<table>
  <tr>
    <td>✅</td>
    <td>استفاده تجاری آزاد</td>
  </tr>
  <tr>
    <td>✅</td>
    <td>تغییر و بهبود کد</td>
  </tr>
  <tr>
    <td>✅</td>
    <td>انتشار مجدد</td>
  </tr>
</table>

<hr>

<div align="center">
  <p style="font-size: 18px;">
    ⭐ <b>اگر این پروژه برات مفید بود، لطفاً بهش ستاره بده!</b> ⭐
  </p>
  <p>Made with ❤️ by Khashayar.one</p>
</div>
