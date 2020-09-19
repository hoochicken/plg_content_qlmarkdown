<?php
/**
 * @package        plg_content_qlmarkdown
 * @copyright      Copyright (C) 2020 ql.de All rights reserved.
 * @author         Mareike Riegel mareike.riegel@ql.de
 * @license        GNU General Public License version 2 or later; see LICENSE.txt
 */

//no direct access
use Michelf\Markdown;
use Michelf\MarkdownExtra;

defined('_JEXEC') or die ('Restricted Access');

jimport('joomla.plugin.plugin');


class plgContentQlmarkdown extends JPlugin
{

    protected $strCallStart = 'qlmarkdown';
    protected $strCallEnd = '/qlmarkdown';
    protected $offTag = '';
    protected $parser = '';
    protected $endpoint = '';
    protected $arrReplace = [];
    protected $arrAttributesAvailable = ['class', 'id', 'style', 'type', 'title', 'layout', 'parser', 'endpoint'];
    public $params;
    private $boolDebug = false;

    /**
     * onContentPrepare :: some kind of controller of plugin
     * @param $strContext
     * @param $objArticle
     * @param $objParams
     * @param int $numPage
     * @return bool
     * @throws Exception
     */
    public function onContentPrepare($strContext, &$objArticle, &$objParams, $numPage = 0)
    {
        if (!$this->checkContext($strContext)) return true;

        $this->offTag = '{' . $this->strCallStart . '-off}';
        $this->parser = $this->params->get('parser', 'erusev-parsedown');
        $this->endpoint = $this->params->get('apiendpoint', 'https://en.wikipedia.org/w/api.php');

        $input = JFactory::getApplication()->input;
        $this->boolDebug = $input->getBool('ql_content_debug', false);

        //if no plg tag in article => ignore
        if (!$this->tagExistsInArticle($objArticle) && !$this->checkGlobal($objArticle)) {
            $this->clearOffTagsInArticle($objArticle);
            return true;
        }

        $this->clearOffTagsInArticle($objArticle);

        require_once 'vendor/autoload.php';

        // check session if styles already loaded
        $boolAlreadyLoadedStyles = defined('qlmarkdown_styles');
        if (!$boolAlreadyLoadedStyles) {
            if ($this->params->get('style')) {
                $this->getStyles();
            }
            $this->includeScripts();
            define('qlmarkdown_styles', true);
        }

        if ($this->checkGlobal($objArticle)) {
            $this->parseArticle($this->parser, $objArticle);
            $this->stripUselessTagsInArticle($this->arrAttributesAvailable, $objArticle);
            return true;
        }

        //clear tags, tries to avoid code like <p><div> etc.
        $this->clearTagsInArticle($objArticle);

        //replace tags
        $this->replaceStartTagsInArticle($objArticle);

        $this->stripUselessTagsInArticle($this->arrAttributesAvailable, $objArticle);
    }

    /**
     * replaces placeholder tags {qlmarkdown ...} with actual html code
     * @param $context
     * @return mixed
     * @internal param $text
     */
    private function checkContext($context)
    {
        //if search => ignore
        if ('com_finder.indexer' === $context) return false;
        $whitelist = $this->params->get('contextWhitelist', '');
        $blacklist = $this->params->get('contextBlacklist', '');
        if (!empty($blacklist) && false !== strpos($blacklist, $context)) return false;
        if (!empty($whitelist) && false === strpos($whitelist, $context)) return false;

        return true;
    }

    /**
     * replaces placeholder tags {qlmarkdown ...} with actual html code
     * @param $objArticle
     * @return mixed
     * @internal param $text
     */
    private function tagExistsInArticle($objArticle)
    {
        if (isset($objArticle->text) && $this->tagExists($objArticle->text)) return true;
        if (isset($objArticle->introtext) && $this->tagExists($objArticle->introtext)) return true;
        if (isset($objArticle->fulltext) && $this->tagExists($objArticle->fulltext)) return true;
        return false;
    }

    /**
     * replaces placeholder tags {qlmarkdown ...} with actual html code
     * @param $objArticle
     * @return mixed
     * @internal param $text
     */
    private function checkGlobal($objArticle)
    {
        if (0 == $this->params->get('global', 0)) return false;

        if (isset($objArticle->text) && false === strpos($objArticle->text, $this->offTag)) return true;
        if (isset($objArticle->introtext) && false === strpos($objArticle->introtext, $this->offTag)) return true;
        if (isset($objArticle->fulltext) && false === strpos($objArticle->fulltext, $this->offTag)) return true;
        return false;
    }

