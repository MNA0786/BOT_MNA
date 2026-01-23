<?php
/**
 * ==========================================================
 * TELEGRAM MOVIE BOT - PRODUCTION READY FOR RENDER.COM
 * ==========================================================
 * Combines best features from all versions:
 * 1. index.php (Render) - Production architecture
 * 2. index23thOctober2025.php - Advanced features
 * 3. index29thSeptember2025.php - Stable functions  
 * 4. index25thAugust2025.php - Special features
 * ==========================================================
 */

// ==================== ERROR HANDLING ======================
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/logs/error.log');
error_reporting(E_ALL);
date_default_timezone_set('Asia/Kolkata');

// ==================== LOGGING FUNCTION =====================
function log_message($message, $type = 'INFO') {
    $log_file = __DIR__ . '/logs/bot_' . date('Y-m-d') . '.log';
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "[$timestamp] [$type] $message" . PHP_EOL;
    file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
}

// ==================== SECURITY CHECK =======================
if (php_sapi_name() === 'cli' && !isset($_GET['setwebhook']) && !isset($_GET['test'])) {
    die("CLI access not allowed");
}

// ==================== ENVIRONMENT CONFIG ===================
// Render.com se environment variables use karo (index.php style)
$BOT_TOKEN = getenv('BOT_TOKEN') ?: 'YOUR_BOT_TOKEN_HERE';
$REQUEST_GROUP_ID = getenv('REQUEST_GROUP_ID') ?: '-100XXXXXXXXXX';
$CHANNELS_STRING = getenv('CHANNELS') ?: '-1003251791991,-1002337293281,-1003181705395,-1002831605258,-1002964109368,-1003614546520';

// Parse channels from environment (index.php style)
$CHANNELS = explode(',', $CHANNELS_STRING);
$API_URL = 'https://api.telegram.org/bot' . $BOT_TOKEN . '/';

// Additional config from index23thOctober2025.php
define('GROUP_CHANNEL_ID', '-1003083386043');
define('BACKUP_CHANNEL_ID', '-1002964109368');
define('OWNER_ID', '1080317415');

// ==================== FILE PATHS ===========================
define('CSV_FILE', __DIR__ . '/movies.csv');
define('USERS_JSON', __DIR__ . '/users.json');
define('STATS_FILE', __DIR__ . '/bot_stats.json');
define('UPLOADS_DIR', __DIR__ . '/uploads/');
define('BACKUP_DIR', __DIR__ . '/backups/');
define('LOGS_DIR', __DIR__ . '/logs/');

// ==================== CONSTANTS ============================
define('USER_COOLDOWN', 20); // seconds (index.php style)
define('PER_PAGE', 5); // items per page
define('CACHE_EXPIRY', 300); // 5 minutes cache (index25thAug style)

// ==================== ADMIN USERS =========================
$ADMINS = [1080317415]; // Apne admin IDs yahan add karo

// ==================== MAINTENANCE MODE ====================
// From index23thOctober2025.php
$MAINTENANCE_MODE = false; // Set to true for maintenance

// ==================== GLOBAL CACHES =======================
// From index23thOct and index25thAug
$movie_messages = array();
$movie_cache = array();
$waiting_users = array();

// ==================== INITIAL SETUP =======================
function init_storage() {
    // CSV file initialize karo (index.php style)
    if (!file_exists(CSV_FILE)) {
        file_put_contents(CSV_FILE, "movie_name,message_id,channel_id,added_at\n");
        chmod(CSV_FILE, 0644);
        log_message("CSV file created");
    }
    
    // Users JSON initialize karo (index.php + index25thAug style)
    if (!file_exists(USERS_JSON)) {
        $default_data = [
            'users' => [],
            'stats' => [
                'total_searches' => 0,
                'total_users' => 0,
                'last_updated' => null
            ],
            'message_logs' => [],
            'total_requests' => 0
        ];
        file_put_contents(USERS_JSON, json_encode($default_data, JSON_PRETTY_PRINT));
        chmod(USERS_JSON, 0644);
        log_message("Users JSON created");
    }
    
    // Stats file initialize (index25thAug style)
    if (!file_exists(STATS_FILE)) {
        $default_stats = [
            'total_movies' => 0,
            'total_users' => 0,
            'total_searches' => 0,
            'last_updated' => date('Y-m-d H:i:s')
        ];
        file_put_contents(STATS_FILE, json_encode($default_stats, JSON_PRETTY_PRINT));
        chmod(STATS_FILE, 0644);
    }
    
    // Directories create karo
    $dirs = [UPLOADS_DIR, LOGS_DIR, BACKUP_DIR];
    foreach ($dirs as $dir) {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
            chmod($dir, 0755);
        }
    }
}

// ==================== TELEGRAM API ========================
function tg($method, $data = []) {
    global $API_URL;
    $url = $API_URL . $method;
    $options = [
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/json\r\n",
            'content' => json_encode($data)
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false
        ]
    ];
    
    try {
        $context = stream_context_create($options);
        $response = file_get_contents($url, false, $context);
        log_message("API Call: $method - " . substr(json_encode($data), 0, 200));
        return $response ? json_decode($response, true) : false;
    } catch (Exception $e) {
        log_message("API Error: " . $e->getMessage(), 'ERROR');
        return false;
    }
}

