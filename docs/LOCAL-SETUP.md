# Run ExamForge on your PC

This guide sets up ExamForge on one Windows PC. You do the setup once.
After that, you start it each day with a double-click.

The first setup takes about an hour. Most of that time is downloads.

Everything runs on your PC. With Ollama, your books never leave it.

---

## What you will install

| Program | Why ExamForge needs it |
|---|---|
| PHP 8.2 or newer (XAMPP) | Runs the API, the part that does the work |
| Composer | Downloads the API's packages |
| PostgreSQL 18 | Stores books, questions and papers |
| Memurai (Redis for Windows) | Holds the list of background jobs |
| Poppler (`pdftotext`) | Reads the text out of PDF books. **Required.** |
| Tesseract with Tamil | Reads scanned books. Optional. |
| Node.js 22 | Runs the web app you use in the browser |
| Ollama, **or** a Claude API key | The AI that writes the questions |

---

## 1. Install the programs (once)

Install them in this order. Use the default choices unless a step says otherwise.

### PHP (XAMPP)

1. Download XAMPP for Windows with PHP 8.2 from https://www.apachefriends.org.
2. Install it to `C:\xampp`. You only need PHP. Apache and MySQL are not used.
3. Add `C:\xampp\php` to your PATH:
   - Press the Windows key, type **environment**, open **Edit the system environment variables**.
   - Click **Environment Variables**. Under **User variables**, select **Path**, click **Edit**.
   - Click **New**, type `C:\xampp\php`, click **OK** three times.
4. Turn on the PostgreSQL driver:
   - Open `C:\xampp\php\php.ini` in Notepad.
   - Find the line `;extension=pdo_pgsql`. Delete the `;` at the start.
   - Do the same for `;extension=pgsql`.
   - Save the file.

### Composer

Download and run `Composer-Setup.exe` from https://getcomposer.org/download/.
When it asks for PHP, choose `C:\xampp\php\php.exe`.

### PostgreSQL 18

1. Download the installer from https://www.postgresql.org/download/windows/.
2. Keep port **5432**.
3. It asks for a password for the `postgres` user. Write it down.
4. You can skip Stack Builder at the end.

### Memurai (Redis for Windows)

Download Memurai Developer from https://www.memurai.com/get-memurai and install it.
It starts by itself with Windows.

Shortcut: `scripts\install-deps.ps1` installs Memurai **and** Tesseract with Tamil
in one go. Right-click PowerShell, choose **Run as administrator**, go to the
ExamForge folder, and run:

```
powershell -ExecutionPolicy Bypass -File scripts\install-deps.ps1
```

### Poppler (pdftotext)

1. Go to https://github.com/oschwartz10612/poppler-windows/releases.
2. Download the newest `Release-....zip`.
3. Unzip it. Inside is a folder such as `poppler-24.08.0`.
4. Move and rename that folder so that this file exists:
   `C:\poppler\Library\bin\pdftotext.exe`

You will point ExamForge at it in step 3.

### Tesseract with Tamil (optional, for scanned books)

Skip this if you ran `install-deps.ps1`.

1. Download the installer from https://github.com/UB-Mannheim/tesseract/wiki.
2. During setup, open **Additional language data** and tick **Tamil**.
3. Keep the folder `C:\Program Files\Tesseract-OCR`.

Without Tamil data, scanned Tamil pages come out as nonsense.

### Node.js 22

Download the **LTS** version from https://nodejs.org and install it.

### The AI: Ollama (free, on this PC)

1. Download Ollama for Windows from https://ollama.com/download and install it.
2. It runs in the background. You will see a llama icon near the clock.
3. Open a **new** PowerShell window and download the two models:

```
ollama pull gemma3:12b
ollama pull bge-m3
```

