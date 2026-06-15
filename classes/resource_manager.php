<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace block_mistralagent;

/**
 * Resource manager for RAG functionality.
 *
 * @package    block_mistralagent
 * @copyright  2026 Miguël Dhyne <miguel.dhyne@gmail.com>
 * @author     Miguël Dhyne <miguel.dhyne@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class resource_manager {

    /** @var int Maximum chunk size in characters (roughly 400-500 tokens) */
    const CHUNK_SIZE = 1500;

    /** @var int Overlap between chunks */
    const CHUNK_OVERLAP = 200;

    /** @var int Number of relevant chunks to retrieve */
    const TOP_K_CHUNKS = 5;


    /**
     * Log an internal diagnostic message only when plugin debugging is explicitly enabled.
     *
     * This avoids flooding Moodle pages with routine indexing messages when the site
     * has developer debugging enabled. Administrators can enable it manually with:
     * set_config('debug_resource_processing', 1, 'block_mistralagent').
     *
     * @param string $message Message to log.
     * @param int $level Moodle debug level.
     * @return void
     */
    private static function debug_message(string $message, int $level = DEBUG_DEVELOPER): void {
        if ((int)get_config('block_mistralagent', 'debug_resource_processing') === 1) {
            debugging($message, $level);
        }
    }

    /**
     * Clean text for database insertion.
     *
     * Public so that resources.php and other callers can use this single
     * implementation instead of maintaining their own copy.
     *
     * @param string $text
     * @return string
     */
    public static function clean_text_for_db(string $text): string {
        // Remove null bytes.
        $text = str_replace("\0", '', $text);

        // Remove problematic control characters (keep newlines and tabs).
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);

        // Convert to UTF-8 if needed.
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'auto');
        }

        // Remove invalid UTF-8 sequences.
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');

        // Remove 4-byte UTF-8 characters (emojis) that MySQL utf8 can't handle.
        $text = preg_replace('/[\x{10000}-\x{10FFFF}]/u', '', $text);

        return $text;
    }

    /**
     * Check whether extracted text is valid readable content.
     *
     * Used both when uploading resources (resources.php) and after processing
     * to validate that the extractor produced something usable rather than
     * raw binary garbage (e.g. non-decompressed PDF streams).
     *
     * Heuristics applied (in order):
     *  1. Minimum length — very short strings are never useful.
     *  2. PDF binary markers — known patterns that appear in raw PDF streams.
     *  3. Excessive hex sequences — sign of undecoded binary data.
     *  4. Printable-character ratio — binary data scores < 0.7.
     *  5. Word count — at least 5 recognisable words required.
     *
     * @param string $text Text to validate.
     * @return bool True if the text looks like readable content.
     */
    public static function is_valid_content(string $text): bool {
        if (empty($text) || strlen($text) < 20) {
            return false;
        }

        // PDF binary markers that appear in non-decoded streams.
        if (preg_match('/<<\/MCID|>>BDC|\/C2_|Tf \d|Td \[|TJ EMC|<[0-9A-F]{8,}>/', $text)) {
            return false;
        }

        // More than 5 hex sequences ≥ 4 chars → likely binary.
        if (preg_match_all('/<[0-9A-Fa-f]{4,}>/', $text, $matches) && count($matches[0]) > 5) {
            return false;
        }

        // Printable-character ratio must exceed 70 %.
        $printable = preg_match_all('/[\x20-\x7E\xA0-\xFF\n\r\t]/u', $text);
        $total     = mb_strlen($text);
        if ($total > 0 && ($printable / $total) < 0.7) {
            return false;
        }

        // At least 5 recognisable words.
        if (str_word_count($text) < 5) {
            return false;
        }

        return true;
    }

    /**
     * Get all resources for a given block instance.
     *
     * @param  int   $blockinstanceid  Block instance ID.
     * @return array                   Array of resource records ordered by creation date descending.
     */
    public static function get_instance_resources(int $blockinstanceid): array {
        global $DB;
        return $DB->get_records('block_mistralagent_resources', ['blockinstanceid' => $blockinstanceid], 'timecreated DESC');
    }

    /**
     * Get a single resource.
     *
     * @param int $id
     * @return \stdClass|null
     */
    public static function get_resource(int $id): ?\stdClass {
        global $DB;
        return $DB->get_record('block_mistralagent_resources', ['id' => $id]) ?: null;
    }

    /**
     * Add a new resource.
     *
     * @param int $blockinstanceid
     * @param int $courseid
     * @param string $type
     * @param string $name
     * @param string $source
     * @param int|null $filesize
     * @return int New resource ID.
     */
    public static function add_resource(int $blockinstanceid, int $courseid, string $type,
            string $name, string $source, ?int $filesize = null): int {
        global $DB, $USER;

        $record = new \stdClass();
        $record->blockinstanceid = $blockinstanceid;
        $record->courseid        = $courseid;
        $record->type        = $type;
        $record->name        = $name;
        $record->source      = $source;
        $record->status      = 'pending';
        $record->filesize    = $filesize;
        $record->timecreated = time();
        $record->timemodified = time();
        $record->createdby   = $USER->id;

        return $DB->insert_record('block_mistralagent_resources', $record);
    }

    /**
     * Delete a resource and its chunks.
     *
     * @param int $id
     * @return bool
     */
    public static function delete_resource(int $id): bool {
        global $DB;

        // Delete chunks first.
        $DB->delete_records('block_mistralagent_chunks', ['resourceid' => $id]);

        // Delete stored file if exists.
        $resource = self::get_resource($id);
        if ($resource && in_array($resource->type, ['pdf', 'docx', 'txt', 'json'])) {
            $fs      = get_file_storage();
            $context = \context_course::instance($resource->courseid);
            $files   = $fs->get_area_files($context->id, 'block_mistralagent', 'resource', $id);
            foreach ($files as $file) {
                $file->delete();
            }
        }

        return $DB->delete_records('block_mistralagent_resources', ['id' => $id]);
    }

    /**
     * Process and index a resource.
     *
     * @param int $resourceid
     * @param \stdClass|null $blockconfig
     * @return bool
     */
    public static function process_resource(int $resourceid, ?\stdClass $blockconfig = null): bool {
        global $DB;

        $resource = self::get_resource($resourceid);
        if (!$resource) {
            self::debug_message("MistralAgent: Resource not found: {$resourceid}", DEBUG_DEVELOPER);
            return false;
        }

        // Si $blockconfig non fourni, le charger depuis block_instances.
        if ($blockconfig === null && !empty($resource->blockinstanceid)) {
            $bi = $DB->get_record('block_instances', ['id' => $resource->blockinstanceid]);
            if ($bi && !empty($bi->configdata)) {
                $blockconfig = unserialize(base64_decode($bi->configdata));
            }
        }

        // Resolve the effective API key for this instance (personal or admin).
        $instanceapikey = !empty($resource->blockinstanceid)
            ? \block_mistralagent\manager::get_instance_apikey((int)$resource->blockinstanceid)
            : (string)(get_config('block_mistralagent', 'apikey') ?: '');

        self::debug_message("MistralAgent: Starting to process resource {$resourceid} ({$resource->name})", DEBUG_DEVELOPER);

        try {
            // Update status to processing.
            $DB->set_field('block_mistralagent_resources', 'status', 'processing', ['id' => $resourceid]);
            $DB->set_field('block_mistralagent_resources', 'error_message', null, ['id' => $resourceid]);

            /*
             * PDF resources are OCR-processed by default. The source marker is kept for compatibility with
             * older resources, but new PDF uploads no longer depend on a teacher checkbox.
             */
            $resource->use_ocr = ($resource->type === 'pdf')
                || (!empty($resource->source) && str_contains($resource->source, 'use_ocr:1'));
            $resource->mistralagent_apikey = $instanceapikey;

            // Extract content based on type.
            self::debug_message("MistralAgent: Extracting content for type: {$resource->type}", DEBUG_DEVELOPER);
            $content = self::extract_content($resource);

            if (empty($content)) {
                throw new \Exception('Aucun contenu extrait du fichier');
            }

            $contentlength = strlen($content);
            self::debug_message("MistralAgent: Extracted {$contentlength} characters", DEBUG_DEVELOPER);

            // Clean content for database.
            $content = self::clean_text_for_db($content);

            // For very large content, truncate to reasonable size for RAG.
            $maxcontent = 500000; // 500 KB max.
            if ($contentlength > $maxcontent) {
                self::debug_message(
                    "MistralAgent: Content too large ({$contentlength}), truncating to {$maxcontent}",
                    DEBUG_DEVELOPER);
                $content = substr($content, 0, $maxcontent) . "\n\n[... content truncated for RAG ...]";
            }

            // Save only a preview to the resources table; full content lives in chunks.
            $contentpreview = substr($content, 0, 10000);
            try {
                $DB->set_field('block_mistralagent_resources', 'content', $contentpreview, ['id' => $resourceid]);
            } catch (\Exception $e) {
                self::debug_message("MistralAgent: Could not save content preview: " . $e->getMessage(), DEBUG_DEVELOPER);
                // Continue — chunks are what matter for RAG.
            }

            // Split into chunks.
            $chunks     = self::split_into_chunks($content);
            $chunkcount = count($chunks);
            self::debug_message("MistralAgent: Split into {$chunkcount} chunks", DEBUG_DEVELOPER);

            if (empty($chunks)) {
                throw new \Exception('Cannot split content into chunks');
            }

            // Delete existing chunks.
            $DB->delete_records('block_mistralagent_chunks', ['resourceid' => $resourceid]);

            // Only create embeddings for small documents to limit API calls.
            $apikey               = $instanceapikey;
            /*
             * Configurable limit: global admin setting + per-block override. Priority: block config > admin
             * config > default value (50).
             */
            $globalmax    = (int)(get_config('block_mistralagent', 'max_embedding_chunks') ?: 50);
            $globalmax    = max(1, min(200, $globalmax)); // Borner entre 1 et 200.
            $instancemax  = isset($blockconfig->max_chunks) ? (int)$blockconfig->max_chunks : 0;
            $maxchunksforembeddings = ($instancemax > 0 && $instancemax <= 200)
                ? $instancemax
                : $globalmax;
            $createembeddings     = !empty($apikey) && $chunkcount <= $maxchunksforembeddings;

            if (!$createembeddings && $chunkcount > $maxchunksforembeddings) {
                self::debug_message(
                    "MistralAgent: Skipping embeddings for large document ({$chunkcount} chunks > {$maxchunksforembeddings})",
                    DEBUG_DEVELOPER
                );
            }

            $embeddingcount = 0;

            foreach ($chunks as $index => $chunktext) {
                $chunktext = self::clean_text_for_db($chunktext);
                $embedding = null;

                if ($createembeddings) {
                    try {
                        $embedding = self::get_embedding($chunktext, $apikey);
                        if ($embedding) {
                            $embeddingcount++;
                        }
                    } catch (\Exception $e) {
                        self::debug_message(
                            "MistralAgent: Embedding failed for chunk {$index}: " . $e->getMessage(),
                            DEBUG_DEVELOPER);
                    }
                }

                $chunk              = new \stdClass();
                $chunk->resourceid  = $resourceid;
                $chunk->chunk_index = $index;
                $chunk->chunk_text  = $chunktext;
                $chunk->embedding   = $embedding ? json_encode($embedding) : null;
                $chunk->token_count = self::estimate_tokens($chunktext);
                $chunk->timecreated = time();

                try {
                    $DB->insert_record('block_mistralagent_chunks', $chunk);
                } catch (\Exception $e) {
                    self::debug_message("MistralAgent: Failed to insert chunk {$index}: " . $e->getMessage(), DEBUG_DEVELOPER);
                    // Continue with remaining chunks.
                }
            }

            self::debug_message("MistralAgent: Created {$embeddingcount} embeddings out of {$chunkcount} chunks", DEBUG_DEVELOPER);

            $DB->set_field('block_mistralagent_resources', 'status', 'indexed', ['id' => $resourceid]);
            $DB->set_field('block_mistralagent_resources', 'timemodified', time(), ['id' => $resourceid]);

            self::debug_message("MistralAgent: Resource {$resourceid} indexed successfully", DEBUG_DEVELOPER);
            return true;

        } catch (\Exception $e) {
            $errormsg = $e->getMessage();
            self::debug_message("MistralAgent: Error processing resource {$resourceid}: {$errormsg}", DEBUG_DEVELOPER);

            $DB->set_field('block_mistralagent_resources', 'status', 'error', ['id' => $resourceid]);
            $DB->set_field('block_mistralagent_resources', 'error_message', $errormsg, ['id' => $resourceid]);
            $DB->set_field('block_mistralagent_resources', 'timemodified', time(), ['id' => $resourceid]);

            return false;
        }
    }

    /**
     * Extract content from a resource.
     *
     * @param \stdClass $resource
     * @return string
     */
    private static function extract_content(\stdClass $resource): string {
        switch ($resource->type) {
            case 'pdf':
                // The use_ocr flag is set by process_resource before calling extract_content.
                $useocr = !empty($resource->use_ocr);
                return self::extract_pdf($resource, $useocr);
            case 'docx':
                return self::extract_docx($resource);
            case 'txt':
            case 'json':
                return self::extract_text_file($resource);
            case 'url':
                return self::extract_webpage($resource->source);
            case 'youtube':
                return self::extract_youtube($resource->source);
            default:
                throw new \Exception('Unsupported resource type: ' . $resource->type);
        }
    }

    /**
     * Extract text from PDF using PHP.
     *
     * @param \stdClass $resource
     * @return string
     */
    // OCR via Mistral API.

    /**
     * Check if a PDF text extraction result is insufficient (scanned or protected PDF).
     *
     * @param  string $text Extracted text.
     * @return bool
     */
    private static function is_pdf_extraction_poor(string $text): bool {
        $trimmed = trim($text);
        if (strlen($trimmed) < 100) {
            return true;
        }
        // Heuristic: letter-to-non-space ratio < 40% → likely binary garbage.
        $nonspace = strlen(preg_replace('/\s/', '', $trimmed));
        if ($nonspace === 0) {
            return true;
        }
        preg_match_all('/[a-zA-ZÀ-ÿ]/', $trimmed, $letters);
        $letterratio = count($letters[0]) / $nonspace;
        // Ratio of '?' > 8% = garbage (incorrectly decoded characters).
        $qratio = substr_count($trimmed, '?') / max(1, strlen($trimmed));
        return $letterratio < 0.40 || $qratio > 0.08;
    }

    /**
     * Send a PDF to Mistral OCR API and return the extracted text.
     *
     * Uses the mistral-ocr-latest model via the /v1/ocr endpoint.
     * Falls back silently to empty string on error.
     *
     * @param  string $pdfcontent Raw binary PDF content.
     * @param  string $filename    Original filename (for logging).
     * @param  string $apikeyoverride Optional explicit API key override.
     * @return string              Extracted text, or empty string on failure.
     */
    private static function ocr_via_mistral(string $pdfcontent,
            string $filename = 'document.pdf', string $apikeyoverride = ''): string {
        $apikey = !empty($apikeyoverride) ? $apikeyoverride : get_config('block_mistralagent', 'apikey');
        if (empty($apikey)) {
            self::debug_message('MistralAgent OCR: missing API key', DEBUG_DEVELOPER);
            return '';
        }

        $sizemb = strlen($pdfcontent) / 1048576;
        if ($sizemb > 50) {
            self::debug_message("MistralAgent OCR: file too large ({$sizemb} MB) — skipped", DEBUG_DEVELOPER);
            return '';
        }

        $b64     = base64_encode($pdfcontent);
        $payload = json_encode([
            'model'    => get_config('block_mistralagent', 'model_ocr') ?: 'mistral-ocr-latest',
            'document' => [
                'type'          => 'document_url',
                'document_url'  => 'data:application/pdf;base64,' . $b64,
                'document_name' => $filename,
            ],
            'include_image_base64' => false,
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE);

        $curl = new \curl();
        $curl->setopt([
            'CURLOPT_TIMEOUT'        => 180,
            'CURLOPT_CONNECTTIMEOUT' => 20,
        ]);
        $curl->setHeader([
            'Authorization: Bearer ' . $apikey,
            'Content-Type: application/json',
            'Mistral-Retention: none',
        ]);

        $response = $curl->post('https://api.mistral.ai/v1/ocr', $payload);
        $info     = $curl->get_info();
        $httpcode = $info['http_code'] ?? 0;

        if ($httpcode !== 200) {
            self::debug_message("MistralAgent OCR: HTTP {$httpcode} — " . substr((string)$response, 0, 200), DEBUG_DEVELOPER);
            return '';
        }

        $data = json_decode((string)$response, true);
        if (!is_array($data)) {
            return '';
        }

        // Concatenate text from all pages.
        $text = '';
        foreach (($data['pages'] ?? []) as $page) {
            $text .= ($page['markdown'] ?? '') . "

";
        }

        $text = trim($text);
        self::debug_message(
            "MistralAgent OCR: success — " . strlen($text) . " characters extracted from {$filename}",
            DEBUG_DEVELOPER);
        return $text;
    }

    /**
     * Extract text from a PDF resource, optionally using OCR.
     *
     * @param \stdClass $resource
     * @param bool $useocr
     * @return string
     */
    private static function extract_pdf(\stdClass $resource, bool $useocr = false): string {
        $fs      = get_file_storage();
        $context = \context_course::instance($resource->courseid);
        $files   = $fs->get_area_files($context->id, 'block_mistralagent', 'resource', $resource->id, '', false);

        if (empty($files)) {
            throw new \Exception('PDF file not found in Moodle file storage');
        }

        $file     = reset($files);
        $content  = $file->get_content();
        $filename = $file->get_filename();

        self::debug_message("MistralAgent: PDF file size: " . strlen($content) . " bytes", DEBUG_DEVELOPER);

        $apikey = isset($resource->mistralagent_apikey) ? (string)$resource->mistralagent_apikey : '';

        // OCR is now the preferred path for PDFs. If OCR cannot be used or fails, fall back to native extraction.
        if ($useocr && $apikey !== '') {
            self::debug_message("MistralAgent: OCR Mistral enabled by default for {$filename}", DEBUG_DEVELOPER);
            $ocrtext = self::ocr_via_mistral($content, $filename, $apikey);
            if (!empty($ocrtext) && self::is_valid_content($ocrtext)) {
                self::debug_message("MistralAgent: OCR Mistral selected — " . strlen($ocrtext) . " chars", DEBUG_DEVELOPER);
                return $ocrtext;
            }
            self::debug_message(
                "MistralAgent: OCR unavailable or inconclusive, falling back to native extraction",
                DEBUG_DEVELOPER
            );
        }

        // Native extraction fallback.
        $text = self::extract_text_from_pdf_content($content);

        if (strlen(trim($text)) < 50) {
            self::debug_message(
                "MistralAgent: First extraction got only " . strlen($text) . " chars, trying alternative methods",
                DEBUG_DEVELOPER
            );
            $text2 = self::extract_pdf_alternative($content);
            if (strlen($text2) > strlen($text)) {
                $text = $text2;
            }
        }

        $nativepoor = self::is_pdf_extraction_poor($text);
        if (!$useocr && $nativepoor && $apikey !== '') {
            self::debug_message(
                "MistralAgent: OCR Mistral enabled: native text insufficient for {$filename}",
                DEBUG_DEVELOPER);
            $ocrtext = self::ocr_via_mistral($content, $filename, $apikey);
            if (!empty($ocrtext) && self::is_valid_content($ocrtext) && strlen($ocrtext) > strlen($text)) {
                self::debug_message("MistralAgent: OCR Mistral selected — " . strlen($ocrtext) . " chars", DEBUG_DEVELOPER);
                return $ocrtext;
            }
        }

        if (empty(trim($text)) || strlen(trim($text)) < 20) {
            throw new \Exception(get_string('err_pdf_extract_ocr', 'block_mistralagent'));
        }

        self::debug_message("MistralAgent: PDF extraction got " . strlen($text) . " characters", DEBUG_DEVELOPER);
        return $text;
    }

    /**
     * Alternative PDF text extraction using multiple methods.
     *
     * @param string $content Raw PDF binary.
     * @return string
     */
    private static function extract_pdf_alternative(string $content): string {
        $text = '';

        preg_match_all('/stream\s*\r?\n(.+?)\r?\n?\s*endstream/s', $content, $streammatches);

        foreach ($streammatches[1] as $stream) {
            $decoded = $stream;

            $decompressed = @gzuncompress($stream);
            if ($decompressed !== false) {
                $decoded = $decompressed;
            } else {
                $decompressed = @gzinflate($stream);
                if ($decompressed !== false) {
                    $decoded = $decompressed;
                }
            }

            if (preg_match_all('/BT\s*(.+?)\s*ET/s', $decoded, $btmatches)) {
                foreach ($btmatches[1] as $block) {
                    if (preg_match_all('/\[([^\]]+)\]\s*TJ/s', $block, $tjmatches)) {
                        foreach ($tjmatches[1] as $tj) {
                            if (preg_match_all('/\(([^)]*)\)/', $tj, $textmatches)) {
                                foreach ($textmatches[1] as $t) {
                                    $text .= self::decode_pdf_escape($t);
                                }
                            }
                        }
                    }

                    if (preg_match_all('/\(([^)]*)\)\s*Tj/s', $block, $tjmatches)) {
                        foreach ($tjmatches[1] as $t) {
                            $text .= self::decode_pdf_escape($t) . ' ';
                        }
                    }
                }
            }
        }

        // Fallback for uncompressed PDFs.
        if (strlen(trim($text)) < 50) {
            if (preg_match_all('/\(([^)]{3,})\)/', $content, $matches)) {
                foreach ($matches[1] as $match) {
                    $decoded = self::decode_pdf_escape($match);
                    if (preg_match('/^[\x20-\x7E\x80-\xFF\s]{3,}$/', $decoded)) {
                        $text .= $decoded . ' ';
                    }
                }
            }
        }

        return self::clean_extracted_text($text);
    }

    /**
     * Decode PDF escape sequences.
     *
     * @param string $str
     * @return string
     */
    private static function decode_pdf_escape(string $str): string {
        $str = preg_replace_callback('/\\\\([0-7]{1,3})/', function($m) {
            return chr(octdec($m[1]));
        }, $str);

        $str = str_replace(
            ['\\n', '\\r', '\\t', '\\b', '\\f', '\\(', '\\)', '\\\\'],
            ["\n",  "\r",  "\t",  "\b",  "\f",  '(',   ')',   '\\'],
            $str
        );

        return $str;
    }

    /**
     * Clean extracted text from PDF or web.
     *
     * @param string $text
     * @return string
     */
    private static function clean_extracted_text(string $text): string {
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text);

        /*
         * Encoding fix: if the text contains ? instead of accented characters, the PDF was encoded in
         * Latin-1 and was not converted to UTF-8.
         */
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'ISO-8859-1');
        } else {
            $qratio = substr_count($text, '?') / max(1, strlen($text));
            if ($qratio > 0.03) {
                $converted = @mb_convert_encoding($text, 'UTF-8', 'ISO-8859-1');
                if ($converted && mb_check_encoding($converted, 'UTF-8')
                    && substr_count($converted, '?') < substr_count($text, '?')) {
                    $text = $converted;
                }
            }
        }

        // Corriger les Mojibake courants (double-encodage UTF-8).
        $mojibakefrom = [
            'Ã©', 'Ã¨', 'Ãª', 'Ã ', 'Ã¹', 'Ã»', 'Ã®', 'Ã¯', 'Ã´', 'Ã§',
            'â€™', 'â€"', 'â€"', 'â€œ', 'â€', 'Â«', 'Â»', 'Â ', 'â€¦',
        ];
        $mojibaketo = [
            'é', 'è', 'ê', 'à', 'ù', 'û', 'î', 'ï', 'ô', 'ç',
            "'", '–', '—', '"', '"', '«', '»', ' ', '…',
        ];
        $text = str_replace($mojibakefrom, $mojibaketo, $text);

        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n\s*\n\s*\n+/', "\n\n", $text);
        return trim($text);
    }

    /**
     * Basic PDF text extraction.
     *
     * @param string $content Raw PDF binary.
     * @return string
     */
    private static function extract_text_from_pdf_content(string $content): string {
        $text = '';

        if (preg_match_all('/stream\s*(.*?)\s*endstream/s', $content, $matches)) {
            foreach ($matches[1] as $stream) {
                $decoded = @gzuncompress($stream);
                if ($decoded !== false) {
                    $stream = $decoded;
                }

                if (preg_match_all('/\[(.*?)\]\s*TJ/s', $stream, $tjmatches)) {
                    foreach ($tjmatches[1] as $tj) {
                        if (preg_match_all('/\((.*?)\)/', $tj, $textmatches)) {
                            $text .= implode('', $textmatches[1]);
                        }
                    }
                }
                if (preg_match_all('/\((.*?)\)\s*Tj/s', $stream, $tjmatches)) {
                    $text .= implode('', $tjmatches[1]);
                }
            }
        }

        $text = preg_replace('/[^\x20-\x7E\x0A\x0D\xC0-\xFF]/', ' ', $text);
        $text = preg_replace('/\s+/', ' ', $text);

        return trim($text);
    }

    /**
     * Extract text from DOCX.
     *
     * The temporary file is always deleted, even if an exception is thrown.
     *
     * @param \stdClass $resource
     * @return string
     */
    private static function extract_docx(\stdClass $resource): string {
        $fs      = get_file_storage();
        $context = \context_course::instance($resource->courseid);
        $files   = $fs->get_area_files($context->id, 'block_mistralagent', 'resource', $resource->id, '', false);

        if (empty($files)) {
            throw new \Exception('DOCX file not found');
        }

        $file     = reset($files);
        $tempfile = tempnam(sys_get_temp_dir(), 'docx_');
        $file->copy_content_to($tempfile);

        try {
            $text = '';
            $zip  = new \ZipArchive();

            if ($zip->open($tempfile) === true) {
                $xml = $zip->getFromName('word/document.xml');
                $zip->close();

                if ($xml) {
                    $text = strip_tags(str_replace('<', ' <', $xml));
                    $text = preg_replace('/\s+/', ' ', $text);
                    $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
                }
            }

            if (empty(trim($text))) {
                throw new \Exception('Could not extract text from DOCX');
            }

            return trim($text);

        } finally {
            // Always clean up the temp file, even on exception.
            if (file_exists($tempfile)) {
                unlink($tempfile);
            }
        }
    }

    /**
     * Extract text from TXT/JSON file.
     *
     * @param \stdClass $resource
     * @return string
     */
    private static function extract_text_file(\stdClass $resource): string {
        $fs      = get_file_storage();
        $context = \context_course::instance($resource->courseid);
        $files   = $fs->get_area_files($context->id, 'block_mistralagent', 'resource', $resource->id, '', false);

        if (empty($files)) {
            throw new \Exception('File not found');
        }

        $file    = reset($files);
        $content = $file->get_content();

        if ($resource->type === 'json') {
            $decoded = json_decode($content, true);
            if ($decoded !== null) {
                $content = self::extract_json_text($decoded);
            }
        }

        return $content;
    }

    /**
     * Extract text content from a JSON structure.
     *
     * Handles common patterns: {"content": "…"}, {"pages": […]}, {"sections": […]},
     * {"chapters": […]}, and falls back to recursively collecting all string values.
     *
     * @param mixed $data Decoded JSON value.
     * @return string
     */
    private static function extract_json_text($data): string {
        $texts = [];

        if (is_array($data)) {
            // Well-known root-level text keys.
            $textkeys = ['content', 'text', 'body', 'abstract', 'summary', 'description'];
            foreach ($textkeys as $key) {
                if (isset($data[$key]) && is_string($data[$key])) {
                    $texts[] = $data[$key];
                }
            }

            if (isset($data['title']) && is_string($data['title'])) {
                array_unshift($texts, '# ' . $data['title']);
            }

            // Pages array (common PDF-to-JSON format).
            if (isset($data['pages']) && is_array($data['pages'])) {
                foreach ($data['pages'] as $page) {
                    if (is_array($page)) {
                        $texts[] = $page['text'] ?? $page['content'] ?? '';
                    } else if (is_string($page)) {
                        $texts[] = $page;
                    }
                }
            }

            // Sections array.
            if (isset($data['sections']) && is_array($data['sections'])) {
                foreach ($data['sections'] as $section) {
                    if (!is_array($section)) {
                        continue;
                    }
                    if (!empty($section['heading'])) {
                        $texts[] = '## ' . $section['heading'];
                    }
                    if (!empty($section['title'])) {
                        $texts[] = '## ' . $section['title'];
                    }
                    $texts[] = $section['text'] ?? $section['content'] ?? '';
                }
            }

            // Chapters array.
            if (isset($data['chapters']) && is_array($data['chapters'])) {
                foreach ($data['chapters'] as $chapter) {
                    if (!is_array($chapter)) {
                        continue;
                    }
                    if (!empty($chapter['title'])) {
                        $texts[] = '## ' . $chapter['title'];
                    }
                    $texts[] = $chapter['text'] ?? $chapter['content'] ?? '';
                }
            }

            // Fallback: collect all string values recursively.
            if (empty(array_filter($texts))) {
                $texts = self::extract_all_strings($data);
            }

        } else if (is_string($data)) {
            $texts[] = $data;
        }

        $result = implode("\n\n", array_filter($texts));
        return trim(preg_replace('/\n{3,}/', "\n\n", $result));
    }

    /**
     * Recursively extract all string values from a nested array.
     * Used as a last-resort fallback when no known JSON structure is detected.
     *
     * @param mixed $data
     * @param int   $depth Current recursion depth (guards against infinite loops).
     * @return array
     */
    private static function extract_all_strings($data, int $depth = 0): array {
        $strings = [];

        if ($depth > 10) {
            return $strings;
        }

        if (is_array($data)) {
            $skipkeys = ['id', 'url', 'path', 'file', 'filename', 'source', 'source_file',
                         'hash', 'checksum', 'date', 'page', 'page_number', 'page_count'];

            foreach ($data as $key => $value) {
                if (is_string($value) && strlen($value) > 10 && !in_array(strtolower((string)$key), $skipkeys)) {
                    $strings[] = $value;
                } else if (is_array($value)) {
                    $strings = array_merge($strings, self::extract_all_strings($value, $depth + 1));
                }
            }
        }

        return $strings;
    }

    /**
     * Extract text from a webpage.
     *
     * Validates the URL scheme and blocks requests to private/loopback addresses
     * to prevent Server-Side Request Forgery (SSRF).
     *
     * @param string $url
     * @return string
     * @throws \Exception On invalid URL, blocked host, or fetch failure.
     */
    private static function extract_webpage(string $url): string {
        // Validate scheme: only http and https are allowed.
        $parsed = parse_url($url);
        if (!in_array($parsed['scheme'] ?? '', ['http', 'https'], true)) {
            throw new \Exception("Unsupported URL: only http and https are allowed");
        }

        // Block requests to loopback and RFC-1918 private ranges (SSRF protection).
        $host = $parsed['host'] ?? '';
        if (preg_match(
            '/^(localhost|127\.\d+\.\d+\.\d+|::1'
            . '|10\.\d+\.\d+\.\d+'
            . '|192\.168\.\d+\.\d+'
            . '|172\.(1[6-9]|2\d|3[01])\.\d+\.\d+)/i',
            $host
        )) {
            throw new \Exception("URL not allowed: local or private address rejected");
        }

        $curl = new \curl();
        $curl->setopt([
            'CURLOPT_FOLLOWLOCATION' => true,
            'CURLOPT_MAXREDIRS'      => 5,
            'CURLOPT_TIMEOUT'        => 30,
            'CURLOPT_USERAGENT'      => 'Mozilla/5.0 (compatible; MoodleBot/1.0)',
        ]);

        $html = $curl->get($url);

        if (empty($html)) {
            throw new \Exception('Could not fetch webpage');
        }

        // Strip non-content elements.
        $html = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $html);
        $html = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $html);
        $html = preg_replace('/<nav[^>]*>.*?<\/nav>/is', '', $html);
        $html = preg_replace('/<footer[^>]*>.*?<\/footer>/is', '', $html);
        $html = preg_replace('/<header[^>]*>.*?<\/header>/is', '', $html);

        $text = strip_tags(str_replace('<', ' <', $html));
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text);

        return trim($text);
    }

    /**
     * Extract subtitles from a YouTube video.
     *
     * @param string $url
     * @return string
     */
    private static function extract_youtube(string $url): string {
        $videoid = self::get_youtube_video_id($url);
        if (!$videoid) {
            throw new \Exception('Invalid YouTube URL');
        }

        $subtitles = self::fetch_youtube_subtitles($videoid);

        if (empty($subtitles)) {
            throw new \Exception('No subtitles available for this video');
        }

        return $subtitles;
    }

    /**
     * Extract YouTube video ID from URL.
     *
     * @param string $url
     * @return string|null
     */
    private static function get_youtube_video_id(string $url): ?string {
        $patterns = [
            '/youtube\.com\/watch\?v=([^&]+)/',
            '/youtu\.be\/([^?]+)/',
            '/youtube\.com\/embed\/([^?]+)/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $url, $matches)) {
                return $matches[1];
            }
        }

        return null;
    }

    /**
     * Fetch YouTube subtitles without an API key.
     *
     * @param string $videoid
     * @return string
     */
    private static function fetch_youtube_subtitles(string $videoid): string {
        $curl = new \curl();
        $curl->setopt([
            'CURLOPT_FOLLOWLOCATION' => true,
            'CURLOPT_USERAGENT'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        ]);

        // Try to extract a caption track URL from the video page.
        $html = $curl->get("https://www.youtube.com/watch?v={$videoid}");

        if (!empty($html) && preg_match('/"captions":\s*(\{[^}]+\})/s', $html)) {
            if (preg_match('/"baseUrl":\s*"([^"]+)"/', $html, $urlmatches)) {
                $captionurl = html_entity_decode(str_replace('\u0026', '&', $urlmatches[1]));
                $captionxml = $curl->get($captionurl);
                if (!empty($captionxml)) {
                    return self::parse_youtube_captions($captionxml);
                }
            }
        }

        // Fallback: timedtext API in French, English, then auto-generated French.
        $candidates = [
            "https://www.youtube.com/api/timedtext?v={$videoid}&lang=fr&fmt=srv3",
            "https://www.youtube.com/api/timedtext?v={$videoid}&lang=en&fmt=srv3",
            "https://www.youtube.com/api/timedtext?v={$videoid}&lang=fr&kind=asr&fmt=srv3",
        ];

        foreach ($candidates as $timedtexturl) {
            $captions = $curl->get($timedtexturl);
            if (!empty($captions) && strpos($captions, '<') === 0) {
                return self::parse_youtube_captions($captions);
            }
        }

        return '';
    }

    /**
     * Parse YouTube caption XML into plain text.
     *
     * @param string $xml
     * @return string
     */
    private static function parse_youtube_captions(string $xml): string {
        $text = '';

        if (preg_match_all('/<text[^>]*>([^<]*)<\/text>/i', $xml, $matches)) {
            foreach ($matches[1] as $line) {
                $text .= strip_tags(html_entity_decode($line, ENT_QUOTES, 'UTF-8')) . ' ';
            }
        }

        return trim(preg_replace('/\s+/', ' ', $text));
    }

    /**
     * Split text into chunks with overlap.
     *
     * Previously private with a public wrapper. Now directly public so callers
     * do not need the indirection of split_into_chunks_public().
     *
     * @param string $text
     * @return array
     */
    public static function split_into_chunks(string $text): array {
        $chunks = [];
        $text   = trim($text);

        if (empty($text)) {
            return $chunks;
        }

        $paragraphs = preg_split('/\n\s*\n/', $text);
        $currentchunk = '';

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if (empty($paragraph)) {
                continue;
            }

            if (strlen($currentchunk) + strlen($paragraph) > self::CHUNK_SIZE) {
                if (!empty($currentchunk)) {
                    $chunks[]     = trim($currentchunk);
                    $overlap      = substr($currentchunk, -self::CHUNK_OVERLAP);
                    $currentchunk = $overlap . ' ' . $paragraph;
                } else {
                    // Paragraph is itself too long — split by sentences.
                    $sentences = preg_split('/(?<=[.!?])\s+/', $paragraph);
                    foreach ($sentences as $sentence) {
                        if (strlen($currentchunk) + strlen($sentence) > self::CHUNK_SIZE) {
                            if (!empty($currentchunk)) {
                                $chunks[]     = trim($currentchunk);
                                $overlap      = substr($currentchunk, -self::CHUNK_OVERLAP);
                                $currentchunk = $overlap . ' ' . $sentence;
                            } else {
                                // Single sentence is too long — force-split.
                                $chunks[]     = substr($sentence, 0, self::CHUNK_SIZE);
                                $currentchunk = substr($sentence, self::CHUNK_SIZE - self::CHUNK_OVERLAP);
                            }
                        } else {
                            $currentchunk .= ' ' . $sentence;
                        }
                    }
                }
            } else {
                $currentchunk .= "\n\n" . $paragraph;
            }
        }

        if (!empty(trim($currentchunk))) {
            $chunks[] = trim($currentchunk);
        }

        return $chunks;
    }

    /**
     * Get an embedding vector from the Mistral API.
     *
     * Previously private with a public wrapper. Now directly public.
     * The API key is sanitised before being placed in the Authorization header.
     *
     * @param string $text
     * @param string $apikey
     * @return array|null Embedding vector, or null on failure.
     */
    public static function get_embedding(string $text, string $apikey): ?array {
        // Sanitise to prevent HTTP header injection.
        $apikey = trim(str_replace(["\r", "\n"], '', $apikey));

        $curl = new \curl();
        $curl->setHeader([
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apikey,
            'Mistral-Retention: none',
        ]);

        $response = $curl->post(
            'https://api.mistral.ai/v1/embeddings',
            json_encode([
                'model' => get_config('block_mistralagent', 'model_embed') ?: 'mistral-embed',
                'input' => [$text],
            ])
        );

        $data = json_decode($response, true);

        return $data['data'][0]['embedding'] ?? null;
    }

    /**
     * Estimate token count (rough approximation: ~4 characters per token).
     *
     * @param string $text
     * @return int
     */
    private static function estimate_tokens(string $text): int {
        return (int)ceil(strlen($text) / 4);
    }

    /**
     * Search for relevant chunks using embeddings or keyword fallback.
     *
     * @param  int    $blockinstanceid  Block instance ID used to scope resources.
     * @param  string $query            User query to match against indexed chunks.
     * @param  int    $topk             Maximum number of chunks to return (default: TOP_K_CHUNKS).
     * @return array                    Array of matching chunk records, most relevant first.
     */
    public static function search_relevant_chunks(int $blockinstanceid, string $query, int $topk = self::TOP_K_CHUNKS): array {
        global $DB;

        $apikey    = \block_mistralagent\manager::get_instance_apikey($blockinstanceid);
        $resources = $DB->get_records('block_mistralagent_resources', [
            'blockinstanceid' => $blockinstanceid,
            'status'   => 'indexed',
        ]);

        if (empty($resources)) {
            return [];
        }

        $resourceids      = array_keys($resources);
        list($insql, $params) = $DB->get_in_or_equal($resourceids, SQL_PARAMS_NAMED);

        $chunks = $DB->get_records_select('block_mistralagent_chunks', "resourceid {$insql}", $params);

        if (empty($chunks)) {
            return [];
        }

        $queryembedding = null;
        if (!empty($apikey)) {
            $queryembedding = self::get_embedding($query, $apikey);
        }

        $scored = [];
        foreach ($chunks as $chunk) {
            $score = 0;

            if ($queryembedding && !empty($chunk->embedding)) {
                $chunkembedding = json_decode($chunk->embedding, true);
                if ($chunkembedding) {
                    $score = self::cosine_similarity($queryembedding, $chunkembedding);
                }
            } else {
                $score = self::keyword_score($query, $chunk->chunk_text);
            }

            $chunk->score         = $score;
            $chunk->resource_name = $resources[$chunk->resourceid]->name ?? 'Unknown';
            $scored[]             = $chunk;
        }

        usort($scored, fn($a, $b) => $b->score <=> $a->score);

        return array_slice($scored, 0, $topk);
    }

    /**
     * Calculate cosine similarity between two vectors.
     *
     * @param array $a
     * @param array $b
     * @return float
     */
    private static function cosine_similarity(array $a, array $b): float {
        $dot   = 0.0;
        $norma = 0.0;
        $normb = 0.0;

        $len = count($a);
        for ($i = 0; $i < $len; $i++) {
            $dot   += $a[$i] * $b[$i];
            $norma += $a[$i] * $a[$i];
            $normb += $b[$i] * $b[$i];
        }

        $norma = sqrt($norma);
        $normb = sqrt($normb);

        if ($norma == 0.0 || $normb == 0.0) {
            return 0.0;
        }

        return $dot / ($norma * $normb);
    }

    /**
     * Simple keyword-based scoring fallback when no embeddings are available.
     *
     * @param string $query
     * @param string $text
     * @return float
     */
    private static function keyword_score(string $query, string $text): float {
        $query = mb_strtolower($query);
        $text  = mb_strtolower($text);

        $querywords = array_filter(
            preg_split('/\s+/', $query),
            fn($w) => strlen($w) > 2
        );

        if (empty($querywords)) {
            return 0.0;
        }

        $textlen = strlen($text);
        if ($textlen === 0) {
            return 0.0;
        }

        $score = 0;
        foreach ($querywords as $word) {
            $score += substr_count($text, $word);
        }

        return $score / (max($textlen, 100) / 1000);
    }

    /**
     * Build context string from relevant chunks for injection into the prompt.
     *
     * @param array $chunks
     * @return string
     */
    public static function build_context(array $chunks): string {
        if (empty($chunks)) {
            return '';
        }

        $context = "Voici des informations pertinentes issues des documents du cours :\n\n";

        foreach ($chunks as $chunk) {
            $context .= "--- Document: {$chunk->resource_name} ---\n";
            $context .= $chunk->chunk_text . "\n\n";
        }

        $context .= "---\n\n" . get_string('rag_context_footer', 'block_mistralagent');

        return $context;
    }
}
