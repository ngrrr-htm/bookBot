from telegram import Update
from telegram.ext import ApplicationBuilder, CommandHandler, ContextTypes
import asyncio
import os

TOKEN = os.getenv("BOT_TOKEN")
async def start(update:Update, context:ContextTypes.DEFAULT_TYPE):

    await update.message.reply_text(
                """📚

سلام!

این کتاب کاملاً رایگان است.

اگر دوست داشتید از ترجمه‌های بعدی حمایت کنید، می‌توانید دونیت کنید ❤️

شماره کارت:
6037....

فایل تا چند ثانیه دیگر ارسال می‌شود...
"""
    )
    await asyncio.sleep(5)
    await update.message.reply_document(
        document=open("Ss 13.pdf","rb")
    )

app = ApplicationBuilder().token(TOKEN).build()
app.add_handler(CommandHandler("start", start))
app.run_polling()
# import requests

# print(requests.get("https://api.telegram.org").status_code)