// Additional API functions from index23thOct
function sendMessage($chat_id, $text, $reply_markup = null, $parse_mode = null) {
    $data = [
        'chat_id' => $chat_id,
        'text' => $text
    ];
    if ($reply_markup) $data['reply_markup'] = json_encode($reply_markup);
    if ($parse_mode) $data['parse_mode'] = $parse_mode;
    return tg('sendMessage', $data);
}

function copyMessage($chat_id, $from_chat_id, $message_id) {
    return tg('copyMessage', [
        'chat_id' => $chat_id,
        'from_chat_id' => $from_chat_id,
        'message_id' => $message_id
    ]);
}

function forwardMessage($chat_id, $from_chat_id, $message_id) {
    return tg('forwardMessage', [
        'chat_id' => $chat_id,
        'from_chat_id' => $from_chat_id,
        'message_id' => $message_id
    ]);
}

function answerCallbackQuery($callback_query_id, $text = null) {
    $data = ['callback_query_id' => $callback_query_id];
    if ($text) $data['text'] = $text;
    return tg('answerCallbackQuery', $data);
}

function editMessage($chat_id, $message_obj, $new_text, $reply_markup = null) {
    if (is_array($message_obj) && isset($message_obj['message_id'])) {
        $data = [
            'chat_id' => $chat_id,
            'message_id' => $message_obj['message_id'],
            'text' => $new_text
        ];
        if ($reply_markup) $data['reply_markup'] = json_encode($reply_markup);
        return tg('editMessageText', $data);
    }
    return false;
}

// ==================== CSV FUNCTIONS ========================
// From index.php with improvements from index23thOct
function add_movie($movie_name, $message_id, $channel_id) {
    init_storage();
    
    // Duplicate check (index.php style)
    $rows = file(CSV_FILE, FILE_IGNORE_NEW_LINES);
    foreach ($rows as $r) {
        if (strpos($r, ',' . $message_id . ',') !== false) {
            return false;
        }
    }
    
    $fp = fopen(CSV_FILE, 'a');
    fputcsv($fp, [$movie_name, $message_id, $channel_id, date('Y-m-d H:i:s')]);
    fclose($fp);
    
    // Update cache (index23thOct style)
    global $movie_messages, $movie_cache;
    $movie = strtolower(trim($movie_name));
    $item = [
        'movie_name' => $movie_name,
        'message_id' => $message_id,
        'channel_id' => $channel_id,
        'added_at' => date('Y-m-d H:i:s')
    ];
    if (!isset($movie_messages[$movie])) $movie_messages[$movie] = [];
    $movie_messages[$movie][] = $item;
    $movie_cache = []; // Clear cache
    
    // Notify waiting users (index23thOct style)
    global $waiting_users;
    $query_lower = strtolower(trim($movie_name));
    foreach ($waiting_users as $query => $users) {
        if (strpos($query_lower, $query) !== false) {
            foreach ($users as $user_data) {
                list($user_chat_id, $user_id) = $user_data;
                deliver_item_to_chat($user_chat_id, $item);
                sendMessage($user_chat_id, "✅ '$movie_name' ab channel me add ho gaya!");
            }
            unset($waiting_users[$query]);
        }
    }
    
    // Update stats
    update_stats('total_movies', 1);
    
    log_message("Movie added: $movie_name (ID: $message_id, Channel: $channel_id)");
    return true;
}

function get_all_movies() {
    init_storage();
    $movies = [];
    if (($h = fopen(CSV_FILE, 'r')) !== false) {
        fgetcsv($h); // Header skip
        while (($d = fgetcsv($h)) !== false) {
            if (count($d) >= 3) {
                $movies[] = [
                    'movie_name' => $d[0],
                    'message_id' => $d[1],
                    'channel_id' => $d[2],
                    'added_at' => $d[3] ?? ''
                ];
            }
        }
        fclose($h);
    }
    return $movies;
}

// Smart search from index25thAug with improvements
function smart_search($query) {
    global $movie_messages;
    $query_lower = strtolower(trim($query));
    $results = array();
    
    if (empty($movie_messages)) {
        // Load movies if cache empty
        $movies = get_all_movies();
        foreach ($movies as $movie) {
            $movie_key = strtolower($movie['movie_name']);
            if (!isset($movie_messages[$movie_key])) $movie_messages[$movie_key] = [];
            $movie_messages[$movie_key][] = $movie;
        }
    }
    
    foreach ($movie_messages as $movie => $entries) {
        $score = 0;
        // EXACT MATCH - Highest priority
        if ($movie == $query_lower) {
            $score = 100;
        }
        // PARTIAL MATCH - Medium priority
        elseif (strpos($movie, $query_lower) !== false) {
            $score = 80 - (strlen($movie) - strlen($query_lower));
        }
        // SIMILARITY MATCH - Fuzzy search (index25thAug style)
        else {
            similar_text($movie, $query_lower, $similarity);
            if ($similarity > 60) {
                $score = $similarity;
            }
        }
        
        if ($score > 0) {
            $results[$movie] = [
                'score' => $score,
                'count' => count($entries),
                'entries' => $entries
            ];
        }
    }
    
    // SORT BY RELEVANCE SCORE
    uasort($results, function($a, $b) {
        return $b['score'] - $a['score'];
    });
    
    return array_slice($results, 0, 10);
}

