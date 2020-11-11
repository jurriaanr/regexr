<?php
/*
RegExr: Learn, Build, & Test RegEx
Copyright (C) 2017  gskinner.com, inc.

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program.  If not, see <https://www.gnu.org/licenses/>.
*/

namespace regex;

class solve extends \core\AbstractAction {
    public $description = 'Run PCRE functions.';

    private $errorMessage;
    private $errorCode;

    public function execute() {
        $data = json_decode($_POST['data']);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \core\APIError(\core\ErrorCodes::API_INVALID_JSON, json_last_error_msg());
        }

        $id = property_exists($data, "id")?$data->id:null;
        $pattern = $data->pattern;
        $delimiter = $data->delimiter;
        $modifiers = idx($data, "flags");
        $text = idx($data, "text");
        $tool = idx($data, "tool");
        $mode = idx($data, "mode");
        $tests = idx($data, "tests");

        if (strrpos($modifiers, 'g') !== false) {
            $global = true;
            $modifiers = str_replace('g', '', $modifiers);
        } else {
            $global = false;
        }

        $result = null;

        switch ($mode) {
            case 'tests':
                $result = $this->runTests($tests, $pattern, $delimiter, $modifiers, $global, $id);
                break;
            case 'text':
            default:
                $result = $this->parseText($pattern, $delimiter, $modifiers, $text, $tool, $global, $id);
        }

