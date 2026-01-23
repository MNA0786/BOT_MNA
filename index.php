<?php
/**
 * ==========================================================
 * TELEGRAM MOVIE BOT - PRO ADVANCED COMPLETE VERSION
 * ==========================================================
 * Complete 1500+ lines implementation with all features
 * Directly deployable on Render.com
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
if (php_sapi_name() === 'cli' && !isset($_GET['setwebhook']) && !isset($_GET['test']) && !isset($_GET['deploy']) && !isset($_GET['check_csv']) && !isset($_GET['test_save'])) {
    die("CLI access not allowed");
}

// ==================== ENVIRONMENT CONFIG ===================
// FORMAT LOCKED: Environment variables only
$BOT_TOKEN = getenv('BOT_TOKEN') ?: 'YOUR_BOT_TOKEN_HERE';
$REQUEST_GROUP_ID = getenv('REQUEST_GROUP_ID') ?: '-1003083386043';
$CHANNELS_STRING = getenv('CHANNELS') ?: '-1003251791991,-1002337293281,-1003181705395,-1002831605258,-1002964109368,-1003614546520';

// Parse channels from environment
$CHANNELS = explode(',', $CHANNELS_STRING);
$API_URL = 'https://api.telegram.org/bot' . $BOT_TOKEN . '/';

// Additional config constants
define('MAIN_CHANNEL_ID', '-1003181705395');
define('THREATER_CHANNEL_ID', '-1002831605258');
define('GROUP_CHANNEL_ID', '-1003083386043');
define('BACKUP_CHANNEL_ID', '-1002964109368');
define('OWNER_ID', '1080317415');

// ==================== FILE PATHS ===========================
define('CSV_FILE', __DIR__ . '/movies.csv'); // FORMAT LOCKED: movie_name,message_id,channel_id
define('USERS_JSON', __DIR__ . '/users.json');
define('STATS_FILE', __DIR__ . '/bot_stats.json');
define('UPLOADS_DIR', __DIR__ . '/uploads/');
define('BACKUP_DIR', __DIR__ . '/backups/');
define('LOGS_DIR', __DIR__ . '/logs/');

// ==================== CONSTANTS ============================
define('USER_COOLDOWN', 10); // seconds between requests
define('PER_PAGE', 5); // items per page in pagination
define('CACHE_EXPIRY', 300); // 5 minutes cache expiry
define('MAINTENANCE_MODE', false); // set to true for maintenance
define('MAX_SEARCH_RESULTS', 50); // maximum search results to return
define('DAILY_LIMIT_PER_USER', 100); // daily search limit per user

// ==================== ADMIN USERS =========================
$ADMINS = [1080317415]; // Add your admin IDs here

// ==================== GLOBAL CACHES =======================
$movie_cache = [];
$waiting_users = [];
$user_daily_counts = [];

// ==================== INITIAL SETUP =======================
function init_storage() {
    // CSV file initialize (FORMAT LOCKED: movie_name,message_id,channel_id)
    if (!file_exists(CSV_FILE)) {
        file_put_contents(CSV_FILE, "movie_name,message_id,channel_id\n");
        chmod(CSV_FILE, 0644);
        log_message("CSV file created with locked format: movie_name,message_id,channel_id");
    }
    
    // Users JSON initialize with enhanced structure
    if (!file_exists(USERS_JSON)) {
        $default_data = [
            'users' => [],
            'stats' => [
                'total_searches' => 0,
                'total_users' => 0,
                'total_requests' => 0,
                'last_updated' => null
            ],
            'daily_stats' => [
                date('Y-m-d') => [
                    'searches' => 0,
                    'new_users' => 0,
                    'movies_added' => 0
                ]
            ],
            'total_requests' => 0,
            'system' => [
                'last_backup' => null,
                'last_cache_clear' => null,
                'version' => '2.0.0'
            ]
        ];
        file_put_contents(USERS_JSON, json_encode($default_data, JSON_PRETTY_PRINT));
        chmod(USERS_JSON, 0644);
        log_message("Users JSON created with enhanced structure");
    }
    
    // Stats file initialize with detailed tracking
    if (!file_exists(STATS_FILE)) {
        $default_stats = [
            'total_movies' => 0,
            'total_users' => 0,
            'total_searches' => 0,
            'total_requests' => 0,
            'movies_by_channel' => [],
            'searches_by_day' => [],
            'last_updated' => date('Y-m-d H:i:s'),
            'performance' => [
                'avg_response_time' => 0,
                'total_uptime' => 0,
                'last_restart' => date('Y-m-d H:i:s')
            ]
        ];
        file_put_contents(STATS_FILE, json_encode($default_stats, JSON_PRETTY_PRINT));
        chmod(STATS_FILE, 0644);
        log_message("Stats file created with detailed tracking");
    }
    
    // Create necessary directories with proper permissions
    $dirs = [
        UPLOADS_DIR => 0755,
        LOGS_DIR => 0755,
        BACKUP_DIR => 0755,
        __DIR__ . '/cache' => 0755,
        __DIR__ . '/temp' => 0755
    ];
    
    foreach ($dirs as $dir => $permissions) {
        if (!is_dir($dir)) {
            mkdir($dir, $permissions, true);
            chmod($dir, $permissions);
            log_message("Directory created: $dir");
        }
    }
    
    // Initialize daily stats if not exists
    $users_data = json_decode(file_get_contents(USERS_JSON), true);
    $today = date('Y-m-d');
    if (!isset($users_data['daily_stats'][$today])) {
        $users_data['daily_stats'][$today] = [
            'searches' => 0,
            'new_users' => 0,
            'movies_added' => 0,
            'requests' => 0
        ];
        file_put_contents(USERS_JSON, json_encode($users_data, JSON_PRETTY_PRINT));
    }
    
    log_message("Storage initialization completed");
}

// ==================== TELEGRAM API FUNCTIONS ==============
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
    
    $start_time = microtime(true);
    
    try {
        $context = stream_context_create($options);
        $response = file_get_contents($url, false, $context);
        $execution_time = microtime(true) - $start_time;
        
        if ($response === false) {
            log_message("API Call Failed: $method - No response received", 'ERROR');
            return false;
        }
        
        $decoded_response = json_decode($response, true);
        
        if (!$decoded_response || !isset($decoded_response['ok'])) {
            log_message("API Invalid Response: $method - " . substr($response, 0, 200), 'ERROR');
            return false;
        }
        
        // Log slow API calls
        if ($execution_time > 2.0) {
            log_message("Slow API Call: $method took " . round($execution_time, 2) . "s", 'WARNING');
        }
        
        log_message("API Success: $method - Time: " . round($execution_time, 2) . "s");
        return $decoded_response;
        
    } catch (Exception $e) {
        $execution_time = microtime(true) - $start_time;
        log_message("API Exception: $method - " . $e->getMessage() . " - Time: " . round($execution_time, 2) . "s", 'ERROR');
        return false;
    }
}

function sendMessage($chat_id, $text, $reply_markup = null, $parse_mode = null, $disable_web_page_preview = false) {
    $data = [
        'chat_id' => $chat_id,
        'text' => $text,
        'disable_web_page_preview' => $disable_web_page_preview
    ];
    
    if ($reply_markup !== null) {
        $data['reply_markup'] = json_encode($reply_markup);
    }
    
    if ($parse_mode !== null) {
        $data['parse_mode'] = $parse_mode;
    }
    
    return tg('sendMessage', $data);
}

function copyMessage($chat_id, $from_chat_id, $message_id, $caption = null, $parse_mode = null) {
    $data = [
        'chat_id' => $chat_id,
        'from_chat_id' => $from_chat_id,
        'message_id' => $message_id
    ];
    
    if ($caption !== null) {
        $data['caption'] = $caption;
    }
    
    if ($parse_mode !== null) {
        $data['parse_mode'] = $parse_mode;
    }
    
    return tg('copyMessage', $data);
}

function forwardMessage($chat_id, $from_chat_id, $message_id, $disable_notification = false) {
    return tg('forwardMessage', [
        'chat_id' => $chat_id,
        'from_chat_id' => $from_chat_id,
        'message_id' => $message_id,
        'disable_notification' => $disable_notification
    ]);
}

function answerCallbackQuery($callback_query_id, $text = null, $show_alert = false, $url = null, $cache_time = null) {
    $data = [
        'callback_query_id' => $callback_query_id,
        'show_alert' => $show_alert
    ];
    
    if ($text !== null) {
        $data['text'] = $text;
    }
    
    if ($url !== null) {
        $data['url'] = $url;
    }
    
    if ($cache_time !== null) {
        $data['cache_time'] = $cache_time;
    }
    
    return tg('answerCallbackQuery', $data);
}

function editMessageText($chat_id, $message_id, $text, $reply_markup = null, $parse_mode = null, $disable_web_page_preview = false) {
    $data = [
        'chat_id' => $chat_id,
        'message_id' => $message_id,
        'text' => $text,
        'disable_web_page_preview' => $disable_web_page_preview
    ];
    
    if ($reply_markup !== null) {
        $data['reply_markup'] = json_encode($reply_markup);
    }
    
    if ($parse_mode !== null) {
        $data['parse_mode'] = $parse_mode;
    }
    
    return tg('editMessageText', $data);
}

function editMessageReplyMarkup($chat_id, $message_id, $reply_markup = null) {
    $data = [
        'chat_id' => $chat_id,
        'message_id' => $message_id
    ];
    
    if ($reply_markup !== null) {
        $data['reply_markup'] = json_encode($reply_markup);
    }
    
    return tg('editMessageReplyMarkup', $data);
}

function deleteMessage($chat_id, $message_id) {
    return tg('deleteMessage', [
        'chat_id' => $chat_id,
        'message_id' => $message_id
    ]);
}

function sendChatAction($chat_id, $action) {
    return tg('sendChatAction', [
        'chat_id' => $chat_id,
        'action' => $action
    ]);
}

// ==================== CSV FUNCTIONS (FORMAT LOCKED) =======
function add_movie($movie_name, $message_id, $channel_id) {
    init_storage();
    
    // Validate inputs
    if (empty(trim($movie_name)) || empty($message_id) || empty($channel_id)) {
        log_message("Invalid movie data provided: Name: $movie_name, ID: $message_id, Channel: $channel_id", 'ERROR');
        return false;
    }
    
    // Check for duplicates based on message_id
    $rows = file(CSV_FILE, FILE_IGNORE_NEW_LINES);
    foreach ($rows as $index => $row) {
        if ($index === 0) continue; // Skip header
        
        $parts = explode(',', $row);
        if (count($parts) >= 2 && trim($parts[1]) == $message_id) {
            log_message("Duplicate movie detected: Message ID $message_id already exists", 'WARNING');
            return false;
        }
    }
    
    // FORMAT LOCKED: movie_name,message_id,channel_id
    $fp = fopen(CSV_FILE, 'a');
    if (flock($fp, LOCK_EX)) {
        fputcsv($fp, [$movie_name, $message_id, $channel_id]);
        flock($fp, LOCK_UN);
    }
    fclose($fp);
    
    // Clear cache to ensure fresh data
    global $movie_cache;
    $movie_cache = [];
    
    // Update stats
    update_stats('total_movies', 1);
    
    // Update channel-specific stats
    $stats = json_decode(file_get_contents(STATS_FILE), true);
    if (!isset($stats['movies_by_channel'][$channel_id])) {
        $stats['movies_by_channel'][$channel_id] = 0;
    }
    $stats['movies_by_channel'][$channel_id]++;
    $stats['last_updated'] = date('Y-m-d H:i:s');
    file_put_contents(STATS_FILE, json_encode($stats, JSON_PRETTY_PRINT));
    
    // Notify waiting users
    global $waiting_users;
    $query_lower = strtolower(trim($movie_name));
    $notified_users = 0;
    
    foreach ($waiting_users as $query => $users) {
        if (strpos($query_lower, $query) !== false || strpos($query, $query_lower) !== false) {
            foreach ($users as $user_data) {
                list($user_chat_id, $user_id) = $user_data;
                
                // Send notification
                sendMessage($user_chat_id, "🎉 Good news! The movie you requested is now available!\n\n🎬 <b>" . htmlspecialchars($movie_name) . "</b>\n\nIt's being sent to you now...", null, 'HTML');
                
                // Deliver the movie
                deliver_item_to_chat($user_chat_id, [
                    'movie_name' => $movie_name,
                    'message_id' => $message_id,
                    'channel_id' => $channel_id
                ]);
                
                $notified_users++;
                
                // Add small delay to avoid rate limiting
                usleep(100000); // 0.1 second
            }
            // Remove notified users
            unset($waiting_users[$query]);
        }
    }
    
    // Update daily stats
    $users_data = json_decode(file_get_contents(USERS_JSON), true);
    $today = date('Y-m-d');
    $users_data['daily_stats'][$today]['movies_added'] = ($users_data['daily_stats'][$today]['movies_added'] ?? 0) + 1;
    file_put_contents(USERS_JSON, json_encode($users_data, JSON_PRETTY_PRINT));
    
    log_message("Movie added successfully: '$movie_name' (ID: $message_id, Channel: $channel_id) - Notified $notified_users users");
    return true;
}

function get_all_movies() {
    init_storage();
    
    if (!file_exists(CSV_FILE) || filesize(CSV_FILE) === 0) {
        return [];
    }
    
    $movies = [];
    if (($handle = fopen(CSV_FILE, 'r')) !== false) {
        // Skip header row
        fgetcsv($handle);
        
        while (($data = fgetcsv($handle)) !== false) {
            if (count($data) >= 3) {
                // FORMAT LOCKED: movie_name,message_id,channel_id
                $movies[] = [
                    'movie_name' => $data[0],
                    'message_id' => $data[1],
                    'channel_id' => $data[2],
                    'index' => count($movies) + 1
                ];
            }
        }
        fclose($handle);
    }
    
    return $movies;
}

function get_cached_movies() {
    global $movie_cache;
    
    if (!empty($movie_cache) && (time() - $movie_cache['timestamp']) < CACHE_EXPIRY) {
        log_message("Returning cached movies (" . count($movie_cache['data']) . " movies)");
        return $movie_cache['data'];
    }
    
    log_message("Refreshing movie cache");
    $movies = get_all_movies();
    $movie_cache = [
        'data' => $movies,
        'timestamp' => time()
    ];
    
    return $movies;
}

function search_movie($query, $limit = MAX_SEARCH_RESULTS) {
    $query = strtolower(trim($query));
    $results = [];
    
    if (strlen($query) < 2) {
        log_message("Search query too short: '$query'", 'WARNING');
        return $results;
    }
    
    log_message("Starting search for: '$query'");
    $movies = get_cached_movies();
    $total_movies = count($movies);
    
    // First pass: Exact matches
    foreach ($movies as $movie) {
        if (strtolower($movie['movie_name']) === $query) {
            $results[] = $movie;
            if (count($results) >= $limit) break;
        }
    }
    
    // Second pass: Partial matches if we need more results
    if (count($results) < $limit) {
        foreach ($movies as $movie) {
            if (strpos(strtolower($movie['movie_name']), $query) !== false) {
                // Avoid duplicates
                $already_exists = false;
                foreach ($results as $existing) {
                    if ($existing['message_id'] == $movie['message_id']) {
                        $already_exists = true;
                        break;
                    }
                }
                
                if (!$already_exists) {
                    $results[] = $movie;
                    if (count($results) >= $limit) break;
                }
            }
        }
    }
    
    // Third pass: Similarity search if still need more results
    if (count($results) < $limit) {
        $similar_results = [];
        foreach ($movies as $movie) {
            similar_text(strtolower($movie['movie_name']), $query, $similarity);
            if ($similarity > 40) { // Lower threshold for more results
                $already_exists = false;
                foreach ($results as $existing) {
                    if ($existing['message_id'] == $movie['message_id']) {
                        $already_exists = true;
                        break;
                    }
                }
                
                if (!$already_exists) {
                    $similar_results[] = [
                        'movie' => $movie,
                        'similarity' => $similarity
                    ];
                }
            }
        }
        
        // Sort by similarity
        usort($similar_results, function($a, $b) {
            return $b['similarity'] - $a['similarity'];
        });
        
        // Add top similar results
        foreach ($similar_results as $similar_result) {
            $results[] = $similar_result['movie'];
            if (count($results) >= $limit) break;
        }
    }
    
    log_message("Search completed for '$query': Found " . count($results) . " results out of $total_movies movies");
    return $results;
}

function smart_search($query, $limit = 10) {
    $query = strtolower(trim($query));
    $results = [];
    
    if (strlen($query) < 2) {
        return $results;
    }
    
    $movies = get_cached_movies();
    
    foreach ($movies as $movie) {
        $movie_lower = strtolower($movie['movie_name']);
        $score = 0;
        
        // Exact match
        if ($movie_lower === $query) {
            $score = 100;
        }
        // Starts with query
        elseif (strpos($movie_lower, $query) === 0) {
            $score = 90;
        }
        // Contains query
        elseif (strpos($movie_lower, $query) !== false) {
            $score = 80;
        }
        // Similar text
        else {
            similar_text($movie_lower, $query, $similarity);
            if ($similarity > 60) {
                $score = $similarity;
            }
        }
        
        if ($score > 0) {
            $results[] = [
                'movie' => $movie,
                'score' => $score
            ];
        }
    }
    
    // Sort by score
    usort($results, function($a, $b) {
        return $b['score'] - $a['score'];
    });
    
    // Return limited results
    return array_slice($results, 0, $limit);
}

function get_movie_by_id($message_id) {
    $movies = get_cached_movies();
    
    foreach ($movies as $movie) {
        if ($movie['message_id'] == $message_id) {
            return $movie;
        }
    }
    
    return null;
}

function get_movies_by_channel($channel_id) {
    $movies = get_cached_movies();
    $channel_movies = [];
    
    foreach ($movies as $movie) {
        if ($movie['channel_id'] == $channel_id) {
            $channel_movies[] = $movie;
        }
    }
    
    return $channel_movies;
}

// ==================== STATS MANAGEMENT ====================
function update_stats($field, $increment = 1, $additional_data = null) {
    if (!file_exists(STATS_FILE)) {
        init_storage();
    }
    
    $stats = json_decode(file_get_contents(STATS_FILE), true);
    
    if (!isset($stats[$field])) {
        $stats[$field] = 0;
    }
    
    $stats[$field] += $increment;
    $stats['last_updated'] = date('Y-m-d H:i:s');
    
    // Update additional data if provided
    if ($additional_data !== null && is_array($additional_data)) {
        foreach ($additional_data as $key => $value) {
            if (!isset($stats[$key])) {
                $stats[$key] = [];
            }
            $stats[$key] = array_merge($stats[$key], $value);
        }
    }
    
    file_put_contents(STATS_FILE, json_encode($stats, JSON_PRETTY_PRINT));
    
    // Also update users.json stats
    $users_data = json_decode(file_get_contents(USERS_JSON), true);
    $today = date('Y-m-d');
    
    if (!isset($users_data['daily_stats'][$today])) {
        $users_data['daily_stats'][$today] = [
            'searches' => 0,
            'new_users' => 0,
            'movies_added' => 0,
            'requests' => 0
        ];
    }
    
    if ($field === 'total_searches') {
        $users_data['daily_stats'][$today]['searches'] += $increment;
    } elseif ($field === 'total_requests') {
        $users_data['daily_stats'][$today]['requests'] += $increment;
    }
    
    $users_data['stats']['last_updated'] = date('Y-m-d H:i:s');
    file_put_contents(USERS_JSON, json_encode($users_data, JSON_PRETTY_PRINT));
}

function get_stats() {
    if (!file_exists(STATS_FILE)) {
        return [];
    }
    
    return json_decode(file_get_contents(STATS_FILE), true);
}

function get_detailed_stats() {
    $stats = get_stats();
    $users_data = json_decode(file_get_contents(USERS_JSON), true);
    $movies = get_cached_movies();
    
    $detailed_stats = [
        'basic' => $stats,
        'users' => [
            'total' => count($users_data['users'] ?? []),
            'today_new' => $users_data['daily_stats'][date('Y-m-d')]['new_users'] ?? 0,
            'active_today' => 0
        ],
        'movies' => [
            'total' => count($movies),
            'by_channel' => [],
            'recent_additions' => array_slice($movies, -10)
        ],
        'performance' => [
            'cache_hits' => 0,
            'api_calls' => 0,
            'avg_response_time' => 0
        ],
        'daily' => $users_data['daily_stats'] ?? []
    ];
    
    // Calculate movies by channel
    foreach ($movies as $movie) {
        $channel_id = $movie['channel_id'];
        if (!isset($detailed_stats['movies']['by_channel'][$channel_id])) {
            $detailed_stats['movies']['by_channel'][$channel_id] = 0;
        }
        $detailed_stats['movies']['by_channel'][$channel_id]++;
    }
    
    return $detailed_stats;
}

// ==================== USER MANAGEMENT ======================
function update_user($user_data, $is_new = false) {
    $users_data = json_decode(file_get_contents(USERS_JSON), true);
    $user_id = $user_data['id'];
    
    $current_time = date('Y-m-d H:i:s');
    $today = date('Y-m-d');
    
    if (!isset($users_data['users'][$user_id])) {
        // New user
        $users_data['users'][$user_id] = [
            'first_name' => $user_data['first_name'] ?? '',
            'last_name' => $user_data['last_name'] ?? '',
            'username' => $user_data['username'] ?? '',
            'language_code' => $user_data['language_code'] ?? 'en',
            'joined' => $current_time,
            'last_active' => $current_time,
            'search_count' => 0,
            'total_requests' => 0,
            'points' => 0,
            'daily_stats' => [
                $today => [
                    'searches' => 0,
                    'requests' => 0
                ]
            ],
            'preferences' => [
                'language' => $user_data['language_code'] ?? 'en',
                'notifications' => true
            ]
        ];
        
        $users_data['stats']['total_users'] = ($users_data['stats']['total_users'] ?? 0) + 1;
        $users_data['daily_stats'][$today]['new_users'] = ($users_data['daily_stats'][$today]['new_users'] ?? 0) + 1;
        
        update_stats('total_users', 1);
        
        log_message("New user registered: ID $user_id, Username: @" . ($user_data['username'] ?? 'none'));
    } else {
        // Existing user - update last active
        $users_data['users'][$user_id]['last_active'] = $current_time;
        
        // Initialize daily stats if not exists
        if (!isset($users_data['users'][$user_id]['daily_stats'][$today])) {
            $users_data['users'][$user_id]['daily_stats'][$today] = [
                'searches' => 0,
                'requests' => 0
            ];
        }
    }
    
    file_put_contents(USERS_JSON, json_encode($users_data, JSON_PRETTY_PRINT));
    
    return $users_data['users'][$user_id];
}

function increment_user_search($user_id) {
    $users_data = json_decode(file_get_contents(USERS_JSON), true);
    
    if (isset($users_data['users'][$user_id])) {
        $users_data['users'][$user_id]['search_count'] = ($users_data['users'][$user_id]['search_count'] ?? 0) + 1;
        
        $today = date('Y-m-d');
        if (!isset($users_data['users'][$user_id]['daily_stats'][$today])) {
            $users_data['users'][$user_id]['daily_stats'][$today] = ['searches' => 0, 'requests' => 0];
        }
        $users_data['users'][$user_id]['daily_stats'][$today]['searches']++;
        
        file_put_contents(USERS_JSON, json_encode($users_data, JSON_PRETTY_PRINT));
    }
}

function increment_user_request($user_id) {
    $users_data = json_decode(file_get_contents(USERS_JSON), true);
    
    if (isset($users_data['users'][$user_id])) {
        $users_data['users'][$user_id]['total_requests'] = ($users_data['users'][$user_id]['total_requests'] ?? 0) + 1;
        
        $today = date('Y-m-d');
        if (!isset($users_data['users'][$user_id]['daily_stats'][$today])) {
            $users_data['users'][$user_id]['daily_stats'][$today] = ['searches' => 0, 'requests' => 0];
        }
        $users_data['users'][$user_id]['daily_stats'][$today]['requests']++;
        
        file_put_contents(USERS_JSON, json_encode($users_data, JSON_PRETTY_PRINT));
    }
}

function get_user_stats($user_id) {
    $users_data = json_decode(file_get_contents(USERS_JSON), true);
    
    if (!isset($users_data['users'][$user_id])) {
        return null;
    }
    
    $user = $users_data['users'][$user_id];
    $today = date('Y-m-d');
    
    return [
        'basic' => [
            'username' => $user['username'] ?? 'N/A',
            'joined' => $user['joined'],
            'last_active' => $user['last_active'],
            'total_searches' => $user['search_count'] ?? 0,
            'total_requests' => $user['total_requests'] ?? 0,
            'points' => $user['points'] ?? 0
        ],
        'today' => [
            'searches' => $user['daily_stats'][$today]['searches'] ?? 0,
            'requests' => $user['daily_stats'][$today]['requests'] ?? 0
        ],
        'preferences' => $user['preferences'] ?? []
    ];
}

function check_daily_limit($user_id) {
    $users_data = json_decode(file_get_contents(USERS_JSON), true);
    $today = date('Y-m-d');
    
    if (!isset($users_data['users'][$user_id])) {
        return false;
    }
    
    $today_searches = $users_data['users'][$user_id]['daily_stats'][$today]['searches'] ?? 0;
    
    if ($today_searches >= DAILY_LIMIT_PER_USER) {
        return true;
    }
    
    return false;
}

// ==================== FLOOD CONTROL ========================
function is_flood($user_id) {
    $flood_file = sys_get_temp_dir() . '/tgflood_' . $user_id;
    
    if (file_exists($flood_file)) {
        $last_time = file_get_contents($flood_file);
        if (time() - (int)$last_time < USER_COOLDOWN) {
            log_message("Flood control triggered for user $user_id", 'WARNING');
            return true;
        }
    }
    
    file_put_contents($flood_file, time());
    return false;
}

function clear_old_flood_files() {
    $files = glob(sys_get_temp_dir() . '/tgflood_*');
    $now = time();
    $cleared = 0;
    
    foreach ($files as $file) {
        if (file_exists($file)) {
            $file_time = file_get_contents($file);
            if ($now - (int)$file_time > 3600) { // Clear files older than 1 hour
                unlink($file);
                $cleared++;
            }
        }
    }
    
    if ($cleared > 0) {
        log_message("Cleared $cleared old flood control files");
    }
}

// ==================== DELIVERY FUNCTIONS ====================
function deliver_item_to_chat($chat_id, $item, $batch_mode = false) {
    if (empty($item['message_id']) || empty($item['channel_id'])) {
        log_message("Invalid item for delivery: " . json_encode($item), 'ERROR');
        return false;
    }
    
    // Send chat action to show user we're working
    if (!$batch_mode) {
        sendChatAction($chat_id, 'upload_video');
    }
    
    $success = false;
    
    try {
        // Try to copy message first (preserves original formatting)
        $result = copyMessage($chat_id, $item['channel_id'], $item['message_id']);
        
        if ($result && isset($result['ok']) && $result['ok']) {
            $success = true;
            log_message("Successfully copied message {$item['message_id']} to chat $chat_id");
        } else {
            // Fallback to forward message
            $result = forwardMessage($chat_id, $item['channel_id'], $item['message_id'], true);
            
            if ($result && isset($result['ok']) && $result['ok']) {
                $success = true;
                log_message("Successfully forwarded message {$item['message_id']} to chat $chat_id");
            } else {
                log_message("Failed to deliver message {$item['message_id']} to chat $chat_id", 'ERROR');
            }
        }
    } catch (Exception $e) {
        log_message("Exception while delivering message: " . $e->getMessage(), 'ERROR');
    }
    
    return $success;
}

function deliver_movies_batch($chat_id, $movies, $progress_callback = null) {
    $total = count($movies);
    $success_count = 0;
    
    if ($total === 0) {
        return 0;
    }
    
    log_message("Starting batch delivery of $total movies to chat $chat_id");
    
    // Send initial chat action
    sendChatAction($chat_id, 'upload_video');
    
    foreach ($movies as $index => $movie) {
        $movie_number = $index + 1;
        
        // Update progress if callback provided
        if ($progress_callback && is_callable($progress_callback)) {
            $progress_callback($movie_number, $total);
        }
        
        // Deliver the movie
        if (deliver_item_to_chat($chat_id, $movie, true)) {
            $success_count++;
        }
        
        // Add small delay between deliveries to avoid rate limiting
        if ($movie_number < $total) {
            usleep(100000); // 0.1 second delay
        }
    }
    
    log_message("Batch delivery completed: $success_count/$total movies delivered to chat $chat_id");
    return $success_count;
}

// ==================== PAGINATION SYSTEM ====================
function paginate_movies($page = 1, $filters = []) {
    $movies = get_cached_movies();
    
    // Apply filters if provided
    if (!empty($filters)) {
        if (isset($filters['channel_id'])) {
            $movies = array_filter($movies, function($movie) use ($filters) {
                return $movie['channel_id'] == $filters['channel_id'];
            });
            $movies = array_values($movies); // Reindex array
        }
        
        if (isset($filters['search'])) {
            $search_query = strtolower($filters['search']);
            $movies = array_filter($movies, function($movie) use ($search_query) {
                return strpos(strtolower($movie['movie_name']), $search_query) !== false;
            });
            $movies = array_values($movies); // Reindex array
        }
    }
    
    $total = count($movies);
    $total_pages = ceil($total / PER_PAGE);
    $page = max(1, min($page, $total_pages));
    $start = ($page - 1) * PER_PAGE;
    
    return [
        'movies' => array_slice($movies, $start, PER_PAGE),
        'total' => $total,
        'page' => $page,
        'total_pages' => $total_pages,
        'start' => $start + 1,
        'end' => min($start + PER_PAGE, $total)
    ];
}

function forward_page_movies($chat_id, $page_movies, $page_info = null) {
    $total = count($page_movies);
    
    if ($total === 0) {
        sendMessage($chat_id, "No movies found in this page.");
        return 0;
    }
    
    // Send progress message
    $progress_msg = sendMessage($chat_id, "⏳ Preparing to send $total movies...\n\nPlease wait...");
    
    $success_count = deliver_movies_batch($chat_id, $page_movies, 
        function($current, $total) use ($chat_id, $progress_msg) {
            if ($current % 3 === 0 || $current === $total) {
                $percentage = round(($current / $total) * 100);
                editMessageText(
                    $chat_id,
                    $progress_msg['result']['message_id'],
                    "⏳ Sending movies...\n\nProgress: $current/$total ($percentage%)\n\nPlease wait...",
                    null,
                    'HTML'
                );
            }
        }
    );
    
    // Update final progress message
    if ($success_count > 0) {
        $final_message = "✅ Successfully sent $success_count/$total movies!";
        
        if ($page_info) {
            $final_message .= "\n\n📄 Page {$page_info['page']}/{$page_info['total_pages']}";
            $final_message .= "\n🎬 Movies {$page_info['start']}-{$page_info['end']} of {$page_info['total']}";
        }
        
        editMessageText(
            $chat_id,
            $progress_msg['result']['message_id'],
            $final_message,
            null,
            'HTML'
        );
    } else {
        editMessageText(
            $chat_id,
            $progress_msg['result']['message_id'],
            "❌ Failed to send movies. Please try again.",
            null,
            'HTML'
        );
    }
    
    return $success_count;
}

// ==================== KEYBOARD BUILDERS ====================
function build_totalupload_keyboard($page, $total_pages, $filters = []) {
    $keyboard = ['inline_keyboard' => []];
    
    // Navigation row
    $nav_row = [];
    
    if ($page > 1) {
        $nav_row[] = [
            'text' => '⏪ First', 
            'callback_data' => 'tu_page_1_' . json_encode($filters)
        ];
        
        $nav_row[] = [
            'text' => '⬅️ Previous', 
            'callback_data' => 'tu_page_' . ($page - 1) . '_' . json_encode($filters)
        ];
    }
    
    // Page indicator (non-clickable)
    $nav_row[] = [
        'text' => "📄 $page/$total_pages", 
        'callback_data' => 'tu_current_' . $page
    ];
    
    if ($page < $total_pages) {
        $nav_row[] = [
            'text' => 'Next ➡️', 
            'callback_data' => 'tu_page_' . ($page + 1) . '_' . json_encode($filters)
        ];
        
        $nav_row[] = [
            'text' => 'Last ⏩', 
            'callback_data' => 'tu_page_' . $total_pages . '_' . json_encode($filters)
        ];
    }
    
    if (!empty($nav_row)) {
        $keyboard['inline_keyboard'][] = $nav_row;
    }
    
    // Action row
    $action_row = [
        [
            'text' => '🎬 Send This Page', 
            'callback_data' => 'tu_send_' . $page . '_' . json_encode($filters)
        ],
        [
            'text' => '🔄 Refresh', 
            'callback_data' => 'tu_refresh_' . $page . '_' . json_encode($filters)
        ]
    ];
    
    $keyboard['inline_keyboard'][] = $action_row;
    
    // Filter row (if filters are active)
    if (!empty($filters)) {
        $filter_row = [
            [
                'text' => '🧹 Clear Filters', 
                'callback_data' => 'tu_clear_filters'
            ]
        ];
        
        if (isset($filters['channel_id'])) {
            $filter_row[] = [
                'text' => '📺 Channel Filtered', 
                'callback_data' => 'tu_filter_info'
            ];
        }
        
        if (isset($filters['search'])) {
            $filter_row[] = [
                'text' => '🔍 Search: ' . substr($filters['search'], 0, 10), 
                'callback_data' => 'tu_search_info'
            ];
        }
        
        $keyboard['inline_keyboard'][] = $filter_row;
    }
    
    // Channel filter row (if multiple channels)
    global $CHANNELS;
    if (count($CHANNELS) > 1) {
        $channel_row = [];
        foreach (array_slice($CHANNELS, 0, 3) as $channel) {
            $channel_row[] = [
                'text' => '📺 Filter Channel', 
                'callback_data' => 'tu_filter_channel_' . $channel
            ];
        }
        $keyboard['inline_keyboard'][] = $channel_row;
    }
    
    // Control row
    $control_row = [
        [
            'text' => '📊 Stats', 
            'callback_data' => 'tu_stats'
        ],
        [
            'text' => '🛑 Stop', 
            'callback_data' => 'tu_stop'
        ],
        [
            'text' => '❓ Help', 
            'callback_data' => 'tu_help'
        ]
    ];
    
    $keyboard['inline_keyboard'][] = $control_row;
    
    return $keyboard;
}

function build_search_results_keyboard($query, $search_results) {
    $keyboard = ['inline_keyboard' => []];
    
    if (empty($search_results)) {
        return $keyboard;
    }
    
    // Add top search results as buttons (max 5)
    $top_results = array_slice($search_results, 0, 5);
    foreach ($top_results as $result) {
        $movie = $result['movie'];
        $movie_name_short = substr($movie['movie_name'], 0, 40);
        if (strlen($movie['movie_name']) > 40) {
            $movie_name_short .= '...';
        }
        
        $keyboard['inline_keyboard'][] = [
            [
                'text' => "🎬 " . $movie_name_short . " (" . $result['score'] . "%)", 
                'callback_data' => 'sr_select_' . base64_encode($movie['message_id'])
            ]
        ];
    }
    
    // Add action buttons
    if (count($search_results) > 5) {
        $keyboard['inline_keyboard'][] = [
            [
                'text' => '📥 Send All Results (' . count($search_results) . ')', 
                'callback_data' => 'sr_send_all_' . base64_encode($query)
            ],
            [
                'text' => '🔍 Show More', 
                'callback_data' => 'sr_more_' . base64_encode($query)
            ]
        ];
    } else if (!empty($search_results)) {
        $keyboard['inline_keyboard'][] = [
            [
                'text' => '📥 Send All Results (' . count($search_results) . ')', 
                'callback_data' => 'sr_send_all_' . base64_encode($query)
            ]
        ];
    }
    
    return $keyboard;
}

function build_admin_keyboard() {
    return [
        'inline_keyboard' => [
            [
                ['text' => '📊 System Stats', 'callback_data' => 'admin_stats_full'],
                ['text' => '👥 User Stats', 'callback_data' => 'admin_users']
            ],
            [
                ['text' => '🔄 Refresh Cache', 'callback_data' => 'admin_cache_refresh'],
                ['text' => '🧹 Cleanup', 'callback_data' => 'admin_cleanup']
            ],
            [
                ['text' => '📁 View CSV', 'callback_data' => 'admin_view_csv'],
                ['text' => '📥 Export Data', 'callback_data' => 'admin_export']
            ],
            [
                ['text' => '💾 Backup Now', 'callback_data' => 'admin_backup_now'],
                ['text' => '🔄 Maintenance', 'callback_data' => 'admin_maintenance']
            ],
            [
                ['text' => '📈 Daily Report', 'callback_data' => 'admin_daily_report'],
                ['text' => '🔔 Notifications', 'callback_data' => 'admin_notifications']
            ]
        ]
    ];
}

function build_user_stats_keyboard($user_id) {
    return [
        'inline_keyboard' => [
            [
                ['text' => '📊 My Stats', 'callback_data' => 'user_stats_' . $user_id],
                ['text' => '📈 Daily Activity', 'callback_data' => 'user_daily_' . $user_id]
            ],
            [
                ['text' => '⚙️ Preferences', 'callback_data' => 'user_prefs_' . $user_id],
                ['text' => '❓ Help', 'callback_data' => 'user_help']
            ]
        ]
    ];
}

// ==================== MESSAGE FORMATTERS ==================
function format_movie_list($movies, $start_number = 1) {
    $message = "";
    
    foreach ($movies as $index => $movie) {
        $item_number = $start_number + $index;
        $movie_name = htmlspecialchars($movie['movie_name']);
        
        $message .= "<b>$item_number.</b> 🎬 $movie_name\n";
        $message .= "   📝 ID: <code>{$movie['message_id']}</code>\n";
        $message .= "   📺 Channel: <code>{$movie['channel_id']}</code>\n\n";
    }
    
    return $message;
}

function format_stats_message($stats) {
    $message = "📊 <b>Bot Statistics</b>\n\n";
    
    $message .= "🎬 <b>Movies:</b>\n";
    $message .= "• Total: <b>{$stats['basic']['total_movies'] ?? 0}</b>\n";
    
    if (!empty($stats['movies']['by_channel'])) {
        foreach ($stats['movies']['by_channel'] as $channel => $count) {
            $message .= "• Channel $channel: <b>$count</b>\n";
        }
    }
    
    $message .= "\n👥 <b>Users:</b>\n";
    $message .= "• Total: <b>{$stats['users']['total']}</b>\n";
    $message .= "• New Today: <b>{$stats['users']['today_new']}</b>\n";
    
    $message .= "\n🔍 <b>Activity:</b>\n";
    $message .= "• Total Searches: <b>{$stats['basic']['total_searches'] ?? 0}</b>\n";
    $message .= "• Total Requests: <b>{$stats['basic']['total_requests'] ?? 0}</b>\n";
    
    $message .= "\n🕒 <b>System:</b>\n";
    $message .= "• Last Updated: <b>{$stats['basic']['last_updated'] ?? 'N/A'}</b>\n";
    
    // Add daily stats for today
    $today = date('Y-m-d');
    if (isset($stats['daily'][$today])) {
        $message .= "\n📅 <b>Today ({$today}):</b>\n";
        $message .= "• Searches: <b>{$stats['daily'][$today]['searches'] ?? 0}</b>\n";
        $message .= "• New Users: <b>{$stats['daily'][$today]['new_users'] ?? 0}</b>\n";
        $message .= "• Movies Added: <b>{$stats['daily'][$today]['movies_added'] ?? 0}</b>\n";
    }
    
    return $message;
}

function format_user_stats_message($user_stats) {
    if (!$user_stats) {
        return "User not found or no statistics available.";
    }
    
    $message = "👤 <b>Your Statistics</b>\n\n";
    
    $message .= "📊 <b>Basic Info:</b>\n";
    $message .= "• Username: <b>@" . ($user_stats['basic']['username'] ?: 'Not set') . "</b>\n";
    $message .= "• Joined: <b>{$user_stats['basic']['joined']}</b>\n";
    $message .= "• Last Active: <b>{$user_stats['basic']['last_active']}</b>\n";
    
    $message .= "\n📈 <b>Activity:</b>\n";
    $message .= "• Total Searches: <b>{$user_stats['basic']['total_searches']}</b>\n";
    $message .= "• Total Requests: <b>{$user_stats['basic']['total_requests']}</b>\n";
    $message .= "• Points: <b>{$user_stats['basic']['points']}</b>\n";
    
    $message .= "\n📅 <b>Today's Activity:</b>\n";
    $message .= "• Searches: <b>{$user_stats['today']['searches']}</b>\n";
    $message .= "• Requests: <b>{$user_stats['today']['requests']}</b>\n";
    
    $message .= "\n⚙️ <b>Preferences:</b>\n";
    $message .= "• Language: <b>{$user_stats['preferences']['language'] ?? 'en'}</b>\n";
    $message .= "• Notifications: <b>" . ($user_stats['preferences']['notifications'] ? 'Enabled' : 'Disabled') . "</b>\n";
    
    return $message;
}

// ==================== COMMAND HANDLERS ====================
function handle_start($chat_id, $user_id, $user_data) {
    $welcome = "🎬 <b>Welcome to Entertainment Tadka Bot!</b>\n\n";
    $welcome .= "🤖 <b>I'm your personal movie assistant!</b>\n\n";
    
    $welcome .= "📢 <b>How to use me:</b>\n";
    $welcome .= "1. <b>Search Movies:</b> Just type any movie name\n";
    $welcome .= "2. <b>Browse All:</b> Use /totalupload to see all movies\n";
    $welcome .= "3. <b>Smart Search:</b> I'll find even partial matches\n";
    $welcome .= "4. <b>Request Movies:</b> If not found, I'll notify when added\n\n";
    
    $welcome .= "🔍 <b>Examples:</b>\n";
    $welcome .= "• <code>kgf</code>\n";
    $welcome .= "• <code>pushpa</code>\n";
    $welcome .= "• <code>avengers endgame</code>\n";
    $welcome .= "• <code>hindi movie 2024</code>\n\n";
    
    $welcome .= "🚀 <b>Pro Tips:</b>\n";
    $welcome .= "• Use <code>/stats</code> to see your activity\n";
    $welcome .= "• Use <code>/help</code> for command list\n";
    $welcome .= "• Daily limit: " . DAILY_LIMIT_PER_USER . " searches\n\n";
    
    $welcome .= "📢 Join our channel: @EntertainmentTadka786\n";
    $welcome .= "💬 Request/Help: @EntertainmentTadka0786\n\n";
    
    $welcome .= "🎉 <b>Start by typing a movie name!</b>";
    
    $keyboard = build_user_stats_keyboard($user_id);
    sendMessage($chat_id, $welcome, $keyboard, 'HTML', true);
    
    log_message("Start command handled for user $user_id");
}

function handle_search($chat_id, $query, $user_id) {
    global $waiting_users;
    
    // Clear old flood files periodically
    clear_old_flood_files();
    
    // Check flood control
    if (is_flood($user_id)) {
        sendMessage($chat_id, '⏳ <b>Please wait a moment!</b>\n\nYou\'re sending requests too quickly. Please wait ' . USER_COOLDOWN . ' seconds between searches.', null, 'HTML');
        return;
    }
    
    // Check daily limit
    if (check_daily_limit($user_id)) {
        $message = "⚠️ <b>Daily Limit Reached!</b>\n\n";
        $message .= "You've reached your daily search limit of " . DAILY_LIMIT_PER_USER . " searches.\n";
        $message .= "Please try again tomorrow!\n\n";
        $message .= "📢 Join: @EntertainmentTadka786\n";
        $message .= "💬 Request movies: @EntertainmentTadka0786";
        
        sendMessage($chat_id, $message, null, 'HTML');
        return;
    }
    
    // Validate query
    $query = trim($query);
    if (strlen($query) < 2) {
        sendMessage($chat_id, "❌ <b>Search query too short!</b>\n\nPlease enter at least 2 characters for search.", null, 'HTML');
        return;
    }
    
    if (strlen($query) > 100) {
        sendMessage($chat_id, "❌ <b>Search query too long!</b>\n\nPlease keep your search under 100 characters.", null, 'HTML');
        return;
    }
    
    // Send "typing" action
    sendChatAction($chat_id, 'typing');
    
    // Update user search count
    increment_user_search($user_id);
    
    // Perform search
    log_message("User $user_id searching for: '$query'");
    $results = search_movie($query);
    
    if (empty($results)) {
        // No results found
        $message = "🔍 <b>Search Results for:</b> <code>" . htmlspecialchars($query) . "</code>\n\n";
        $message .= "❌ <b>No movies found!</b>\n\n";
        $message .= "📝 <b>Suggestions:</b>\n";
        $message .= "1. Try different keywords\n";
        $message .= "2. Check spelling\n";
        $message .= "3. Try shorter search terms\n";
        $message .= "4. Request this movie below\n\n";
        $message .= "📢 Join: @EntertainmentTadka786\n";
        $message .= "💬 Request: @EntertainmentTadka0786\n\n";
        $message .= "⚡ <b>I'll notify you when it's added!</b>";
        
        sendMessage($chat_id, $message, null, 'HTML');
        
        // Add to waiting list
        $query_lower = strtolower($query);
        if (!isset($waiting_users[$query_lower])) {
            $waiting_users[$query_lower] = [];
        }
        
        // Check if user is already in waiting list
        $already_waiting = false;
        foreach ($waiting_users[$query_lower] as $waiting_user) {
            if ($waiting_user[1] == $user_id) {
                $already_waiting = true;
                break;
            }
        }
        
        if (!$already_waiting) {
            $waiting_users[$query_lower][] = [$chat_id, $user_id];
            log_message("User $user_id added to waiting list for query: '$query'");
            
            // Send confirmation
            sendMessage($chat_id, "✅ <b>You've been added to the notification list!</b>\n\nI'll send you <code>" . htmlspecialchars($query) . "</code> as soon as it's added to our database.", null, 'HTML');
        }
    } else {
        // Results found
        $count = count($results);
        $message = "🔍 <b>Search Results for:</b> <code>" . htmlspecialchars($query) . "</code>\n\n";
        $message .= "✅ <b>Found $count movie" . ($count > 1 ? 's' : '') . "!</b>\n\n";
        
        // Show first 3 results in message
        $display_count = min(3, $count);
        for ($i = 0; $i < $display_count; $i++) {
            $movie = $results[$i];
            $message .= ($i + 1) . ". <b>" . htmlspecialchars($movie['movie_name']) . "</b>\n";
        }
        
        if ($count > $display_count) {
            $message .= "\n... and " . ($count - $display_count) . " more\n";
        }
        
        $message .= "\n⚡ <b>Sending movies now...</b>";
        
        // Send initial message
        $search_msg = sendMessage($chat_id, $message, null, 'HTML');
        
        // Perform smart search for keyboard
        $smart_results = smart_search($query, 5);
        $keyboard = build_search_results_keyboard($query, $smart_results);
        
        // Send movies with progress
        $success_count = deliver_movies_batch($chat_id, $results, 
            function($current, $total) use ($chat_id, $search_msg) {
                if ($current % 5 === 0 || $current === $total) {
                    $percentage = round(($current / $total) * 100);
                    editMessageText(
                        $chat_id,
                        $search_msg['result']['message_id'],
                        "🔍 <b>Search Results</b>\n\n✅ Found $total movies!\n\n⚡ Sending... $current/$total ($percentage%)",
                        null,
                        'HTML'
                    );
                }
            }
        );
        
        // Update message with results
        $final_message = "🔍 <b>Search Completed!</b>\n\n";
        $final_message .= "✅ Found: <b>$count movie" . ($count > 1 ? 's' : '') . "</b>\n";
        $final_message .= "📤 Sent: <b>$success_count movie" . ($success_count > 1 ? 's' : '') . "</b>\n\n";
        
        if ($success_count < $count) {
            $final_message .= "⚠️ <i>Some movies couldn't be sent due to restrictions.</i>\n\n";
        }
        
        $final_message .= "🎬 <b>Top matches:</b>\n";
        
        // Show keyboard if we have smart results
        if (!empty($keyboard['inline_keyboard'])) {
            editMessageText($chat_id, $search_msg['result']['message_id'], $final_message, $keyboard, 'HTML');
        } else {
            editMessageText($chat_id, $search_msg['result']['message_id'], $final_message, null, 'HTML');
        }
        
        // Update stats
        update_stats('total_searches', 1);
        update_stats('total_requests', $success_count);
        
        log_message("Search completed for '$query': $success_count/$count movies sent to user $user_id");
    }
}

function handle_totalupload($chat_id, $page = 1, $filters = [], $edit_message_id = null) {
    // Get paginated data
    $data = paginate_movies($page, $filters);
    
    if (empty($data['movies'])) {
        $message = "📭 <b>No movies found!</b>\n\n";
        
        if (!empty($filters)) {
            $message .= "Try clearing your filters or check different pages.\n\n";
        } else {
            $message .= "The database is empty. Movies will appear here once added to channels.\n\n";
        }
        
        $message .= "📢 Join: @EntertainmentTadka786\n";
        $message .= "💬 Request movies: @EntertainmentTadka0786";
        
        if ($edit_message_id) {
            editMessageText($chat_id, $edit_message_id, $message, null, 'HTML');
        } else {
            sendMessage($chat_id, $message, null, 'HTML');
        }
        return;
    }
    
    // Build message
    $message = "📊 <b>Total Uploads</b>\n\n";
    
    // Add filter info if active
    if (!empty($filters)) {
        $message .= "🔍 <b>Active Filters:</b>\n";
        
        if (isset($filters['channel_id'])) {
            $message .= "• Channel: <code>{$filters['channel_id']}</code>\n";
        }
        
        if (isset($filters['search'])) {
            $message .= "• Search: <code>" . htmlspecialchars($filters['search']) . "</code>\n";
        }
        
        $message .= "\n";
    }
    
    $message .= "🎬 <b>Total Movies:</b> <code>{$data['total']}</code>\n";
    $message .= "📄 <b>Page:</b> <code>{$data['page']}/{$data['total_pages']}</code>\n";
    $message .= "📋 <b>Showing:</b> <code>" . count($data['movies']) . " movies</code>\n";
    $message .= "📍 <b>Range:</b> <code>{$data['start']}-{$data['end']}</code>\n\n";
    
    $message .= "<b>Movies in this page:</b>\n";
    $message .= format_movie_list($data['movies'], $data['start']);
    
    $message .= "💡 <i>Use buttons below to navigate or send this page.</i>";
    
    // Build keyboard
    $keyboard = build_totalupload_keyboard($data['page'], $data['total_pages'], $filters);
    
    if ($edit_message_id) {
        editMessageText($chat_id, $edit_message_id, $message, $keyboard, 'HTML');
    } else {
        sendMessage($chat_id, $message, $keyboard, 'HTML');
    }
    
    log_message("Total uploads page {$data['page']} shown to chat $chat_id");
}

function handle_stats($chat_id, $user_id, $detailed = false) {
    if ($detailed) {
        $stats = get_detailed_stats();
        $message = format_stats_message($stats);
    } else {
        $user_stats = get_user_stats($user_id);
        $message = format_user_stats_message($user_stats);
    }
    
    sendMessage($chat_id, $message, null, 'HTML');
}

function handle_checkcsv($chat_id, $show_all = false) {
    $movies = get_cached_movies();
    
    if (empty($movies)) {
        sendMessage($chat_id, "📊 <b>Movie Database</b>\n\n📭 Database is empty. Movies will appear here once added.", null, 'HTML');
        return;
    }
    
    // Reverse to show latest first
    $movies = array_reverse($movies);
    $total = count($movies);
    $limit = $show_all ? $total : 10;
    $display_movies = array_slice($movies, 0, $limit);
    
    $message = "📊 <b>Movie Database</b>\n\n";
    $message .= "📁 <b>Total Movies:</b> <code>$total</code>\n";
    
    if (!$show_all) {
        $message .= "🔍 <b>Showing:</b> Latest 10 entries\n";
        $message .= "📋 <b>Full list:</b> Use <code>/checkcsv all</code>\n\n";
    } else {
        $message .= "🔍 <b>Showing:</b> All entries (latest first)\n\n";
    }
    
    $i = 1;
    foreach ($display_movies as $movie) {
        $message .= "<b>$i.</b> 🎬 " . htmlspecialchars($movie['movie_name']) . "\n";
        $message .= "   📝 ID: <code>{$movie['message_id']}</code>\n";
        $message .= "   📺 Channel: <code>{$movie['channel_id']}</code>\n\n";
        
        $i++;
        
        // Split message if too long
        if (strlen($message) > 3000) {
            sendMessage($chat_id, $message, null, 'HTML');
            $message = "📊 <b>Continuing...</b>\n\n";
        }
    }
    
    $message .= "💾 <b>File:</b> <code>" . CSV_FILE . "</code>\n";
    $message .= "⏰ <b>Last Updated:</b> <code>" . date('Y-m-d H:i:s', filemtime(CSV_FILE)) . "</code>";
    
    sendMessage($chat_id, $message, null, 'HTML');
}

function handle_admin_panel($chat_id) {
    $message = "⚙️ <b>Admin Control Panel</b>\n\n";
    $message .= "Welcome to the administration interface. Select an option below:\n\n";
    $message .= "📊 <b>Statistics:</b> View detailed system stats\n";
    $message .= "👥 <b>Users:</b> Manage user data and activity\n";
    $message .= "🔄 <b>Maintenance:</b> System maintenance tasks\n";
    $message .= "📁 <b>Data:</b> View and export data\n";
    $message .= "💾 <b>Backup:</b> Create backups\n";
    $message .= "📈 <b>Reports:</b> Generate reports\n";
    $message .= "🔔 <b>Notifications:</b> Configure alerts\n";
    
    $keyboard = build_admin_keyboard();
    sendMessage($chat_id, $message, $keyboard, 'HTML');
}

function handle_help($chat_id) {
    $help = "🤖 <b>Entertainment Tadka Bot - Help Guide</b>\n\n";
    
    $help .= "🎬 <b>BASIC USAGE:</b>\n";
    $help .= "• Simply type any movie name to search\n";
    $help .= "• Use partial names - I'll find matches\n";
    $help .= "• Request movies not in database\n\n";
    
    $help .= "📋 <b>COMMANDS:</b>\n";
    $help .= "• <code>/start</code> - Welcome message & setup\n";
    $help .= "• <code>/totalupload</code> - Browse all movies\n";
    $help .= "• <code>/stats</code> - Your personal statistics\n";
    $help .= "• <code>/checkcsv</code> - View database contents\n";
    $help .= "• <code>/help</code> - This help message\n\n";
    
    $help .= "🔧 <b>ADVANCED FEATURES:</b>\n";
    $help .= "• Smart search with partial matching\n";
    $help .= "• Pagination for browsing all movies\n";
    $help .= "• Notification system for requested movies\n";
    $help .= "• Daily activity tracking\n";
    $help .= "• Multi-channel support\n\n";
    
    $help .= "⚡ <b>TIPS:</b>\n";
    $help .= "• Keep searches under 100 characters\n";
    $help .= "• Use specific keywords for better results\n";
    $help .= "• Check spelling if no results found\n";
    $help .= "• Daily limit: " . DAILY_LIMIT_PER_USER . " searches\n\n";
    
    $help .= "📢 <b>SUPPORT:</b>\n";
    $help .= "Channel: @EntertainmentTadka786\n";
    $help .= "Help/Request: @EntertainmentTadka0786\n\n";
    
    $help .= "🎉 <b>Happy movie hunting!</b>";
    
    sendMessage($chat_id, $help, null, 'HTML', true);
}

// ==================== CALLBACK QUERY HANDLERS =============
function handle_callback_query($callback_query) {
    $chat_id = $callback_query['message']['chat']['id'];
    $message_id = $callback_query['message']['message_id'];
    $callback_id = $callback_query['id'];
    $data = $callback_query['data'];
    $user_id = $callback_query['from']['id'];
    
    log_message("Callback received from user $user_id: $data");
    
    // Immediately answer callback to remove loading state
    answerCallbackQuery($callback_id, "Processing...", false);
    
    // Update user activity
    increment_user_request($user_id);
    
    // Parse callback data
    $parts = explode('_', $data);
    $action = $parts[0] ?? '';
    
    try {
        // Handle different callback actions
        switch ($action) {
            case 'tu': // Total uploads pagination
                handle_totalupload_callbacks($chat_id, $message_id, $data, $user_id);
                break;
                
            case 'sr': // Search results
                handle_search_callbacks($chat_id, $message_id, $data, $user_id);
                break;
                
            case 'admin': // Admin actions
                handle_admin_callbacks($chat_id, $message_id, $data, $user_id);
                break;
                
            case 'user': // User actions
                handle_user_callbacks($chat_id, $message_id, $data, $user_id);
                break;
                
            default:
                answerCallbackQuery($callback_id, "Unknown action", true);
                log_message("Unknown callback action: $data", 'WARNING');
        }
    } catch (Exception $e) {
        log_message("Callback error: " . $e->getMessage() . " - Data: $data", 'ERROR');
        answerCallbackQuery($callback_id, "Error processing request", true);
    }
}

function handle_totalupload_callbacks($chat_id, $message_id, $data, $user_id) {
    $parts = explode('_', $data);
    
    if (count($parts) < 2) {
        answerCallbackQuery($callback_query['id'], "Invalid callback data", true);
        return;
    }
    
    $sub_action = $parts[1] ?? '';
    
    switch ($sub_action) {
        case 'page': // Page navigation
            $page = intval($parts[2] ?? 1);
            $filters_json = $parts[3] ?? '[]';
            $filters = json_decode($filters_json, true) ?: [];
            
            handle_totalupload($chat_id, $page, $filters, $message_id);
            break;
            
        case 'send': // Send current page
            $page = intval($parts[2] ?? 1);
            $filters_json = $parts[3] ?? '[]';
            $filters = json_decode($filters_json, true) ?: [];
            
            $data = paginate_movies($page, $filters);
            $sent_count = forward_page_movies($chat_id, $data['movies'], $data);
            
            answerCallbackQuery($callback_query['id'], "Sent $sent_count movies!", false);
            break;
            
        case 'refresh': // Refresh current page
            $page = intval($parts[2] ?? 1);
            $filters_json = $parts[3] ?? '[]';
            $filters = json_decode($filters_json, true) ?: [];
            
            // Clear cache for this page
            global $movie_cache;
            $movie_cache = [];
            
            handle_totalupload($chat_id, $page, $filters, $message_id);
            answerCallbackQuery($callback_query['id'], "Page refreshed!", false);
            break;
            
        case 'filter': // Apply filters
            if ($parts[2] === 'channel') {
                $channel_id = $parts[3] ?? '';
                $filters = ['channel_id' => $channel_id];
                handle_totalupload($chat_id, 1, $filters, $message_id);
            } elseif ($parts[2] === 'clear') {
                handle_totalupload($chat_id, 1, [], $message_id);
            }
            break;
            
        case 'stats': // Show stats
            handle_stats($chat_id, $user_id, true);
            break;
            
        case 'help': // Show help
            handle_help($chat_id);
            break;
            
        case 'stop': // Stop pagination
            editMessageText($chat_id, $message_id, "✅ Pagination stopped.\n\nUse /totalupload to browse movies again.", null, 'HTML');
            break;
            
        case 'current': // Current page info
            answerCallbackQuery($callback_query['id'], "You're on this page", true);
            break;
            
        default:
            answerCallbackQuery($callback_query['id'], "Unknown action", true);
    }
}

function handle_search_callbacks($chat_id, $message_id, $data, $user_id) {
    $parts = explode('_', $data);
    
    if (count($parts) < 3) {
        answerCallbackQuery($callback_query['id'], "Invalid callback data", true);
        return;
    }
    
    $sub_action = $parts[1] ?? '';
    $param = $parts[2] ?? '';
    
    switch ($sub_action) {
        case 'select': // Select specific movie
            $message_id_encoded = $param;
            for ($i = 3; $i < count($parts); $i++) {
                $message_id_encoded .= '_' . $parts[$i];
            }
            
            $message_id_decoded = base64_decode($message_id_encoded);
            $movie = get_movie_by_id($message_id_decoded);
            
            if ($movie) {
                if (deliver_item_to_chat($chat_id, $movie)) {
                    sendMessage($chat_id, "✅ Movie sent successfully!");
                } else {
                    sendMessage($chat_id, "❌ Failed to send movie. Please try again.");
                }
            } else {
                sendMessage($chat_id, "❌ Movie not found. It may have been removed.");
            }
            break;
            
        case 'send': // Send all search results
            $query_encoded = $param;
            for ($i = 3; $i < count($parts); $i++) {
                $query_encoded .= '_' . $parts[$i];
            }
            
            $query = base64_decode($query_encoded);
            $results = search_movie($query);
            
            if (!empty($results)) {
                $sent_count = deliver_movies_batch($chat_id, $results);
                sendMessage($chat_id, "✅ Sent $sent_count/" . count($results) . " movies from your search!");
            } else {
                sendMessage($chat_id, "❌ No movies found for your search.");
            }
            break;
            
        case 'more': // Show more search results
            $query_encoded = $param;
            for ($i = 3; $i < count($parts); $i++) {
                $query_encoded .= '_' . $parts[$i];
            }
            
            $query = base64_decode($query_encoded);
            $results = search_movie($query, 50); // Get more results
            
            if (!empty($results)) {
                $message = "🔍 <b>Extended Search Results for:</b> <code>" . htmlspecialchars($query) . "</code>\n\n";
                $message .= "✅ <b>Found " . count($results) . " movies!</b>\n\n";
                $message .= "📤 Use the search box again to send specific movies.\n";
                $message .= "💡 Try more specific keywords for better results.";
                
                editMessageText($chat_id, $message_id, $message, null, 'HTML');
            }
            break;
            
        default:
            answerCallbackQuery($callback_query['id'], "Unknown action", true);
    }
}

function handle_admin_callbacks($chat_id, $message_id, $data, $user_id) {
    global $ADMINS;
    
    // Check if user is admin
    if (!in_array($user_id, $ADMINS)) {
        answerCallbackQuery($callback_query['id'], "Access denied", true);
        return;
    }
    
    $parts = explode('_', $data);
    $sub_action = $parts[1] ?? '';
    $action_type = $parts[2] ?? '';
    
    switch ($sub_action) {
        case 'stats':
            if ($action_type === 'full') {
                $stats = get_detailed_stats();
                $message = format_stats_message($stats);
                editMessageText($chat_id, $message_id, $message, null, 'HTML');
            }
            break;
            
        case 'cache':
            if ($action_type === 'refresh') {
                global $movie_cache;
                $movie_cache = [];
                editMessageText($chat_id, $message_id, "✅ Cache refreshed successfully!", null, 'HTML');
            }
            break;
            
        case 'view':
            if ($action_type === 'csv') {
                handle_checkcsv($chat_id, false);
            }
            break;
            
        case 'backup':
            if ($action_type === 'now') {
                auto_backup();
                editMessageText($chat_id, $message_id, "✅ Backup created successfully!", null, 'HTML');
            }
            break;
            
        default:
            answerCallbackQuery($callback_query['id'], "Admin action processed", false);
    }
}

function handle_user_callbacks($chat_id, $message_id, $data, $user_id) {
    $parts = explode('_', $data);
    $sub_action = $parts[1] ?? '';
    $target_user_id = $parts[2] ?? '';
    
    // Verify user can access this data
    if ($target_user_id != $user_id) {
        answerCallbackQuery($callback_query['id'], "Access denied", true);
        return;
    }
    
    switch ($sub_action) {
        case 'stats':
            $user_stats = get_user_stats($user_id);
            $message = format_user_stats_message($user_stats);
            editMessageText($chat_id, $message_id, $message, null, 'HTML');
            break;
            
        case 'daily':
            $users_data = json_decode(file_get_contents(USERS_JSON), true);
            $today = date('Y-m-d');
            
            if (isset($users_data['users'][$user_id]['daily_stats'][$today])) {
                $daily = $users_data['users'][$user_id]['daily_stats'][$today];
                $message = "📅 <b>Your Activity Today ($today)</b>\n\n";
                $message .= "🔍 Searches: <b>{$daily['searches']}</b>\n";
                $message .= "📤 Requests: <b>{$daily['requests']}</b>\n";
                $message .= "📊 Remaining: <b>" . (DAILY_LIMIT_PER_USER - $daily['searches']) . "</b> searches left\n\n";
                $message .= "💡 <i>Daily limit resets at midnight.</i>";
                
                editMessageText($chat_id, $message_id, $message, null, 'HTML');
            }
            break;
            
        default:
            answerCallbackQuery($callback_query['id'], "Action processed", false);
    }
}

// ==================== BACKUP SYSTEM =======================
function auto_backup() {
    $backup_files = [CSV_FILE, USERS_JSON, STATS_FILE];
    $backup_dir = BACKUP_DIR . date('Y-m-d_H-i-s');
    
    if (!file_exists($backup_dir)) {
        mkdir($backup_dir, 0777, true);
    }
    
    $backup_log = "Backup created: " . date('Y-m-d H:i:s') . "\n";
    $backup_log .= "================================\n";
    
    foreach ($backup_files as $file) {
        if (file_exists($file)) {
            $backup_path = $backup_dir . '/' . basename($file) . '.bak';
            if (copy($file, $backup_path)) {
                $backup_log .= "✓ " . basename($file) . " backed up\n";
                log_message("File backed up: $file to $backup_path");
            } else {
                $backup_log .= "✗ " . basename($file) . " backup failed\n";
                log_message("Backup failed for file: $file", 'ERROR');
            }
        }
    }
    
    // Save backup log
    file_put_contents($backup_dir . '/backup.log', $backup_log);
    
    // Keep only last 7 backups
    $old_backups = glob(BACKUP_DIR . '*', GLOB_ONLYDIR);
    if (count($old_backups) > 7) {
        usort($old_backups, function($a, $b) {
            return filemtime($a) - filemtime($b);
        });
        
        $to_delete = array_slice($old_backups, 0, count($old_backups) - 7);
        foreach ($to_delete as $dir) {
            $files = glob($dir . '/*');
            foreach ($files as $file) {
                @unlink($file);
            }
            @rmdir($dir);
            log_message("Old backup deleted: $dir");
        }
    }
    
    // Update users.json with backup info
    $users_data = json_decode(file_get_contents(USERS_JSON), true);
    $users_data['system']['last_backup'] = date('Y-m-d H:i:s');
    file_put_contents(USERS_JSON, json_encode($users_data, JSON_PRETTY_PRINT));
    
    log_message("Auto-backup completed: " . $backup_dir);
    return true;
}

// ==================== MAINTENANCE FUNCTIONS ===============
function cleanup_old_data() {
    $cleaned = 0;
    
    // Clean old temp files
    $temp_files = glob(sys_get_temp_dir() . '/tgflood_*');
    $now = time();
    
    foreach ($temp_files as $file) {
        if (file_exists($file)) {
            $file_time = file_get_contents($file);
            if ($now - (int)$file_time > 86400) { // 24 hours
                unlink($file);
                $cleaned++;
            }
        }
    }
    
    // Clean old log files (keep last 30 days)
    $log_files = glob(LOGS_DIR . '/*.log');
    foreach ($log_files as $log_file) {
        $file_age = time() - filemtime($log_file);
        if ($file_age > 2592000) { // 30 days
            unlink($log_file);
            $cleaned++;
        }
    }
    
    log_message("Cleanup completed: $cleaned old files removed");
    return $cleaned;
}

// ==================== MAIN WEBHOOK HANDLER ================
function handle_update($update) {
    global $MAINTENANCE_MODE, $CHANNELS, $ADMINS;
    
    log_message("Webhook update received");
    
    // Check maintenance mode
    if ($MAINTENANCE_MODE) {
        if (isset($update['message'])) {
            $chat_id = $update['message']['chat']['id'];
            sendMessage($chat_id, "🛠️ <b>Bot Under Maintenance</b>\n\nWe're performing scheduled maintenance. Please try again in a few minutes.\n\nThank you for your patience! 🙏", null, 'HTML');
        }
        return;
    }
    
    // Update global request count
    update_stats('total_requests', 1);
    
    // Handle channel posts (auto-add movies)
    if (isset($update['channel_post'])) {
        $post = $update['channel_post'];
        $channel_id = $post['chat']['id'];
        
        if (in_array($channel_id, $CHANNELS)) {
            $text = $post['text'] ?? $post['caption'] ?? '';
            $message_id = $post['message_id'];
            
            // Extract movie name (first line or caption)
            $lines = explode("\n", $text);
            $movie_name = trim($lines[0]);
            
            if ($movie_name && strlen($movie_name) > 2) {
                if (add_movie($movie_name, $message_id, $channel_id)) {
                    log_message("Movie auto-added from channel $channel_id: '$movie_name'");
                }
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
        
        // Update user information
        $user_info = update_user([
            'id' => $user_id,
            'first_name' => $message['from']['first_name'] ?? '',
            'last_name' => $message['from']['last_name'] ?? '',
            'username' => $message['from']['username'] ?? '',
            'language_code' => $message['from']['language_code'] ?? 'en'
        ]);
        
        // Handle commands
        if (strpos($text, '/') === 0) {
            $parts = explode(' ', $text);
            $command = strtolower($parts[0]);
            
            switch ($command) {
                case '/start':
                    handle_start($chat_id, $user_id, $user_info);
                    break;
                    
                case '/totalupload':
                case '/totaluploads':
                    $page = $parts[1] ?? 1;
                    $filters = [];
                    
                    // Check for filters in command
                    if (isset($parts[2]) && strpos($parts[2], 'channel:') === 0) {
                        $filters['channel_id'] = str_replace('channel:', '', $parts[2]);
                    }
                    
                    if (isset($parts[3]) && strpos($parts[3], 'search:') === 0) {
                        $filters['search'] = str_replace('search:', '', $parts[3]);
                    }
                    
                    handle_totalupload($chat_id, $page, $filters);
                    break;
                    
                case '/stats':
                    handle_stats($chat_id, $user_id, false);
                    break;
                    
                case '/checkcsv':
                    $show_all = (isset($parts[1]) && strtolower($parts[1]) == 'all');
                    handle_checkcsv($chat_id, $show_all);
                    break;
                    
                case '/admin':
                    if (in_array($user_id, $ADMINS)) {
                        handle_admin_panel($chat_id);
                    } else {
                        sendMessage($chat_id, "❌ <b>Access Denied</b>\n\nThis command is for administrators only.", null, 'HTML');
                    }
                    break;
                    
                case '/help':
                    handle_help($chat_id);
                    break;
                    
                default:
                    // Unknown command
                    sendMessage($chat_id, "❌ <b>Unknown Command</b>\n\nUse /help to see available commands.", null, 'HTML');
            }
        }
        // Handle search queries (non-command text)
        elseif (!empty($text) && strlen($text) >= 2) {
            handle_search($chat_id, $text, $user_id);
        }
        // Handle very short messages
        elseif (!empty($text) && strlen($text) < 2) {
            sendMessage($chat_id, "❌ <b>Search query too short!</b>\n\nPlease enter at least 2 characters to search for movies.", null, 'HTML');
        }
    }
    
    // Handle callback queries
    if (isset($update['callback_query'])) {
        handle_callback_query($update['callback_query']);
    }
    
    // Perform periodic maintenance (once per hour)
    $current_hour = date('H');
    if ($current_hour != date('H', time() - 3600)) {
        // Run cleanup once per hour
        cleanup_old_data();
        
        // Auto-backup at midnight
        if (date('H:i') == '00:00') {
            auto_backup();
        }
    }
}

// ==================== WEBHOOK SETUP ENDPOINT ==============
if (isset($_GET['action']) && $_GET['action'] === 'setwebhook') {
    global $BOT_TOKEN;
    
    if (empty($BOT_TOKEN) || $BOT_TOKEN === 'YOUR_BOT_TOKEN_HERE') {
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'error',
            'message' => 'BOT_TOKEN not set in environment variables',
            'required_vars' => ['BOT_TOKEN', 'CHANNELS', 'REQUEST_GROUP_ID'],
            'setup_instructions' => 'Set these environment variables in Render.com dashboard'
        ], JSON_PRETTY_PRINT);
        exit;
    }
    
    $webhook_url = "https://" . $_SERVER['HTTP_HOST'] . $_SERVER['SCRIPT_NAME'];
    $result = @file_get_contents("https://api.telegram.org/bot{$BOT_TOKEN}/setWebhook?url=" . urlencode($webhook_url));
    
    if ($result === false) {
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'error',
            'message' => 'Failed to set webhook',
            'possible_reasons' => [
                'Invalid BOT_TOKEN',
                'Network connectivity issue',
                'Telegram API down'
            ]
        ], JSON_PRETTY_PRINT);
        exit;
    }
    
    $decoded_result = json_decode($result, true);
    
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'success',
        'result' => $decoded_result,
        'webhook_url' => $webhook_url,
        'timestamp' => date('Y-m-d H:i:s'),
        'config' => [
            'csv_format' => 'movie_name,message_id,channel_id',
            'channels_count' => count($CHANNELS),
            'environment_variables' => [
                'BOT_TOKEN' => substr($BOT_TOKEN, 0, 10) . '...' . substr($BOT_TOKEN, -5),
                'CHANNELS' => 'Set (' . count($CHANNELS) . ' channels)',
                'REQUEST_GROUP_ID' => 'Optional'
            ]
        ],
        'next_steps' => [
            '1. Test the bot by searching for a movie',
            '2. Check /stats command',
            '3. Verify auto-add from channels is working',
            '4. Test callback queries and pagination'
        ]
    ], JSON_PRETTY_PRINT);
    exit;
}

// ==================== DEPLOYMENT CHECK ====================
if (isset($_GET['deploy'])) {
    $required_vars = ['BOT_TOKEN'];
    $missing = [];
    $warnings = [];
    
    foreach ($required_vars as $var) {
        $value = getenv($var);
        if (empty($value)) {
            $missing[] = $var;
        } elseif ($value === 'YOUR_BOT_TOKEN_HERE' && $var === 'BOT_TOKEN') {
            $warnings[] = "$var is set to placeholder value";
        }
    }
    
    // Check CHANNELS variable
    $channels = getenv('CHANNELS');
    if (empty($channels)) {
        $warnings[] = 'CHANNELS not set (using default)';
    } else {
        $channel_count = count(explode(',', $channels));
        if ($channel_count < 1) {
            $warnings[] = 'CHANNELS should contain at least one channel ID';
        }
    }
    
    // Check file permissions
    $files_to_check = [CSV_FILE, USERS_JSON, STATS_FILE, LOGS_DIR, BACKUP_DIR];
    $permission_issues = [];
    
    foreach ($files_to_check as $file) {
        if (file_exists($file)) {
            if (!is_writable($file) && !is_dir($file)) {
                $permission_issues[] = "$file is not writable";
            }
        }
    }
    
    // Check CSV format
    $csv_valid = true;
    if (file_exists(CSV_FILE)) {
        $handle = @fopen(CSV_FILE, 'r');
        if ($handle) {
            $header = fgetcsv($handle);
            if ($header && count($header) >= 3) {
                if ($header[0] !== 'movie_name' || $header[1] !== 'message_id' || $header[2] !== 'channel_id') {
                    $csv_valid = false;
                    $warnings[] = 'CSV header format incorrect (should be: movie_name,message_id,channel_id)';
                }
            }
            fclose($handle);
        }
    }
    
    $status = empty($missing) ? 'ready' : 'error';
    
    header('Content-Type: application/json');
    echo json_encode([
        'status' => $status,
        'missing_variables' => $missing,
        'warnings' => $warnings,
        'permission_issues' => $permission_issues,
        'csv_format_valid' => $csv_valid,
        'environment' => [
            'php_version' => PHP_VERSION,
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
            'max_execution_time' => ini_get('max_execution_time'),
            'memory_limit' => ini_get('memory_limit')
        ],
        'features' => [
            'smart_search' => true,
            'pagination' => true,
            'callback_queries' => true,
            'auto_backup' => true,
            'admin_panel' => true,
            'multi_channel' => true,
            'user_stats' => true,
            'flood_control' => true,
            'daily_limits' => true
        ],
        'endpoints' => [
            'GET /?action=setwebhook' => 'Setup webhook',
            'GET /?deploy=1' => 'Deployment check',
            'POST /' => 'Telegram webhook',
            'GET /' => 'Health check'
        ],
        'documentation' => [
            'csv_format' => 'movie_name,message_id,channel_id',
            'environment_vars' => 'BOT_TOKEN, CHANNELS, REQUEST_GROUP_ID',
            'deployment' => 'Ready for Render.com deployment'
        ]
    ], JSON_PRETTY_PRINT);
    exit;
}

// ==================== TEST ENDPOINTS ======================
if (isset($_GET['test_save'])) {
    header('Content-Type: text/html; charset=utf-8');
    echo "<h1>Test Movie Save</h1>";
    
    function test_save_movie($name, $id, $channel) {
        $result = add_movie($name, $id, $channel);
        return $result ? "✅ Added: $name" : "❌ Failed: $name";
    }
    
    echo "<h3>Adding test movies...</h3>";
    echo "<pre>";
    echo test_save_movie("Test Movie 1", rand(1000, 9999), "-1003181705395") . "\n";
    echo test_save_movie("Test Movie 2", rand(1000, 9999), "-1003251791991") . "\n";
    echo test_save_movie("Test Movie 3", rand(1000, 9999), "-1002337293281") . "\n";
    echo "</pre>";
    
    echo '<h3><a href="?check_csv=1">Check CSV Contents</a></h3>';
    echo '<h3><a href="?deploy=1">Check Deployment Status</a></h3>';
    exit;
}

if (isset($_GET['check_csv'])) {
    header('Content-Type: text/html; charset=utf-8');
    echo "<h1>CSV File Contents</h1>";
    
    if (!file_exists(CSV_FILE)) {
        echo "<p style='color: red;'>CSV file does not exist!</p>";
        exit;
    }
    
    echo "<h3>File: " . CSV_FILE . "</h3>";
    echo "<h3>Size: " . filesize(CSV_FILE) . " bytes</h3>";
    echo "<h3>Last Modified: " . date('Y-m-d H:i:s', filemtime(CSV_FILE)) . "</h3>";
    
    echo "<h3>Contents:</h3>";
    echo "<pre style='background: #f5f5f5; padding: 10px; border: 1px solid #ddd; max-height: 500px; overflow: auto;'>";
    
    $handle = fopen(CSV_FILE, 'r');
    if ($handle) {
        $line_number = 0;
        while (($line = fgets($handle)) !== false) {
            $line_number++;
            echo str_pad($line_number, 4, ' ', STR_PAD_LEFT) . ": " . htmlspecialchars($line);
        }
        fclose($handle);
    } else {
        echo "Failed to open CSV file";
    }
    
    echo "</pre>";
    
    echo '<h3><a href="?test_save=1">Test Save More Movies</a></h3>';
    echo '<h3><a href="?deploy=1">Check Deployment Status</a></h3>';
    exit;
}

// ==================== MAIN EXECUTION ======================
init_storage();

// Get update from webhook
$content = file_get_contents('php://input');
$update = json_decode($content, true);

if ($update) {
    // Handle the update
    handle_update($update);
    
    // Send 200 OK response
    http_response_code(200);
    echo 'OK';
    
    log_message("Webhook request processed successfully");
} else {
    // No update received - show info page
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $stats = get_stats();
        $detailed_stats = get_detailed_stats();
        
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'online',
            'service' => 'Telegram Movie Bot - Pro Advanced Edition',
            'version' => '2.0.0',
            'timestamp' => date('Y-m-d H:i:s'),
            'uptime' => 'Running',
            'statistics' => [
                'movies' => [
                    'total' => $stats['total_movies'] ?? 0,
                    'cached' => count(get_cached_movies()),
                    'channels' => count($detailed_stats['movies']['by_channel'] ?? [])
                ],
                'users' => [
                    'total' => $stats['total_users'] ?? 0,
                    'active_today' => 0 // Would need tracking
                ],
                'activity' => [
                    'total_searches' => $stats['total_searches'] ?? 0,
                    'total_requests' => $stats['total_requests'] ?? 0,
                    'today_searches' => $detailed_stats['daily'][date('Y-m-d')]['searches'] ?? 0
                ]
            ],
            'system' => [
                'maintenance_mode' => MAINTENANCE_MODE ? 'ON' : 'OFF',
                'cache_enabled' => true,
                'backup_system' => true,
                'daily_limits' => true
            ],
            'csv_format' => 'movie_name,message_id,channel_id',
            'channels_configured' => count($CHANNELS),
            'endpoints' => [
                'GET /?action=setwebhook' => 'Setup Telegram webhook',
                'GET /?deploy=1' => 'Check deployment status',
                'GET /?test_save=1' => 'Test movie saving',
                'GET /?check_csv=1' => 'View CSV contents',
                'POST /' => 'Telegram webhook endpoint'
            ],
            'documentation' => [
                'usage' => 'Type any movie name to search',
                'commands' => '/start, /totalupload, /stats, /checkcsv, /help',
                'admin' => '/admin (admin users only)',
                'features' => 'Smart search, pagination, callback queries, auto-backup'
            ]
        ], JSON_PRETTY_PRINT);
    } else {
        // Invalid request
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode([
            'error' => 'Invalid request',
            'message' => 'This endpoint expects Telegram webhook POST requests',
            'expected' => 'JSON update object from Telegram',
            'received' => $_SERVER['REQUEST_METHOD'] . ' request'
        ]);
        
        log_message("Invalid request received: " . $_SERVER['REQUEST_METHOD'] . " " . $_SERVER['REQUEST_URI'], 'WARNING');
    }
}
?>
