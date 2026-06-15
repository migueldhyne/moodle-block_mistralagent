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

use function preg_match;
use function base64_decode;
use function explode;
use function bin2hex;
use function random_bytes;
use function curl_init;
use function curl_setopt_array;
use function curl_exec;
use function curl_getinfo;
use function curl_close;
use function json_decode;
use function json_encode;

/**
 * Mistral API client — Conversations API (beta).
 *
 * Uses the beta Conversations API exclusively:
 *   POST /v1/conversations              → start a new conversation
 *   POST /v1/conversations/{id}         → append to an existing conversation
 *
 * This API supports ALL built-in connectors:
 *   web_search, web_search_premium, code_interpreter,
 *   image_generation, document_library.
 *
 * The legacy /v1/agents/completions endpoint is NOT used because it
 * rejects agents that have built-in connectors enabled.
 *
 * Response structure (non-streaming):
 *   {
 *     "conversation_id": "...",
 *     "outputs": [
 *       { "type": "message.output", "role": "assistant", "content": "..." },
 *       { "type": "tool.execution", ... },
 *       ...
 *     ]
 *   }
 *
 * We extract the last entry whose type starts with "message" and role
 * is "assistant". Content may be a plain string or an array of content
 * blocks (text / image_url / …).
 *
 * @package    block_mistralagent
 * @copyright  2026 Miguël Dhyne <miguel.dhyne@gmail.com>
 * @author     Miguël Dhyne <miguel.dhyne@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mistral_client {
    /** @var string Base URL for the beta Conversations API */
    private const CONVERSATIONS_URL = 'https://api.mistral.ai/v1/conversations';

    /** @var string Used for API key validation only */
    private const MODELS_URL = 'https://api.mistral.ai/v1/models';

    /** @var string */
    private string $apikey;

    /** @var string */
    private string $agentid;

    /**
     * Constructor.
     *
     * @param string $agentid  Mistral agent ID (ag:…).
     * @param string $apikey   Optional API key. If empty, falls back to the global admin key.
     * @throws \moodle_exception If no API key is available.
     */
    public function __construct(string $agentid, string $apikey = '') {
        $this->agentid = $agentid;

        if (!empty($apikey)) {
            $this->apikey = self::sanitise_api_key($apikey);
        } else {
            $this->apikey = self::sanitise_api_key(get_config('block_mistralagent', 'apikey'));
        }

        if (empty($this->apikey)) {
            throw new \moodle_exception('noapikey', 'block_mistralagent');
        }
    }

    // Public API.

    /**
     * Start a brand-new conversation with the agent.
     *
     * Calls POST /v1/conversations.
     *
     * @param  string $message      First user message.
     * @param  string $imagebase64  Optional — data-URI base64 image (data:image/jpeg;base64,...)
     * @return array  ['content' => string, 'mistral_conversation_id' => string]
     * @throws \Exception
     */
    public function start_conversation(string $message, string $imagebase64 = ''): array {
        $payload = [
            'agent_id' => $this->agentid,
            'inputs'   => $this->build_inputs($message, $imagebase64),
            'store'    => true,
            'stream'   => false,
        ];

        $data = $this->make_request(self::CONVERSATIONS_URL, $payload);
        return $this->parse_response($data);
    }

    /**
     * Append a message to an existing Mistral conversation.
     *
     * Calls POST /v1/conversations/{id}  (the "append" endpoint).
     * Mistral stores the full history server-side — only the new message
     * is sent, not the entire thread.
     *
     * @param  string $mistralconvid  ID returned by start_conversation().
     * @param  string $message        New user message.
     * @param  string $imagebase64    Optional — data-URI base64 image.
     * @return array  ['content' => string, 'mistral_conversation_id' => string]
     * @throws \Exception
     */
    public function continue_conversation(string $mistralconvid, string $message, string $imagebase64 = ''): array {
        $url = self::CONVERSATIONS_URL . '/' . rawurlencode($mistralconvid);

        $payload = [
            'inputs' => $this->build_inputs($message, $imagebase64),
            'store'  => true,
            'stream' => false,
        ];

        $data = $this->make_request($url, $payload);
        return $this->parse_response($data);
    }

    /**
     * Build the inputs field.
     *
     * L'API Conversations Mistral n'accepte pas les images directement.
     * On analyse l'image via /v1/chat/completions (vision), puis on injecte
     * the textual description into the message sent to the conversation.
     *
     * @param  string $message
     * @param  string $imagebase64  Full data-URI (data:image/jpeg;base64,...) or empty string.
     * @return string
     */
    private function build_inputs(string $message, string $imagebase64 = ''): string {
        if (empty($imagebase64)) {
            return $message;
        }

        $description = $this->describe_image_via_vision($imagebase64, $message);

        if ($description !== null) {
            $intro  = get_string('vision_intro', 'block_mistralagent') . "\n\n";
            $intro .= get_string('vision_intro_sep_start', 'block_mistralagent') . "\n";
            $intro .= $description;
            $intro .= "\n" . get_string('vision_intro_sep_end', 'block_mistralagent') . "\n\n";
            $intro .= !empty($message)
                ? get_string('vision_user_message', 'block_mistralagent', $message)
                : get_string('vision_user_analyse', 'block_mistralagent');
            return $intro;
        }

        return !empty($message) ? $message : get_string('vision_user_analyse', 'block_mistralagent');
    }

    /**
     * Describe an image using /v1/chat/completions with pixtral (vision model).
     *
     * @param  string $imagebase64  Full data-URI (data:image/jpeg;base64,...).
     * @param  string $userquestion Question asked by the user.
     * @return string|null
     */
    private function describe_image_via_vision(string $imagebase64, string $userquestion = ''): ?string {
        $prompt = !empty($userquestion)
            ? get_string('vision_prompt_with_question', 'block_mistralagent', $userquestion)
            : get_string('vision_prompt_describe', 'block_mistralagent');

        $payload = json_encode([
            'model'      => get_config('block_mistralagent', 'model_vision') ?: 'pixtral-12b-2409',
            'max_tokens' => 2000,
            'messages'   => [[
                'role'    => 'user',
                'content' => [
                    ['type' => 'text',      'text'      => $prompt],
                    ['type' => 'image_url', 'image_url' => ['url' => $imagebase64]],
                ],
            ]],
        ]);

        $ch = curl_init('https://api.mistral.ai/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->apikey,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT => 60,
        ]);
        $resp     = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpcode !== 200 || !$resp) {
            debugging('MistralAgent vision HTTP ' . $httpcode . ': ' . substr($resp, 0, 300), DEBUG_DEVELOPER);
            return null;
        }

        $data = json_decode($resp, true);
        return $data['choices'][0]['message']['content'] ?? null;
    }

    // Response parsing.

    /**
     * Extract the assistant's text response from a Conversations API response.
     *
     * The API returns an "outputs" array containing entries of various types:
     *   - message.output  (the assistant's reply — what we want)
     *   - tool.execution  (web_search, code_interpreter calls)
     *   - function.call   (custom function calling)
     *   - agent.handoff   (multi-agent workflows)
     *   - …
     *
     * We find the last entry whose type is "message.output" (or starts with
     * "message") with role "assistant". Its "content" field may be:
     *   - a plain string
     *   - an array of content blocks: [{type:"text",text:"…"}, {type:"image_url",…}]
     *
     * The conversation ID is stored in "conversation_id" at the top level.
     *
     * @param  array $data  Decoded JSON response body.
     * @return array ['content' => string, 'mistral_conversation_id' => string]
     * @throws \Exception
     */
    private function parse_response(array $data): array {
        // The conversation ID is at the top level of the response.
        $mistralconvid = $data['conversation_id'] ?? ($data['id'] ?? '');

        $outputs = $data['outputs'] ?? [];

        $content = '';

        $images  = [];

        // Iterate over non-message outputs to extract any generated images.
        foreach ($outputs as $entry) {
            $etype = $entry['type'] ?? '';
            if (!str_starts_with($etype, 'message')) {
                // Look for images across all known keys.
                foreach (['content', 'output', 'result', 'data'] as $key) {
                    $tc = $entry[$key] ?? null;
                    if ($tc === null) {
                        continue;
                    }
                    if (is_string($tc)) {
                        $dec = json_decode($tc, true);
                        if (is_array($dec)) {
                            $tc = $dec;
                        }
                    }
                    if (is_array($tc)) {
                        foreach ($tc as $block) {
                            if (!is_array($block)) {
                                continue;
                            }
                            $btype = $block['type'] ?? '';
                            if ($btype === 'image_url') {
                                $u = $block['url'] ?? ($block['image_url']['url'] ?? null);
                                if ($u) {
                                    $images[] = $u;
                                }
                            } else if ($btype === 'image') {
                                $u = $block['url'] ?? ($block['source']['url'] ?? null);
                                if ($u) {
                                    $images[] = $u;
                                }
                            }
                        }
                    }
                }
                // Check url/image_url directly on the entry.
                foreach (['url', 'image_url', 'image'] as $ukey) {
                    $uval = $entry[$ukey] ?? null;
                    if ($uval && is_string($uval) && str_starts_with($uval, 'http')) {
                        $images[] = $uval;
                    }
                }
            }
        }

        /*
         * Walk outputs in reverse to get the last assistant message (message.output). The generated image is
         * dans content[] sous forme de chunk type=tool_file avec un file_id.
         */
        foreach (array_reverse($outputs) as $entry) {
            $etype = $entry['type'] ?? '';
            $role  = $entry['role'] ?? '';

            if (str_starts_with($etype, 'message') && $role === 'assistant') {
                $raw = $entry['content'] ?? '';

                if (is_array($raw)) {
                    foreach ($raw as $block) {
                        $btype = $block['type'] ?? '';
                        if ($btype === 'text') {
                            $content .= $block['text'] ?? '';
                        } else if ($btype === 'tool_file') {
                            /*
                             * Image generated by the image_generation connector. The block is a
                             * tool_file chunk carrying a file_id, file_name and file_type.
                             */
                            $fileid = $block['file_id'] ?? null;
                            if ($fileid) {
                                // Download the image and convert it to a base64 data-URI.
                                try {
                                    $imgdata = $this->download_file($fileid);
                                    if ($imgdata) {
                                        $images[] = 'data:image/png;base64,' . base64_encode($imgdata);
                                    }
                                } catch (\Exception $e) {
                                    debugging('MistralAgent: file download failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
                                    // Fallback : stocker juste le file_id pour debug.
                                    $content .= '\n' . get_string('vision_file_fallback', 'block_mistralagent', $fileid);
                                }
                            }
                        } else if ($btype === 'image_url') {
                            $imgurl = $block['url'] ?? ($block['image_url']['url'] ?? null);
                            if ($imgurl) {
                                $images[] = $imgurl;
                            }
                        }
                    }
                } else {
                    $content = (string)$raw;
                }

                if (!empty($content) || !empty($images)) {
                    break;
                }
            }
        }

        if (empty($content) && empty($images)) {
            debugging(
                'MistralAgent: no assistant content found. Full response: ' . json_encode($data),
                DEBUG_DEVELOPER
            );
            throw new \moodle_exception('err_api_no_content', 'block_mistralagent');
        }

        /*
         * Fallback: extract image URLs from the Markdown text of the response. Mistral sometimes includes
         * generated images as ![alt](url) or [url](url) Markdown syntax.
         */
        if (!empty($content) && empty($images)) {
            preg_match_all('/!\[.*?\]\((https?:\/\/[^\s)]+)\)/', $content, $mdmatches);
            foreach ($mdmatches[1] as $mdurl) {
                $images[] = $mdurl;
            }
            // Strip Markdown image tags from the text when images were found.
            if (!empty($images)) {
                $content = preg_replace('/!\[.*?\]\(https?:\/\/[^\s)]+\)\s*/u', '', $content);
                $content = trim($content);
            }
        }

        return [
            'content'                 => $content,
            'images'                  => $images,
            'mistral_conversation_id' => $mistralconvid,
        ];
    }

    // File download.

    /** Endpoint for downloading generated files. */
    private const FILES_URL = 'https://api.mistral.ai/v1/files/';

    /**
     * Download the binary content of a generated file (e.g. a PNG image).
     *
     * @param  string $fileid  ID du fichier (file_id du chunk tool_file).
     * @return string          Contenu binaire du fichier.
     * @throws \Exception
     */
    public function download_file(string $fileid): string {
        $url  = self::FILES_URL . rawurlencode($fileid) . '/content';
        $curl = new \curl();
        $curl->setopt([
            'CURLOPT_TIMEOUT'        => 60,
            'CURLOPT_CONNECTTIMEOUT' => 15,
        ]);
        $curl->setHeader([
            'Authorization: Bearer ' . $this->apikey,
            'Accept: */*',
            'Mistral-Retention: none',
        ]);

        $response  = $curl->get($url);
        $info      = $curl->get_info();
        $httpcode  = $info['http_code'] ?? 0;
        $curlerror = $curl->get_errno();

        if ($curlerror) {
            throw new \moodle_exception('err_api_curl_download', 'block_mistralagent', '', $curl->error);
        }
        if ($httpcode !== 200) {
            throw new \moodle_exception('err_api_http_download', 'block_mistralagent', '', $httpcode);
        }

        return $response;
    }

    // Agent listing.

    /**
     * List agents available for a given API key.
     * Called from configure.php via AJAX.
     *
     * @param  string $apikey  Mistral API key to query.
     * @return array           Array of ['id' => ..., 'name' => ..., 'description' => ...].
     * @throws \Exception     On network error or invalid key.
     */
    public static function list_agents_for_key(string $apikey): array {
        $apikey = self::sanitise_api_key($apikey);
        if (empty($apikey)) {
            throw new \Exception(get_string('apikey_empty', 'block_mistralagent'));
        }

        $curl = new \curl();
        $curl->setopt([
            'CURLOPT_TIMEOUT'        => 15,
            'CURLOPT_CONNECTTIMEOUT' => 10,
        ]);
        $curl->setHeader([
            'Authorization: Bearer ' . $apikey,
            'Content-Type: application/json',
            'Mistral-Retention: none',
        ]);

        // Mistral agents API: GET /v1/agents.
        $response = $curl->get('https://api.mistral.ai/v1/agents');
        $info     = $curl->get_info();
        $httpcode = $info['http_code'] ?? 0;

        if ($httpcode === 401) {
            throw new \Exception(get_string('apikey_invalid', 'block_mistralagent'));
        }
        if ($httpcode !== 200) {
            throw new \Exception(get_string('api_error', 'block_mistralagent') . " (HTTP {$httpcode})");
        }

        $data = json_decode((string)$response, true);
        if (!is_array($data)) {
            throw new \Exception(get_string('api_error', 'block_mistralagent') . ': invalid JSON');
        }

        $agents = [];
        // GET /v1/agents returns either a direct array or a data-wrapped object, depending on version.
        $list = [];
        if (isset($data[0]) || empty($data)) {
            // Case 1: a direct array of agent objects.
            $list = $data;
        } else {
            // Case 2: an object wrapping the agents under a data or agents property.
            $list = $data['data'] ?? $data['agents'] ?? [];
        }

        foreach ($list as $ag) {
            if (!is_array($ag)) {
                continue;
            }
            $agents[] = [
                'id'          => $ag['id'] ?? '',
                'name'        => $ag['name'] ?? '',
                'description' => $ag['description'] ?? '',
            ];
        }

        return $agents;
    }

    // Image generation.

    /** Dedicated image generation endpoint (OpenAI-compatible). */
    private const IMAGES_URL = 'https://api.mistral.ai/v1/images/generations';

    /**
     * Generate an image via the /v1/images/generations API.
     *
     * @param  string $prompt  Description of the image to generate.
     * @return string          URL of the generated image.
     * @throws \Exception
     */
    public function generate_image(string $prompt): string {
        $payload = [
            'model'   => get_config('block_mistralagent', 'model_image') ?: 'pixtral-1-25-01',
            'prompt'  => $prompt,
            'n'       => 1,
            'size'    => '1024x1024',
        ];

        $data = $this->make_request(self::IMAGES_URL, $payload);

        // The expected response wraps an image URL under the data property.
        $url = $data['data'][0]['url'] ?? null;

        if (!$url) {
            // Fallback: the data property may carry a base64-encoded image instead of a URL.
            $b64 = $data['data'][0]['b64_json'] ?? null;
            if ($b64) {
                $url = 'data:image/png;base64,' . $b64;
            }
        }

        if (!$url) {
            throw new \moodle_exception('err_api_no_image', 'block_mistralagent');
        }

        return $url;
    }

    /**
     * Detect whether a message is an image generation request.
     *
     * Searches for keywords in both French and English.
     *
     * @param  string $message
     * @return bool
     */
    public static function is_image_request(string $message): bool {
        // Normaliser : minuscules + supprimer accents pour comparaison robuste.
        $lower = mb_strtolower($message, 'UTF-8');
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $lower);

        // Keywords without accents (after transliteration).
        $keywords = [
            // French keywords.
            'image de ', 'image du ', 'image des ', 'image sur ',
            'image qui ', 'une image', 'une autre image', 'une nouvelle image',
            'une illustration', 'un dessin',
            'genere', 'cree une image', 'creer une image',
            'dessine', 'fais une image', 'fais moi',
            'je veux une image', 'je voudrais une image',
            'montre moi une image', 'produis une image',
            'represente ', 'visualise ',
            // English.
            'generate an image', 'generate image', 'create an image', 'create image',
            'draw me', 'draw a ', 'draw an ', 'make an image', 'make image',
            'an image of', 'a picture of', 'a drawing of',
            'produce an image', 'render an image', 'show me an image',
            'another image', 'a new image',
        ];

        foreach ($keywords as $kw) {
            if (str_contains($ascii, $kw)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Extract the image generation prompt from a user message.
     *
     * @param string $message
     * @return string
     */
    public static function extract_image_prompt(string $message): string {
        $lower = mb_strtolower($message, 'UTF-8');
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $lower);

        // Paires (pattern_ascii, longueur_originale_approx) — du plus long au plus court.
        $triggers = [
            'genere moi une image de', 'genere moi une image qui represente', 'genere moi une image',
            'genere une image de', 'genere une image qui represente', 'genere une image',
            'cree une image de', 'cree une image qui', 'cree une image',
            'creer une image de', 'creer une image',
            'fais une image de', 'fais une image',
            'je veux une image de', 'je veux une image',
            'je voudrais une image de', 'je voudrais une image',
            'dessine moi un', 'dessine moi une', 'dessine moi',
            'dessine un', 'dessine une', 'dessine',
            'je veux une image qui represente', 'je veux une image qui montre', 'je veux une image de',
            'une image qui represente', 'une image qui montre',
            'une image de', 'une image qui', 'une illustration de',
            'un dessin de',
            // English.
            'generate an image of', 'generate an image', 'generate image of', 'generate image',
            'create an image of', 'create an image', 'create image of',
            'draw me a', 'draw me an', 'draw me', 'draw a', 'draw an',
            'make an image of', 'make an image',
            'a picture of', 'an image of',
        ];

        // Sort by descending length.
        usort($triggers, fn($a, $b) => strlen($b) - strlen($a));

        foreach ($triggers as $trigger) {
            $pos = mb_strpos($ascii, $trigger);
            if ($pos !== false) {
                // Extraire depuis la position correspondante dans le message original.
                $afterlen = mb_strlen($trigger);
                $prompt   = mb_substr($message, $pos + $afterlen);
                $prompt   = trim($prompt, " \t\n\r\0\x0B:,.");
                if (mb_strlen($prompt) > 3) {
                    return $prompt;
                }
            }
        }

        return trim($message);
    }

    // HTTP layer.

    /**
     * Execute a POST request and return the decoded JSON body.
     *
     * @param  string $url
     * @param  array  $payload
     * @return array  Decoded JSON.
     * @throws \Exception
     */
    private function make_request(string $url, array $payload): array {
        $curl = new \curl();
        $curl->setopt([
            'CURLOPT_TIMEOUT'        => 120, // Longer for connectors (web search may take time).
            'CURLOPT_CONNECTTIMEOUT' => 30,
        ]);
        $curl->setHeader([
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apikey,
            'Accept: application/json',
            'Mistral-Retention: none',
        ]);

        $json = json_encode($payload);
        debugging('MistralAgent: POST ' . $url . ' (' . strlen($json) . ' bytes)', DEBUG_DEVELOPER);

        $response  = $curl->post($url, $json);
        $info      = $curl->get_info();
        $httpcode  = $info['http_code'] ?? 0;
        $curlerror = $curl->get_errno();

        if ($curlerror) {
            throw new \moodle_exception('err_api_network', 'block_mistralagent', '', $curlerror);
        }
        if ($httpcode === 0) {
            throw new \moodle_exception('err_api_no_response', 'block_mistralagent');
        }
        if ($httpcode !== 200) {
            $this->throw_api_error($httpcode, $response);
        }

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            debugging('MistralAgent: invalid JSON: ' . substr($response, 0, 500), DEBUG_DEVELOPER);
            throw new \moodle_exception('err_api_json', 'block_mistralagent');
        }

        return $data;
    }

    /**
     * Throw a user-friendly exception for a non-200 HTTP response.
     *
     * @param int    $httpcode
     * @param string $rawresponse
     * @return void
     * @throws \moodle_exception Always.
     */
    private function throw_api_error(int $httpcode, string $rawresponse): void {
        $error    = json_decode($rawresponse, true);
        $errormsg = $error['message'] ?? ($error['error']['message'] ?? $rawresponse);

        debugging("MistralAgent API error ({$httpcode}): {$errormsg}", DEBUG_DEVELOPER);

        switch ($httpcode) {
            case 400:
                throw new \moodle_exception('err_api_400', 'block_mistralagent', '', $errormsg);
            case 401:
                throw new \moodle_exception('err_api_401', 'block_mistralagent');
            case 403:
                throw new \moodle_exception('err_api_403', 'block_mistralagent');
            case 404:
                throw new \moodle_exception('err_api_404', 'block_mistralagent', '', $errormsg);
            case 429:
                throw new \moodle_exception('err_api_429', 'block_mistralagent');
            case 500:
            case 502:
            case 503:
                throw new \moodle_exception('err_api_5xx', 'block_mistralagent');
            default:
                $a = (object)['code' => $httpcode, 'message' => $errormsg];
                throw new \moodle_exception('err_api_default', 'block_mistralagent', '', $a);
        }
    }

    // Utilities.

    /**
     * Validate the configured API key against /v1/models.
     *
     * @param  string $key  Optional explicit key; falls back to the configured key.
     * @return bool  True if accepted.
     * @throws \moodle_exception On network error.
     */
    public static function validate_api_key(string $key = ''): bool {
        $apikey = self::sanitise_api_key(!empty($key) ? $key : get_config('block_mistralagent', 'apikey'));
        if (empty($apikey)) {
            return false;
        }

        $curl = new \curl();
        $curl->setopt(['CURLOPT_TIMEOUT' => 15]);
        $curl->setHeader(['Authorization: Bearer ' . $apikey]);
        $curl->get(self::MODELS_URL);
        $httpcode = $curl->get_info()['http_code'] ?? 0;

        if ($httpcode === 0) {
            throw new \moodle_exception('networkerror', 'block_mistralagent');
        }

        return $httpcode === 200;
    }

    /**
     * Strip CR/LF from an API key to prevent HTTP header injection.
     *
     * @param mixed $key
     * @return string
     */
    private static function sanitise_api_key($key): string {
        if (empty($key)) {
            return '';
        }
        return trim(str_replace(["\r", "\n"], '', (string)$key));
    }
}
