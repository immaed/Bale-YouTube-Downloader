<?php
/**
 * ============================================================
 * gateway.php — Bale YouTube Downloader Gateway
 * Repository: Bale-YouTube-Downloader
 * 
 * وظیفه: واسط بین ربات بله و GitHub Actions
 * - دریافت Webhook از بله (پیام‌های کاربران)
 * - اعتبارسنجی و استخراج لینک‌های یوتیوب
 * - کنترل نرخ درخواست (Rate Limiting) — هر کاربر ۳ دقیقه
 * - ارسال درخواست dispatch به GitHub Actions
 * - دریافت نتیجه از GitHub Actions و ارسال فایل‌ها به کاربر
 * 
 * تنظیم پیش‌نیاز: این فایل باید روی یک هاست PHP (مثلاً cPanel) قرار بگیرد.
 * تنظیم Webhook بله: https://tapi.bale.ai/bot<TOKEN>/setWebhook?url=https://your-host.com/gateway.php
 * ============================================================
 */

// ══════════════════════════════════════════════════════════
// ۱. پیکربندی (این بخش را قبل از استفاده باید تنظیم کنید)
// ══════════════════════════════════════════════════════════

// توکن ربات بله (از @botfather دریافت کنید)
define('BALE_BOT_TOKEN', 'YOUR_BALE_BOT_TOKEN_HERE');

// توکن دسترسی شخصی گیت‌هاب (Personal Access Token با مجوز workflow)
define('GITHUB_PAT', 'YOUR_GITHUB_PAT_HERE');

// نام کاربری گیت‌هاب و نام مخزن
define('GITHUB_OWNER', 'YOUR_GITHUB_USERNAME');
define('GITHUB_REPO', 'Bale-YouTube-Downloader');

// برنچ (شاخه) پیش‌فرض برای dispatch
define('GITHUB_REF', 'main');

// فایل workflow که باید dispatch شود
define('WORKFLOW_FILENAME', 'yt-dl.yml');

// رمز امنیتی برای تأیید درخواست‌های برگشتی از GitHub Actions
// همین مقدار را در Secrets گیت‌هاب با نام GATEWAY_SECRET تنظیم کنید
define('GATEWAY_SECRET', 'YOUR_RANDOM_SECRET_STRING');

// مدت زمان محدودیت نرخ (به ثانیه) — پیش‌فرض ۱۸۰ = ۳ دقیقه
define('RATE_LIMIT_SECONDS', 180);

// مسیر فایل دیتابیس SQLite (برای ذخیره‌سازی محدودیت نرخ)
define('DB_FILE', __DIR__ . '/rate_limit.db');

// آدرس پایه API بله
define('BALE_API_BASE', 'https://tapi.bale.ai/bot' . BALE_BOT_TOKEN);

// ══════════════════════════════════════════════════════════
// ۲. راه‌اندازی دیتابیس SQLite
// ══════════════════════════════════════════════════════════

function initDatabase() {
    $db = new SQLite3(DB_FILE);
    $db->exec("CREATE TABLE IF NOT EXISTS rate_limits (
        chat_id TEXT PRIMARY KEY,
        last_request_time INTEGER
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS processed_updates (
        update_id INTEGER PRIMARY KEY
    )");
    return $db;
}

// ══════════════════════════════════════════════════════════
// ۳. توابع کمکی برای ارتباط با API بله
// ══════════════════════════════════════════════════════════

/**
 * ارسال درخواست به API بله
 */
function callBaleAPI($method, $params = []) {
    $url = BALE_API_BASE . '/' . $method;
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    
    // اگر فایل وجود داشت از multipart/form-data استفاده کن
    $hasFile = false;
    foreach ($params as $value) {
        if ($value instanceof CURLFile) {
            $hasFile = true;
            break;
        }
    }
    
    if ($hasFile) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
    } else {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    }
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return [
        'http_code' => $httpCode,
        'body' => json_decode($response, true)
    ];
}

/**
 * ارسال پیام متنی به کاربر
 */
function sendMessage($chatId, $text, $replyMarkup = null) {
    $params = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'Markdown'
    ];
    
    if ($replyMarkup !== null) {
        $params['reply_markup'] = $replyMarkup;
    }
    
    return callBaleAPI('sendMessage', $params);
}

/**
 * ارسال وضعیت "در حال تایپ..." به کاربر
 * مطابق با مستندات متد sendChatAction
 */
