<?php
ob_start();
session_start();
require_once 'config.php';
require_once 'utils.php';

// Suppress PHP warnings in production
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

if (empty($_SESSION['user_id'])) {
    error_log("No user_id in session");
    header('Content-Type: application/json');
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Session expired. Please log in again.']);
    ob_end_flush();
    exit();
}

$userId = $_SESSION['user_id'];
error_log("Session user_id: " . ($userId ?? 'not set'));

// Initialize session array for verified passwords
if (!isset($_SESSION['verified_notes'])) {
    $_SESSION['verified_notes'] = [];
}

// Fetch user details
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

// Send sharing email
function sendSharingEmail($pdo, $recipientEmail, $noteId, $noteTitle, $permission) {
    $htmlBody = "A note titled '$noteTitle' has been shared with you (Permission: $permission). <a href='http://localhost/notes_frontend.php?action=edit&id=$noteId'>View Note</a>";
    $textBody = "A note titled '$noteTitle' has been shared with you (Permission: $permission). View it at: http://localhost/notes_frontend.php?action=edit&id=$noteId";
    return sendEmail($recipientEmail, '', 'Note Shared with You', $htmlBody, $textBody);
}

// Check if a note is accessible (owner or shared user)
function isNoteAccessible($pdo, $noteId, $userId) {
    $stmt = $pdo->prepare("
        SELECT n.password_hash, n.user_id, ns.recipient_user_id 
        FROM notes n 
        LEFT JOIN note_shares ns ON n.id = ns.note_id AND ns.recipient_user_id = :user_id 
        WHERE n.id = :note_id
    ");
    $stmt->execute(['note_id' => $noteId, 'user_id' => $userId]);
    $note = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$note) {
        error_log("Note ID: $noteId not found");
        return false; // Note doesn't exist
    }
    if ($note['user_id'] != $userId && !$note['recipient_user_id']) {
        error_log("User $userId has no access to note ID: $noteId");
        return false; // User is neither owner nor shared recipient
    }
    if ($note['password_hash'] === null) {
        error_log("Note ID: $noteId is unlocked (no password)");
        return true; // No password protection
    }
    $isVerified = isset($_SESSION['verified_notes'][$noteId]) && $_SESSION['verified_notes'][$noteId] === true;
    error_log("Note ID: $noteId is locked, verified: " . ($isVerified ? 'true' : 'false'));
    return $isVerified;
}

// Handle set password
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_password'])) {
    header('Content-Type: application/json');
    $noteId = filter_input(INPUT_POST, 'note_id', FILTER_VALIDATE_INT) ?: null;
    $password = trim($_POST['password'] ?? '');

    if (!$noteId || empty($password)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Note ID and password required']);
        error_log("Invalid set_password request: note_id=$noteId");
        ob_end_flush();
        exit();
    }

    try {
        // Verify user owns the note
        $stmt = $pdo->prepare("SELECT id FROM notes WHERE id = :id AND user_id = :user_id");
        $stmt->execute(['id' => $noteId, 'user_id' => $userId]);
        if (!$stmt->fetch()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'No permission to set password']);
            error_log("User $userId attempted to set password for note $noteId without ownership");
            ob_end_flush();
            exit();
        }

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE notes SET password_hash = :password_hash WHERE id = :note_id");
        $stmt->execute(['password_hash' => $passwordHash, 'note_id' => $noteId]);
        $_SESSION['verified_notes'][$noteId] = true; // Auto-verify after setting
        error_log("Password set for note ID: $noteId by user ID: $userId");
        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        error_log("Set password PDO error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database error']);
    }
    ob_end_flush();
    exit();
}

// Handle remove password
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_password'])) {
    header('Content-Type: application/json');
    $noteId = filter_input(INPUT_POST, 'note_id', FILTER_VALIDATE_INT) ?: null;

    if (!$noteId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Note ID required']);
        error_log("Invalid remove_password request: note_id=$noteId");
        ob_end_flush();
        exit();
    }

    try {
        // Verify user owns the note
        $stmt = $pdo->prepare("SELECT id FROM notes WHERE id = :id AND user_id = :user_id");
        $stmt->execute(['id' => $noteId, 'user_id' => $userId]);
        if (!$stmt->fetch()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'No permission to remove password']);
            error_log("User $userId attempted to remove password for note $noteId without ownership");
            ob_end_flush();
            exit();
        }

        $stmt = $pdo->prepare("UPDATE notes SET password_hash = NULL WHERE id = :note_id");
        $stmt->execute(['note_id' => $noteId]);
        unset($_SESSION['verified_notes'][$noteId]);
        error_log("Password removed for note ID: $noteId by user ID: $userId");
        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        error_log("Remove password PDO error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database error']);
    }
    ob_end_flush();
    exit();
}