function search_movie($query) {
    $query = strtolower(trim($query));
    $results = [];
    
    // Use smart search
    $smart_results = smart_search($query);
    
    foreach ($smart_results as $movie_data) {
        $results = array_merge($results, $movie_data['entries']);
    }
    
    // Also do simple search as fallback
    if (empty($results)) {
        foreach (get_all_movies() as $movie) {
            if (strpos(strtolower($movie['movie_name']), $query) !== false) {
                $results[] = $movie;
            }
        }
    }
    
    return $results;
}

// ==================== STATS MANAGEMENT ====================
// From index25thAug + index.php
function update_stats($field, $increment = 1) {
    if (!file_exists(STATS_FILE)) return;
    $stats = json_decode(file_get_contents(STATS_FILE), true);
    $stats[$field] = ($stats[$field] ?? 0) + $increment;
    $stats['last_updated'] = date('Y-m-d H:i:s');
    file_put_contents(STATS_FILE, json_encode($stats, JSON_PRETTY_PRINT));
}

function get_stats() {
    if (!file_exists(STATS_FILE)) return [];
    return json_decode(file_get_contents(STATS_FILE), true);
}

// ==================== FLOOD CONTROL ========================
// From index.php
function is_flood($user_id) {
    $flood_file = sys_get_temp_dir() . '/tgflood_' . $user_id;
    
    if (file_exists($flood_file)) {
        $last_time = file_get_contents($flood_file);
        if (time() - (int)$last_time < USER_COOLDOWN) {
            return true;
        }
    }
    
    file_put_contents($flood_file, time());
    return false;
}

// ==================== USER MANAGEMENT ======================
// From index.php + index25thAug points system
function update_user($user_data, $action = null) {
    $users = json_decode(file_get_contents(USERS_JSON), true);
    $user_id = $user_data['id'];
    
    if (!isset($users['users'][$user_id])) {
        $users['users'][$user_id] = [
            'first_name' => $user_data['first_name'] ?? '',
            'last_name' => $user_data['last_name'] ?? '',
            'username' => $user_data['username'] ?? '',
            'joined' => date('Y-m-d H:i:s'),
            'last_active' => date('Y-m-d H:i:s'),
            'points' => 0,
            'search_count' => 0
        ];
        $users['stats']['total_users'] = ($users['stats']['total_users'] ?? 0) + 1;
        update_stats('total_users', 1);
    } else {
        $users['users'][$user_id]['last_active'] = date('Y-m-d H:i:s');
    }
    
    // Update points based on action (index25thAug style)
    if ($action) {
        $points_map = [
            'search' => 1,
            'found_movie' => 5,
            'daily_login' => 10,
            'request_movie' => 2
        ];
        
        $users['users'][$user_id]['points'] += ($points_map[$action] ?? 0);
        
        if ($action == 'search') {
            $users['users'][$user_id]['search_count']++;
        }
    }
    
    file_put_contents(USERS_JSON, json_encode($users, JSON_PRETTY_PRINT));
}

// ==================== DELIVERY FUNCTIONS ====================
// Combined from index.php and index23thOct
function deliver_item_to_chat($chat_id, $item) {
    // Try copy first (hide sender - index.php style)
    if (!empty($item['message_id']) && !empty($item['channel_id'])) {
        $result = copyMessage($chat_id, $item['channel_id'], $item['message_id']);
        
        if ($result && $result['ok']) {
            return true;
        }
        
        // Fallback to forward (index23thOct style)
        forwardMessage($chat_id, $item['channel_id'], $item['message_id']);
        return true;
    }
    
    return false;
}

function deliver_movie($chat_id, $movie) {
    return deliver_item_to_chat($chat_id, $movie);
}

// ==================== PAGINATION SYSTEM ====================
// From index.php + index23thOct advanced features
function paginate_movies($page = 1) {
    $movies = get_all_movies();
    $total = count($movies);
    $total_pages = ceil($total / PER_PAGE);
    $page = max(1, min($page, $total_pages));
    $start = ($page - 1) * PER_PAGE;
    
    return [
        'movies' => array_slice($movies, $start, PER_PAGE),
        'total' => $total,
        'page' => $page,
        'total_pages' => $total_pages
    ];
}

// Advanced pagination from index23thOct
function forward_page_movies($chat_id, array $page_movies) {
    $total = count($page_movies);
    if ($total === 0) return;
    
    // Progress message bhejo (index23thOct style)
    $progress_msg = sendMessage($chat_id, "⏳ Forwarding {$total} movies...");
    
    $i = 1;
    $success_count = 0;
    
    foreach ($page_movies as $m) {
        $success = deliver_item_to_chat($chat_id, $m);
        if ($success) $success_count++;
        
        // Har 3 movies ke baad progress update karo
        if ($i % 3 === 0 && $progress_msg) {
            editMessage($chat_id, $progress_msg, "⏳ Forwarding... ({$i}/{$total})");
        }
        
        usleep(500000); // 0.5 second delay
        $i++;
    }
    
    // Final progress update
    if ($progress_msg) {
        editMessage($chat_id, $progress_msg, "✅ Successfully forwarded {$success_count}/{$total} movies");
    }
}

