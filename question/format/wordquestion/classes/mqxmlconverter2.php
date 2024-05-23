<?php
// This file is part of Moodle - http://moodle.org/
//
// This program is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// This program is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with this program.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Import/Export Moodle Question XML library.
 *
 *  @package     qformat_wordquestion
 *  @copyright   2023 Viet Anh <vietanhtvym@gmail.com>
 *  @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace qformat_wordquestion;

use \qformat_wordtable\mqxmlconverter;

defined('MOODLE_INTERNAL') || die();

use \booktool_wordimport\wordconverter;
use moodle_exception;
use tool_brickfield\local\htmlchecker\common\html_elements;

/**
 * Convert XHTML into XML and vice versa.
 *
 * Class to convert XHTML strings into Moodle Question XML (import), and convert Moodle Question XML into XHTML strings (export).
 */
class mqxmlconverter2 extends mqxmlconverter
{
    /** @var string Stylesheet to export Moodle Question XML into XHTML */
    private $mqxml2xhtmlstylesheet = __DIR__ . "/mqxml2xhtml.xsl";

    /** @var string Stylesheet to import XHTML into question XML */
    private $xhtml2mqxmlstylesheet = __DIR__ . "/xhtml2mqxml.xsl";

    public function convertXHTMLtoXML($xhtmlString) {
        // Use regular expression to find and replace the <span> element inside <div>
        $pattern = '/<p>\s*<span lang=".*">([^<]*)<\/span>\s*<\/p>/i';
        $replacement = '<p>$1</p>';

        $xhtmlString = preg_replace($pattern, $replacement, $xhtmlString);

        // Load XHTML string as SimpleXMLElement
        $xml = simplexml_load_string($xhtmlString);
    
        // Remove XHTML namespace declaration
        $namespaces = $xml->getDocNamespaces(true);
        $xhtmlString = str_replace('xmlns="' . $namespaces[''], '', $xhtmlString);
    
        // Create the XML structure
        $xmlString = '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL;
        $xmlString .= '<quiz>' . PHP_EOL;
    
        foreach ($xml->body->div->table as $table) {
            $questionText = (string)$table->thead->tr->th[0]->p;
            $questionContent = (string)$table->thead->tr->th[1]->p;
            $answersContent = $table->tbody->tr;
            $answerLenght = (int)count($answersContent);
            $answerText = (string)$answersContent[count($answersContent) - 1]->td->p;
    
            $xmlString .= '<question type="category"><category><text>$course$/</text></category></question><question type="multichoice">' . PHP_EOL;
            $xmlString .= '<name><text>' . htmlspecialchars(trim($questionText)) . '</text></name>' . PHP_EOL;
            $xmlString .= '<questiontext format="html"><text><![CDATA[' . $questionContent . ']]></text></questiontext>' . PHP_EOL;
            $xmlString .= '<generalfeedback format="html"><text/></generalfeedback>' . PHP_EOL;
            $xmlString .= '<defaultgrade>1.0000000</defaultgrade>' . PHP_EOL;
            $xmlString .= '<penalty>0.3333333</penalty>' . PHP_EOL;
            $xmlString .= '<hidden>0</hidden>' . PHP_EOL;
            $xmlString .= '<idnumber/>' . PHP_EOL;
            $xmlString .= '<single>true</single>' . PHP_EOL;
            $xmlString .= '<shuffleanswers>true</shuffleanswers>' . PHP_EOL;
            $xmlString .= '<answernumbering>ABCD</answernumbering>' . PHP_EOL;
            $xmlString .= '<correctfeedback format="html"><text><![CDATA[<p>Câu trả lời của bạn đúng</p>]]></text></correctfeedback>' . PHP_EOL;
            $xmlString .= '<partiallycorrectfeedback format="html"><text/></partiallycorrectfeedback>' . PHP_EOL;
            $xmlString .= '<incorrectfeedback format="html"><text><![CDATA[<p>Câu trả lời của bạn sai.</p>]]></text></incorrectfeedback>' . PHP_EOL;
    
            $answers = ['A', 'B', 'C', 'D'];

            $correctAnswer = (string)$answersContent[$answerLenght - 1]->td[1]->p;

            for ($i = 0; $i < $answerLenght - 1; $i++) {
                $answer = (string)$answersContent[$i]->td[1]->p;
                
                $isCorrect = $answers[$i] === $correctAnswer;
                $fraction = $isCorrect ? '100' : '0';
    
                $xmlString .= '<answer fraction="' . $fraction . '" format="html"><text><![CDATA[' . $answer . ']]></text><feedback format="html"><text/></feedback></answer>' . PHP_EOL;
            }
    
            $xmlString .= '</question>' . PHP_EOL;
        }
    
        $xmlString .= '</quiz>';
    
        return $xmlString;
    }
    /**
     * Convert XHTML into Moodle Question XML.
     *
     * @param string $xhtmldata XHTML-formatted content
     * @param string $imagedata Base64-encoded images
     * @param array $parameters Extra XSLT parameters, if any
     * @return string Processed XML content
     */
    public function import(string $xhtmldata, string $imagedata, array $parameters = array())
    {
        global $CFG;

        // Set common parameters for all XSLT transformations. Note that the XSLT processor doesn't support $arguments.
        $this->xsltparameters = array_merge($this->xsltparameters, $parameters);

        // Check that the XSLT stylesheet exists.
        if (!file_exists($this->xhtml2mqxmlstylesheet)) {
            throw new \moodle_exception(get_string('stylesheetunavailable', 'qformat_wordtable', $this->xhtml2mqxmlstylesheet));
        }

        // echo htmlspecialchars($xhtmldata) . '<br/>' . '------------- <br/>';


        // Merge and wrap all the required input data into a single string to simplify XSLT processing.
        // $xhtmldata = "<pass3Container>\n" . $xhtmldata .
        //     "<imagesContainer>\n" . $imagedata . "</imagesContainer>\n" .
        //     $this->get_question_labels() . "\n</pass3Container>";


        // // Use the Book tool wordimport plugin to do the actual XSLT transformation.
        // $word2xml = new wordconverter($this->xsltparameters['pluginname']);
        // $mqxml = $word2xml->xsltransform($xhtmldata, $this->xhtml2mqxmlstylesheet);


        $mqxml = $this->convertXHTMLtoXML($xhtmldata);
        
        // echo htmlspecialchars($mqxml) . '<br/>';
        // die();

        return $mqxml;
    }  // End import function.

   
    /**
     * Export Moodle Question XML into Word-compatible XHTML
     *
     * This export function is for Question bank questions only, not for Lesson questions.
     * It exports all questions in a category.
     *
     * @param string $mqxml Moodle Question XML
     * @param string $imagehandling Embedded or encoded image data
     * @return string XHTML text
     */
    public function export(string $mqxml, string $imagehandling = 'embedded')
    {
        $this->xsltparameters['exportimagehandling'] = $imagehandling;
        // Check that the XSLT stylesheet exists.
        if (!file_exists($this->mqxml2xhtmlstylesheet)) {
            throw new \moodle_exception(get_string('stylesheetunavailable', 'qformat_wordtable', $this->mqxml2xhtmlstylesheet));
        }

        // Clean up the Question XML to ensure it is well-formed XML and won't break the XSLT processing.
        $mqxml = $this->clean_all_questions($mqxml);


        // Merge and wrap all the required input data into a single string to simplify XSLT processing.
        $moodlelabels = $this->get_question_labels();
        $mqxml = "<container>\n<quiz>" . $mqxml . "</quiz>\n" . $moodlelabels . "\n</container>";

        // Use the Book tool wordimport plugin to do the actual XSLT transformation.
        $word2xml = new wordconverter($this->xsltparameters['pluginname']);
        $xhtmldata = $word2xml->xsltransform($mqxml, $this->mqxml2xhtmlstylesheet);

        // Embed the XHTML tables into a Word-compatible template document with styling information, etc.
        $content = $word2xml->export($xhtmldata, $this->xsltparameters['pluginname'], $moodlelabels, 'embedded');


        return $content;
    }   // End export function.

