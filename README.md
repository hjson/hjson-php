# hjson-php

[![Build Status](https://github.com/hjson/hjson-php/workflows/test/badge.svg)](https://github.com/hjson/hjson-php/actions)
[![Packagist](https://img.shields.io/packagist/v/laktak/hjson.svg?style=flat-square)](https://packagist.org/packages/laktak/hjson)

[Hjson](https://hjson.github.io), the Human JSON. A configuration file format for humans. Relaxed syntax, fewer mistakes, more comments.

![Hjson Intro](https://hjson.github.io/hjson1.gif)

```
{
  # specify rate in requests/second (because comments are helpful!)
  rate: 1000

  // prefer c-style comments?
  /* feeling old fashioned? */

  # did you notice that rate doesn't need quotes?
  hey: look ma, no quotes for strings either!

  # best of all
  notice: []
  anything: ?

  # yes, commas are optional!
}
```

The PHP implementation of Hjson is based on [hjson-js](https://github.com/hjson/hjson-js). For other platforms see [hjson.github.io](https://hjson.github.io).

# Install from composer

```
composer require laktak/hjson
```

# Usage

```
use HJSON\HJSONParser;
use HJSON\HJSONStringifier;

$parser = new HJSONParser();
$obj = $parser->parse(hjsonText);

$stringifier = new HJSONStringifier;
$text = $stringifier->stringify($obj);
```


# API

### HJSONParser: parse($text, $options)

This method parses *JSON* or *Hjson* text to produce an object or array.

- *text*: the string to parse as JSON or Hjson
- *options*: array
  - *keepWsc*: boolean, keep white space and comments. This is useful if you want to edit an hjson file and save it while preserving comments (default false)
  - *assoc*: boolean, return associative array instead of object (default false)

### HJSONStringifier: stringify($value, $options)

This method produces Hjson text from a value.

- *value*: any value, usually an object or array.
- *options*: array
  - *keepWsc*: boolean, keep white space. See parse.
  - *bracesSameLine*: boolean, makes braces appear on the same line as the key name. Default false.
  - *quotes*: string, controls how strings are displayed.
    - "min": no quotes whenever possible (default)
    - "always": always use quotes
  - *space*: specifies the indentation of nested structures. If it is a number, it will specify the number of spaces to indent at each level. If it is a string (such as '\t' or '&nbsp;'), it contains the characters used to indent at each level.
  - *eol*: specifies the EOL sequence


## modify & keep comments

You can modify a Hjson file and keep the whitespace & comments intact. This is useful if an app updates its config file.

```
$parser = new HJSONParser;
$stringifier = new HJSONStringifier;

$text = "{
  # specify rate in requests/second (because comments are helpful!)
  rate: 1000

  // prefer c-style comments?
  /* feeling old fashioned? */

  # did you notice that rate doesn't need quotes?
  hey: look ma, no quotes for strings either!

  # best of all
  notice: []
  anything: ?

  # yes, commas are optional!

  array: [
    // hello
    0
    1
    2
  ]
}";

// Parse, keep whitespace and comments
$data = $parser->parseWsc($text);

// Modify like you normally would
$data->rate = 500;

// You can also edit comments by accessing __WSC__
$wsc1 = &$data->__WSC__; // for objects
$wsc2 = &$data->array['__WSC__']; // for arrays

// __WSC__ for objects contains { c: {}, o: [] }
// - c with the actual comment and, firts comment is key ' '
// - o (array) with the order of the members
$emptyKey = " ";
$wsc1->c->$emptyKey = "\n  # This is the first comment";
$wsc1->c->rate = "\n  # This is the comment after rate";

// Sort comments order just because we can
sort($wsc1->o);

// Edit array comments
$wsc2[0] .= ' world';

// convert back to Hjson
$text2 = $stringifier->stringifyWsc($data);
```

# History

[see releases](https://github.com/hjson/hjson-php/releases)
