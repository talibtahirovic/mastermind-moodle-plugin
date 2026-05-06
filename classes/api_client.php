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
 * API client for Mastermind Assistant dashboard
 *
 * @package    block_mastermind_assistant
 * @copyright  2026 The Namers <info@mastermindassistant.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace block_mastermind_assistant;

/**
 * Centralized HTTP client for all Mastermind Dashboard API calls.
 *
 * All external classes use this instead of making direct OpenAI cURL requests.
 * The dashboard handles all AI logic (prompts, function schemas, OpenAI calls).
 */
class api_client {
    /** @var string Default dashboard base URL. Override only via $CFG->forced_plugin_settings. */
    public const DASHBOARD_URL = 'https://mastermindassistant.ai';

    /** @var string Dashboard base URL */
    private string $baseurl;

    /** @var string API key (ma_live_xxx) */
    private string $apikey;

    /**
     * Resolve the dashboard base URL, respecting $CFG->forced_plugin_settings overrides.
     *
     * @return string Dashboard base URL with trailing slashes trimmed.
     */
    public static function get_dashboard_url(): string {
        $configured = get_config('block_mastermind_assistant', 'dashboard_url');
        if (is_string($configured) && $configured !== '') {
            return rtrim($configured, '/');
        }
        return rtrim(self::DASHBOARD_URL, '/');
    }

    /**
     * Constructor - reads config from plugin settings.
     *
     * @throws \moodle_exception if settings are not configured
     */
    public function __construct() {
        $this->baseurl = self::get_dashboard_url();
        $this->apikey = get_config('block_mastermind_assistant', 'api_key') ?: '';

        if (empty($this->apikey)) {
            throw new \moodle_exception('settings_not_configured', 'block_mastermind_assistant');
        }
    }

    /**
     * Make a request to the dashboard API.
     *
     * @param string $endpoint e.g. '/api/ma/analyze-course'
     * @param array $data POST body (will be JSON-encoded)
     * @param string $method 'GET' or 'POST'
     * @param int $timeout seconds (default 300 for AI calls)
     * @return array decoded JSON response
     * @throws \Exception on failure
     */
    public function request(string $endpoint, array $data = [], string $method = 'POST', int $timeout = 300): array {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        $url = $this->baseurl . $endpoint;

        $curl = new \curl();
        $curl->setHeader([
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apikey,
            'X-LMS-Origin: ' . ($CFG->wwwroot ?? ''),
        ]);

        $options = [
            'CURLOPT_TIMEOUT' => $timeout,
            'CURLOPT_CONNECTTIMEOUT' => 30,
        ];

        if ($method === 'POST') {
            $response = $curl->post($url, json_encode($data), $options);
        } else {
            $response = $curl->get($url, [], $options);
        }

        $httpcode = $curl->get_info()['http_code'] ?? 0;
        $error = $curl->get_errno() ? $curl->error : '';

        if ($error) {
            throw new \Exception('Dashboard API request failed: ' . $error);
        }

        if ($httpcode !== 200) {
            $decoded = json_decode($response, true);
            $msg = $decoded['error'] ?? "HTTP {$httpcode}";
            throw new \Exception('Dashboard API error: ' . $msg);
        }

        $decoded = json_decode($response, true);
        if ($decoded === null) {
            throw new \Exception('Invalid JSON response from dashboard');
        }

        return $decoded;
    }

    /**
     * Analyze course structure and get AI recommendations.
     *
     * @param array $coursedata {course, sections, activities}
     * @return array
     */
    public function analyze_course(array $coursedata): array {
        return $this->request('/api/ma/analyze-course', $coursedata);
    }

    /**
     * Full analysis: analyze course AND generate updated structure in a single call.
     *
     * Requires the /api/ma/full-analysis endpoint on the dashboard.
     * If the endpoint doesn't exist yet, callers should fall back to
     * analyze_course() + generate_structure() separately.
     *
     * @param array $coursedata {course, sections, activities}
     * @return array {recommendations: string, structure: {sections: [...]}}
     */
    public function full_analysis(array $coursedata): array {
        return $this->request('/api/ma/full-analysis', $coursedata);
    }

    /**
     * Generate a complete course structure from scratch.
     *
     * @param string $coursename
     * @return array
     */
    public function generate_course(string $coursename): array {
        return $this->request('/api/ma/generate-course', ['coursename' => $coursename]);
    }

    /**
     * Generate a course structure from an uploaded document (PDF, DOCX, TXT).
     *
     * @param string $filedata Base64-encoded file content
     * @param string $filetype MIME type (application/pdf, etc.)
     * @param string $filename Original file name
     * @return array
     */
    public function generate_course_from_document(string $filedata, string $filetype, string $filename): array {
        return $this->request('/api/ma/generate-course-from-document', [
            'file_data' => $filedata,
            'file_type' => $filetype,
            'file_name' => $filename,
        ], 'POST', 600);
    }