    /**
     * Convert Moodle Question XML into generic XHTML
     *
     * This function converts a single Lesson question into generic XHTML.
     *
     * @param string $mqxml Moodle Question XML
     * @param string $imagehandling Embedded or encoded image data
     * @return string XHTML text
     */
    public function convert_mqx2htm(string $mqxml, string $imagehandling = 'embedded')
    {
        $this->xsltparameters['exportimagehandling'] = $imagehandling;
        // Check that the XML to XHTML conversion XSLT stylesheet exists.
        if (!file_exists($this->mqxml2xhtmlstylesheet)) {
            throw new \moodle_exception(get_string('stylesheetunavailable', 'qformat_wordtable', $this->mqxml2xhtmlstylesheet));
        }

        // Clean up the Question XML to ensure it is well-formed XML and won't break the XSLT processing.
        $mqxml = $this->clean_all_questions($mqxml);

        // Merge and wrap all the required input data into a single string to simplify XSLT processing.
        $moodlelabels = $this->get_core_question_labels();
        $mqxml = "<container>\n<quiz>" . $mqxml . "</quiz>\n" . $moodlelabels . "\n</container>";

        // Use the Book tool wordimport plugin to do the actual XSLT transformation.
        $word2xml = new wordconverter($this->xsltparameters['pluginname']);
        $xhtmldata = $word2xml->xsltransform($mqxml, $this->mqxml2xhtmlstylesheet, $this->xsltparameters);
        $matches = null;
        if (preg_match('/<div[^>]*>(.+)<\/div>/is', $xhtmldata, $matches)) {
            $xhtmldata = $matches[1];
        }

        return $xhtmldata;
    }   // End convert_mqx2htm function.