function build_totalupload_keyboard(int $page, int $total_pages): array {
    // Improved keyboard from index23thOct
    $kb = ['inline_keyboard' => []];
    
    // Navigation buttons - better spacing
    $nav_row = [];
    if ($page > 1) {
        $nav_row[] = ['text' => '⬅️ Previous', 'callback_data' => 'page:' . ($page - 1)];
    }
    
    // Page indicator as button (non-clickable)
    $nav_row[] = ['text' => "📄 $page/$total_pages", 'callback_data' => 'current_page'];
    
    if ($page < $total_pages) {
        $nav_row[] = ['text' => 'Next ➡️', 'callback_data' => 'page:' . ($page + 1)];
    }
    
    if (!empty($nav_row)) {
        $kb['inline_keyboard'][] = $nav_row;
    }
    
    // Action buttons - separate row
    $action_row = [];
    $action_row[] = ['text' => '🎬 Send This Page', 'callback_data' => 'send_page:' . $page];
    $action_row[] = ['text' => '🛑 Stop', 'callback_data' => 'stop_pagination'];
    
    $kb['inline_keyboard'][] = $action_row;
    
    // Quick jump buttons for first/last pages
    if ($total_pages > 5) {
        $jump_row = [];
        if ($page > 1) {
            $jump_row[] = ['text' => '⏮️ First', 'callback_data' => 'page:1'];
        }
        if ($page < $total_pages) {
            $jump_row[] = ['text' => 'Last ⏭️', 'callback_data' => 'page:' . $total_pages];
        }
        if (!empty($jump_row)) {
            $kb['inline_keyboard'][] = $jump_row;
        }
    }
    
    return $kb;
}

// ==================== MULTI-LANGUAGE SUPPORT ==============
// From index25thAug and index23thOct
function detect_language($text) {
    $hindi_keywords = ['फिल्म','मूवी','डाउनलोड','हिंदी','कैसे','क्या','है'];
    $english_keywords = ['movie','download','watch','print','how','what','is'];
    
    $hindi_count = 0;
    $english_count = 0;
    
    $text_lower = strtolower($text);
    
    foreach ($hindi_keywords as $keyword) {
        if (strpos($text, $keyword) !== false) $hindi_count++;
    }
    
    foreach ($english_keywords as $keyword) {
        if (strpos($text_lower, $keyword) !== false) $english_count++;
    }
    
    return $hindi_count > $english_count ? 'hindi' : 'english';
}

function send_multilingual_response($chat_id, $message_type, $language) {
    $responses = [
        'hindi' => [
            'welcome' => "🎬 स्वागत है! कौन सी मूवी चाहिए?",
            'found' => "✅ मूवी मिल गई! फॉरवर्ड कर रहा हूं...",
            'not_found' => "😔 यह मूवी अभी उपलब्ध नहीं है!\n\n📝 आप इसे रिक्वेस्ट कर सकते हैं: @EntertainmentTadka0786\n\n🔔 जब भी यह एड होगी, मैं ऑटोमेटिक भेज दूंगा!",
            'searching' => "🔍 ढूंढ रहा हूं... जरा वेट करो"
        ],
        'english' => [
            'welcome' => "🎬 Welcome! Which movie do you want?",
            'found' => "✅ Found it! Forwarding the movie...",
            'not_found' => "😔 This movie isn't available yet!\n\n📝 You can request it here: @EntertainmentTadka0786\n\n🔔 I'll send it automatically once it's added!",
            'searching' => "🔍 Searching... Please wait"
        ]
    ];
    
    if (isset($responses[$language][$message_type])) {
        sendMessage($chat_id, $responses[$language][$message_type]);
    }
}

// ==================== GROUP MESSAGE FILTER ================
// From index23thOct
function is_valid_movie_query($text) {
    $text = strtolower(trim($text));
    
    // Skip commands
    if (strpos($text, '/') === 0) {
        return true;
    }
    
    // Skip very short messages
    if (strlen($text) < 3) {
        return false;
    }
    
    // Common group chat phrases block karo
    $invalid_phrases = [
        'good morning', 'good night', 'hello', 'hi ', 'hey ', 'thank you', 'thanks',
        'welcome', 'bye', 'see you', 'ok ', 'okay', 'yes', 'no', 'maybe',
        'how are you', 'whats up', 'anyone', 'someone', 'everyone',
        'problem', 'issue', 'help', 'question', 'doubt', 'query'
    ];
    
    foreach ($invalid_phrases as $phrase) {
        if (strpos($text, $phrase) !== false) {
            return false;
        }
    }
    
    // Movie-like patterns allow karo
    $movie_patterns = [
        'movie', 'film', 'video', 'download', 'watch', 'hd', 'full', 'part',
        'series', 'episode', 'season', 'bollywood', 'hollywood'
    ];
    
    foreach ($movie_patterns as $pattern) {
        if (strpos($text, $pattern) !== false) {
            return true;
        }
    }
    
    // Agar koi specific movie jaisa lagta hai
    if (preg_match('/^[a-zA-Z0-9\s\-\.\,]{3,}$/', $text)) {
        return true;
    }
    
    return false;
}