// Handle verify password
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_password'])) {
    header('Content-Type: application/json');
    $noteId = filter_input(INPUT_POST, 'note_id', FILTER_VALIDATE_INT) ?: null;
    $password = trim($_POST['password'] ?? '');

    if (!$noteId || empty($password)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Note ID and password required']);
        error_log("Invalid verify_password request: note_id=$noteId");
        ob_end_flush();
        exit();
    }

    try {
        $stmt = $pdo->prepare("SELECT password_hash FROM notes WHERE id = :note_id");
        $stmt->execute(['note_id' => $noteId]);
        $note = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$note) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Note not found']);
            error_log("Note ID: $noteId not found for password verification");
            ob_end_flush();
            exit();
        }

        if ($note['password_hash'] === null || password_verify($password, $note['password_hash'])) {
            $_SESSION['verified_notes'][$noteId] = true;
            error_log("Password verified for note ID: $noteId by user ID: $userId");
            echo json_encode(['success' => true]);
        } else {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Incorrect password']);
            error_log("Incorrect password for note ID: $noteId by user ID: $userId");
        }
    } catch (PDOException $e) {
        error_log("Verify password PDO error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database error']);
    }
    ob_end_flush();
    exit();
}

// Handle relock
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['relock'])) {
    header('Content-Type: application/json');
    $noteId = filter_input(INPUT_POST, 'note_id', FILTER_VALIDATE_INT) ?: null;

    if (!$noteId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Note ID required']);
        error_log("Invalid relock request: note_id=$noteId");
        ob_end_flush();
        exit();
    }

    try {
        // Verify note exists and is locked
        $stmt = $pdo->prepare("SELECT password_hash FROM notes WHERE id = :note_id AND user_id = :user_id");
        $stmt->execute(['note_id' => $noteId, 'user_id' => $userId]);
        $note = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$note || $note['password_hash'] === null) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Note is not locked or you lack permission']);
            error_log("User $userId attempted to relock note $noteId without permission or note not locked");
            ob_end_flush();
            exit();
        }

        unset($_SESSION['verified_notes'][$noteId]);
        error_log("Relocked note ID: $noteId by user ID: $userId");
        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        error_log("Relock PDO error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database error']);
    }
    ob_end_flush();
    exit();
}

