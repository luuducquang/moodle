<?php
// This file is part of Moodle - https://moodle.org/
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
 * Plugin version and other meta-data are defined here.
 *
 * @package     qformat_wordquestion
 * @copyright   2023 Viet Anh <vietanhtvym@gmail.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use gradereport_singleview\local\ui\override;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/question/format/wordtable/format.php');


use \booktool_wordimport\wordconverter;
use \qformat_wordquestion\mqxmlconverter2;

class qformat_wordquestion extends qformat_wordtable
{
    /** @var array Overrides to default XSLT parameters used for conversion */
    private $xsltparameters = array(
        'pluginname' => 'qformat_wordquestion',
        'heading1stylelevel' => 1, // Map "Heading 1" style to <h1> element.
        'imagehandling' => 'embedded' // Embed image data directly into the generated Moodle Question XML.
    );
    /** @var array Lesson questions are stored here if importing a lesson Word file. */
    private $lessonquestions = array();

    public function __construct(string $plugin = 'qformat_wordquestion')
    {
        global $CFG, $USER, $COURSE;

        // Set common parameters for all XSLT transformations.
        $this->xsltparameters = array(
            'course_id' => $COURSE->id,
            'course_name' => $COURSE->fullname,
            'author_name' => $USER->firstname . ' ' . $USER->lastname,
            'moodle_country' => $USER->country,
            'moodle_language' => current_language(),
            'moodle_textdirection' => (right_to_left()) ? 'rtl' : 'ltr',
            'moodle_release' => $CFG->release, // Moodle version, e.g. 3.5, 3.10.
            'moodle_release_date' => substr($CFG->version, 0, 8), // The Moodle major version release date, for testing.
            'moodle_url' => $CFG->wwwroot . "/",
            'moodle_username' => $USER->username,
            'imagehandling' => 'embedded', // Question banks are embedded, Lessons are referenced.
            'heading1stylelevel' => 1, // Question banks are 1, Lessons should be overridden to 3.
            'pluginname' => $plugin,
            'debug_flag' => (debugging(null, DEBUG_DEVELOPER)) ? '1' : '0',
            'language' => 'vi'
        );
    }

    // IMPORT FUNCTIONS START HERE.

