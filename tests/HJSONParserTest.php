<?php

namespace HJSON\Tests;

require_once('src/HJSON/HJSONParser.php');
require_once('src/HJSON/HJSONStringifier.php');
require_once('src/HJSON/HJSONException.php');
require_once('src/HJSON/HJSONUtils.php');

use HJSON\HJSONParser;
use HJSON\HJSONStringifier;
use HJSON\HJSONException;

class HJSONParserTest
{

    public function assertEquals($a, $b)
    {
        if ($a !== $b) {
            echo "\n\n";
            $a2 = preg_split('/\r\n|\r|\n/', $a);
            $b2 = preg_split('/\r\n|\r|\n/', $b);
            $indexA = $indexB = 0;
            for (; $indexB < count($b2); $indexA++, $indexB++) {
                if ($indexB >= count($a2) || $b2[$indexB] !== $a2[$indexB]) {
                    $indexA = $indexB;
                    echo "Expected ($this->lastFilename, line $indexB):\n\n";
                    while ($indexB < count($b2) && (
                           $indexB >= count($a2) ||
                           $b2[$indexB] !== $a2[$indexB])) {
                        echo "|".$b2[$indexB++]."|\n";
                    }

                    echo "\n\nGot:\n\n";
                    while ($indexA < count($a2) && $indexA < $indexB) {
                        echo "|".$a2[$indexA++]."|\n";
                    }

                    echo "\n\n";
                }
            }

            if ($indexA < count($a2)) {
                echo "\n\nGot trailing lines vs $this->lastFilename:\n\n";
                while ($indexA < count($a2)) {
                    echo "|".$a2[$indexA++]."|\n";
                }
                echo "\n\n";
            }

            throw new HJSONException();
        }
    }

    public function setUp()
    {
        $this->rootDir = dirname(__FILE__).DIRECTORY_SEPARATOR."assets";
        $this->lastFilename = '';
    }

    private function load($file, $cr)
    {
        $this->lastFilename = $file;
        $text = file_get_contents($this->rootDir.DIRECTORY_SEPARATOR.$file);
        $std = mb_ereg_replace('/\r/', "", $text); // make sure we have unix style text regardless of the input
        return $cr ? mb_ereg_replace("\n", "\r\n", $std) : $std;
    }

    private function runEach($name, $file, $isJson, $inputCr, $outputCr)
    {
        echo "Running test for $name, $file, ".(+$isJson).', '.(+$inputCr).', '.(+$outputCr)."\n";
        $text = $this->load($file, $inputCr);
        $shouldFail = substr($name, 0, 4) === "fail";

        try {
            $parser = new HJSONParser();
            $data = $parser->parse($text);

            $arrayData = $parser->parse($text, ['assoc' => true]);
            $this->assertEquals($arrayData, json_decode(json_encode($data), true));

            if (!$shouldFail) {
                if ($isJson) {
                    // compare Hjson parse to JSON parse
                    $json1 = json_encode($data);
                    $json2 = json_encode(json_decode($text));
                    $this->assertEquals($json1, $json2);
                }

                $text1 = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                $stringifier = new HJSONStringifier();
                $hjson1 = $stringifier->stringify($data, [
                    'eol' => $outputCr ? "\r\n" : "\n",
                    'emitRootBraces' => true,
                    'space' => 2
                ]);
                $result = json_decode($this->load("{$name}_result.json", $inputCr));
                $text2 = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                $this->assertEquals($text1, $text2);
                $hjson2 = $this->load("{$name}_result.hjson", $outputCr);
                $this->assertEquals($hjson1, $hjson2);
            } else {
                $this->markTestIncomplete('This test succeeded on data that should fail.');
            }
        } catch (HJSONException $e) {
            if (!$shouldFail) {
                echo "\n$e\n";
                throw $e;
            }
        }
    }

    public function testAll()
    {
        $hasFailure = false;
        $files = array_diff(scandir($this->rootDir), ['..', '.']);
        foreach ($files as $file) {
            $name = explode('_test.', $file);
            if (count($name) < 2) {
                continue;
            }
            $isJson = $name[1] === "json";
            $name = $name[0];

            // skip empty test, empty keys are not supported by PHP
            if ($name === "empty") {
                continue;
            }

            try {
                $this->runEach($name, $file, $isJson, false, false);
            } catch (HJSONException $e) {
                $hasFailure = true;
            }
            try {
                $this->runEach($name, $file, $isJson, false, true);
            } catch (HJSONException $e) {
                $hasFailure = true;
            }
            try {
                $this->runEach($name, $file, $isJson, true, false);
            } catch (HJSONException $e) {
                $hasFailure = true;
            }
            try {
                $this->runEach($name, $file, $isJson, true, true);
            } catch (HJSONException $e) {
                $hasFailure = true;
            }
        }

        if ($hasFailure) {
            exit(1);
        }
    }
}

$tester = new HJSONParserTest;
$tester->setUp();
$tester->testAll();