// Handle save (autosave via JSON POST ?save=1&id=:id or /notes/:id)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_GET['save']) && $_GET['save'] == 1 || preg_match('#^/notes/(\d+)$#', $_SERVER['REQUEST_URI'], $matches))) {
    header('Content-Type: application/json');
    $noteId = isset($matches[1]) ? (int)$matches[1] : (filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: null);
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid JSON payload']);
        error_log("Invalid JSON payload for save id=$noteId");
        ob_end_flush();
        exit();
    }
    $title = trim($input['title'] ?? '');
    $content = trim($input['content'] ?? '');
    $labelNames = isset($input['labels']) ? array_filter(array_map('trim', explode(',', $input['labels']))) : [];

    if (empty($content)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Content required']);
        error_log("Content required for save id=$noteId");
        ob_end_flush();
        exit();
    }

    try {
        if ($noteId) {
            // Check if note is accessible
            if (!isNoteAccessible($pdo, $noteId, $userId)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Password required to edit this note']);
                error_log("User $userId attempted to edit note $noteId without password verification");
                ob_end_flush();
                exit();
            }

            // Check if user owns the note or has edit permission
            $stmt = $pdo->prepare("SELECT n.id, n.user_id, ns.permission FROM notes n LEFT JOIN note_shares ns ON n.id = ns.note_id AND ns.recipient_user_id = :user_id WHERE n.id = :note_id");
            $stmt->execute(['note_id' => $noteId, 'user_id' => $userId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            error_log("Permission check for note ID: $noteId, user ID: $userId, result: " . json_encode($result));

            if (!$result || ($result['user_id'] != $userId && $result['permission'] != 'edit')) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'You do not have permission to edit this note']);
                error_log("User $userId attempted to edit note $noteId without permission. Note owner: " . ($result['user_id'] ?? 'unknown') . ", Share permission: " . ($result['permission'] ?? 'none'));
                ob_end_flush();
                exit();
            }
        }

        // Get or create label IDs
        $labelIds = [];
        foreach ($labelNames as $labelName) {
            if (!empty($labelName)) {
                $stmt = $pdo->prepare("SELECT id FROM labels WHERE user_id = :user_id AND name = :name");
                $stmt->execute(['user_id' => $userId, 'name' => $labelName]);
                $label = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($label) {
                    $labelIds[] = $label['id'];
                } else {
                    $stmt = $pdo->prepare("INSERT INTO labels (user_id, name) VALUES (:user_id, :name)");
                    $stmt->execute(['user_id' => $userId, 'name' => $labelName]);
                    $labelIds[] = $pdo->lastInsertId();
                    error_log("Created new label: $labelName for user ID: $userId");
                }
            }
        }

        if (empty($noteId)) {
            // Create new note
            $stmt = $pdo->prepare("INSERT INTO notes (user_id, title, content, created_at, updated_at) VALUES (:user_id, :title, :content, NOW(), NOW())");
            $stmt->execute(['user_id' => $userId, 'title' => $title, 'content' => $content]);
            $newId = $pdo->lastInsertId();
            error_log("New note created with ID: $newId");

            // Assign labels
            if (!empty($labelIds)) {
                $placeholders = implode(',', array_fill(0, count($labelIds), '(?, ?)'));
                $values = [];
                foreach ($labelIds as $labelId) {
                    $values[] = $newId;
                    $values[] = $labelId;
                }
                $stmt = $pdo->prepare("INSERT IGNORE INTO note_labels (note_id, label_id) VALUES $placeholders");
                $stmt->execute($values);
                error_log("Assigned labels " . implode(',', $labelIds) . " to note ID: $newId");
            }

            echo json_encode(['id' => $newId, 'success' => true, 'updated_at' => date('Y-m-d H:i:s')]);
        } else {
            // Update note
            $stmt = $pdo->prepare("UPDATE notes SET title = :title, content = :content, updated_at = NOW() WHERE id = :id");
            $stmt->execute(['title' => $title, 'content' => $content, 'id' => $noteId]);
            error_log("Note updated with ID: $noteId");

            // Update labels
            $stmt = $pdo->prepare("DELETE FROM note_labels WHERE note_id = :note_id");
            $stmt->execute(['note_id' => $noteId]);
            if (!empty($labelIds)) {
                $placeholders = implode(',', array_fill(0, count($labelIds), '(?, ?)'));
                $values = [];
                foreach ($labelIds as $labelId) {
                    $values[] = $noteId;
                    $values[] = $labelId;
                }
                $stmt = $pdo->prepare("INSERT IGNORE INTO note_labels (note_id, label_id) VALUES $placeholders");
                $stmt->execute($values);
                error_log("Updated labels " . implode(',', $labelIds) . " to note ID: $noteId");
            }

            echo json_encode(['id' => $noteId, 'success' => true, 'updated_at' => date('Y-m-d H:i:s')]);
        }
    } catch (PDOException $e) {
        error_log("Save PDO error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database error']);
    }
    ob_end_flush();
    exit();
}

// Handle image upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['image'])) {
    $noteId = filter_input(INPUT_POST, 'note_id', FILTER_VALIDATE_INT) ?: null;
    if ($noteId && !empty($_FILES['image']['name'])) {
        // Check if note is accessible
        if (!isNoteAccessible($pdo, $noteId, $userId)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Password required to upload image']);
            error_log("User $userId attempted to upload image for note $noteId without password verification");
            ob_end_flush();
            exit();
        }

        // Check if user owns the note or has edit permission
        $stmt = $pdo->prepare("SELECT n.id, n.user_id, ns.permission FROM notes n LEFT JOIN note_shares ns ON n.id = ns.note_id AND ns.recipient_user_id = :user_id WHERE n.id = :note_id");
        $stmt->execute(['note_id' => $noteId, 'user_id' => $userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        error_log("Image upload permission check for note ID: $noteId, user ID: $userId, result: " . json_encode($result));

        if ($result && ($result['user_id'] == $userId || $result['permission'] == 'edit')) {
            $uploadDir = 'Uploads/';
            if (!is_dir($uploadDir)) {
                if (!mkdir($uploadDir, 0777, true)) {
                    error_log("Failed to create uploads directory");
                    echo json_encode(['success' => false, 'error' => 'Failed to create uploads directory']);
                    ob_end_flush();
                    exit();
                }
            }
            $fileName = uniqid() . '_' . basename($_FILES['image']['name']);
            $filePath = $uploadDir . $fileName;

            if (move_uploaded_file($_FILES['image']['tmp_name'], $filePath)) {
                try {
                    $stmt = $pdo->prepare("INSERT INTO note_images (note_id, image_path) VALUES (:note_id, :image_path)");
                    $stmt->execute(['note_id' => $noteId, 'image_path' => $filePath]);
                    error_log("Image uploaded for note ID: $noteId, path: $filePath");
                    echo json_encode(['success' => true, 'image_path' => $filePath]);
                } catch (PDOException $e) {
                    error_log("Image save PDO error: " . $e->getMessage());
                    echo json_encode(['success' => false, 'error' => 'Database error']);
                }
            } else {
                error_log("Failed to move uploaded file: " . $_FILES['image']['error']);
                echo json_encode(['success' => false, 'error' => 'Failed to upload image']);
            }
        } else {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'You do not have permission to upload an image for this note']);
            error_log("User $userId attempted to upload image for note $noteId without permission. Note owner: " . ($result['user_id'] ?? 'unknown') . ", Share permission: " . ($result['permission'] ?? 'none'));
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid note ID or no image provided']);
    }
    ob_end_flush();
    exit();
}