    /**
     * Perform required pre-processing, i.e. convert Word file into Moodle Question XML
     *
     * Extract the WordProcessingML XML files from the .docx file, and use a sequence of XSLT
     * steps to convert it into Moodle Question XML
     *
     * @return bool Success
     */
    public function importpreprocess()
    {
        global $CFG;
        $realfilename = "";
        $filename = "";

        // Handle question imports in Lesson module by using mform, not the question/format.php qformat_default class.
        if (property_exists('qformat_default', 'realfilename')) {
            $realfilename = $this->realfilename;
        } else {
            global $mform;
            $realfilename = $mform->get_new_filename('questionfile');
        }
        if (property_exists('qformat_default', 'filename')) {
            $filename = $this->filename;
        } else {
            global $mform, $USER;

            if (property_exists('qformat_default', 'importcontext')) {
                // We have to check if this request is made from the lesson interface.
                $cm = get_coursemodule_from_id('lesson', $this->importcontext->instanceid);
                if ($cm) {
                    $draftid = optional_param('questionfile', '', PARAM_FILE);
                    $dir = make_temp_directory('forms');
                    $tempfile = tempnam($dir, 'tempup_');

                    $fs = get_file_storage();
                    $context = context_user::instance($USER->id);
                    if (!$files = $fs->get_area_files($context->id, 'user', 'draft', $draftid, 'id DESC', false)) {
                        throw new \moodle_exception(get_string('cannotwritetotempfile', 'qformat_wordtable', ''));
                    }
                    $file = reset($files);

                    $filename = $file->copy_content_to($tempfile);
                    $filename = $tempfile;
                } else {
                    $filename = "{$CFG->tempdir}/questionimport/{$realfilename}";
                }
            } else {
                $filename = "{$CFG->tempdir}/questionimport/{$realfilename}";
            }
        }
        $basefilename = basename($filename);
        $baserealfilename = basename($realfilename);

        // Check that the file is in Word 2010 format, not HTML, XML, or Word 2003.
        if ((substr($realfilename, -3, 3) == 'doc')) {
            throw new \moodle_exception(get_string('docnotsupported', 'qformat_wordtable', $baserealfilename));
            return false;
        } else if ((substr($realfilename, -3, 3) == 'xml')) {
            throw new \moodle_exception(get_string('xmlnotsupported', 'qformat_wordtable', $baserealfilename));
            return false;
        } else if ((stripos($realfilename, 'htm'))) {
            throw new \moodle_exception(get_string('htmlnotsupported', 'qformat_wordtable', $baserealfilename));
            return false;
        } else if ((stripos(file_get_contents($filename, 0, null, 0, 100), 'html'))) {
            throw new \moodle_exception(get_string('htmldocnotsupported', 'qformat_wordtable', $baserealfilename));
            return false;
        }

        // Import the Word file into XHTML and an array of images.
        $imagesforzipping = array();
        $word2xml = new wordconverter($this->xsltparameters['pluginname']);
        $word2xml->set_heading1styleoffset($this->xsltparameters['heading1stylelevel']);
        $word2xml->set_imagehandling($this->xsltparameters['imagehandling']);
        $xhtmldata = $word2xml->import($filename, $imagesforzipping, true);

        // Convert the returned array of images, if any, into a string.
        $imagestring = "";
        foreach ($imagesforzipping as $imagename => $imagedata) {
            $filetype = strtolower(pathinfo($imagename, PATHINFO_EXTENSION));
            $base64data = base64_encode($imagedata);
            $filedata = 'data:image/' . $filetype . ';base64,' . $base64data;
            // Embed the image name and data into the HTML.
            $imagestring .= '<img title="' . $imagename . '" src="' . $filedata . '"/>';
        }

        // Convert XHTML into Moodle Question XML.
        $xhtml2mqxml = new mqxmlconverter2($this->xsltparameters['pluginname']);
        $mqxmldata = $xhtml2mqxml->import($xhtmldata, $imagestring, $this->xsltparameters);

        if ((strpos($mqxmldata, "</question>") === false)) {
            throw new \moodle_exception(get_string('noquestionsinfile', 'question'));
        }

        // Now over-write the original Word file with the XML file, so that default XML file handling will work.
        if (($fp = fopen($filename, "wb"))) {
            if (($nbytes = fwrite($fp, $mqxmldata)) == 0) {
                throw new moodle_exception(get_string('cannotwritetotempfile', 'qformat_wordtable', $basefilename));
            }
            fclose($fp);
        }

        // This part of the code is a copy of "readdata" function developed in format.php question/import.php
        // and mod/lesson/import.php, to return the structure of the file.
        // This patch is required because the lesson logic file uses its own file that it consumes in the form
        // and does not do so like question import which shares a file at the class level.
        if (is_readable($filename) && isset($cm)) {
            $filearray = file($filename);
            // Check for Macintosh OS line returns (ie file on one line), and fix.
            if (preg_match("/\r/", $filearray[0]) and !preg_match("/\n/", $filearray[0])) {
                $this->lessonquestions = explode("\r", $filearray[0]);
            } else {
                $this->lessonquestions = $filearray;
            }


            return false;
        }


        return true;
    }   // End importpreprocess function.

    /**
     * Use a .doc file extension when exporting, so that Word is used to open the file
     * @return string file extension
     */
    public function export_file_extension()
    {
        return ".doc";
    }

    /**
     * Convert the Moodle Question XML into Word-compatible XHTML format
     * just prior to the file being saved
     *
     * Use an XSLT script to do the job, as it is much easier to implement this,
     * and Moodle sites are guaranteed to have an XSLT processor available (I think).
     *
     * @param string $content Question XML text
     * @return string Word-compatible XHTML text
     */
    public function presave_process($content)
    {
        $regex = '/<!--.*?-->|<question type="category">.*?<\/question>|<feedback format="html">.*?<\/feedback>/s';


        $content = preg_replace($regex, '', $content);

        // Check that there are questions to convert.
        if (strpos($content, "</question>") === false) {
            throw new moodle_exception(get_string('noquestions', 'qformat_wordtable'));
            return $content;
        }

        // Convert the Moodle Question XML into Word-compatible XHTML.
        $mqxml2xhtml = new mqxmlconverter2($this->xsltparameters['pluginname']);
        $xhtmldata = $mqxml2xhtml->export($content, $this->xsltparameters['pluginname'], $this->xsltparameters['imagehandling']);

        // echo $xhtmldata;
        // die;

        return $xhtmldata;
    }
}
