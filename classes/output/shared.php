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
 * Renderer functions shared between multiple renderers.
 *
 * @package   theme_snap
 * @copyright Copyright (c) 2015 Blackboard Inc. (http://www.moodlerooms.com)
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace theme_snap\output;

defined('MOODLE_INTERNAL') || die();

use context_course;
use core_component;
use html_writer;
use moodle_url;
use stdClass;
use theme_snap\local;

class shared extends \renderer_base {

    /**
     * Taken from /format/renderer.php
     * Generate a summary of the activites in a section
     *
     * @param stdClass $section The course_section entry from DB
     * @param stdClass $course the course record from DB
     * @param array    $mods (argument not used)
     * @return stdClass
     */
    public static function section_activity_summary($section, $course, $mods) {
        global $CFG;

        require_once($CFG->libdir.'/completionlib.php');

        $modinfo = get_fast_modinfo($course);
        if (empty($modinfo->sections[$section->section])) {
            return '';
        }

        // Generate array with count of activities in this section.
        $sectionmods = array();
        $total = 0;
        $complete = 0;
        $cancomplete = isloggedin() && !isguestuser();
        $completioninfo = new \completion_info($course);
        foreach ($modinfo->sections[$section->section] as $cmid) {
            $thismod = $modinfo->cms[$cmid];
            if ($thismod->uservisible) {
                if (isset($sectionmods[$thismod->modname])) {
                    $sectionmods[$thismod->modname]['name'] = $thismod->modplural;
                    $sectionmods[$thismod->modname]['count']++;
                } else {
                    $sectionmods[$thismod->modname]['name'] = $thismod->modfullname;
                    $sectionmods[$thismod->modname]['count'] = 1;
                }
                if ($cancomplete && $completioninfo->is_enabled($thismod) != COMPLETION_TRACKING_NONE) {
                    $total++;
                    $completiondata = $completioninfo->get_data($thismod, true);
                    if ($completiondata->completionstate == COMPLETION_COMPLETE ||
                        $completiondata->completionstate == COMPLETION_COMPLETE_PASS) {
                        $complete++;
                    }
                }
            }
        }

        if (empty($sectionmods)) {
            // No sections.
            return '';
        }

        // Output section activities summary.
        $o = '';
        $o .= "<div class='section-summary-activities mdl-right'>";
        foreach ($sectionmods as $mod) {
            $o .= "<span class='activity-count'>";
            $o .= $mod['name'].': '.$mod['count'];
            $o .= "</span>";
        }
        $o .= "</div>";

        $a = false;

        // Output section completion data.
        if ($total > 0) {
            $a = new stdClass;
            $a->complete = $complete;
            $a->total = $total;
            $a->percentage = ($complete / $total) * 100;

            $o .= "<div class='section-summary-activities mdl-right'>";
            $o .= "<span class='activity-count'>".get_string('progresstotal', 'completion', $a)."</span>";
            $o .= "</div>";
        }

        $retobj = (object) array (
            'output' => $o,
            'progress' => $a,
            'complete' => $complete,
            'total' => $total
        );

        return $retobj;
    }

    /**
     * Add the Javascript to enable drag and drop upload to a course page
     *
     * @param object $course The currently displayed course
     * @param array $modnames The list of enabled (visible) modules on this site
     * @return void
     */
    protected static function dndupload_add_to_course($course, $modnames) {
        global $CFG, $PAGE;

        $showstatus = optional_param('notifyeditingon', false, PARAM_BOOL);

        // Get all handlers.
        $handler = new \dndupload_handler($course, $modnames);
        $jsdata = $handler->get_js_data();
        if (empty($jsdata->types) && empty($jsdata->filehandlers)) {
            return; // No valid handlers - don't enable drag and drop.
        }

        // Adding file handlers straight to footer, explanation below.
        $json = json_encode($handler->get_js_data());
        $script = <<<EOF
            <script>
                var themeSnapCourseFileHandlers = $json;
            </script>
EOF;

        if (!isset($CFG->additionalhtmlfooter)) {
            $CFG->additionalhtmlfooter = '';
        }
        // Note, we have to put the file handlers into the footer instead of passing them into the amd module as an
        // argument. If you pass large amounts of data into the amd arguments then it throws a debug error.
        $CFG->additionalhtmlfooter .= $script;

        // Add the javascript to the page.
        $PAGE->requires->strings_for_js([
            'addfilehere',
            'dndworkingfiletextlink',
            'dndworkingfilelink',
            'dndworkingfiletext',
            'dndworkingfile',
            'dndworkingtextlink',
            'dndworkingtext',
            'dndworkinglink',
            'namedfiletoolarge',
            'actionchoice',
            'servererror',
            'upload',
            'cancel'
        ], 'moodle');
        $PAGE->requires->strings_for_js([
            'modulename'
        ], 'mod_label');
        $vars = array(
            array('courseid' => $course->id,
                'maxbytes' => get_max_upload_file_size($CFG->maxbytes, $course->maxbytes),
                'showstatus' => $showstatus)
        );

        $PAGE->requires->js('/course/dndupload.js');
        $PAGE->requires->js_call_amd('theme_snap/dndupload-lazy', 'init', $vars);
    }


    /**
     * Include the relevant javascript and language strings for the resource
     * toolbox YUI module
     *
     * @param integer $id The ID of the course being applied to
     * @param array $usedmodules An array containing the names of the modules in use on the page
     * @param array $enabledmodules An array containing the names of the enabled (visible) modules on this site
     * @param stdClass $config An object containing configuration parameters for ajax modules including:
     *          * resourceurl   The URL to post changes to for resource changes
     *          * sectionurl    The URL to post changes to for section changes
     *          * pageparams    Additional parameters to pass through in the post
     * @return bool
     */
    protected static function include_course_ajax($course, $usedmodules = array(), $enabledmodules = null, $config = null) {
        global $CFG, $PAGE, $COURSE;

        // Only include course AJAX for supported formats.
        if (!course_ajax_enabled($course)) {
            return false;
        }

        // Require various strings for the command toolbox.
        $PAGE->requires->strings_for_js([
            'afterresource',
            'aftersection',
            'clicktochangeinbrackets',
            'confirmdeletesection',
            'deletechecktype',
            'deletechecktypename',
            'edittitle',
            'edittitleinstructions',
            'emptydragdropregion',
            'groupsnone',
            'groupsvisible',
            'groupsseparate',
            'hide',
            'markthistopic',
            'markedthistopic',
            'moveleft',
            'movesection',
            'movecoursemodule',
            'movecoursesection',
            'movecontent',
            'show',
            'tocontent',
            'totopofsection',
            'unknownerror',
            'ok',
            'cancel'
        ], 'moodle');

        $PAGE->requires->strings_for_js([
            'action:changeassetvisibility',
            'action:changesectionvisibility',
            'action:duplicateasset',
            'action:highlightsectionvisibility',
            'error:failedtochangesectionvisibility',
            'error:failedtohighlightsection',
            'error:failedtochangeassetvisibility',
            'error:failedtoduplicateasset',
            'deleteassetconfirm',
            'deletesectionconfirm',
            'deletingsection'
        ], 'theme_snap');

        // Include section-specific strings for formats which support sections.
        if (course_format_uses_sections($course->format)) {
            $PAGE->requires->strings_for_js(array(
                'showfromothers',
                'hidefromothers',
            ), 'format_' . $course->format);
        }

        // For confirming resource deletion we need the name of the module in question.
        foreach ($usedmodules as $module => $modname) {
            $PAGE->requires->string_for_js('pluginname', $module);
        }

        if ($COURSE->id !== SITEID) {
            // Load drag and drop upload AJAX.
            require_once($CFG->dirroot . '/course/dnduploadlib.php');
            self::dndupload_add_to_course($course, $enabledmodules);
        }

        return true;
    }

    /**
     * Javascript required by both standard header layout and flexpage layout
     *
     * @return void
     */
    public static function page_requires_js() {
        global $CFG, $PAGE, $COURSE, $USER, $OUTPUT;

        $PAGE->requires->jquery();
        $PAGE->requires->js_amd_inline("require(['theme_boost/loader']);");
        $PAGE->requires->strings_for_js(array(
            'coursecontacts',
            'debugerrors',
            'problemsfound',
            'error:coverimageexceedsmaxbytes',
            'error:coverimageresolutionlow',
            'forumtopic',
            'forumauthor',
            'forumpicturegroup',
            'forumreplies',
            'forumlastpost',
            'hiddencoursestoggle',
            'loading',
            'more',
            'moving',
            'movingcount',
            'movehere',
            'movefailed',
            'movingdropsectionhelp',
            'movingstartedhelp',
            'notpublished',
            'visibility'
        ), 'theme_snap');

        $PAGE->requires->strings_for_js([
            'ok',
            'cancel',
            'error',
            'unknownerror',
            'closebuttontitle',
            'modhide',
            'modshow',
            'hiddenoncoursepage',
            'showoncoursepage',
            'switchrolereturn'
        ], 'moodle');

        $PAGE->requires->strings_for_js([
            'printbook'
        ], 'booktool_print');

        $PAGE->requires->strings_for_js([
            'progresstotal'
        ], 'completion');

        // Are we viewing /course/view.php - note, this is different from just checking the page type.
        // We only ever want to load course.js when on site page or view.php - no point in loading it when on
        // course settings page, etc.
        $courseviewpage = local::current_url_path() === '/course/view.php';
        $pagehascoursecontent = ($PAGE->pagetype === 'site-index' || $courseviewpage);

        $cancomplete = isloggedin() && !isguestuser();
        $unavailablesections = [];
        $unavailablemods = [];
        if ($cancomplete) {
            $completioninfo = new \completion_info($COURSE);
            if ($completioninfo->is_enabled()) {
                $modinfo = get_fast_modinfo($COURSE);
                $sections = $modinfo->get_section_info_all();
                foreach ($sections as $number => $section) {
                    $ci = new \core_availability\info_section($section);
                    $information = '';
                    if (!$ci->is_available($information, true)) {
                        $unavailablesections[] = $number;
                    }
                }
                foreach ($modinfo as $mod) {
                    $ci = new \core_availability\info_module($mod);
                    if (!$ci->is_available($information, true)) {
                        $unavailablemods[] = $mod->id;
                    }
                }

            }
        }

        list ($unavailablesections, $unavailablemods) = local::conditionally_unavailable_elements($COURSE);

        $coursevars = (object) [
            'id' => $COURSE->id,
            'shortname' => $COURSE->shortname,
            'contextid' => $PAGE->context->id,
            'categoryid' => !empty($PAGE->category->id) ? $PAGE->category->id : false,
            'ajaxurl' => '/course/rest.php',
            'unavailablesections' => $unavailablesections,
            'unavailablemods' => $unavailablemods,
            'enablecompletion' => isloggedin() && $COURSE->enablecompletion
        ];

        $mprocs = get_message_processors(true);
        $forcepwdchange = (bool) get_user_preferences('auth_forcepasswordchange', false);
        $conversationbadgecountenabled = isloggedin() && isset($mprocs['badge']) && $PAGE->theme->settings->messagestoggle == 1;
        $userid = $USER->id;
        $manager = new \core_privacy\local\sitepolicy\manager();
        $policyurlexist = $manager->is_defined();
        $sitepolicyacceptreqd = isloggedin() && $policyurlexist && empty($USER->policyagreed) && !is_siteadmin();
        $inalternativerole = $OUTPUT->in_alternative_role();
        $initvars = [$coursevars, $pagehascoursecontent, get_max_upload_file_size($CFG->maxbytes), $forcepwdchange,
                     $conversationbadgecountenabled, $userid, $sitepolicyacceptreqd, $inalternativerole];
        $PAGE->requires->js_call_amd('theme_snap/snap', 'snapInit', $initvars);

        // Does the page have editable course content?
        if ($pagehascoursecontent && $PAGE->user_allowed_editing()) {
            $canmanageacts = has_capability('moodle/course:manageactivities', context_course::instance($COURSE->id));
            if ($canmanageacts && (empty($USER->editing) || $COURSE->id === SITEID)) {
                $modinfo = get_fast_modinfo($COURSE);
                $modnamesused = $modinfo->get_used_module_names();

                // Temporarily change edit mode to on for course ajax to be included.
                $originaleditstate = !empty($USER->editing) ? $USER->editing : false;
                $USER->editing = true;
                self::include_course_ajax($COURSE, $modnamesused);
                $USER->editing = $originaleditstate;
            }
        }
    }

    /**
     * Render a warning where flexpage is the course format for the front page.
     *
     * @author: Guy Thomas
     * @date: 2014-07-17
     * @param bool $adminsonly
     * @return string
     */
    public static function flexpage_frontpage_warning($adminsonly = false) {
        global $OUTPUT;

        if ($adminsonly) {
            if (!is_siteadmin()) {
                // Only for admin users.
                return '';
            }
        }

        // Check to see if the front page course has a format of flexpage.
        $fpage = get_site();
        if ($fpage->format != 'flexpage') {
            // Front page format is not flexpage.
            return '';
        }

        $url = new moodle_url('/admin/settings.php', ['section' => 'frontpagesettings']);

        // Output warning.
        return ($OUTPUT->notification(get_string('warnsiteformatflexpage',
                'theme_snap', $url->out())));
    }

    /**
     * Is the gradebook accessible - i.e. are there any reports accessible to this user
     * @return bool
     */
    public static function gradebook_accessible($context) {
        global $COURSE;

        // Ask if user has not capabilities and if course is set to not to show the grades to students.
        if ((!has_capability('gradereport/grader:view', $context)) && ($COURSE->showgrades == 0)) {
            return false;
        }

        // Find all enabled reports.
        $reports = core_component::get_plugin_list('gradereport');
        foreach (array_keys($reports) as $report) {
            if (!component_callback('gradereport_'.$report, 'is_enabled', array(), true)) {
                unset($reports[$report]);
            }
        }

        // Reduce reports list down to just those accessible to user.
        foreach ($reports as $plugin => $plugindir) {
            // Remove ones we can't see.
            if (!has_capability('gradereport/'.$plugin.':view', $context)) {
                unset($reports[$plugin]);
            }
        }
        return !empty($reports);
    }

    /**
     * generates a string list of links based on links array
     * structure of links array should be
     * array(
     *      array(
     *          'link'=>[url in a string]
     *          'title'=>[mandatory - anyold string title]
     *      )
     * )
     * note - couldn't use html_writer::alist function as it does not support sub lists
     *
     * @author Guy Thomas
     * @param array $links
     * @return string;
     */
    public static function render_appendices(array $links) {
        global $CFG, $COURSE;

        $o = '';
        foreach ($links as $item) {
            $item = (object) $item;
            // Make sure item link is the correct type of url.
            if (stripos($item->link, 'http') !== 0) {
                $item->link = $CFG->wwwroot.'/'.$item->link;
            }
            // Generate linkhtml.
            $o .= html_writer::link($item->link, $item->title);
        }
        return $o;
    }

    /**
     * generate list of course tools
     *
     * @author Guy Thomas
     * @date 2014-04-23
     * @return string
     */
    public static function appendices() {
        global $CFG, $COURSE, $PAGE, $OUTPUT, $DB;

        $links = array();
        $localplugins = core_component::get_plugin_list('local');
        $coursecontext = context_course::instance($COURSE->id);

        // Course enrolment link.
        $enrollink = '';
        $plugins   = enrol_get_plugins(true);
        $instances = enrol_get_instances($COURSE->id, true);
        $selfenrol = false;
        foreach ($instances as $instance) { // Need to check enrolment methods for self enrol.
            if ($instance->enrol === 'self') {
                $plugin = $plugins[$instance->enrol];
                if (is_enrolled($coursecontext)) {
                    // Prepare unenrolment link.
                    $enrolurl = $plugin->get_unenrolself_link($instance);
                    if ($enrolurl) {
                        $selfenrol = true;
                        $enrolstr = get_string('unenrolme', 'theme_snap');
                        break;
                    }
                } else {
                    if ($plugin->show_enrolme_link($instance)) {
                        // Prepare enrolment link.
                        $selfenrol = true;
                        $enrolurl = new moodle_url('/enrol/index.php', ['id' => $COURSE->id]);
                        $enrolstr = get_string('enrolme', 'core_enrol');
                        break;
                    }
                }
            }
        }
        if ($selfenrol) {
            $enrollink = '<div class="text-center"><a href="'.$enrolurl.'" class="btn btn-primary">'.$enrolstr.'</a></div><br>';
        }

        // Course settings.
        if (has_capability('moodle/course:update', $coursecontext)) {
            $iconurl = $OUTPUT->image_url('gear', 'theme');
            $coverimageurl = local::course_coverimage_url($COURSE->id);
            if (!empty($coverimageurl)) {
                $iconurl = $coverimageurl;
            }
            $settingsicon = '<img src="'.$iconurl.'" class="snap-cover-icon svg-icon" alt="" role="presentation">';

            $links[] = array(
                'link' => 'course/edit.php?id='.$COURSE->id,
                'title' => $settingsicon.get_string('editcoursesettings', 'theme_snap'),
            );
        }

        $iconurl = $OUTPUT->image_url('joule_grader', 'theme');
        $gradebookicon = '<img src="'.$iconurl.'" class="svg-icon" alt="" role="presentation">';

        // Joule grader if installed.
        if (array_key_exists('joulegrader', $localplugins)) {
            if (has_capability('local/joulegrader:grade', $coursecontext)
                || has_capability('local/joulegrader:view', $coursecontext)
            ) {
                $links[] = array(
                    'link' => 'local/joulegrader/view.php?courseid='.$COURSE->id,
                    'title' => $gradebookicon.get_string('pluginname', 'local_joulegrader'),
                );
            }
        }

        // Gradebook.
        if (self::gradebook_accessible($coursecontext)) {
            $iconurl = $OUTPUT->image_url('gradebook', 'theme');
            $gradebookicon = '<img src="'.$iconurl.'" class="svg-icon" alt="" role="presentation">';
            // Gradebook.
            $links[] = array(
                'link' => 'grade/index.php?id='.$COURSE->id,
                'title' => $gradebookicon.get_string('gradebook', 'grades')
            );
        }

        // Participants.
        if (course_can_view_participants($coursecontext)) {

            // Get count of course users.
            $usercount = count_enrolled_users($coursecontext, '', 0, true);

            // Build icon.
            $participanticons = '';
            if (!empty($usercount)) {
                // Get subset of users for icon.
                $usersubset = get_enrolled_users($coursecontext,
                        '', 0, 'u.*', 'picture desc, lastaccess desc', 0, 4, true);
                foreach ($usersubset as $user) {
                    $userpicture = new \user_picture($user);
                    $userpicture->link = false;
                    $userpicture->size = 100;
                    $participanticons .= $OUTPUT->render($userpicture);
                }
            } else {
                // Default icon when 0 participants.
                $iconurl = $OUTPUT->image_url('u/f1');
                $participanticons = '<img src="'.$iconurl.'" alt="" role="presentation">';
            }

            $participanticons = '<div class="snap-participant-icons">'.$participanticons.'</div>';
            $links[] = array(
                'link' => 'user/index.php?id='.$COURSE->id.'&mode=1',
                'title' => $participanticons.$usercount.' '.get_string('participants')
            );
        }

        // Joule reports if installed.
        if (array_key_exists('reports', core_component::get_plugin_list('block'))) {
            $iconurl = $OUTPUT->image_url('joule_reports', 'theme');
            $reportsicon = '<img src="'.$iconurl.'" class="svg-icon" alt="" role="presentation">';
            if (has_capability('block/reports:viewown', $coursecontext, null, false)
                || has_capability('block/reports:view', $coursecontext)
            ) {
                $links[] = array(
                    'link' => $CFG->wwwroot.'/blocks/reports/view.php?action=dashboard&courseid='.$COURSE->id,
                    'title' => $reportsicon.get_string('joulereports', 'block_reports')
                );
            }
        }

        // Personalised Learning Designer.
        if (array_key_exists('pld', $localplugins) && has_capability('local/pld:editcourserules', $coursecontext)) {
            $iconurl = $OUTPUT->image_url('pld', 'theme');
            $pldicon = '<img src="'.$iconurl.'" class="svg-icon" alt="" role="presentation">';
            $pldname = get_string('pld', 'theme_snap');
            $links[] = array(
                'link' => 'local/pld/view.php?courseid='.$COURSE->id,
                'title' => $pldicon.$pldname
            );
        }

        // Competencies if enabled.
        if (get_config('core_competency', 'enabled') && has_capability('moodle/competency:competencyview', $coursecontext)) {
            $iconurl = $OUTPUT->image_url('competencies', 'theme');
            $competenciesicon = '<img src="'.$iconurl.'" class="svg-icon" alt="" role="presentation">';
            $links[] = array(
                'link'  => 'admin/tool/lp/coursecompetencies.php?courseid='.$COURSE->id,
                'title' => $competenciesicon.get_string('competencies', 'core_competency')
            );
        }

        // Outcomes if enabled.
        if (!empty($CFG->core_outcome_enable)) {
            $iconurl = $OUTPUT->image_url('outcomes', 'theme');
            $outcomesicon = '<img src="'.$iconurl.'" class="svg-icon" alt="" role="presentation">';

            if (has_capability('moodle/grade:edit', $coursecontext)) {
                $links[] = array(
                    'link'  => 'outcome/course.php?contextid='.$coursecontext->id,
                    'title' => $outcomesicon.get_string('outcomes', 'outcome'),
                );
            } else if (!is_guest($coursecontext)) {
                $outcomesets = new \core_outcome\model\outcome_set_repository();
                if ($outcomesets->course_has_any_outcome_sets($COURSE->id)) {
                    $links[] = array(
                        'link'  => 'outcome/course.php?contextid='.$coursecontext->id.
                            '&action=report_course_user_performance_table',
                        'title' => $outcomesicon.get_string('outcomes', 'outcome'),
                    );
                }
            }
        }

        // Course badges.
        if (!empty($CFG->enablebadges) && !empty($CFG->badges_allowcoursebadges)) {
            // Match capabilities used by badges subsystem.
            $badgecaps = array(
                'moodle/badges:earnbadge',
                'moodle/badges:viewbadges',
                'moodle/badges:viewawarded',
                'moodle/badges:createbadge',
                'moodle/badges:awardbadge',
                'moodle/badges:configuremessages',
                'moodle/badges:configuredetails',
                'moodle/badges:deletebadge',
            );
            $canviewbadges = has_any_capability($badgecaps, $coursecontext);
            if (!is_guest($coursecontext) && $canviewbadges) {
                $iconurl = $OUTPUT->image_url('badges', 'theme');
                $badgesicon = '<img src="'.$iconurl.'" class="svg-icon" alt="" role="presentation">';
                $links[] = array(
                    'link' => 'badges/view.php?type=' . BADGE_TYPE_COURSE . '&id=' . $COURSE->id,
                    'title' => $badgesicon.get_string('badges', 'badges')
                );
            }
        }

        // Mediasite.
        if ($COURSE->id > 1 && has_capability('mod/mediasite:courses7', $coursecontext) &&
            \core_component::get_component_directory('mod_mediasite') !== null && is_callable('mr_on') &&
            mr_on("mediasite", "_MR_MODULES")) {
            require_once($CFG->dirroot . "/mod/mediasite/mediasitesite.php");
            $iconurl = $OUTPUT->image_url('icon', 'mediasite');
            $badgesicon = '<img src="'.$iconurl.'" class="svg-icon" alt="" role="presentation">';
            $courseconfig = $DB->get_record('mediasite_course_config', array('course' => $COURSE->id));
            if (!empty($courseconfig->mediasite_courses_enabled) && $courseconfig->mediasite_site) {
                $site = new MediasiteSite($courseconfig->mediasite_site);
                $url = new moodle_url(
                    '/mod/mediasite/courses7.php',
                    array('id' => $COURSE->id, 'siteid' => $courseconfig->mediasite_site)
                );
                $links[] = array(
                    'link' => $url->out_as_local_url(false),
                    'title' => $badgesicon . $site->get_integration_catalog_title()
                );
            } else {
                foreach (get_mediasite_sites(true, false) as $site) {
                    $url = new moodle_url('/mod/mediasite/courses7.php', array('id' => $COURSE->id, 'siteid' => $site->id));
                    $links[] = array(
                        'link' => $url->out_as_local_url(false),
                        'title' => $badgesicon . $site->integration_catalog_title
                    );
                }
            }
        }

        // Output course tools section.
        $coursetools = get_string('coursetools', 'theme_snap');
        $iconurl = $OUTPUT->image_url('course_dashboard', 'theme');
        $coursetoolsicon = '<img src="'.$iconurl.'" class="svg-icon" alt="" role="presentation">';
        $o = '<h2>'.$coursetoolsicon.$coursetools.'</h2>';
        $o .= $enrollink;
        $o .= self::print_student_dashboard();
        $o .= '<div id="coursetools-list">' .self::render_appendices($links). '</div><hr>';

        return $o;
    }

    /**
     * Course tools.
     *
     * @param bool $forceshow - force the tools section to be shown.
     * @return string
     */
    public static function course_tools($forceshow = false) {
        global $PAGE, $DB;

        $output = '';

        $showtools = $forceshow;

        if (!$showtools && stripos($PAGE->bodyclasses, 'format-singleactivity') !== false ) {
            // Display course tools in single activity mode, but only on main page.
            // Current test for main page is based on the pagetype matching a regex.
            // Would be nice if there was something more direct to test.
            if (preg_match('/^mod-.*-view$/', $PAGE->pagetype)) {
                $showtools = true;
            } else if ($PAGE->cm && $PAGE->cm->modname === 'hsuforum') {
                $mod = $DB->get_record('hsuforum', ['id' => $PAGE->cm->instance]);
                $showtools = $mod->type === 'single' && $PAGE->pagetype === 'mod-hsuforum-discuss';
            }
        }

        if ($showtools) {
            $output = '<section id="coursetools" class="clearfix" tabindex="-1">';
            $output .= self::appendices();
            $output .= '</section>';
        }

        return $output;
    }

    /**
     * User dashboard.
     * Shown to users in the course dashboard, initially their progress and grade.
     * Progress and Grade use a progress.js circle.
     *
     * @return string
     */
    public static function print_student_dashboard() {
        global $USER, $COURSE, $OUTPUT;

        $coursecontext = context_course::instance($COURSE->id);
        $output = '';

        // Don't output for teachers.
        if (has_capability('moodle/grade:viewall', $coursecontext)) {
            return $output;
        }
        // Don't output if gradebook is not accessible for this user.
        if (!self::gradebook_accessible($coursecontext)) {
            return $output;
        }

        $userpicture = new \user_picture($USER);
        $userpicture->link = false;
        $userpicture->alttext = false;
        $userpicture->class = 'userpicture snap-icon'; // Icon class for margin.
        $userpicture->size = 100;
        $userpic = $OUTPUT->render($userpicture);

        $userboard  = '<div id="snap-student-dashboard" class="row clearfix">';
        $userboard .= '<div class="col-xs-6">';
        $userboard .= '<h4 class="h6">' .s(fullname($USER)). '</h4>';
        $userboard .= $userpic;
        $userboard .= '</div>';

        // User progress.
        if ($COURSE->enablecompletion) {
            $progress = local::course_completion_progress($COURSE);
            $userboard .= '<div class="col-xs-3 text-center snap-student-dashboard-progress">';
            $userboard .= '<h4 class="h6">' .get_string('progress', 'theme_snap'). '</h6>';
            $userboard .= '<div class="js-progressbar-circle snap-progress-circle" value="' .round($progress->progress). '"></div>';
            $userboard .= '</div>';
        }

        // User grade.
        if (has_capability('gradereport/overview:view', $coursecontext)) {
            $grade = local::course_grade($COURSE, true);
            $coursegrade = '-';
            if (isset($grade->coursegrade['percentage'])) {
                $coursegrade = round($grade->coursegrade['percentage']);
            }

            $moodleurl = new moodle_url('/grade/report/user/index.php', ['id' => $COURSE->id, 'userid' => $USER->id]);

            $userboard .= '<div class="col-xs-3 text-center snap-student-dashboard-grade">';
            $userboard .= '<h4 class="h6">' . get_string('grade') . '</h6>';
            $userboard .= '<a href="' . $moodleurl . '">';
            $userboard .= '<div class="js-progressbar-circle snap-progress-circle snap-progressbar-link" value="';
            $userboard .= s($coursegrade) . '"></div>';
            $userboard .= '</a>';
            $userboard .= '</div>';
        }

        $userboard .= '</div><!- close .snap-user-dashboard ->';
        $userboard .= '<br>';

        $output .= $userboard;
        return $output;

    }
}
