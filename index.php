<?php
/* 
|--------------------------------------------------------------------------
| 🎬 Entertainment Tadka Telegram Bot - COMPLETE PRODUCTION VERSION
|--------------------------------------------------------------------------
| Version: 4.0 | Features: Channel Attribution + Auto-Delete + Request System
|--------------------------------------------------------------------------
*/

// ==================== CONFIGURATION ======================
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/logs/error.log');
error_reporting(E_ALL);
date_default_timezone_set('Asia/Kolkata');

// ==================== BOT TOKEN =========================
$BOT_TOKEN = getenv('BOT_TOKEN') ?: "YOUR_BOT_TOKEN_HERE";
$API_URL = "https://api.telegram.org/bot$BOT_TOKEN/";

// ==================== CHANNELS CONFIG ====================
$CHANNELS_STRING = getenv('CHANNELS') ?: '-1003251791991,-1002337293281,-1003181705395,-1002831605258,-1002964109368,-1003614546520';
$CHANNELS = explode(',', $CHANNELS_STRING);
$REQUEST_GROUP_ID = getenv('REQUEST_GROUP_ID') ?: '-100XXXXXXXXXX';

// ==================== CHANNEL USERNAMES MAPPING =========
$CHANNEL_USERNAMES = [
    '-1003251791991' => '@EntertainmentTadka786',      // Main Channel
    '-1002337293281' => '@EntertainmentTadka7860',     // Request Group
    '-1003181705395' => '@threater_print_movies',      // Theater Prints
    '-1002831605258' => '@ETBackup',                   // Backup Channel
    '-1002964109368' => '@EntertainmentTadka_Extra1',  // Extra Channel 1
    '-1003614546520' => '@EntertainmentTadka_Extra2'   // Extra Channel 2
];

// ==================== CONSTANTS ==========================
define('CSV_FILE', __DIR__ . '/movies.csv');
define('REQUEST_FILE', __DIR__ . '/movie_requests.json');
define('DELETE_SCHEDULE_FILE', __DIR__ . '/delete_schedule.json');
define('PROGRESS_TRACKING_FILE', __DIR__ . '/progress_tracking.json');
define('USER_COOLDOWN', 20);
define('PER_PAGE', 5);
define('AUTO_DELETE_MINUTES', 5);
define('AUTO_DELETE_SECONDS', AUTO_DELETE_MINUTES * 60);
define('TYPING_DELAY', 2);
define('RESULT_DELAY', 1);

// ==================== ADMIN USERS ========================
$ADMINS = [123456789]; // YOUR_TELEGRAM_USER_ID

// ==================== LOGGING FUNCTION ===================
function log_message($message, $type = 'INFO') {
    $log_file = __DIR__ . '/logs/bot_' . date('Y-m-d') . '.log';
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "[$timestamp] [$type] $message" . PHP_EOL;
    file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
}

// ==================== INITIAL SETUP ======================
function init_system() {
    // Create logs directory
    if (!is_dir(__DIR__ . '/logs')) {
        mkdir(__DIR__ . '/logs', 0755, true);
        chmod(__DIR__ . '/logs', 0755);
    }
    
    // Create movies.csv if not exists
    if (!file_exists(CSV_FILE)) {
        file_put_contents(CSV_FILE, "movie_name,message_id,channel_id\n");
        chmod(CSV_FILE, 0644);
        log_message("CSV file created");
    }
    
    // Create movie_requests.json if not exists
    if (!file_exists(REQUEST_FILE)) {
        file_put_contents(REQUEST_FILE, json_encode([
            'requests' => [],
            'stats' => ['total' => 0, 'pending' => 0, 'completed' => 0]
        ], JSON_PRETTY_PRINT));
        chmod(REQUEST_FILE, 0644);
        log_message("Request file created");
    }
    
    // Create delete_schedule.json if not exists
    if (!file_exists(DELETE_SCHEDULE_FILE)) {
        file_put_contents(DELETE_SCHEDULE_FILE, json_encode([]));
        chmod(DELETE_SCHEDULE_FILE, 0644);
    }
    
    // Create progress_tracking.json if not exists
    if (!file_exists(PROGRESS_TRACKING_FILE)) {
        file_put_contents(PROGRESS_TRACKING_FILE, json_encode([]));
        chmod(PROGRESS_TRACKING_FILE, 0644);
    }
}

// ==================== TELEGRAM API FUNCTIONS ============
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
        return $response ? json_decode($response, true) : false;
    } catch (Exception $e) {
        log_message("API Error [$method]: " . $e->getMessage(), 'ERROR');
        return false;
    }
}

function send_message($chat_id, $text, $parse_mode = 'HTML', $keyboard = null) {
    $data = [
        'chat_id' => $chat_id,
        'text' => $text,
        'parse_mode' => $parse_mode,
        'disable_web_page_preview' => true
    ];
    
    if ($keyboard) {
        $data['reply_markup'] = json_encode($keyboard);
    }
    
    return tg('sendMessage', $data);
}

function send_typing_action($chat_id) {
    return tg('sendChatAction', [
        'chat_id' => $chat_id,
        'action' => 'typing'
    ]);
}

function copy_message($chat_id, $from_chat_id, $message_id) {
    return tg('copyMessage', [
        'chat_id' => $chat_id,
        'from_chat_id' => $from_chat_id,
        'message_id' => $message_id
    ]);
}

function delete_message($chat_id, $message_id) {
    return tg('deleteMessage', [
        'chat_id' => $chat_id,
        'message_id' => $message_id
    ]);
}

function edit_message_text($chat_id, $message_id, $text, $parse_mode = 'HTML', $keyboard = null) {
    $data = [
        'chat_id' => $chat_id,
        'message_id' => $message_id,
        'text' => $text,
        'parse_mode' => $parse_mode
    ];
    
    if ($keyboard) {
        $data['reply_markup'] = json_encode($keyboard);
    }
    
    return tg('editMessageText', $data);
}