function sendChatAction($chatId, $action = 'typing') {
    $allowedActions = ['typing', 'upload_photo', 'record_video', 'upload_video', 
                       'record_voice', 'upload_voice', 'upload_document', 'choose_sticker'];
    
    if (!in_array($action, $allowedActions)) {
        $action = 'typing';
    }
    
    return callBaleAPI('sendChatAction', [
        'chat_id' => $chatId,
        'action' => $action
    ]);
}

/**
 * پاسخ به callback query (برای خروج دکمه‌ها از حالت انتظار)
 */
function answerCallbackQuery($callbackQueryId, $text = '', $showAlert = false) {
    return callBaleAPI('answerCallbackQuery', [
        'callback_query_id' => $callbackQueryId,
        'text' => $text,
        'show_alert' => $showAlert
    ]);
}

// ══════════════════════════════════════════════════════════
// ۴. توابع مدیریت نرخ درخواست
// ══════════════════════════════════════════════════════════

/**
 * بررسی محدودیت نرخ برای یک کاربر
 * بازگشت: true یعنی کاربر باید منتظر بماند
 */
function isRateLimited($db, $chatId) {
    $stmt = $db->prepare("SELECT last_request_time FROM rate_limits WHERE chat_id = :chat_id");
    $stmt->bindValue(':chat_id', $chatId, SQLITE3_TEXT);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    
    if ($row) {
        $elapsed = time() - $row['last_request_time'];
        if ($elapsed < RATE_LIMIT_SECONDS) {
            return true; // کاربر باید صبر کند
        }
    }
    
    return false;
}

/**
 * بروزرسانی زمان آخرین درخواست کاربر
 */