    /**
     * replaces placeholder tags {qlmarkdown ...} with actual html code
     * @param $string
     * @return mixed
     * @internal param $text
     */
    private function tagExists($string = '')
    {
        $return = false;
        if (false !== strpos($string, '{' . $this->strCallStart) && false !== strpos($string, '{' . $this->strCallEnd . '}')) $return = true;
        return $return;
    }

    /**
     * replaces placeholder tags {qlmarkdown ...} with actual html code
     * @param $objArticle
     * @return mixed
     * @internal param $text
     */
    private function replaceStartTagsInArticle(&$objArticle) {
        if (isset($objArticle->text)) $objArticle->text = $this->replaceStartTags($objArticle->text);
        if (isset($objArticle->introtext)) $objArticle->introtext = $this->replaceStartTags($objArticle->introtext);
        if (isset($objArticle->fulltext)) $objArticle->fulltext = $this->replaceStartTags($objArticle->fulltext);
    }

    /**
     * replaces placeholder tags {qlmarkdown ...} with actual html code
     * @param $strText
     * @return mixed
     * @internal param $text
     */
    private function replaceStartTags($strText)
    {
        // get matches
        $arrMatches = $this->getMatches($strText);

        //if no matches found (can't be, but just in case ...)
        if (0 === count($arrMatches) || !isset($arrMatches[0])) {
            return $strText;
        }

        // write into more beautiful variables
        $complete = $arrMatches[0];
        $attributes = $arrMatches[1];
        $content = $arrMatches[2];

        //iterate through matches
        foreach ($complete as $numKey => $strContent) {
            //get replacement array (written to class variable)
            $this->arrReplace[$numKey] = $this->getAttributes($this->arrAttributesAvailable, $attributes[$numKey]);
            $parser = !empty($this->arrReplace[$numKey]['parser']) ? $this->arrReplace[$numKey]['parser'] : $this->parser;
            $endpoint = !empty($this->arrReplace[$numKey]['endpoint']) ? $this->arrReplace[$numKey]['endpoint'] : $this->endpoint;
            $text = $this->parse($parser, $content[$numKey], $endpoint);

            // for reasons obsolutely obscure, SOME tags are turned into html &lg; while others are NOT. something's rotten here ...
            $text = str_replace('&lt;', '<', $text);
            $text = str_replace('&gt;', '>', $text);

            $this->arrReplace[$numKey]['content'] = $text;

            //get html code
            $this->arrReplace[$numKey]['html'] = $this->getHtml($numKey, $this->arrReplace[$numKey]);
        }

        //iterate through matches
        foreach ($complete as $numKey => $strContent) {
            $strText = str_replace($strContent, $this->arrReplace[$numKey]['html'], $strText);
        }

        //return text
        return $strText;
    }

    /**
     * parses markdown string as html
     * @param $parser
     * @param $objArticle
     * @param string $endpoint
     * @return mixed
     * @internal param $text
     */
    private function parseArticle($parser, &$objArticle, $endpoint = '') {
        if (isset($objArticle->text)) $objArticle->text = $this->parse($parser, $objArticle->text, $endpoint);
        if (isset($objArticle->introtext)) $objArticle->introtext = $this->parse($parser, $objArticle->introtext, $endpoint);
        if (isset($objArticle->fulltext)) $objArticle->fulltext = $this->parse($parser, $objArticle->fulltext, $endpoint);
    }

    /**
     * parses markdown string as html
     * @param $parser
     * @param string $text
     * @param string $endpoint
     * @return mixed
     * @internal param $text
     */
    private function parse($parser, $text = '', $endpoint = '')
    {
        switch ($parser) {
            case 'wikipedia-api-post':
                $endPoint = !empty($endpoint) ? $endpoint : $this->params->get('apiendpoint', "https://en.wikipedia.org/w/api.php");
                $text = $this->parseWikipediaApiPost($endPoint, $text);
                break;
            case 'michelf-php-markdown':
                $text = $this->parseMichelfPhpMarkdown($text);
                break;
            case 'michelf-php-markdown-extra':
                $text = $this->parseMichelfPhpMarkdownExtra($text);
                break;
            case 'erusev-parsedown':
            default:
                $text = $this->parseErusevParsedown($text);
                break;
        }
        return $text;
    }

    /**
     * parses markdown string as html
     * @param $endPoint
     * @param string $text
     * @return mixed
     * @internal param $text
     */
    private function parseWikipediaApiPost($endPoint, $text = '')
    {
        $params = [
            "action" => "parse",
            "text" => $text,
            "format" => "json"
        ];

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $endPoint);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $output = curl_exec($ch);
        curl_close($ch);

