<?php
session_start();
require_once 'config.php';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$userId = $_SESSION['user_id'];
try {
    $stmt = $pdo->prepare("SELECT display_name, is_activated FROM users WHERE id = :user_id");
    $stmt->execute(['user_id' => $userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    $username = $user ? htmlspecialchars($user['display_name']) : 'Unknown User';
    $isActivated = $user && $user['is_activated'] ? 'Activated' : 'Not Activated';
} catch (PDOException $e) {
    error_log("User fetch PDO error: " . $e->getMessage());
    $username = 'Unknown User';
    $isActivated = 'Error';
}

$note = null;
if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id'])) {
    $noteId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if ($noteId) {
        try {
            $stmt = $pdo->prepare("SELECT * FROM notes WHERE id = :id AND (user_id = :user_id OR id IN (SELECT note_id FROM note_shares WHERE recipient_user_id = :user_id))");
            $stmt->execute(['id' => $noteId, 'user_id' => $userId]);
            $note = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Edit note PDO error: " . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notes - My Note</title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500&display=swap">
    <link rel="stylesheet" href="style.css">
    <script>
        const userId = <?php echo json_encode($_SESSION['user_id']); ?>;
        let currentNoteId = <?php echo isset($note) && $note ? json_encode($note['id']) : 'null'; ?>;
    </script>
    <script src="/js/api.js"></script>
    <script src="/js/autosave.js"></script>
    <script src="/js/ui.js"></script>
    <script src="/js/notes.js"></script>
</head>
<body>
    <div class="container">
        <div class="sidebar">
            <h2>Labels</h2>
            <input type="text" id="new-label-input" placeholder="Add new label..." />
            <ul id="label-list">
                <li class="label-item" onclick="selectLabel('')">All Notes</li>
            </ul>
        </div>
        <div class="main-content">
            <div class="header">
                <div class="user-info">
                    <span class="username"><?php echo $username; ?></span>
                    <span class="activation-status <?php echo strtolower(str_replace(' ', '-', $isActivated)); ?>">
                        <?php echo $isActivated; ?>
                    </span>
                </div>
                <h1>Notes</h1>
                <div class="header-actions">
                    <button id="view-toggle" aria-label="Toggle view layout">Grid View</button>
                    <a href="logout.php" class="logout-button">Logout</a>
                </div>
            </div>
            <div style="text-align: center; margin-bottom: 20px;">
                <input type="text" id="search" placeholder="Search notes..." />
            </div>
            <div id="note-form-container" class="note-form-container">
                <form id="note-form">
                    <input type="text" id="title" name="title" placeholder="Title" />
                    <textarea id="content" name="content" rows="1" placeholder="Take a note..."></textarea>
                    <input type="text" id="labels" name="labels" placeholder="Labels (comma-separated, e.g., work,personal)" />
                    <div class="form-actions">
                        <input type="file" id="image-upload" name="image" accept="image/*" />
                        <button type="button" id="share-button" onclick="openShareModal(currentNoteId)" style="display: none;">Share</button>
                        <button type="button" onclick="resetForm()">Clear</button>
                        <div id="save-indicator"></div>
                    </div>
                </form>
            </div>
            <div class="notes-container"></div>
        </div>
    </div>
    <div id="share-modal" class="modal" style="display: none;">
        <div class="modal-content">
            <h2>Share Note</h2>
            <label for="share-emails">Recipient Emails (comma-separated):</label>
            <input type="text" id="share-emails" placeholder="email1@example.com,email2@example.com" />
            <label for="share-permission">Permission:</label>
            <select id="share-permission">
                <option value="read">Read</option>
                <option value="edit">Edit</option>
            </select>
            <div id="share-settings" class="share-settings"></div>
            <button onclick="shareNote()">Share</button>
            <button onclick="closeShareModal()">Cancel</button>
        </div>
    </div>
    <div id="rename-label-modal" class="modal" style="display: none;">
        <div class="modal-content">
            <h2>Rename Label</h2>
            <label for="rename-label-old">Current Name:</label>
            <input type="text" id="rename-label-old" readonly />
            <label for="rename-label-new">New Name:</label>
            <input type="text" id="rename-label-new" placeholder="Enter new label name" />
            <button onclick="renameLabel()">Rename</button>
            <button onclick="closeRenameLabelModal()">Cancel</button>
        </div>
    </div>
    <div id="password-modal" class="modal" style="display: none;">
        <div class="modal-content">
            <h2 id="password-modal-title">Enter Password</h2>
            <input type="password" id="note-password" placeholder="Password" />
            <button id="password-submit">Submit</button>
            <button onclick="document.getElementById('password-modal').style.display='none'">Cancel</button>
        </div>
    </div>
</body>
</html>