function answer_callback_query($callback_query_id, $text = '', $show_alert = false) {
    $data = [
        'callback_query_id' => $callback_query_id
    ];
    
    if ($text) {
        $data['text'] = $text;
        $data['show_alert'] = $show_alert;
    }
    
    return tg('answerCallbackQuery', $data);
}

// ==================== DELETYPING WITH ETA ================
function deploy_typing($chat_id, $action = 'typing', $progress_text = null) {
    send_typing_action($chat_id);
    sleep(TYPING_DELAY);
    
    if ($progress_text) {
        $eta = 3;
        $progress_msg = send_message($chat_id, $progress_text . "\n\n⏳ ETA: $eta seconds...", 'HTML');
        return $progress_msg['result']['message_id'] ?? null;
    }
    
    return null;
}

// ==================== CHANNEL ATTRIBUTION ================
function get_channel_username($channel_id) {
    global $CHANNEL_USERNAMES;
    return $CHANNEL_USERNAMES[$channel_id] ?? "Unknown Channel";
}

function send_movie_with_attribution($chat_id, $movie_data) {
    $channel_username = get_channel_username($movie_data['channel_id']);
    
    // Send attribution message
    $attribution_msg = send_message(
        $chat_id,
        "🎬 *{$movie_data['movie_name']}*\n\n📤 Forwarded from: $channel_username\n⬇️ Downloading...",
        'Markdown'
    );
    
    // Forward the movie
    $forward_result = copy_message(
        $chat_id,
        $movie_data['channel_id'],
        $movie_data['message_id']
    );
    
    // Delete attribution after delay
    if (isset($attribution_msg['result']['message_id'])) {
        sleep(2);
        delete_message($chat_id, $attribution_msg['result']['message_id']);
    }
    
    return $forward_result;
}

function send_batch_with_attribution($chat_id, $movies, $search_query = '') {
    if (empty($movies)) return;
    
    $count = count($movies);
    $unique_channels = [];
    
    // Collect channel statistics
    foreach ($movies as $movie) {
        $channel_id = $movie['channel_id'];
        $username = get_channel_username($channel_id);
        
        if (!isset($unique_channels[$username])) {
            $unique_channels[$username] = 0;
        }
        $unique_channels[$username]++;
    }
    
    // Send summary message
    $summary = "✅ *Found $count movies*\n";
    if (!empty($search_query)) {
        $summary .= "🔍 Search: `$search_query`\n\n";
    }
    
    $summary .= "📊 *Sources:*\n";
    foreach ($unique_channels as $channel => $movie_count) {
        $summary .= "• $channel ($movie_count)\n";
    }
    
    $summary .= "\n⬇️ *Sending movies...*";
    
    $summary_msg = send_message($chat_id, $summary, 'Markdown');
    
    // Send each movie with attribution
    foreach ($movies as $index => $movie) {
        if ($index > 0) sleep(RESULT_DELAY);
        
        $channel_username = get_channel_username($movie['channel_id']);
        
        // Send attribution for this movie
        $attribution = send_message(
            $chat_id,
            "🎬 *{$movie['movie_name']}*\n📤 From: $channel_username",
            'Markdown'
        );
        
        // Forward the movie
        copy_message(
            $chat_id,
            $movie['channel_id'],
            $movie['message_id']
        );
        
        // Delete attribution after delay
        if (isset($attribution['result']['message_id'])) {
            sleep(1);
            delete_message($chat_id, $attribution['result']['message_id']);
        }
    }
    
    // Update summary message
    if (isset($summary_msg['result']['message_id'])) {
        $updated_summary = str_replace(
            "⬇️ *Sending movies...*",
            "🎉 *All movies sent!*\n\n💡 *Tip:* Save messages to Saved Messages",
            $summary
        );
        
        edit_message_text(
            $chat_id,
            $summary_msg['result']['message_id'],
            $updated_summary,
            'Markdown',
            [
                'inline_keyboard' => [[
                    ['text' => '🔍 Search Again', 'switch_inline_query_current_chat' => ''],
                    ['text' => '🏠 Main Menu', 'callback_data' => 'mainmenu']
                ]]
            ]
        );
    }
}

// ==================== AUTO-DELETE SYSTEM =================
function schedule_auto_delete($chat_id, $message_id, $delay_seconds = AUTO_DELETE_SECONDS) {
    $schedule = [];
    if (file_exists(DELETE_SCHEDULE_FILE)) {
        $schedule = json_decode(file_get_contents(DELETE_SCHEDULE_FILE), true);
    }
    
    $delete_time = time() + $delay_seconds;
    $schedule[] = [
        'chat_id' => $chat_id,
        'message_id' => $message_id,
        'delete_at' => $delete_time,
        'scheduled_at' => time()
    ];
    
    file_put_contents(DELETE_SCHEDULE_FILE, json_encode($schedule, JSON_PRETTY_PRINT));
    
    // Start progress tracking
    start_progress_tracking($chat_id, $message_id, $delay_seconds);
    
    log_message("Scheduled auto-delete for message $message_id in chat $chat_id");
}

function start_progress_tracking($chat_id, $message_id, $total_seconds) {
    $tracking = [];
    if (file_exists(PROGRESS_TRACKING_FILE)) {
        $tracking = json_decode(file_get_contents(PROGRESS_TRACKING_FILE), true);
    }
    
    $tracking_key = $chat_id . '_' . $message_id;
    $tracking[$tracking_key] = [
        'start_time' => time(),
        'end_time' => time() + $total_seconds,
        'total_seconds' => $total_seconds,
        'last_update' => time()
    ];
    
    file_put_contents(PROGRESS_TRACKING_FILE, json_encode($tracking, JSON_PRETTY_PRINT));
}