function updateRateLimit($db, $chatId) {
    $stmt = $db->prepare("INSERT OR REPLACE INTO rate_limits (chat_id, last_request_time) 
                          VALUES (:chat_id, :time)");
    $stmt->bindValue(':chat_id', $chatId, SQLITE3_TEXT);
    $stmt->bindValue(':time', time(), SQLITE3_INTEGER);
    $stmt->execute();
}

/**
 * دریافت زمان باقی‌مانده تا درخواست بعدی
 */
function getRemainingTime($db, $chatId) {
    $stmt = $db->prepare("SELECT last_request_time FROM rate_limits WHERE chat_id = :chat_id");
    $stmt->bindValue(':chat_id', $chatId, SQLITE3_TEXT);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    
    if ($row) {
        $elapsed = time() - $row['last_request_time'];
        $remaining = RATE_LIMIT_SECONDS - $elapsed;
        return max(0, $remaining);
    }
    
    return 0;
}

// ══════════════════════════════════════════════════════════
// ۵. تابع استخراج لینک‌های یوتیوب از متن
// ══════════════════════════════════════════════════════════

/**
 * استخراج URL های یوتیوب از متن
 * پشتیبانی از فرمت‌های:
 * - https://www.youtube.com/watch?v=VIDEO_ID
 * - https://youtu.be/VIDEO_ID
 * - https://m.youtube.com/watch?v=VIDEO_ID
 * @return array آرایه‌ای از URL های یافت شده
 */
function extractYoutubeUrls($text) {
    $urls = [];
    
    // الگوی regex برای تشخیص لینک‌های یوتیوب
    $patterns = [
        '/(?:https?:\/\/)?(?:www\.)?youtube\.com\/watch\?v=([a-zA-Z0-9_-]{11})/',
        '/(?:https?:\/\/)?youtu\.be\/([a-zA-Z0-9_-]{11})/',
        '/(?:https?:\/\/)?(?:www\.)?m\.youtube\.com\/watch\?v=([a-zA-Z0-9_-]{11})/'
    ];
    
    foreach ($patterns as $pattern) {
        if (preg_match_all($pattern, $text, $matches)) {
            foreach ($matches[1] as $videoId) {
                // نرمال‌سازی به فرمت استاندارد
                $urls[] = "https://www.youtube.com/watch?v={$videoId}";
            }
        }
    }
    
    // حذف موارد تکراری
    return array_unique($urls);
}

// ══════════════════════════════════════════════════════════
// ۶. تابع dispatch کردن workflow گیت‌هاب
// ══════════════════════════════════════════════════════════

/**
 * ارسال درخواست dispatch به GitHub Actions
 */
function dispatchGitHubWorkflow($youtubeUrl, $chatId, $quality, $subs) {
    $url = "https://api.github.com/repos/" . GITHUB_OWNER . "/" . GITHUB_REPO . 
           "/actions/workflows/" . WORKFLOW_FILENAME . "/dispatches";
    
    // تنظیمات کیفیت به فرمت داخلی
    $internalQuality = $quality;
    $friendlyQuality = $quality;
    
    // تبدیل کیفیت‌های خاص
    switch ($quality) {
        case '2160p': case '4K': 
            $internalQuality = '2160'; 
            $friendlyQuality = '4K (2160p)';
            break;
        case '1440p': case '2K': 
            $internalQuality = '1440'; 
            $friendlyQuality = '2K (1440p)';
            break;
        case '1080p': 
            $internalQuality = '1080'; 
            $friendlyQuality = 'Full HD (1080p)';
            break;
        case '720p': 
            $internalQuality = '720'; 
            $friendlyQuality = 'HD (720p)';
            break;
        case '480p': 
            $internalQuality = '480'; 
            $friendlyQuality = 'SD (480p)';
            break;
        case 'audio': 
            $internalQuality = 'audio'; 
            $friendlyQuality = 'Audio Only';
            break;
        default: 
            $internalQuality = 'best'; 
            $friendlyQuality = 'Best Quality';
            break;
    }
    
    $subsInternal = $subs === 'yes' ? 'true' : 'false';
    $subsFriendly = $subs === 'yes' ? 'Enabled' : 'Disabled';
    
    $postData = [
        'ref' => GITHUB_REF,
        'inputs' => [
            'youtube_urls' => $youtubeUrl,
            'quality' => $internalQuality,
            'download_subtitles' => $subsInternal,
            'password' => '',
            'user_chat_id' => (string)$chatId,
            'bale_gateway_url' => getCurrentUrl(),
            'user_friendly_quality' => $friendlyQuality,
            'user_friendly_subs' => $subsFriendly
        ]
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: application/vnd.github.v3+json',
        'Authorization: Bearer ' . GITHUB_PAT,
        'Content-Type: application/json',
        'User-Agent: Bale-YouTube-Downloader/1.0'
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return [
        'success' => ($httpCode >= 200 && $httpCode < 300),
        'http_code' => $httpCode,
        'response' => $response
    ];
}

// ══════════════════════════════════════════════════════════
// ۷. تابع دریافت URL فعلی (برای callback)
// ══════════════════════════════════════════════════════════

function getCurrentUrl() {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'];
    $path = str_replace(basename($_SERVER['SCRIPT_NAME']), '', $_SERVER['SCRIPT_NAME']);
    return $protocol . $host . $path . basename($_SERVER['SCRIPT_NAME']);
}

// ══════════════════════════════════════════════════════════
// ۸. ساخت منوی تنظیمات (دکمه‌های شیشه‌ای)
// ══════════════════════════════════════════════════════════

/**
 * ساخت منوی اصلی ربات
 */
function getMainMenu() {
    return [
        'keyboard' => [
            [['text' => '🎥 دانلود ویدیو']],
            [['text' => '⚙️ تنظیمات'], ['text' => 'ℹ️ راهنما']],
            [['text' => '📊 وضعیت سرور']]
        ],
        'resize_keyboard' => true,
        'persistent' => true
    ];
}

/**
 * ساخت منوی تنظیمات کیفیت
 */
function getQualitySettingsMenu() {
    return [
        'inline_keyboard' => [
            [['text' => '✨ Best Quality', 'callback_data' => 'quality_best']],
            [['text' => '4K (2160p)', 'callback_data' => 'quality_2160'], ['text' => '2K (1440p)', 'callback_data' => 'quality_1440']],
            [['text' => '1080p', 'callback_data' => 'quality_1080'], ['text' => '720p', 'callback_data' => 'quality_720']],
            [['text' => '480p', 'callback_data' => 'quality_480'], ['text' => '🎵 Audio Only', 'callback_data' => 'quality_audio']],
            [['text' => '🔙 بازگشت', 'callback_data' => 'settings_back']]
        ]
    ];
}

/**
 * ساخت منوی تنظیمات زیرنویس
 */
function getSubtitleSettingsMenu($currentSubs) {
    $subsStatus = $currentSubs === 'yes' ? '✅ فعال' : '❌ غیرفعال';
    
    return [
        'inline_keyboard' => [
            [['text' => "زیرنویس: {$subsStatus}", 'callback_data' => 'toggle_subs']],
            [['text' => '🔙 بازگشت به تنظیمات', 'callback_data' => 'settings_main']]
        ]
    ];
}

/**
 * ساخت منوی اصلی تنظیمات
 */
function getSettingsMainMenu() {
    return [
        'inline_keyboard' => [
            [['text' => '🎬 کیفیت ویدیو', 'callback_data' => 'settings_quality']],
            [['text' => '📝 تنظیمات زیرنویس', 'callback_data' => 'settings_subs']],
            [['text' => '🔙 بستن منو', 'callback_data' => 'settings_close']]
        ]
    ];
}

// ══════════════════════════════════════════════════════════
// ۹. ذخیره و بازیابی تنظیمات کاربر
// ══════════════════════════════════════════════════════════

function initUserSettings() {
    $db = new SQLite3(DB_FILE);
    $db->exec("CREATE TABLE IF NOT EXISTS user_settings (
        chat_id TEXT PRIMARY KEY,
        quality TEXT DEFAULT 'best',
        subtitles TEXT DEFAULT 'no'
    )");
    return $db;
}

function getUserSettings($chatId) {
    $db = initUserSettings();
    $stmt = $db->prepare("SELECT quality, subtitles FROM user_settings WHERE chat_id = :chat_id");
    $stmt->bindValue(':chat_id', $chatId, SQLITE3_TEXT);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    
    if ($row) {
        return $row;
    }
    
    // تنظیمات پیش‌فرض
    return [
        'quality' => 'best',
        'subtitles' => 'no'
    ];
}

function saveUserSettings($chatId, $quality, $subtitles) {
    $db = initUserSettings();
    $stmt = $db->prepare("INSERT OR REPLACE INTO user_settings (chat_id, quality, subtitles) 
                          VALUES (:chat_id, :quality, :subtitles)");
    $stmt->bindValue(':chat_id', $chatId, SQLITE3_TEXT);
    $stmt->bindValue(':quality', $quality, SQLITE3_TEXT);
    $stmt->bindValue(':subtitles', $subtitles, SQLITE3_TEXT);
    $stmt->execute();
}

// ══════════════════════════════════════════════════════════
// ۱۰. پردازش پیام‌های ورودی (Webhook)
// ══════════════════════════════════════════════════════════

function processMessage($message, $db) {
    $chatId = $message['chat']['id'] ?? null;
    $text = $message['text'] ?? '';
    $messageId = $message['message_id'] ?? null;
    
    if (!$chatId) return;
    
    // ── ۱۰.۱: پردازش دستور /start ──────────────────────
    if (strpos($text, '/start') === 0) {
        $welcomeText = "🎬 *سلام! به ربات دانلودر یوتیوب خوش آمدید!*\n\n" .
                       "من می‌توانم ویدیوهای یوتیوب را برای شما دانلود کنم.\n\n" .
                       "📌 *روش استفاده:*\n" .
                       "۱. لینک ویدیوی یوتیوب را ارسال کنید\n" .
                       "۲. منتظر بمانید تا دانلود تمام شود\n" .
                       "۳. فایل‌ها را دریافت کنید\n\n" .
                       "⚡️ *ویژگی‌ها:*\n" .
                       "• پشتیبانی از کیفیت 4K تا 480p\n" .
                       "• دانلود فقط صدا (MP3)\n" .
                       "• زیرنویس فارسی و انگلیسی\n" .
                       "• سرورلس و بدون قطعی\n\n" .
                       "🔗 *قدرت گرفته از GitHub Actions*\n\n" .
                       "از دکمه‌های زیر برای شروع استفاده کنید 👇";
        
        sendMessage($chatId, $welcomeText, json_encode(getMainMenu()));
        return;
    }
    
    // ── ۱۰.۲: پردازش دستور /help ──────────────────────
    if (strpos($text, '/help') === 0) {
        $helpText = "📖 *راهنمای ربات*\n\n" .
                    "🔸 *دانلود ویدیو:*\n" .
                    "لینک یوتیوب را ارسال کنید تا دانلود شروع شود.\n\n" .
                    "🔸 *تنظیم کیفیت:*\n" .
                    "از دکمه ⚙️ تنظیمات > 🎬 کیفیت ویدیو\n" .
                    "کیفیت مورد نظر را انتخاب کنید.\n\n" .
                    "🔸 *زیرنویس:*\n" .
                    "از بخش تنظیمات می‌توانید زیرنویس را فعال کنید.\n\n" .
                    "🔸 *محدودیت:*\n" .
                    "هر کاربر می‌تواند هر ۳ دقیقه یک درخواست ارسال کند.\n\n" .
                    "🔸 *حجم فایل:*\n" .
                    "فایل‌های بزرگ به صورت چند بخش ارسال می‌شوند.";
        
        sendMessage($chatId, $helpText);
        return;
    }
    
    // ── ۱۰.۳: پردازش دکمه‌های منو (ReplyKeyboard) ─────
    if ($text === '🎥 دانلود ویدیو') {
        sendMessage($chatId, "🔗 *لینک ویدیوی یوتیوب را ارسال کنید:*\n\n" .
                   "_مثال: https://youtu.be/abc123def45_");
        return;
    }
    
    if ($text === '⚙️ تنظیمات') {
        $settings = getUserSettings($chatId);
        $qualityNames = [
            'best' => '✨ Best Quality',
            '2160' => '4K (2160p)',
            '1440' => '2K (1440p)',
            '1080' => 'Full HD (1080p)',
            '720' => 'HD (720p)',
            '480' => 'SD (480p)',
            'audio' => '🎵 Audio Only'
        ];
        
        $currentQuality = $qualityNames[$settings['quality']] ?? 'Best';
        $subsStatus = $settings['subtitles'] === 'yes' ? '✅ فعال' : '❌ غیرفعال';
        
        $settingsText = "⚙️ *تنظیمات فعلی:*\n\n" .
                       "🎬 *کیفیت:* {$currentQuality}\n" .
                       "📝 *زیرنویس:* {$subsStatus}\n\n" .
                       "برای تغییر هر بخش، روی گزینه مورد نظر کلیک کنید:";
        
        sendMessage($chatId, $settingsText, json_encode(getSettingsMainMenu()));
        return;
    }
    
    if ($text === 'ℹ️ راهنما') {
        // همانند دستور /help عمل می‌کند
        $helpText = "📖 *راهنمای ربات*\n\n" .
                    "🔸 *دانلود ویدیو:*\n" .
                    "لینک یوتیوب را ارسال کنید تا دانلود شروع شود.\n\n" .
                    "🔸 *تنظیم کیفیت:*\n" .
                    "از دکمه ⚙️ تنظیمات > 🎬 کیفیت ویدیو\n" .
                    "کیفیت مورد نظر را انتخاب کنید.\n\n" .
                    "🔸 *زیرنویس:*\n" .
                    "از بخش تنظیمات می‌توانید زیرنویس را فعال کنید.\n\n" .
                    "🔸 *محدودیت:*\n" .
                    "هر کاربر می‌تواند هر ۳ دقیقه یک درخواست ارسال کند.\n\n" .
                    "🔸 *حجم فایل:*\n" .
                    "فایل‌های بزرگ به صورت چند بخش ارسال می‌شوند.";
        
        sendMessage($chatId, $helpText);
        return;
    }
    
    if ($text === '📊 وضعیت سرور') {
        $db_check = new SQLite3(DB_FILE);
        $count = $db_check->querySingle("SELECT COUNT(*) FROM rate_limits");
        $remaining = getRemainingTime($db, $chatId);
        
        $statusText = "📊 *وضعیت سرور*\n\n" .
                     "✅ *سرویس:* فعال\n" .
                     "🔄 *درخواست‌های امروز:* {$count}\n" .
                     "⏱ *زمان تا درخواست بعدی شما:* " . 
                     ($remaining > 0 ? gmdate("i:s", $remaining) : "آماده ✅") . "\n" .
                     "🔗 *قدرت گرفته از:* GitHub Actions\n" .
                     "💻 *هاست:* " . ($_SERVER['HTTP_HOST'] ?? 'Unknown');
        
        sendMessage($chatId, $statusText);
        return;
    }
    
    // ── ۱۰.۴: پردازش لینک‌های یوتیوب ────────────────
    $youtubeUrls = extractYoutubeUrls($text);
    
    if (!empty($youtubeUrls)) {
        // بررسی محدودیت نرخ
        if (isRateLimited($db, $chatId)) {
            $remaining = getRemainingTime($db, $chatId);
            $minutes = floor($remaining / 60);
            $seconds = $remaining % 60;
            
            sendMessage($chatId, "⏳ *لطفاً کمی صبر کنید!*\n\n" .
                       "شما می‌توانید {$minutes} دقیقه و {$seconds} ثانیه دیگر " .
                       "درخواست جدید ارسال کنید.\n\n" .
                       "_این محدودیت برای جلوگیری از فشار روی سرور است._");
            return;
        }
        
        // ارسال وضعیت به کاربر
        $settings = getUserSettings($chatId);
        
        sendChatAction($chatId, 'typing');
        
        $qualityNames = [
            'best' => 'Best Quality',
            '2160' => '4K',
            '1440' => '2K',
            '1080' => '1080p',
            '720' => '720p',
            '480' => '480p',
            'audio' => 'Audio'
        ];
        
        $friendlyQuality = $qualityNames[$settings['quality']] ?? 'Best';
        
        sendMessage($chatId, "✅ *درخواست شما دریافت شد!*\n\n" .
                   "🎬 کیفیت: {$friendlyQuality}\n" .
                   "📝 زیرنویس: " . ($settings['subtitles'] === 'yes' ? 'فعال' : 'غیرفعال') . "\n" .
                   "⏳ در حال پردازش...\n\n" .
                   "_این فرآیند ممکن است چند دقیقه طول بکشد. لطفاً شکیبا باشید._");
        
        // dispatch کردن workflow
        $youtubeUrl = $youtubeUrls[0]; // اولین لینک
        $result = dispatchGitHubWorkflow(
            $youtubeUrl,
            $chatId,
            $settings['quality'],
            $settings['subtitles']
        );
        
        if ($result['success']) {
            // بروزرسانی محدودیت نرخ
            updateRateLimit($db, $chatId);
            
            sendMessage($chatId, "🚀 *دانلود شروع شد!*\n\n" .
                       "ویدیوی شما در صف پردازش قرار گرفت.\n" .
                       "پس از اتمام، فایل‌ها برای شما ارسال خواهد شد.\n\n" .
                       "⏱ _زمان تقریبی: ۲ تا ۵ دقیقه_");
        } else {
            sendMessage($chatId, "❌ *خطا در شروع دانلود!*\n\n" .
                       "متأسفانه مشکلی در ارتباط با سرور دانلود پیش آمد.\n" .
                       "لطفاً چند دقیقه دیگر دوباره تلاش کنید.\n\n" .
                       "_کد خطا: {$result['http_code']}_");
        }
        
        return;
    }
    
    // ── ۱۰.۵: پیام پیش‌فرض برای سایر موارد ──────────
    sendMessage($chatId, "📋 *لطفاً یکی از موارد زیر را انتخاب کنید:*\n\n" .
               "• یک لینک یوتیوب ارسال کنید\n" .
               "• از دکمه‌های منو استفاده کنید\n" .
               "• دستور /help را برای راهنمایی بفرستید", 
               json_encode(getMainMenu()));
}

// ══════════════════════════════════════════════════════════
// ۱۱. پردازش Callback Query (دکمه‌های inline)
// ══════════════════════════════════════════════════════════

function processCallbackQuery($callbackQuery, $db) {
    $callbackId = $callbackQuery['id'] ?? null;
    $chatId = $callbackQuery['from']['id'] ?? null;
    $data = $callbackQuery['data'] ?? '';
    $messageId = $callbackQuery['message']['message_id'] ?? null;
    
    if (!$callbackId || !$chatId || !$data) return;
    
    // همیشه به callback پاسخ بده تا دکمه از حالت انتظار خارج شود
    answerCallbackQuery($callbackId);
    
    $settings = getUserSettings($chatId);
    
    // تنظیمات کیفیت
    if (strpos($data, 'quality_') === 0) {
        $quality = str_replace('quality_', '', $data);
        saveUserSettings($chatId, $quality, $settings['subtitles']);
        
        $qualityNames = [
            'best' => '✨ Best Quality',
            '2160' => '4K (2160p)',
            '1440' => '2K (1440p)',
            '1080' => 'Full HD (1080p)',
            '720' => 'HD (720p)',
            '480' => 'SD (480p)',
            'audio' => '🎵 Audio Only'
        ];
        
        // بروزرسانی پیام تنظیمات
        $newText = "✅ *کیفیت تنظیم شد!*\n\n" .
                   "🎬 کیفیت فعلی: {$qualityNames[$quality]}\n\n" .
                   "_برای تغییر روی گزینه مورد نظر کلیک کنید:_";
        
        callBaleAPI('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $newText,
            'reply_markup' => json_encode(getQualitySettingsMenu())
        ]);
        return;
    }
    
    // نمایش منوی تنظیمات کیفیت
    if ($data === 'settings_quality') {
        callBaleAPI('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => '🎬 *کیفیت ویدیوی خود را انتخاب کنید:*',
            'reply_markup' => json_encode(getQualitySettingsMenu())
        ]);
        return;
    }
    
    // نمایش منوی تنظیمات زیرنویس
    if ($data === 'settings_subs') {
        callBaleAPI('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => '📝 *تنظیمات زیرنویس:*',
            'reply_markup' => json_encode(getSubtitleSettingsMenu($settings['subtitles']))
        ]);
        return;
    }
    
    // toggle زیرنویس
    if ($data === 'toggle_subs') {
        $newSubs = $settings['subtitles'] === 'yes' ? 'no' : 'yes';
        saveUserSettings($chatId, $settings['quality'], $newSubs);
        
        $subsStatus = $newSubs === 'yes' ? '✅ فعال' : '❌ غیرفعال';
        
        callBaleAPI('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => "✅ *زیرنویس {$subsStatus} شد!*\n\n📝 *تنظیمات زیرنویس:*",
            'reply_markup' => json_encode(getSubtitleSettingsMenu($newSubs))
        ]);
        return;
    }
    
    // بازگشت به منوی اصلی تنظیمات
    if ($data === 'settings_main') {
        $qualityNames = [
            'best' => '✨ Best Quality',
            '2160' => '4K (2160p)',
            '1440' => '2K (1440p)',
            '1080' => 'Full HD (1080p)',
            '720' => 'HD (720p)',
            '480' => 'SD (480p)',
            'audio' => '🎵 Audio Only'
        ];
        
        $currentQuality = $qualityNames[$settings['quality']] ?? 'Best';
        $subsStatus = $settings['subtitles'] === 'yes' ? '✅ فعال' : '❌ غیرفعال';
        
        $settingsText = "⚙️ *تنظیمات فعلی:*\n\n" .
                       "🎬 *کیفیت:* {$currentQuality}\n" .
                       "📝 *زیرنویس:* {$subsStatus}\n\n" .
                       "برای تغییر هر بخش، روی گزینه مورد نظر کلیک کنید:";
        
        callBaleAPI('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $settingsText,
            'reply_markup' => json_encode(getSettingsMainMenu())
        ]);
        return;
    }
    
    // بستن منو
    if ($data === 'settings_close' || $data === 'settings_back') {
        callBaleAPI('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => '⚙️ *تنظیمات بسته شد.*\n\nبرای استفاده از ربات، از دکمه‌های زیر استفاده کنید:'
        ]);
        sendMessage($chatId, '📋 *منوی اصلی:*', json_encode(getMainMenu()));
        return;
    }
}