    /**
     * Generate updated course structure based on recommendations.
     *
     * @param array $coursedata
     * @param string $recommendations
     * @return array
     */
    public function generate_structure(array $coursedata, string $recommendations): array {
        return $this->request('/api/ma/generate-structure', [
            'coursedata' => $coursedata,
            'recommendations' => $recommendations,
        ]);
    }

    /**
     * Generate quiz questions.
     *
     * @param string $quizname
     * @param string $quizdescription
     * @param array $existingquestions
     * @param string $difficultylevel
     * @param int $questioncount
     * @param string $academiclevel
     * @param string $sectionname
     * @param array $courseactivities
     * @return array
     */
    public function generate_quiz(
        string $quizname,
        string $quizdescription,
        array $existingquestions = [],
        string $difficultylevel = '',
        int $questioncount = 8,
        string $academiclevel = '',
        string $sectionname = '',
        array $courseactivities = []
    ): array {
        return $this->request('/api/ma/generate-quiz', [
            'quizname' => $quizname,
            'quizdescription' => $quizdescription,
            'existing_questions' => $existingquestions,
            'difficulty_level' => $difficultylevel,
            'question_count' => $questioncount,
            'academic_level' => $academiclevel,
            'section_name' => $sectionname,
            'course_activities' => $courseactivities,
        ]);
    }

    /**
     * Generate quiz questions from an uploaded source document (PDF, DOCX, TXT).
     *
     * The dashboard extracts text from the document and uses it as the
     * authoritative source for question generation.
     *
     * @param string $quizname
     * @param string $quizdescription
     * @param string $filedata Base64-encoded file content
     * @param string $filetype MIME type (application/pdf, etc.)
     * @param string $filename Original file name
     * @param array $existingquestions
     * @param string $difficultylevel
     * @param int $questioncount
     * @param string $academiclevel
     * @return array
     */
    public function generate_quiz_from_document(
        string $quizname,
        string $quizdescription,
        string $filedata,
        string $filetype,
        string $filename,
        array $existingquestions = [],
        string $difficultylevel = '',
        int $questioncount = 8,
        string $academiclevel = ''
    ): array {
        return $this->request('/api/ma/generate-quiz-from-document', [
            'quizname' => $quizname,
            'quizdescription' => $quizdescription,
            'file_data' => $filedata,
            'file_type' => $filetype,
            'file_name' => $filename,
            'existing_questions' => $existingquestions,
            'difficulty_level' => $difficultylevel,
            'question_count' => $questioncount,
            'academic_level' => $academiclevel,
        ], 'POST', 600);
    }

    /**
     * Generate page content.
     *
     * @param string $coursename
     * @param string $pagename
     * @param string $pagedescription
     * @param string $contenttype
     * @param string $academiclevel
     * @param string $targetlength
     * @param string $sectionname
     * @param array $courseactivities
     * @return array
     */
    public function generate_page_content(
        string $coursename,
        string $pagename,
        string $pagedescription,
        string $contenttype = '',
        string $academiclevel = '',
        string $targetlength = '',
        string $sectionname = '',
        array $courseactivities = []
    ): array {
        return $this->request('/api/ma/generate-page', [
            'coursename' => $coursename,
            'pagename' => $pagename,
            'pagedescription' => $pagedescription,
            'content_type' => $contenttype,
            'academic_level' => $academiclevel,
            'target_length' => $targetlength,
            'section_name' => $sectionname,
            'course_activities' => $courseactivities,
        ]);
    }

    /**
     * Generate assignment instructions.
     *
     * @param string $coursename
     * @param string $assignmentname
     * @param string $assignmentdescription
     * @param string $assignmenttype
     * @param string $academiclevel
     * @param string $scopelength
     * @param string $sectionname
     * @param array $courseactivities
     * @return array
     */
    public function generate_assignment(
        string $coursename,
        string $assignmentname,
        string $assignmentdescription,
        string $assignmenttype = '',
        string $academiclevel = '',
        string $scopelength = '',
        string $sectionname = '',
        array $courseactivities = []
    ): array {
        return $this->request('/api/ma/generate-assignment', [
            'coursename' => $coursename,
            'assignmentname' => $assignmentname,
            'assignmentdescription' => $assignmentdescription,
            'assignment_type' => $assignmenttype,
            'academic_level' => $academiclevel,
            'scope_length' => $scopelength,
            'section_name' => $sectionname,
            'course_activities' => $courseactivities,
        ]);
    }

