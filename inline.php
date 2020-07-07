<?php

require 'diatom.php';

class Inline {
  const LOOKUP = [
    '~~' => 's',
    '**' => 'strong',
    '__' => 'em',
    '``' => 'time',
    '`'  => 'code',
    '^^' => 'abbr',
    '|'  => 'mark',
    '"'  => 'q',
  ];
  
  private $DOM, $node;
  
  public function __construct(Element $node) {
    $this->node = $node;
    $this->DOM  = $node->ownerDocument;
  } 
  
  public function parse(?Element $node = null) {
    $node ??= $this->node;

    $matches = [];
    $text = $node->nodeValue;

    array_push($matches, ...$this->gather('/(!?)\[([^\[\]]+)\]\(([^\)]+?)(?:\"([^"]+)\")?\)/u', $text, [$this, 'link']));
    array_push($matches, ...$this->gather('/(~~|\*\*|__|\||``|`|\^\^|\")(?:(?!\1).)+\1/u', $text, [$this, 'basic']));

    if ($node->nodeName == 'li')
      array_push($matches, ...$this->gather('/^\[([x\s])\](.*)$/u', $text, [$this, 'input']));
    
    usort($matches, fn($A, $B) => $B[0] <=> $A[0]);
    $matches = array_filter($matches, fn($v, $k) => ($matches[$k+1][2] ?? 0) < $v[2], ARRAY_FILTER_USE_BOTH);
    
    $context = $node->firstChild;
    
    foreach ($matches as [$start, $end, $length, $swap]) {
      $stub = $context->splitText($start)->splitText($end)->previousSibling;
      $node->replaceChild($swap, $stub);
      $this->parse($swap);
    }
    return $node;
  }
  
  public function gather($rgxp, $text, callable $callback)
  {
    preg_match_all($rgxp, $text, $matches, PREG_OFFSET_CAPTURE|PREG_SET_ORDER);
    return array_map($callback, $matches);
  }
    
  private function basic($match)
  {
    $symbol = $match[1][0];
    $node   = $this->DOM->createElement(self::LOOKUP[$symbol], trim($match[0][0], $symbol));
    $end = strlen($match[0][0]);
    return [$match[0][1], $end, $match[0][1] + $end, $node];
  }
  
  
  private function link($match)
  {
    if ($match[1][0]) {
      $node = $this->DOM->createElement('img');
      $node->setAttribute('src', $match[3][0]);
      $node->setAttribute('alt',  $match[2][0]);
    } else {
      $node = $this->DOM->createElement('a', $match[2][0]);
      $node->setAttribute('href', $match[3][0]);
      if ($title = ($match[4][0] ?? false))
        $node->setAttribute('title', $title);
    }

    $end = strlen($match[0][0]);
    return [$match[0][1], $end, $match[0][1] + $end, $node];
  }

  private function input($match)
  {
    $node = $this->DOM->createElement('label', $match[2][0]);
    $input = $node->insertBefore($this->DOM->createElement('input'), $node->firstChild);
    $input->setAttribute('type', 'checkbox');
    if ($match[1][0] != ' ') $input->setAttribute('checked', 'checked');
    $end = strlen($match[0][0]);
    return [0, $end, $end, $node];
  }
}



$sample = <<<EOT
<p>What is the dependency map? I |**want**| ^^to^^ match ~~squiggles~~ in this `code` at ``2pm`` with a "quote for good measure" **string** of text and __this will be italicized__ and I'd like to use an analysis tool like **[graphviz](http://www.graphviz.org/)**, especially a gem [gem depenedencies visualizer](http://patshaughnessy.net/2011/9/17/bundlers-best-kept-secret). Pending no success there, I may have to resort to a homemade ^^svg^^ chart or something. ![fancy image](/somethin.jpg)</p>
EOT;

$list = '<li>[x] testing list</li>';
$doc = new Document($sample);

$elem = new Inline($doc->documentElement);
$elem->parse();

echo $doc->saveXML();