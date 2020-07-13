<?php
$mark = microtime(true);
// $test = <<<EOD
//
// # Test h1
// #### Test h4
// ####### Test h7 (shouldnt work)
//
//
// 1. ordered one
// 2. ordered two
//   - nested unordered [one](/url)
//   - nested unordered two
//     - unordered double nested one
//     - unordered double nested two
//       1. orderded triple nested one
//       2. ordered triple nested two
//     - unordered double nested three
// 3. ordered three
//
// - unordered one
// - unordered two
//
// this is some paragraph <strong>text</strong> with **strong** and this is a [![img](http://sometthing.com)](http://example.com) and another [thing](http://whatever.com) and then I'm finding some more [text](to link to)"something great"
//
// > this is a blockquote paragraph "with some quoted text."
//
// EOD;
// echo "------ ORIGINAL --------\n\n\n\n ".$test . "\n\n\n\n";

// include 'vendor-markdown.php';
//
// // include('vendor-markdown.php');
// echo "\n\n\n\n ------ CLASSIC ---------\n\n\n\n" . (new Markdown)->text(file_get_contents('example.md')) . "\n";

include 'src/parse.php';

$parser = new Parse('examples/complex.md');

$parser->addCallback(function(DOMDocument $document) {
  
  foreach ($this->xpath->query('//article/h2[not(ancestor::section)]') as $mark) {
    $section = $document->createElement('section');
    $mark = $mark->parentNode->replaceChild($section, $mark);
    $section->appendChild($mark);
    $sibling = $section->nextSibling;
    while($sibling && $sibling->nodeName != 'h2') {
      $next = $sibling->nextSibling;
      $section->appendChild($sibling);
      $sibling = $next;
    }
  }
  
  // find all sections without id
  foreach($this->xpath->query('//section[not(@id)]/h2') as $idx => $h2)
    $h2->parentNode->setAttribute('id', strtoupper(preg_replace('/[^a-z0-9]/i','', $h2->nodeValue)));
  
});

echo $parser;

echo (microtime(true) - $mark). 'sec, mem:' . (memory_get_peak_usage() / 1000) . "kb\n";
