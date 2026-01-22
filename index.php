<?php
/* 
|--------------------------------------------------------------------------
| 🎬 Entertainment Tadka Telegram Bot - COMPLETE VERSION
|--------------------------------------------------------------------------
| Includes: Movie Search + Request System + Auto-Delete + Progress Bars
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

// ==================== CONSTANTS ==========================
define('CSV_FILE', __DIR__ . '/movies.csv');
define('REQUEST_FILE', __DIR__ . '/movie_requests.json');
define('USER_COOLDOWN', 20);
define('PER_PAGE', 5);
define('AUTO_DELETE_MINUTES', 5);
define('AUTO_DELETE_SECONDS', AUTO_DELETE_MINUTES * 60);

// ==================== ADMIN USERS ========================
$ADMINS = [123456789]; // YOUR_TELEGRAM_USER_ID

// ==================== REQUEST SYSTEM FUNCTIONS ===========
function init_request_system() {
    // Request file
    if (!file_exists(REQUEST_FILE)) {
        file_put_contents(REQUEST_FILE, json_encode([
            'requests' => [],
            'stats' => ['total' => 0, 'pending' => 0, 'completed' => 0]
        ], JSON_PRETTY_PRINT));
    }
    
    // CSV file
    if (!file_exists(CSV_FILE)) {
        file_put_contents(CSV_FILE, "movie_name,message_id,channel_id\n");
    }
    
    // Logs directory
    if (!is_dir(__DIR__ . '/logs')) {
        mkdir(__DIR__ . '/logs', 0755, true);
    }
}

function movie_exists_in_db($movie_name) {
    if (!file_exists(CSV_FILE)) return false;
    
    $search_name = strtolower(trim($movie_name));
    $handle = fopen(CSV_FILE, 'r');
    
    if ($handle === FALSE) return false;
    
    fgetcsv($handle); // Skip header
    
    while (($row = fgetcsv($handle)) !== FALSE) {
        if (count($row) > 0) {
            $db_name = strtolower(trim($row[0]));
            
            // Exact match
            if ($db_name === $search_name) {
                fclose($handle);
                return [
                    'name' => $row[0],
                    'message_id' => $row[1],
                    'channel_id' => $row[2]
                ];
            }
            
            // Partial match
            if (strpos($db_name, $search_name) !== false || 
                strpos($search_name, $db_name) !== false) {
                fclose($handle);
                return [
                    'name' => $row[0],
                    'message_id' => $row[1],
                    'channel_id' => $row[2]
                ];
            }
        }
    }
    
    fclose($handle);
    return false;
}

function add_movie_request($user_id, $movie_name) {
    // Check if movie already exists
    $exists = movie_exists_in_db($movie_name);
    if ($exists) {
        return ['status' => 'exists', 'data' => $exists];
    }
    
    // Load requests
    $data = json_decode(file_get_contents(REQUEST_FILE), true);
    
    // Check duplicate request
    foreach ($data['requests'] as $req) {
        if ($req['user_id'] == $user_id && 
            strtolower($req['movie']) == strtolower($movie_name) &&
            $req['status'] == 'pending') {
            return ['status' => 'duplicate', 'id' => $req['id']];
        }
    }
    
    // Create new request
    $request_id = 'REQ' . date('Ymd') . rand(1000, 9999);
    $new_request = [
        'id' => $request_id,
        'user_id' => $user_id,
        'movie' => trim($movie_name),
        'date' => date('Y-m-d H:i:s'),
        'status' => 'pending'
    ];
    
    // Add to data
    $data['requests'][] = $new_request;
    $data['stats']['total']++;
    $data['stats']['pending']++;
    
    // Save
    file_put_contents(REQUEST_FILE, json_encode($data, JSON_PRETTY_PRINT));
    
    // Notify admin
    $admin_id = getenv('ADMIN_ID');
    if ($admin_id) {
        $msg = "📥 New Movie Request\n\n";
        $msg .= "🎬 $movie_name\n";
        $msg .= "👤 User: $user_id\n";
        $msg .= "🆔 ID: $request_id\n";
        $msg .= "⏰ " . date('H:i:s');
        send_telegram_message($admin_id, $msg);
    }
    
    return ['status' => 'added', 'id' => $request_id];
}

function notify_request_users($movie_name, $channel_id) {
    $data = json_decode(file_get_contents(REQUEST_FILE), true);
    $count = 0;
    
    foreach ($data['requests'] as &$req) {
        if ($req['status'] == 'pending') {
            $req_movie = strtolower($req['movie']);
            $new_movie = strtolower($movie_name);
            
            // Check match
            if ($req_movie == $new_movie || 
                strpos($new_movie, $req_movie) !== false ||
                strpos($req_movie, $new_movie) !== false) {
                
                // Send notification
                $msg = "✅ Request Completed!\n\n";
                $msg .= "🎬 $req[movie]\n";
                $msg .= "📅 Added: " . date('d-m-Y') . "\n";
                $msg .= "🎭 Available in our channels\n\n";
                $msg .= "🔍 Search for it now in the bot!";
                
                send_telegram_message($req['user_id'], $msg);
                
                // Update status
                $req['status'] = 'completed';
                $req['completed_date'] = date('Y-m-d H:i:s');
                $count++;
            }
        }
    }
    
    // Update stats if any changes
    if ($count > 0) {
        $data['stats']['completed'] += $count;
        $data['stats']['pending'] -= $count;
        file_put_contents(REQUEST_FILE, json_encode($data, JSON_PRETTY_PRINT));
    }
    
    return $count;
}

// ==================== LOGGING ============================
function log_message($message, $type = 'INFO') {
    $log_file = __DIR__ . '/logs/bot_' . date('Y-m-d') . '.log';
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "[$timestamp] [$type] $message" . PHP_EOL;
    file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
}

// ==================== TELEGRAM API =======================
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
        log_message("API Error: " . $e->getMessage(), 'ERROR');
        return false;
    }
}

function send_telegram_message($chat_id, $text, $keyboard = null) {
    global $API_URL;
    
    $data = [
        'chat_id' => $chat_id,
        'text' => $text,
        'parse_mode' => 'HTML'
    ];
    
    if ($keyboard) {
        $data['reply_markup'] = json_encode($keyboard);
    }
    
    $url = $API_URL . 'sendMessage';
    $options = [
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/json\r\n",
            'content' => json_encode($data)
        ]
    ];
    
    $context = stream_context_create($options);
    @file_get_contents($url, false, $context);
}

// ==================== DELETYPING FUNCTION ================
function deploy_typing($chat_id, $action = 'typing', $progress_text = null) {
    global $API_URL;
    
    // Send typing action
    tg('sendChatAction', [
        'chat_id' => $chat_id,
        'action' => $action
    ]);
    
    // If progress text provided, send progress message with ETA
    if ($progress_text) {
        $eta = 3; // Estimated seconds for operation
        $progress_msg = tg('sendMessage', [
            'chat_id' => $chat_id,
            'text' => $progress_text . "\n\n⏳ ETA: $eta seconds...",
            'parse_mode' => 'HTML'
        ]);
        
        // Return message ID for possible deletion
        return $progress_msg['result']['message_id'] ?? null;
    }
    
    return null;
}

// ==================== AUTO-DELETE SYSTEM =================
function schedule_auto_delete($chat_id, $message_id, $delay_seconds = AUTO_DELETE_SECONDS) {
    $delete_file = __DIR__ . '/delete_schedule.json';
    $schedule = [];
    
    if (file_exists($delete_file)) {
        $schedule = json_decode(file_get_contents($delete_file), true);
    }
    
    $delete_time = time() + $delay_seconds;
    $schedule[] = [
        'chat_id' => $chat_id,
        'message_id' => $message_id,
        'delete_at' => $delete_time
    ];
    
    file_put_contents($delete_file, json_encode($schedule, JSON_PRETTY_PRINT));
    
    // Start progress updates
    start_progress_updates($chat_id, $message_id, $delay_seconds);
}

function start_progress_updates($chat_id, $message_id, $total_seconds) {
    $progress_file = __DIR__ . '/progress_tracking.json';
    $progress_data = [];
    
    if (file_exists($progress_file)) {
        $progress_data = json_decode(file_get_contents($progress_file), true);
    }
    
    $progress_data[$chat_id . '_' . $message_id] = [
        'start_time' => time(),
        'end_time' => time() + $total_seconds,
        'total_seconds' => $total_seconds,
        'last_update' => time()
    ];
    
    file_put_contents($progress_file, json_encode($progress_data, JSON_PRETTY_PRINT));
}

function process_scheduled_deletes() {
    $delete_file = __DIR__ . '/delete_schedule.json';
    
    if (!file_exists($delete_file)) {
        return;
    }
    
    $schedule = json_decode(file_get_contents($delete_file), true);
    $now = time();
    $updated_schedule = [];
    
    foreach ($schedule as $item) {
        if ($now >= $item['delete_at']) {
            tg('deleteMessage', [
                'chat_id' => $item['chat_id'],
                'message_id' => $item['message_id']
            ]);
            log_message("Auto-deleted message {$item['message_id']} from chat {$item['chat_id']}");
        } else {
            $updated_schedule[] = $item;
        }
    }
    
    file_put_contents($delete_file, json_encode($updated_schedule, JSON_PRETTY_PRINT));
}

// ==================== REQUEST GROUP HANDLER ==============
function handle_request_group_message($chat_id, $text, $message_id) {
    global $REQUEST_GROUP_ID;
    
    // Check if this is the request group
    if ($chat_id != $REQUEST_GROUP_ID) {
        return false;
    }
    
    // Check if message is from bot
    $update = json_decode(file_get_contents("php://input"), true);
    $message = $update['message'] ?? null;
    
    if ($message && isset($message['from']['is_bot']) && $message['from']['is_bot']) {
        // This is bot's message in request group
        $auto_delete_msg = tg('sendMessage', [
            'chat_id' => $chat_id,
            'text' => "⏳ This message will auto-delete in " . AUTO_DELETE_MINUTES . " minutes\n\n📌 ᴘʟᴇᴀsᴇ ꜰᴏʀᴡᴀʀᴅ ᴛʜɪs ꜰɪʟᴇs ᴛᴏ ᴛʜᴇ sᴀᴠᴇᴅ ᴍᴇssᴀɢᴇ ᴀɴᴅ ᴄʟᴏsᴇ ᴛʜɪs ᴍᴇssᴀɢᴇ",
            'parse_mode' => 'HTML',
            'reply_to_message_id' => $message_id
        ]);
        
        if (isset($auto_delete_msg['result']['message_id'])) {
            schedule_auto_delete($chat_id, $auto_delete_msg['result']['message_id']);
        }
        
        return true;
    }
    
    return false;
}

// ==================== MOVIE DATABASE FUNCTIONS ===========
function add_movie_to_db($movie_name, $message_id, $channel_id) {
    // Duplicate check
    $rows = file(CSV_FILE, FILE_IGNORE_NEW_LINES);
    foreach ($rows as $r) {
        $cols = str_getcsv($r);
        if (isset($cols[1]) && $cols[1] == $message_id) {
            return false;
        }
    }
    
    $fp = fopen(CSV_FILE, 'a');
    fputcsv($fp, [$movie_name, $message_id, $channel_id]);
    fclose($fp);
    
    // Notify users who requested this movie
    $notified_count = notify_request_users($movie_name, $channel_id);
    
    log_message("Movie added: $movie_name (ID: $message_id), Notified: $notified_count users");
    return true;
}

function get_all_movies() {
    $movies = [];
    if (($h = fopen(CSV_FILE, 'r')) !== false) {
        fgetcsv($h);
        while (($d = fgetcsv($h)) !== false) {
            if (count($d) >= 3) {
                $movies[] = [
                    'movie_name' => $d[0],
                    'message_id' => $d[1],
                    'channel_id' => $d[2]
                ];
            }
        }
        fclose($h);
    }
    return $movies;
}

function search_movie($query) {
    $query = strtolower(trim($query));
    $results = [];
    
    foreach (get_all_movies() as $movie) {
        if (strpos(strtolower($movie['movie_name']), $query) !== false) {
            $results[] = $movie;
        }
    }
    
    return $results;
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
            'total_pages' => 0
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

// ==================== BUTTONS SYSTEM =====================
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
            ['text' => '📢 Join Channel', 'url' => 'https://t.me/EntertainmentTadka786'],
            ['text' => '📥 Request Group', 'url' => 'https://t.me/EntertainmentTadka7860']
        ],
        [
            ['text' => '🎭 Theatre Prints', 'url' => 'https://t.me/threater_print_movies'],
            ['text' => '🔒 Backup Channel', 'url' => 'https://t.me/ETBackup']
        ],
        [
            ['text' => '❓ Help', 'callback_data' => 'help']
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
    
    if ($current_page > 1) {
        $row[] = ['text' => '◀️ Prev', 'callback_data' => 'page:' . ($current_page - 1)];
    }
    
    $row[] = ['text' => "📄 $current_page/$total_pages", 'callback_data' => 'current:' . $current_page];
    
    if ($current_page < $total_pages) {
        $row[] = ['text' => 'Next ▶️', 'callback_data' => 'page:' . ($current_page + 1)];
    }
    
    $keyboard[] = $row;
    
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

// ==================== MESSAGES ===========================
function welcomeMessage($chat_id) {
    $total_movies = count(get_all_movies());
    
    $text = "
┌──────────────────────────────────────┐
│  🎬 <b>Welcome to Entertainment Tadka!</b>
│
│  📊 <b>Total Movies Available:</b> $total_movies
│
│  📢 <b>How to use this bot:</b>
│  • Simply type any movie name
│  • English & Hindi dono supported
│  • Theater prints ke liye 'theater' add karo
│  • Partial movie names bhi kaam karte hain
│
│  🔍 <b>Examples:</b>
│  • Mandala Murders 2025
│  • Lokah Chapter 1 Chandra 2025
│  • Idli Kadai (2025)
│  • IT - Welcome to Derry (2025) S01
│  • hindi movie
│  • kgf theater print
│
│  ❌ <b>Don't type:</b>
│  • Technical questions
│  • Player instructions
│  • Non-movie queries
│
│  📢 <b>Join Our Channels:</b>
│  🍿 Main: @EntertainmentTadka786
│  📥 Requests: @EntertainmentTadka7860
│  🎭 Theater Prints: @threater_print_movies
│  🔒 Backup: @ETBackup
│
│  💬 Need help? Use /help
└──────────────────────────────────────┘
";
    
    deploy_typing($chat_id, 'typing', "🎬 Loading Entertainment Tadka...");
    tg('sendMessage', [
        'chat_id' => $chat_id,
        'text' => $text,
        'parse_mode' => 'HTML',
        'reply_markup' => json_encode(get_welcome_keyboard())
    ]);
}

function helpMessage($chat_id) {
    $text = "
❓ <b>Help Section</b>

🎬 <b>Search Movies:</b>
• Type movie name
• Partial names work
• Add 'theater' for theater prints

📥 <b>Request Movies:</b>
• Use /request command
• Request movies not available
• Get notified when added

📊 <b>Total Uploads:</b>
• /totaluploads - See all movies
• Paginated for easy browsing

📢 <b>Our Channels:</b>
• /channels - View all channels

❌ <b>Don't ask:</b>
• Technical questions
• Player instructions
• Non-movie queries

👉 <b>Only movies related!</b>
";
    
    deploy_typing($chat_id, 'typing', "📖 Loading help guide...");
    tg('sendMessage', [
        'chat_id' => $chat_id,
        'text' => $text,
        'parse_mode' => 'HTML',
        'reply_markup' => json_encode(get_welcome_keyboard())
    ]);
}

function requestHelpMessage($chat_id) {
    $text = "
📝 <b>REQUEST A MOVIE</b>

🔧 <b>Usage:</b> /request movie_name

📋 <b>Examples:</b>
• /request Animal Park
• /request KGF 3
• /request New Hindi Movie

✨ <b>Features:</b>
✅ Unlimited requests
✅ Auto-check if exists
✅ Auto-notification when added

📢 <b>Our Channels:</b>
🍿 Main: @EntertainmentTadka786
📥 Request: @EntertainmentTadka7860
🎭 Theater: @threater_print_movies
📂 Backup: @ETBackup

💡 <b>Tip:</b> Be specific with movie names!

Click below to submit request:
";
    
    tg('sendMessage', [
        'chat_id' => $chat_id,
        'text' => $text,
        'parse_mode' => 'HTML',
        'reply_markup' => json_encode(get_request_keyboard())
    ]);
}

function channelsMessage($chat_id) {
    $text = "
📢 <b>OUR CHANNELS</b>

🍿 <b>Main:</b> @EntertainmentTadka786
Latest movies & web series

📥 <b>Request:</b> @EntertainmentTadka7860
Request movies & support

🎭 <b>Theater:</b> @threater_print_movies
HD theater prints

📂 <b>Backup:</b> @ETBackup
System backups

💡 Join all channels for best experience!
";
    
    tg('sendMessage', [
        'chat_id' => $chat_id,
        'text' => $text,
        'parse_mode' => 'HTML',
        'reply_markup' => json_encode(get_welcome_keyboard())
    ]);
}

// ==================== REQUEST HANDLER ====================
function handleMovieRequest($chat_id, $user_id, $input) {
    if (empty($input)) {
        requestHelpMessage($chat_id);
        return;
    }
    
    $movie_name = trim($input);
    
    // Check if movie already exists
    $exists = movie_exists_in_db($movie_name);
    if ($exists) {
        $msg = "✅ <b>MOVIE ALREADY AVAILABLE!</b>\n\n";
        $msg .= "🎬 <b>$exists[name]</b>\n";
        $msg .= "📊 Available in our database\n\n";
        $msg .= "🔍 <b>Search for it:</b>\n";
        $msg .= "Type: $exists[name]\n\n";
        $msg .= "📥 <b>Download from our channels</b>";
        
        tg('sendMessage', [
            'chat_id' => $chat_id,
            'text' => $msg,
            'parse_mode' => 'HTML'
        ]);
        return;
    }
    
    // Add request
    $result = add_movie_request($user_id, $movie_name);
    
    if ($result['status'] == 'added') {
        $msg = "✅ <b>REQUEST SUBMITTED!</b>\n\n";
        $msg .= "🎬 <b>$movie_name</b>\n";
        $msg .= "🆔 ID: <code>$result[id]</code>\n";
        $msg .= "📅 Date: " . date('d-m-Y H:i:s') . "\n\n";
        $msg .= "🔔 <b>What happens next?</b>\n";
        $msg .= "1. We add it within 24 hours\n";
        $msg .= "2. You get auto-notification\n";
        $msg .= "3. Download from our channels\n\n";
        $msg .= "📊 Status: ⏳ Pending\n\n";
        $msg .= "📢 <b>Our Channels:</b>\n";
        $msg .= "🍿 Main: @EntertainmentTadka786\n";
        $msg .= "📥 Request: @EntertainmentTadka7860\n";
        $msg .= "🎭 Theater: @threater_print_movies\n";
        $msg .= "📂 Backup: @ETBackup\n\n";
        $msg .= "❤️ Thank you for your request!";
        
        tg('sendMessage', [
            'chat_id' => $chat_id,
            'text' => $msg,
            'parse_mode' => 'HTML'
        ]);
        
    } elseif ($result['status'] == 'duplicate') {
        $msg = "⏳ <b>REQUEST ALREADY PENDING</b>\n\n";
        $msg .= "🎬 $movie_name\n";
        $msg .= "🆔 ID: <code>$result[id]</code>\n\n";
        $msg .= "✅ Already in our system\n";
        $msg .= "⏳ Status: Pending\n\n";
        $msg .= "🔔 You'll be notified when added!\n\n";
        $msg .= "📢 Follow: @EntertainmentTadka7860";
        
        tg('sendMessage', [
            'chat_id' => $chat_id,
            'text' => $msg,
            'parse_mode' => 'HTML'
        ]);
    }
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
function isInvalidQuery($text) {
    $blocked = [
        "how", "php", "bot", "telegram", "code",
        "player", "vlc", "mx", "download link",
        "technical", "error", "source", "github"
    ];
    
    foreach ($blocked as $word) {
        if (stripos($text, $word) !== false) {
            return true;
        }
    }
    return false;
}

// ==================== MAIN HANDLER =======================
$update = json_decode(file_get_contents("php://input"), true);

if (!$update) {
    // Health check and process scheduled deletes
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        init_request_system();
        process_scheduled_deletes();
        
        $movies = get_all_movies();
        $requests_data = json_decode(file_get_contents(REQUEST_FILE), true);
        
        echo json_encode([
            'status' => 'online',
            'timestamp' => date('Y-m-d H:i:s'),
            'movies_count' => count($movies),
            'requests_total' => $requests_data['stats']['total'],
            'requests_pending' => $requests_data['stats']['pending'],
            'requests_completed' => $requests_data['stats']['completed'],
            'auto_delete_enabled' => true,
            'service' => 'Entertainment Tadka Movie Bot'
        ]);
    }
    exit;
}

init_request_system();
process_scheduled_deletes();
log_message("Update received");

$message = $update['message'] ?? null;
$callback = $update['callback_query'] ?? null;

// 📨 Handle Messages
if ($message) {
    $chat_id = $message['chat']['id'];
    $user_id = $message['from']['id'] ?? null;
    $text = trim($message['text'] ?? "");
    $message_id = $message['message_id'] ?? null;

    // Handle request group auto-delete
    if (handle_request_group_message($chat_id, $text, $message_id)) {
        exit;
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
        
        tg('sendMessage', [
            'chat_id' => $chat_id,
            'text' => "🚫 <b>Please wait!</b>\n\nYou can send another request in <b>$time_left</b>.",
            'parse_mode' => 'HTML'
        ]);
        exit;
    }

    if ($text == "/start") {
        welcomeMessage($chat_id);
        exit;
    }

    if ($text == "/help") {
        helpMessage($chat_id);
        exit;
    }

    if ($text == "/request") {
        handleMovieRequest($chat_id, $user_id, "");
        exit;
    }

    if (strpos($text, "/request ") === 0) {
        $movie_name = trim(substr($text, 9));
        handleMovieRequest($chat_id, $user_id, $movie_name);
        exit;
    }

    if ($text == "/channels") {
        channelsMessage($chat_id);
        exit;
    }

    if ($text == "/totaluploads") {
        deploy_typing($chat_id, 'typing', "📊 Loading total uploads...");
        $page_data = get_movies_page(1);
        
        if ($page_data['total'] === 0) {
            tg('sendMessage', [
                'chat_id' => $chat_id,
                'text' => "📭 <b>No movies found in database.</b>",
                'parse_mode' => 'HTML'
            ]);
            exit;
        }
        
        $msg = "📊 <b>Total Uploads:</b> {$page_data['total']}\n";
        $msg .= "📄 <b>Page:</b> {$page_data['page']}/{$page_data['total_pages']}\n";
        $msg .= "📋 <b>Showing:</b> {$page_data['start']}-{$page_data['end']} of {$page_data['total']}\n\n";
        
        $counter = $page_data['start'];
        foreach ($page_data['movies'] as $movie) {
            $msg .= "$counter. 🎥 " . htmlspecialchars($movie['movie_name']) . "\n";
            $counter++;
        }
        
        tg('sendMessage', [
            'chat_id' => $chat_id,
            'text' => $msg,
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode(get_pagination_keyboard($page_data['page'], $page_data['total_pages']))
        ]);
        exit;
    }

    if ($text == "/stats") {
        deploy_typing($chat_id, 'typing', "📈 Loading statistics...");
        $movies = get_all_movies();
        $total = count($movies);
        $total_pages = get_total_pages();
        
        $requests_data = json_decode(file_get_contents(REQUEST_FILE), true);
        
        $message = "📊 <b>Bot Statistics</b>\n\n";
        $message .= "🎬 <b>Total Movies:</b> $total\n";
        $message .= "📄 <b>Total Pages:</b> $total_pages\n\n";
        $message .= "📥 <b>Requests:</b>\n";
        $message .= "• Total: {$requests_data['stats']['total']}\n";
        $message .= "• Pending: {$requests_data['stats']['pending']}\n";
        $message .= "• Completed: {$requests_data['stats']['completed']}\n\n";
        $message .= "⏰ <b>Auto-Delete:</b> " . AUTO_DELETE_MINUTES . " minutes\n";
        $message .= "⚡ <b>Status:</b> Online ✅";
        
        tg('sendMessage', [
            'chat_id' => $chat_id,
            'text' => $message,
            'parse_mode' => 'HTML'
        ]);
        exit;
    }

    if (isInvalidQuery($text)) {
        deploy_typing($chat_id, 'typing', "🔍 Analyzing query...");
        tg('sendMessage', [
            'chat_id' => $chat_id,
            'text' => "❌ Ye bot sirf <b>movies ke liye</b> hai.\n📢 Please movie ka naam type karo.",
            'parse_mode' => 'HTML'
        ]);
        exit;
    }

    // Admin add command
    if (strpos($text, '/add') === 0 && in_array($user_id, $ADMINS)) {
        deploy_typing($chat_id, 'typing', "➕ Adding movie to database...");
        
        $movie_name = trim(substr($text, 4));
        
        if ($message['reply_to_message'] && $message['reply_to_message']['message_id']) {
            $reply = $message['reply_to_message'];
            $channel_id = $reply['chat']['id'] ?? null;
            
            if ($channel_id && in_array($channel_id, $CHANNELS)) {
                $message_id = $reply['message_id'];
                
                if (add_movie_to_db($movie_name, $message_id, $channel_id)) {
                    tg('sendMessage', [
                        'chat_id' => $chat_id,
                        'text' => "✅ <b>Movie Added Successfully!</b>\n\n📽️ <b>$movie_name</b>\n📡 Channel: <code>$channel_id</code>\n🆔 Message ID: <code>$message_id</code>\n📊 Total Movies Now: " . count(get_all_movies()),
                        'parse_mode' => 'HTML'
                    ]);
                } else {
                    tg('sendMessage', [
                        'chat_id' => $chat_id,
                        'text' => "❌ Movie already exists in database!",
                        'parse_mode' => 'HTML'
                    ]);
                }
            } else {
                tg('sendMessage', [
                    'chat_id' => $chat_id,
                    'text' => "❌ This channel is not in allowed list!",
                    'parse_mode' => 'HTML'
                ]);
            }
        } else {
            tg('sendMessage', [
                'chat_id' => $chat_id,
                'text' => "❌ Please reply to a movie message with <code>/add Movie Name</code>",
                'parse_mode' => 'HTML'
            ]);
        }
        exit;
    }

    // 🎬 Movie search
    if ($text && $text[0] !== '/') {
        deploy_typing($chat_id, 'typing', "🔍 Searching for movies...");
        
        $search_msg = tg('sendMessage', [
            'chat_id' => $chat_id,
            'text' => "🔍 <b>Searching:</b> <i>" . htmlspecialchars($text) . "</i>\n\n⏳ ETA: 3 seconds...",
            'parse_mode' => 'HTML'
        ]);
        
        $results = search_movie($text);
        
        // Update search message with results
        if (isset($search_msg['result']['message_id'])) {
            if (empty($results)) {
                tg('editMessageText', [
                    'chat_id' => $chat_id,
                    'message_id' => $search_msg['result']['message_id'],
                    'text' => "❌ <b>No movies found!</b>\n\nTry:\n• Different keywords\n• Partial names\n• Check spelling\n\nOr use buttons below:",
                    'parse_mode' => 'HTML',
                    'reply_markup' => json_encode(get_welcome_keyboard())
                ]);
            } else {
                $count = count($results);
                tg('editMessageText', [
                    'chat_id' => $chat_id,
                    'message_id' => $search_msg['result']['message_id'],
                    'text' => "✅ <b>Found $count result(s)</b> for: <b>" . htmlspecialchars($text) . "</b>\n\nSending movies...",
                    'parse_mode' => 'HTML'
                ]);
                
                foreach ($results as $index => $result) {
                    if ($index > 0) sleep(1);
                    tg('copyMessage', [
                        'chat_id' => $chat_id,
                        'from_chat_id' => $result['channel_id'],
                        'message_id' => $result['message_id']
                    ]);
                }
                
                tg('sendMessage', [
                    'chat_id' => $chat_id,
                    'text' => "🎉 <b>Search Complete!</b>\n\nFound <b>$count movies</b> for your search.\n\nNeed more? Try another search!",
                    'parse_mode' => 'HTML',
                    'reply_markup' => json_encode(get_welcome_keyboard())
                ]);
            }
        }
        exit;
    }
}

// 🔁 Callback Handling
if ($callback) {
    $message_id = $callback['message']['message_id'];
    $chat_id = $callback['message']['chat']['id'];
    $user_id = $callback['from']['id'];
    $data = $callback['data'];

    // Answer callback query immediately
    tg('answerCallbackQuery', [
        'callback_query_id' => $callback['id']
    ]);

    if ($data == "HELP" || $data == "help") {
        helpMessage($chat_id);
    }
    
    elseif ($data == "mainmenu") {
        welcomeMessage($chat_id);
    }
    
    elseif ($data == "request_movie") {
        requestHelpMessage($chat_id);
    }
    
    elseif ($data == "submit_request") {
        // Show input prompt for movie name
        tg('sendMessage', [
            'chat_id' => $chat_id,
            'text' => "📝 <b>Enter Movie Name:</b>\n\nPlease type the movie name you want to request.\n\nExample: <code>Movie Name (2024)</code>",
            'parse_mode' => 'HTML'
        ]);
    }
    
    elseif ($data == "my_requests") {
        $requests_data = json_decode(file_get_contents(REQUEST_FILE), true);
        $user_requests = [];
        
        foreach ($requests_data['requests'] as $req) {
            if ($req['user_id'] == $user_id) {
                $user_requests[] = $req;
            }
        }
        
        if (empty($user_requests)) {
            $msg = "📭 <b>No Requests Found</b>\n\nYou haven't submitted any requests yet.\n\nClick below to submit your first request!";
            tg('sendMessage', [
                'chat_id' => $chat_id,
                'text' => $msg,
                'parse_mode' => 'HTML',
                'reply_markup' => json_encode(get_request_keyboard())
            ]);
        } else {
            $msg = "📋 <b>Your Requests</b>\n\n";
            foreach ($user_requests as $req) {
                $status_icon = $req['status'] == 'pending' ? '⏳' : '✅';
                $msg .= "$status_icon <b>{$req['movie']}</b>\n";
                $msg .= "🆔 ID: <code>{$req['id']}</code>\n";
                $msg .= "📅 Date: {$req['date']}\n";
                $msg .= "📊 Status: " . ucfirst($req['status']) . "\n\n";
            }
            
            tg('sendMessage', [
                'chat_id' => $chat_id,
                'text' => $msg,
                'parse_mode' => 'HTML',
                'reply_markup' => json_encode(get_request_keyboard())
            ]);
        }
    }
    
    elseif (strpos($data, 'totaluploads:') === 0) {
        deploy_typing($chat_id, 'typing', "📄 Loading page...");
        $page = (int) str_replace('totaluploads:', '', $data);
        $page_data = get_movies_page($page);
        
        if ($page_data['total'] === 0) {
            tg('editMessageText', [
                'chat_id' => $chat_id,
                'message_id' => $message_id,
                'text' => "📭 <b>No movies found in database.</b>",
                'parse_mode' => 'HTML'
            ]);
            return;
        }
        
        $msg = "📊 <b>Total Uploads:</b> {$page_data['total']}\n";
        $msg .= "📄 <b>Page:</b> {$page_data['page']}/{$page_data['total_pages']}\n";
        $msg .= "📋 <b>Showing:</b> {$page_data['start']}-{$page_data['end']} of {$page_data['total']}\n\n";
        
        $counter = $page_data['start'];
        foreach ($page_data['movies'] as $movie) {
            $msg .= "$counter. 🎥 " . htmlspecialchars($movie['movie_name']) . "\n";
            $counter++;
        }
        
        tg('editMessageText', [
            'chat_id' => $chat_id,
            'message_id' => $message_id,
            'text' => $msg,
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode(get_pagination_keyboard($page_data['page'], $page_data['total_pages']))
        ]);
    }
    
    elseif (strpos($data, 'page:') === 0) {
        deploy_typing($chat_id, 'typing', "🔄 Loading page...");
        $page = (int) str_replace('page:', '', $data);
        $page_data = get_movies_page($page);
        
        if ($page_data['total'] === 0) {
            tg('editMessageText', [
                'chat_id' => $chat_id,
                'message_id' => $message_id,
                'text' => "📭 <b>No movies found in database.</b>",
                'parse_mode' => 'HTML'
            ]);
            return;
        }
        
        $msg = "📊 <b>Total Uploads:</b> {$page_data['total']}\n";
        $msg .= "📄 <b>Page:</b> {$page_data['page']}/{$page_data['total_pages']}\n";
        $msg .= "📋 <b>Showing:</b> {$page_data['start']}-{$page_data['end']} of {$page_data['total']}\n\n";
        
        $counter = $page_data['start'];
        foreach ($page_data['movies'] as $movie) {
            $msg .= "$counter. 🎥 " . htmlspecialchars($movie['movie_name']) . "\n";
            $counter++;
        }
        
        tg('editMessageText', [
            'chat_id' => $chat_id,
            'message_id' => $message_id,
            'text' => $msg,
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode(get_pagination_keyboard($page_data['page'], $page_data['total_pages']))
        ]);
    }
    
    elseif (strpos($data, 'current:') === 0) {
        tg('answerCallbackQuery', [
            'callback_query_id' => $callback['id'],
            'text' => 'You are on this page',
            'show_alert' => false
        ]);
    }
}

// Auto-add from channel posts
if (isset($update['channel_post'])) {
    $post = $update['channel_post'];
    $channel_id = $post['chat']['id'];
    
    if (in_array($channel_id, $CHANNELS)) {
        $text = $post['text'] ?? $post['caption'] ?? '';
        $message_id = $post['message_id'];
        
        $lines = explode("\n", $text);
        $movie_name = trim($lines[0]);
        $movie_name = preg_replace('/[^\x20-\x7E]/u', '', $movie_name);
        $movie_name = trim($movie_name);
        
        if ($movie_name && strlen($movie_name) > 2) {
            add_movie_to_db($movie_name, $message_id, $channel_id);
            log_message("Auto-added from channel: $movie_name");
        }
    }
}
?>
