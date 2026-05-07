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
 * Mastermind Assistant block.
 *
 * @package    block_mastermind_assistant
 * @copyright  2026 The Namers <info@mastermindassistant.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Mastermind Assistant block class.
 */
class block_mastermind_assistant extends block_base {
    /**
     * Initialize the block.
     */
    public function init() {
        $this->title = get_string('pluginname', 'block_mastermind_assistant');
    }

    /**
     * Specialize the title based on context.
     */
    public function specialization() {
        if (empty($this->config)) {
            $this->config = new stdClass();
        }

        // Set title based on context.
        if ($this->is_course_management_page()) {
            $this->title = get_string('pluginname', 'block_mastermind_assistant');
        } else if ($this->is_mod_edit_page()) {
            $this->title = get_string('ai_content_assistant', 'block_mastermind_assistant');
        } else {
            // Course view - show as "Insights".
            $this->title = get_string('insights_title', 'block_mastermind_assistant');
        }
    }

    /**
     * Where the block can be added.
     *
     * @return array
     */
    public function applicable_formats() {
        return [
            'all' => true,
        ];
    }

    /**
     * Allow multiple instances of the block on the same page.
     *
     * @return bool
     */
    public function instance_allow_multiple() {
        return false;
    }

    /**
     * Does this block have global settings?
     *
     * @return bool
     */
    public function has_config() {
        return true;
    }

    /**
     * Generate the block content with course-specific insights.
     *
     * @return stdClass
     */
    public function get_content() {
        global $COURSE;

        if ($this->content !== null) {
            return $this->content;
        }

        $this->content = new stdClass();

        // Locked state: when the plugin is not yet configured, show a connect CTA
        // on every page type. Site admins see the "Connect" button; regular
        // teachers see a short message telling them to ask their administrator.
        // Dashboard URL is always available via api_client::get_dashboard_url()
        // (which defaults to the DASHBOARD_URL constant, overridable via
        // $CFG->forced_plugin_settings); only api_key gates the "configured" state.
        $apikey = get_config('block_mastermind_assistant', 'api_key');
        if (empty($apikey)) {
            return $this->get_connect_content();
        }

        // Check if we're on the course management page.
        if ($this->is_course_management_page()) {
            return $this->get_course_management_content();
        }

        // Check if we're on a supported mod page — show AI assistant.
        if ($this->is_mod_edit_page()) {
            return $this->get_mod_draft_content();
        }

        // If we're on any other mod page (unsupported activity), hide the block.
        if ($this->is_mod_page()) {
            $this->content->text = '';
            $this->content->footer = '';
            return $this->content;
        }

        // Site-level page: show link to course management.
        if (empty($COURSE) || $COURSE->id == SITEID) {
            $systemcontext = context_system::instance();
            if (
                has_capability('moodle/course:create', $systemcontext) ||
                has_capability('moodle/category:manage', $systemcontext)
            ) {
                $links = [[
                    'url' => (new \moodle_url('/course/management.php'))->out(false),
                    'label' => get_string('nav_manage_courses', 'block_mastermind_assistant'),
                    'icon' => 'folder',
                ]];

                $data = (object) ['nav_links' => $links];
                $this->content->text = $this->page->get_renderer('block_mastermind_assistant')
                    ->render_from_template('block_mastermind_assistant/site_nav', $data);
            } else {
                $this->content->text = '';
            }
            $this->content->footer = '';
            return $this->content;
        }

        // Check capability.
        $context = context_course::instance($COURSE->id);
        if (!has_capability('block/mastermind_assistant:view', $context)) {
            // Avoid rendering anything to students.
            $this->content->text = '';
            $this->content->footer = '';
            return $this->content;
        }

        // Test OpenAI connection.
        $aistatus = self::test_ai_connection();

        $metrics = self::calculate_metrics($COURSE->id);

        $data = (object) [
            'courseid' => $COURSE->id,
            'completionrate' => $metrics['completionrate'],
            'avggrade' => $metrics['avggrade'],
            'dropoffsection' => $metrics['dropoffsection'],
            'forumactivity' => $metrics['forumactivity'],
            'lastactivity' => $metrics['lastactivity'],
            'resources' => $metrics['resources'],
            'ai_status' => $aistatus['status'],
            'ai_message' => $aistatus['message'],
        ];

        $this->content->text = $this->page->get_renderer('block_mastermind_assistant')
            ->render_from_template('block_mastermind_assistant/content', $data);
        $this->content->footer = '';

        // Include the AMD module for handling recommendations button.
        $this->page->requires->js_call_amd('block_mastermind_assistant/recommendations', 'init', [$COURSE->id]);

        return $this->content;
    }

