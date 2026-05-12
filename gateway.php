<?php
/**
 * ============================================================
 * gateway.php — Bale YouTube Downloader Gateway (Final V3)
 * Repository: Bale-YouTube-Downloader
 * 
 * وظیفه: واسط بین ربات بله و GitHub Actions
 * - دریافت Webhook از بله (پیام‌های کاربران)
 * - اعتبارسنجی و استخراج لینک‌های یوتیوب
 * - کنترل نرخ درخواست (Rate Limiting) — هر کاربر 5 دقیقه
 * - دکمه تأیید قبل از dispatch
 * - Progress indicator (ویرایش زنده پیام)
 * - دکمه بررسی وضعیت با تایمر ۱۲۰ ثانیه + بررسی داخلی status.json
 * - منوی inline برای /start
 * ============================================================
 */

// ══════════════════════════════════════════════════════════
// ۱. پیکربندی (این مقادیر را قبل از استفاده تغییر دهید)
// ══════════════════════════════════════════════════════════

// توکن ربات بله — از @botfather دریافت کنید
define('BALE_BOT_TOKEN', 'YOUR_BALE_BOT_TOKEN_HERE');

// توکن دسترسی شخصی گیت‌هاب — با مجوز repo و workflow
define('GITHUB_PAT', 'YOUR_GITHUB_PAT_HERE');

// نام کاربری گیت‌هاب
define('GITHUB_OWNER', 'YOUR_GITHUB_USERNAME');

// نام مخزن (پیش‌فرض: Bale-YouTube-Downloader)
define('GITHUB_REPO', 'Bale-YouTube-Downloader');

// برنچ (پیش‌فرض: main)
define('GITHUB_REF', 'main');

// نام فایل workflow دانلود (پیش‌فرض: yt-dl.yml)
define('WORKFLOW_FILENAME', 'yt-dl.yml');

// محدودیت نرخ درخواست — به ثانیه (پیش‌فرض: ۳۰۰ = ۵ دقیقه)
define('RATE_LIMIT_SECONDS', 300);

// محدودیت بررسی وضعیت — به ثانیه (پیش‌فرض: ۱۸۰ = ۳ دقیقه)
define('STATUS_CHECK_SECONDS', 180);

// مسیر فایل دیتابیس (پیش‌فرض: همین پوشه)
define('DB_FILE', __DIR__ . '/rate_limit.db');

// آدرس پایه API بله (نیازی به تغییر ندارد)
define('BALE_API_BASE', 'https://tapi.bale.ai/bot' . BALE_BOT_TOKEN);

// آدرس پایه API گیت‌هاب (نیازی به تغییر ندارد)
define('GITHUB_API_BASE', 'https://api.github.com/repos/' . GITHUB_OWNER . '/' . GITHUB_REPO);

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
    $db->exec("CREATE TABLE IF NOT EXISTS user_settings (
        chat_id TEXT PRIMARY KEY,
        quality TEXT DEFAULT 'best',
        subtitles TEXT DEFAULT 'no'
    )");
    return $db;
}

// ══════════════════════════════════════════════════════════
// ۳. توابع کمکی API بله
// ══════════════════════════════════════════════════════════