`gemma3:12b` is about 8 GB. `bge-m3` is about 1.2 GB. You only download them once.
If your PC is small, read [Which model for which PC](#which-model-for-which-pc) first.

### Or: a Claude API key (online, paid)

1. Create a key at https://console.anthropic.com (**API Keys**).
2. Keep it safe. It starts with `sk-ant-`.

Claude is faster and often better. But your book text is sent to Anthropic, and
each call costs money.

---

## 2. Create the database (once)

1. Open `apps\api\database\setup.sql` in Notepad.
2. Find `PASSWORD 'change-this-password'`. Replace `change-this-password` with a
   password of your own. Save. Write the password down.
3. Open PowerShell in the ExamForge folder and run:

```
& "C:\Program Files\PostgreSQL\18\bin\psql.exe" -U postgres -h 127.0.0.1 -f apps\api\database\setup.sql
```

4. Type the `postgres` password from the PostgreSQL install.

This creates the `examforge` user and database.
"pgvector" is not needed. ExamForge works without it.

---

## 3. Fill in the settings file (once)

1. Copy `apps\api\.env.example` to `apps\api\.env`.
2. Open `apps\api\.env` in Notepad.
3. Paste this block at the **very end** of the file. A setting that appears twice
   uses the **last** value, so the block wins over the lines above it.

```
APP_NAME=ExamForge
APP_URL=http://localhost:8000

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=examforge
DB_USERNAME=examforge
DB_PASSWORD=the-password-you-put-in-setup.sql

QUEUE_CONNECTION=redis
CACHE_STORE=redis
REDIS_CLIENT=predis

PDFTOTEXT_BIN="C:/poppler/Library/bin/pdftotext.exe"
PDFTOPPM_BIN="C:/poppler/Library/bin/pdftoppm.exe"
OCR_DRIVER=tesseract
TESSERACT_BIN="C:/Program Files/Tesseract-OCR/tesseract.exe"

AI_DRIVER=ollama
OLLAMA_BASE_URL=http://127.0.0.1:11434
OLLAMA_MODEL=gemma3:12b
EMBEDDING_DRIVER=ollama
EMBEDDING_MODEL=bge-m3
EMBEDDING_DIMENSIONS=1024
```

4. Change `DB_PASSWORD` to your password from step 2.
5. No Tesseract? Use `OCR_DRIVER=fake` instead.
6. Save the file.

Paths in this file use forward slashes `/`. Put paths with spaces in double quotes.

---

## 4. Install the app's packages (once)

Open PowerShell in the ExamForge folder and run these, one at a time:

```
cd apps\api
composer install
php artisan key:generate
cd ..\web
npm install
```

Each one takes a few minutes the first time.

---

## 5. First start

1. Open the `scripts` folder.
2. Double-click `start-local.bat`.

It checks your PC first. Every line says **OK**, **WARN**, **INFO** or **FAIL**.
Each FAIL comes with a **Fix:** that says exactly what to do. Fix those, then
double-click again.

When the checks pass, it:

- creates the database tables (only new changes are applied; nothing is deleted),
- adds the starting data (languages, exams, prompts) the first time only,
- opens three windows: **ExamForge API**, **ExamForge worker** and **ExamForge web**,
- waits until they answer,
- opens http://localhost:3000 in your browser.

The first start of the web app can take a few minutes. Later starts are quicker.

Sign in, or create an account on the **Register** page.

Want to check without starting anything? Open PowerShell in the ExamForge folder:

```
scripts\start-local.bat -CheckOnly
```

`-DryRun` shows every step it would take, and changes nothing.

---

## Daily use

- **Start:** double-click `scripts\start-local.bat`.
- **Work:** keep the three ExamForge windows open. You can minimise them.
  The **worker** window is where questions are written. If it is closed, nothing gets generated.
- **Stop:** double-click `scripts\stop-local.bat`.

`stop-local` only stops what `start-local` started. Other programs are left alone.

Double-clicking `start-local.bat` while ExamForge is already running just opens
the browser again.

If you stop in the middle of a long job, it continues after the next start.
It may take about 15 minutes to be picked up again.

After you change `apps\api\.env`, stop and start again. The settings are read at start.

---

## Opening from a phone or tablet

1. Connect the phone to the **same Wi-Fi** as the PC.
2. Start ExamForge on the PC. It prints a line like this:
   `Phone or tablet: http://192.168.1.23:3000`
3. Type that address into the phone's browser.

The first time, Windows may ask whether PHP and Node.js may use the network.
Tick **Private networks** and click **Allow**.

If the phone cannot open the page:

- Make sure the PC's Wi-Fi is a **Private** network:
  Settings > Network & internet > Wi-Fi > your network > **Private network**.
- Allow the programs through the firewall:
  Windows Security > Firewall & network protection > **Allow an app through firewall** >
  **Change settings**. Tick **Private** for **php** (or "CLI") and **Node.js JavaScript Runtime**.
- The phone needs both port **3000** (the page) and port **8000** (the data).
  To test the API from the phone, open `http://<PC address>:8000/up`.
- The PC's address can change after a restart of the router.
  `start-local` always prints the current one.
  If the address changed while ExamForge was running, stop and start it again.

---

## Switching between Ollama and Claude

Both are set in `apps\api\.env`. Change the line at the end of the file.

**To use Claude:**

```
AI_DRIVER=claude
ANTHROPIC_API_KEY=sk-ant-your-key
AI_DAILY_BUDGET_INR=2000
```

`AI_DAILY_BUDGET_INR` stops all AI calls for the day once that much is spent.
Embeddings can stay on Ollama (`EMBEDDING_DRIVER=ollama`). Then Ollama must still run.

**To go back to Ollama:**

```
AI_DRIVER=ollama
```

Then stop and start ExamForge.

`AI_DRIVER=fake` writes placeholder questions. It is only for testing.
`start-local` warns you when it is on.

---

## Which model for which PC

The model must fit in memory: your graphics card's memory (VRAM) is fastest,
normal memory (RAM) is slower. `start-local` does not test speed. Use this table as a guide.

| Your PC | Model to use | What to expect |
|---|---|---|
| NVIDIA graphics card with 12 GB or more | `gemma3:12b` (the default) | Good speed and quality |
| Graphics card with 8 GB, 16 GB RAM | `gemma3:12b` | Works; part runs on the processor, so slower |
| No graphics card, 32 GB RAM | `gemma3:12b` | Works, but a batch of questions can take many minutes |
| No graphics card, 16 GB RAM | `gemma3:4b` | Usable. Check Tamil questions carefully |
| 8 GB RAM | Use Claude instead | Local models will be too slow |
| Graphics card with 24 GB | `gemma3:27b` for writing | Best local quality |

To change the model, download it and set it in `apps\api\.env`:

```
ollama pull gemma3:4b
```
```
OLLAMA_MODEL=gemma3:4b
```

On a strong PC you can mix them. A big model writes the questions and a small one checks them:

```
OLLAMA_MODEL=gemma3:12b
OLLAMA_MODEL_PREMIUM=gemma3:27b
OLLAMA_MODEL_CHEAP=gemma3:4b
```

To see whether the model runs on the graphics card, run `ollama ps` while
questions are being written. The **PROCESSOR** column shows `100% GPU` when it does.

Smaller models make more mistakes, mostly in Tamil. ExamForge checks every question
and drops the ones that fail, so a weak model mostly gives you fewer questions.
Still, read them before you use them.

---

## Troubleshooting

Start with `scripts\start-local.bat -CheckOnly`. It names most problems and their fix.

**"Ollama - not reachable"**
Ollama is not running. Open the Start menu, type **Ollama**, press Enter.
Wait for the llama icon near the clock, then start again.
Not installed? See step 1. Still failing? Check `OLLAMA_BASE_URL` in `apps\api\.env`.
It should be `http://127.0.0.1:11434`.

**"Ollama models - not downloaded yet"**
Run the `ollama pull ...` lines it prints, in a new PowerShell window.
The name in `apps\api\.env` must match a name in `ollama list` exactly.

**"pdftotext - was not found"**
Install Poppler (step 1). Then make sure the `PDFTOTEXT_BIN` line in `apps\api\.env`
points at the real file, with forward slashes and double quotes.
If Poppler is already on the PC, the check prints the exact line to paste.

**"Port 8000 (or 3000) - already in use"**
Another program uses the port. The message names it.
If it is an old ExamForge window, double-click `stop-local.bat`, or close the window.
If you do not know the program, restart the PC.

**"Redis - nothing is listening"**
Memurai is stopped. Press Win+R, type `services.msc`, press Enter.
Find **Memurai**, right-click it, choose **Start**.
Or run without Redis. In `apps\api\.env` set:

```
QUEUE_CONNECTION=database
CACHE_STORE=database
```

**"PostgreSQL - nothing is listening"**
Press Win+R, type `services.msc`, press Enter. Find **postgresql-x64-18**,
right-click it, choose **Start**.

**"Database login - password authentication failed"**
`DB_PASSWORD` in `apps\api\.env` must be the password you put in `setup.sql`.

**"PHP extensions - missing: pdo_pgsql"**
Do step 1, "Turn on the PostgreSQL driver", then start again.

**Question generation is slow**
On a PC without a strong graphics card, this is normal.

- Run `ollama ps`. If it says `CPU`, the model is too big for the graphics card.
  Try `gemma3:4b` (see the table above).
- Plug a laptop in. On battery, Windows slows the processor down.
- Close games and heavy programs. They take memory the model needs.
- Ask for fewer questions at a time.
- Or switch to Claude for big batches.

**The API, worker or web window shows red errors**
Read the last lines. Then run `stop-local.bat`, fix the problem, and start again.
The **worker** window restarts itself if it stops. That is normal.

**The window closes before I can read it**
Use `start-local.bat`, not the `.ps1` file. The `.bat` waits when something fails.

---

## What the scripts do

| File | What it does |
|---|---|
| `scripts\start-local.bat` | Runs `start-local.ps1` and keeps the window open to read |
| `scripts\start-local.ps1` | Checks, migrates, seeds once, starts the three windows, saves their IDs |
| `scripts\stop-local.bat` | Runs `stop-local.ps1` |
| `scripts\stop-local.ps1` | Stops what `start-local` started, then deletes the ID file |
| `scripts\.local-pids.json` | Process IDs of the running windows. Created and deleted by the scripts |

Options for `start-local`: `-CheckOnly`, `-DryRun`, `-NoBrowser`, `-ProjectRoot <folder>`.
Options for `stop-local`: `-DryRun`, `-ProjectRoot <folder>`.

The three windows run:

```
php artisan serve --host=0.0.0.0 --port=8000
php artisan queue:work --queue=critical,ingest,ocr,structure,embed,generate,validate,translate,pdf,plan,predict,maintenance --timeout=900 --tries=3
npm run dev          (in apps\web, port 3000)
```