// ==================== BACKUP SYSTEM =======================
// From index25thAug
function auto_backup() {
    $backup_files = [CSV_FILE, USERS_JSON, STATS_FILE];
    $backup_dir = BACKUP_DIR . date('Y-m-d');
    
    if (!file_exists($backup_dir)) {
        mkdir($backup_dir, 0777, true);
    }
    
    foreach ($backup_files as $file) {
        if (file_exists($file)) {
            copy($file, $backup_dir . '/' . basename($file) . '.bak');
        }
    }
    
    // Keep only last 7 backups
    $old_backups = glob(BACKUP_DIR . '*', GLOB_ONLYDIR);
    if (count($old_backups) > 7) {
        usort($old_backups, function($a, $b) {
            return filemtime($a) - filemtime($b);
        });
        
        foreach (array_slice($old_backups, 0, count($old_backups) - 7) as $dir) {
            $files = glob($dir . '/*');
            foreach ($files as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }
    }
    
    log_message("Auto-backup completed for " . date('Y-m-d'));
}

// ==================== DAILY DIGEST ========================
// From index25thAug
function send_daily_digest() {
    $yesterday = date('d-m-Y', strtotime('-1 day'));
    $yesterday_movies = [];
    
    $handle = fopen(CSV_FILE, "r");
    if ($handle !== false) {
        fgetcsv($handle); // Skip header
        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) >= 4 && $row[3] == $yesterday) {
                $yesterday_movies[] = $row[0];
            }
        }
        fclose($handle);
    }
    
    if (!empty($yesterday_movies)) {
        $users_data = json_decode(file_get_contents(USERS_JSON), true);
        foreach ($users_data['users'] as $user_id => $user_data) {
            $msg = "📅 <b>Daily Movie Digest</b>\n\n";
            $msg .= "📢 Join our channel: @EntertainmentTadka786\n\n";
            $msg .= "🎬 Yesterday's Uploads (" . $yesterday . "):\n";
            
            foreach (array_slice($yesterday_movies, 0, 10) as $movie) {
                $msg .= "• " . htmlspecialchars($movie) . "\n";
            }
            
            if (count($yesterday_movies) > 10) {
                $msg .= "• ... and " . (count($yesterday_movies) - 10) . " more\n";
            }
            
            $msg .= "\n🔥 Total: " . count($yesterday_movies) . " movies";
            
            sendMessage($user_id, $msg, null, 'HTML');
        }
        
        log_message("Daily digest sent to " . count($users_data['users']) . " users");
    }
}

// ==================== COMMAND HANDLERS ====================
function handle_start($chat_id, $user_id) {
    $welcome = "🎬 <b>Welcome to Entertainment Tadka Bot!</b>\n\n";
    $welcome .= "📢 <b>How to use:</b>\n";
    $welcome .= "• Simply type any movie name to search\n";
    $welcome .= "• Use /totalupload to see all movies\n";
    $welcome .= "• Partial names also work\n\n";
    $welcome .= "🔍 <b>Examples:</b>\n";
    $welcome .= "• kgf\n• pushpa\n• avengers\n• hindi movie\n\n";
    $welcome .= "❌ <b>Don't type:</b>\n";
    $welcome .= "• Technical queries\n";
    $welcome .= "• Player instructions\n\n";
    $welcome .= "📢 Join: @EntertainmentTadka786\n";
    $welcome .= "💬 Help: @EntertainmentTadka0786";
    
    sendMessage($chat_id, $welcome, null, 'HTML');
    update_user(['id' => $user_id], 'daily_login');
}

function handle_search($chat_id, $query, $user_id) {
    global $waiting_users;
    
    // Flood control
    if (is_flood($user_id)) {
        sendMessage($chat_id, '⏳ Please wait before sending another request.', null, 'HTML');
        return;
    }
    
    // Minimum length check
    if (strlen(trim($query)) < 2) {
        sendMessage($chat_id, "❌ Please enter at least 2 characters for search");
        return;
    }
    
    // Language detection and searching message
    $lang = detect_language($query);
    send_multilingual_response($chat_id, 'searching', $lang);
    
    $results = search_movie($query);
    
    if (empty($results)) {
        send_multilingual_response($chat_id, 'not_found', $lang);
        
        // Add to waiting list (index23thOct style)
        $query_lower = strtolower(trim($query));
        if (!isset($waiting_users[$query_lower])) {
            $waiting_users[$query_lower] = [];
        }
        $waiting_users[$query_lower][] = [$chat_id, $user_id];
        
        update_user(['id' => $user_id], 'request_movie');
    } else {
        send_multilingual_response($chat_id, 'found', $lang);
        
        $count = count($results);
        sendMessage($chat_id, "✅ Found $count result(s) for: <b>" . htmlspecialchars($query) . "</b>", null, 'HTML');
        
        // Show top matches as buttons if many results (index25thAug style)
        if ($count > 3) {
            $smart_results = smart_search($query);
            $top_matches = array_slice(array_keys($smart_results), 0, 5);
            
            if (!empty($top_matches)) {
                $keyboard = ['inline_keyboard' => []];
                foreach ($top_matches as $movie) {
                    $keyboard['inline_keyboard'][] = [
                        ['text' => "🎬 " . ucwords($movie), 'callback_data' => 'search:' . $movie]
                    ];
                }
                sendMessage($chat_id, "🚀 Top matches:", $keyboard);
            }
        }
        
        // Deliver movies
        foreach ($results as $result) {
            deliver_item_to_chat($chat_id, $result);
            usleep(500000); // 0.5 second delay
        }
        
        update_user(['id' => $user_id], 'found_movie');
        update_stats('total_searches', 1);
    }
    
    update_user(['id' => $user_id], 'search');
}