function callBaleAPI($method, $params = []) {
    $url = BALE_API_BASE . '/' . $method;
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    
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

function editMessageText($chatId, $messageId, $text, $replyMarkup = null) {
    $params = [
        'chat_id' => $chatId,
        'message_id' => $messageId,
        'text' => $text,
        'parse_mode' => 'Markdown'
    ];
    
    if ($replyMarkup !== null) {
        $params['reply_markup'] = $replyMarkup;
    }
    
    return callBaleAPI('editMessageText', $params);
}

function deleteMessage($chatId, $messageId) {
    return callBaleAPI('deleteMessage', [
        'chat_id' => $chatId,
        'message_id' => $messageId
    ]);
}

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

function isRateLimited($db, $chatId) {
    $stmt = $db->prepare("SELECT last_request_time FROM rate_limits WHERE chat_id = :chat_id");
    $stmt->bindValue(':chat_id', $chatId, SQLITE3_TEXT);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    
    if ($row) {
        $elapsed = time() - $row['last_request_time'];
        if ($elapsed < RATE_LIMIT_SECONDS) {
            return true;
        }
    }
    
    return false;
}

function updateRateLimit($db, $chatId) {
    $stmt = $db->prepare("INSERT OR REPLACE INTO rate_limits (chat_id, last_request_time) 
                          VALUES (:chat_id, :time)");
    $stmt->bindValue(':chat_id', $chatId, SQLITE3_TEXT);
    $stmt->bindValue(':time', time(), SQLITE3_INTEGER);
    $stmt->execute();
}

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

function canCheckStatus($db, $chatId) {
    $stmt = $db->prepare("SELECT last_request_time FROM rate_limits WHERE chat_id = :chat_id");
    $stmt->bindValue(':chat_id', $chatId, SQLITE3_TEXT);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    
    if ($row) {
        $elapsed = time() - $row['last_request_time'];
        if ($elapsed < STATUS_CHECK_SECONDS) {
            return [
                'can_check' => false,
                'remaining' => STATUS_CHECK_SECONDS - $elapsed
            ];
        }
    }
    
    return [
        'can_check' => true,
        'remaining' => 0
    ];
}

// ══════════════════════════════════════════════════════════
// ۵. تابع استخراج لینک‌های یوتیوب
// ══════════════════════════════════════════════════════════

function extractYoutubeUrls($text) {
    $urls = [];
    
    $patterns = [
        '/(?:https?:\/\/)?(?:www\.)?youtube\.com\/watch\?v=([a-zA-Z0-9_-]{11})/',
        '/(?:https?:\/\/)?youtu\.be\/([a-zA-Z0-9_-]{11})/',
        '/(?:https?:\/\/)?(?:www\.)?m\.youtube\.com\/watch\?v=([a-zA-Z0-9_-]{11})/',
        '/(?:https?:\/\/)?(?:www\.)?youtube\.com\/shorts\/([a-zA-Z0-9_-]{11})/'
    ];
    
    foreach ($patterns as $pattern) {
        if (preg_match_all($pattern, $text, $matches)) {
            foreach ($matches[1] as $videoId) {
                $urls[] = "https://www.youtube.com/watch?v={$videoId}";
            }
        }
    }
    
    return array_unique($urls);
}

// ══════════════════════════════════════════════════════════
// ۶. تابع dispatch کردن workflow گیت‌هاب
// ══════════════════════════════════════════════════════════

function dispatchGitHubWorkflow($youtubeUrl, $chatId, $quality, $subs) {
    $url = "https://api.github.com/repos/" . GITHUB_OWNER . "/" . GITHUB_REPO . 
           "/actions/workflows/" . WORKFLOW_FILENAME . "/dispatches";
    
    $internalQuality = $quality;
    $friendlyQuality = $quality;
    
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
        'User-Agent: Bale-YouTube-Downloader/3.0'
    ]);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    return [
        'success' => ($httpCode >= 200 && $httpCode < 300),
        'http_code' => $httpCode,
        'curl_error' => $curlError
    ];
}

// ══════════════════════════════════════════════════════════
// ۷. توابع GitHub API
// ══════════════════════════════════════════════════════════

function callGitHubAPI($endpoint) {
    $url = GITHUB_API_BASE . $endpoint;
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . GITHUB_PAT,
        'Accept: application/vnd.github.v3+json',
        'User-Agent: Bale-YouTube-Downloader/3.0'
    ]);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return [
        'code' => $httpCode,
        'body' => json_decode($response, true)
    ];
}

/**
 * بررسی فایل‌های ready برای یک کاربر
 */
function checkReadyFiles($chatId) {
    $endpoint = '/contents/videos?ref=' . GITHUB_REF;
    $result = callGitHubAPI($endpoint);
    
    $readyFolders = [];
    
    if ($result['code'] !== 200 || !is_array($result['body'])) {
        return $readyFolders;
    }
    
    foreach ($result['body'] as $item) {
        if ($item['type'] !== 'dir') continue;
        
        $folderName = $item['name'];
        
        // خواندن status.json
        $statusEndpoint = '/contents/videos/' . $folderName . '/status.json?ref=' . GITHUB_REF;
        $statusResult = callGitHubAPI($statusEndpoint);
        
        if ($statusResult['code'] !== 200) continue;
        
        $content = base64_decode($statusResult['body']['content'] ?? '');
        $status = json_decode($content, true);
        
        if (!$status) continue;
        
        if (($status['status'] ?? '') === 'ready' && ($status['chat_id'] ?? '') === $chatId) {
            // دریافت لیست فایل‌ها
            $filesEndpoint = '/contents/videos/' . $folderName . '?ref=' . GITHUB_REF;
            $filesResult = callGitHubAPI($filesEndpoint);
            
            $files = [];
            if ($filesResult['code'] === 200 && is_array($filesResult['body'])) {
                foreach ($filesResult['body'] as $file) {
                    if ($file['type'] === 'file' && 
                        $file['name'] !== 'status.json' && 
                        $file['name'] !== 'README.md' && 
                        $file['name'] !== 'thumbnail.jpg') {
                        $files[] = [
                            'name' => $file['name'],
                            'url' => $file['download_url'] ?? 
                                     'https://raw.githubusercontent.com/' . GITHUB_OWNER . '/' . GITHUB_REPO . '/' . GITHUB_REF . '/videos/' . $folderName . '/' . $file['name']
                        ];
                    }
                }
            }
            
            $readyFolders[] = [
                'folder' => $folderName,
                'files' => $files,
                'sha' => $statusResult['body']['sha'] ?? null
            ];
        }
    }
    
    return $readyFolders;
}



