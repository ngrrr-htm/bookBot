# Telegram Bookstore Bot

A Telegram-based digital bookstore built with **PHP and MySQL**. The bot lets users browse available books, receive free books instantly, and purchase paid books through a manual payment-verification workflow.

Buyers submit payment receipts through Telegram, and administrators can approve or reject orders directly from their chats. Approved orders receive their corresponding book automatically.

## Features

- **Book catalog:** Displays available books with inline Telegram buttons.
- **Free book delivery:** Sends free books immediately when selected.
- **Manual payment verification:** Displays payment instructions and a configured payment card number for paid books.
- **Receipt submission:** Buyers submit payment receipts as Telegram photos.
- **Multi-admin review:** Forwards receipts to configured administrators with Approve and Reject buttons.
- **Order management:** Stores order details, statuses, and administrator handling information in MySQL.
- **Automatic delivery:** Sends the purchased book to the buyer after an administrator approves the receipt.
- **Duplicate-action protection:** Prevents an order that has already been handled from being approved or rejected again through the same workflow.
- **Persistent user state:** Uses MySQL to remember which book a buyer selected while waiting for their receipt.
- **Telegram file ID support:** Uses Telegram document and photo file IDs rather than storing book PDFs in the repository.
- **Webhook-based operation:** Processes incoming Telegram updates through a PHP webhook.

## Tech Stack

- **PHP** — application logic and webhook handling
- **MySQL / MariaDB** — relational database and persistent storage
- **PDO** — database access and prepared SQL statements
- **cURL** — requests to the Telegram Bot API
- **Telegram Bot API** — messaging, inline keyboards, receipt forwarding, and document delivery
- **Apache** — web-server deployment, with `.htaccess` available for access restrictions

## Project Structure

```text
bookBOT/
├── bot_sql.php          # Main Telegram webhook and bot logic
├── get_file_id.php      # Temporary helper for retrieving Telegram file IDs
├── schema.sql           # Database table definitions
├── config.php           # Local configuration; not committed
├── config.example.php   # Configuration template, if provided
├── .gitignore           # Excludes local secrets and runtime files
├── .htaccess            # Web-server access restrictions, if provided
└── README.md

```

`config.php`, `config.example.php`, and `.htaccess` are deployment/configuration files; include the example and `.htaccess` in the repository only if you have created and reviewed them.

## Database Design

The database contains four tables:

| Table | Purpose |
|---|---|
| `Books` | Stores book identifiers, titles, descriptions, prices, free/paid status, and Telegram file IDs. |
| `Orders` | Stores buyer details, receipt file IDs, order status, and administrator handling information. |
| `OrderAdminMessages` | Records the receipt message sent to each administrator so the bot can update each copy after a decision. |
| `UserState` | Stores the book currently selected by each user while waiting for a receipt. |

### Order lifecycle

```text
Buyer selects a book
        |
        v
   Is it free?
    /       \
  Yes        No
   |          |
Send book   Show payment
immediately instructions
                |
                v
        Buyer sends receipt
                |
                v
        Create pending order
                |
                v
       Notify all admins
                |
                v
       Admin reviews receipt
          /          \
      Approve        Reject
         |              |
    Send book      Notify buyer
    to buyer       of rejection
```

Order statuses are `pending`, `approved`, and `rejected`.

## Requirements

Before deploying the bot, you will need:

- PHP with PDO, PDO MySQL, and cURL extensions enabled
- A MySQL or MariaDB database
- A Telegram bot token obtained through [BotFather](https://t.me/BotFather)
- One or more Telegram administrator chat IDs
- A web host that supports PHP webhooks over HTTPS
- The Telegram Bot API access required to send messages and documents

## Setup and Configuration

### 1. Create the database

Create a database on your MySQL/MariaDB server, then execute [`schema.sql`](schema.sql) against that database.

The script creates the four tables but does not populate the bookstore. Add your own book records before testing the bot.

### 2. Configure the application

Create a local `config.php` file based on the configuration template.

The main bot expects these PHP constants:

- `BOT_TOKEN` — your Telegram bot token
- `ADMIN_IDS` — an array of administrator Telegram chat IDs
- `CARD_NUMBER` — the payment card number displayed to buyers
- `DB_HOST` — database host
- `DB_NAME` — database name
- `DB_USER` — database username
- `DB_PASS` — database password

Use your own credentials and values. Never commit the real `config.php` file or expose your bot token or database credentials.

### 3. Add books

Insert book records into the `Books` table. Each record needs a unique `BookID`, title, description, price, free/paid flag, and Telegram `FileID`.

- Set `IsFree` to `1` for free books.
- Set `IsFree` to `0` for paid books.
- Supply the Telegram file ID for the document to be delivered.

The bot uses these records to populate the `/start` menu.

### 4. Deploy the webhook

Before deploying the bot itself, use a deployment URL like `https://example.com/bookbot/get_file_id.php`. After deployment, send your file to the chatbot from telegram.

On your server, a text file named "log.txt" is made; Inside, you can find your file's ID and use it in sql table. 

After getting all the IDs you need, it's best to delete both "get_file_id.php" and "log.txt" from your server.

Upload the main PHP file and configure your Telegram bot's webhook to point to its publicly accessible HTTPS URL.

For example, if your deployment URL is `https://example.com/bookbot/bot_sql.php`, configure the webhook through the Telegram Bot API's `setWebhook` method.

Replace the example domain with your actual deployment URL. Keep your bot token private when configuring the webhook.

### 5. Test the workflow

Before using the bot with real buyers, test the following:

- `/start` displays the available books.
- Selecting a free book delivers its document.
- Selecting a paid book displays the payment instructions.
- Sending a receipt creates a pending order and notifies the configured admins.
- An authorized administrator can approve or reject an order.
- Approval delivers the correct book; rejection notifies the buyer.
- Repeated approval/rejection attempts do not process an already-handled order again.

Use test records and controlled transactions before launching the bookstore publicly.

## Security Notes

- Keep `config.php` out of version control.
- Never commit real bot tokens, database passwords, payment details, receipts, or copyrighted book files without authorization.
- Use a database account with only the permissions the application needs.
- Configure web-server access restrictions to prevent direct access to sensitive configuration files and logs.
- Protect temporary diagnostic tools and remove them from production when no longer needed.
- Do not expose debug logs or file IDs unnecessarily.
- Use HTTPS for the webhook endpoint.

## Limitations

- Payments are verified manually by administrators; the bot does not integrate with a payment gateway.
- The bot expects receipts to be submitted as Telegram photos.
- Book delivery depends on valid Telegram document file IDs and the bot's ability to message the buyer.
- Deployment requires external configuration, database initialization, and webhook setup.

## Author

This project was first constructed with json files, then improved to sql.
Developed as a practical project exploring Telegram bot development, PHP backend logic, relational database design, and automated document delivery.

---

**Note:** This project is intended as a self-hosted application. Configure your own bot, database, hosting, and payment instructions before use.