    /**
     * Check if we're on any module page (view, edit, or settings).
     *
     * Used to hide the block on unsupported activity pages.
     *
     * @return bool
     */
    protected function is_mod_page(): bool {
        $pageurl = $this->page->url->out_as_local_url(false);

        // Any /mod/xxx/ page or the modedit.php settings page.
        return (strpos($pageurl, '/mod/') !== false || strpos($pageurl, '/course/modedit.php') !== false);
    }

    /**
     * Check if we're on the course management page.
     *
     * @return bool
     */
    protected function is_course_management_page(): bool {
        // Check if the page URL contains /course/management.php.
        $pageurl = $this->page->url->out_as_local_url(false);
        return (strpos($pageurl, '/course/management.php') !== false);
    }

    /**
     * Get content for course management page.
     *
     * @return stdClass
     */
    protected function get_course_management_content(): stdClass {
        // Check if user has capability to manage courses.
        $systemcontext = context_system::instance();
        if (!has_capability('moodle/course:create', $systemcontext)) {
            $this->content->text = '';
            $this->content->footer = '';
            return $this->content;
        }

        // Test OpenAI connection.
        $aistatus = self::test_ai_connection();

        $data = (object) [
            'is_course_management' => true,
            'ai_status' => $aistatus['status'],
            'ai_message' => $aistatus['message'],
            'is_ai_success' => ($aistatus['status'] === 'success'),
            'is_ai_warning' => ($aistatus['status'] === 'warning'),
            'is_ai_error' => ($aistatus['status'] === 'error'),
        ];

        // Include custom CSS for professional styling.
        $this->content->text = $this->page->get_renderer('block_mastermind_assistant')
            ->render_from_template('block_mastermind_assistant/course_search', $data);
        $this->content->footer = '';

        // Load categories server-side for the filter dropdown.
        $categories = \core_course_category::make_categories_list();
        $catlist = [];
        foreach ($categories as $catid => $catname) {
            $catlist[] = ['id' => (int) $catid, 'name' => $catname];
        }

        // Include the AMD module for handling course search.
        $this->page->requires->js_call_amd('block_mastermind_assistant/course_search', 'init', [$catlist]);

        return $this->content;
    }

    /**
     * Test AI connection.
     *
     * @return array
     */
    protected static function test_ai_connection(): array {
        $apikey = get_config('block_mastermind_assistant', 'api_key');

        if (empty($apikey)) {
            return [
                'status' => 'warning',
                'message' => get_string('settings_not_configured', 'block_mastermind_assistant'),
            ];
        }

        return [
            'status' => 'success',
            'message' => get_string('connection_success', 'block_mastermind_assistant'),
        ];
    }