function handle_totalupload($chat_id, $page = 1) {
    $data = paginate_movies($page);
    
    if (empty($data['movies'])) {
        sendMessage($chat_id, "📭 No movies found! Add some movies first.", null, 'HTML');
        return;
    }
    
    // Send current page movies with progress
    forward_page_movies($chat_id, $data['movies']);
    
    // Build message
    $msg = "📊 <b>Total Uploads</b>\n\n";
    $msg .= "🎬 Total Movies: <b>{$data['total']}</b>\n";
    $msg .= "📄 Page: <b>{$data['page']}/{$data['total_pages']}</b>\n";
    $msg .= "📋 Showing: <b>" . count($data['movies']) . " movies</b>\n\n";
    
    // Show current page movies list
    $i = 1;
    foreach ($data['movies'] as $movie) {
        $msg .= "<b>" . (($data['page'] - 1) * PER_PAGE + $i) . ".</b> " . 
                htmlspecialchars($movie['movie_name']) . "\n";
        $i++;
    }
    
    // Build keyboard
    $keyboard = build_totalupload_keyboard($data['page'], $data['total_pages']);
    
    sendMessage($chat_id, $msg, $keyboard, 'HTML');
}

function handle_stats($chat_id) {
    $stats = get_stats();
    $users_data = json_decode(file_get_contents(USERS_JSON), true);
    $total_users = count($users_data['users'] ?? []);
    
    $msg = "📊 <b>Bot Statistics</b>\n\n";
    $msg .= "🎬 Total Movies: <b>" . ($stats['total_movies'] ?? 0) . "</b>\n";
    $msg .= "👥 Total Users: <b>$total_users</b>\n";
    $msg .= "🔍 Total Searches: <b>" . ($stats['total_searches'] ?? 0) . "</b>\n";
    $msg .= "🕒 Last Updated: <b>" . ($stats['last_updated'] ?? 'N/A') . "</b>\n\n";
    
    // Top users by points (index25thAug style)
    $top_users = [];
    foreach ($users_data['users'] ?? [] as $uid => $ud) {
        $points = $ud['points'] ?? 0;
        if ($points > 0) {
            $top_users[$uid] = $points;
        }
    }
    
    arsort($top_users);
    $top_users = array_slice($top_users, 0, 5);
    
    if (!empty($top_users)) {
        $msg .= "🏆 <b>Top Users by Points:</b>\n";
        $rank = 1;
        foreach ($top_users as $uid => $points) {
            $username = $users_data['users'][$uid]['username'] ?? "User$uid";
            $msg .= "$rank. @$username - $points points\n";
            $rank++;
        }
    }
    
    sendMessage($chat_id, $msg, null, 'HTML');
}

function handle_checkcsv($chat_id, $show_all = false) {
    if (!file_exists(CSV_FILE)) {
        sendMessage($chat_id, "❌ CSV file not found.");
        return;
    }
    
    $movies = get_all_movies();
    
    if (empty($movies)) {
        sendMessage($chat_id, "📊 CSV file is empty.");
        return;
    }
    
    $movies = array_reverse($movies); // Latest first
    
    $limit = $show_all ? count($movies) : 10;
    $display_movies = array_slice($movies, 0, $limit);
    
    $message = "📊 <b>Movie Database</b>\n\n";
    $message .= "📁 Total Movies: <b>" . count($movies) . "</b>\n";
    
    if (!$show_all) {
        $message .= "🔍 Showing latest 10 entries\n";
        $message .= "📋 Use '/checkcsv all' for full list\n\n";
    } else {
        $message .= "📋 Full database listing\n\n";
    }
    
    $i = 1;
    foreach ($display_movies as $movie) {
        $message .= "<b>$i.</b> 🎬 " . htmlspecialchars($movie['movie_name']) . "\n";
        $message .= "   📝 ID: " . ($movie['message_id'] ?? 'N/A') . "\n";
        $message .= "   📅 Added: " . ($movie['added_at'] ?? 'N/A') . "\n\n";
        
        $i++;
        
        if (strlen($message) > 3000) {
            sendMessage($chat_id, $message, null, 'HTML');
            $message = "📊 Continuing...\n\n";
        }
    }
    
    $message .= "💾 File: " . CSV_FILE . "\n";
    $message .= "⏰ Last Updated: " . date('Y-m-d H:i:s', filemtime(CSV_FILE));
    
    sendMessage($chat_id, $message, null, 'HTML');
}