/**
 * ارسال درخواست جستجو به workflow yt-search.yml
 */
function dispatchSearchWorkflow($query, $chatId) {
    $url = "https://api.github.com/repos/" . GITHUB_OWNER . "/" . GITHUB_REPO . 
           "/actions/workflows/yt-search.yml/dispatches";
    
    $postData = [
        'ref' => GITHUB_REF,
        'inputs' => [
            'query' => $query,
            'chat_id' => (string)$chatId,
            'max_results' => '5'
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
        'User-Agent: Bale-YouTube-Downloader/3.0'
    ]);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return [
        'success' => ($httpCode >= 200 && $httpCode < 300),
        'http_code' => $httpCode
    ];
}



// ══════════════════════════════════════════════════════════
// ۸. تابع دریافت URL فعلی
// ══════════════════════════════════════════════════════════

function getCurrentUrl() {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'];
    $path = str_replace(basename($_SERVER['SCRIPT_NAME']), '', $_SERVER['SCRIPT_NAME']);
    return $protocol . $host . $path . basename($_SERVER['SCRIPT_NAME']);
}

// ══════════════════════════════════════════════════════════
// ۹. ساخت منوها
// ══════════════════════════════════════════════════════════

function getStartMenu() {
    return [
        'inline_keyboard' => [
            [['text' => '🎥 دانلود ویدیو', 'callback_data' => 'menu_download'],
             ['text' => '🔍 جستجوی یوتیوب', 'callback_data' => 'menu_search']],
            [['text' => '⚙️ تنظیمات', 'callback_data' => 'menu_settings'], 
             ['text' => 'ℹ️ راهنما', 'callback_data' => 'menu_help']],
            [['text' => '📊 وضعیت سرور', 'callback_data' => 'menu_status']]
        ]
    ];
}

function getMainMenu() {
    return [
        'keyboard' => [
            [['text' => '🎥 دانلود ویدیو'], ['text' => '🔍 جستجوی یوتیوب']],
            [['text' => '⚙️ تنظیمات'], ['text' => 'ℹ️ راهنما']],
            [['text' => '📊 وضعیت سرور']]
        ],
        'resize_keyboard' => true,
        'persistent' => true
    ];
}

function getQualitySettingsMenu() {
    return [
        'inline_keyboard' => [
            [['text' => '✨ Best Quality', 'callback_data' => 'quality_best']],
            [['text' => '4K (2160p)', 'callback_data' => 'quality_2160'], 
             ['text' => '2K (1440p)', 'callback_data' => 'quality_1440']],
            [['text' => '1080p', 'callback_data' => 'quality_1080'], 
             ['text' => '720p', 'callback_data' => 'quality_720']],
            [['text' => '480p', 'callback_data' => 'quality_480'], 
             ['text' => '🎵 Audio Only', 'callback_data' => 'quality_audio']],
            [['text' => '🔙 بازگشت', 'callback_data' => 'settings_back']]
        ]
    ];
}

function getSubtitleSettingsMenu($currentSubs) {
    $subsStatus = $currentSubs === 'yes' ? '✅ فعال' : '❌ غیرفعال';
    
    return [
        'inline_keyboard' => [
            [['text' => "زیرنویس: {$subsStatus}", 'callback_data' => 'toggle_subs']],
            [['text' => '🔙 بازگشت به تنظیمات', 'callback_data' => 'settings_main']]
        ]
    ];
}

function getSettingsMainMenu() {
    return [
        'inline_keyboard' => [
            [['text' => '🎬 کیفیت ویدیو', 'callback_data' => 'settings_quality']],
            [['text' => '📝 تنظیمات زیرنویس', 'callback_data' => 'settings_subs']],
            [['text' => '🔙 بستن منو', 'callback_data' => 'settings_close']]
        ]
    ];
}

function getConfirmKeyboard($quality, $subs) {
    $qualityNames = [
        'best' => '✨ Best Quality',
        '2160' => '4K (2160p)',
        '1440' => '2K (1440p)',
        '1080' => 'Full HD (1080p)',
        '720' => 'HD (720p)',
        '480' => 'SD (480p)',
        'audio' => '🎵 Audio Only'
    ];
    
    $friendlyQuality = $qualityNames[$quality] ?? 'Best';
    $subsStatus = $subs === 'yes' ? '✅ فعال' : '❌ غیرفعال';
    
    return [
        'inline_keyboard' => [
            [['text' => '✅ تأیید و شروع دانلود', 'callback_data' => 'confirm_download']],
            [['text' => '❌ لغو', 'callback_data' => 'cancel_download']],
            [['text' => '⚙️ تغییر تنظیمات', 'callback_data' => 'menu_settings']]
        ]
    ];
}