// Handle add label
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_label'])) {
    header('Content-Type: application/json');
    $labelName = trim($_POST['label_name'] ?? '');
    if (empty($labelName)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Label name required']);
        error_log("Invalid label name for add_label");
        ob_end_flush();
        exit();
    }

    try {
        $stmt = $pdo->prepare("SELECT id FROM labels WHERE user_id = :user_id AND name = :name");
        $stmt->execute(['user_id' => $userId, 'name' => $labelName]);
        if ($stmt->fetch()) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Label already exists']);
            error_log("Label $labelName already exists for user ID: $userId");
        } else {
            $stmt = $pdo->prepare("INSERT INTO labels (user_id, name) VALUES (:user_id, :name)");
            $stmt->execute(['user_id' => $userId, 'name' => $labelName]);
            error_log("Added new label: $labelName for user ID: $userId");
            echo json_encode(['success' => true]);
        }
    } catch (PDOException $e) {
        error_log("Add label PDO error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database error']);
    }
    ob_end_flush();
    exit();
}

// Handle rename label
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rename_label'])) {
    header('Content-Type: application/json');
    $oldName = trim($_POST['old_name'] ?? '');
    $newName = trim($_POST['new_name'] ?? '');
    if (empty($oldName) || empty($newName)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Old and new label names required']);
        error_log("Invalid label names for rename_label");
        ob_end_flush();
        exit();
    }

    try {
        $stmt = $pdo->prepare("SELECT id FROM labels WHERE user_id = :user_id AND name = :name");
        $stmt->execute(['user_id' => $userId, 'name' => $oldName]);
        $label = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$label) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Label not found']);
            error_log("Label $oldName not found for user ID: $userId");
            ob_end_flush();
            exit();
        }

        $stmt = $pdo->prepare("SELECT id FROM labels WHERE user_id = :user_id AND name = :name");
        $stmt->execute(['user_id' => $userId, 'name' => $newName]);
        if ($stmt->fetch()) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'New label name already exists']);
            error_log("New label name $newName already exists for user ID: $userId");
            ob_end_flush();
            exit();
        }

        $stmt = $pdo->prepare("UPDATE labels SET name = :new_name WHERE id = :id AND user_id = :user_id");
        $stmt->execute(['new_name' => $newName, 'id' => $label['id'], 'user_id' => $userId]);
        error_log("Renamed label from $oldName to $newName for user ID: $userId");
        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        error_log("Rename label PDO error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database error']);
    }
    ob_end_flush();
    exit();
}

// Handle delete label
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_label'])) {
    header('Content-Type: application/json');
    $labelName = trim($_POST['label_name'] ?? '');
    if (empty($labelName)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Label name required']);
        error_log("Invalid label name for delete_label");
        ob_end_flush();
        exit();
    }

    try {
        $stmt = $pdo->prepare("SELECT id FROM labels WHERE user_id = :user_id AND name = :name");
        $stmt->execute(['user_id' => $userId, 'name' => $labelName]);
        $label = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$label) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Label not found']);
            error_log("Label $labelName not found for user ID: $userId");
            ob_end_flush();
            exit();
        }

        $stmt = $pdo->prepare("DELETE FROM note_labels WHERE label_id = :label_id");
        $stmt->execute(['label_id' => $label['id']]);
        $stmt = $pdo->prepare("DELETE FROM labels WHERE id = :id AND user_id = :user_id");
        $stmt->execute(['id' => $label['id'], 'user_id' => $userId]);
        error_log("Deleted label $labelName for user ID: $userId");
        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        error_log("Delete label PDO error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database error']);
    }
    ob_end_flush();
    exit();
}