    /**
     * Get the core and contributed question text strings needed to fill in table labels
     *
     * A string containing XML data, populated from the language folders, is returned.
     * We need to split core from contributed questions to support the Core questions in Lessons.
     *
     * @return string
     */
    private function get_question_labels()
    {
        global $CFG;

        // Get the core question labels first.
        $questionlabels = $this->get_core_question_labels();

        // Append All-or-Nothing MCQ question type strings if present.
        if (\question_bank::is_qtype_installed('multichoiceset')) {
            // Strip out the closing element first so that we can insert the extra labels.
            $questionlabels = str_replace("</moodlelabels>", "", $questionlabels);

            $textstrings['qtype_multichoiceset'] = array('pluginnamesummary', 'showeachanswerfeedback');
            foreach ($textstrings as $typegroup => $grouparray) {
                foreach ($grouparray as $stringid) {
                    $namestring = $typegroup . '_' . $stringid;
                    // Get the question type field label text.
                    $labeltext = get_string($stringid, $typegroup);
                    $questionlabels .= '<data name="' . $namestring . '"><value>' . $labeltext . "</value></data>\n";
                }
            }
            $questionlabels .= "</moodlelabels>";
        }

        // Ensure the XML is well-formed, as the standard label and help text strings may have been overridden on some sites.
        $word2xml = new wordconverter($this->xsltparameters['pluginname']);
        $questionlabels = $word2xml->convert_to_xml($questionlabels);
        $questionlabels = str_replace("<br>", "<br/>", $questionlabels);

        return $questionlabels;
    }

    /**
     * Clean HTML markup inside question text element content
     *
     * A string containing Moodle Question XML with clean HTML inside the text elements is returned.
     *
     * @param string $questionxmlstring Question XML text
     * @return string
     */
    private function clean_all_questions($questionxmlstring)
    {
        // Start assembling the cleaned output string, starting with empty.
        $cleanquestionxml = "";
        $word2xml = new wordconverter($this->xsltparameters['pluginname']);

        // Split the string into questions in order to check the text fields for clean HTML.
        $foundquestions = preg_match_all(
            '~(.*?)<question type="([^"]*)"[^>]*>(.*?)</question>~s',
            $questionxmlstring,
            $questionmatches,
            PREG_SET_ORDER
        );
        $numquestions = count($questionmatches);
        if ($foundquestions === false or $foundquestions == 0) {
            return $questionxmlstring;
        }

        // Split the questions into text strings to check the HTML.
        for ($i = 0; $i < $numquestions; $i++) {

            $qtype = $questionmatches[$i][2];
            $questioncontent = $questionmatches[$i][3];
            // Split the question into chunks at CDATA boundaries, using ungreedy (?) and matching across newlines (s modifier).
            $foundcdatasections = preg_match_all('~(.*?)<\!\[CDATA\[(.*?)\]\]>~s', $questioncontent, $cdatamatches, PREG_SET_ORDER);

            $foundanswers = preg_match_all('/<answer .*?>/', $questioncontent, $answersmatches, PREG_SET_ORDER);

            // ascii of 'A'
            $asciiValue = 65;

            for ($j = 0; $j < $foundanswers; $j++) {

                $str = $answersmatches[$j][0];


                $xml = simplexml_load_string($str . '</answer>');
                if ($xml !== false) {
                    $fractionValue = (int)$xml['fraction'];
                }

                if ($fractionValue == 100) {
                    $correct_answer = '<correct><text><![CDATA[<p>' . chr($asciiValue + $j) . '</p>]]></text></correct>';
                };
            }

            if ($foundcdatasections === false) {
                $cleanquestionxml .= preg_replace('/<\/question>/', '', $questionmatches[$i][0]) . $correct_answer . '</question>';
            } else if ($foundcdatasections != 0) {
                $numcdatasections = count($cdatamatches);
                // Found CDATA sections, so first add the question start tag and then process the body.
                $cleanquestionxml .= '<question type="' . $qtype . '">';

                // Process content of each CDATA section to clean the HTML.
                for ($j = 0; $j < $numcdatasections; $j++) {
                    $cleancdatacontent = $word2xml->clean_html_text($cdatamatches[$j][2]);

                    // Add all the text before the first CDATA start boundary, and the cleaned string, to the output string.
                    $cleanquestionxml .= $cdatamatches[$j][1] . '<![CDATA[' . $cleancdatacontent . ']]>';
                } // End CDATA section loop.

                // Add the text after the last CDATA section closing delimiter.
                $textafterlastcdata = substr($questionmatches[$i][0], strrpos($questionmatches[$i][0], "]]>") + 3);
                $cleanquestionxml .= preg_replace('/<\/question>/', '', $textafterlastcdata) . $correct_answer . '</question>';
            } else {
                $cleanquestionxml .= preg_replace('/<\/question>/', '', $questionmatches[$i][0]) . $correct_answer . '</question>';
            }
        } // End question element loop.

        // echo htmlspecialchars($cleanquestionxml) . '<br/>';

        return $cleanquestionxml;
    }
}