        $obj = json_decode($output);
        $text = isset($obj->parse->text->{'*'}) ? $obj->parse->text->{'*'} : $text;
        return $text;
    }

    /**
     * parses markdown string as html
     * @param string $text
     * @return mixed
     * @internal param $text
     */
    private function parseMichelfPhpMarkdown($text = '')
    {
        $text = strip_tags($text);
        $text = Markdown::defaultTransform($text);
        return $text;
    }

    /**
     * parses markdown string as html
     * @param string $text
     * @return mixed
     * @internal param $text
     */
    private function parseMichelfPhpMarkdownExtra($text = '')
    {
        $text = strip_tags($text);
        $text = MarkdownExtra::defaultTransform($text);
        return $text;
    }

    /**
     * parses markdown string as html
     * @param string $text
     * @return mixed
     * @internal param $text
     */
    private function parseErusevParsedown($text = '')
    {
        $Parsedown = new Parsedown();
        $text = $Parsedown->text(strip_tags($text));
        return $text;
    }

    /**
     * @param $string
     * @return array
     */
    private function getMatches($string)
    {
        //get matches to {qlmarkdown}
        $strRegex = '~{' . $this->strCallStart . '(.*?)}(.+?){' . $this->strCallEnd . '}~s';
        preg_match_all($strRegex, $string, $arrMatches);
        return $arrMatches;
    }

    /**
     * method to get attributes
     * @param $arrAttributesAvailable
     * @param $string
     * @return array
     */
    private function getAttributes($arrAttributesAvailable, $string)
    {
        $arrMatches = $this->filterStartTags($arrAttributesAvailable, $string);
        $arrAttributes = [];
        if (is_array($arrMatches)) {
            foreach ($arrMatches[0] as $k => $v) {
                if (isset($arrMatches[1][$k]) && isset($arrMatches[2][$k])) {
                    $arrAttributes[$arrMatches[1][$k]] = $arrMatches[2][$k];
                }
            }
        }
        return $arrAttributes;
    }

    /**
     * method to get attributes
     * @param $arrAttributesAvailable
     * @param $objArticle
     */
    private function stripUselessTagsInArticle($arrAttributesAvailable, &$objArticle)
    {
        $strSelector = implode('|', $arrAttributesAvailable);

        if (isset($objArticle->text)) {
            $objArticle->text = str_replace('{qlmarkdown}', '', $objArticle->text);
            $objArticle->text = preg_replace('~(' . $strSelector . ')="(.+?)"~s', '', $objArticle->text);
            $objArticle->text = str_replace('{' . $this->strCallEnd . '}', '', $objArticle->text);
        }

        if (isset($objArticle->fulltext)) {
            $objArticle->fulltext = str_replace('{qlmarkdown}', '', $objArticle->fulltext);
            preg_replace('~(' . $strSelector . ')="(.+?)"~s', '', $objArticle->fulltext);
            str_replace('{' . $this->strCallEnd . '}', '', $objArticle->fulltext);
        }

        if (isset($objArticle->introtext)) {
            $objArticle->introtext = str_replace('{qlmarkdown}', '', $objArticle->introtext);
            $objArticle->introtext = preg_replace('~({qlmarkdown (' . $strSelector . ')="(.+?)"})}~s', '', $objArticle->introtext);
            $objArticle->introtext = str_replace('{' . $this->strCallEnd . '}', '', $objArticle->introtext);
        }
    }

    /**
     * method to get attributes
     * @param $arrAttributesAvailable
     * @param $string
     * @return array
     */
    private function filterStartTags($arrAttributesAvailable, $string)
    {
        $strSelector = implode('|', $arrAttributesAvailable);
        preg_match_all('~(' . $strSelector . ')="(.+?)"~s', $string, $arrMatches);
        return $arrMatches;
    }

    /**
     * method to clear tags
     * @param $objArticle
     * @return mixed
     */
    private function clearTagsInArticle(&$objArticle)
    {
        if (isset($objArticle->text)) $objArticle->text = $this->clearTags($objArticle->text);
        if (isset($objArticle->introtext)) $objArticle->introtext = $this->clearTags($objArticle->introtext);
        if (isset($objArticle->fulltext)) $objArticle->fulltext = $this->clearTags($objArticle->fulltext);
    }

    /**
     * method to clear tags
     * @param $str
     * @return mixed
     */
    private function clearTags($str)
    {
        $str = str_replace('<p>{' . $this->strCallEnd . '}', '{' . $this->strCallEnd . '}<p>', $str);
        $str = str_replace('{' . $this->strCallEnd . '}', '{' . $this->strCallEnd . '}', $str);
        $str = preg_replace('!<p>{' . $this->strCallStart . '}</p>!', '{' . $this->strCallStart . '}', $str);
        $this->debugPrintText($str);
        return $str;
    }

    /**
     * method to clear tags
     * @param $objArticle
     * @return mixed
     */
    private function clearOffTagsInArticle(&$objArticle)
    {
        if (isset($objArticle->text)) $objArticle->text = $this->clearOffTags($objArticle->text);
        if (isset($objArticle->introtext)) $objArticle->introtext = $this->clearOffTags($objArticle->introtext);
        if (isset($objArticle->fulltext)) $objArticle->fulltext = $this->clearOffTags($objArticle->fulltext);
    }

    /**
     * method to clear tags
     * @param $str
     * @return mixed
     */
    private function clearOffTags($str)
    {
        return str_replace($this->offTag, '', $str);
    }

    /**
     * @param $str
     */
    private function debugPrintText($str)
    {
        if (!$this->boolDebug) {
            return;
        }
        echo '<pre>' . htmlspecialchars($str) . '</pre>';
    }

    /**
     * @param $intCounter
     * @param $arrData
     * @return string
     */
    private function getHtml($intCounter, $arrData)
    {
        // initiating variables for output
        $objParams = $this->params;
        extract($arrData);
        $class = isset($class) ? $class : '';
        $id = isset($id) ? $id : 'qlmarkdown' . $intCounter;
        $style = isset($style) ? $style : '';
        $type = isset($type) ? $type : '';
        $title = isset($title) ? $title : '';
        $layout = isset($layout) ? $layout : '';

        // get layout path
        $strPathLayout = $this->getLayoutPath($this->_type, $this->_name, $layout);

        // load into buffer, and return
        ob_start();
        include $strPathLayout;
        $strHtml = ob_get_contents();
        ob_end_clean();
        return $strHtml;
    }

    /**
     * @param $extType
     * @param $extName
     * @param $layout
     * @return string
     */
    private function getLayoutPath($extType = 'content', $extName = 'qlmarkdown', $layout)
    {
        $strLayoutFile = !empty($layout) ? $layout : $this->params->get('layout', $layout);
        $strPathLayout = JPluginHelper::getLayoutPath($extType, $extName, $strLayoutFile);
        if (!file_exists($strPathLayout)) {
            $strPathLayout = JPluginHelper::getLayoutPath($extType, $extName, 'default');
        }
        return $strPathLayout;
    }

    /**
     * method to get matches according to search string
     * @internal param string $text haystack
     * @internal param string $searchString needle, string to be searched
     */
    private function getStyles()
    {
        $numBorderWidth = $this->params->get('borderwidth');
        $strBorderColor = $this->params->get('bordercolor');
        $strBorderType = $this->params->get('bordertype');
        $strFontColor = $this->params->get('fontcolor');
        $numPadding = $this->params->get('padding');
        $numOpacity = $this->params->get('backgroundopacity');
        $strBackgroundColor = $this->getBgColor($this->params->get('backgroundcolor'), $numOpacity);

        $arrStyle = [];
        $arrStyle[] = '.qlmarkdown {color:' . $strFontColor . '; border:' . $numBorderWidth . 'px ' . $strBorderType . ' ' . $strBorderColor . '; padding:' . $numPadding . 'px; background:' . $strBackgroundColor . ';}';
        $strStyle = implode("\n", $arrStyle);
        JFactory::getDocument()->addStyleDeclaration($strStyle);
    }

    /**
     *
     */
    private function includeScripts()
    {
        if (1 == $this->params->get('jquery')) {
            JHtml::_('jquery.framework');
        }
        JHtml::_('script', JUri::root() . 'media/plg_content_qlmarkdown/js/qlmarkdown.js');
        JHtml::_('stylesheet', JUri::root() . 'media/plg_content_qlmarkdown/css/qlmarkdown.css');
    }

    /**
     * @param $bg
     * @param $opacity
     * @return string
     */
    private function getBgColor($bg, $opacity)
    {
        include_once __DIR__ . '/php/clsPlgContentQlmarkdownColor.php';
        $objColor = new clsPlgContentQlmarkdownColor;
        $arr = $objColor->html2rgb($bg);
        $numOpacity = $opacity / 100;
        return 'rgba(' . implode(',', $arr) . ',' . $numOpacity . ')';
    }
}
