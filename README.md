# Mistral Agent Block for Moodle (`block_mistralagent`)

**Version:** 3.1.2 (2026053000)  
**Requires Moodle:** 4.4+  
**License:** GNU GPL v3 or later

---

## What does this plugin do?

This block integrates a [Mistral AI](https://mistral.ai) conversational agent directly into a Moodle course. Students can chat with the agent from within the course page, and teachers can configure which agent to use, upload reference documents, and manage usage quotas.

Key features:

- **Multiple chatbot blocks per course** — each block is fully independent with its own agent, documents, quota and conversation history.
- **Dual API key mode** — teachers can use the administrator's shared Mistral agents, or enter their own personal Mistral API key and select from their own agents.
- **Zero Data Retention** — all API requests include the `Mistral-Retention: none` header, instructing Mistral not to retain inputs or outputs beyond the time needed to process each request.
- **RAG (Retrieval-Augmented Generation)** — teachers can upload PDF, DOCX, TXT or JSON documents. The plugin indexes them as vector embeddings and injects the most relevant passages into the agent's context automatically.
- **Mistral OCR** — for scanned or image-based PDFs, the teacher can enable OCR via Mistral's OCR API to extract text accurately.
- **Image generation** — if the Mistral agent has the `image_generation` connector enabled, generated images are displayed directly in the chat.
- **Usage quotas** — teachers can set a maximum number of messages per student per period (daily, weekly or monthly).
- **Conversation history** — students can start new conversations and switch between them. Teachers can view all conversations in the course.
- **Context presets** — three levels (`light`, `standard`, `full`) control how much document context and conversation history is sent to the API.

---

## Requirements

| Requirement | Details |
|---|---|
| Moodle | 4.4 or higher |
| PHP | 8.1 or higher |
| PHP extensions | `curl`, `json`, `mbstring`, `zip` |
| Mistral API key | Required — obtained from [console.mistral.ai](https://console.mistral.ai) |
| Mistral Agent | At least one agent created in the Mistral console |

> `shell_exec` / `pdftotext` are **not** required. PDF extraction uses the Mistral OCR API as primary method, with a pure-PHP fallback parser.

---

## Installation

### 1. Install the plugin

1. Download the ZIP file.
2. In Moodle, go to **Site administration → Plugins → Install plugins**.
3. Upload the ZIP and follow the on-screen instructions.
4. Moodle will run the database upgrade automatically.

Alternatively, unzip the archive into `blocks/mistralagent/` on your server and visit the notifications page (`/admin/index.php`).

### 2. Configure the global API key (administrator)

1. Go to **Site administration → Plugins → Blocks → Mistral Agent**.
2. Enter your **Mistral API key** (starts with `sk-...`).
3. Set the **default quota** and **quota period** (daily / weekly / monthly) if needed.
4. Set the **maximum context preset** allowed for teachers (`light`, `standard` or `full`).
5. Optionally adjust the **Mistral Models** section to change the OCR, embeddings or image generation model IDs without touching any code.
6. Save changes.

### 3. Create a Mistral Agent

1. Log in to [console.mistral.ai](https://console.mistral.ai).
2. Go to **Agents** and click **New agent**.
3. Give it a name, choose a model, and write a system prompt.
4. Optionally enable connectors such as `web_search` or `image_generation`.
   > ⚠️ Do **not** enable `web_search_premium` — it is not supported by the Conversations API and will cause errors.
5. Save and copy the **Agent ID** (format `ag:xxxxxxxx...`).

---

## Adding a chatbot block to a course

1. In a course, turn editing on.
2. Click **Add a block** and select **Mistral Agent**.
3. Click the block's gear icon → **Configure Mistral Agent block**.
4. Choose between **administrator agents** or **your own API key** (see below).
5. Optionally set a **context preset** for this block (`light`, `standard`, `full`).
6. Save. The chatbot is now active for students.

You can add **multiple Mistral Agent blocks** to the same course — each one is independent.

---

## Dual API key mode (v3)

Each block instance can be configured to use either the administrator's shared agents or a teacher's personal Mistral API key. This is configured in **Configure Mistral Agent block** via two tabs:

### Tab 1 — Administrator agents

Select one of the agents already registered by the site administrator. This is the default mode and requires no additional API key from the teacher.

### Tab 2 — Personal Mistral API key

Teachers who have their own Mistral account can:

1. Enter their personal Mistral API key (format `sk-...`).
2. Click **Fetch my agents** — the plugin queries the Mistral API in real time and lists the teacher's own agents.
3. Select an agent from the list and save.

When a personal key is configured:
- All API calls (chat, embeddings, OCR, image generation) use the teacher's key instead of the admin key.
- The key is **encrypted** (RC4 with the Moodle site secret) before being stored in the database.
- The key is never transmitted to the browser after saving — it is only used server-side.

To revert to the administrator's agents, simply select the **Administrator agents** tab and save a new agent selection.

> **Note:** Teachers are responsible for the usage and costs associated with their personal API key. The plugin stores the key encrypted, but the site administrator should be aware that personal keys are being used.

---

## Zero Data Retention (v3)

All requests sent to the Mistral API include the HTTP header:

```
Mistral-Retention: none
```

This header instructs Mistral not to retain inputs or outputs beyond the time strictly needed to generate the response. It applies to all API calls made by the plugin: chat messages, embeddings, OCR, image downloads, and agent listing.

This covers the following endpoints:
- `POST /v1/conversations` — chat messages
- `POST /v1/embeddings` — document indexing
- `POST /v1/ocr` — PDF OCR
- `GET /v1/files/{id}/content` — generated image download
- `GET /v1/agents` — teacher agent listing

> **Important:** The `Mistral-Retention: none` header is effective only if Zero Data Retention has been activated on the Mistral account. To request ZDR activation, contact Mistral support via the [Help Center](https://help.mistral.ai). Without ZDR activation, Mistral retains inputs and outputs for 30 rolling days for abuse monitoring, regardless of this header.

---

## Uploading reference documents (RAG)

Teachers can provide documents that the agent will use to answer questions.

1. In the block, click **Manage documents** (or the book icon).
2. Choose a source type:
   - **File** — upload a PDF, DOCX or TXT file.
   - **Text** — paste plain text directly.
   - **JSON** — upload a structured JSON or JSONL file.
   - **URL** — provide a web page URL or a YouTube video URL.
3. For PDF files, optionally check **Use Mistral OCR** to extract text via the Mistral OCR API (recommended for scanned PDFs or PDFs with encoding issues).
4. Click **Add**.

> **Copyright reminder:** Only upload documents for which you hold the rights or have an appropriate licence. Document text is sent to the Mistral API for embedding.

---

## How the RAG works

When a student sends a message:

1. The message is embedded using `mistral-embed`.
2. The plugin computes the **cosine similarity** (in PHP) between the message vector and every stored chunk vector.
3. The top N most relevant chunks (3, 5 or 8 depending on the preset) are prepended to the prompt as context.
4. The full prompt is sent to the Mistral Conversations API.

If embeddings are unavailable (API error or document too large), the plugin falls back to a simple keyword frequency score.

---

## Configurable model IDs

Since v3.1, all four Mistral models used internally by the plugin are configurable in the admin settings — no code change needed when Mistral deprecates a model.

**Site administration → Plugins → Blocks → Mistral Agent → Mistral Models**

| Setting | Default | Used for |
|---|---|---|
| OCR model | `mistral-ocr-latest` | PDF text extraction (chat attachments + RAG) via `/v1/ocr` |
| Embeddings model | `mistral-embed` | RAG document indexing via `/v1/embeddings` |
| Image generation model | `pixtral-1-25-01` | Image generation via `/v1/images/generations` |
| Vision model | `pixtral-12b-2409` | Analysis of images attached by students via `/v1/chat/completions` |

When Mistral sends a deprecation notice, simply update the model ID in this settings page and save — no deployment required.

---





If the Mistral agent has the `image_generation` connector enabled:

- When the agent decides to generate an image, the plugin automatically downloads it from the Mistral Files API and displays it inline in the chat.
- No special syntax is needed from the student — the agent decides when to generate an image based on the conversation.

---

## Context presets

| Preset | History sent | File attachment limit | RAG chunks |
|---|---|---|---|
| `light` | Last 10 messages / 10 000 chars | 50 000 chars | 3 |
| `standard` | Last 20 messages / 30 000 chars | 200 000 chars | 5 |
| `full` | Last 40 messages / 60 000 chars | 400 000 chars | 8 |

The site administrator sets the maximum preset allowed. Teachers can choose any preset up to that maximum for each block.

---

## RAG chunk limit

By default, the plugin sends a maximum of **50 chunks** per document to the Mistral embedding API (~30 pages of dense text). Beyond this limit, keyword-based search is used instead of semantic vector search.

Configurable at two levels:

**Global (administrator):** *Site administration → Plugins → Blocks → Mistral Agent → RAG indexing* → **Global chunk limit per document** (1–200, default 50).

**Per block (teacher):** In the block's edit form, under *RAG settings*, set the **Chunk limit for this block**. Enter `0` to use the global value, or a value between 1 and 200 to override.

> 200 chunks ≈ 120 pages of standard text. Increasing the limit increases API cost and indexing time.

---

## Quota management

- Teachers can set per-student message limits in the **Quotas** page.
- Quotas reset automatically at the start of each period (daily, weekly or monthly).
- A `NULL` limit means unlimited messages.

---

## Supported file formats (RAG documents)

| Format | Extraction method |
|---|---|
| `.txt` | Direct read |
| `.json` / `.jsonl` | `json_decode()` + recursive key-value flattening |
| `.docx` | ZipArchive → `word/document.xml` → `strip_tags()` |
| `.pdf` | Mistral OCR API (primary) → PHP stream parser (fallback) |
| URL | `curl` → `strip_tags()` |
| YouTube URL | Metadata / transcript extraction |

> `.pptx`, `.xlsx`, `.odt` and `.csv` are **not** supported. Convert content to JSON or plain text before uploading.

---

## File attachments in chat

Students can attach a file to any chat message. The attachment button is in the message input area, alongside a badge showing the active size limit for this block's preset.

**Accepted formats:** TXT, JSON, DOCX, PDF, JPG, PNG, GIF, WEBP

| Format | Processing |
|---|---|
| **TXT / JSON** | Read locally in the browser — no server call needed |
| **DOCX** | Uploaded to the server; text extracted via ZipArchive + `strip_tags()` |
| **PDF** | Uploaded to the server; text extracted via Mistral OCR API, then pure-PHP parser as fallback |
| **Images** | Uploaded to the server; analysed by `pixtral-12b-2409 (configurable)` via `/v1/chat/completions`; the description is injected as text into the conversation |

**Size limits by preset:**

| Preset | Limit |
|---|---|
| `light` | 50 KB of extracted text |
| `standard` | 200 KB of extracted text |
| `full` | 400 KB of extracted text |

The limit is shown dynamically next to the attachment button. Images are exempt from this limit.

Files are uploaded via `multipart/form-data` to `extract_file_ajax.php` and are **never stored** on the Moodle server — the PHP temp file is deleted immediately after extraction.

---

## Privacy

This plugin stores the following personal data:

- Conversation messages (question and answer text) per user per block instance.
- Message quota counters per user per block instance.
- Teacher personal Mistral API keys (encrypted, stored in `block_mistralagent_course`).

Document text and conversation messages are sent to the Mistral AI API (external service). The `Mistral-Retention: none` header is included in all requests. Please ensure your institution's data processing agreement covers this usage, and consider activating Zero Data Retention on your Mistral account.

The plugin implements the Moodle Privacy API (`classes/privacy/provider.php`).

---

## Architecture overview (for developers)

```
block_mistralagent/
├── block_mistralagent.php       Main block class — instance_allow_multiple() = true
├── chat.php                     Chat page
├── extract_file_ajax.php        Multipart file upload endpoint (PDF, DOCX, images)
├── configure.php                Agent selection (admin agents OR personal API key)
├── resources.php                RAG document management + copyright notice
├── history.php / quotas.php     Teacher views
├── amd/src/chat.js              AMD JavaScript — AJAX chat + file attachment via FormData
├── styles/chat.css              Chat UI styles (scoped, theme-aware)
├── styles.css                   Admin/teacher pages styles (scoped under #region-main)
├── templates/block.mustache     Block widget template (3-zone toolbar layout)
├── classes/
│   ├── manager.php              Conversations, quotas, API key resolution
│   ├── mistral_client.php       Mistral API — chat, vision (pixtral), image generation
│   ├── preset_manager.php       Context preset resolution
│   ├── resource_manager.php     Document upload, chunking, embeddings, RAG, OCR
│   └── external/
│       ├── send_message.php     Web service — send a chat message (with file/image support)
│       ├── extract_file.php     Web service — extract text from base64 file (legacy fallback)
│       ├── new_conversation.php Web service — start a new conversation
│       ├── get_conversation.php Web service — load conversation history
│       └── get_quota_status.php Web service — check remaining quota
└── db/
    ├── install.xml              Database schema
    ├── upgrade.php              Upgrade script
    ├── services.php             Web service declarations
    └── access.php               Capability definitions
```

**Key design decisions:**
- **v2:** All data isolation uses `blockinstanceid` as primary key, enabling multiple independent blocks per course.
- **v3:** `block_mistralagent_course` stores `custom_apikey` (encrypted), `custom_agent_id`, `custom_agent_name`, and `use_custom_key`. `manager::get_instance_apikey()` resolves the effective key transparently for all API calls.

---

## Changelog

### 3.1.2 (2026053000)
- **Authorship** — all file headers now correctly credit Miguël Dhyne <miguel.dhyne@gmail.com> as author and copyright holder.
- **Automatic PDF OCR** — PDF resources uploaded to the RAG are now systematically routed through the Mistral OCR API. The "Use OCR" checkbox is no longer required (kept readable for legacy resources).
- **Quieter logs** — verbose resource-processing debug messages are now gated behind a dedicated setting (`debug_resource_processing`), preventing pollution of Moodle's debug output when general developer debugging is on.
- **Conversation lookup** — `get_conversation` web service now correctly handles teachers' personal agents (no local agent record) by relying on the effective Mistral agent ID.
- Final pass of camelCase / line-length cleanup missed in 3.1.1.

### 3.1.1 (2026052901)
- **Full Moodle coding-style compliance** — the entire codebase now passes Moodle's code checker (PHPCS) cleanly: standardised boilerplate and file/class/function docblocks, camelCase variable names (no underscores), `else if` instead of `elseif`, line length under 132 (code) / 180 (lang), no trailing whitespace, alphabetically sorted language strings, and properly punctuated comments. No functional changes.
- Removed the temporary `test_extract.php` diagnostic file.

### 3.1.0 (2026052900)
- **Configurable model IDs** — OCR, embeddings and image generation models are now configurable in the admin settings page. No code change needed when Mistral deprecates a model.
- **File attachments in chat** — students can now attach TXT, JSON, DOCX, PDF and image files (JPG, PNG, GIF, WEBP) to any chat message.
  - PDF extraction uses Mistral OCR API as primary method; pure-PHP stream parser as fallback. `pdftotext` / `shell_exec` are no longer required.
  - Image files are analysed by `pixtral-12b-2409 (configurable)` via `/v1/chat/completions`; the description is injected as text into the conversation.
  - Upload via `multipart/form-data` (`extract_file_ajax.php`) — replaces the previous base64-in-AJAX approach which silently failed on large files.
  - Active size limit shown dynamically next to the attachment button.
- **UI/UX overhaul** — modern, theme-aware design (rounded corners, soft shadows, CSS custom properties). Block widget reorganised as a 3-zone toolbar. CSS fully scoped under `#region-main` to prevent interference with Moodle's navbar.
- **Bug fix** — `use function` declarations added to all namespaced PHP classes to prevent `Call to undefined function namespace\builtin()` errors on servers that disable `shell_exec`.

### 3.0.0 (2026042803)
- **Teacher personal API key** — teachers can enter their own Mistral API key and select from their own agents, independently of the administrator.
- **Zero Data Retention** — `Mistral-Retention: none` header added to all Mistral API calls.
- **Mistral OCR** — checkbox on PDF upload to use Mistral OCR API for scanned or encoded PDFs.
- **Copyright reminder** — displayed on the document management page.
- Bug fixes: history page errors (fullname fields, method rename), lang file syntax errors.

### 2.0.0 (2026042723)
- Multiple block instances per course (`blockinstanceid` isolation).
- Image generation support via Mistral Files API.
- Improved chat UI: left/right message bubbles, animated typing indicator.
- RAG chunk limit configurable globally and per block.
- New conversation button fixed.

### 1.x
- Single chatbot per course.
- RAG with PDF, DOCX, TXT, JSON, URL support.
- Usage quotas and conversation history.

---

## License

This plugin is licensed under the [GNU General Public License v3](https://www.gnu.org/licenses/gpl-3.0.html) or later.
