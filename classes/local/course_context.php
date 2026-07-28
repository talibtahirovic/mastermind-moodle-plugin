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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Normalizer for the optional AI course creation context.
 *
 * @package    block_mastermind_assistant
 * @copyright  2026 The Namers <info@mastermindassistant.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_mastermind_assistant\local;

/**
 * Normalizer for the optional AI course creation context.
 *
 * The "Course details" modal collects optional answers (audience, difficulty,
 * language, outcomes, size, emphasis, tone) that are forwarded to the
 * dashboard as a context object. Context is best-effort: anything malformed
 * is dropped so course creation never fails because of optional metadata.
 */
class course_context {
    /** @var string[] Whitelisted context keys. */
    private const ALLOWED_KEYS = [
        'target_audience',
        'difficulty',
        'language',
        'learning_outcomes',
        'course_size',
        'activity_emphasis',
        'tone',
    ];

    /** @var string[] Keys that may carry free text (longer cap). */
    private const FREE_TEXT_KEYS = ['target_audience', 'learning_outcomes'];

    /** @var int Maximum length for free-text values. */
    private const FREE_TEXT_MAX = 500;

    /** @var int Maximum length for preset and language values. */
    private const PRESET_MAX = 100;

    /**
     * Decode and sanitize a JSON-encoded context object.
     *
     * @param string $json JSON string from the client (may be empty).
     * @return array Sanitized context; [] when nothing usable.
     */
    public static function from_json(string $json): array {
        if (trim($json) === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }
        return self::from_array($decoded);
    }

    /**
     * Whitelist and sanitize a decoded context array.
     *
     * @param array $raw Decoded context candidate.
     * @return array Sanitized context; [] when nothing usable.
     */
    public static function from_array(array $raw): array {
        $context = [];
        foreach (self::ALLOWED_KEYS as $key) {
            if (!isset($raw[$key]) || !is_string($raw[$key])) {
                continue;
            }
            $value = trim($raw[$key]);
            if ($value === '') {
                continue;
            }
            $max = in_array($key, self::FREE_TEXT_KEYS, true) ? self::FREE_TEXT_MAX : self::PRESET_MAX;
            $context[$key] = \core_text::substr($value, 0, $max);
        }
        return $context;
    }
}
