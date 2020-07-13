<?php
$mark = microtime(true);

$file = 'examples/complex.md';

// include('vendor-markdown.php');
//
// echo (new Markdown)->text(file_get_contents($file)) . "\n";




include 'src/parse.php';

$parser = new Parse($file);

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
