<?php
// =========================================================
// config.php (V1.0 - Centralized Configuration)
// =========================================================

// --- 1. APPLICATION & SERVER CONFIGURATION ---
const DB_FILE_PATH = 'data.db'; 
const TIMEZONE_RESET = 'Asia/Kolkata';
// Session TTL: 30 days. This makes sessions feel more "permanent" for a local app.
const SESSION_TTL_SECONDS = 30 * 86400; 

// --- 2. POINT & REWARD CONSTANTS ---
// New variable names for clarity
const TASK_COMPLETION_REWARD = 2;     // Points gained per task (Task_Points)
const DAILY_CHECKIN_REWARD = 10;       // Points gained for daily sign-in (SP_Points)
const DAILY_FAILURE_PENALTY = 1;      // Points penalized for missing daily quota (Failed_Points)

// --- 3. COLOR & STYLE CONSTANTS ---
// These are defined here but primarily used in style.css or embedded HTML
const COLOR_MAIN_BG = '#0d0d0d';        // Body Background (Dark)
const COLOR_MAIN_TEXT = '#00ff99';      // Standard Green Text (Highlight)
const COLOR_HEADER_BG = '#1a1a1a';      // Header Background (Slightly Lighter Dark)
const COLOR_HEADER_ACCENT = '#32CD32';  // Header Bottom Border / Menu Button (Lime Green)
const COLOR_RANK_LABEL = '#ff9900';     // Rank Label/Headers (Orange)
const COLOR_RANK_TITLE = '#FFD700';     // Rank Title/Star (Gold)
const COLOR_DROPDOWN_BG = '#333';       // Dropdown Menu Background
const COLOR_DROPDOWN_ALERT = '#ff0000'; // Dropdown Menu Text (Log out/Delete - Red Alert)
const COLOR_MODAL_BG = '#282828';       // Modal/Container background
const COLOR_BUTTON_SUCCESS = '#00ff99'; // Objective Save Button (Bright Green)
const COLOR_BUTTON_ACTION = '#0099ff';  // Add Task/Login Button (Blue)

// --- 4. RANK THRESHOLDS ---
// Define the rank structure based on SP_Points
const RANK_THRESHOLDS = [
    ['sp' => 16500, 'title' => 'Code Wizard 🧙'],           // Ultima>
    ['sp' => 14000, 'title' => 'Software Master 🏆'],       // Top Ti>
    ['sp' => 12000, 'title' => 'System Architect 🏗️'],     // High-Lev>    
    ['sp' => 10000, 'title' => 'Senior Specialist 🌟'],     // Expert>
    ['sp' => 8000, 'title' => 'Refactor Engineer 🛠️'],      // Code Qu>    
    ['sp' => 6000, 'title' => 'Domain Specialist 🖥️'],      // Deep Su>
    ['sp' => 4500, 'title' => 'Senior Developer ✨'],      // Autonom>    
    ['sp' => 3000, 'title' => 'Assiocate Software Engineer 💡'], // I>
    ['sp' => 1800, 'title' => 'Full stack Dev 🌐'],         // Compre>
    ['sp' => 900, 'title' => 'Developer 💾'],              // Solid C>    
    ['sp' => 400, 'title' => 'Junior Developer 💻'],      // First Of>
    ['sp' => 150, 'title' => 'Front End Dev 🎨'],           // Initia>    
    ['sp' => 50, 'title' => 'Trainee Coder 🌱'],            // Learni>
    ['sp' => 0, 'title' => 'Aspiring 🚀']
];
