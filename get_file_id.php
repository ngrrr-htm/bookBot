<?php
/**
 * ONE-TIME HELPER: run this to find the file_id of a PDF you've sent to your bot.
 *
 * HOW TO USE:
 * 1. Upload this file to your host, e.g. ketab-negar.ir/telegram/get_file_id.php
 * 2. Fill in your BOT_TOKEN below.
 * 3. Temporarily set the webhook to point here instead of bot.php:
 *    https://api.telegram.org/bot<TOKEN>/setWebhook?url=https://ketab-negar.ir/telegram/get_file_id.php
 * 4. Open a chat with your bot and send it the PDF file directly (as a document, not as a link).
 * 5. Visit https://ketab-negar.ir/telegram/log.txt in your browser to see the file_id that was printed.
 * 6. Copy that file_id into $PDF_FILE_ID in bot.php.
 * 7. IMPORTANT: switch the webhook back to bot.php afterwards:
 *    https://api.telegram.org/bot<TOKEN>/setWebhook?url=https://ketab-negar.ir/telegram/bot.php
 * 8. Delete this file and log.txt from your host when done (it's just a one-time tool).
 */

$content = file_get_contents("php://input");
$update = json_decode($content, true);

if (isset($update["message"]["document"])) {
    $fileId = $update["message"]["document"]["file_id"];
    $fileName = $update["message"]["document"]["file_name"] ?? "unknown";
    file_put_contents(__DIR__ . "/log.txt", "File: {$fileName}\nfile_id: {$fileId}\n", FILE_APPEND);
}

http_response_code(200);
echo "OK";
