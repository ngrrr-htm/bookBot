<?php
/**
 * bookstore telegram bot (Webhook version (PHP + MySQL))
 * features:
 * -multiple admins
 * -user sends a receipt for the selected book, then receipt is sent to admins' dm,
 *  admins can accept or reject a receipt. if accept -> file is sent to uesr
 *                                        if reject -> user in informed of rejection with costumised message
 * -avoids "query is too old" errors with method below:
 *      responds to Telegram instantly via fastcgi_finish_request(), then keeps
 *      working in the background. Also answers callback queries immediately.
 * -debug_log is created: for better recognition of bugs - with the date f accurance
 */

// --- CONFIG ---
// where secret & important data is kept and imported here (safety messures)
require __DIR__ . "/config.php";
$TOKEN = BOT_TOKEN;
$ADMIN_CHAT_IDS = ADMIN_IDS;
$CARD_NUMBER = CARD_NUMBER;

// -----------------

$API_URL = "https://api.telegram.org/bot{$TOKEN}/";
$DEBUG_LOG = __DIR__ . "/debug.log";

function debugLog($file, $msg) {
    file_put_contents($file, "[" . date("Y-m-d H:i:s") . "] " . $msg . "\n", FILE_APPEND);
}

function getDb() {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            // DB info is imported from CONFIG
            "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }
    return $pdo;
}

function telegramRequest($apiUrl, $method, $params) {
    global $DEBUG_LOG;
    $ch = curl_init($apiUrl . $method);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        debugLog($DEBUG_LOG, "cURL error calling {$method}: {$curlError}");
        return null;
    }
    $decoded = json_decode($response, true);
    if (!$decoded || (isset($decoded["ok"]) && $decoded["ok"] === false)) {
        debugLog($DEBUG_LOG, "Telegram API error calling {$method}: " . $response);
    }
    return $decoded;
}

function isAdmin($userId, $adminIds) {
    foreach ($adminIds as $id) {
        if ((string)$id === (string)$userId) return true;
    }
    return false;
}

