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
 * Course search, copy, and AI-powered creation module
 *
 * @module     block_mastermind_assistant/course_search
 * @copyright  2026 The Namers <info@mastermindassistant.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['jquery', 'core/ajax', 'core/notification', 'block_mastermind_assistant/ai_policy', 'core/str'],
function($, Ajax, Notification, AiPolicy, Str) {

    var searchTimeout = null;
    var $searchInput = null;
    var $searchResults = null;
    var $searchLoading = null;
    var allCategories = [];
    var $creationProgress = null;
    var $copyProgress = null;
    var $postCopyGuide = null;
    var $filterCategory = null;
    var $filterYear = null;

    // Document upload elements.
    var $uploadArea = null;
    var $uploadDropzone = null;
    var $documentInput = null;
    var $uploadSelected = null;
    var $docCreationProgress = null;
    var selectedFile = null;

    // Preview modal state.
    var pendingStructure = null;

    // Refinement conversation — lives in memory for the modal's lifetime.
    var refineConversation = [];

    // Maximum conversation entries kept client-side (oldest pairs dropped).
    var MAX_CONVERSATION_ENTRIES = 8;

    var MAX_FILE_SIZE = 10 * 1024 * 1024; // 10 MB
    var ALLOWED_TYPES = [
        'application/pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'text/plain'
    ];
    var ALLOWED_EXTENSIONS = ['pdf', 'docx', 'txt'];

    // Activity type icon mapping.
    var ACTIVITY_ICONS = {
        'page': '&#128196;',
        'quiz': '&#10068;',
        'assignment': '&#128221;',
        'assign': '&#128221;',
        'forum': '&#128172;',
        'resource': '&#128193;',
        'label': '&#127991;',
        'lesson': '&#128218;',
        'workshop': '&#128736;',
        'wiki': '&#128214;',
        'glossary': '&#128218;',
        'feedback': '&#128202;'
    };

    /**
     * Initialize the course search interface
     * @param {Array} categories List of {id, name} objects from server
     */
    function init(categories) {
        $searchInput = $('#mastermind-course-search-input');
        $searchResults = $('#mastermind-search-results');
        $searchLoading = $('#mastermind-search-loading');
        $creationProgress = $('#mastermind-creation-progress');
        $copyProgress = $('#mastermind-copy-progress');
        $postCopyGuide = $('#mastermind-post-copy-guide');
        $filterCategory = $('#mastermind-filter-category');
        $filterYear = $('#mastermind-filter-year');

        allCategories = categories || [];
        loadCategories(categories);

        // Handle search input
        $searchInput.on('input', function() {
            triggerSearch();
        });

        // Handle filter changes
        $filterCategory.on('change', function() {
            triggerSearch();
        });
        $filterYear.on('input', function() {
            triggerSearch();
        });

        // Close results when clicking outside
        $(document).on('click', function(e) {
            if (!$(e.target).closest('.mastermind-course-search-container').length) {
                hideResults();
            }
        });

        // Document upload elements.
        $uploadArea = $('#mastermind-upload-area');
        $uploadDropzone = $('#mastermind-upload-dropzone');
        $documentInput = $('#mastermind-document-input');
        $uploadSelected = $('#mastermind-upload-selected');
        $docCreationProgress = $('#mastermind-doc-creation-progress');

        // Upload button click.
        $('#mastermind-upload-btn').on('click', function(e) {
            e.preventDefault();
            $documentInput.trigger('click');
        });

        // File input change.
        $documentInput.on('change', function() {
            if (this.files && this.files[0]) {
                handleFileSelection(this.files[0]);
            }
        });

        // Drag and drop.
        $uploadDropzone.on('dragover', function(e) {
            e.preventDefault();
            e.stopPropagation();
            $(this).addClass('mastermind-dropzone-active');
        });
        $uploadDropzone.on('dragleave drop', function(e) {
            e.preventDefault();
            e.stopPropagation();
            $(this).removeClass('mastermind-dropzone-active');
        });
        $uploadDropzone.on('drop', function(e) {
            var files = e.originalEvent.dataTransfer.files;
            if (files && files.length > 0) {
                handleFileSelection(files[0]);
            }
        });

        // Remove file.
        $('#mastermind-file-remove').on('click', function(e) {
            e.preventDefault();
            clearFileSelection();
        });

        // Create from document.
        $('#mastermind-create-from-doc-btn').on('click', function(e) {
            e.preventDefault();
            AiPolicy.checkAndProceed(function() {
                createCourseFromDocument();
            });
        });
    }

    /**
     * Trigger a debounced search
     */
    function triggerSearch() {
        clearTimeout(searchTimeout);
        hideResults();

        var query = $searchInput.val().trim();
        if (query.length < 2) {
            return;
        }

        searchTimeout = setTimeout(function() {
            searchCourses(query);
        }, 500);
    }

    /**
     * Populate category dropdown from server-provided data
     * @param {Array} categories List of {id, name} objects
     */
    function loadCategories(categories) {
        if (!categories || !categories.length) {
            return;
        }
        categories.forEach(function(cat) {
            $filterCategory.append(
                '<option value="' + cat.id + '">' + escapeHtml(cat.name) + '</option>'
            );
        });
    }

    /**
     * Search for courses
     * @param {string} query Search query
     */
    function searchCourses(query) {
        showLoading();

        var args = {query: query};
        var catVal = parseInt($filterCategory.val(), 10);
        if (catVal > 0) {
            args.categoryid = catVal;
        }
        var yearVal = $filterYear.val().trim();
        if (yearVal.length === 4 && /^\d{4}$/.test(yearVal)) {
            args.year = yearVal;
        }

        Ajax.call([{
            methodname: 'block_mastermind_assistant_search_courses',
            args: args,
            done: function(response) {
                hideLoading();
                displayResults(query, response);
            },
            fail: function(error) {
                hideLoading();
                Notification.exception(error);
            }
        }]);
    }

    /**
     * Format a Unix timestamp as a readable date
     * @param {number} ts Unix timestamp
     * @returns {string}
     */
    function formatDate(ts) {
        if (!ts) {
            return '-';
        }
        var d = new Date(ts * 1000);
        return d.toLocaleDateString(undefined, {year: 'numeric', month: 'short', day: 'numeric'});
    }

    /**
     * Display search results
     * @param {string} query Original search query
     * @param {object} response Search response
     */
    function displayResults(query, response) {
        $searchResults.empty();

        if (response.found && response.courses && response.courses.length > 0) {
            var $list = $('<div class="mastermind-search-results-list"></div>');

            response.courses.forEach(function(course) {
                var courseUrl = M.cfg.wwwroot + '/course/view.php?id=' + course.id;

                var metaHtml =
                    '<span class="mastermind-result-meta-item" title="Category">' +
                        escapeHtml(course.categoryname || '') +
                    '</span>' +
                    '<span class="mastermind-result-meta-item" title="Activities">' +
                        course.activitycount + ' activities' +
                    '</span>' +
                    '<span class="mastermind-result-meta-item" title="Enrolled">' +
                        course.enrolledcount + ' enrolled' +
                    '</span>' +
                    '<span class="mastermind-result-meta-item" title="Modified">' +
                        formatDate(course.timemodified) +
                    '</span>';

                var $item = $('<div class="mastermind-search-result-item"></div>');
                $item.html(
                    '<div class="mastermind-result-info">' +
                        '<h4 class="mastermind-result-title">' + escapeHtml(course.fullname) + '</h4>' +
                        '<p class="mastermind-result-shortname">' + escapeHtml(course.shortname) + '</p>' +
                        '<div class="mastermind-result-meta">' + metaHtml + '</div>' +
                    '</div>' +
                    '<div class="mastermind-result-actions">' +
                        '<a href="' + courseUrl + '" class="btn btn-outline-primary btn-sm mastermind-view-btn">View</a>' +
                        '<button class="btn btn-info btn-sm mastermind-copy-btn" ' +
                            'data-courseid="' + course.id + '" ' +
                            'data-coursename="' + escapeHtml(course.fullname) + '">Copy</button>' +
                    '</div>'
                );

                // Bind copy handler.
                $item.find('.mastermind-copy-btn').on('click', function(e) {
                    e.preventDefault();
                    var cid = $(this).data('courseid');
                    copyCourse(cid);
                });

                $list.append($item);
            });

            $searchResults.append($list);

        } else {
            // No courses found - suggest AI creation
            var $noResults = $('<div class="mastermind-no-results"></div>');
            $noResults.html(
                '<div class="mastermind-ai-suggestion">' +
                    '<h4 class="mastermind-suggestion-title">No course found</h4>' +
                    '<p class="mastermind-suggestion-text">Would you like to create "<strong>' +
                        escapeHtml(query) + '</strong>" with AI assistance?</p>' +
                    '<button class="btn btn-primary mastermind-create-btn" data-coursename="' +
                        escapeHtml(query) + '">&#9889; Create</button>' +
                '</div>'
            );

            $noResults.find('.mastermind-create-btn').on('click', function(e) {
                e.preventDefault();
                var courseName = $(this).data('coursename');
                AiPolicy.checkAndProceed(function() {
                    createCourseWithAI(courseName);
                });
            });

            $searchResults.append($noResults);
        }

        showResults();
    }

    /**
     * Copy an existing course
     * @param {number} courseId Source course ID
     */
    function copyCourse(courseId) {
        var categoryId = getCategoryIdFromPage();

        hideResults();
        $copyProgress.show();
        $searchInput.prop('disabled', true);

        Ajax.call([{
            methodname: 'block_mastermind_assistant_copy_course',
            args: {
                courseid: courseId,
                categoryid: categoryId
            },
            done: function(response) {
                $copyProgress.hide();
                $searchInput.prop('disabled', false);
                $searchInput.val('');

                if (response.success && response.courseurl) {
                    showPostCopyGuide(response.courseid, response.courseurl);
                } else {
                    var errorMsg = response.error || 'Failed to copy course. Please try again.';
                    Notification.alert('Error', errorMsg, 'OK');
                }
            },
            fail: function(error) {
                $copyProgress.hide();
                $searchInput.prop('disabled', false);
                Notification.exception(error);
            }
        }]);
    }

    /**
     * Show the post-copy guide with action links and audit findings
     * @param {number} newCourseId New course ID
     * @param {string} courseUrl New course URL
     */
    function showPostCopyGuide(newCourseId, courseUrl) {
        var editUrl = M.cfg.wwwroot + '/course/edit.php?id=' + newCourseId;
        var viewUrl = courseUrl;

        $('#mastermind-guide-dates-link').attr('href', editUrl);
        $('#mastermind-guide-analysis-link').attr('href', viewUrl);
        $('#mastermind-guide-view-btn').attr('href', viewUrl);

        $postCopyGuide.show();

        // Fetch audit findings for the newly copied course.
        fetchAuditFindings(newCourseId);
    }

    /**
     * Fetch and display audit findings for a course
     * @param {number} courseId Course ID to audit
     */
    function fetchAuditFindings(courseId) {
        var flags = null;
        var stringRequests = [
            {key: 'audit_past_due_date', component: 'block_mastermind_assistant'},
            {key: 'audit_old_year_reference', component: 'block_mastermind_assistant'},
            {key: 'audit_empty_section', component: 'block_mastermind_assistant'},
            {key: 'audit_no_students', component: 'block_mastermind_assistant'},
        ];

        Ajax.call([{
            methodname: 'block_mastermind_assistant_get_course_data',
            args: {courseid: courseId}
        }])[0].then(function(response) {
            if (!response.success) {
                return null;
            }
            var data = JSON.parse(response.data);
            flags = data.audit_flags || {};
            return Str.get_strings(stringRequests);
        }).then(function(strings) {
            if (!strings || !flags) {
                return null;
            }
            var items = [];

            if (flags.past_due_dates && flags.past_due_dates.length) {
                flags.past_due_dates.forEach(function(d) {
                    items.push({
                        icon: '&#x1F4C5;',
                        text: strings[0] + ': ' + escapeHtml(d.name) + ' (' + escapeHtml(d.duedate) + ')'
                    });
                });
            }
            if (flags.old_year_references && flags.old_year_references.length) {
                flags.old_year_references.forEach(function(name) {
                    items.push({icon: '&#x1F4C4;', text: strings[1] + ': ' + escapeHtml(name)});
                });
            }
            if (flags.empty_sections && flags.empty_sections.length) {
                flags.empty_sections.forEach(function(name) {
                    items.push({icon: '&#x1F4AD;', text: strings[2] + ': ' + escapeHtml(name)});
                });
            }
            if (flags.missing_enrollments) {
                items.push({icon: '&#x1F465;', text: strings[3]});
            }

            if (items.length > 0) {
                var $list = $('#mastermind-audit-list');
                $list.empty();
                items.forEach(function(item) {
                    $list.append(
                        '<div class="mastermind-audit-item">' +
                            '<span class="mastermind-audit-icon">' + item.icon + '</span>' +
                            '<span>' + item.text + '</span>' +
                        '</div>'
                    );
                });
                $('#mastermind-audit-findings').show();
            }
            return null;
        }).catch(function() {
            // Silently fail — audit is supplementary.
        });
    }

    /**
     * Create a course with AI — Phase 1: get preview
     * @param {string} courseName Course name
     */
    function createCourseWithAI(courseName) {
        hideResults();
        showCreationProgress();
        $searchInput.prop('disabled', true);

        Ajax.call([{
            methodname: 'block_mastermind_assistant_create_course_with_ai',
            args: {
                coursename: courseName,
                categoryid: getCategoryIdFromPage(),
                previewonly: true
            },
            done: function(response) {
                hideCreationProgress();
                $searchInput.prop('disabled', false);

                if (response.success && response.preview) {
                    var structure = JSON.parse(response.preview);
                    showCoursePreviewModal(structure);
                } else if (response.success && response.courseurl) {
                    // Fallback: if no preview returned, redirect directly.
                    window.location.href = response.courseurl;
                } else {
                    var errorMsg = response.error || 'Failed to create course. Please try again.';
                    Notification.alert('Error', errorMsg, 'OK');
                }
            },
            fail: function(error) {
                hideCreationProgress();
                $searchInput.prop('disabled', false);
                $searchInput.val('');
                Notification.exception(error);
            }
        }]);
    }

    /**
     * Create a course from the selected document — Phase 1: get preview
     */
    function createCourseFromDocument() {
        if (!selectedFile) {
            return;
        }

        var categoryId = getCategoryIdFromPage();

        // Read file as base64.
        var reader = new FileReader();
        reader.onload = function(e) {
            var base64Full = e.target.result;
            var base64Data = base64Full.split(',')[1];

            // Determine MIME type.
            var mimeType = selectedFile.type;
            if (!mimeType || ALLOWED_TYPES.indexOf(mimeType) === -1) {
                var fileExt = selectedFile.name.split('.').pop().toLowerCase();
                var mimeMap = {
                    'pdf': 'application/pdf',
                    'docx': 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    'txt': 'text/plain'
                };
                mimeType = mimeMap[fileExt] || 'application/octet-stream';
            }

            // Show progress, hide upload area.
            $uploadArea.hide();
            $docCreationProgress.show();
            $searchInput.prop('disabled', true);
            hideResults();

            Ajax.call([{
                methodname: 'block_mastermind_assistant_create_course_from_document',
                args: {
                    filedata: base64Data,
                    filetype: mimeType,
                    filename: selectedFile.name,
                    categoryid: categoryId,
                    previewonly: true
                },
                done: function(response) {
                    $docCreationProgress.hide();
                    $uploadArea.show();
                    $searchInput.prop('disabled', false);
                    clearFileSelection();

                    if (response.success && response.preview) {
                        var structure = JSON.parse(response.preview);
                        showCoursePreviewModal(structure);
                    } else if (response.success && response.courseurl) {
                        // Fallback: redirect directly.
                        window.location.href = response.courseurl;
                    } else {
                        var errorMsg = response.error || 'Failed to create course from document. Please try again.';
                        Notification.alert('Error', errorMsg, 'OK');
                    }
                },
                fail: function(error) {
                    $docCreationProgress.hide();
                    $uploadArea.show();
                    $searchInput.prop('disabled', false);
                    clearFileSelection();
                    Notification.exception(error);
                }
            }]);
        };

        reader.onerror = function() {
            Notification.alert('Error', 'Failed to read file. Please try again.', 'OK');
        };

        reader.readAsDataURL(selectedFile);
    }

    /**
     * Show the course structure preview modal
     * @param {object} structure AI-generated structure
     * @param {boolean} preserveConversation Keep the refinement conversation (refine re-render)
     */
    function showCoursePreviewModal(structure, preserveConversation) {
        pendingStructure = structure;

        // Fresh preview: start a new refinement conversation.
        if (!preserveConversation) {
            refineConversation = [];
        }

        // Remove any existing modal.
        $('#mastermind-course-preview-overlay').remove();

        var courseName = structure.course_name || 'Untitled Course';
        var courseDescription = structure.course_description || '';
        var sections = structure.sections || [];

        // Count total activities.
        var totalActivities = 0;
        sections.forEach(function(s) {
            totalActivities += (s.activities || []).length;
        });

        // Build sections HTML.
        var sectionsHtml = '';
        sections.forEach(function(section, idx) {
            var sectionName = section.section_name || ('Section ' + (idx + 1));
            var sectionDesc = section.description || '';
            var activities = section.activities || [];

            var activitiesHtml = '';
            activities.forEach(function(act) {
                var actType = (act.moodle_type || 'page').toLowerCase();
                var icon = ACTIVITY_ICONS[actType] || '&#128196;';
                var actName = act.activity_name || 'Untitled';
                var actDesc = act.description || '';

                activitiesHtml +=
                    '<div class="mastermind-course-preview-activity">' +
                        '<span class="mastermind-course-preview-act-icon">' + icon + '</span>' +
                        '<div class="mastermind-course-preview-act-info">' +
                            '<span class="mastermind-course-preview-act-name">' + escapeHtml(actName) + '</span>' +
                            '<span class="mastermind-course-preview-act-type">' + escapeHtml(actType) + '</span>' +
                            (actDesc ? '<span class="mastermind-course-preview-act-desc">' + escapeHtml(actDesc) + '</span>' : '') +
                        '</div>' +
                    '</div>';
            });

            sectionsHtml +=
                '<div class="mastermind-course-preview-section">' +
                    '<div class="mastermind-course-preview-section-header" data-section="' + idx + '">' +
                        '<span class="mastermind-course-preview-section-name">' + escapeHtml(sectionName) + '</span>' +
                        '<span class="mastermind-course-preview-section-meta">' +
                            activities.length + ' activit' + (activities.length === 1 ? 'y' : 'ies') +
                        '</span>' +
                        '<span class="toggle-icon-updated">&#9654;</span>' +
                    '</div>' +
                    '<div class="mastermind-course-preview-section-body" data-section-body="' + idx + '">' +
                        (sectionDesc
                            ? '<p class="mastermind-course-preview-section-desc">' + escapeHtml(sectionDesc) + '</p>'
                            : '') +
                        activitiesHtml +
                    '</div>' +
                '</div>';
        });

        // Build modal HTML.
        var modalHtml =
            '<div id="mastermind-course-preview-overlay" class="mastermind-preview-overlay">' +
                '<div class="mastermind-preview-modal" style="max-width: 800px;">' +
                    '<div class="mastermind-preview-header">' +
                        '<h3 class="mastermind-preview-title">Preview Course Structure</h3>' +
                        '<button class="mastermind-close-modal-btn" id="mastermind-course-preview-close">&times;</button>' +
                    '</div>' +
                    '<div class="mastermind-course-preview-body">' +
                        '<div class="mastermind-course-preview-info">' +
                            '<h4 class="mastermind-course-preview-coursename">' + escapeHtml(courseName) + '</h4>' +
                            '<div class="mastermind-course-preview-stats">' +
                                '<span class="mastermind-course-preview-stat">' + sections.length + ' sections</span>' +
                                '<span class="mastermind-course-preview-stat">' + totalActivities + ' activities</span>' +
                            '</div>' +
                            (courseDescription ?
                                '<div class="mastermind-course-preview-desc">' +
                                    '<h5>Description</h5>' +
                                    '<p>' + escapeHtml(courseDescription) + '</p>' +
                                '</div>' : '') +
                        '</div>' +
                        '<div class="mastermind-course-preview-sections">' +
                            sectionsHtml +
                        '</div>' +
                    '</div>' +
                    '<div class="mastermind-refine-bar">' +
                        '<textarea id="mastermind-course-refine-feedback" class="mastermind-refine-textarea" rows="2"' +
                            ' placeholder="Tell the assistant what to change or add context..."></textarea>' +
                        '<button class="mastermind-secondary-button mastermind-refine-btn"' +
                            ' id="mastermind-course-refine-btn">Regenerate &mdash; uses 1 course creation action</button>' +
                    '</div>' +
                    '<div class="mastermind-preview-footer">' +
                        '<button class="mastermind-secondary-button mastermind-preview-btn"' +
                            ' id="mastermind-course-preview-cancel">Cancel</button>' +
                        '<button class="mastermind-action-button mastermind-preview-btn" id="mastermind-course-preview-create">' +
                            '&#9889; Create Course</button>' +
                    '</div>' +
                '</div>' +
            '</div>';

        // Append to document body (avoid narrow block container).
        $(document.body).append(modalHtml);

        // Bind section toggle.
        $('.mastermind-course-preview-section-header').on('click', function() {
            var secIdx = $(this).data('section');
            var $body = $('[data-section-body="' + secIdx + '"]');
            var $icon = $(this).find('.toggle-icon-updated');
            $body.toggleClass('expanded');
            $icon.toggleClass('expanded');
        });

        // Close handlers.
        $('#mastermind-course-preview-close, #mastermind-course-preview-cancel').on('click', function() {
            closeCoursePreviewModal();
        });

        // Click outside to close.
        $('#mastermind-course-preview-overlay').on('click', function(e) {
            if ($(e.target).is('#mastermind-course-preview-overlay')) {
                closeCoursePreviewModal();
            }
        });

        // Escape key to close.
        $(document).on('keydown.coursePreview', function(e) {
            if (e.key === 'Escape') {
                closeCoursePreviewModal();
            }
        });

        // Create button.
        $('#mastermind-course-preview-create').on('click', function() {
            confirmCourseCreation();
        });

        // Refinement bar: localized strings + regenerate handler.
        Str.get_strings([
            {key: 'refine_placeholder', component: 'block_mastermind_assistant'},
            {key: 'refine_course_button', component: 'block_mastermind_assistant'}
        ]).then(function(strings) {
            $('#mastermind-course-refine-feedback').attr('placeholder', strings[0]);
            $('#mastermind-course-refine-btn').text(strings[1]);
            return strings;
        }).catch(function() {
            // Keep English fallbacks.
        });

        $('#mastermind-course-refine-btn').on('click', function(e) {
            e.preventDefault();
            refineCoursePreview();
        });
    }

    /**
     * Send the user's refinement feedback to the dashboard and re-render the
     * preview modal with the refined structure. Billed as one course creation
     * action. The course is only created when the Create button is clicked.
     */
    function refineCoursePreview() {
        if (!pendingStructure) {
            return;
        }

        var $textarea = $('#mastermind-course-refine-feedback');
        var $refineBtn = $('#mastermind-course-refine-btn');
        var feedback = ($textarea.val() || '').trim();
        if (!feedback) {
            return;
        }

        // Existing loading pattern: disable inputs, show processing label.
        var originalLabel = $refineBtn.html();
        $textarea.prop('disabled', true);
        $refineBtn.prop('disabled', true);
        $('#mastermind-course-preview-create').prop('disabled', true);
        Str.get_string('processing', 'block_mastermind_assistant').then(function(s) {
            $refineBtn.text(s);
            return s;
        }).catch(function() {
            $refineBtn.text('Processing...');
        });

        var payload = {
            // eslint-disable-next-line camelcase
            course_name: pendingStructure.course_name || '',
            // eslint-disable-next-line camelcase
            previous_result: pendingStructure,
            conversation: refineConversation,
            feedback: feedback
        };

        Ajax.call([{
            methodname: 'block_mastermind_assistant_refine_course',
            args: {payload: JSON.stringify(payload)},
            timeout: 600000,
            done: function(response) {
                if (response.success && response.preview) {
                    var refined = JSON.parse(response.preview);
                    appendCourseRefineConversation(feedback, refined);
                    // Re-render the modal with the refined structure; the
                    // Create button always applies the displayed structure.
                    showCoursePreviewModal(refined, true);
                } else {
                    showCourseRefineError(response.error);
                    $textarea.prop('disabled', false);
                    $refineBtn.prop('disabled', false).html(originalLabel);
                    $('#mastermind-course-preview-create').prop('disabled', false);
                }
            },
            fail: function(error) {
                showCourseRefineError(error && error.message);
                $textarea.prop('disabled', false);
                $refineBtn.prop('disabled', false).html(originalLabel);
                $('#mastermind-course-preview-create').prop('disabled', false);
            }
        }]);
    }

    /**
     * Append a user/assistant exchange to the refinement conversation,
     * capping it at MAX_CONVERSATION_ENTRIES by dropping the oldest pairs.
     *
     * @param {string} feedback The user's refinement feedback
     * @param {object} structure The refined course structure
     */
    function appendCourseRefineConversation(feedback, structure) {
        refineConversation.push({role: 'user', content: feedback});
        refineConversation.push({
            role: 'assistant',
            // Compact summary: course name + section names only.
            content: JSON.stringify({
                // eslint-disable-next-line camelcase
                course_name: structure.course_name || '',
                sections: (structure.sections || []).map(function(s) {
                    return s.section_name || '';
                })
            }).substring(0, 2000)
        });
        while (refineConversation.length > MAX_CONVERSATION_ENTRIES) {
            refineConversation.splice(0, 2);
        }
    }

    /**
     * Show a refinement error notification.
     *
     * @param {string} detail Optional server-provided error detail
     */
    function showCourseRefineError(detail) {
        Str.get_string('refine_error', 'block_mastermind_assistant').then(function(s) {
            Notification.alert('Error', detail ? s + ' (' + detail + ')' : s, 'OK');
            return s;
        }).catch(function() {
            Notification.alert('Error', detail || 'Could not refine the result. Please try again.', 'OK');
        });
    }

    /**
     * Close the course preview modal
     */
    function closeCoursePreviewModal() {
        $('#mastermind-course-preview-overlay').remove();
        $(document).off('keydown.coursePreview');
        pendingStructure = null;
    }

    /**
     * Phase 2: Create the course from the previewed structure
     */
    function confirmCourseCreation() {
        if (!pendingStructure) {
            return;
        }

        var $createBtn = $('#mastermind-course-preview-create');
        $createBtn.prop('disabled', true).html('&#9889; Creating course...');
        $('#mastermind-course-preview-cancel').prop('disabled', true);

        var categoryId = getCategoryIdFromPage();

        Ajax.call([{
            methodname: 'block_mastermind_assistant_create_course_from_structure',
            args: {
                structure: JSON.stringify(pendingStructure),
                categoryid: categoryId
            },
            done: function(response) {
                closeCoursePreviewModal();
                $searchInput.val('');

                if (response.success && response.courseurl) {
                    window.location.href = response.courseurl;
                } else {
                    var errorMsg = response.error || 'Failed to create course. Please try again.';
                    Notification.alert('Error', errorMsg, 'OK');
                }
            },
            fail: function(error) {
                $createBtn.prop('disabled', false).html('&#9889; Create Course');
                $('#mastermind-course-preview-cancel').prop('disabled', false);
                Notification.exception(error);
            }
        }]);
    }

    /**
     * Get category ID from current page URL
     * @returns {number} Category ID
     */
    function getCategoryIdFromPage() {
        var urlParams = new URLSearchParams(window.location.search);
        var urlCategoryId = parseInt(urlParams.get('categoryid'));
        if (urlCategoryId > 0) {
            return urlCategoryId;
        }
        // Fall back to the selected filter category.
        if ($filterCategory && $filterCategory.val()) {
            var filterVal = parseInt($filterCategory.val());
            if (filterVal > 0) {
                return filterVal;
            }
        }
        // Fall back to the first available category from the server.
        if (allCategories.length > 0) {
            return allCategories[0].id;
        }
        return 1;
    }

    /** Show the search results panel. */
    function showResults() {
        $searchResults.show();
    }

    /** Hide the search results panel. */
    function hideResults() {
        $searchResults.hide();
    }

    /** Show the loading indicator and hide results. */
    function showLoading() {
        $searchLoading.show();
        $searchResults.hide();
    }

    /** Hide the loading indicator. */
    function hideLoading() {
        $searchLoading.hide();
    }

    /** Show the course-creation progress UI. */
    function showCreationProgress() {
        $creationProgress.show();
    }

    /** Hide the course-creation progress UI. */
    function hideCreationProgress() {
        $creationProgress.hide();
    }

    /**
     * Escape HTML to prevent XSS
     * @param {string} text Text to escape
     * @returns {string} Escaped text
     */
    function escapeHtml(text) {
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    /**
     * Handle file selection from input or drag-and-drop
     * @param {File} file Selected file
     */
    function handleFileSelection(file) {
        if (!file) {
            return;
        }

        // Validate type by MIME or extension.
        var ext = file.name.split('.').pop().toLowerCase();
        if (ALLOWED_TYPES.indexOf(file.type) === -1 && ALLOWED_EXTENSIONS.indexOf(ext) === -1) {
            Notification.alert('Error', 'Unsupported file type. Please upload a PDF, DOCX, or TXT file.', 'OK');
            return;
        }

        // Validate size.
        if (file.size > MAX_FILE_SIZE) {
            Notification.alert('Error', 'File is too large. Maximum size is 10MB.', 'OK');
            return;
        }

        selectedFile = file;
        $('#mastermind-file-name').text(file.name);
        $('#mastermind-file-size').text(formatFileSize(file.size));
        $uploadDropzone.hide();
        $uploadSelected.show();
    }

    /**
     * Clear file selection and reset upload UI
     */
    function clearFileSelection() {
        selectedFile = null;
        $documentInput.val('');
        $uploadSelected.hide();
        $uploadDropzone.show();
    }

    /**
     * Format bytes as human-readable file size
     * @param {number} bytes File size in bytes
     * @returns {string}
     */
    function formatFileSize(bytes) {
        if (bytes < 1024) {
            return bytes + ' B';
        }
        if (bytes < 1024 * 1024) {
            return Math.round(bytes / 1024) + ' KB';
        }
        return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
    }

    return {
        init: init
    };
});