    /**
     * Generate forum content (introduction + discussion topics).
     *
     * @param string $coursename
     * @param string $forumname
     * @param string $forumdescription
     * @param string $forumtype
     * @param string $academiclevel
     * @param int $discussioncount
     * @param string $sectionname
     * @param array $courseactivities
     * @return array
     */
    public function generate_forum(
        string $coursename,
        string $forumname,
        string $forumdescription,
        string $forumtype = '',
        string $academiclevel = '',
        int $discussioncount = 5,
        string $sectionname = '',
        array $courseactivities = []
    ): array {
        return $this->request('/api/ma/generate-forum', [
            'coursename' => $coursename,
            'forumname' => $forumname,
            'forumdescription' => $forumdescription,
            'forum_type' => $forumtype,
            'academic_level' => $academiclevel,
            'discussion_count' => $discussioncount,
            'section_name' => $sectionname,
            'course_activities' => $courseactivities,
        ]);
    }

    /**
     * Generate lesson content (branching pages).
     *
     * @param string $coursename
     * @param string $lessonname
     * @param string $lessondescription
     * @param string $academiclevel
     * @param int $pagecount
     * @param string $sectionname
     * @param array $courseactivities
     * @return array
     */
    public function generate_lesson(
        string $coursename,
        string $lessonname,
        string $lessondescription,
        string $academiclevel = '',
        int $pagecount = 6,
        string $sectionname = '',
        array $courseactivities = []
    ): array {
        return $this->request('/api/ma/generate-lesson', [
            'coursename' => $coursename,
            'lessonname' => $lessonname,
            'lessondescription' => $lessondescription,
            'academic_level' => $academiclevel,
            'page_count' => $pagecount,
            'section_name' => $sectionname,
            'course_activities' => $courseactivities,
        ]);
    }

    /**
     * Generate glossary entries.
     *
     * @param string $coursename
     * @param string $glossaryname
     * @param string $glossarydescription
     * @param string $academiclevel
     * @param int $entrycount
     * @param string $sectionname
     * @param array $courseactivities
     * @return array
     */
    public function generate_glossary(
        string $coursename,
        string $glossaryname,
        string $glossarydescription,
        string $academiclevel = '',
        int $entrycount = 10,
        string $sectionname = '',
        array $courseactivities = []
    ): array {
        return $this->request('/api/ma/generate-glossary', [
            'coursename' => $coursename,
            'glossaryname' => $glossaryname,
            'glossarydescription' => $glossarydescription,
            'academic_level' => $academiclevel,
            'entry_count' => $entrycount,
            'section_name' => $sectionname,
            'course_activities' => $courseactivities,
        ]);
    }

    /**
     * Generate book content (multi-chapter).
     *
     * @param string $coursename
     * @param string $bookname
     * @param string $bookdescription
     * @param string $academiclevel
     * @param int $chaptercount
     * @param string $targetlength
     * @param string $sectionname
     * @param array $courseactivities
     * @return array
     */
    public function generate_book(
        string $coursename,
        string $bookname,
        string $bookdescription,
        string $academiclevel = '',
        int $chaptercount = 5,
        string $targetlength = '',
        string $sectionname = '',
        array $courseactivities = []
    ): array {
        return $this->request('/api/ma/generate-book', [
            'coursename' => $coursename,
            'bookname' => $bookname,
            'bookdescription' => $bookdescription,
            'academic_level' => $academiclevel,
            'chapter_count' => $chaptercount,
            'target_length' => $targetlength,
            'section_name' => $sectionname,
            'course_activities' => $courseactivities,
        ]);
    }

    /**
     * Generate URL resource recommendations.
     *
     * @param string $coursename
     * @param string $urlname
     * @param string $urldescription
     * @param string $academiclevel
     * @param int $resourcecount
     * @param string $sectionname
     * @param array $courseactivities
     * @return array
     */
    public function generate_url(
        string $coursename,
        string $urlname,
        string $urldescription,
        string $academiclevel = '',
        int $resourcecount = 5,
        string $sectionname = '',
        array $courseactivities = []
    ): array {
        return $this->request('/api/ma/generate-url', [
            'coursename' => $coursename,
            'urlname' => $urlname,
            'urldescription' => $urldescription,
            'academic_level' => $academiclevel,
            'resource_count' => $resourcecount,
            'section_name' => $sectionname,
            'course_activities' => $courseactivities,
        ]);
    }

    /**
     * Log user feedback on AI-generated content.
     *
     * @param string $action
     * @param string $moduletype
     * @param string $activityname
     * @param string $coursename
     * @return array
     */
    public function log_generation_feedback(
        string $action,
        string $moduletype,
        string $activityname = '',
        string $coursename = ''
    ): array {
        return $this->request('/api/ma/generation-feedback', [
            'action' => $action,
            'module_type' => $moduletype,
            'activity_name' => $activityname,
            'course_name' => $coursename,
        ], 'POST', 15);
    }

    /**
     * Test connection to the dashboard.
     *
     * @return array
     */
    public function test_connection(): array {
        return $this->request('/api/ma/test-connection', [], 'GET', 15);
    }

    /**
     * Get account info (tier, usage).
     *
     * @return array
     */
    public function get_account(): array {
        return $this->request('/api/ma/account', [], 'GET', 15);
    }
}