// ══════════════════════════════════════════════════════════
// ۱۲. پردازش نتیجه برگشتی از GitHub Actions
// ══════════════════════════════════════════════════════════

function processGitHubCallback() {
    // دریافت raw input
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (!$data) {
        sendErrorResponse('Invalid JSON');
        return;
    }
    
    // بررسی رمز امنیتی
    $secret = $_SERVER['HTTP_X_GATEWAY_SECRET'] ?? '';
    if ($secret !== GATEWAY_SECRET) {
        sendErrorResponse('Unauthorized', 401);
        return;
    }
    
    $chatId = $data['chat_id'] ?? null;
    $files = $data['files'] ?? [];
    $quality = $data['quality'] ?? 'Best Quality';
    $subs = $data['subs'] ?? 'Disabled';
    $title = $data['title'] ?? 'دانلود موفق';
    
    if (!$chatId) {
        sendErrorResponse('Missing chat_id');
        return;
    }
    
    // ارسال پیام اولیه
    sendMessage($chatId, "✅ *دانلود با موفقیت انجام شد!*\n\n" .
               "🎬 کیفیت: {$quality}\n" .
               "📝 زیرنویس: {$subs}\n" .
               "📦 تعداد فایل: " . count($files));
    
    // ساخت دکمه‌های دانلود
    if (!empty($files)) {
        $inlineKeyboard = ['inline_keyboard' => []];
        
        if (count($files) === 1) {
            // یک فایل — یک دکمه با لینک مستقیم
            $inlineKeyboard['inline_keyboard'][] = [
                ['text' => '📥 دانلود فایل', 'url' => $files[0]]
            ];
        } else {
            // چند فایل — دکمه‌های شماره‌گذاری شده
            $partNumber = 1;
            foreach ($files as $fileUrl) {
                $inlineKeyboard['inline_keyboard'][] = [
                    ['text' => "📦 پارت {$partNumber}", 'url' => $fileUrl]
                ];
                $partNumber++;
            }
        }
        
        sendMessage($chatId, 
                   "🔗 *لینک‌های دانلود:*\n\n" .
                   (count($files) > 1 ? 
                    "⚠️ این فایل به دلیل حجم بالا به " . count($files) . " بخش تقسیم شده است.\n" .
                    "_لطفاً همه بخش‌ها را دانلود کرده و سپس از حالت فشرده خارج کنید._\n\n" :
                    "برای دانلود روی دکمه زیر کلیک کنید:\n\n") .
                   "⏱ _توجه: لینک‌ها تا ۵ دقیقه معتبر هستند._",
                   json_encode($inlineKeyboard));
    } else {
        sendMessage($chatId, "⚠️ متأسفانه هیچ فایلی برای ارسال یافت نشد.");
    }
    
    sendSuccessResponse();
}