// Handle pin/unpin
if (isset($_GET['pin']) && isset($_GET['id'])) {
    $isPinned = filter_input(INPUT_GET, 'pin', FILTER_VALIDATE_INT) === 1 ? 1 : 0;
    $noteId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    $pinnedAt = $isPinned ? date('Y-m-d H:i:s') : null;

    if (!$noteId) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Invalid note ID']);
        ob_end_flush();
        exit();
    }

    // Check if note is accessible
    if (!isNoteAccessible($pdo, $noteId, $userId)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Password required to pin/unpin this note']);
        error_log("User $userId attempted to pin/unpin note $noteId without password verification");
        ob_end_flush();
        exit();
    }

    try {
        $stmt = $pdo->prepare("UPDATE notes SET is_pinned = :pinned, pinned_at = :pinned_at WHERE id = :id AND user_id = :user_id");
        $stmt->bindValue(':pinned', $isPinned, PDO::PARAM_INT);
        $stmt->bindValue(':pinned_at', $pinnedAt, $pinnedAt === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':id', $noteId, PDO::PARAM_INT);
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();
        error_log("Note ID: $noteId " . ($isPinned ? "pinned" : "unpinned"));
        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        error_log("Pin PDO error: " . $e->getMessage());
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database error']);
    }
    ob_end_flush();
    exit();
}

// Handle note sharing
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['share_note'])) {
    header('Content-Type: application/json');
    $noteId = filter_input(INPUT_POST, 'note_id', FILTER_VALIDATE_INT) ?: null;
    $emails = isset($_POST['emails']) ? array_filter(array_map('trim', explode(',', $_POST['emails']))) : [];
    $permission = filter_input(INPUT_POST, 'permission', FILTER_SANITIZE_STRING);

    if (!$noteId || empty($emails) || !in_array($permission, ['read', 'edit'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid note ID, emails, or permission']);
        error_log("Invalid share request: note_id=$noteId, emails=" . implode(',', $emails) . ", permission=$permission");
        ob_end_flush();
        exit();
    }

    // Check if note is accessible
    if (!isNoteAccessible($pdo, $noteId, $userId)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Password required to share this note']);
        error_log("User $userId attempted to share note $noteId without password verification");
        ob_end_flush();
        exit();
    }

    try {
        // Verify user owns the note
        $stmt = $pdo->prepare("SELECT id, title FROM notes WHERE id = :id AND user_id = :user_id");
        $stmt->execute(['id' => $noteId, 'user_id' => $userId]);
        $note = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$note) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'No permission to share note']);
            error_log("User $userId attempted to share note $noteId without ownership");
            ob_end_flush();
            exit();
        }

        $successCount = 0;
        $failedEmails = [];
        foreach ($emails as $email) {
            // Validate email format
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $failedEmails[] = $email;
                continue;
            }
            // Find recipient user
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email");
            $stmt->execute(['email' => $email]);
            $recipient = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($recipient) {
                // Insert sharing record
                $stmt = $pdo->prepare("INSERT IGNORE INTO note_shares (note_id, recipient_user_id, permission) VALUES (:note_id, :recipient_user_id, :permission)");
                $stmt->execute(['note_id' => $noteId, 'recipient_user_id' => $recipient['id'], 'permission' => $permission]);
                if ($stmt->rowCount()) {
                    if (sendSharingEmail($pdo, $email, $noteId, $note['title'], $permission)) {
                        $successCount++;
                    } else {
                        $failedEmails[] = $email;
                    }
                }
            } else {
                $failedEmails[] = $email;
            }
        }
        $response = ['success' => true, 'message' => "Shared with $successCount user(s)"];
        if (!empty($failedEmails)) {
            $response['warning'] = "Failed to share with: " . implode(', ', $failedEmails);
        }
        echo json_encode($response);
        error_log("Note ID: $noteId shared with " . implode(',', $emails) . ", success: $successCount, failed: " . implode(',', $failedEmails));
    } catch (PDOException $e) {
        error_log("Share note PDO error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database error']);
    }
    ob_end_flush();
    exit();
}

