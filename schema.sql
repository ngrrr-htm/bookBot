-- MySQL schema (adjusted from SQL Server syntax to match shared-hosting MySQL/MariaDB)

CREATE TABLE Books (
    BookID VARCHAR(50) PRIMARY KEY,       -- e.g. "book1" — matches your existing callback_data style
    Title VARCHAR(200) NOT NULL,
    Description TEXT,
    Price VARCHAR(50),                    -- kept as text since you display "رایگان" / "150,000 تومان", not just numbers
    IsFree TINYINT(1) NOT NULL DEFAULT 0, -- 0 = paid, 1 = free
    FileID VARCHAR(300) NOT NULL          -- Telegram file_id for the PDF
);

CREATE TABLE Orders (
    OrderID INT AUTO_INCREMENT PRIMARY KEY,
    BookID VARCHAR(50) NOT NULL,
    BuyerChatID BIGINT NOT NULL,          -- Telegram chat id (can be large, BIGINT is safer than INT)
    BuyerUsername VARCHAR(100),
    BuyerFirstName VARCHAR(100),
    ReceiptFileID VARCHAR(300),           -- Telegram file_id of the receipt photo
    Status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    HandledBy VARCHAR(100) DEFAULT NULL,  -- admin's first name, filled in once handled
    CreatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    HandledAt DATETIME DEFAULT NULL,
    FOREIGN KEY (BookID) REFERENCES Books(BookID)
);

-- One row per admin per order, so we can edit EACH admin's own copy
-- of the receipt message when one of them approves/rejects it.
CREATE TABLE OrderAdminMessages (
    OrderID INT NOT NULL,
    AdminChatID BIGINT NOT NULL,
    MessageID BIGINT NOT NULL,
    PRIMARY KEY (OrderID, AdminChatID),
    FOREIGN KEY (OrderID) REFERENCES Orders(OrderID)
);

-- Replaces state.json: remembers which book a user is currently viewing,
-- so an incoming receipt photo can be matched to the right book.
CREATE TABLE UserState (
    ChatID BIGINT PRIMARY KEY,
    CurrentBookID VARCHAR(50),
    FOREIGN KEY (CurrentBookID) REFERENCES Books(BookID)
);