function getBook($bookId) {
    $stmt = getDb()->prepare("SELECT * FROM Books WHERE BookID = ?");
    $stmt->execute([$bookId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function getAllBooks() {
    $stmt = getDb()->query("SELECT * FROM Books");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function setUserState($chatId, $bookId) {
    $stmt = getDb()->prepare(
        "INSERT INTO UserState (ChatID, CurrentBookID) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE CurrentBookID = VALUES(CurrentBookID)"
    );
    $stmt->execute([$chatId, $bookId]);
}

function clearUserState($chatId) {
    $stmt = getDb()->prepare("DELETE FROM UserState WHERE ChatID = ?");
    $stmt->execute([$chatId]);
}

function getUserState($chatId) {
    $stmt = getDb()->prepare("SELECT CurrentBookID FROM UserState WHERE ChatID = ?");
    $stmt->execute([$chatId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? $row["CurrentBookID"] : null;
}

function createOrder($bookId, $chatId, $username, $firstName, $receiptFileId) {
    $stmt = getDb()->prepare(
        "INSERT INTO Orders (BookID, BuyerChatID, BuyerUsername, BuyerFirstName, ReceiptFileID, Status)
         VALUES (?, ?, ?, ?, ?, 'pending')"
    );
    $stmt->execute([$bookId, $chatId, $username, $firstName, $receiptFileId]);
    return getDb()->lastInsertId();
}

function saveAdminMessage($orderId, $adminChatId, $messageId) {
    $stmt = getDb()->prepare(
        "INSERT INTO OrderAdminMessages (OrderID, AdminChatID, MessageID) VALUES (?, ?, ?)"
    );
    $stmt->execute([$orderId, $adminChatId, $messageId]);
}

function getOrder($orderId) {
    $stmt = getDb()->prepare("SELECT * FROM Orders WHERE OrderID = ?");
    $stmt->execute([$orderId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function getOrderAdminMessages($orderId) {
    $stmt = getDb()->prepare("SELECT AdminChatID, MessageID FROM OrderAdminMessages WHERE OrderID = ?");
    $stmt->execute([$orderId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function tryHandleOrder($orderId, $newStatus, $handledByName) {
    $stmt = getDb()->prepare(
        "UPDATE Orders
         SET Status = ?, HandledBy = ?, HandledAt = NOW()
         WHERE OrderID = ? AND Status = 'pending'"
    );
    $stmt->execute([$newStatus, $handledByName, $orderId]);
    return $stmt->rowCount() === 1;
}

// --- Webhook handling ---

$content = file_get_contents("php://input");
$update = json_decode($content, true);

if (!$update) {
    http_response_code(200);
    exit;
}

// ---> One of the parts, contributing to speed (Telegram side-preventing "connection timeout): 
//      Telegram no longer waits for all the backend stuff to be done
//      the request to Tell is sent immediately, then the rest is done

ignore_user_abort(true);
header("Connection: close");
ob_start();
echo "OK";
$responseSize = ob_get_length();
header("Content-Length: {$responseSize}");
ob_end_flush();
flush();
if (function_exists("fastcgi_finish_request")) {
    fastcgi_finish_request();
}

// --- Button taps ---
if (isset($update["callback_query"])) {
    $callback = $update["callback_query"];
    $data = $callback["data"];
    $fromId = $callback["from"]["id"];
    $chatId = $callback["message"]["chat"]["id"];

    // User tapped a book from the menu
    
    if (strpos($data, "book:") === 0) {
        $bookId = substr($data, strlen("book:"));
        telegramRequest($API_URL, "answerCallbackQuery", ["callback_query_id" => $callback["id"]]);
        $book = getBook($bookId);
        if (!$book) {
            http_response_code(200);
            exit;
        }
        // IsFree: 0 = paid, 1 = free
        if ($book["IsFree"]) { 
			clearUserState($chatId); 
            $text = "📖 {$book['Title']}\n\n{$book['Description']}\n\nthis book is free. sending file now...";
            telegramRequest($API_URL, "sendMessage", ["chat_id" => $chatId, "text" => $text]);
            telegramRequest($API_URL, "sendDocument", ["chat_id" => $chatId, "document" => $book["FileID"]]);
            http_response_code(200);
            exit;
        }

        setUserState($chatId, $bookId);

        $text = "📖 {$book['Title']}\n\n{$book['Description']}\n\nprice: {$book['Price']}\n\n"
            . "please transfer the fee and send the receipt:\n\n"
            . "credit card number:\n{$CARD_NUMBER}";

        telegramRequest($API_URL, "sendMessage", ["chat_id" => $chatId, "text" => $text]);
        http_response_code(200);
        exit;
    }

    // Admin Approves/Rejects
    if (strpos($data, "approve:") === 0 || strpos($data, "reject:") === 0) {
        if (!isAdmin($fromId, $ADMIN_CHAT_IDS)) {
            http_response_code(200);
            exit;
        }
        telegramRequest($API_URL, "answerCallbackQuery", ["callback_query_id" => $callback["id"]]);

        list($action, $orderId) = explode(":", $data);
        $order = getOrder($orderId);

        if (!$order) {
            http_response_code(200);
            exit;
        }

        $handledByName = $callback["from"]["first_name"] ?? "Admin";
        $newStatus = $action === "approve" ? "approved" : "rejected";

        $didHandle = tryHandleOrder($orderId, $newStatus, $handledByName);
        if (!$didHandle) {
            http_response_code(200);
            exit;
        }

        $buyerChatId = $order["BuyerChatID"];
        $book = getBook($order["BookID"]);

        if ($newStatus === "approved" && $book) {
            telegramRequest($API_URL, "sendMessage", [
                "chat_id" => $buyerChatId,
                "text" => "your receipt was approved.✅ sending the file now..."
            ]);
            telegramRequest($API_URL, "sendDocument", [
                "chat_id" => $buyerChatId,
                "document" => $book["FileID"]
            ]);
            $resultLabel = "✅ Approved by {$handledByName}";
        } else {
            telegramRequest($API_URL, "sendMessage", [
                "chat_id" => $buyerChatId,
                "text" => "your receipt was not approved. please contact support."
            ]);
            $resultLabel = "❌ Rejected by {$handledByName}";
        }

        $bookTitle = $book ? $book["Title"] : $order["BookID"];
        $originalCaption = "New receipt\nBook: {$bookTitle}\n"
            . "From: {$order['BuyerFirstName']} (@{$order['BuyerUsername']})\n"
            . "Order: {$orderId}";

        foreach (getOrderAdminMessages($orderId) as $row) {
            telegramRequest($API_URL, "editMessageCaption", [
                "chat_id" => $row["AdminChatID"],
                "message_id" => $row["MessageID"],
                "caption" => $originalCaption . "\n\n" . $resultLabel,
                "reply_markup" => json_encode(["inline_keyboard" => []])
            ]);
        }

        http_response_code(200);
        exit;
    }

    http_response_code(200);
    exit;
}

// bot messages
if (!isset($update["message"])) {
    http_response_code(200);
    exit;
}

$message = $update["message"];
$chatId = $message["chat"]["id"] ?? null;
$text = $message["text"] ?? "";

// "/start"
if ($chatId && trim($text) === "/start") {
    clearUserState($chatId); 
    $books = getAllBooks();
    $buttons = [];
    foreach ($books as $book) {
        $label = $book["IsFree"] ? "{$book['Title']} 🎁" : $book["Title"];
        $buttons[] = [["text" => $label, "callback_data" => "book:{$book['BookID']}"]];
    }

    telegramRequest($API_URL, "sendMessage", [
        "chat_id" => $chatId,
        "text" => "welcome to our online bookstore!\n\nplease select your pick:",
        "reply_markup" => json_encode(["inline_keyboard" => $buttons])
    ]);

    http_response_code(200);
    exit;
}


if ($chatId && isset($message["photo"])) {
    $bookId = getUserState($chatId);
    $book = $bookId ? getBook($bookId) : null;
    //in case a receipt was sent before selecting a book:
    if (!$book) {
        telegramRequest($API_URL, "sendMessage", [
            "chat_id" => $chatId,
            "text" => "I couldn't understand which book does this receipt belong to. please select a book first: (/start)."
        ]);
        http_response_code(200);
        exit;
    }

    // builds what the receipt message looks like in admins' DMs
    $photos = $message["photo"];
    $largestPhoto = end($photos);
    $receiptFileId = $largestPhoto["file_id"];

    $username = $message["from"]["username"] ?? "";
    $firstName = $message["from"]["first_name"] ?? "";

    $orderId = createOrder($bookId, $chatId, $username, $firstName, $receiptFileId);
	clearUserState($chatId); 

    $caption = "New receipt\nBook: {$book['Title']}\nFrom: {$firstName} (@{$username})\nOrder: {$orderId}";
    $keyboard = json_encode([
        "inline_keyboard" => [[
            ["text" => "✅ Approve", "callback_data" => "approve:{$orderId}"],
            ["text" => "❌ Reject", "callback_data" => "reject:{$orderId}"]
        ]]
    ]);

    foreach ($ADMIN_CHAT_IDS as $adminChatId) {
        $sent = telegramRequest($API_URL, "sendPhoto", [
            "chat_id" => $adminChatId,
            "photo" => $receiptFileId,
            "caption" => $caption,
            "reply_markup" => $keyboard
        ]);
        if (isset($sent["result"]["message_id"])) {
            saveAdminMessage($orderId, $adminChatId, $sent["result"]["message_id"]);
        }
    }

    telegramRequest($API_URL, "sendMessage", [
        "chat_id" => $chatId,
        "text" => "your receipt was sent and is awaiting approval. please wait... ⏳"
    ]);

    http_response_code(200);
    exit;
}

http_response_code(200);
exit;