// Handle update sharing permissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_share'])) {
    header('Content-Type: application/json');
    $noteId = filter_input(INPUT_POST, 'note_id', FILTER_VALIDATE_INT) ?: null;
    $recipientUserId = filter_input(INPUT_POST, 'recipient_user_id', FILTER_VALIDATE_INT) ?: null;
    $permission = filter_input(INPUT_POST, 'permission', FILTER_SANITIZE_STRING);

    if (!$noteId || !$recipientUserId || !in_array($permission, ['read', 'edit'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid note ID, recipient, or permission']);
        ob_end_flush();
        exit();
    }

    // Check if note is accessible
    if (!isNoteAccessible($pdo, $noteId, $userId)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Password required to update sharing']);
        error_log("User $userId attempted to update sharing for note $noteId without password verification");
        ob_end_flush();
        exit();
    }

    try {
        // Verify user owns the note
        $stmt = $pdo->prepare("SELECT id FROM notes WHERE id = :id AND user_id = :user_id");
        $stmt->execute(['id' => $noteId, 'user_id' => $userId]);
        if (!$stmt->fetch()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'No permission to update sharing']);
            ob_end_flush();
            exit();
        }

        $stmt = $pdo->prepare("UPDATE note_shares SET permission = :permission WHERE note_id = :note_id AND recipient_user_id = :recipient_user_id");
        $stmt->execute(['permission' => $permission, 'note_id' => $noteId, 'recipient_user_id' => $recipientUserId]);
        echo json_encode(['success' => true]);
        error_log("Updated permission for note ID: $noteId, recipient ID: $recipientUserId to $permission");
    } catch (PDOException $e) {
        error_log("Update share PDO error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database error']);
    }
    ob_end_flush();
    exit();
}

// Handle revoke sharing
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['revoke_share'])) {
    header('Content-Type: application/json');
    $noteId = filter_input(INPUT_POST, 'note_id', FILTER_VALIDATE_INT) ?: null;
    $recipientUserId = filter_input(INPUT_POST, 'recipient_user_id', FILTER_VALIDATE_INT) ?: null;

    if (!$noteId || !$recipientUserId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid note ID or recipient']);
        ob_end_flush();
        exit();
    }

    // Check if note is accessible
    if (!isNoteAccessible($pdo, $noteId, $userId)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Password required to revoke sharing']);
        error_log("User $userId attempted to revoke sharing for note $noteId without password verification");
        ob_end_flush();
        exit();
    }

    try {
        // Verify user owns the note
        $stmt = $pdo->prepare("SELECT id FROM notes WHERE id = :id AND user_id = :user_id");
        $stmt->execute(['id' => $noteId, 'user_id' => $userId]);
        if (!$stmt->fetch()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'No permission to revoke sharing']);
            ob_end_flush();
            exit();
        }

        $stmt = $pdo->prepare("DELETE FROM note_shares WHERE note_id = :note_id AND recipient_user_id = :recipient_user_id");
        $stmt->execute(['note_id' => $noteId, 'recipient_user_id' => $recipientUserId]);
        echo json_encode(['success' => true]);
        error_log("Revoked sharing for note ID: $noteId, recipient ID: $recipientUserId");
    } catch (PDOException $e) {
        error_log("Revoke share PDO error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database error']);
    }
    ob_end_flush();
    exit();
}

