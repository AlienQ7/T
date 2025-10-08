<?php
// =========================================================
// index.php (V1.3 - Main Application Dashboard with FINAL Point Fix)
// =========================================================

// --- CRITICAL CONFIGURATION ---
// Set timezone for all date/time operations to ensure consistent daily resets
ini_set('display_errors', 0); 
error_reporting(E_ALL & ~E_DEPRECATED & ~E_WARNING);
date_default_timezone_set('Asia/Kolkata'); // Use TIMEZONE_RESET constant from config

// --- INCLUSIONS ---
require_once 'config.php';
require_once 'DbManager.php';

// --- INITIALIZATION ---
$dbManager = new DbManager();
$loggedInUser = getCurrentUser($dbManager); 

// If not logged in, redirect to authentication page
if (!$loggedInUser && basename($_SERVER['PHP_SELF']) !== 'auth.php') {
    header('Location: auth.php');
    exit;
}

// =========================================================
// SESSION & AUTHENTICATION MANAGEMENT
// =========================================================

/**
 * Validates session and retrieves user data.
 * @return array|null User data array or null if session is invalid.
 */
function getCurrentUser($dbManager) {
    $sessionToken = $_COOKIE['session'] ?? null;
    if (!$sessionToken) return null;

    $username = $dbManager->getUsernameFromSession($sessionToken);
    if (!$username) return null;

    $userData = $dbManager->getUserData($username);
    if (!$userData) return null;

    // Ensure new fields exist for robust point tracking (CRITICAL: claimed_task_points)
    $userData['failed_points'] = $userData['failed_points'] ?? 0;
    $userData['claimed_task_points'] = $userData['claimed_task_points'] ?? 0;
    
    // Calculate and set the current task points (Coins) upon retrieval
    $userData['task_points'] = $userData['claimed_task_points'] - $userData['failed_points'];
    
    // --- CRITICAL FIX: Force rank calculation on every load ---
    $userData['rank'] = getRankTitle($userData['sp_points']); 
    
    return $userData;
}

function handleLogout() {
    global $dbManager;
    $sessionToken = $_COOKIE['session'] ?? null;
    if ($sessionToken) {
        $dbManager->deleteSession($sessionToken);
    }
    setcookie('session', '', time() - 3600, '/'); // Expire the cookie
    header('Location: auth.php');
    exit;
}

function handleDeleteAccount($username) {
    global $dbManager;
    $dbManager->deleteUserAndData($username);
    handleLogout(); // Automatically logs out and redirects
}

// =========================================================
// DAILY RESET & SP COLLECTION LOGIC (Asia/Kolkata Time)
// =========================================================

/**
 * Checks if a new day has started in Kolkata time and performs necessary resets.
 */
function checkDailyReset(&$user, $dbManager) {
    $now = new DateTime('now', new DateTimeZone(TIMEZONE_RESET));
    $today_midnight_ts = (clone $now)->setTime(0, 0, 0)->getTimestamp();

    // Check if the last refresh was before today's midnight (Kolkata time)
    if ($user['last_task_refresh'] < $today_midnight_ts) {
        
        $tasksJson = $dbManager->getTasks($user['username'], 'all_tasks');
        $tasks = json_decode($tasksJson, true) ?: [];
        $updatedTasks = [];

        foreach ($tasks as $task) {
            // Permanent Task Refresh Logic:
            if ($task['permanent'] === true) {
                // Completed permanent task resets status for the new day
                $task['completed'] = false;
                // Important: claimed status is NOT reset here; points are permanent
                $updatedTasks[] = $task;
            } else {
                // Non-permanent tasks are removed.
                // We keep only the permanent ones.
            }
        }
        
        // Save the refreshed tasks
        $dbManager->saveTasks($user['username'], 'all_tasks', json_encode($updatedTasks));

        // Update user stats
        $user['daily_completed_count'] = 0; // Reset daily count
        $user['last_task_refresh'] = time(); // Update refresh time
        
        // Save is handled by updateUserData which is called later
    }
}