function process_scheduled_deletes() {
    if (!file_exists(DELETE_SCHEDULE_FILE)) {
        return;
    }
    
    $schedule = json_decode(file_get_contents(DELETE_SCHEDULE_FILE), true);
    $now = time();
    $updated_schedule = [];
    $deleted_count = 0;
    
    foreach ($schedule as $item) {
        if ($now >= $item['delete_at']) {
            $delete_result = delete_message($item['chat_id'], $item['message_id']);
            if ($delete_result && isset($delete_result['ok']) && $delete_result['ok']) {
                log_message("Auto-deleted message {$item['message_id']} from chat {$item['chat_id']}");
                $deleted_count++;
            } else {
                // Keep for retry
                $updated_schedule[] = $item;
            }
        } else {
            $updated_schedule[] = $item;
        }
    }
    
    if ($deleted_count > 0 || count($schedule) != count($updated_schedule)) {
        file_put_contents(DELETE_SCHEDULE_FILE, json_encode($updated_schedule, JSON_PRETTY_PRINT));
    }
    
    return $deleted_count;
}

// ==================== PROGRESS BAR FUNCTIONS =============
function create_progress_bar($percentage, $length = 10) {
    $filled = round($percentage / 100 * $length);
    $empty = $length - $filled;
    
    $bar = "🟩" . str_repeat("🟩", max(0, $filled - 1));
    $bar .= str_repeat("⬜", max(0, $empty));
    
    return $bar;
}

function get_remaining_time($end_time) {
    $remaining = $end_time - time();
    if ($remaining <= 0) return "0:00";
    
    $minutes = floor($remaining / 60);
    $seconds = $remaining % 60;
    
    return sprintf("%d:%02d", $minutes, $seconds);
}

// ==================== CSV DATABASE FUNCTIONS =============
function add_movie_to_db($movie_name, $message_id, $channel_id) {
    // Check for duplicates
    $rows = file(CSV_FILE, FILE_IGNORE_NEW_LINES);
    foreach ($rows as $r) {
        $cols = str_getcsv($r);
        if (isset($cols[1]) && $cols[1] == $message_id) {
            return false;
        }
    }
    
    // Add to CSV
    $fp = fopen(CSV_FILE, 'a');
    fputcsv($fp, [$movie_name, $message_id, $channel_id]);
    fclose($fp);
    
    log_message("Movie added to DB: $movie_name (Message ID: $message_id, Channel: $channel_id)");
    return true;
}

function get_all_movies() {
    $movies = [];
    if (!file_exists(CSV_FILE)) {
        return $movies;
    }
    
    if (($handle = fopen(CSV_FILE, 'r')) !== false) {
        fgetcsv($handle); // Skip header
        while (($data = fgetcsv($handle)) !== false) {
            if (count($data) >= 3) {
                $movies[] = [
                    'movie_name' => $data[0],
                    'message_id' => $data[1],
                    'channel_id' => $data[2]
                ];
            }
        }
        fclose($handle);
    }
    
    return $movies;
}

function search_movies($query) {
    $query = strtolower(trim($query));
    $results = [];
    
    foreach (get_all_movies() as $movie) {
        if (strpos(strtolower($movie['movie_name']), $query) !== false) {
            $results[] = $movie;
        }
    }
    
    return $results;
}

function movie_exists($movie_name) {
    $search_name = strtolower(trim($movie_name));
    
    foreach (get_all_movies() as $movie) {
        $db_name = strtolower($movie['movie_name']);
        if ($db_name === $search_name || strpos($db_name, $search_name) !== false) {
            return $movie;
        }
    }
    
    return false;
}

// ==================== REQUEST SYSTEM FUNCTIONS ===========
function add_movie_request($user_id, $movie_name) {
    // Check if movie already exists
    $existing = movie_exists($movie_name);
    if ($existing) {
        return ['status' => 'exists', 'data' => $existing];
    }
    
    // Load requests data
    $data = json_decode(file_get_contents(REQUEST_FILE), true);
    
    // Check for duplicate pending request
    foreach ($data['requests'] as $req) {
        if ($req['user_id'] == $user_id && 
            strtolower($req['movie']) == strtolower($movie_name) &&
            $req['status'] == 'pending') {
            return ['status' => 'duplicate', 'id' => $req['id']];
        }
    }
    
    // Create new request
    $request_id = 'REQ' . date('YmdHis') . rand(100, 999);
    $new_request = [
        'id' => $request_id,
        'user_id' => $user_id,
        'movie' => trim($movie_name),
        'date' => date('Y-m-d H:i:s'),
        'status' => 'pending'
    ];
    
    $data['requests'][] = $new_request;
    $data['stats']['total']++;
    $data['stats']['pending']++;
    
    file_put_contents(REQUEST_FILE, json_encode($data, JSON_PRETTY_PRINT));
    
    // Notify admin if ADMIN_ID is set
    $admin_id = getenv('ADMIN_ID');
    if ($admin_id) {
        $admin_msg = "📥 *New Movie Request*\n\n";
        $admin_msg .= "🎬 *$movie_name*\n";
        $admin_msg .= "👤 User: `$user_id`\n";
        $admin_msg .= "🆔 ID: `$request_id`\n";
        $admin_msg .= "⏰ Time: " . date('H:i:s');
        send_message($admin_id, $admin_msg, 'Markdown');
    }
    
    log_message("Request added: $movie_name by user $user_id (ID: $request_id)");
    return ['status' => 'added', 'id' => $request_id];
}

