<?php
/**
 * ==========================================================
 * TELEGRAM MOVIE BOT - PRODUCTION READY FOR RENDER.COM
 * ==========================================================
 * Webhook based architecture
 * Secure file handling
 * Environment variables support
 * Logging system
 * Error handling
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
if (php_sapi_name() === 'cli') {
    die("CLI access not allowed");
}

// ==================== ENVIRONMENT CONFIG ===================
// Render.com se environment variables use karo
$BOT_TOKEN = getenv('BOT_TOKEN') ?: 'YOUR_BOT_TOKEN_HERE';
$REQUEST_GROUP_ID = getenv('REQUEST_GROUP_ID') ?: '-100XXXXXXXXXX';
$CHANNELS_STRING = getenv('CHANNELS') ?: '-1003251791991,-1002337293281,-1003181705395,-1002831605258,-1002964109368,-1003614546520';

// Parse channels from environment
$CHANNELS = explode(',', $CHANNELS_STRING);
$API_URL = 'https://api.telegram.org/bot' . $BOT_TOKEN . '/';

// ==================== FILE PATHS ===========================
define('CSV_FILE', __DIR__ . '/movies.csv');
define('USERS_JSON', __DIR__ . '/users.json');
define('UPLOADS_DIR', __DIR__ . '/uploads/');

// ==================== CONSTANTS ============================
define('USER_COOLDOWN', 20);
define('PER_PAGE', 5);

// ==================== ADMIN USERS =========================
$ADMINS = [123456789]; // Apne admin IDs yahan add karo

// ==================== INITIAL SETUP =======================
function init_storage() {
    // CSV file initialize karo
    if (!file_exists(CSV_FILE)) {
        file_put_contents(CSV_FILE, "movie_name,message_id,channel_id,added_at\n");
        chmod(CSV_FILE, 0644);
        log_message("CSV file created");
    }
    
    // Users JSON initialize karo
    if (!file_exists(USERS_JSON)) {
        file_put_contents(USERS_JSON, json_encode(['users' => []], JSON_PRETTY_PRINT));
        chmod(USERS_JSON, 0644);
        log_message("Users JSON created");
    }
    
    // Directories create karo
    $dirs = [UPLOADS_DIR, __DIR__ . '/logs', __DIR__ . '/backups'];
    foreach ($dirs as $dir) {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
            chmod($dir, 0755);
        }
    }
}

// ==================== WEBHOOK SETUP ========================
// Agar webhook setup karna ho toh /setwebhook endpoint
if (isset($_GET['action']) && $_GET['action'] === 'setwebhook') {
    $webhook_url = "https://" . $_SERVER['HTTP_HOST'] . $_SERVER['SCRIPT_NAME'];
    $result = file_get_contents($API_URL . 'setWebhook?url=' . urlencode($webhook_url));
    echo json_encode(['status' => 'success', 'result' => json_decode($result), 'webhook' => $webhook_url]);
    log_message("Webhook set: $webhook_url");
    exit;
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

// ==================== CSV FUNCTIONS ========================
function add_movie($movie_name, $message_id, $channel_id) {
    init_storage();
    
    // Duplicate check
    $rows = file(CSV_FILE, FILE_IGNORE_NEW_LINES);
    foreach ($rows as $r) {
        if (strpos($r, ',' . $message_id . ',') !== false) {
            return false;
        }
    }
    
    $fp = fopen(CSV_FILE, 'a');
    fputcsv($fp, [$movie_name, $message_id, $channel_id, date('Y-m-d H:i:s')]);
    fclose($fp);
    
    // Users JSON update (agar needed ho)
    if (file_exists(USERS_JSON)) {
        $users = json_decode(file_get_contents(USERS_JSON), true);
        $users['last_update'] = time();
        file_put_contents(USERS_JSON, json_encode($users, JSON_PRETTY_PRINT));
    }
    
    log_message("Movie added: $movie_name (ID: $message_id)");
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

// ==================== FLOOD CONTROL ========================
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

// ==================== MAIN WEBHOOK HANDLER ================
function handle_update($update) {
    log_message("Update received: " . json_encode($update));
    
    $message = $update['message'] ?? null;
    $callback = $update['callback_query'] ?? null;
    
    // Message handler
    if ($message) {
        $chat_id = $message['chat']['id'];
        $user_id = $message['from']['id'];
        $text = trim($message['text'] ?? '');
        
        // Flood control
        if (is_flood($user_id)) {
            tg('sendMessage', [
                'chat_id' => $chat_id,
                'text' => '⏳ Please wait before sending another request.',
                'parse_mode' => 'HTML'
            ]);
            return;
        }
        
        // START command
        if ($text === '/start') {
            tg('sendMessage', [
                'chat_id' => $chat_id,
                'text' => "🎬 <b>Movie Bot</b>\n\nSearch movies by typing movie name\n\nCommands:\n/totaluploads - Show all movies",
                'parse_mode' => 'HTML'
            ]);
            log_message("User started: $user_id");
        }
        
        // TOTALUPLOADS command
        elseif (strpos($text, '/totaluploads') === 0) {
            $page = (int) trim(str_replace('/totaluploads', '', $text));
            if ($page < 1) $page = 1;
            
            $movies = get_all_movies();
            $total = count($movies);
            $start = ($page - 1) * PER_PAGE;
            $slice = array_slice($movies, $start, PER_PAGE);
            
            $msg = "📊 <b>Total Uploads:</b> $total\n";
            $msg .= "📄 <b>Page:</b> $page/" . ceil($total / PER_PAGE) . "\n\n";
            
            $counter = $start + 1;
            foreach ($slice as $movie) {
                $msg .= "$counter. 🎥 " . htmlspecialchars($movie['movie_name']) . "\n";
                $counter++;
            }
            
            // Pagination buttons
            $keyboard = [];
            if ($start > 0) {
                $keyboard[] = [
                    ['text' => '⬅️ Previous', 'callback_data' => 'page:' . ($page - 1)]
                ];
            }
            if ($start + PER_PAGE < $total) {
                $keyboard[] = [
                    ['text' => 'Next ➡️', 'callback_data' => 'page:' . ($page + 1)]
                ];
            }
            
            tg('sendMessage', [
                'chat_id' => $chat_id,
                'text' => $msg,
                'parse_mode' => 'HTML',
                'reply_markup' => $keyboard ? json_encode(['inline_keyboard' => $keyboard]) : null
            ]);
        }
        
        // SEARCH (non-command text)
        elseif ($text && $text[0] !== '/') {
            $results = search_movie($text);
            
            if (empty($results)) {
                tg('sendMessage', [
                    'chat_id' => $chat_id,
                    'text' => "❌ <b>Movie not found!</b>\n\nTry different keywords.",
                    'parse_mode' => 'HTML'
                ]);
                log_message("Search failed: '$text' by user $user_id");
            } else {
                $count = count($results);
                tg('sendMessage', [
                    'chat_id' => $chat_id,
                    'text' => "✅ Found $count result(s) for: <b>" . htmlspecialchars($text) . "</b>",
                    'parse_mode' => 'HTML'
                ]);
                
                foreach ($results as $result) {
                    tg('copyMessage', [
                        'chat_id' => $chat_id,
                        'from_chat_id' => $result['channel_id'],
                        'message_id' => $result['message_id']
                    ]);
                    usleep(500000); // 0.5 second delay to avoid flood
                }
                
                log_message("Search successful: '$text' found $count results");
            }
        }
        
        // ADMIN: Add movie from channel
        elseif (strpos($text, '/add') === 0 && in_array($user_id, $GLOBALS['ADMINS'])) {
            // Format: /add Movie Name
            $movie_name = trim(substr($text, 4));
            
            if ($message['reply_to_message'] && $message['reply_to_message']['message_id']) {
                $reply = $message['reply_to_message'];
                
                // Check if from allowed channel
                $channel_id = $reply['chat']['id'] ?? null;
                if ($channel_id && in_array($channel_id, $GLOBALS['CHANNELS'])) {
                    $message_id = $reply['message_id'];
                    
                    if (add_movie($movie_name, $message_id, $channel_id)) {
                        tg('sendMessage', [
                            'chat_id' => $chat_id,
                            'text' => "✅ Movie added successfully!\n\n<b>$movie_name</b>\nChannel: $channel_id",
                            'parse_mode' => 'HTML'
                        ]);
                        
                        // Request group mein forward karo
                        if (defined('REQUEST_GROUP_ID')) {
                            tg('forwardMessage', [
                                'chat_id' => REQUEST_GROUP_ID,
                                'from_chat_id' => $channel_id,
                                'message_id' => $message_id
                            ]);
                        }
                    } else {
                        tg('sendMessage', [
                            'chat_id' => $chat_id,
                            'text' => "❌ Movie already exists or failed to add.",
                            'parse_mode' => 'HTML'
                        ]);
                    }
                }
            }
        }
    }
    
    // Callback query handler
    elseif ($callback) {
        $chat_id = $callback['message']['chat']['id'];
        $data = $callback['data'];
        
        // Answer callback query (to remove loading)
        tg('answerCallbackQuery', [
            'callback_query_id' => $callback['id']
        ]);
        
        // Pagination handling
        if (strpos($data, 'page:') === 0) {
            $page = (int) str_replace('page:', '', $data);
            tg('sendMessage', [
                'chat_id' => $chat_id,
                'text' => "/totaluploads $page"
            ]);
        }
    }
    
    // Channel post handler (for auto-adding)
    elseif (isset($update['channel_post'])) {
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
                log_message("Auto-added from channel: $movie_name");
            }
        }
    }
}

// ==================== MAIN EXECUTION ======================
init_storage();
log_message("Bot started. Method: " . $_SERVER['REQUEST_METHOD']);

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
        echo json_encode([
            'status' => 'online',
            'timestamp' => date('Y-m-d H:i:s'),
            'movies_count' => count(get_all_movies()),
            'service' => 'Telegram Movie Bot'
        ]);
    } else {
        http_response_code(400);
        echo 'Invalid request';
        log_message("Invalid request received", 'ERROR');
    }
}
?>