/**
 * Logic for collecting daily SP (Self-Improvement Points).
 * Only allowed once per Kolkata day.
 */
function handleSpCollect(&$user, $dbManager) {
    header('Content-Type: application/json');
    $now = new DateTime('now', new DateTimeZone(TIMEZONE_RESET));
    $today_midnight_ts = (clone $now)->setTime(0, 0, 0)->getTimestamp();

    if ($user['last_sp_collect'] >= $today_midnight_ts) {
        // Already collected today
        echo json_encode(['success' => false, 'message' => 'Error: Daily 💎 already collected!']);
        return;
    }

    $user['sp_points'] += DAILY_CHECKIN_REWARD;
    $user['last_sp_collect'] = time();
    $message = "💎 COLLECTED! +".DAILY_CHECKIN_REWARD." 💎. Total: {$user['sp_points']}";
    
    updateUserData($user, $dbManager);
    
    echo json_encode([
        'success' => true, 
        'message' => $message, 
        'sp_points' => $user['sp_points'],
        'rank' => $user['rank']
    ]);
}

// =========================================================
// USER DATA & RANKING UTILITIES
// =========================================================

/**
 * Determines the rank title based on SP points.
 */
function getRankTitle($sp_points) {
    // NOTE: This assumes RANK_THRESHOLDS is defined in config.php
    foreach (RANK_THRESHOLDS as $rank) {
        if ($sp_points >= $rank['sp']) {
            return $rank['title'];
        }
    }
    // Should not happen if 0 is included
    return 'Aspiring 🌱'; 
}

/**
 * Updates user data in the database, including recalculating rank.
 */
function updateUserData(&$user, $dbManager) {
    // 1. Recalculate Rank
    $newRank = getRankTitle($user['sp_points']);
    $user['rank'] = $newRank; // Update the user array immediately

    // 2. Calculate current task_points (Coins)
    // The final task_points is the net of claimed points minus failed points.
    $user['task_points'] = $user['claimed_task_points'] - $user['failed_points'];

    // 3. Save to DB
    $dataToSave = [
        'rank' => $user['rank'],
        'sp_points' => $user['sp_points'],
        'task_points' => $user['task_points'], 
        'claimed_task_points' => $user['claimed_task_points'], 
        'failed_points' => $user['failed_points'], 
        'last_sp_collect' => $user['last_sp_collect'],
        'last_task_refresh' => $user['last_task_refresh'],
        'daily_completed_count' => $user['daily_completed_count'],
        'user_objective' => $user['user_objective']
    ];
    $dbManager->saveUserData($user['username'], $dataToSave);
}

// =========================================================
// TASK MANAGEMENT (AJAX ENDPOINT)
// =========================================================