function notify_request_users($movie_name, $channel_id) {
    $data = json_decode(file_get_contents(REQUEST_FILE), true);
    $count = 0;
    $channel_username = get_channel_username($channel_id);
    
    foreach ($data['requests'] as &$req) {
        if ($req['status'] == 'pending') {
            $req_movie = strtolower($req['movie']);
            $new_movie = strtolower($movie_name);
            
            // Check for match
            if ($req_movie == $new_movie || 
                strpos($new_movie, $req_movie) !== false ||
                strpos($req_movie, $new_movie) !== false) {
                
                // Send notification to user
                $notification = "✅ *Request Completed!*\n\n";
                $notification .= "🎬 *{$req['movie']}*\n";
                $notification .= "📅 Added: " . date('d-m-Y') . "\n";
                $notification .= "📤 From: $channel_username\n\n";
                $notification .= "🔍 *Search for it now in the bot!*";
                
                send_message($req['user_id'], $notification, 'Markdown');
                
                // Update request status
                $req['status'] = 'completed';
                $req['completed_date'] = date('Y-m-d H:i:s');
                $req['channel'] = $channel_username;
                $count++;
            }
        }
    }
    
    // Update statistics if any changes
    if ($count > 0) {
        $data['stats']['completed'] += $count;
        $data['stats']['pending'] -= $count;
        file_put_contents(REQUEST_FILE, json_encode($data, JSON_PRETTY_PRINT));
        
        log_message("Notified $count users for movie: $movie_name");
    }
    
    return $count;
}

function get_user_requests($user_id) {
    $data = json_decode(file_get_contents(REQUEST_FILE), true);
    $user_requests = [];
    
    foreach ($data['requests'] as $req) {
        if ($req['user_id'] == $user_id) {
            $user_requests[] = $req;
        }
    }
    
    return $user_requests;
}

function get_request_stats() {
    $data = json_decode(file_get_contents(REQUEST_FILE), true);
    return $data['stats'];
}

// ==================== PAGINATION FUNCTIONS ===============
function get_total_pages() {
    $movies = get_all_movies();
    $total = count($movies);
    return ceil($total / PER_PAGE);
}

function get_movies_page($page = 1) {
    $movies = get_all_movies();
    $total = count($movies);
    
    if ($total === 0) {
        return [
            'movies' => [],
            'total' => 0,
            'page' => $page,
            'total_pages' => 0,
            'start' => 0,
            'end' => 0
        ];
    }
    
    $total_pages = ceil($total / PER_PAGE);
    if ($page < 1) $page = 1;
    if ($page > $total_pages) $page = $total_pages;
    
    $start = ($page - 1) * PER_PAGE;
    $slice = array_slice($movies, $start, PER_PAGE);
    
    return [
        'movies' => $slice,
        'total' => $total,
        'page' => $page,
        'total_pages' => $total_pages,
        'start' => $start + 1,
        'end' => min($start + PER_PAGE, $total)
    ];
}

// ==================== KEYBOARD FUNCTIONS =================
function get_welcome_keyboard() {
    $keyboard = [
        [
            ['text' => '🔍 Search Movies', 'switch_inline_query_current_chat' => ''],
            ['text' => '📊 Total Uploads', 'callback_data' => 'totaluploads:1']
        ],
        [
            ['text' => '📥 Request Movie', 'callback_data' => 'request_movie']
        ],
        [
            ['text' => '📢 Main Channel', 'url' => 'https://t.me/EntertainmentTadka786'],
            ['text' => '🎭 Theater Prints', 'url' => 'https://t.me/threater_print_movies']
        ],
        [
            ['text' => '📥 Request Group', 'url' => 'https://t.me/EntertainmentTadka7860'],
            ['text' => '🔒 Backup Channel', 'url' => 'https://t.me/ETBackup']
        ],
        [
            ['text' => '❓ Help', 'callback_data' => 'help'],
            ['text' => '📈 Stats', 'callback_data' => 'stats']
        ]
    ];
    
    return ['inline_keyboard' => $keyboard];
}

function get_pagination_keyboard($current_page, $total_pages) {
    if ($total_pages <= 1) {
        return ['inline_keyboard' => [[['text' => '🏠 Main Menu', 'callback_data' => 'mainmenu']]]];
    }
    
    $keyboard = [];
    $row = [];
    
    // Previous button
    if ($current_page > 1) {
        $row[] = ['text' => '◀️ Prev', 'callback_data' => 'page:' . ($current_page - 1)];
    }
    
    // Page indicator
    $row[] = ['text' => "📄 $current_page/$total_pages", 'callback_data' => 'current:' . $current_page];
    
    // Next button
    if ($current_page < $total_pages) {
        $row[] = ['text' => 'Next ▶️', 'callback_data' => 'page:' . ($current_page + 1)];
    }
    
    $keyboard[] = $row;
    
    // First/Last buttons for many pages
    if ($total_pages > 5) {
        $row2 = [];
        if ($current_page > 2) {
            $row2[] = ['text' => '⏮️ First', 'callback_data' => 'page:1'];
        }
        if ($current_page < $total_pages - 1) {
            $row2[] = ['text' => 'Last ⏭️', 'callback_data' => 'page:' . $total_pages];
        }
        if (!empty($row2)) {
            $keyboard[] = $row2;
        }
    }
    
    // Main menu button
    $keyboard[] = [['text' => '🏠 Main Menu', 'callback_data' => 'mainmenu']];
    
    return ['inline_keyboard' => $keyboard];
}

function get_request_keyboard() {
    $keyboard = [
        [
            ['text' => '📥 Submit Request', 'callback_data' => 'submit_request']
        ],
        [
            ['text' => '📋 My Requests', 'callback_data' => 'my_requests']
        ],
        [
            ['text' => '🏠 Main Menu', 'callback_data' => 'mainmenu']
        ]
    ];
    
    return ['inline_keyboard' => $keyboard];
}