// ==================== MAIN WEBHOOK HANDLER ================
function handle_update($update) {
    global $MAINTENANCE_MODE, $CHANNELS, $ADMINS;
    
    log_message("Update received: " . json_encode($update));
    
    // Check maintenance mode
    if ($MAINTENANCE_MODE) {
        if (isset($update['message'])) {
            $chat_id = $update['message']['chat']['id'];
            $maintenance_msg = "🛠️ <b>Bot Under Maintenance</b>\n\n";
            $maintenance_msg .= "We're temporarily unavailable for updates.\n";
            $maintenance_msg .= "Will be back in few days!\n\n";
            $maintenance_msg .= "Thanks for patience 🙏";
            sendMessage($chat_id, $maintenance_msg, null, 'HTML');
        }
        return;
    }
    
    // Handle channel posts (auto-add movies)
    if (isset($update['channel_post'])) {
        $post = $update['channel_post'];
        $channel_id = $post['chat']['id'];
        
        // Check if from allowed channel
        if (in_array($channel_id, $CHANNELS)) {
            $text = $post['text'] ?? $post['caption'] ?? '';
            $message_id = $post['message_id'];
            
            // Extract movie name (first line or caption)
            $lines = explode("\n", $text);
            $movie_name = trim($lines[0]);
            
            if ($movie_name && strlen($movie_name) > 2) {
                add_movie($movie_name, $message_id, $channel_id);
                log_message("Auto-added from channel: $movie_name (Channel: $channel_id)");
            }
        }
    }
    
    // Handle user messages
    if (isset($update['message'])) {
        $message = $update['message'];
        $chat_id = $message['chat']['id'];
        $user_id = $message['from']['id'];
        $text = trim($message['text'] ?? '');
        $chat_type = $message['chat']['type'] ?? 'private';
        
        // Group message filtering (index23thOct style)
        if ($chat_type !== 'private') {
            if (strpos($text, '/') === 0) {
                // Commands allow karo
            } else {
                if (!is_valid_movie_query($text)) {
                    // Invalid message hai, ignore karo
                    log_message("Invalid group message filtered: $text", 'FILTER');
                    return;
                }
            }
        }
        
        // Update user info
        update_user([
            'id' => $user_id,
            'first_name' => $message['from']['first_name'] ?? '',
            'last_name' => $message['from']['last_name'] ?? '',
            'username' => $message['from']['username'] ?? ''
        ]);
        
        // Handle commands
        if (strpos($text, '/') === 0) {
            $parts = explode(' ', $text);
            $command = strtolower($parts[0]);
            
            switch ($command) {
                case '/start':
                    handle_start($chat_id, $user_id);
                    break;
                    
                case '/totalupload':
                case '/totaluploads':
                    $page = $parts[1] ?? 1;
                    handle_totalupload($chat_id, $page);
                    break;
                    
                case '/stats':
                    if (in_array($user_id, $ADMINS)) {
                        handle_stats($chat_id);
                    }
                    break;
                    
                case '/checkcsv':
                    $show_all = (isset($parts[1]) && strtolower($parts[1]) == 'all');
                    handle_checkcsv($chat_id, $show_all);
                    break;
                    
                case '/help':
                    $help = "🤖 <b>Entertainment Tadka Bot</b>\n\n";
                    $help .= "📢 Channel: @EntertainmentTadka786\n\n";
                    $help .= "📋 <b>Commands:</b>\n";
                    $help .= "/start - Welcome message\n";
                    $help .= "/totalupload - View all movies\n";
                    $help .= "/checkcsv - Check database\n";
                    $help .= "/help - This message\n\n";
                    $help .= "🔍 <b>Just type any movie name to search!</b>";
                    
                    sendMessage($chat_id, $help, null, 'HTML');
                    break;
                    
                case '/checkdate':
                    // From index23thOct
                    if (!file_exists(CSV_FILE)) { 
                        sendMessage($chat_id, "⚠️ No data saved yet."); 
                        return; 
                    }
                    
                    $date_counts = [];
                    $handle = fopen(CSV_FILE, "r");
                    if ($handle !== false) {
                        fgetcsv($handle);
                        while (($row = fgetcsv($handle)) !== false) {
                            if (count($row) >= 4) {
                                $date = $row[3] ?? date('d-m-Y');
                                $date_simple = explode(' ', $date)[0]; // Only date part
                                if (!isset($date_counts[$date_simple])) $date_counts[$date_simple] = 0;
                                $date_counts[$date_simple]++;
                            }
                        }
                        fclose($handle);
                    }
                    
                    krsort($date_counts);
                    $msg = "📅 <b>Movies Upload Record</b>\n\n";
                    $total_days = 0;
                    $total_movies = 0;
                    
                    foreach ($date_counts as $date => $count) {
                        $msg .= "➡️ $date: $count movies\n";
                        $total_days++;
                        $total_movies += $count;
                    }
                    
                    $msg .= "\n📊 <b>Summary:</b>\n";
                    $msg .= "• Total Days: $total_days\n";
                    $msg .= "• Total Movies: $total_movies\n";
                    $msg .= "• Average per day: " . round($total_movies / max(1, $total_days), 2);
                    
                    sendMessage($chat_id, $msg, null, 'HTML');
                    break;
            }
        }
        // Handle search queries
        elseif (!empty($text) && strlen($text) >= 2) {
            handle_search($chat_id, $text, $user_id);
        }
    }
    
    // Handle callback queries
    if (isset($update['callback_query'])) {
        $query = $update['callback_query'];
        $message = $query['message'];
        $chat_id = $message['chat']['id'];
        $data = $query['data'];
        
        // Answer callback query
        answerCallbackQuery($query['id']);
        
        if (strpos($data, 'page:') === 0) {
            $page = (int) str_replace('page:', '', $data);
            handle_totalupload($chat_id, $page);
        }
        elseif (strpos($data, 'send_page:') === 0) {
            $page = (int) str_replace('send_page:', '', $data);
            $data = paginate_movies($page);
            forward_page_movies($chat_id, $data['movies']);
        }
        elseif ($data === 'stop_pagination') {
            sendMessage($chat_id, "✅ Pagination stopped. Type /totalupload to start again.");
        }
        elseif ($data === 'current_page') {
            answerCallbackQuery($query['id'], "You're on this page");
        }
        elseif (strpos($data, 'search:') === 0) {
            $movie_name = str_replace('search:', '', $data);
            $results = search_movie($movie_name);
            
            if (!empty($results)) {
                foreach ($results as $result) {
                    deliver_item_to_chat($chat_id, $result);
                    usleep(500000);
                }
                sendMessage($chat_id, "✅ '" . htmlspecialchars($movie_name) . "' ke " . count($results) . " movies forward ho gaye!");
            }
        }
    }
    
    // Auto-backup at midnight
    if (date('H:i') == '00:00') {
        auto_backup();
    }
    
    // Daily digest at 8 AM
    if (date('H:i') == '08:00') {
        send_daily_digest();
    }
}

