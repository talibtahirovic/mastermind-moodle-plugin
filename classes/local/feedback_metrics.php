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

namespace block_mastermind_assistant\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Aggregates course evaluation (mod_feedback) responses for a course.
 *
 * Produces per-activity, per-question statistics plus anonymized free-text
 * comments — no user ids ever leave this collector, so the result is safe to
 * ship to the AI dashboard as-is. Courses without feedback activities get the
 * empty shape, so callers never need a guard.
 *
 * @package    block_mastermind_assistant
 * @copyright  2026 The Namers
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class feedback_metrics {

    /** @var int Maximum comments collected per feedback activity. */
    const COMMENT_CAP = 50;

    /** @var int Maximum length of a single comment. */
    const COMMENT_MAXLEN = 300;

    /** @var string[] Item types whose values are numeric ratings. */
    const RATED_TYPES = ['numeric', 'multichoicerated'];

    /** @var string[] Item types whose values are free text. */
    const TEXT_TYPES = ['textarea', 'textfield'];

    /** @var string[] Presentation-only item types that carry no answers. */
    const SKIP_TYPES = ['label', 'pagebreak', 'captcha', 'info'];

    /**
     * Collect per-activity and per-question evaluation aggregates for a course.
     *
     * @param int $courseid Course id.
     * @return array ['activities' => [...], 'satisfaction' => float|null]
     */
    public static function collect(int $courseid): array {
        global $DB;

        $result = ['activities' => [], 'satisfaction' => null];
        $feedbacks = $DB->get_records('feedback', ['course' => $courseid], 'id');
        if (!$feedbacks) {
            return $result;
        }

        $ratingsum = 0.0;
        $ratingcount = 0;

        foreach ($feedbacks as $feedback) {
            $items = $DB->get_records('feedback_item', ['feedback' => $feedback->id], 'position, id');
            $questions = [];
            $commentrows = [];

            foreach ($items as $item) {
                if (in_array($item->typ, self::SKIP_TYPES, true)) {
                    continue;
                }
                // Newest first: feedback_completed ids increase per submission.
                $values = $DB->get_records('feedback_value', ['item' => $item->id],
                    'completed DESC', 'id, value, completed');
                $avg = null;
                if (in_array($item->typ, self::RATED_TYPES, true)) {
                    $ratingmap = $item->typ === 'multichoicerated'
                        ? self::rating_map($item->presentation) : null;
                    $numbers = [];
                    foreach ($values as $value) {
                        $raw = trim((string) $value->value);
                        if ($ratingmap !== null) {
                            if (isset($ratingmap[(int) $raw])) {
                                $numbers[] = $ratingmap[(int) $raw];
                            }
                        } else if (is_numeric($raw)) {
                            $numbers[] = (float) $raw;
                        }
                    }
                    if ($numbers) {
                        $avg = round(array_sum($numbers) / count($numbers), 1);
                        $ratingsum += array_sum($numbers);
                        $ratingcount += count($numbers);
                    }
                } else if (in_array($item->typ, self::TEXT_TYPES, true)) {
                    foreach ($values as $value) {
                        $text = trim(strip_tags((string) $value->value));
                        if ($text === '') {
                            continue;
                        }
                        $commentrows[] = [
                            'completed' => (int) $value->completed,
                            'text' => \core_text::substr($text, 0, self::COMMENT_MAXLEN),
                        ];
                    }
                }
                $questions[] = [
                    'question' => format_string($item->name),
                    'type' => $item->typ,
                    'responses' => count($values),
                    'avg' => $avg,
                ];
            }

            // Newest first across every text question in this activity.
            usort($commentrows, fn($a, $b) => $b['completed'] <=> $a['completed']);
            $comments = array_column(array_slice($commentrows, 0, self::COMMENT_CAP), 'text');

            $result['activities'][] = [
                'name' => format_string($feedback->name),
                'responses' => (int) $DB->count_records('feedback_completed', ['feedback' => $feedback->id]),
                'questions' => $questions,
                'comments' => $comments,
            ];
        }

        if ($ratingcount > 0) {
            $result['satisfaction'] = round($ratingsum / $ratingcount, 1);
        }
        return $result;
    }

    /**
     * Map multichoicerated option indexes to their numeric ratings.
     *
     * feedback_value.value stores the 1-based index of the chosen option;
     * the rating is the numeric prefix of that option's presentation line
     * ("r>>>>>10####Low|20####Medium|30####High", optionally ending with an
     * "<<<<<1" adjustment suffix).
     *
     * @param string $presentation Item presentation string.
     * @return float[] Option index => rating.
     */
    private static function rating_map(string $presentation): array {
        $pos = strpos($presentation, '>>>>>');
        if ($pos !== false) {
            $presentation = substr($presentation, $pos + 5);
        }
        $pos = strpos($presentation, '<<<<<');
        if ($pos !== false) {
            $presentation = substr($presentation, 0, $pos);
        }
        $map = [];
        foreach (explode('|', $presentation) as $i => $line) {
            $parts = explode('####', $line);
            if (count($parts) === 2 && is_numeric(trim($parts[0]))) {
                $map[$i + 1] = (float) trim($parts[0]);
            }
        }
        return $map;
    }
}