function get_search_again_keyboard() {
    $keyboard = [
        [
            ['text' => '🔍 Search Again', 'switch_inline_query_current_chat' => '']
        ],
        [
            ['text' => '🏠 Main Menu', 'callback_data' => 'mainmenu']
        ]
    ];
    
    return ['inline_keyboard' => $keyboard];
}

// ==================== MESSAGE TEMPLATES ==================
function welcome_message($chat_id) {
    $total_movies = count(get_all_movies());
    $request_stats = get_request_stats();
    
    $message = "┌──────────────────────────────────────┐\n";
    $message .= "│  🎬 *Welcome to Entertainment Tadka!*\n";
    $message .= "│\n";
    $message .= "│  📊 *Stats:*\n";
    $message .= "│  • Movies: $total_movies\n";
    $message .= "│  • Requests: {$request_stats['pending']} pending\n";
    $message .= "│\n";
    $message .= "│  🔍 *How to use:*\n";
    $message .= "│  1. Search any movie name\n";
    $message .= "│  2. See source channel\n";
    $message .= "│  3. Request missing movies\n";
    $message .= "│  4. Get auto-notifications\n";
    $message .= "│\n";
    $message .= "│  📤 *Shows source for every movie*\n";
    $message .= "│  • Know which channel it's from\n";
    $message .= "│  • Direct channel links\n";
    $message .= "│\n";
    $message .= "│  💬 *Commands:* /help\n";
    $message .= "└──────────────────────────────────────┘";
    
    deploy_typing($chat_id, 'typing', "🎬 Loading Entertainment Tadka...");
    send_message($chat_id, $message, 'Markdown', get_welcome_keyboard());
}

function help_message($chat_id) {
    $message = "❓ *Help & Commands*\n\n";
    
    $message .= "🎬 *Movie Search:*\n";
    $message .= "• Type any movie name\n";
    $message .= "• Shows source channel\n";
    $message .= "• Partial names work\n\n";
    
    $message .= "📥 *Request System:*\n";
    $message .= "• /request movie_name\n";
    $message .= "• Auto-notification when added\n";
    $message .= "• Check /request for details\n\n";
    
    $message .= "📊 *Other Commands:*\n";
    $message .= "• /totaluploads - All movies\n";
    $message .= "• /channels - Our channels\n";
    $message .= "• /stats - Bot statistics\n\n";
    
    $message .= "📤 *Every movie shows:*\n";
    $message .= "• Which channel it's from\n";
    $message .= "• Direct channel link\n";
    $message .= "• Auto-delete in request group\n\n";
    
    $message .= "💡 *Tip:* Use buttons for quick access!";
    
    deploy_typing($chat_id, 'typing', "📖 Loading help guide...");
    send_message($chat_id, $message, 'Markdown', get_welcome_keyboard());
}

function request_help_message($chat_id) {
    $message = "📝 *Request A Movie*\n\n";
    
    $message .= "🔧 *Usage:* `/request Movie Name`\n\n";
    
    $message .= "📋 *Examples:*\n";
    $message .= "• `/request Animal Park`\n";
    $message .= "• `/request KGF 3`\n";
    $message .= "• `/request New Hindi Movie`\n\n";
    
    $message .= "✨ *Features:*\n";
    $message .= "✅ Unlimited requests\n";
    $message .= "✅ Auto-check if exists\n";
    $message .= "✅ Auto-notification when added\n";
    $message .= "✅ Request ID tracking\n\n";
    
    $message .= "📊 *Current Stats:*\n";
    $stats = get_request_stats();
    $message .= "• Total: {$stats['total']}\n";
    $message .= "• Pending: {$stats['pending']}\n";
    $message .= "• Completed: {$stats['completed']}\n\n";
    
    $message .= "💡 *Tip:* Be specific with movie names!\n\n";
    $message .= "Click below to submit request:";
    
    send_message($chat_id, $message, 'Markdown', get_request_keyboard());
}

function channels_message($chat_id) {
    global $CHANNEL_USERNAMES;
    
    $message = "📢 *Our Channels*\n\n";
    
    $counter = 1;
    foreach ($CHANNEL_USERNAMES as $channel_username) {
        $message .= "$counter. $channel_username\n";
        $counter++;
    }
    
    $message .= "\n💡 *Join all channels for complete access!*\n\n";
    $message .= "🎬 *Main Channel:* @EntertainmentTadka786\n";
    $message .= "📥 *Request Group:* @EntertainmentTadka7860\n";
    $message .= "🎭 *Theater Prints:* @threater_print_movies\n";
    $message .= "🔒 *Backup:* @ETBackup";
    
    send_message($chat_id, $message, 'Markdown', get_welcome_keyboard());
}

function stats_message($chat_id) {
    $movies = get_all_movies();
    $total_movies = count($movies);
    $total_pages = get_total_pages();
    $request_stats = get_request_stats();
    
    // Count active flood files
    $active_users = count(glob(sys_get_temp_dir() . '/tgflood_*'));
    
    $message = "📊 *Bot Statistics*\n\n";
    
    $message .= "🎬 *Movies Database:*\n";
    $message .= "• Total Movies: $total_movies\n";
    $message .= "• Total Pages: $total_pages\n";
    $message .= "• Per Page: " . PER_PAGE . "\n\n";
    
    $message .= "📥 *Request System:*\n";
    $message .= "• Total Requests: {$request_stats['total']}\n";
    $message .= "• Pending: {$request_stats['pending']}\n";
    $message .= "• Completed: {$request_stats['completed']}\n\n";
    
    $message .= "👤 *Users:*\n";
    $message .= "• Active Today: $active_users\n";
    $message .= "• Flood Control: " . USER_COOLDOWN . "s\n\n";
    
    $message .= "⚙️ *System:*\n";
    $message .= "• Auto-Delete: " . AUTO_DELETE_MINUTES . " min\n";
    $message .= "• Channels: " . count($CHANNEL_USERNAMES) . "\n";
    $message .= "• Status: ✅ Online\n";
    $message .= "• Last Update: " . date('H:i:s');
    
    deploy_typing($chat_id, 'typing', "📈 Loading statistics...");
    send_message($chat_id, $message, 'Markdown', get_welcome_keyboard());
}

