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
 * Defines renderer for course format flexsections
 *
 * @package    format_flexsections
 * @copyright  2012 Marina Glancy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

require_once($CFG->dirroot . '/course/format/renderer.php');

/**
 * Renderer for flexsections format.
 *
 * @copyright 2012 Marina Glancy
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class format_flexsections_renderer extends plugin_renderer_base
{
    /** @var core_course_renderer Stores instances of core_course_renderer */
    protected $courserenderer = null;

    /**
     * Constructor
     *
     * @param moodle_page $page
     * @param string $target
     */
    public function __construct(moodle_page $page, $target)
    {
        parent::__construct($page, $target);
        $this->courserenderer = $page->get_renderer('core', 'course');
    }

    /**
     * Generate the section title (with link if section is collapsed)
     *
     * @param int|section_info $section
     * @param stdClass $course The course entry from DB
     * @param bool $supresslink
     * @return string HTML to output.
     */
    public function section_title($section, $course, $supresslink = false)
    {
        global $CFG;
        if ((float)$CFG->version >= 2016052300) {
            // For Moodle 3.1 or later use inplace editable for displaying section name.
            $section = course_get_format($course)->get_section($section);
            return $this->render(course_get_format($course)->inplace_editable_render_section_name($section, !$supresslink));
        }
        $title = get_section_name($course, $section);
        if (!$supresslink) {
            $url = course_get_url($course, $section, array('navigation' => true));
            if ($url) {
                $title = html_writer::link($url, $title);
            }
        }
        return $title;
    }

    /**
     * Generate html for a section summary text
     *
     * @param stdClass $section The course_section entry from DB
     * @return string HTML to output.
     */
    protected function format_summary_text($section)
    {
        $context = context_course::instance($section->course);
        $summarytext = file_rewrite_pluginfile_urls(
            $section->summary,
            'pluginfile.php',
            $context->id,
            'course',
            'section',
            $section->id
        );

        $options = new stdClass();
        $options->noclean = true;
        $options->overflowdiv = true;
        return format_text($summarytext, $section->summaryformat, $options);
    }

    /**
     * Display section and all its activities and subsections (called recursively)
     *
     * @param int|stdClass $course
     * @param int|section_info $section
     * @param int $sr section to return to (for building links)
     * @param int $level nested level on the page (in case of 0 also displays additional start/end html code)
     */
    public function display_section($course, $section, $sr, $level = 0)
    {
        $course = course_get_format($course)->get_course();
        $section = course_get_format($course)->get_section($section);
        $context = context_course::instance($course->id);
        $contentvisible = true;
        if (!$section->uservisible || !course_get_format($course)->is_section_real_available($section)) {
            if ($section->visible && !$section->available && $section->availableinfo) {
                // Still display section but without content.
                $contentvisible = false;
            } else {
                return '';
            }
        }
        $sectionnum = $section->section;
        $movingsection = course_get_format($course)->is_moving_section();
        if ($level === 0) {
            $cancelmovingcontrols = course_get_format($course)->get_edit_controls_cancelmoving();
            foreach ($cancelmovingcontrols as $control) {
                echo $this->render($control);
            }
            echo html_writer::start_tag('ul', array('class' => 'flexsections flexsections-level-0'));
            if ($section->section) {
                $this->display_insert_section_here($course, $section->parent, $section->section, $sr);
            }
        }
        echo html_writer::start_tag(
            'li',
            array(
                'class' => "section main" .
                    ($movingsection === $sectionnum ? ' ismoving' : '') .
                    (course_get_format($course)->is_section_current($section) ? ' current' : '') .
                    (($section->visible && $contentvisible) ? '' : ' hidden'),
                'id' => 'section-' . $sectionnum
            )
        );

        // Display controls except for expanded/collapsed.
        /** @var format_flexsections_edit_control[] $controls */
        $controls = course_get_format($course)->get_section_edit_controls($section, $sr);
        $collapsedcontrol = null;
        $controlsstr = '';
        foreach ($controls as $idxcontrol => $control) {
            if ($control->actionname === 'expanded' || $control->actionname === 'collapsed') {
                $collapsedcontrol = $control;
            } else {
                $controlsstr .= $this->render($control);
            }
        }
        if (!empty($controlsstr)) {
            echo html_writer::tag('div', $controlsstr, array('class' => 'controls'));
        }

        // Display section content.
        echo html_writer::start_tag('div', array('class' => 'content'));
        // Display section name and expanded/collapsed control.
        if ($sectionnum && ($title = $this->section_title($sectionnum, $course, ($level == 0) || !$contentvisible))) {
            if ($collapsedcontrol) {
                $title = $this->render($collapsedcontrol) . $title;
            }
            echo html_writer::tag('h3', $title, array('class' => 'sectionname'));
        }

        echo $this->section_availability_message(
            $section,
            has_capability('moodle/course:viewhiddensections', $context)
        );

        // Display section description (if needed).
        if ($contentvisible && ($summary = $this->format_summary_text($section))) {
            echo html_writer::tag('div', $summary, array('class' => 'summary'));
        } else {
            echo html_writer::tag('div', '', array('class' => 'summary nosummary'));
        }
        // Display section contents (activities and subsections).
        if ($contentvisible && ($section->collapsed == FORMAT_FLEXSECTIONS_EXPANDED || !$level)) {
            // Display resources and activities.
            echo $this->courserenderer->course_section_cm_list($course, $section, $sr);
            if ($this->page->user_is_editing()) {
                // A little hack to allow use drag&drop for moving activities if the section is empty.
                if (empty(get_fast_modinfo($course)->sections[$sectionnum])) {
                    echo "<ul class=\"section img-text\">\n</ul>\n";
                }
                echo $this->courserenderer->course_section_add_cm_control($course, $sectionnum, $sr);
            }
            // Display subsections.
            $children = course_get_format($course)->get_subsections($sectionnum);
            if (!empty($children) || $movingsection) {
                echo html_writer::start_tag('ul', ['class' => 'flexsections flexsections-level-' . ($level + 1)]);
                foreach ($children as $num) {
                    $this->display_insert_section_here($course, $section, $num, $sr);
                    $this->display_section($course, $num, $sr, $level + 1);
                }
                $this->display_insert_section_here($course, $section, null, $sr);
                echo html_writer::end_tag('ul'); // End of  .flexsections.
            }
            if ($addsectioncontrol = course_get_format($course)->get_add_section_control($sectionnum)) {
                echo $this->render($addsectioncontrol);
            }
        }
        echo html_writer::end_tag('div'); // End of .content .
        echo html_writer::end_tag('li'); // End of .section .
        if ($level === 0) {
            if ($section->section) {
                $this->display_insert_section_here($course, $section->parent, null, $sr);
            }
            echo html_writer::end_tag('ul'); // End of  .flexsections .
        }
    }

    /**
     * Displays the target div for moving section (in 'moving' mode only)
     *
     * @param int|stdClass $courseorid current course
     * @param int|section_info $parent new parent section
     * @param null|int|section_info $before number of section before which we want to insert (or null if in the end)
     * @param null $sr
     */
    protected function display_insert_section_here($courseorid, $parent, $before = null, $sr = null)
    {
        if ($control = course_get_format($courseorid)->get_edit_control_movehere($parent, $before, $sr)) {
            echo $this->render($control);
        }
    }

    /**
     * Renders HTML for format_flexsections_edit_control
     *
     * @param format_flexsections_edit_control $control
     * @return string
     */
    protected function render_format_flexsections_edit_control(format_flexsections_edit_control $control)
    {
        if (!$control) {
            return '';
        }
        if ($control->actionname === 'movehere') {
            $icon = new pix_icon(
                'movehere',
                $control->text,
                'moodle',
                ['class' => 'movetarget', 'title' => $control->text]
            );
            $action = new action_link($control->url, $icon, null, ['data-action' => $control->actionname]);
            return html_writer::tag('li', $this->render($action), ['class' => 'movehere']);
        } else if ($control->actionname === 'cancelmovingsection' || $control->actionname === 'cancelmovingactivity') {
            return html_writer::tag(
                'div',
                html_writer::link($control->url, $control->text),
                array('class' => 'cancelmoving ' . $control->actionname)
            );
        } else if ($control->actionname === 'addsection') {
            $icon = new pix_icon('t/add', '', 'moodle', ['class' => 'iconsmall']);
            $text = $this->render($icon) . html_writer::tag(
                'span',
                $control->text,
                ['class' => $control->actionname . '-text']
            );
            $action = new action_link($control->url, $text, null, ['data-action' => $control->actionname]);
            return html_writer::tag('div', $this->render($action), ['class' => 'mdl-right']);
        } else if ($control->actionname === 'backto') {
            $icon = new pix_icon('t/up', '', 'moodle');
            $text = $this->render($icon) . html_writer::tag(
                'span',
                $control->text,
                ['class' => $control->actionname . '-text']
            );
            return html_writer::tag(
                'div',
                html_writer::link($control->url, $text),
                array('class' => 'header ' . $control->actionname)
            );
        } else if (
            $control->actionname === 'settings' || $control->actionname === 'marker' ||
            $control->actionname === 'marked'
        ) {
            $icon = new pix_icon(
                'i/' . $control->actionname,
                $control->text,
                'moodle',
                ['class' => 'iconsmall', 'title' => $control->text]
            );
        } else if (
            $control->actionname === 'move' || $control->actionname === 'expanded' || $control->actionname === 'collapsed'
            || $control->actionname === 'hide' || $control->actionname === 'show' || $control->actionname === 'delete'
        ) {
            $icon = new pix_icon(
                't/' . $control->actionname,
                $control->text,
                'moodle',
                ['class' => 'iconsmall', 'title' => $control->text]
            );
        } else if ($control->actionname === 'mergeup') {
            $icon = new pix_icon(
                'mergeup',
                $control->text,
                'format_flexsections',
                ['class' => 'iconsmall', 'title' => $control->text]
            );
        }
        if (isset($icon)) {
            if ($control->url) {
                // Icon with a link.
                $action = new action_link($control->url, $icon, null, ['data-action' => $control->actionname]);
                return $this->render($action);
            } else {
                // Just icon.
                return html_writer::tag('span', $this->render($icon), ['data-action' => $control->actionname]);
            }
        }
        // Unknown control.
        return ' ' . html_writer::link($control->url, $control->text, ['data-action' => $control->actionname]) . '';
    }

    /**
     * If section is not visible, display the message about that ('Not available
     * until...', that sort of thing). Otherwise, returns blank.
     *
     * For users with the ability to view hidden sections, it shows the
     * information even though you can view the section and also may include
     * slightly fuller information (so that teachers can tell when sections
     * are going to be unavailable etc). This logic is the same as for
     * activities.
     *
     * @param stdClass $section The course_section entry from DB
     * @param bool $canviewhidden True if user can view hidden sections
     * @return string HTML to output
     */
    protected function section_availability_message($section, $canviewhidden)
    {
        global $CFG;
        $o = '';
        if (!$section->visible) {
            if ($canviewhidden) {
                $o .= $this->courserenderer->availability_info(get_string('hiddenfromstudents'), 'ishidden');
            } else {
                // We are here because of the setting "Hidden sections are shown in collapsed form".
                // Student can not see the section contents but can see its name.
                $o .= $this->courserenderer->availability_info(get_string('notavailable'), 'ishidden');
            }
        } else if (!$section->uservisible) {
            if ($section->availableinfo) {
                // Note: We only get to this function if availableinfo is non-empty,
                // so there is definitely something to print.
                $formattedinfo = \core_availability\info::format_info(
                    $section->availableinfo,
                    $section->course
                );
                $o .= $this->courserenderer->availability_info($formattedinfo, 'isrestricted');
            }
        } else if ($canviewhidden && !empty($CFG->enableavailability)) {
            // Check if there is an availability restriction.
            $ci = new \core_availability\info_section($section);
            $fullinfo = $ci->get_full_information();
            if ($fullinfo) {
                $formattedinfo = \core_availability\info::format_info(
                    $fullinfo,
                    $section->course
                );
                $o .= $this->courserenderer->availability_info($formattedinfo, 'isrestricted isfullinfo');
            }
        }
        return $o;
    }

    /**
     * Displays a confirmation dialogue when deleting the section (for non-JS mode)
     *
     * @param stdClass $course
     * @param int $sectionreturn
     * @param int $deletesection
     */
    public function confirm_delete_section($course, $sectionreturn, $deletesection)
    {
        echo $this->box_start('noticebox');
        $courseurl = course_get_url($course, $sectionreturn);
        $optionsyes = array('confirm' => 1, 'deletesection' => $deletesection, 'sesskey' => sesskey());
        $formcontinue = new single_button(new moodle_url($courseurl, $optionsyes), get_string('yes'));
        $formcancel = new single_button($courseurl, get_string('no'), 'get');
        echo $this->confirm(get_string('confirmdelete', 'format_flexsections'), $formcontinue, $formcancel);
        echo $this->box_end();
    }

    /**
     * Display section and all its activities and subsections (called recursively)
     *
     * @param int|stdClass $course
     * @param int|section_info $section
     * @param int $sr section to return to (for building links)
     * @param int $level nested level on the page (in case of 0 also displays additional start/end html code)
     */
    public function display_flexsection($course, $section, $sr, $level = 0)
    {
        $course = course_get_format($course)->get_course();
        $section = course_get_format($course)->get_section($section);
        $context = context_course::instance($course->id);
        $contentvisible = true;
        if (!$section->uservisible || !course_get_format($course)->is_section_real_available($section)) {
            if ($section->visible && !$section->available && $section->availableinfo) {
                // Still display section but without content.
                $contentvisible = false;
            } else {
                return '';
            }
        }
        $sectionnum = $section->section;
        $movingsection = course_get_format($course)->is_moving_section();
        if ($level === 0) {
            $cancelmovingcontrols = course_get_format($course)->get_edit_controls_cancelmoving();
            foreach ($cancelmovingcontrols as $control) {
                echo $this->render($control);
            }
            echo html_writer::start_tag('ul', array('class' => 'flexsections flexsections-level-0'));
            if ($section->section) {
                $this->display_insert_section_here($course, $section->parent, $section->section, $sr);
            }
        }

        // Display controls except for expanded/collapsed.
        /** @var format_flexsections_edit_control[] $controls */
        $controls = course_get_format($course)->get_section_edit_controls($section, $sr);
        $collapsedcontrol = null;
        $controlsstr = '';
        foreach ($controls as $idxcontrol => $control) {
            if ($control->actionname === 'expanded' || $control->actionname === 'collapsed') {
                $collapsedcontrol = $control;
            } else {
                $controlsstr .= $this->render($control);
            }
        }
        if (!empty($controlsstr)) {
            $elocontrolsstr = html_writer::tag('div', $controlsstr, array('class' => 'controls'));
        }

        $elotitle = $this->section_title($sectionnum, $course, true);         

         // Nhien Bo vao ben ngoai cho dep
         $this->echo_start_section('section' . $sectionnum,$elotitle,$elosummary,$elocontrolsstr,$section->collapsed,$level);
         echo html_writer::start_tag('li',
                 array('class' => "section main".
                     ($movingsection === $sectionnum ? ' ismoving' : '').
                     (course_get_format($course)->is_section_current($section) ? ' current' : '').
                     (($section->visible && $contentvisible) ? '' : ' hidden'),
                     'id' => 'section-'.$sectionnum));//Nhien elo fix hien thi dung vi tri khi click
 

        // Display section content.
        echo html_writer::start_tag('div', array('class' => 'content'));
        // Display section name and expanded/collapsed control.
        if ($sectionnum && ($title = $this->section_title($sectionnum, $course, ($level == 0) || !$contentvisible))) {
            if ($collapsedcontrol) {
                $title = $this->render($collapsedcontrol) . $title;
            }
        }

        echo $this->section_availability_message(
            $section,
            has_capability('moodle/course:viewhiddensections', $context)
        );

        // Display section description (if needed).
        if ($contentvisible && ($summary = $this->format_summary_text($section))) {
            echo html_writer::tag('div', $summary, array('class' => 'summary'));
        } else {
            echo html_writer::tag('div', '', array('class' => 'summary nosummary'));
        }
        // Display section contents (activities and subsections).
        //Nhien 
        if ($contentvisible || ($level == 0)) {
        // if ($contentvisible && ($section->collapsed == FORMAT_FLEXSECTIONS_EXPANDED || !$level)) {
            // Display resources and activities.
            echo $this->course_section_cm_list($course, $section, $sr);
            // echo $this->courserenderer->course_section_cm_list($course, $section, $sr);
            if ($this->page->user_is_editing()) {
                // A little hack to allow use drag&drop for moving activities if the section is empty.
                if (empty(get_fast_modinfo($course)->sections[$sectionnum])) {
                    echo "<ul class=\"section img-text\">\n</ul>\n";
                }
                echo $this->courserenderer->course_section_add_cm_control($course, $sectionnum, $sr);
            }
            // Display subsections.
            $children = course_get_format($course)->get_subsections($sectionnum);
            if (!empty($children) || $movingsection) {
                echo html_writer::start_tag('ul', ['class' => 'flexsections flexsections-level-' . ($level + 1)]);
                foreach ($children as $num) {
                    $this->display_insert_section_here($course, $section, $num, $sr);
                    $this->display_flexsection($course, $num, $sr, $level + 1);
                }
                $this->display_insert_section_here($course, $section, null, $sr);
                echo html_writer::end_tag('ul'); // End of  .flexsections.
            }
            if ($addsectioncontrol = course_get_format($course)->get_add_section_control($sectionnum)) {
                echo $this->render($addsectioncontrol);
            }
        }
        echo html_writer::end_tag('div'); // End of .content .
        echo html_writer::end_tag('li'); // End of .section .
        $this->elo_echo_end_section();
        if ($level === 0) {
            if ($section->section) {
                $this->display_insert_section_here($course, $section->parent, null, $sr);
            }
            echo html_writer::end_tag('ul'); // End of  .flexsections .
        }
    }

    public function echo_start_section($eloclassnametail, $title, $elosummary, $elocontrolsstr, $sectioncollapsed, $level)
    {
        $level = MIN($level, 5);

        $title = '<span id="' . $eloclassnametail . '" class = "sectiontitle-' . $level . '">' . $title . '</span>';
        $elosummary = '<div class = "sectionsummary-' . $level . '">' . $elosummary . '</div>';

        $checked = '';
        $collapsed = 'collapsed';
        if ($sectioncollapsed == FORMAT_FLEXSECTIONS_EXPANDED) {
            $checked = 'show';
            $collapsed = '';
        }
        global $elo_echo_start_section;
        $eloclassnametail .= $elo_echo_start_section;
        if ($elo_echo_start_section) {
            echo '<div class="collapsetitle '.$collapsed.' sectiontoggle" data-toggle="collapse"  href="#collapse' . $eloclassnametail . '">
            <label for="collapsible' . $eloclassnametail . '" class="lbl-toggle">' . $title . $elocontrolsstr . $elosummary . '</label></div>
            <div id="collapse' . $eloclassnametail . '" class="panel-collapse collapse ' . $checked . '">
            <div class="content-inner">';
        } else {
            echo '<div><div><div>';
        }
        $elo_echo_start_section++;
    }

    public function elo_echo_end_section()
    {
        echo '</div></div>';
    }

    public function course_section_cm_list($course, $section, $sectionreturn = null, $displayoptions = array()) {
        global $USER;

        $output = '';
        $modinfo = get_fast_modinfo($course);
        if (is_object($section)) {
            $section = $modinfo->get_section_info($section->section);
        } else {
            $section = $modinfo->get_section_info($section);
        }
        $completioninfo = new completion_info($course);

        // check if we are currently in the process of moving a module with JavaScript disabled
        $ismoving = $this->courserenderer->page->user_is_editing() && ismoving($course->id);
        if ($ismoving) {
            $movingpix = new pix_icon('movehere', get_string('movehere'), 'moodle', array('class' => 'movetarget'));
            $strmovefull = strip_tags(get_string("movefull", "", "'$USER->activitycopyname'"));
        }

        // Get the list of modules visible to user (excluding the module being moved if there is one)
        $moduleshtml = array();
        if (!empty($modinfo->sections[$section->section])) {
            foreach ($modinfo->sections[$section->section] as $modnumber) {
                $mod = $modinfo->cms[$modnumber];

                if ($ismoving and $mod->id == $USER->activitycopy) {
                    // do not display moving mod
                    continue;
                }

                // if ($modulehtml = $this->courserenderer->course_section_cm_list_item($course,
                //         $completioninfo, $mod, $sectionreturn, $displayoptions)) {
                //     $moduleshtml[$modnumber] = $modulehtml;
                // }

                if ($modulehtml = $this->course_section_cm_list_item($course,
                        $completioninfo, $mod, $sectionreturn, $displayoptions)) {
                    $moduleshtml[$modnumber] = $modulehtml;
                }
            }
        }

        $sectionoutput = '';
        if (!empty($moduleshtml) || $ismoving) {
            foreach ($moduleshtml as $modnumber => $modulehtml) {
                if ($ismoving) {
                    $movingurl = new moodle_url('/course/mod.php', array('moveto' => $modnumber, 'sesskey' => sesskey()));
                    $sectionoutput .= html_writer::tag('li',
                            html_writer::link($movingurl, $this->courserenderer->output->render($movingpix), array('title' => $strmovefull)),
                            array('class' => 'movehere'));
                }

                $sectionoutput .= $modulehtml;
            }

            if ($ismoving) {
                $movingurl = new moodle_url('/course/mod.php', array('movetosection' => $section->id, 'sesskey' => sesskey()));
                $sectionoutput .= html_writer::tag('li',
                        html_writer::link($movingurl, $this->courserenderer->output->render($movingpix), array('title' => $strmovefull)),
                        array('class' => 'movehere'));
            }
        }

        // Always output the section module list.
        if($sectionoutput != ''){ // Nhien Only change here not display empty summary
            $output .= html_writer::tag('ul', $sectionoutput, array('class' => 'section img-text'));
        }

        return $output;
    }

    public function display_flexsection_calendar($course, $PAGE)
    {
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/calendar/lib.php');
    
        $categoryid = optional_param('category', null, PARAM_INT);
        $time = optional_param('time', 0, PARAM_INT);
        $view = optional_param('view', 'month', PARAM_ALPHA);
    
        $url = new moodle_url('/calendar/view.php');
    
        if (empty($time)) {
            $time = time();
        }
        $url->param('format_eloflexsections', $course->id);
        if ($categoryid) {
            $url->param('categoryid', $categoryid);
        }
        if ($view !== 'upcoming') {
            $time = usergetmidnight($time);
            $url->param('view', $view);
        }
    
        $calendar = calendar_information::create($time, $course->id, $categoryid);
    
        $renderer = $PAGE->get_renderer('core_calendar');
    
        list($data, $template) = calendar_get_view($calendar, $view);
        list($dataupcoming, $templateupcoming) = calendar_get_view($calendar, 'upcoming');
    
        $calendarhtml = $renderer->start_layout();
        $calendarhtml .= $renderer->render_from_template($template, $data);
        $calendarhtml .= $renderer->complete_layout();
    
        print $calendarhtml;
    
        $elocomplettionhtlm = export_course_completed_html($course);
    
        print $elocomplettionhtlm;
    }

     /**
     * Renders HTML to display one course module for display within a section.
     *
     * This function calls:
     * {@link core_course_renderer::course_section_cm()}
     *
     * @param stdClass $course
     * @param completion_info $completioninfo
     * @param cm_info $mod
     * @param int|null $sectionreturn
     * @param array $displayoptions
     * @return String
     */
    public function course_section_cm_list_item($course, &$completioninfo, cm_info $mod, $sectionreturn, $displayoptions = array()) {
        $output = '';
        if ($modulehtml = $this->course_section_cm($course, $completioninfo, $mod, $sectionreturn, $displayoptions)) {
            $modclasses = 'activity ' . $mod->modname . ' modtype_' . $mod->modname . ' ' . $mod->extraclasses;
            $output .= html_writer::tag('li', $modulehtml, array('class' => $modclasses, 'id' => 'module-' . $mod->id));
        }
        return $output;
    }

    /**
     * Renders HTML to display one course module in a course section
     *
     * This includes link, content, availability, completion info and additional information
     * that module type wants to display (i.e. number of unread forum posts)
     *
     * This function calls:
     * {@link core_course_renderer::course_section_cm_name()}
     * {@link core_course_renderer::course_section_cm_text()}
     * {@link core_course_renderer::course_section_cm_availability()}
     * {@link core_course_renderer::course_section_cm_completion()}
     * {@link course_get_cm_edit_actions()}
     * {@link core_course_renderer::course_section_cm_edit_actions()}
     *
     * @param stdClass $course
     * @param completion_info $completioninfo
     * @param cm_info $mod
     * @param int|null $sectionreturn
     * @param array $displayoptions
     * @return string
     */
    public function course_section_cm($course, &$completioninfo, cm_info $mod, $sectionreturn, $displayoptions = array()) {
        $output = '';
        // We return empty string (because course module will not be displayed at all)
        // if:
        // 1) The activity is not visible to users
        // and
        // 2) The 'availableinfo' is empty, i.e. the activity was
        //     hidden in a way that leaves no info, such as using the
        //     eye icon.
        if (!$mod->is_visible_on_course_page()) {
            return $output;
        }

        $indentclasses = 'mod-indent';
        if (!empty($mod->indent)) {
            $indentclasses .= ' mod-indent-'.$mod->indent;
            if ($mod->indent > 15) {
                $indentclasses .= ' mod-indent-huge';
            }
        }

        $output .= html_writer::start_tag('div');

        if ($this->page->user_is_editing()) {
            $output .= course_get_cm_move($mod, $sectionreturn);
        }

        $output .= html_writer::start_tag('div', array('class' => 'mod-indent-outer w-100'));

        // This div is used to indent the content.
        $output .= html_writer::div('', $indentclasses);

        // Start a wrapper for the actual content to keep the indentation consistent
        $output .= html_writer::start_tag('div');

        // Display the link to the module (or do nothing if module has no url)
        $cmname = $this->courserenderer->course_section_cm_name($mod, $displayoptions);

        if (!empty($cmname)) {
            // Start the div for the activity title, excluding the edit icons.
            $output .= html_writer::start_tag('div', array('class' => 'activityinstance'));
            $output .= $cmname;


            // Module can put text after the link (e.g. forum unread)
            $output .= $mod->afterlink;

            // Closing the tag which contains everything but edit icons. Content part of the module should not be part of this.
            $output .= html_writer::end_tag('div'); // .activityinstance
        }

        // If there is content but NO link (eg label), then display the
        // content here (BEFORE any icons). In this case cons must be
        // displayed after the content so that it makes more sense visually
        // and for accessibility reasons, e.g. if you have a one-line label
        // it should work similarly (at least in terms of ordering) to an
        // activity.
        $contentpart = $this->courserenderer->course_section_cm_text($mod, $displayoptions);
        $url = $mod->url;
        if (empty($url)) {
            $output .= $contentpart;
        }

        $modicons = '';
        if ($this->courserenderer->page->user_is_editing()) {
            $editactions = course_get_cm_edit_actions($mod, $mod->indent, $sectionreturn);
            $modicons .= ' '. $this->courserenderer->course_section_cm_edit_actions($editactions, $mod, $displayoptions);
            $modicons .= $mod->afterediticons;
        }

        $modicons .= $this->courserenderer->course_section_cm_completion($course, $completioninfo, $mod, $displayoptions);

        if (!empty($modicons)) {
            $output .= html_writer::div($modicons, 'actions');
        }

        // Show availability info (if module is not available).
        $output .= $this->course_section_cm_availability($mod, $displayoptions);

        // If there is content AND a link, then display the content here
        // (AFTER any icons). Otherwise it was displayed before
        if (!empty($url)) {
            $output .= $contentpart;
        }

        $output .= html_writer::end_tag('div'); // $indentclasses

        // End of indentation div.
        $output .= html_writer::end_tag('div');

        $output .= html_writer::end_tag('div');
        return $output;
    }

     /**
     * Renders HTML to show course module availability information (for someone who isn't allowed
     * to see the activity itself, or for staff)
     *
     * @param cm_info $mod
     * @param array $displayoptions
     * @return string
     */
    public function course_section_cm_availability(cm_info $mod, $displayoptions = array()) {
        global $CFG;
        $output = '';
        if (!$mod->is_visible_on_course_page()) {
            return $output;
        }
        if (!$mod->uservisible) {
            // this is a student who is not allowed to see the module but might be allowed
            // to see availability info (i.e. "Available from ...")
            if (!empty($mod->availableinfo)) {
                $formattedinfo = \core_availability\info::format_info(
                        $mod->availableinfo, $mod->get_course());
                $output = $this->courserenderer->availability_info($formattedinfo, 'isrestricted');
            }
            return $output;
        }
        // this is a teacher who is allowed to see module but still should see the
        // information that module is not available to all/some students
        $modcontext = context_module::instance($mod->id);
        $canviewhidden = has_capability('moodle/course:viewhiddenactivities', $modcontext);
        if ($canviewhidden && !$mod->visible) {
            // This module is hidden but current user has capability to see it.
            // Do not display the availability info if the whole section is hidden.
            if ($mod->get_section_info()->visible) {
                $output .= $this->courserenderer->availability_info(get_string('hiddenfromstudents'), 'ishidden');
            }
        } else if ($mod->is_stealth()) {
            // This module is available but is normally not displayed on the course page
            // (this user can see it because they can manage it).
            $output .= $this->courserenderer->availability_info(get_string('hiddenoncoursepage'), 'isstealth');
        }
        if ($canviewhidden && !empty($CFG->enableavailability)) {
            // Display information about conditional availability.
            // Don't add availability information if user is not editing and activity is hidden.
            if ($mod->visible || $this->courserenderer->page->user_is_editing()) {
                $hidinfoclass = 'isrestricted isfullinfo';
                $hidinfoclassdeadline = 'isdeadline isfullinfo';
                if (!$mod->visible) {
                    $hidinfoclass .= ' hide';
                    $hidinfoclassdeadline.= ' hide';
                }
                $ci = new \core_availability\info_module($mod);
                $fullinfo = $ci->get_full_information();
                if ($fullinfo) {
                    $formattedinfo = \core_availability\info::format_info(
                            $fullinfo, $mod->get_course());
                    $output .= $this->courserenderer->availability_info($formattedinfo, $hidinfoclass);
                }
                if ($mod->completionexpected > 0) {
                    $formatdaycompletionexpected = userdate($mod->completionexpected, 	get_string('strftimerecent'));
                                   $output .= $this->availability_deadline($formatdaycompletionexpected, 	$hidinfoclassdeadline);
                }
            }
        }
        return $output;
    }

    public function availability_deadline($text, $additionalclasses = '') {
        $data = ['text' => $text, 'classes' => $additionalclasses];
        $additionalclasses = array_filter(explode(' ', $additionalclasses));

        if (in_array('ishidden', $additionalclasses)) {
            $data['ishidden'] = 1;
        } else if (in_array('isstealth', $additionalclasses)) {
            $data['isstealth'] = 1;
        } else if (in_array('isdeadline', $additionalclasses)) {
            $data['isdeadline'] = 1;

            if (in_array('isfullinfo', $additionalclasses)) {
                $data['isfullinfo'] = 1;
            }
        }
        return $this->render_from_template('format_flexsections/availability_deadline', $data);
    }

    public function course_section_cm_deadline(cm_info $mod, $displayoptions = array()) {
        $output = '';
        if (!$mod->is_visible_on_course_page()) {
            return $output;
        }
        if ($mod->completionexpected > 0) {
            $hidinfoclassdeadline = 'isdeadline isfullinfo';
            $formatdaycompletionexpected = userdate($mod->completionexpected,
                    get_string('strftimerecent'));
            $output .= $this->availability_deadline($formatdaycompletionexpected,
                    $hidinfoclassdeadline);
        }
        return $output;
    }
}