function handleTaskActions(&$user, $dbManager) {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    $taskId = $_POST['id'] ?? null;
    $taskText = $_POST['text'] ?? null;
    $isPermanent = (($_POST['permanent'] ?? 'false') === 'true'); 

    $tasksJson = $dbManager->getTasks($user['username'], 'all_tasks');
    $tasks = json_decode($tasksJson, true) ?: [];
    $response = ['success' => false, 'message' => ''];
    $taskFound = false;

    // --- Action: Add ---
    if ($action === 'add' && !empty($taskText)) {
        $newTask = [
            'id' => uniqid(),
            'text' => htmlspecialchars($taskText),
            'completed' => false,
            // 'claimed' status tracks if points were awarded for a permanent task run
            'claimed' => false, 
            'permanent' => $isPermanent
        ];
        $tasks[] = $newTask;
        $response = ['success' => true, 'task' => $newTask];
    } 
    // --- Action: Toggle/Delete/Set Permanent ---
    else {
        foreach ($tasks as $key => &$task) {
            if ($task['id'] === $taskId) {
                $taskFound = true;
                
                if ($action === 'toggle') {
                    $task['completed'] = !$task['completed'];
                    $response = ['success' => true, 'id' => $taskId, 'completed' => $task['completed']];

                    if ($task['completed']) {
                        // Task Completed: Award points
                        if ($task['claimed'] === false) {
                            $user['claimed_task_points'] += TASK_COMPLETION_REWARD;
                            $task['claimed'] = true; // Mark as claimed
                            $response['points_change'] = '+'.TASK_COMPLETION_REWARD;
                        } else {
                            $response['points_change'] = '+0 (Already claimed for this cycle)';
                        }
                        $user['daily_completed_count']++;
                    } else {
                        // Task Uncompleted: Penalty
                        if ($task['claimed'] === true) {
                            // Points are NOT subtracted from claimed_task_points (they are permanent)
                            // They ARE added to failed_points, which reduces the final TP score.
                            $user['failed_points'] += TASK_COMPLETION_REWARD; 
                            // Only reset claimed status for permanent tasks if we want the penalty to be applied only once
                            if ($task['permanent']) {
                                $task['claimed'] = false; 
                            }
                            $response['points_change'] = '-'.TASK_COMPLETION_REWARD;
                        } else {
                             $response['points_change'] = '-0 (Not claimed, no penalty)';
                        }
                        $user['daily_completed_count']--;
                    }
                    break;
                }
                
                if ($action === 'delete') {
                    // FIX: Deleting a task does NOT affect points once they are claimed.
                    unset($tasks[$key]);
                    $tasks = array_values($tasks); // Re-index array
                    $response = ['success' => true, 'id' => $taskId, 'message' => 'Task Deleted.'];
                    break;
                }

                if ($action === 'set_permanent') {
                    $task['permanent'] = $isPermanent;
                    // Reset completion status when toggling permanence for clarity
                    $task['completed'] = false;
                    $response = ['success' => true, 'id' => $taskId, 'permanent' => $isPermanent];
                    break;
                }
            }
        }
        if (!$taskFound && $action !== 'add') {
            $response = ['success' => false, 'message' => 'Error: Task ID not found.'];
        }
    }

    if ($response['success']) {
        // 1. Save tasks to ensure list is updated
        $dbManager->saveTasks($user['username'], 'all_tasks', json_encode($tasks));
        
        // 2. Save user data and, CRITICALLY, recalculate $user['task_points'] and $user['rank']
        updateUserData($user, $dbManager); 
        
        // 3. Populate response with the newly calculated user data
        $response['user_data'] = [
            'tp' => $user['task_points'], 
            'sp' => $user['sp_points'],
            'failed' => $user['failed_points'], 
            'rank' => $user['rank'],
            'daily_count' => $user['daily_completed_count']
        ];
    }
    
    echo json_encode($response);
}

function handleObjectiveSave(&$user, $dbManager) {
    header('Content-Type: application/json');
    $objective = trim($_POST['objective'] ?? '');

    if (!empty($objective) || $objective === '') { // Allow saving an empty objective
        $user['user_objective'] = htmlspecialchars($objective);
        updateUserData($user, $dbManager);
        echo json_encode(['success' => true, 'objective' => $user['user_objective']]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Objective error.']);
    }
}

// =========================================================
// MAIN REQUEST HANDLER
// =========================================================

/**
 * Dispatches requests to the appropriate handler (for AJAX, Logout, Delete).
 */
function handleRequest(&$user, $dbManager) {
    
    // Check for required daily actions first
    checkDailyReset($user, $dbManager);
    
    // Check for explicit GET/POST actions (Logout/Delete Account)
    if (isset($_GET['action'])) {
        if ($_GET['action'] === 'logout') {
            handleLogout();
        } elseif ($_GET['action'] === 'delete_account') {
            handleDeleteAccount($user['username']);
        }
    }

    // Check for AJAX POST requests (Task/SP/Objective Management)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['endpoint'])) {
        $endpoint = $_POST['endpoint'];
        
        if ($endpoint === 'task_action') {
            handleTaskActions($user, $dbManager);
        } elseif ($endpoint === 'sp_collect') {
            handleSpCollect($user, $dbManager);
        } elseif ($endpoint === 'save_objective') {
            handleObjectiveSave($user, $dbManager);
        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Unknown endpoint.']);
        }
        // Terminate execution after AJAX response
        exit;
    }
    
    // Default: Display the main HTML page
    echo generateHtml($user, $dbManager);
}