    /**
     * Calculate course metrics.
     *
     * @param int $courseid
     * @return array
     */
    protected static function calculate_metrics(int $courseid): array {
        global $DB;

        $metrics = [
            'completionrate'  => 0,
            'avggrade'        => 0,
            'dropoffsection'  => get_string('unknown', 'block_mastermind_assistant'),
            'forumactivity'   => 0,
            'lastactivity'    => get_string('no_activity', 'block_mastermind_assistant'),
            'resources'       => 0,
        ];

        // 1. Completion rate.
        $enrolledsql = "SELECT COUNT(u.id)
                          FROM {user} u
                          JOIN {user_enrolments} ue ON ue.userid = u.id
                          JOIN {enrol} e ON e.id = ue.enrolid AND e.courseid = ?
                         WHERE u.deleted = 0";
        $totallearners = (int)$DB->get_field_sql($enrolledsql, [$courseid]);

        if ($totallearners) {
            $completed = $DB->count_records_select(
                'course_completions',
                'course = ? AND timecompleted IS NOT NULL',
                [$courseid]
            );
            $metrics['completionrate'] = round($completed / $totallearners * 100);
        }

        // 2. Average final grade.
        if ($gradeitem = $DB->get_record('grade_items', ['courseid' => $courseid, 'itemtype' => 'course'])) {
            $avggrade = $DB->get_field_sql(
                'SELECT AVG(finalgrade) FROM {grade_grades} WHERE itemid = ? AND finalgrade IS NOT NULL',
                [$gradeitem->id]
            );
            if ($avggrade !== null && $gradeitem->grademax > 0) {
                $scale = $gradeitem->grademax - $gradeitem->grademin;
                if ($scale > 0) {
                    $metrics['avggrade'] = round(($avggrade - $gradeitem->grademin) / $scale * 100);
                }
            }
        }

        // 3. Forum activity (posts per learner).
        $forumposts = $DB->get_field_sql("SELECT COUNT(fp.id)
                                             FROM {forum_posts} fp
                                             JOIN {forum_discussions} fd ON fd.id = fp.discussion AND fd.course = ?", [$courseid]);
        if ($totallearners) {
            $metrics['forumactivity'] = round($forumposts / $totallearners, 1);
        }

        // 4. Drop-off section (lowest average completion across activities).
        $sections = $DB->get_records('course_sections', ['course' => $courseid], '', 'id,section');
        $lowest = 101;
        $lowestsectionnum = null;
        foreach ($sections as $section) {
            $cmids = $DB->get_fieldset_sql(
                'SELECT id FROM {course_modules} WHERE course = ? AND section = ? AND visible = 1',
                [$courseid, $section->id]
            );
            if (!$cmids) {
                continue;
            }

            [$insql, $inparams] = $DB->get_in_or_equal($cmids, SQL_PARAMS_NAMED);
            $completed = $DB->count_records_select(
                'course_modules_completion',
                "coursemoduleid $insql AND completionstate = 1",
                $inparams
            );
            $possible = $DB->count_records_select(
                'course_modules_completion',
                "coursemoduleid $insql",
                $inparams
            );
            if ($possible) {
                $rate = $completed / $possible * 100;
                if ($rate < $lowest) {
                    $lowest = $rate;
                    $lowestsectionnum = $section->section;
                }
            }
        }
        if ($lowestsectionnum !== null) {
            $metrics['dropoffsection'] = get_section_name($courseid, $lowestsectionnum);
        }

        // 5. Last Activity — how recently the most recent student was active.
        // Restrict to last 90 days to avoid full table scan on large log tables.
        $ninetydaysago = time() - (90 * DAYSECS);
        $lastactivitytime = $DB->get_field_sql(
            "SELECT MAX(timecreated) FROM {logstore_standard_log}
              WHERE courseid = ? AND userid != 0 AND timecreated > ?",
            [$courseid, $ninetydaysago]
        );
        if ($lastactivitytime) {
            $diff = time() - $lastactivitytime;
            if ($diff < 3600) {
                $metrics['lastactivity'] = max(1, round($diff / 60)) . 'm ago';
            } else if ($diff < 86400) {
                $metrics['lastactivity'] = round($diff / 3600) . 'h ago';
            } else {
                $metrics['lastactivity'] = round($diff / 86400) . 'd ago';
            }
        }

        // 6. Resources — total count of visible activities/resources.
        $metrics['resources'] = (int) $DB->count_records_select(
            'course_modules',
            'course = ? AND visible = 1 AND deletioninprogress = 0',
            [$courseid]
        );

        return $metrics;
    }

    /**
     * Convert seconds to Hh Mm format.
     *
     * @param int $seconds
     * @return string
     */
    protected static function format_duration(int $seconds): string {
        if ($seconds <= 0) {
            return get_string('unknown', 'block_mastermind_assistant');
        }
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        return sprintf('%dh %02dm', $hours, $minutes);
    }

    /**
     * Check if we're on a mod edit page or mod view page for supported activities.
     *
     * Only returns true for activity types the plugin actually supports.
     *
     * @return bool
     */
    protected function is_mod_edit_page(): bool {
        global $DB;

        $pageurl = $this->page->url->out_as_local_url(false);
        $supported = ['page', 'quiz', 'assign', 'forum', 'lesson', 'glossary', 'book', 'url'];

        // Settings edit form — only for supported module types.
        if (strpos($pageurl, '/course/modedit.php') !== false) {
            $cmid = optional_param('update', 0, PARAM_INT);
            $add = optional_param('add', '', PARAM_ALPHA);
            if ($add) {
                return in_array($add, $supported);
            }
            if ($cmid) {
                $cm = get_coursemodule_from_id('', $cmid, 0, false, IGNORE_MISSING);
                if ($cm) {
                    $modname = $DB->get_field('modules', 'name', ['id' => $cm->module]);
                    return in_array($modname, $supported);
                }
            }
            return false;
        }

        // Module-specific pages where AI assistance is shown.
        $patterns = [
            '/mod/page/view.php',
            '/mod/quiz/view.php',
            '/mod/quiz/edit.php',
            '/mod/assign/view.php',
            '/mod/forum/view.php',
            '/mod/lesson/edit.php',
            '/mod/glossary/view.php',
            '/mod/book/view.php',
            '/mod/book/edit.php',
            '/mod/url/view.php',
        ];

        foreach ($patterns as $pattern) {
            if (strpos($pageurl, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Render the locked "Connect to Mastermind" state when the plugin is not configured.
     *
     * @return stdClass
     */
    protected function get_connect_content(): stdClass {
        $cancourseconfigure = has_capability('moodle/site:config', context_system::instance());

        $data = (object) [
            'can_configure' => $cancourseconfigure,
        ];

        $this->content->text = $this->page->get_renderer('block_mastermind_assistant')
            ->render_from_template('block_mastermind_assistant/connect', $data);
        $this->content->footer = '';

        if ($cancourseconfigure) {
            $returnurl = $this->page->url->out_as_local_url(false);
            $this->page->requires->js_call_amd(
                'block_mastermind_assistant/connect',
                'init',
                ['mastermind-connect-btn', $returnurl]
            );
        }

        return $this->content;
    }

    /**
     * Detect module from course module ID.
     *
     * @param object $cm Course module record.
     * @return array [modname, pagename, pagedescription, instanceid]
     */
    protected function load_module_data($cm): array {
        global $DB;
        $modname = $DB->get_field('modules', 'name', ['id' => $cm->module]);
        $supported = ['page', 'quiz', 'assign', 'forum', 'lesson', 'glossary', 'book', 'url'];
        if (!in_array($modname, $supported)) {
            return ['', '', '', 0];
        }
        $instance = $DB->get_record($modname, ['id' => $cm->instance]);
        if (!$instance) {
            return [$modname, '', '', 0];
        }
        return [$modname, $instance->name, $instance->intro ?? '', $instance->id];
    }

    /**
     * Get content for mod draft page (AI content assistant for activities).
     *
     * @return stdClass
     */
    protected function get_mod_draft_content(): stdClass {
        global $COURSE, $DB;

        // Check if user has capability to edit.
        $context = context_course::instance($COURSE->id);
        if (!has_capability('moodle/course:manageactivities', $context)) {
            $this->content->text = '';
            $this->content->footer = '';
            return $this->content;
        }

        $cmid = optional_param('update', 0, PARAM_INT);
        $add = optional_param('add', '', PARAM_ALPHA);
        $id = optional_param('id', 0, PARAM_INT);

        // Also check cmid parameter (quiz/edit.php, lesson/edit.php).
        if (!$cmid && !$id) {
            $cmid = optional_param('cmid', 0, PARAM_INT);
            if ($cmid) {
                $id = $cmid;
            }
        }

        $modname = '';
        $pagename = '';
        $pagedescription = '';
        $instanceid = 0;
        $cm = null;

        if ($cmid) {
            // Editing existing module via modedit.php.
            $cm = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);
            [$modname, $pagename, $pagedescription, $instanceid] = $this->load_module_data($cm);
        } else if ($add) {
            $modname = $add;
        } else if ($id) {
            // View page — look up the course module once, then check if it's a supported type.
            $cm = get_coursemodule_from_id('', $id, 0, false, IGNORE_MISSING);
            if ($cm) {
                [$modname, $pagename, $pagedescription, $instanceid] = $this->load_module_data($cm);
            }
        }

        // Only show for supported module types.
        $supportedmodules = ['page', 'quiz', 'assign', 'forum', 'lesson', 'glossary', 'book', 'url'];
        if (!in_array($modname, $supportedmodules)) {
            $this->content->text = '';
            $this->content->footer = '';
            return $this->content;
        }

        $aistatus = self::test_ai_connection();
        $pageurl = $this->page->url->out_as_local_url(false);

        // For Page, Assign, URL — we need the settings edit page.
        // For Forum, Glossary, Book, Lesson — they work on their main pages.
        $directactionmodules = ['forum', 'glossary', 'book', 'lesson'];
        $isdirectaction = in_array($modname, $directactionmodules);
        $isonedit = (strpos($pageurl, '/course/modedit.php') !== false);

        // For editor-based modules, build edit URL if on view page.
        $editurl = '';
        if (!$isdirectaction && !$isonedit && $id) {
            $editurl = (new \moodle_url('/course/modedit.php', ['update' => $id, 'return' => 1]))->out(false);
        }

        // For direct-action modules on their settings page, redirect to main page.
        $mainpageurl = '';
        if ($isdirectaction && $isonedit && $cm) {
            $mainpageurl = (new \moodle_url('/mod/' . $modname . '/view.php', ['id' => $cm->id]))->out(false);
            if ($modname === 'lesson') {
                $mainpageurl = (new \moodle_url('/mod/lesson/edit.php', ['id' => $cm->id]))->out(false);
            }
        }

        // Get existing content counts for direct-action modules.
        $existingcount = 0;
        if ($isdirectaction && $instanceid) {
            if ($modname === 'forum') {
                $existingcount = (int) $DB->count_records('forum_discussions', ['forum' => $instanceid]);
            } else if ($modname === 'glossary') {
                $existingcount = (int) $DB->count_records('glossary_entries', ['glossaryid' => $instanceid]);
            } else if ($modname === 'book') {
                $existingcount = (int) $DB->count_records('book_chapters', ['bookid' => $instanceid]);
            } else if ($modname === 'lesson') {
                $existingcount = (int) $DB->count_records('lesson_pages', ['lessonid' => $instanceid]);
            }
        }

        $data = (object) [
            'is_mod_draft' => true,
            'is_page' => ($modname === 'page'),
            'is_quiz' => ($modname === 'quiz'),
            'is_assign' => ($modname === 'assign'),
            'is_forum' => ($modname === 'forum'),
            'is_lesson' => ($modname === 'lesson'),
            'is_glossary' => ($modname === 'glossary'),
            'is_book' => ($modname === 'book'),
            'is_url' => ($modname === 'url'),
            'courseid' => $COURSE->id,
            'coursename' => $COURSE->fullname,
            'modname' => ucfirst($modname),
            'modname_lower' => $modname,
            'pagename' => $pagename,
            'pagedescription' => $pagedescription,
            'cmid' => $cm ? $cm->id : 0,
            'instanceid' => $instanceid,
            'quizid' => $cm ? $cm->id : 0,
            'is_new' => empty($cmid) && empty($instanceid),
            'ai_status' => $aistatus['status'],
            'ai_message' => $aistatus['message'],
            'is_ai_success' => ($aistatus['status'] === 'success'),
            'is_on_edit_page' => $isonedit,
            'is_direct_action' => $isdirectaction,
            'edit_url' => $editurl,
            'main_page_url' => $mainpageurl,
            'existing_count' => $existingcount,
        ];

        $this->content->text = $this->page->get_renderer('block_mastermind_assistant')
            ->render_from_template('block_mastermind_assistant/mod_draft', $data);
        $this->content->footer = '';

        $this->page->requires->js_call_amd('block_mastermind_assistant/mod_draft', 'init', [$COURSE->id]);

        return $this->content;
    }
}
