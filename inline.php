<?php

// require 'diatom.php';

class Inline {
  private static $rgxp = null;
  
  const MAP = [
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
    self::$rgxp ??= [
      'pair' => sprintf('/(%s)(?:(?!\1).)+\1/u', join('|', array_map(fn($k)=> addcslashes($k, '!..~'), array_keys(self::MAP)))),
      'link' => '/(!?)\[([^\]]+)\]\((\S+)\)/u'
    ];
    
    $this->DOM  = $node->ownerDocument;
    $this->node = $node;
  } 
  
  public function parse(?Element $node = null) {
    $node ??= $this->node;

    $text = $node->nodeValue;
    
    $matches = [
      ...$this->gather(self::$rgxp['link'], $text, [$this, 'link']),
      ...$this->gather(self::$rgxp['pair'], $text, [$this, 'basic']),
    ];
    
    if ($node->nodeName == 'li')
      array_push($matches, ...$this->gather('/^\[([x\s])\](.*)$/u', $text, [$this, 'input']));
    
    usort($matches, fn($A, $B)=> $B[0] <=> $A[0]);
    
    foreach ($matches as $i => [$in, $out, $end, $elem]) {
      // check for nested inline elements, skip, they will be parsed separately
      if (($matches[$i+1][2] ?? 0) > $end) continue;
      
      $node->replaceChild($elem, $node->firstChild->splitText($in)->splitText($out)->previousSibling);
      $this->parse($elem);
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
    $node   = $this->DOM->createElement(self::MAP[$symbol], trim($match[0][0], $symbol));
    $out = strlen($match[0][0]);
    return [$match[0][1], $out, $match[0][1] + $out, $node];
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
    }
    $out = strlen($match[0][0]);
    return [$match[0][1], $out, $match[0][1] + $out, $node];
  }

  private function input($match)
  {
    $node = $this->DOM->createElement('label', $match[2][0]);
    $input = $node->insertBefore($this->DOM->createElement('input'), $node->firstChild);
    $input->setAttribute('type', 'checkbox');
    if ($match[1][0] != ' ') $input->setAttribute('checked', 'checked');
    $out = strlen($match[0][0]);
    return [0, $out, $out, $node];
  }
}



// $sample = <<<EOT
// <p>What is the dependency map? I |**want**| ^^to^^ match ~~squiggles~~ in this `code` at ``2pm`` with a "quote for good measure" **string** of text and __this will be italicized__ and I'd like to use an analysis tool like **[graphviz](http://www.graphviz.org/)**, especially a gem [gem depenedencies visualizer](http://patshaughnessy.net/2011/9/17/bundlers-best-kept-secret). Pending no success there, I may have to resort to a homemade ^^svg^^ chart or something. ![fancy image](/somethin.jpg)</p>
// EOT;
//
// $list = '<li>[x] testing list</li>';
// $doc = new Document($sample);
//
// $elem = new Inline($doc->documentElement);
// $elem->parse();
//
// echo $doc->saveXML($doc->documentElement);