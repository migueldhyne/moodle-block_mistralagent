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

namespace block_mistralagent\external;

/*
 * Explicitly declare all native PHP functions used in this namespace to avoid
 * resolution ambiguities within this namespace.
 */
use function tempnam;
use function sys_get_temp_dir;
use function file_put_contents;
use function file_exists;
use function unlink;
use function base64_encode;
use function base64_decode;
use function json_encode;
use function json_decode;
use function is_array;
use function strlen;
use function substr;
use function str_replace;
use function str_starts_with;
use function strip_tags;
use function html_entity_decode;
use function trim;
use function implode;
use function explode;
use function in_array;
use function strtolower;
use function preg_match_all;
use function preg_replace;
use function preg_replace_callback;
use function preg_match;
use function round;
use function chr;
use function ord;
use function octdec;
use function hexdec;
use function mb_chr;
use function mb_ord;
use function mb_strlen;
use function mb_substr;
use function gzuncompress;
use function gzinflate;
use function curl_init;
use function curl_setopt_array;
use function curl_exec;
use function curl_getinfo;
use function curl_close;
use function get_config;
use function pathinfo;
use function array_filter;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Extract file content external function.
 *
 * Accepts a base64-encoded file uploaded by the student, extracts its plain
 * text, and returns it so that send_message.php can embed it in the API call.
 *
 * Supported types : txt, json, docx, pdf.
 *
 * Security model
 * ──────────────
 * The extracted text is never executed, never stored as a file, and never
 * served back as HTML — it is only embedded as plain text inside the prompt
 * sent to the Mistral API.  Consequently the main attack surfaces are:
 *
 *  • Zip-bomb via a crafted PDF stream → mitigated by MAX_DECOMPRESSED_BYTES.
 *  • Wrong type declared by client     → mitigated by magic-byte checks on
 *                                        binary types (DOCX, PDF).
 *  • XXE / billion-laughs in DOCX XML  → mitigated by strip_tags() (no XML
 *                                        parser is used, the XML is treated as
 *                                        a plain string).
 *
 * The truncation to EXTRACTED_CHARS_LIMIT is a first-pass safety net.  The
 * definitive limit is enforced later in send_message.php against the preset
 * configured for the course.
 *
 * @package    block_mistralagent
 * @copyright  2026 Miguël Dhyne <miguel.dhyne@gmail.com>
 * @author     Miguël Dhyne <miguel.dhyne@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class extract_file extends external_api {
    /**
     * Hard cap on the plain-text returned by this function.
     * send_message.php applies a second, per-course cap based on the preset.
     */
    private const EXTRACTED_CHARS_LIMIT = 0; // The real limit is enforced in send_message.php (preset).

    /**
     * Maximum number of bytes a single decompressed PDF stream may produce.
     * Prevents zip-bomb attacks embedded in PDF FlateDecode streams.
     * 10 MB is well above what any legitimate text-only stream would need.
     */
    private const MAX_DECOMPRESSED_BYTES = 10 * 1024 * 1024; // 10 MB.

    /**
     * Magic bytes used to verify binary file types.
     * Checked against the first bytes of the decoded binary payload.
     */
    private const MAGIC = [
        // PDF: "%PDF".
        'pdf'  => "\x25\x50\x44\x46",
        // DOCX (ZIP): local file header "PK\x03\x04".
        'docx' => "\x50\x4B\x03\x04",
    ];

    // Section separator.
    // External API.
    // Section separator.

    /**
     * Parameters definition.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'filename'    => new external_value(PARAM_FILE,  'File name'),
            'filecontent' => new external_value(PARAM_RAW, 'Base64-encoded file content'),
            'filetype'    => new external_value(PARAM_ALPHA, 'File type (pdf, docx, txt, json, jpg, jpeg, png, gif, webp)'),
        ]);
    }

    /**
     * Execute the extraction.
     *
     * @param string $filename
     * @param string $filecontent  Base64-encoded binary (may include a data-URL prefix).
     * @param string $filetype
     * @return array
     */
    public static function execute(string $filename, string $filecontent, string $filetype): array {
        // Normalise type before validation so PARAM_ALPHANUMEXT sees clean input.
        $filetype = strtolower(trim($filetype));

        $params = self::validate_parameters(self::execute_parameters(), [
            'filename'    => $filename,
            'filecontent' => $filecontent,
            'filetype'    => $filetype,
        ]);

        $filename    = $params['filename'];
        $filecontent = $params['filecontent'];
        $filetype    = $params['filetype'];

        // Explicit allowlist — reject any unsupported file type.
        $allowed = ['txt', 'json', 'docx', 'pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp'];
        if (!in_array($filetype, $allowed, true)) {
            return [
                'success'   => false,
                'text'      => '',
                'truncated' => false,
                'canpaste'  => false,
                'error'     => get_string('err_file_type', 'block_mistralagent', $filetype),
            ];
        }

        try {
            // Strip data-URL prefix when present ("data:…;base64,<data>").
            if (strpos($filecontent, 'base64,') !== false) {
                $filecontent = explode('base64,', $filecontent)[1];
            }

            /*
             * Images: keep the raw base64 — it will be injected as image_url in send_message.php.
             * Pas d'extraction de texte ici.
             */
            $imagetypes = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            if (in_array($filetype, $imagetypes, true)) {
                // Rebuild the full data-URI if the prefix was stripped.
                $mimemap = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
                            'png' => 'image/png',  'gif'  => 'image/gif',
                            'webp' => 'image/webp'];
                $mime    = $mimemap[$filetype] ?? 'image/jpeg';
                // Stocker le base64 pur pour que send_message.php le passe en image_url.
                return [
                    'success'   => true,
                    'text'      => '__IMAGE_BASE64__' . $mime . ';base64,' . $filecontent,
                    'truncated' => false,
                    'canpaste'  => false,
                    'error'     => '',
                ];
            }

            $binarydata = base64_decode($filecontent, true);
            if ($binarydata === false) {
                throw new \Exception(get_string('err_file_corrupt', 'block_mistralagent'));
            }

            // Verify magic bytes for binary types before doing any heavier work.
            self::verify_magic_bytes($filetype, $binarydata);

            $extractedtext = match ($filetype) {
                'txt'   => $binarydata,
                'json'  => self::extract_json($binarydata),
                'docx'  => self::extract_docx($binarydata),
                'pdf'   => self::extract_pdf($binarydata),
                default => throw new \Exception(get_string('err_file_type', 'block_mistralagent', $filetype)),
            };

            $extractedtext = trim($extractedtext);
            $truncated     = false;

            // Soft cap: if set and exceeded, truncate and notify the JS layer.
            if (self::EXTRACTED_CHARS_LIMIT > 0 && strlen($extractedtext) > self::EXTRACTED_CHARS_LIMIT) {
                $extractedtext = substr($extractedtext, 0, self::EXTRACTED_CHARS_LIMIT)
                    . "\n\n" . get_string('file_truncated_marker', 'block_mistralagent',
                        round(self::EXTRACTED_CHARS_LIMIT / 1000));
                $truncated = true;
            }

            return [
                'success'   => true,
                'text'      => $extractedtext,   // The JS reads resp.text.
                'truncated' => $truncated,
                'canpaste'  => false,
                'error'     => '',
            ];

        } catch (\Exception $e) {
            debugging('block_mistralagent extract_file error [' . $filetype . ']: '
                . $e->getMessage() . "\n" . $e->getTraceAsString(), DEBUG_DEVELOPER);
            return [
                'success'   => false,
                'text'      => '',
                'truncated' => false,
                'canpaste'  => false,
                'error'     => $e->getMessage(),
            ];
        }
    }

    /**
     * Return definition.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success'   => new external_value(PARAM_BOOL, 'Success status'),
            'text'      => new external_value(PARAM_RAW, 'Extracted text content'),
            'truncated' => new external_value(PARAM_BOOL, 'Whether text was truncated', VALUE_DEFAULT, false),
            'canpaste'  => new external_value(PARAM_BOOL, 'Whether paste modal should be offered', VALUE_DEFAULT, false),
            'error'     => new external_value(PARAM_TEXT, 'Error message if any'),
        ]);
    }

    // Section separator.
    // Magic-byte verification.
    // Section separator.

    /**
     * Verify that the binary payload starts with the expected magic bytes.
     *
     * Only applied to binary types (pdf, docx).  txt and json are plain text
     * and have no reliable binary signature.
     *
     * @param string $filetype
     * @param string $binarydata
     * @throws \Exception If the magic bytes do not match.
     */
    private static function verify_magic_bytes(string $filetype, string $binarydata): void {
        if (!isset(self::MAGIC[$filetype])) {
            return; // Txt and json files do not need a magic-bytes check.
        }

        $magic  = self::MAGIC[$filetype];
        $prefix = substr($binarydata, 0, strlen($magic));

        if ($prefix !== $magic) {
            throw new \moodle_exception(
                'err_file_magic',
                'block_mistralagent',
                '',
                $filetype
            );
        }
    }

    // Public wrappers (used by extract_file_ajax.php).

    /**
     * Public wrapper to extract text from JSON content.
     *
     * @param string $data
     * @return string
     */
    public static function extract_json_public(string $data): string {
        return self::extract_json($data);
    }

    /**
     * Public wrapper to extract text from DOCX content.
     *
     * @param string $data
     * @return string
     */
    public static function extract_docx_public(string $data): string {
        return self::extract_docx($data);
    }

    /**
     * Public wrapper to extract text from PDF content.
     *
     * @param string $data
     * @return string
     */
    public static function extract_pdf_public(string $data): string {
        return self::extract_pdf($data);
    }

    // Per-type extractors.

    /**
     * Extract readable text from a JSON binary payload.
     *
     * @param string $data Raw binary (UTF-8 JSON text).
     * @return string
     */
    private static function extract_json(string $data): string {
        $decoded = json_decode($data, true);
        if ($decoded !== null) {
            return self::flatten_json($decoded);
        }
        // Not valid JSON — return as-is (might be JSONL or similar).
        return $data;
    }

    /**
     * Extract text from DOCX binary data.
     *
     * A DOCX file is a ZIP archive containing word/document.xml.  We open it
     * with ZipArchive, read the XML, strip all tags, and clean up whitespace.
     * No XML parser is involved — the XML is treated as a plain string — so
     * XXE and billion-laughs attacks are not applicable.
     *
     * The temporary file is always deleted via a finally block.
     *
     * @param string $data Raw DOCX binary.
     * @return string
     * @throws \Exception On extraction failure.
     */
    private static function extract_docx(string $data): string {
        $tempfile = tempnam(sys_get_temp_dir(), 'moodle_docx_');
        file_put_contents($tempfile, $data);

        try {
            $zip = new \ZipArchive();
            if ($zip->open($tempfile) !== true) {
                throw new \Exception(get_string('err_docx_zip', 'block_mistralagent'));
            }

            $xml = $zip->getFromName('word/document.xml');
            $zip->close();

            if ($xml === false) {
                throw new \Exception(get_string('err_docx_xml', 'block_mistralagent'));
            }

            // Map XML structural elements to whitespace before stripping tags.
            $xml  = str_replace('</w:p>',  "\n", $xml);
            $xml  = str_replace('</w:tr>', "\n", $xml);
            $xml  = str_replace('<w:tab/>', "\t", $xml);
            $xml  = str_replace('<w:br/>',  "\n", $xml);
            $text = strip_tags($xml);
            $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
            $text = preg_replace('/[ \t]+/', ' ', $text);
            $text = preg_replace('/\n\s*\n/', "\n\n", $text);
            $text = trim($text);

            if (empty($text)) {
                throw new \Exception(get_string('err_docx_empty', 'block_mistralagent'));
            }

            return $text;

        } finally {
            // Always remove the temp file, even if an exception was thrown.
            if (file_exists($tempfile)) {
                unlink($tempfile);
            }
        }
    }

    /**
     * Extract text from PDF binary data.
     *
     * Strategy (in order of preference):
     *  1. Mistral OCR API — reliable on all PDF types including scanned documents.
     *  2. Built-in parser — fallback with no external dependencies.
     *
     * Limitations du parseur maison :
     *  • Does not handle encrypted PDFs.
     *  • Does not handle scanned PDFs (image without a text layer).
     *  • May miss text using CID or Type 3 fonts.
     *
     * @param string $data Raw PDF binary.
     * @return string
     * @throws \Exception If no text could be extracted.
     */
    private static function extract_pdf(string $data): string {
        // Attempt 1: Mistral OCR API — reliable on all PDF types including scanned documents.
        $ocrtext = self::extract_pdf_via_mistral_ocr($data);
        if ($ocrtext !== null) {
            return $ocrtext;
        }

        // Attempt 2: built-in parser — last resort, no external dependency.
        $text = '';

        $streamtext = self::extract_pdf_streams($data);
        if (strlen(trim($streamtext)) >= 50) {
            $text = $streamtext;
        }

        if (strlen(trim($text)) < 50) {
            $unicodetext = self::extract_pdf_unicode($data);
            if (strlen($unicodetext) > strlen($text)) {
                $text = $unicodetext;
            }
        }

        if (strlen(trim($text)) < 50) {
            $btext = self::extract_pdf_bt_et($data);
            if (strlen($btext) > strlen($text)) {
                $text = $btext;
            }
        }

        $text = self::clean_pdf_text($text);

        if (strlen(trim($text)) < 20) {
            throw new \Exception(get_string('err_pdf_extract', 'block_mistralagent'));
        }

        return $text;
    }

    /**
     * Extract PDF text via the Mistral OCR API.
     *
     * Uses the model configured in block settings (model_ocr).
     * Returns null if the API key is not configured or the call fails,
     * so the caller can fall back to the homemade parser.
     *
     * @param string $data Raw PDF binary.
     * @return string|null
     */
    private static function extract_pdf_via_mistral_ocr(string $data): ?string {
        $apikey = get_config('block_mistralagent', 'apikey');
        if (empty($apikey)) {
            return null;
        }

        $model = get_config('block_mistralagent', 'model_ocr') ?: 'mistral-ocr-latest';
        $b64   = base64_encode($data);

        $payload = json_encode([
            'model'    => $model,
            'document' => [
                'type'          => 'document_url',
                'document_url'  => 'data:application/pdf;base64,' . $b64,
            ],
        ]);

        $ch = curl_init('https://api.mistral.ai/v1/ocr');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $apikey,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT        => 60,
        ]);

        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpcode !== 200) {
            return null; // Let the homemade parser take over.
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            return null;
        }

        // L'API OCR retourne pages[].markdown.
        $text = '';
        foreach ($decoded['pages'] ?? [] as $page) {
            $text .= ($page['markdown'] ?? '') . "\n\n";
        }

        $text = trim($text);
        return strlen($text) >= 20 ? $text : null;
    }

    // Section separator.
    // PDF — homemade parser (fallback).
    // Section separator.

    /**
     * Extract text from PDF FlateDecode streams.
     *
     * Each compressed stream is decompressed with a hard size cap
     * (MAX_DECOMPRESSED_BYTES) to prevent zip-bomb attacks.
     *
     * @param string $data Raw PDF binary.
     * @return string
     */
    private static function extract_pdf_streams(string $data): string {
        $text = '';

        preg_match_all('/stream\s*\r?\n(.+?)\r?\n?\s*endstream/s', $data, $streammatches);

        foreach ($streammatches[1] as $stream) {
            $decoded = self::safe_decompress($stream);

            $streamtext = self::extract_text_from_stream($decoded);
            $text      .= $streamtext . ' ';
        }

        return $text;
    }

    /**
     * Decompress a raw stream with a hard output-size cap.
     *
     * Tries gzuncompress then gzinflate.  Returns the original stream if
     * decompression fails (the stream may be uncompressed).
     *
     * @param string $stream Compressed (or plain) stream bytes.
     * @return string
     */
    private static function safe_decompress(string $stream): string {
        $limit = self::MAX_DECOMPRESSED_BYTES;

        // Gzuncompress handles zlib-wrapped deflate (most common in PDF).
        $result = @gzuncompress($stream, $limit);
        if ($result !== false) {
            return $result;
        }

        // Gzinflate handles raw deflate.
        $result = @gzinflate($stream, $limit);
        if ($result !== false) {
            return $result;
        }

        // Stream is uncompressed or uses an unsupported filter — return as-is.
        return $stream;
    }

    /**
     * Extract text operators (Tj, TJ, ', ") from a single decoded PDF stream.
     *
     * @param string $stream Decoded stream content.
     * @return string
     */
    private static function extract_text_from_stream(string $stream): string {
        $text = '';

        // TJ: [(text) -kern (text)] TJ.
        if (preg_match_all('/\[([^\]]+)\]\s*TJ/s', $stream, $matches)) {
            foreach ($matches[1] as $tj) {
                preg_match_all('/\(([^)]*)\)|<([^>]*)>/', $tj, $parts);
                foreach ($parts[1] as $i => $part) {
                    if ($part !== '') {
                        $text .= self::decode_pdf_string($part);
                    } else if (!empty($parts[2][$i])) {
                        $text .= self::decode_hex_string($parts[2][$i]);
                    }
                }
            }
        }

        // Tj: (text) Tj.
        if (preg_match_all('/\(([^)]*)\)\s*Tj/s', $stream, $matches)) {
            foreach ($matches[1] as $part) {
                $text .= self::decode_pdf_string($part) . ' ';
            }
        }

        // Quote operators move to a new line before showing text.
        if (preg_match_all('/\(([^)]*)\)\s*\'/s', $stream, $matches)) {
            foreach ($matches[1] as $part) {
                $text .= "\n" . self::decode_pdf_string($part);
            }
        }
        if (preg_match_all('/\(([^)]*)\)\s*"/s', $stream, $matches)) {
            foreach ($matches[1] as $part) {
                $text .= "\n" . self::decode_pdf_string($part);
            }
        }

        return $text;
    }

    /**
     * Try to extract Unicode text from raw PDF hex strings (ToUnicode CMap).
     *
     * @param string $data Raw PDF binary.
     * @return string
     */
    private static function extract_pdf_unicode(string $data): string {
        $text = '';

        if (preg_match_all('/<([0-9A-Fa-f\s]+)>/', $data, $matches)) {
            foreach ($matches[1] as $hex) {
                $hex = preg_replace('/\s/', '', $hex);
                if (strlen($hex) >= 4 && strlen($hex) % 4 === 0) {
                    $decoded = self::decode_utf16be_hex($hex);
                    if (self::is_readable_text($decoded)) {
                        $text .= $decoded . ' ';
                    }
                }
            }
        }

        return $text;
    }

    /**
     * Extract text from BT … ET (Begin Text / End Text) blocks.
     *
     * Used as a last-resort fallback when stream extraction yields nothing.
     *
     * @param string $data Raw PDF binary.
     * @return string
     */
    private static function extract_pdf_bt_et(string $data): string {
        $text = '';

        // Decompress all streams first to improve BT/ET matching.
        $decompressed = $data;
        preg_match_all('/stream\s*\r?\n(.+?)\r?\n?\s*endstream/s', $data, $streams);
        foreach ($streams[1] as $stream) {
            $dec = self::safe_decompress($stream);
            if ($dec !== $stream) {
                $decompressed .= "\n" . $dec;
            }
        }

        if (preg_match_all('/BT\s*(.+?)\s*ET/s', $decompressed, $matches)) {
            foreach ($matches[1] as $block) {
                preg_match_all('/\(([^)]+)\)/', $block, $strings);
                foreach ($strings[1] as $str) {
                    $decoded = self::decode_pdf_string($str);
                    if (self::is_readable_text($decoded)) {
                        $text .= $decoded . ' ';
                    }
                }
            }
        }

        return $text;
    }

    // Section separator.
    // PDF — string decoders.
    // Section separator.

    /**
     * Decode a PDF literal string (handle octal and standard escape sequences).
     *
     * @param string $str Raw PDF string content (without outer parentheses).
     * @return string
     */
    private static function decode_pdf_string(string $str): string {
        $str = preg_replace_callback('/\\\\([0-7]{1,3})/', static function ($m) {
            return chr(octdec($m[1]));
        }, $str);

        return str_replace(
            ['\\n', '\\r', '\\t', '\\b', '\\f', '\\(', '\\)', '\\\\'],
            ["\n",  "\r",  "\t",  "\b",  "\f",  '(',   ')',   '\\'],
            $str
        );
    }

    /**
     * Decode a PDF hex string to printable ASCII.
     *
     * @param string $hex Hex digits (may contain whitespace).
     * @return string
     */
    private static function decode_hex_string(string $hex): string {
        $hex  = preg_replace('/\s/', '', $hex);
        $text = '';

        for ($i = 0, $len = strlen($hex); $i < $len; $i += 2) {
            $byte = substr($hex, $i, 2);
            if (strlen($byte) === 2) {
                $char = chr(hexdec($byte));
                if (ord($char) >= 32 && ord($char) < 127) {
                    $text .= $char;
                }
            }
        }

        return $text;
    }

    /**
     * Decode a UTF-16BE hex string to UTF-8.
     *
     * @param string $hex Hex digits, length must be a multiple of 4.
     * @return string
     */
    private static function decode_utf16be_hex(string $hex): string {
        $text = '';

        for ($i = 0, $len = strlen($hex); $i < $len; $i += 4) {
            $code = substr($hex, $i, 4);
            if (strlen($code) === 4) {
                $codepoint = hexdec($code);
                if ($codepoint >= 32 && $codepoint < 0xFFFF) {
                    $text .= mb_chr($codepoint, 'UTF-8');
                }
            }
        }

        return $text;
    }

    /**
     * Heuristic check: is this string mostly printable characters?
     *
     * Used to filter out binary garbage before appending to the result.
     *
     * @param string $text
     * @return bool
     */
    private static function is_readable_text(string $text): bool {
        if (strlen($text) < 2) {
            return false;
        }

        $total     = mb_strlen($text);
        $printable = 0;

        for ($i = 0; $i < $total; $i++) {
            $ord = mb_ord(mb_substr($text, $i, 1));
            if ($ord >= 32 && $ord < 127 || $ord > 160 || $ord === 9 || $ord === 10) {
                $printable++;
            }
        }

        return ($printable / max(1, $total)) > 0.7;
    }

    /**
     * Clean up extracted PDF text.
     *
     * Removes control characters, normalises whitespace, and drops
     * lines that are likely page numbers or artefacts (only digits).
     *
     * @param string $text
     * @return string
     */
    private static function clean_pdf_text(string $text): string {
        // Remove control characters except LF, CR, TAB.
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text);

        // Fix common Mojibake sequences from Latin-1 mis-decoded as UTF-8.
        $text = str_replace(
            ['Â ', 'â€™', 'â€"', 'â€œ', 'â€'],
            [' ',  "'",   '-',   '"',   '"'],
            $text
        );

        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n\s*\n\s*\n+/', "\n\n", $text);

        // Drop lines that are only digits (page numbers, figure numbers, etc.).
        $lines = explode("\n", $text);
        $clean = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if (strlen($line) > 2 && !preg_match('/^[\d\s]+$/', $line)) {
                $clean[] = $line;
            }
        }

        return trim(implode("\n", $clean));
    }

    // Section separator.
    // JSON helper.
    // Section separator.

    /**
     * Recursively flatten a decoded JSON structure to "key: value" lines.
     *
     * @param mixed  $data
     * @param string $prefix
     * @return string
     */
    private static function flatten_json(mixed $data, string $prefix = ''): string {
        if (!is_array($data)) {
            return (string)$data;
        }

        $result = '';
        foreach ($data as $key => $value) {
            $newprefix = $prefix !== '' ? "{$prefix}.{$key}" : (string)$key;
            if (is_array($value)) {
                $result .= self::flatten_json($value, $newprefix);
            } else {
                $result .= "{$newprefix}: {$value}\n";
            }
        }

        return $result;
    }
}