// ==================== FLOOD CONTROL ======================
function is_flood($user_id) {
    $flood_file = sys_get_temp_dir() . '/tgflood_' . $user_id;
    
    if (file_exists($flood_file)) {
        $last_time = file_get_contents($flood_file);
        $remaining = USER_COOLDOWN - (time() - (int)$last_time);
        
        if ($remaining > 0) {
            return $remaining;
        }
    }
    
    file_put_contents($flood_file, time());
    return 0;
}

// ==================== INVALID QUERY CHECK ================
function is_invalid_query($text) {
    $blocked = [
        "how", "php", "bot", "telegram", "code", "script",
        "player", "vlc", "mx player", "download link", "link",
        "technical", "error", "source", "github", "api",
        "token", "admin", "password", "login", "register"
    ];
    
    $text_lower = strtolower($text);
    
    foreach ($blocked as $word) {
        if (strpos($text_lower, $word) !== false) {
            return true;
        }
    }
    
    return false;
}

// ==================== REQUEST GROUP HANDLER ==============
function handle_request_group_message($chat_id, $text, $message_id) {
    global $REQUEST_GROUP_ID;
    
    if ($chat_id != $REQUEST_GROUP_ID) {
        return false;
    }
    
    $update = json_decode(file_get_contents("php://input"), true);
    $message = $update['message'] ?? null;
    
    if ($message && isset($message['from']['is_bot']) && $message['from']['is_bot']) {
        // Send auto-delete notification
        $delete_msg = send_message(
            $chat_id,
            "⏳ This message will auto-delete in " . AUTO_DELETE_MINUTES . " minutes\n\n📌 ᴘʟᴇᴀsᴇ ꜰᴏʀᴡᴀʀᴅ ᴛʜɪs ꜰɪʟᴇs ᴛᴏ ᴛʜᴇ sᴀᴠᴇᴅ ᴍᴇssᴀɢᴇ ᴀɴᴅ ᴄʟᴏsᴇ ᴛʜɪs ᴍᴇssᴀɢᴇ",
            'HTML',
            ['reply_to_message_id' => $message_id]
        );
        
        if (isset($delete_msg['result']['message_id'])) {
            schedule_auto_delete($chat_id, $delete_msg['result']['message_id']);
        }
        
        return true;
    }
    
    return false;
}

// ==================== REQUEST HANDLER ====================
function handle_movie_request($chat_id, $user_id, $input) {
    if (empty($input)) {
        request_help_message($chat_id);
        return;
    }
    
    $movie_name = trim($input);
    
    // Check if movie already exists
    $existing = movie_exists($movie_name);
    if ($existing) {
        $channel_username = get_channel_username($existing['channel_id']);
        
        $message = "✅ *Movie Already Available!*\n\n";
        $message .= "🎬 *{$existing['movie_name']}*\n";
        $message .= "📤 From: $channel_username\n\n";
        $message .= "🔍 *Search for it:*\n";
        $message .= "Type: `{$existing['movie_name']}`\n\n";
        $message .= "📥 *Available in our channels*";
        
        send_message($chat_id, $message, 'Markdown');
        return;
    }
    
    // Add request
    $result = add_movie_request($user_id, $movie_name);
    
    if ($result['status'] == 'added') {
        $message = "✅ *Request Submitted!*\n\n";
        $message .= "🎬 *$movie_name*\n";
        $message .= "🆔 ID: `{$result['id']}`\n";
        $message .= "📅 Date: " . date('d-m-Y H:i:s') . "\n\n";
        $message .= "🔔 *What happens next?*\n";
        $message .= "1. We add it within 24 hours\n";
        $message .= "2. You get auto-notification\n";
        $message .= "3. Download from our channels\n\n";
        $message .= "📊 Status: ⏳ Pending\n\n";
        $message .= "📢 *Our Channels:*\n";
        $message .= "🍿 Main: @EntertainmentTadka786\n";
        $message .= "📥 Request: @EntertainmentTadka7860\n";
        $message .= "🎭 Theater: @threater_print_movies\n";
        $message .= "📂 Backup: @ETBackup\n\n";
        $message .= "❤️ Thank you for your request!";
        
        send_message($chat_id, $message, 'Markdown');
        
    } elseif ($result['status'] == 'duplicate') {
        $message = "⏳ *Request Already Pending*\n\n";
        $message .= "🎬 $movie_name\n";
        $message .= "🆔 ID: `{$result['id']}`\n\n";
        $message .= "✅ Already in our system\n";
        $message .= "⏳ Status: Pending\n\n";
        $message .= "🔔 You'll be notified when added!\n\n";
        $message .= "📢 Follow: @EntertainmentTadka7860";
        
        send_message($chat_id, $message, 'Markdown');
    }
}

