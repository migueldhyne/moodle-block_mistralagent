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

/**
 * AJAX endpoint for file text extraction.
 *
 * WHY THIS FILE EXISTS (not an External Service):
 * ────────────────────────────────────────────────
 * Moodle's External Services API encodes all parameters as JSON, which makes it
 * unsuitable for binary file uploads: base64-encoding a 10 MB PDF inflates the
 * payload by ~33 % and causes issues with PHP's max_input_vars / post_max_size
 * on many shared hosting environments.
 *
 * This endpoint uses the native multipart/form-data mechanism so the browser can
 * stream the raw binary directly.  All Moodle security controls are still in place:
 *   - require_login()         → authentication
 *   - require_sesskey()       → CSRF protection
 *   - require_capability()    → authorisation
 *   - clean_param(PARAM_FILE) → file name sanitisation
 *   - extension whitelist     → file type restriction
 *   - magic-byte verification → content-type validation
 *
 * If Moodle ever adds native binary upload support to External Services, this
 * file should be migrated to classes/external/extract_file.php and removed.
 *
 * Receives a file via multipart/form-data (not base64), extracts text and returns JSON.
 *
 * POST params:
 *   sesskey         - Moodle session key (CSRF)
 *   blockinstanceid - int
 *   courseid        - int
 *   userfile        - the file (multipart)
 *
 * @package    block_mistralagent
 * @copyright  2026 Miguël Dhyne <miguel.dhyne@gmail.com>
 * @author     Miguël Dhyne <miguel.dhyne@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);
require_once('../../config.php');
require_once($CFG->dirroot . '/blocks/mistralagent/classes/external/extract_file.php');

header('Content-Type: application/json; charset=utf-8');

$respond = function (bool $success, string $text = '', string $error = '', bool $truncated = false) {
    echo json_encode([
        'success'   => $success,
        'text'      => $text,
        'truncated' => $truncated,
        'canpaste'  => false,
        'error'     => $error,
    ]);
    die();
};

try {
    // Authentification.
    require_login();
    require_sesskey();

    $blockinstanceid = required_param('blockinstanceid', PARAM_INT);
    $courseid        = required_param('courseid', PARAM_INT);

    $course  = get_course($courseid);
    $context = context_course::instance($courseid);
    require_capability('block/mistralagent:use', $context);

    // Uploaded file.
    if (empty($_FILES['userfile']) || $_FILES['userfile']['error'] !== UPLOAD_ERR_OK) {
        $errcodes = [
            UPLOAD_ERR_INI_SIZE   => get_string('err_upload_ini_size', 'block_mistralagent'),
            UPLOAD_ERR_FORM_SIZE  => get_string('err_upload_form_size', 'block_mistralagent'),
            UPLOAD_ERR_PARTIAL    => get_string('err_upload_partial', 'block_mistralagent'),
            UPLOAD_ERR_NO_FILE    => get_string('err_upload_no_file', 'block_mistralagent'),
            UPLOAD_ERR_NO_TMP_DIR => get_string('err_upload_no_tmp', 'block_mistralagent'),
            UPLOAD_ERR_CANT_WRITE => get_string('err_upload_cant_write', 'block_mistralagent'),
        ];
        $code = $_FILES['userfile']['error'] ?? UPLOAD_ERR_NO_FILE;
        $respond(false, '', $errcodes[$code] ?? get_string('err_file_upload', 'block_mistralagent', $code));
    }

    $tmppath  = $_FILES['userfile']['tmp_name'];
    $filename = clean_param($_FILES['userfile']['name'], PARAM_FILE);
    $ext      = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

    // Whitelist des types.
    $allowed = ['txt', 'json', 'docx', 'pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp'];
    if (!in_array($ext, $allowed, true)) {
        $respond(false, '', get_string('err_file_type', 'block_mistralagent', $ext));
    }

    // Lecture du fichier.
    $data = file_get_contents($tmppath);
    if ($data === false || strlen($data) === 0) {
        $respond(false, '', get_string('err_file_empty', 'block_mistralagent'));
    }

    // Extraction selon le type.
    $imagetypes = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    if (in_array($ext, $imagetypes, true)) {
        // Images : on construit la data-URI et on retourne le sentinel.
        $mimemap = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
                    'png' => 'image/png', 'gif'  => 'image/gif',
                    'webp' => 'image/webp'];
        $mime    = $mimemap[$ext];
        $b64     = base64_encode($data);
        $respond(true, '__IMAGE_BASE64__' . $mime . ';base64,' . $b64);
    }

    // Magic-byte verification.
    $magic = ['pdf' => "\x25\x50\x44\x46", 'docx' => "\x50\x4B\x03\x04"];
    if (isset($magic[$ext]) && substr($data, 0, strlen($magic[$ext])) !== $magic[$ext]) {
        $respond(false, '', get_string('err_file_magic', 'block_mistralagent', $ext));
    }

    // Extraction texte.
    $text = match ($ext) {
        'txt'  => $data,
        'json' => \block_mistralagent\external\extract_file::extract_json_public($data),
        'docx' => \block_mistralagent\external\extract_file::extract_docx_public($data),
        'pdf'  => \block_mistralagent\external\extract_file::extract_pdf_public($data),
    };

    $text = trim($text);
    $respond(true, $text);
} catch (Exception $e) {
    debugging('block_mistralagent extract_file_ajax: ' . $e->getMessage(), DEBUG_DEVELOPER);
    $respond(false, '', $e->getMessage());
}