// =========================================================
// HTML VIEW GENERATION
// =========================================================

/**
 * Generates the full HTML view for the user dashboard.
 */
function generateHtml($user, $dbManager) {
    $tasksJson = $dbManager->getTasks($user['username'], 'all_tasks');
    $tasks = json_decode($tasksJson, true) ?: [];

    // Check if SP can be collected today (based on Kolkata time)
    $now = new DateTime('now', new DateTimeZone(TIMEZONE_RESET));
    $today_midnight_ts = (clone $now)->setTime(0, 0, 0)->getTimestamp();
    $canCollectSp = $user['last_sp_collect'] < $today_midnight_ts;
    $spButtonText = $canCollectSp ? 'Collect(💎)' : 'Collected(💎):';
    
    // Check for the old "Pro max programmer xd." objective and show empty if needed.
    $objectiveDisplay = ($user['user_objective'] === 'Pro max programmer xd.') ? '' : htmlspecialchars($user['user_objective']);


    ob_start(); // Start output buffering
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dev Console: <?php echo htmlspecialchars($user['username']); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
    <style>
        /* Embed time-specific styles for the rank title color */
        .rank-title { 
            color: <?php echo COLOR_RANK_TITLE; ?>; 
        }
        /* Style for error message display (if needed) */
        .error-message {
            color: #ff3333;
            background-color: #330000;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 15px;
        }
    </style>
</head>
<body>

<div class="header-bar">
    <div class="rank-display">
        <span class="rank-label">RANK:</span>
        <span id="user-rank-title" class="rank-title"><?php echo htmlspecialchars($user['rank']); ?></span>
    </div>
    <div class="header-menu">
        <button class="hamburger-btn" onclick="toggleMenu()">☰</button>
        
        <div id="dropdown-menu" class="menu-dropdown">
            <span class="dropdown-sp-collected">Coins(🪙): <span id="tp-display"><?php echo $user['task_points']; ?></span></span>
            <span class="dropdown-sp-collected">Diamonds(💎): <span id="sp-display-menu"><?php echo $user['sp_points']; ?></span></span>
            <a href="shop.php">Shop (Coming Soon!)</a>
            <hr style="border-color:#555;">
            <a href="?action=logout">Log Out</a>
            <button onclick="confirmDelete()">Delete Account</button>
        </div>
    </div>
</div>

<div class="container">
    <div class="profile-container">
        <h2>Dev: <?php echo htmlspecialchars($user['username']); ?></h2>
        
        <div class="stats-line">
            Diamonds(💎): <strong id="sp-stats"><?php echo $user['sp_points']; ?></strong>
        </div>
        <div class="stats-line">
            Coins(🪙): <strong id="tp-stats"><?php echo $user['task_points']; ?></strong>
        </div>
        <div class="stats-line">
            Daily Missions(🎯): <strong id="daily-count-stats"><?php echo $user['daily_completed_count']; ?></strong>
        </div>
        <div class="stats-line">
            Failed (❌): <strong id="failed-stats"><?php echo $user['failed_points']; ?></strong>
        </div>
        
        <div class="sp-btn-container">
            <button id="sp-collect-btn" onclick="collectSp()" class="auth-btn" <?php if (!$canCollectSp) echo 'disabled'; ?>>
                <?php echo $spButtonText; ?>
            </button>
        </div>
        
        <h3>Current Objective:</h3>
        <div class="objective-container">
            <input type="text" id="objective-input" placeholder="Set Your Objective" value="<?php echo $objectiveDisplay; ?>">
            <button onclick="saveObjective()">SAVE</button>
        </div>

    </div>

    <div class="task-manager">
        <h2>Task Log</h2>
        <div style="display: flex; align-items: center; margin-bottom: 20px;">
            <input type="text" id="new-task-input" placeholder="New Mission Log Entry..." onkeydown="if(event.key === 'Enter') document.getElementById('add-task-btn').click();">
            <select id="task-type-select" style="padding: 10px; margin-right: 5px; background: #000; color: #00ff99; border: 1px solid #00ff99;">
                <option value="false">One-Time Mission</option>
                <option value="true">Permanent Daily Lock</option>
            </select>
            <button id="add-task-btn" onclick="addTask()" class="add-btn">ADD</button>
        </div>
        
        <div id="task-list">
            <?php foreach ($tasks as $task): ?>
                <?php echo renderTaskHtml($task); ?>
            <?php endforeach; ?>
            <?php if (empty($tasks)): ?>
                <p id="no-tasks-message">No active missions. Add a new one to begin!</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
    // Constants are used by JavaScript (defined in config.php)
    const TASK_COMPLETION_REWARD = <?php echo TASK_COMPLETION_REWARD; ?>;
    const DAILY_CHECKIN_REWARD = <?php echo DAILY_CHECKIN_REWARD; ?>;

    /**
     * Toggles the Hamburger Dropdown Menu visibility.
     */
    function toggleMenu() {
        const menu = document.getElementById('dropdown-menu');
        menu.classList.toggle('show');
    }
    
    /**
     * Confirmation prompt before deleting the user's account.
     */
    function confirmDelete() {
        if (confirm("WARNING: All data (tasks, points, progress) will be permanently deleted. Are you sure you wish to delete your account?")) {
            window.location.href = '?action=delete_account';
        }
    }

    /**
     * Converts a task object into its HTML representation.
     */
    function renderTaskHtml(task) {
        const completedClass = task.completed ? 'completed-slot' : '';
        const completedAttr = task.completed ? 'checked' : '';
        const permanentIndicator = task.permanent ? '<span class="permanent-indicator" title="Permanent Daily Task">🔒</span>' : '';
        const permanentBtnText = task.permanent ? 'Unlock' : 'Lock';
        // Ensure the onclick attribute sets the correct status for the NEXT click
        const nextStatus = task.permanent ? 'false' : 'true';

        return `
            <div class="task-slot ${completedClass}" id="task-${task.id}" data-id="${task.id}" data-permanent="${task.permanent}" data-completed="${task.completed}">
                <input type="checkbox" class="task-checkbox" ${completedAttr} onchange="toggleTask('${task.id}')">
                <div class="task-description-wrapper">
                    ${permanentIndicator}
                    <span class="task-description ${completedAttr ? 'completed' : ''}">${task.text}</span>
                </div>
                <button class="permanent-btn" data-permanent="${task.permanent}" onclick="togglePermanent('${task.id}', ${nextStatus})">${permanentBtnText}</button>
                <button class="remove-btn" onclick="deleteTask('${task.id}')">REMOVE</button>
            </div>
        `;
    }

    /**
     * Updates the HTML display of user stats.
     */
    function updateStatsDisplay(data) {
        document.getElementById('tp-stats').textContent = data.tp;
        document.getElementById('sp-stats').textContent = data.sp;
        document.getElementById('daily-count-stats').textContent = data.daily_count;
        document.getElementById('failed-stats').textContent = data.failed; 
        document.getElementById('user-rank-title').textContent = data.rank;
        document.getElementById('tp-display').textContent = data.tp;
        document.getElementById('sp-display-menu').textContent = data.sp;
    }

    // =========================================================
    // AJAX FUNCTIONS
    // =========================================================

    /**
     * Executes an asynchronous POST request to the server.
     */
    async function postAction(data) {
        try {
            const response = await fetch('index.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams(data)
            });
            return response.json();
        } catch (error) {
            console.error('Network or parsing error:', error);
            alert('A network error occurred. Check your connection.');
            return { success: false, message: 'Network Error' };
        }
    }

    /**
     * Adds a new task to the list.
     */
    async function addTask() {
        const input = document.getElementById('new-task-input');
        const text = input.value.trim();
        const permanent = document.getElementById('task-type-select').value;
        
        if (!text) return;

        const result = await postAction({ 
            endpoint: 'task_action', 
            action: 'add', 
            text: text, 
            permanent: permanent
        });

        if (result.success) {
            document.getElementById('task-list').insertAdjacentHTML('beforeend', renderTaskHtml(result.task));
            input.value = '';
            document.getElementById('no-tasks-message')?.remove();
        } else {
            alert(result.message);
        }
    }

    /**
     * Toggles the completion status of a task.
     */
    async function toggleTask(id) {
        const slot = document.getElementById(`task-${id}`);
        const isCompleted = slot.dataset.completed === 'true';

        const result = await postAction({ 
            endpoint: 'task_action', 
            action: 'toggle', 
            id: id 
        });

        if (result.success) {
            // Update UI elements based on response
            slot.dataset.completed = result.completed;
            slot.querySelector('.task-checkbox').checked = result.completed;
            slot.querySelector('.task-description').classList.toggle('completed', result.completed);
            slot.classList.toggle('completed-slot', result.completed);
            
            updateStatsDisplay(result.user_data);
            console.log(`Task ${id} toggled. Points change: ${result.points_change}`);
        } else {
            // Revert checkbox state if server failed
            slot.querySelector('.task-checkbox').checked = isCompleted;
            alert(result.message);
        }
    }

    /**
     * Deletes a task from the list.
     */
    async function deleteTask(id) {
        if (!confirm("Confirm mission abort (REMOVE)?")) return; 
        
        const result = await postAction({ 
            endpoint: 'task_action', 
            action: 'delete', 
            id: id 
        });

        if (result.success) {
            // The server correctly recalculates points based on persistent claimed/failed values.
            document.getElementById(`task-${id}`).remove();
            updateStatsDisplay(result.user_data);
            
            if (document.getElementById('task-list').children.length === 0) {
                 document.getElementById('task-list').innerHTML = '<p id="no-tasks-message">No active missions. Add a new one to begin!</p>';
            }
        } else {
            alert(result.message);
        }
    }

    /**
     * Toggles the permanent status of a task.
     */
    async function togglePermanent(id, newPermanentStatus) {
        const result = await postAction({ 
            endpoint: 'task_action', 
            action: 'set_permanent', 
            id: id,
            permanent: newPermanentStatus 
        });

        if (result.success) {
            const slot = document.getElementById(`task-${id}`);
            slot.dataset.permanent = result.permanent;
            
            // Update indicator and button text
            const indicator = slot.querySelector('.permanent-indicator');
            const button = slot.querySelector('.permanent-btn');
            
            if (result.permanent) {
                // LOCK state
                if (!indicator) { 
                    const wrapper = slot.querySelector('.task-description-wrapper');
                    wrapper.insertAdjacentHTML('afterbegin', '<span class="permanent-indicator" title="Permanent Daily Task">🔒</span>');
                }
                // UI UPDATE: Set to 'Unlock'
                button.textContent = 'Unlock'; 
                button.setAttribute('data-permanent', 'true');
                // Update the onclick event handler's second argument for the next toggle
                button.setAttribute('onclick', `togglePermanent('${id}', false)`);
            } else {
                // UNLOCK state
                indicator?.remove();
                // UI UPDATE: Set to 'Lock'
                button.textContent = 'Lock'; 
                button.setAttribute('data-permanent', 'false');
                // Update the onclick event handler's second argument for the next toggle
                button.setAttribute('onclick', `togglePermanent('${id}', true)`);
            }
            // Reset completion state on UI if the server did it (for clarity)
            slot.dataset.completed = 'false';
            slot.querySelector('.task-checkbox').checked = false;
            slot.querySelector('.task-description').classList.remove('completed');
            slot.classList.remove('completed-slot');
            
        } else {
            alert(result.message);
        }
    }

    /**
     * Collects daily SP points.
     */
    async function collectSp() {
        const button = document.getElementById('sp-collect-btn');
        button.disabled = true; // Prevent double click

        const result = await postAction({ 
            endpoint: 'sp_collect' 
        });

        if (result.success) {
            alert(result.message);
            updateStatsDisplay({
                tp: document.getElementById('tp-stats').textContent, 
                sp: result.sp_points,
                failed: document.getElementById('failed-stats').textContent,
                rank: result.rank,
                daily_count: document.getElementById('daily-count-stats').textContent
            });
            button.textContent = 'Collected(💎):'; // UI update
        } else {
            alert(result.message);
            button.disabled = false; // Re-enable if server failed
        }
    }
    
    /**
     * Saves the user's main objective.
     */
    async function saveObjective() {
        const objective = document.getElementById('objective-input').value.trim();
        const result = await postAction({ 
            endpoint: 'save_objective', 
            objective: objective 
        });
        
        if (result.success) {
            alert('Objective saved successfully!');
            // After saving, reload to remove the "Pro max programmer xd." placeholder logic
            if (objective === '') {
                window.location.reload(); 
            }
        } else {
            alert(result.message);
        }
    }

    // Close dropdown menu when clicking outside
    document.addEventListener('click', (event) => {
        const menu = document.getElementById('dropdown-menu');
        const button = document.querySelector('.hamburger-btn');
        if (!menu.contains(event.target) && !button.contains(event.target)) {
            menu.classList.remove('show');
        }
    });