// ==================== WEBHOOK SETUP ========================
if (isset($_GET['action']) && $_GET['action'] === 'setwebhook') {
    global $BOT_TOKEN;
    
    if (empty($BOT_TOKEN) || $BOT_TOKEN === 'YOUR_BOT_TOKEN_HERE') {
        die(json_encode([
            'status' => 'error',
            'message' => 'BOT_TOKEN not set in environment variables'
        ]));
    }
    
    $webhook_url = "https://" . $_SERVER['HTTP_HOST'] . $_SERVER['SCRIPT_NAME'];
    $result = file_get_contents("https://api.telegram.org/bot{$BOT_TOKEN}/setWebhook?url=" . urlencode($webhook_url));
    
    echo json_encode([
        'status' => 'success',
        'result' => json_decode($result, true),
        'webhook_url' => $webhook_url,
        'timestamp' => date('Y-m-d H:i:s'),
        'channels_count' => count($CHANNELS)
    ]);
    log_message("Webhook set: $webhook_url");
    exit;
}

// ==================== MANUAL TEST FUNCTIONS ================
// From index23thOct
if (isset($_GET['test_save'])) {
    function manual_save_to_csv($movie_name, $message_id, $channel_id = '-1003181705395') {
        $entry = [$movie_name, $message_id, $channel_id, date('Y-m-d H:i:s')];
        $handle = fopen(CSV_FILE, "a");
        if ($handle !== FALSE) {
            fputcsv($handle, $entry);
            fclose($handle);
            @chmod(CSV_FILE, 0644);
            return true;
        }
        return false;
    }
    
    manual_save_to_csv("Metro In Dino (2025)", 1924);
    manual_save_to_csv("Metro In Dino 2025 WebRip 480p x265 HEVC 10bit Hindi ESubs", 1925);
    manual_save_to_csv("Metro In Dino (2025) Hindi 720p HEVC HDRip x265 AAC 5.1 ESubs", 1926);
    
    echo "✅ Test movies saved!<br>";
    echo "📊 <a href='?check_csv=1'>Check CSV</a> | ";
    echo "<a href='?setwebhook=1'>Set Webhook</a>";
    exit;
}

if (isset($_GET['check_csv'])) {
    echo "<h3>CSV Content:</h3>";
    if (file_exists(CSV_FILE)) {
        $lines = file(CSV_FILE);
        foreach ($lines as $line) {
            echo htmlspecialchars($line) . "<br>";
        }
    } else {
        echo "❌ CSV file not found!";
    }
    exit;
}

// ==================== MAIN EXECUTION ======================
init_storage();
log_message("Bot started. Method: " . $_SERVER['REQUEST_METHOD'] . ", IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));

// Get update from webhook
$content = file_get_contents('php://input');
$update = json_decode($content, true);

if ($update) {
    handle_update($update);
    http_response_code(200);
    echo 'OK';
} else {
    // Health check endpoint
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $stats = get_stats();
        $users_data = json_decode(file_get_contents(USERS_JSON), true);
        
        echo json_encode([
            'status' => 'online',
            'timestamp' => date('Y-m-d H:i:s'),
            'service' => 'Telegram Movie Bot - Combined Version',
            'stats' => [
                'total_movies' => $stats['total_movies'] ?? 0,
                'total_users' => count($users_data['users'] ?? []),
                'total_searches' => $stats['total_searches'] ?? 0
            ],
            'channels' => $CHANNELS,
            'maintenance_mode' => $MAINTENANCE_MODE,
            'endpoints' => [
                '/?action=setwebhook' => 'Setup webhook',
                '/?test_save=1' => 'Test save movies',
                '/?check_csv=1' => 'Check CSV content'
            ]
        ], JSON_PRETTY_PRINT);
    } else {
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => 'Invalid request format',
            'required' => 'Telegram webhook POST request'
        ]);
        log_message("Invalid request received", 'ERROR');
    }
}
?>