        return new \core\Result($result);
    }

    function runTests($tests, $pattern, $delimiter, $modifiers, $global, $requestId) {
        $testResults = [];
        $startTime = now();
        for ($i=0; $i < count($tests); $i++) {
            $id = $tests[$i]->id;
            $text = $tests[$i]->text;
            $matches = [];

            // PCRE functions throw warnings if something is malformed or errors out.
            set_error_handler(array($this, 'pcreWarningHandler'), E_WARNING);
            if ($global === true) {
                $match = preg_match_all("{$delimiter}{$pattern}{$delimiter}{$modifiers}", $text, $matches, PREG_OFFSET_CAPTURE|PREG_SET_ORDER);
            } else {
                $match = preg_match("{$delimiter}{$pattern}{$delimiter}{$modifiers}", $text, $matches, PREG_OFFSET_CAPTURE);
            }
            // Stop capturing warnings.
            restore_error_handler();

            if ($match > 0) {
                $testResults[] = [
                    "id" => $id,
                    "i" => $matches[0][0][1],
                    "l" => \realStringLength($matches[0][0][0])
                ];
            } else if ($match === 0) {
                $testResults[] = ["id" => $id];
            } else if ($match === false) {
                $testResults[] = [
                    "id" => $id,
                    "error" => $this->getLastError()
                ];
            }
        }

        $endTime = now();
        $totalTime = floatval(number_format($endTime - $startTime, 4, '.', ''));

        return [
            'id' => $requestId,
            'timestamp' => time(),
            'time' => $totalTime,
            'mode' => "tests",
            'matches' => $testResults
        ];
    }

    function parseText($pattern, $delimiter, $modifiers, $text, $tool, $global, $requestId) {
        // PCRE functions throw warnings if something is malformed or errors out.
        set_error_handler(array($this, 'pcreWarningHandler'), E_WARNING);

        $toolResult = "";

        switch (strtolower($tool->id)) {
            case 'replace':
                $toolResult = $this->pcreReplace($pattern, $delimiter, $modifiers, $text, $tool->input);
                break;
            case 'list':
                $toolResult = $this->pcreList($pattern, $delimiter, $modifiers,  $text, $tool->input);
                break;
        }

        $startTime = now();
        $matches = $this->pcreMatch($pattern, $delimiter, $modifiers, $global, $text);
        $endTime = now();

        // Stop capturing warnings.
        restore_error_handler();

        $totalTime = floatval(number_format($endTime - $startTime, 4, '.', ''));

        $result = [
            'id' => $requestId,
            'timestamp' => time(),
            'time' => $totalTime,
            'matches' => $matches,
            'mode' => "text",
            'tool' => [
                'id' => $tool->id,
                'result' => $toolResult
            ]
        ];

        $error = $this->getLastError();

        if (!is_null($error)) {
            $result['error'] = $error;
        }

        return $result;
    }

    function getLastError() {
        if (!is_null($this->errorMessage) || !is_null($this->errorCode)) {
            return [
                'message' => $this->errorMessage,
                'name' => $this->getRegexErrorCodeString($this->errorCode),
                'id' => $this->pcreErrorCodeToJS($this->errorCode)
            ];
        }

        return null;
    }

    function pcreList($pattern, $delimiter, $modifiers, $source, $str) {
        $results = [];
        preg_replace_callback("{$delimiter}{$pattern}{$delimiter}{$modifiers}", function($matches) use ($pattern, $delimiter, $modifiers, $str, &$results) {
            $results[] = preg_replace("{$delimiter}{$pattern}{$delimiter}{$modifiers}", $str, $matches[0]);
            return $matches[0];
        }, $source);

        return implode("", $results);
    }

    function pcreReplace($pattern, $delimiter, $modifiers, $text, $input) {
        return preg_replace("{$delimiter}{$pattern}{$delimiter}{$modifiers}", $input, $text);
    }

    function pcreMatch($pattern, $delimiter, $modifiers, $global, $text) {
        $matches = [];
        $jsonMatches = [];

        if ($global === true) {
            $match = preg_match_all("{$delimiter}{$pattern}{$delimiter}{$modifiers}", $text, $matches, PREG_OFFSET_CAPTURE|PREG_SET_ORDER);
        } else {
            $match = preg_match("{$delimiter}{$pattern}{$delimiter}{$modifiers}", $text, $matches, PREG_OFFSET_CAPTURE);
        }

        if ($global === true && $match !== false) {
            for ($i=0;$i<count($matches);$i++) {
                $match = $matches[$i];
                $first = array_shift($match);
                $jsonMatches[] = $this->createMatchEntry($first, $match, $text);
            }
        } elseif ($match !== false) {
            $first = array_shift($matches);
            $jsonMatches[] = $this->createMatchEntry($first, $matches, $text);
        }

        return $jsonMatches;
    }

    function createMatchEntry($match, $groups, $text) {
        $result = [];

        $txt = $match[0];
        $index = intval($match[1]);
        $result['i'] = \mb_strlen(\mb_strcut($text, 0, $index));
        $result['l'] = realStringLength($txt);
        $result['groups'] = [];

        foreach($groups as $key => $group) {
            if (is_int($key)) {
                $index = intval($group[1]);
                $matchResult = $group[0];
                $result['groups'][] = [
                    'i' => \mb_strlen(\mb_strcut($text, 0, $index)),
                    'l' => realStringLength($matchResult)
                ];
            }
        }
        return $result;
    }

    function pcreWarningHandler($errCode, $errstr) {
        $this->errorCode = $errCode;
        $this->errorMessage = preg_replace("/^[a-z_():\s]+/", "", $errstr);
    }

    function pcreErrorCodeToJS($code) {
        $errorId = null;
        switch ($code) {
            case PREG_INTERNAL_ERROR:
                $errorId = 'error';
                break;
            case PREG_BACKTRACK_LIMIT_ERROR:
            case PREG_RECURSION_LIMIT_ERROR:
            case PREG_JIT_STACKLIMIT_ERROR: // PHP 7.0 only
                $errorId = 'infinite';
                break;
            case PREG_BAD_UTF8_ERROR:
            case PREG_BAD_UTF8_OFFSET_ERROR:
                $errorId = 'badutf8';
                break;
            case PREG_NO_ERROR:
            default:
                break;
        }
        return $errorId;
    }

    function getRegexErrorCodeString($errorCode) {
        $stringCode = '';

        switch ($errorCode) {
            case PREG_INTERNAL_ERROR:
                $stringCode = 'PREG_INTERNAL_ERROR';
                break;
            case PREG_BACKTRACK_LIMIT_ERROR:
                $stringCode = 'PREG_BACKTRACK_LIMIT_ERROR';
                break;
            case PREG_RECURSION_LIMIT_ERROR:
                $stringCode = 'PREG_RECURSION_LIMIT_ERROR';
                break;
            case PREG_BAD_UTF8_ERROR:
                $stringCode = 'PREG_BAD_UTF8_ERROR';
                break;
            case PREG_BAD_UTF8_OFFSET_ERROR:
                $stringCode = 'PREG_BAD_UTF8_OFFSET_ERROR';
                break;
            // PHP 7.0
            case PREG_JIT_STACKLIMIT_ERROR:
                $stringCode = 'PREG_JIT_STACKLIMIT_ERROR';
                break;
            case PREG_NO_ERROR:
                default:
                break;
        }
        return $stringCode;
    }

    public function requiresDatabase() {
        return false;
    }

    public function getSchema() {
        return array(
            'data' => array('type'=>self::STRING, 'required'=>true),
            'flavor' => array('type'=>self::ENUM, 'values'=>['pcre'], 'default'=>'pcre', 'required'=>false),
        );
    }
}