// ══════════════════════════════════════════════════════════
// ۱۳. توابع پاسخ HTTP
// ══════════════════════════════════════════════════════════

function sendSuccessResponse() {
    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode(['ok' => true]);
    exit;
}

function sendErrorResponse($message, $code = 400) {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}

// ══════════════════════════════════════════════════════════
// ۱۴. روتر اصلی — ورودی برنامه
// ══════════════════════════════════════════════════════════

// راه‌اندازی دیتابیس
$db = initDatabase();

// دریافت محتوای درخواست
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$requestMethod = $_SERVER['REQUEST_METHOD'];

// تشخیص نوع درخواست
// اگر درخواست POST با content-type application/json باشد
// و حاوی فیلد files باشد — یعنی از طرف GitHub Actions آمده
$input = json_decode(file_get_contents('php://input'), true);

if ($requestMethod === 'POST' && $input && isset($input['files'])) {
    // callback از GitHub Actions
    processGitHubCallback();
} elseif ($requestMethod === 'POST' && $input && isset($input['update_id'])) {
    // Webhook از بله — آپدیت‌های جدید
    $update = $input;
    
    // بررسی تکراری نبودن update
    if (isset($update['update_id'])) {
        $stmt = $db->prepare("SELECT update_id FROM processed_updates WHERE update_id = :update_id");
        $stmt->bindValue(':update_id', $update['update_id'], SQLITE3_INTEGER);
        $result = $stmt->execute();
        
        if (!$result->fetchArray()) {
            // ذخیره update_id برای جلوگیری از پردازش تکراری
            $stmt = $db->prepare("INSERT INTO processed_updates (update_id) VALUES (:update_id)");
            $stmt->bindValue(':update_id', $update['update_id'], SQLITE3_INTEGER);
            $stmt->execute();
            
            // پردازش message
            if (isset($update['message'])) {
                processMessage($update['message'], $db);
            }
            
            // پردازش callback_query
            if (isset($update['callback_query'])) {
                processCallbackQuery($update['callback_query'], $db);
            }
        }
    }
    
    sendSuccessResponse();
} else {
    // تست ساده — نمایش صفحه status
    if ($requestMethod === 'GET') {
        echo "<h1>Bale YouTube Downloader Gateway</h1>";
        echo "<p>✅ Gateway is running!</p>";
        echo "<p>This is the gateway for connecting Bale bot to GitHub Actions.</p>";
        echo "<p>Repository: <a href='https://github.com/" . GITHUB_OWNER . "/" . GITHUB_REPO . "'>" . GITHUB_REPO . "</a></p>";
    } else {
        sendErrorResponse('Invalid Request');
    }
}