</script>
</body>
</html>
    <?php
    return ob_get_clean(); // Return the buffered HTML
}

/**
 * Helper function to render a single task's HTML.
 * Used during initial page load in generateHtml().
 */
function renderTaskHtml($task) {
    $completedClass = $task['completed'] ? 'completed-slot' : '';
    $completedAttr = $task['completed'] ? 'checked' : '';
    $permanentIndicator = $task['permanent'] ? '<span class="permanent-indicator" title="Permanent Daily Task">🔒</span>' : '';
    // UI UPDATE: BUTTON TEXT STANDARDIZED
    $permanentBtnText = $task['permanent'] ? 'Unlock' : 'Lock';
    // Ensure the onclick attribute sets the correct status for the NEXT click
    $nextStatus = $task['permanent'] ? 'false' : 'true';

    return '
        <div class="task-slot ' . $completedClass . '" id="task-' . $task['id'] . '" data-id="' . $task['id'] . '" data-permanent="' . ($task['permanent'] ? 'true' : 'false') . '" data-completed="' . ($task['completed'] ? 'true' : 'false') . '">
            <input type="checkbox" class="task-checkbox" ' . $completedAttr . ' onchange="toggleTask(\'' . $task['id'] . '\')">
            <div class="task-description-wrapper">
                ' . $permanentIndicator . '
                <span class="task-description ' . ($completedAttr ? 'completed' : '') . '">' . htmlspecialchars($task['text']) . '</span>
            </div>
            <button class="permanent-btn" data-permanent="' . ($task['permanent'] ? 'true' : 'false') . '" onclick="togglePermanent(\'' . $task['id'] . '\', ' . $nextStatus . ')">' . $permanentBtnText . '</button>
            <button class="remove-btn" onclick="deleteTask(\'' . $task['id'] . '\')">REMOVE</button>
        </div>
    ';
}

// --- EXECUTE MAIN APPLICATION LOGIC ---
if ($loggedInUser) {
    handleRequest($loggedInUser, $dbManager);
}

// Close the database connection
$dbManager->close();
?>
