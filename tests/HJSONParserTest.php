<?php

use HJSON\HJSONParser;
use HJSON\HJSONStringifier;
use HJSON\HJSONException;


class HJSONParserTest extends PHPUnit_Framework_TestCase {


    public function setUp()
    {
        parent::setUp();
        $this->rootDir = dirname(__FILE__).DIRECTORY_SEPARATOR."assets";
        $this->parser = new HJSONParser();
        $this->stringifier = new HJSONStringifier();
    }

    private function load($file, $cr)
    {
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
            $data = $this->parser->parse($text);

            if (!$shouldFail) {
                $text1 = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                $hjson1 = $this->stringifier->stringify($data, [
                    'eol' => $outputCr ? "\r\n" : "\n",
                    'emitRootBraces' => true,
                    'space' => 2
                ]);
                $result = json_decode($this->load("{$name}_result.json", $inputCr));
                $text2 = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                $hjson2 = $this->load("{$name}_result.hjson", $outputCr);
                $this->assertEquals($text1, $text2);
                $this->assertEquals($hjson1, $hjson2);
                if ($isJson) {
                    // also compare Hjson parse to JSON parse
                    $json1 = json_encode($data);
                    $json2 = json_encode(json_decode($text));
                    $this->assertEquals($json1, $json2);
                }
            }
            else $this->assertTrue(false);
        }
        catch (HJSONException $e) {
            if (!$shouldFail) throw $e;
        }
    }

    public function testAll()
    {
        $files = array_diff(scandir($this->rootDir), ['..', '.']);
        foreach ($files as $file) {
            $name = explode('_test.', $file);
            if (count($name) < 2) continue;
            $isJson = $name[1] === "json";
            $name = $name[0];

            // skip empty test
            if ($name === "empty") continue;

            $this->runEach($name, $file, $isJson, false, false);
            $this->runEach($name, $file, $isJson, false, true);
            $this->runEach($name, $file, $isJson, true, false);
            $this->runEach($name, $file, $isJson, true, true);
        }
    }
}