// ══════════════════════════════════════════════════════════
// ۱۰. تنظیمات کاربر
// ══════════════════════════════════════════════════════════

function getUserSettings($chatId) {
    $db = new SQLite3(DB_FILE);
    $stmt = $db->prepare("SELECT quality, subtitles FROM user_settings WHERE chat_id = :chat_id");
    $stmt->bindValue(':chat_id', $chatId, SQLITE3_TEXT);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    
    if ($row) {
        return $row;
    }
    
    return [
        'quality' => 'best',
        'subtitles' => 'no'
    ];
}

function saveUserSettings($chatId, $quality, $subtitles) {
    $db = new SQLite3(DB_FILE);
    $stmt = $db->prepare("INSERT OR REPLACE INTO user_settings (chat_id, quality, subtitles) 
                          VALUES (:chat_id, :quality, :subtitles)");
    $stmt->bindValue(':chat_id', $chatId, SQLITE3_TEXT);
    $stmt->bindValue(':quality', $quality, SQLITE3_TEXT);
    $stmt->bindValue(':subtitles', $subtitles, SQLITE3_TEXT);
    $stmt->execute();
}

// ══════════════════════════════════════════════════════════
// ۱۱. پردازش پیام‌های ورودی
// ══════════════════════════════════════════════════════════

function processMessage($message, $db) {
    $chatId = $message['chat']['id'] ?? null;
    $text = $message['text'] ?? '';
    
    if (!$chatId) return;
    
    // ── دستور /start ──────────────────────────────
    if (strpos($text, '/start') === 0) {
        $welcomeText = "🎬 *سلام! به ربات دانلودر یوتیوب خوش آمدید!*\n\n" .
                       "▫️ دانلود ویدیو با کیفیت 4K تا 480p\n" .
                       "▫️ دانلود فقط صدا (MP3)\n" .
                       "▫️ زیرنویس فارسی و انگلیسی\n" .
                       "▫️ کاملاً رایگان و بدون قطعی\n\n" .
                       "🔗 *قدرت گرفته از GitHub Actions*\n\n" .
                       "👇 یکی از گزینه‌های زیر را انتخاب کنید:";
        
        sendMessage($chatId, $welcomeText, json_encode(getStartMenu()));
        return;
    }
    
    // ── دکمه‌های منو ──────────────────────────────
    if ($text === '🎥 دانلود ویدیو') {
        sendMessage($chatId, "🔗 *لینک ویدیوی یوتیوب را ارسال کنید:*\n\n" .
                   "_مثال: https://youtu.be/abc123def45_\n\n" .
                   "📌 _از شورت‌ویدیوها هم پشتیبانی می‌شود._");
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
    
    if ($text === 'ℹ️ راهنما' || strpos($text, '/help') === 0) {
        $helpText = "📖 *راهنمای ربات*\n\n" .
                    "🔸 *دانلود ویدیو:*\n" .
                    "لینک یوتیوب را ارسال کنید. سپس با دکمه تأیید، دانلود شروع می‌شود.\n\n" .
                    "🔸 *تنظیم کیفیت:*\n" .
                    "⚙️ تنظیمات > 🎬 کیفیت ویدیو\n\n" .
                    "🔸 *زیرنویس:*\n" .
                    "⚙️ تنظیمات > 📝 تنظیمات زیرنویس\n\n" .
                    "🔸 *بررسی وضعیت:*\n" .
                    "بعد از دانلود، دکمه بررسی وضعیت را بزنید.\n\n" .
                    "🔸 *محدودیت:*\n" .
                    "هر ۳ دقیقه یک درخواست.\n" .
                    "بررسی وضعیت: هر ۲ دقیقه یکبار.";
        
        sendMessage($chatId, $helpText);
        return;
    }
    
    if ($text === '📊 وضعیت سرور') {
        $db_check = new SQLite3(DB_FILE);
        $count = $db_check->querySingle("SELECT COUNT(*) FROM rate_limits");
        $remaining = getRemainingTime($db, $chatId);
        
        $statusText = "📊 *وضعیت سرور*\n\n" .
                     "✅ *سرویس:* فعال\n" .
                     "🔄 *درخواست‌های ثبت‌شده:* {$count}\n" .
                     "⏱ *درخواست بعدی شما:* " . 
                     ($remaining > 0 ? gmdate("i:s", $remaining) : "آماده ✅") . "\n" .
                     "🔗 *قدرت گرفته از:* GitHub Actions";
        
        sendMessage($chatId, $statusText);
        return;
    }
    
    
        // ── دکمه جستجو ──────────────────────────────
    if ($text === '🔍 جستجوی یوتیوب') {
        sendMessage($chatId, "🔍 *جستجوی یوتیوب*\n\n" .
                   "لطفاً عبارت مورد نظر خود را وارد کنید:\n\n" .
                   "_مثال: آهنگ جدید محسن یگانه_\n" .
                   "_مثال: آموزش PHP_\n" .
                   "_مثال: Avengers trailer_");
        return;
    }
    
    // ── پردازش لینک‌های یوتیوب ──────────────────
    $youtubeUrls = extractYoutubeUrls($text);
    
    if (!empty($youtubeUrls)) {
        if (isRateLimited($db, $chatId)) {
            $remaining = getRemainingTime($db, $chatId);
            $minutes = floor($remaining / 60);
            $seconds = $remaining % 60;
            
            sendMessage($chatId, "⏳ *لطفاً کمی صبر کنید!*\n\n" .
                       "شما می‌توانید {$minutes} دقیقه و {$seconds} ثانیه دیگر " .
                       "درخواست جدید ارسال کنید.\n\n" .
                       "_این محدودیت برای استفاده عادلانه است._");
            return;
        }
        
        $settings = getUserSettings($chatId);
        sendChatAction($chatId, 'typing');
        
        $qualityNames = [
            'best' => '✨ Best Quality',
            '2160' => '4K (2160p)',
            '1440' => '2K (1440p)',
            '1080' => 'Full HD (1080p)',
            '720' => 'HD (720p)',
            '480' => 'SD (480p)',
            'audio' => '🎵 Audio Only'
        ];
        
        $friendlyQuality = $qualityNames[$settings['quality']] ?? 'Best';
        $subsStatus = $settings['subtitles'] === 'yes' ? '✅ فعال' : '❌ غیرفعال';
        
        $youtubeUrl = $youtubeUrls[0];
        
        // ⭐ ذخیره موقت لینک برای تأیید
        $db_temp = new SQLite3(DB_FILE);
        $db_temp->exec("CREATE TABLE IF NOT EXISTS pending_downloads (
            chat_id TEXT PRIMARY KEY,
            youtube_url TEXT,
            quality TEXT,
            subtitles TEXT,
            created_at INTEGER
        )");
        
        $stmt = $db_temp->prepare("INSERT OR REPLACE INTO pending_downloads 
            (chat_id, youtube_url, quality, subtitles, created_at) 
            VALUES (:chat_id, :url, :quality, :subs, :time)");
        $stmt->bindValue(':chat_id', $chatId, SQLITE3_TEXT);
        $stmt->bindValue(':url', $youtubeUrl, SQLITE3_TEXT);
        $stmt->bindValue(':quality', $settings['quality'], SQLITE3_TEXT);
        $stmt->bindValue(':subs', $settings['subtitles'], SQLITE3_TEXT);
        $stmt->bindValue(':time', time(), SQLITE3_INTEGER);
        $stmt->execute();
        
        // ⭐ نمایش پیام تأیید
        $confirmText = "🎬 *آماده دانلود*\n\n" .
                      "🔗 *لینک:* `" . $youtubeUrl . "`\n" .
                      "🎬 *کیفیت:* {$friendlyQuality}\n" .
                      "📝 *زیرنویس:* {$subsStatus}\n\n" .
                      "👆 _لطفاً اطلاعات بالا را بررسی کنید._\n" .
                      "برای شروع دانلود، دکمه تأیید را بزنید:";
        
        sendMessage($chatId, $confirmText, json_encode(getConfirmKeyboard($settings['quality'], $settings['subtitles'])));
        return;
    }
    
    // ⭐ اگر لینک نیست، شاید عبارت جستجو باشد
    if (empty($youtubeUrls) && strlen($text) >= 2 && !str_starts_with($text, '/') 
        && $text !== '🎥 دانلود ویدیو' && $text !== '⚙️ تنظیمات' 
        && $text !== 'ℹ️ راهنما' && $text !== '📊 وضعیت سرور'
        && $text !== '🔍 جستجوی یوتیوب') {
        
        if (isRateLimited($db, $chatId)) {
            $remaining = getRemainingTime($db, $chatId);
            $minutes = floor($remaining / 60);
            $seconds = $remaining % 60;
            
            sendMessage($chatId, "⏳ *لطفاً کمی صبر کنید!*\n\n" .
                       "شما می‌توانید {$minutes} دقیقه و {$seconds} ثانیه دیگر " .
                       "درخواست جدید ارسال کنید.");
            return;
        }
        
        sendMessage($chatId, "🔍 *در حال جستجو برای:* \`{$text}\`\n\n⏳ لطفاً چند لحظه صبر کنید...");
        
        $result = dispatchSearchWorkflow($text, $chatId);
        
        if ($result['success']) {
            updateRateLimit($db, $chatId);
            sendMessage($chatId, "✅ *جستجو آغاز شد!*\n\n" .
                       "نتایج تا چند ثانیه دیگر ارسال می‌شود.\n" .
                       "🔗 _قدرت گرفته از YouTube API + yt-dlp_");
        } else {
            sendMessage($chatId, "❌ *خطا در جستجو!*\n\n" .
                       "متأسفانه مشکلی در ارتباط با سرور جستجو پیش آمد.\n" .
                       "لطفاً چند دقیقه دیگر دوباره تلاش کنید.\n\n" .
                       "_کد خطا: {$result['http_code']}_");
        }
        
        return;
    }
    
    // ── پیام پیش‌فرض ─────────────────────────────
    sendMessage($chatId, "📋 *لطفاً یکی از موارد زیر را انتخاب کنید:*\n\n" .
               "• یک لینک یوتیوب ارسال کنید\n" .
               "• عبارت جستجو بنویسید\n" .
               "• از دکمه‌های منو استفاده کنید\n" .
               "• دستور /help را برای راهنمایی بفرستید", 
               json_encode(getMainMenu()));
}

// ══════════════════════════════════════════════════════════
// ۱۲. پردازش Callback Query
// ══════════════════════════════════════════════════════════

function processCallbackQuery($callbackQuery, $db) {
    $callbackId = $callbackQuery['id'] ?? null;
    $chatId = $callbackQuery['from']['id'] ?? null;
    $data = $callbackQuery['data'] ?? '';
    $messageId = $callbackQuery['message']['message_id'] ?? null;
    
    if (!$callbackId || !$chatId || !$data) return;
    
    // ⭐ دکمه تأیید دانلود
    if ($data === 'confirm_download') {
        // دریافت اطلاعات از pending_downloads
        $db_temp = new SQLite3(DB_FILE);
        $stmt = $db_temp->prepare("SELECT youtube_url, quality, subtitles FROM pending_downloads WHERE chat_id = :chat_id");
        $stmt->bindValue(':chat_id', $chatId, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        
        if (!$row || !$row['youtube_url']) {
            answerCallbackQuery($callbackId, '⚠️ لینک منقضی شده. لطفاً دوباره ارسال کنید.', true);
            return;
        }
        
        // حذف از pending
        $stmt = $db_temp->prepare("DELETE FROM pending_downloads WHERE chat_id = :chat_id");
        $stmt->bindValue(':chat_id', $chatId, SQLITE3_TEXT);
        $stmt->execute();
        
        answerCallbackQuery($callbackId, '🔄 در حال ارسال به سرور...', false);
        
        // ⭐ Progress Indicator: ویرایش پیام
        editMessageText($chatId, $messageId, "⏳ *در حال اتصال به سرور دانلود...*");
        sleep(1);
        
        $result = dispatchGitHubWorkflow(
            $row['youtube_url'],
            $chatId,
            $row['quality'],
            $row['subtitles']
        );
        
        if ($result['success']) {
            updateRateLimit($db, $chatId);
            
            editMessageText($chatId, $messageId, "✅ *دانلود با موفقیت شروع شد!*");
            
            $statusKeyboard = [
                'inline_keyboard' => [
                    [['text' => '🔄 بررسی وضعیت دانلود', 'callback_data' => 'check_status']]
                ]
            ];
            
            $qualityNames = [
                'best' => 'Best Quality',
                '2160' => '4K',
                '1440' => '2K',
                '1080' => '1080p',
                '720' => '720p',
                '480' => '480p',
                'audio' => 'Audio'
            ];
            
            sendMessage($chatId, "🚀 *دانلود شروع شد!*\n\n" .
                       "🎬 کیفیت: " . ($qualityNames[$row['quality']] ?? 'Best') . "\n" .
                       "📝 زیرنویس: " . ($row['subtitles'] === 'yes' ? 'فعال' : 'غیرفعال') . "\n\n" .
                       "⏱ _زمان تقریبی: ۲ تا ۵ دقیقه_\n\n" .
                       "👇 برای بررسی وضعیت، دکمه زیر را بزنید:",
                       json_encode($statusKeyboard));
        } else {
            editMessageText($chatId, $messageId, "❌ *خطا در اتصال به سرور!*\n\n" .
                       "کد خطا: {$result['http_code']}\n" .
                       "لطفاً چند دقیقه دیگر دوباره تلاش کنید.");
        }
        return;
    }
    
    // ⭐ دکمه لغو دانلود
    if ($data === 'cancel_download') {
        $db_temp = new SQLite3(DB_FILE);
        $stmt = $db_temp->prepare("DELETE FROM pending_downloads WHERE chat_id = :chat_id");
        $stmt->bindValue(':chat_id', $chatId, SQLITE3_TEXT);
        $stmt->execute();
        
        answerCallbackQuery($callbackId, '❌ دانلود لغو شد.', false);
        editMessageText($chatId, $messageId, "❌ *دانلود لغو شد.*\n\nبرای شروع مجدد، یک لینک جدید ارسال کنید.");
        return;
    }
    
    // ⭐ دکمه بررسی وضعیت
    if ($data === 'check_status') {
        $checkStatus = canCheckStatus($db, $chatId);
        
        if (!$checkStatus['can_check']) {
            $remaining = $checkStatus['remaining'];
            $minutes = floor($remaining / 60);
            $seconds = $remaining % 60;
            
            answerCallbackQuery($callbackId, "⏳ {$minutes} دقیقه و {$seconds} ثانیه دیگر صبر کنید...", true);
            return;
        }
        
        answerCallbackQuery($callbackId, '🔍 در حال بررسی...', false);
        
        // ⭐ بررسی مستقیم status.json
        $readyFolders = checkReadyFiles($chatId);
        
        if (!empty($readyFolders)) {
            foreach ($readyFolders as $folder) {
                $files = $folder['files'];
                $fileCount = count($files);
                
                // ارسال پیام
                $message = "✅ *دانلود شما آماده است!*\n\n" .
                           "📁 *پوشه:* `{$folder['folder']}`\n" .
                           "📦 *تعداد فایل:* {$fileCount}\n\n";
                
                $inlineKeyboard = ['inline_keyboard' => []];
                
                if ($fileCount === 1) {
                    $message .= "برای دانلود روی دکمه زیر کلیک کنید:\n\n";
                    $inlineKeyboard['inline_keyboard'][] = [
                        ['text' => '📥 دانلود فایل', 'url' => $files[0]['url']]
                    ];
                } else {
                    $message .= "⚠️ فایل به {$fileCount} بخش تقسیم شده.\n_همه بخش‌ها را دانلود کنید._\n\n";
                    $partNumber = 1;
                    foreach ($files as $file) {
                        $inlineKeyboard['inline_keyboard'][] = [
                            ['text' => "📦 پارت {$partNumber}", 'url' => $file['url']]
                        ];
                        $partNumber++;
                    }
                }
                
                $message .= "⏱ _لینک‌ها تا ۵ دقیقه معتبر هستند._";
                
                sendMessage($chatId, $message, json_encode($inlineKeyboard));
            }
            
            updateRateLimit($db, $chatId);
        } else {
            // ارسال درخواست به کاربر که هنوز آماده نیست
            sendMessage($chatId, "⏳ *هنوز آماده نیست!*\n\n" .
                       "دانلود شما در حال انجام است.\n" .
                       "لطفاً ۲ دقیقه دیگر دکمه بررسی وضعیت را بزنید.\n\n" .
                       "⏱ _زمان تقریبی باقی‌مانده: ۱-۳ دقیقه_");
            
            updateRateLimit($db, $chatId);
        }
        return;
    }
    
    // ⭐ منوهای inline از /start
    if ($data === 'menu_download') {
        answerCallbackQuery($callbackId);
        sendMessage($chatId, "🔗 *لینک ویدیوی یوتیوب را ارسال کنید:*\n\n" .
                   "_مثال: https://youtu.be/abc123def45_\n\n" .
                   "📌 _از شورت‌ویدیوها هم پشتیبانی می‌شود._");
        return;
    }
    
    if ($data === 'menu_settings') {
        answerCallbackQuery($callbackId);
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
    
    if ($data === 'menu_help') {
        answerCallbackQuery($callbackId);
        $helpText = "📖 *راهنمای ربات*\n\n" .
                    "🔸 *دانلود ویدیو:*\n" .
                    "لینک یوتیوب را ارسال کنید. سپس با دکمه تأیید، دانلود شروع می‌شود.\n\n" .
                    "🔸 *تنظیم کیفیت:*\n" .
                    "⚙️ تنظیمات > 🎬 کیفیت ویدیو\n\n" .
                    "🔸 *زیرنویس:*\n" .
                    "⚙️ تنظیمات > 📝 تنظیمات زیرنویس\n\n" .
                    "🔸 *بررسی وضعیت:*\n" .
                    "بعد از دانلود، دکمه بررسی وضعیت را بزنید.\n\n" .
                    "🔸 *محدودیت:*\n" .
                    "هر ۳ دقیقه یک درخواست.\n" .
                    "بررسی وضعیت: هر ۲ دقیقه یکبار.";
        
        sendMessage($chatId, $helpText);
        return;
    }
    
    if ($data === 'menu_status') {
        answerCallbackQuery($callbackId);
        $db_check = new SQLite3(DB_FILE);
        $count = $db_check->querySingle("SELECT COUNT(*) FROM rate_limits");
        $remaining = getRemainingTime($db, $chatId);
        
        $statusText = "📊 *وضعیت سرور*\n\n" .
                     "✅ *سرویس:* فعال\n" .
                     "🔄 *درخواست‌های ثبت‌شده:* {$count}\n" .
                     "⏱ *درخواست بعدی شما:* " . 
                     ($remaining > 0 ? gmdate("i:s", $remaining) : "آماده ✅") . "\n" .
                     "🔗 *قدرت گرفته از:* GitHub Actions";
        
        sendMessage($chatId, $statusText);
        return;
    }
    
        if ($data === 'menu_search') {
        answerCallbackQuery($callbackId);
        sendMessage($chatId, "🔍 *جستجوی یوتیوب*\n\n" .
                   "لطفاً عبارت مورد نظر خود را وارد کنید:\n\n" .
                   "_مثال: آهنگ جدید محسن یگانه_");
        return;
    }
    
    
        // ⭐ دانلود از نتایج جستجو
    if (strpos($data, 'dl_') === 0) {
        $videoId = str_replace('dl_', '', $data);
        $youtubeUrl = "https://www.youtube.com/watch?v={$videoId}";
        
        $settings = getUserSettings($chatId);
        
        $result = dispatchGitHubWorkflow(
            $youtubeUrl,
            $chatId,
            $settings['quality'],
            $settings['subtitles']
        );
        
        if ($result['success']) {
            updateRateLimit($db, $chatId);
            
            answerCallbackQuery($callbackId, '🚀 دانلود شروع شد!', false);
            
            $statusKeyboard = [
                'inline_keyboard' => [
                    [['text' => '🔄 بررسی وضعیت دانلود', 'callback_data' => 'check_status']]
                ]
            ];
            
            sendMessage($chatId, "🚀 *دانلود شروع شد!*\n\n" .
                       "🔗 \`{$youtubeUrl}\`\n\n" .
                       "⏱ _زمان تقریبی: ۲ تا ۵ دقیقه_\n\n" .
                       "👇 برای بررسی وضعیت، دکمه زیر را بزنید:",
                       json_encode($statusKeyboard));
        } else {
            answerCallbackQuery($callbackId, '❌ خطا در شروع دانلود. لطفاً دوباره تلاش کنید.', true);
        }
        return;
    }
    
    // بقیه callbackها
    answerCallbackQuery($callbackId);
    
    $settings = getUserSettings($chatId);
    
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
    
    if ($data === 'settings_quality') {
        callBaleAPI('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => '🎬 *کیفیت ویدیوی خود را انتخاب کنید:*',
            'reply_markup' => json_encode(getQualitySettingsMenu())
        ]);
        return;
    }
    
    if ($data === 'settings_subs') {
        callBaleAPI('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => '📝 *تنظیمات زیرنویس:*',
            'reply_markup' => json_encode(getSubtitleSettingsMenu($settings['subtitles']))
        ]);
        return;
    }
    
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
// ۱۴. روتر اصلی
// ══════════════════════════════════════════════════════════

$db = initDatabase();
$requestMethod = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

if ($requestMethod === 'POST' && $input && isset($input['update_id'])) {
    // Webhook از بله
    $update = $input;
    
    if (isset($update['update_id'])) {
        $stmt = $db->prepare("SELECT update_id FROM processed_updates WHERE update_id = :update_id");
        $stmt->bindValue(':update_id', $update['update_id'], SQLITE3_INTEGER);
        $result = $stmt->execute();
        
        if (!$result->fetchArray()) {
            $stmt = $db->prepare("INSERT INTO processed_updates (update_id) VALUES (:update_id)");
            $stmt->bindValue(':update_id', $update['update_id'], SQLITE3_INTEGER);
            $stmt->execute();
            
            if (isset($update['message'])) {
                processMessage($update['message'], $db);
            }
            
            if (isset($update['callback_query'])) {
                processCallbackQuery($update['callback_query'], $db);
            }
        }
    }
    
    sendSuccessResponse();
    
} else {
    if ($requestMethod === 'GET') {
        echo "<h1>Bale YouTube Downloader Gateway V3</h1>";
        echo "<p>✅ Gateway is running!</p>";
        echo "<p>New Features:</p>";
        echo "<ul>";
        echo "<li>✅ Confirm button before download</li>";
        echo "<li>✅ Progress indicator (live text)</li>";
        echo "<li>✅ Status check every 120 seconds</li>";
        echo "<li>✅ Direct status.json check</li>";
        echo "<li>✅ Inline start menu</li>";
        echo "<li>✅ Shorts support</li>";
        echo "</ul>";
        echo "<p>Repository: <a href='https://github.com/" . GITHUB_OWNER . "/" . GITHUB_REPO . "'>" . GITHUB_REPO . "</a></p>";
    } else {
        sendErrorResponse('Invalid Request');
    }
}