// Delete note
if (isset($_GET['delete']) && isset($_GET['id'])) {
    header('Content-Type: application/json');
    $noteId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if (!$noteId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid note ID']);
        ob_end_flush();
        exit();
    }

    // Check if note is accessible
    if (!isNoteAccessible($pdo, $noteId, $userId)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Password required to delete this note']);
        error_log("User $userId attempted to delete note $noteId without password verification");
        ob_end_flush();
        exit();
    }

    try {
        $stmt = $pdo->prepare("DELETE FROM notes WHERE id = :id AND user_id = :user_id");
        $stmt->execute(['id' => $noteId, 'user_id' => $userId]);
        error_log("Deleted note ID: $noteId");
        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        error_log("Delete PDO error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database error']);
    }
    ob_end_flush();
    exit();
}

// Return notes HTML for live reload via AJAX
if (isset($_GET['action']) && $_GET['action'] === 'list') {
    header('Content-Type: text/html');
    $search = trim($_GET['search'] ?? '');
    $labelFilter = trim($_GET['label'] ?? '');
    error_log("Fetching notes with search: '$search', label: '$labelFilter' for user ID: $userId");

    $query = "
        SELECT n.id, n.user_id, n.title, n.content, n.is_pinned, n.pinned_at, n.password_hash, n.updated_at,
               GROUP_CONCAT(ni.image_path) as image_paths, GROUP_CONCAT(l.name) as label_names,
               GROUP_CONCAT(DISTINCT ns.recipient_user_id) as shared_user_ids,
               GROUP_CONCAT(DISTINCT ns.permission) as shared_permissions,
               GROUP_CONCAT(DISTINCT u2.email) as shared_emails
        FROM notes n
        LEFT JOIN note_images ni ON n.id = ni.note_id
        LEFT JOIN note_labels nl ON n.id = nl.note_id
        LEFT JOIN labels l ON nl.label_id = l.id
        LEFT JOIN note_shares ns ON n.id = ns.note_id
        LEFT JOIN users u2 ON ns.recipient_user_id = u2.id
        WHERE (n.user_id = :user_id OR ns.recipient_user_id = :user_id)
    ";
    $params = ['user_id' => $userId];

    if (!empty($search)) {
        $query .= " AND (n.title LIKE :search OR n.content LIKE :search OR l.name LIKE :search)";
        $params['search'] = "%$search%";
    }

    if (!empty($labelFilter)) {
        $query .= " AND n.id IN (
            SELECT nl2.note_id 
            FROM note_labels nl2 
            JOIN labels l2 ON nl2.label_id = l2.id 
            WHERE l2.name = :label AND l2.user_id = :user_id
        )";
        $params['label'] = $labelFilter;
    }

    $query .= " GROUP BY n.id ORDER BY n.is_pinned DESC, COALESCE(n.pinned_at, '1970-01-01') DESC, n.updated_at DESC";
    try {
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        error_log("Fetched " . count($notes) . " notes for user ID: $userId, label filter: '$labelFilter'");

        foreach ($notes as $note) {
            $isLocked = $note['password_hash'] !== null;
            $isShared = !empty($note['shared_user_ids']);
            $isAccessible = isNoteAccessible($pdo, $note['id'], $userId);
            echo '<div class="note" data-id="' . $note['id'] . '">';
            echo '<div class="note-icons">';
            if ($note['is_pinned']) {
                echo '<span class="pin-indicator" title="Pinned">📍</span>';
            }
            if ($isLocked) {
                echo '<span class="lock-indicator" title="Password-Protected">🔒</span>';
            }
            if ($isShared) {
                echo '<span class="share-indicator" title="Shared">📤</span>';
            }
            echo '</div>';
            if ($isAccessible) {
                echo '<h3>' . htmlspecialchars($note['title']) . '</h3>';
                echo '<p>' . nl2br(htmlspecialchars($note['content'])) . '</p>';
                if (!empty($note['image_paths'])) {
                    echo '<div class="note-images">';
                    foreach (explode(',', $note['image_paths']) as $image) {
                        echo '<img src="' . htmlspecialchars($image) . '" alt="Note image">';
                    }
                    echo '</div>';
                }
                if (!empty($note['label_names'])) {
                    echo '<div class="note-labels">';
                    foreach (explode(',', $note['label_names']) as $label) {
                        echo '<span class="label">' . htmlspecialchars($label) . '</span>';
                    }
                    echo '</div>';
                }
                if (!empty($note['shared_emails'])) {
                    echo '<div class="note-shared">';
                    echo '<span>Shared with: ' . htmlspecialchars($note['shared_emails']) . '</span>';
                    echo '</div>';
                }
            } else {
                echo '<h3>' . htmlspecialchars($note['title']) . '</h3>';
                echo '<p>Enter password to view content</p>';
            }
            echo '<small>Last updated: ' . $note['updated_at'] . '</small><br>';
            echo '<div class="note-actions">';
            $canEdit = $note['user_id'] == $userId || (strpos($note['shared_permissions'], 'edit') !== false && strpos($note['shared_user_ids'], (string)$userId) !== false);
            if ($isAccessible && $canEdit) {
                echo '<a href="#" onclick="editNote(' . $note['id'] . '); return false;">✏️ Edit</a> | ';
            }
            if ($note['user_id'] == $userId) {
                if ($isAccessible) {
                    echo '<a href="#" onclick="deleteNote(' . $note['id'] . ')">🗑️ Delete</a> | ';
                    echo '<a href="#" onclick="openShareModal(' . $note['id'] . ')">📤 Share</a> | ';
                    if ($isLocked) {
                        echo '<a href="#" onclick="relockNote(' . $note['id'] . ')">🔐 Relock</a> | ';
                        echo '<div class="dropdown">';
                        echo '<a href="#" class="settings-button" onclick="toggleDropdown(event, \'dropdown-' . $note['id'] . '\')">⚙️ Settings</a>';
                        echo '<div class="dropdown-content" id="dropdown-' . $note['id'] . '">';
                        echo '<a href="#" onclick="openPasswordModal(' . $note['id'] . ', \'change\')">🔐 Change Password</a>';
                        echo '<a href="#" onclick="removePassword(' . $note['id'] . ')">🔓 Remove Password</a>';
                        echo '</div>';
                        echo '</div> | ';
                    } else {
                        echo '<a href="#" onclick="openPasswordModal(' . $note['id'] . ', \'set\')">🔒 Lock Note</a> | ';
                    }
                } else {
                    echo '<a href="#" onclick="promptPassword(' . $note['id'] . ', \'access\')">🔓 Unlock</a> | ';
                }
            }
            if ($isAccessible) {
                echo $note['is_pinned']
                    ? '<a href="#" onclick="pinNote(' . $note['id'] . ', 0)">📌 Unpin</a>'
                    : '<a href="#" onclick="pinNote(' . $note['id'] . ', 1)">📍 Pin</a>';
            }
            echo '</div>';
            echo '</div>';
        }
    } catch (PDOException $e) {
        error_log("List notes PDO error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database error']);
    }
    ob_end_flush();
    exit();
}

// Fetch labels for sidebar
if (isset($_GET['action']) && $_GET['action'] === 'labels') {
    header('Content-Type: application/json');
    try {
        $stmt = $pdo->prepare("SELECT DISTINCT name FROM labels WHERE user_id = :user_id ORDER BY name");
        $stmt->execute(['user_id' => $userId]);
        $labels = $stmt->fetchAll(PDO::FETCH_COLUMN);
        echo json_encode(['success' => true, 'labels' => $labels]);
    } catch (PDOException $e) {
        error_log("Fetch labels PDO error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database error']);
    }
    ob_end_flush();
    exit();
}

// Load note for editing (return JSON for AJAX)
if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id'])) {
    header('Content-Type: application/json');
    $noteId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if ($noteId) {
        // Check if note is accessible
        if (!isNoteAccessible($pdo, $noteId, $userId)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Password required to view this note', 'is_locked' => true]);
            error_log("User $userId attempted to view note $noteId without password verification");
            ob_end_flush();
            exit();
        }

        try {
            $stmt = $pdo->prepare("
                SELECT n.*, GROUP_CONCAT(ns.recipient_user_id) as shared_user_ids,
                       GROUP_CONCAT(ns.permission) as shared_permissions,
                       GROUP_CONCAT(u2.email) as shared_emails,
                       GROUP_CONCAT(l.name) as label_names,
                       GROUP_CONCAT(ni.image_path) as image_paths
                FROM notes n
                LEFT JOIN note_shares ns ON n.id = ns.note_id
                LEFT JOIN users u2 ON ns.recipient_user_id = u2.id
                LEFT JOIN note_labels nl ON n.id = nl.note_id
                LEFT JOIN labels l ON nl.label_id = l.id
                LEFT JOIN note_images ni ON n.id = ni.note_id
                WHERE n.id = :id AND (n.user_id = :user_id OR ns.recipient_user_id = :user_id)
                GROUP BY n.id
            ");
            $stmt->execute(['id' => $noteId, 'user_id' => $userId]);
            $note = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($note) {
                echo json_encode([
                    'success' => true,
                    'id' => $note['id'],
                    'title' => $note['title'],
                    'content' => $note['content'],
                    'labels' => $note['label_names'] ? $note['label_names'] : '',
                    'updated_at' => $note['updated_at'],
                    'shared_emails' => $note['shared_emails'] ? explode(',', $note['shared_emails']) : [],
                    'shared_permissions' => $note['shared_permissions'] ? explode(',', $note['shared_permissions']) : [],
                    'shared_user_ids' => $note['shared_user_ids'] ? explode(',', $note['shared_user_ids']) : [],
                    'is_owner' => $note['user_id'] == $userId,
                    'is_locked' => $note['password_hash'] !== null,
                    'images' => $note['image_paths'] ? explode(',', $note['image_paths']) : [],
                    'is_pinned' => $note['is_pinned'] == 1
                ]);
            } else {
                error_log("Note ID: $noteId not found or inaccessible for user: $userId");
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Note not found or you lack permission']);
            }
        } catch (PDOException $e) {
            error_log("Edit note PDO error: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Database error']);
        }
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid note ID']);
    }
    ob_end_flush();
    exit();
}
?>