// ==================== MAIN UPDATE HANDLER ================
function handle_update($update) {
    $message = $update['message'] ?? null;
    $callback = $update['callback_query'] ?? null;
    
    // Handle Message
    if ($message) {
        $chat_id = $message['chat']['id'];
        $user_id = $message['from']['id'] ?? null;
        $text = trim($message['text'] ?? '');
        $message_id = $message['message_id'] ?? null;
        
        // Handle request group auto-delete
        if (handle_request_group_message($chat_id, $text, $message_id)) {
            return;
        }
        
        // Flood control check
        $flood_remaining = is_flood($user_id);
        if ($flood_remaining > 0) {
            $minutes = floor($flood_remaining / 60);
            $seconds = $flood_remaining % 60;
            
            $time_left = '';
            if ($minutes > 0) {
                $time_left .= "$minutes minute" . ($minutes > 1 ? 's' : '');
            }
            if ($seconds > 0) {
                if ($minutes > 0) $time_left .= ' ';
                $time_left .= "$seconds second" . ($seconds > 1 ? 's' : '');
            }
            
            send_message(
                $chat_id,
                "🚫 *Please wait!*\n\nYou can send another request in *$time_left*.",
                'Markdown'
            );
            return;
        }
        
        // Handle commands
        if ($text == "/start") {
            welcome_message($chat_id);
            return;
        }
        
        if ($text == "/help") {
            help_message($chat_id);
            return;
        }
        
        if ($text == "/request") {
            handle_movie_request($chat_id, $user_id, "");
            return;
        }
        
        if (strpos($text, "/request ") === 0) {
            $movie_name = trim(substr($text, 9));
            handle_movie_request($chat_id, $user_id, $movie_name);
            return;
        }
        
        if ($text == "/channels") {
            channels_message($chat_id);
            return;
        }
        
        if ($text == "/totaluploads") {
            deploy_typing($chat_id, 'typing', "📊 Loading total uploads...");
            $page_data = get_movies_page(1);
            
            if ($page_data['total'] === 0) {
                send_message($chat_id, "📭 *No movies found in database.*", 'Markdown');
                return;
            }
            
            $message = "📊 *Total Uploads:* {$page_data['total']}\n";
            $message .= "📄 *Page:* {$page_data['page']}/{$page_data['total_pages']}\n";
            $message .= "📋 *Showing:* {$page_data['start']}-{$page_data['end']} of {$page_data['total']}\n\n";
            
            $counter = $page_data['start'];
            foreach ($page_data['movies'] as $movie) {
                $channel_username = get_channel_username($movie['channel_id']);
                $message .= "$counter. 🎥 {$movie['movie_name']}\n   📤 $channel_username\n";
                $counter++;
            }
            
            send_message(
                $chat_id,
                $message,
                'HTML',
                get_pagination_keyboard($page_data['page'], $page_data['total_pages'])
            );
            return;
        }
        
        if ($text == "/stats") {
            stats_message($chat_id);
            return;
        }
        
        // Admin add command
        if (strpos($text, '/add') === 0 && in_array($user_id, $GLOBALS['ADMINS'])) {
            deploy_typing($chat_id, 'typing', "➕ Adding movie to database...");
            
            $movie_name = trim(substr($text, 4));
            
            if ($message['reply_to_message'] && $message['reply_to_message']['message_id']) {
                $reply = $message['reply_to_message'];
                $channel_id = $reply['chat']['id'] ?? null;
                
                if ($channel_id && in_array($channel_id, $GLOBALS['CHANNELS'])) {
                    $message_id = $reply['message_id'];
                    
                    if (add_movie_to_db($movie_name, $message_id, $channel_id)) {
                        $notified_count = notify_request_users($movie_name, $channel_id);
                        $channel_username = get_channel_username($channel_id);
                        
                        $response = "✅ *Movie Added Successfully!*\n\n";
                        $response .= "🎬 *$movie_name*\n";
                        $response .= "📤 Channel: $channel_username\n";
                        $response .= "🆔 Message ID: `$message_id`\n";
                        $response .= "📊 Total Movies: " . count(get_all_movies()) . "\n";
                        $response .= "🔔 Notified: $notified_count users";
                        
                        send_message($chat_id, $response, 'Markdown');
                    } else {
                        send_message($chat_id, "❌ *Movie already exists in database!*", 'Markdown');
                    }
                } else {
                    send_message($chat_id, "❌ *This channel is not in allowed list!*", 'Markdown');
                }
            } else {
                send_message(
                    $chat_id,
                    "❌ *Please reply to a movie message with* `/add Movie Name`",
                    'Markdown'
                );
            }
            return;
        }
        
        // Invalid query check
        if (is_invalid_query($text)) {
            deploy_typing($chat_id, 'typing', "🔍 Analyzing query...");
            send_message(
                $chat_id,
                "❌ *This bot is only for movies!*\n\n📢 Please type a movie name.",
                'Markdown'
            );
            return;
        }
        
        // Movie search
        if ($text && $text[0] !== '/') {
            deploy_typing($chat_id, 'typing', "🔍 Searching for movies...");
            
            $search_msg = send_message(
                $chat_id,
                "🔍 *Searching:* `" . htmlspecialchars($text) . "`\n\n⏳ Checking all channels...",
                'Markdown'
            );
            
            $results = search_movies($text);
            
            if (isset($search_msg['result']['message_id'])) {
                if (empty($results)) {
                    edit_message_text(
                        $chat_id,
                        $search_msg['result']['message_id'],
                        "❌ *No movies found!*\n\nTry:\n• Different keywords\n• Partial names\n\nOr request it using /request",
                        'Markdown',
                        get_welcome_keyboard()
                    );
                } else {
                    // Send movies with attribution
                    send_batch_with_attribution($chat_id, $results, $text);
                    delete_message($chat_id, $search_msg['result']['message_id']);
                }
            }
            return;
        }
    }
    
    // Handle Callback Query
    if ($callback) {
        $callback_id = $callback['id'];
        $chat_id = $callback['message']['chat']['id'];
        $message_id = $callback['message']['message_id'];
        $user_id = $callback['from']['id'];
        $data = $callback['data'];
        
        // Answer callback query immediately
        answer_callback_query($callback_id);
        
        // Handle different callback actions
        if ($data == "help") {
            help_message($chat_id);
        }
        elseif ($data == "mainmenu") {
            welcome_message($chat_id);
        }
        elseif ($data == "request_movie") {
            request_help_message($chat_id);
        }
        elseif ($data == "submit_request") {
            send_message(
                $chat_id,
                "📝 *Enter Movie Name:*\n\nPlease type the movie name you want to request.\n\nExample: `Movie Name (2024)`",
                'Markdown'
            );
        }
        elseif ($data == "my_requests") {
            $user_requests = get_user_requests($user_id);
            
            if (empty($user_requests)) {
                send_message(
                    $chat_id,
                    "📭 *No Requests Found*\n\nYou haven't submitted any requests yet.\n\nClick below to submit your first request!",
                    'Markdown',
                    get_request_keyboard()
                );
            } else {
                $message = "📋 *Your Requests*\n\n";
                
                foreach ($user_requests as $req) {
                    $status_icon = $req['status'] == 'pending' ? '⏳' : '✅';
                    $message .= "$status_icon *{$req['movie']}*\n";
                    $message .= "🆔 ID: `{$req['id']}`\n";
                    $message .= "📅 Date: {$req['date']}\n";
                    $message .= "📊 Status: " . ucfirst($req['status']);
                    
                    if ($req['status'] == 'completed' && isset($req['channel'])) {
                        $message .= "\n📤 From: {$req['channel']}";
                    }
                    
                    $message .= "\n\n";
                }
                
                send_message($chat_id, $message, 'Markdown', get_request_keyboard());
            }
        }
        elseif ($data == "stats") {
            stats_message($chat_id);
        }
        elseif (strpos($data, 'totaluploads:') === 0) {
            $page = (int) str_replace('totaluploads:', '', $data);
            deploy_typing($chat_id, 'typing', "📄 Loading page...");
            
            $page_data = get_movies_page($page);
            
            if ($page_data['total'] === 0) {
                edit_message_text(
                    $chat_id,
                    $message_id,
                    "📭 *No movies found in database.*",
                    'Markdown'
                );
                return;
            }
            
            $message = "📊 *Total Uploads:* {$page_data['total']}\n";
            $message .= "📄 *Page:* {$page_data['page']}/{$page_data['total_pages']}\n";
            $message .= "📋 *Showing:* {$page_data['start']}-{$page_data['end']} of {$page_data['total']}\n\n";
            
            $counter = $page_data['start'];
            foreach ($page_data['movies'] as $movie) {
                $channel_username = get_channel_username($movie['channel_id']);
                $message .= "$counter. 🎥 {$movie['movie_name']}\n   📤 $channel_username\n";
                $counter++;
            }
            
            edit_message_text(
                $chat_id,
                $message_id,
                $message,
                'HTML',
                get_pagination_keyboard($page_data['page'], $page_data['total_pages'])
            );
        }
        elseif (strpos($data, 'page:') === 0) {
            $page = (int) str_replace('page:', '', $data);
            deploy_typing($chat_id, 'typing', "🔄 Loading page...");
            
            $page_data = get_movies_page($page);
            
            if ($page_data['total'] === 0) {
                edit_message_text(
                    $chat_id,
                    $message_id,
                    "📭 *No movies found in database.*",
                    'Markdown'
                );
                return;
            }
            
            $message = "📊 *Total Uploads:* {$page_data['total']}\n";
            $message .= "📄 *Page:* {$page_data['page']}/{$page_data['total_pages']}\n";
            $message .= "📋 *Showing:* {$page_data['start']}-{$page_data['end']} of {$page_data['total']}\n\n";
            
            $counter = $page_data['start'];
            foreach ($page_data['movies'] as $movie) {
                $channel_username = get_channel_username($movie['channel_id']);
                $message .= "$counter. 🎥 {$movie['movie_name']}\n   📤 $channel_username\n";
                $counter++;
            }
            
            edit_message_text(
                $chat_id,
                $message_id,
                $message,
                'HTML',
                get_pagination_keyboard($page_data['page'], $page_data['total_pages'])
            );
        }
        elseif (strpos($data, 'current:') === 0) {
            answer_callback_query($callback_id, "You are on this page", false);
        }
    }
    
    // Handle channel post (auto-add movies)
    if (isset($update['channel_post'])) {
        $post = $update['channel_post'];
        $channel_id = $post['chat']['id'];
        
        if (in_array($channel_id, $GLOBALS['CHANNELS'])) {
            $text = $post['text'] ?? $post['caption'] ?? '';
            $message_id = $post['message_id'];
            
            // Extract movie name from first line
            $lines = explode("\n", $text);
            $movie_name = trim($lines[0]);
            
            // Clean up movie name
            $movie_name = preg_replace('/[^\x20-\x7E]/u', '', $movie_name);
            $movie_name = trim($movie_name);
            
            if ($movie_name && strlen($movie_name) > 2) {
                add_movie_to_db($movie_name, $message_id, $channel_id);
                log_message("Auto-added from channel: $movie_name (Channel: $channel_id)");
            }
        }
    }
}

// ==================== MAIN EXECUTION =====================
init_system();
process_scheduled_deletes();

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
        $movies = get_all_movies();
        $request_stats = get_request_stats();
        
        echo json_encode([
            'status' => 'online',
            'timestamp' => date('Y-m-d H:i:s'),
            'service' => 'Entertainment Tadka Movie Bot',
            'version' => '4.0',
            'movies_count' => count($movies),
            'requests_total' => $request_stats['total'],
            'requests_pending' => $request_stats['pending'],
            'requests_completed' => $request_stats['completed'],
            'channels_count' => count($GLOBALS['CHANNEL_USERNAMES']),
            'features' => [
                'channel_attribution',
                'auto_delete_system',
                'request_system',
                'pagination',
                'typing_indicators'
            ]
        ]);
    } else {
        http_response_code(400);
        echo 'Invalid request';
    }
}
?>
