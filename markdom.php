<?php
/*
  TODO
  [ ] TYPOGRAPHY: replace ' with actual apostrophe, -- with n-dash --- with mdash
  [ ] deal with & breaking everything
  [ ] post-render h2's into sections
  [ ] think of syntax to post-render certain lists into definition lists
  [ ] consider |mark| into post-render <strong><strong>mark</strong></strong> (****mark****) thing
  [ ] deal with abstract HTML class (by removing it and placing code elsewhere)
*/

/****         ************************************************************************** MarkDom */
class MarkDOM {
  public function __construct($path, $root = 'article') {
    $this->doc = new DOMDocument('1.0', 'UTF-8');
    echo "\n\n\n\n\n\n\n\n\n\n\n\n\n\n RUNNING AGAIN \n\n\n\n";
    foreach ($this->scan($path, $this->doc) as $block) {
      $rendered = $block->render();
      print_r($rendered);
    }
  }
  
  private function scan($path, DOMDocument $context) {    
    try {
      $handle = fopen($path, 'r');
      $block = new Block($context);

      while ($line = fgets($handle)) {
        $block->capture($line);
        if (! $block->state(2)) continue;
        yield $block;
        $block = new Block($context);
      }
    } finally {
      fclose($handle);
    }

  }


  public function __toSTring() {
    return $this->doc->saveXML();
  }
}

class Tokenizer {
  const BLOCK = [
    'keys' => [ 'ol'   , 'ul' ,    'h'  ,  'pre' , 'blockquote',  'hr'  , 'comment',  'p'  ],
    'rgxp' => ['\d+\.' , '- ' , '#{1,6}', '`{3}' ,     '>'     , '-{3,}',  '\/\/'  , '\S'  ],
    'exit' => [ false  , false,  false  ,  true  ,    false    ,  false ,  false   , false ],
    'node' => [ 'li'   , 'li' ,   '*'   ,  false ,     'p'     ,  null  ,  false   ,  '*'  ],
  ];
  
  // these are really better suited to a concept of 'fences', and pre would be involved in one. lots of pondering still
  const INLINE = [
    'q'      => '"([^"]+)"',
    'a'      => '(!?)\[([^\)^\[]+)\]\(([^\)]+)(?:\"([^"]+)\")?\)',
    'input'  => '^\[([x\s])\](.*)$',
    // TODO, right now this does img too.. would rather something <whatever.jpg> do the trick, as a general embedder
    'strong' => '\*\*([^*]+)\*\*',
    'em'     => '\*([^\*]+)\*',
    'mark'   => '\|([^|]+)\|',
    'time'   => '``([^``]+)``',
    'code'   => '`([^`]+)`',
    's'      => '~~([^~~]+)~~',
    'abbr'   => '\^\^([^\^]+)\^\^',
  ];
  
  static public function blockmatch($text) {
    //7.4   sprintf("/%s/Ai",implode('|', array_map(fn($re) => "({$re})", self::BLOCK['rgxp']));
    $rgxp = sprintf("/%s/Ai", implode('|', array_map(function($regex) { 
      return "\s*({$regex})";
    }, self::BLOCK['rgxp'])));
    
    if (preg_match($rgxp, $text, $list, PREG_OFFSET_CAPTURE) < 1) return false;

    $match = array_pop($list); // last match contains match & offset: [string $symbol, int offset]
    $index = count($list)-1;
    return [
      'key'    => self::BLOCK['keys'][$index],
      'exit'   => self::BLOCK['exit'][$index],
      'symbol' => $match[0],
      'depth'  => floor($match[1] / 2),
    ];
  }
}

/****       ****************************************************************************** LEXER */
class Block {
  // $status is 0 for ready, 1 for parsing and 2 for exit
  public $doc, $context = null;

  private $status = 0, $lines = [], $parsed = [], $exit = '';
  
  
  public function __construct(DOMDocument $doc) {
    // $this->doc = $doc;
  }
  
  public function state(int $status): bool {
    return $status === $this->status;
  }
  
  // STILL HAVING ISSUES CAPTURING AROUND NEWLINES
  public function capture(string $text) {
    if ($this->status === 0) {
      if ($match = Tokenizer::blockmatch($text)) {
        $this->parsed[] = $match;
        $this->status  += 1;
        // check for if the capture exits on a condition (fenced)
        if ($match['exit']) {
          $this->exit = $match['symbol'];
          return;
        }
      } else return;
      
    } else if (!empty($this->parsed) && rtrim($text) == $this->exit) {
      return $this->status += 1;
    }
    $this->lines[] = $text;
  }
  
  public function render() {
    return $this;
  }
  
}


/****        **************************************************************************** INLINE */
class Inline {
  const tags = [
    'q'      => '"([^"]+)"',
    'a'      => '(!?)\[([^\)^\[]+)\]\(([^\)]+)(?:\"([^"]+)\")?\)',
    'input'  => '^\[([x\s])\](.*)$',
    // TODO, right now this does img too.. would rather something <whatever.jpg> do the trick, as a general embedder
    'strong' => '\*\*([^*]+)\*\*',
    'em'     => '\*([^\*]+)\*',
    'mark'   => '\|([^|]+)\|',
    'time'   => '``([^``]+)``',
    'code'   => '`([^`]+)`',
    's'      => '~~([^~~]+)~~',
    'abbr'   => '\^\^([^\^]+)\^\^',
  ];
    
  private $text, $node;
  
  public function __construct($text, DOMDocument $doc) {
    $this->node = $doc->createDocumentFragment();
    $this->frag = $doc->createDocumentFragment();
    $this->frag->textContent = $text;
    $this->doc  = $doc;
    $this->text = $text;
  }
  
  public function getFlags() {
    count_chars(implode('', INLINE::tags), 3);
  }
    
  public function inject($elem) {
    if (empty($this->text)) return;
    $this->node->appendXML($this->parse($this->text));
    return $elem->appendChild($this->node);
  }
  
  public function parse2($elem) {
    // this is part of a rewrite of the inline parser so it can easily be used on its own, outside of the Markdom
    // instance (like in model output). I want to get something in place that replaceChilds text nodes with proper
    // element nodes, so the constant saving and outputing and injecting/appending of xml isn't done unnecessarily
    foreach (INLINE::tags as $name => $re) {
      
      preg_match_all("/{$re}/u", $this->frag->textContent, $hits, PREG_OFFSET_CAPTURE);
      foreach (array_reverse($hits[0]) as [$k, $i]) {
        // $N = $var->firstChild->splitText(mb_strlen(substr($var, 0, $i), 'UTF-8'))
        //                      ->splitText(strlen($k))->previousSibling;
        //     if (substr( $N( substr($N,1) ),0,1 ) != '$') $out[] = [$N, explode(':', str_replace('|', '/', $N))];
      }
      print_r($hits);
    }
  }
  
  public function parse($text) {
    foreach (INLINE::tags as $name => $re) {
      if (preg_match_all("/{$re}/u", $text, $hits, PREG_SET_ORDER) > 0)
        foreach ($hits as $hit) $text = str_replace($hit[0], self::{$name}($this->doc, ...$hit), $text);
    }
    return $text;
  }
  
  static public function a($doc, $line, $flag, $value, $url, $title = '') {
    [$name, $attr] = $flag ? ['img', 'src'] : ['a', 'href'];
    $elem = $doc->createElement($name, $value);
    $elem->setAttribute($attr, $url);
    if ($title) $elem->setAttribute('title', $title);
    return $doc->saveXML($elem);
  }

  
  static public function time($doc, $line, $value) {
    $time = strtotime($value);
    $elem = $doc->createElement('time', date('l F jS', $time));
    $elem->setAttribute('datetime', date(DATE_W3C, $time));
    return $doc->saveXML($elem);
  }
  
  static public function input($doc, $line, $value, $label) {
    $elem = $doc->createElement('label', $label);
    $input = $elem->insertBefore($doc->createElement('input'), $elem->firstChild);
    $input->setAttribute('type', 'checkbox');
    if ($value != ' ') {
      $input->setAttribute('checked', 'checked');
    }
    
    return $doc->saveXML($elem);
  }
  
  static public function __callStatic($name, $args) {
    [$doc, $line, $value] = $args;
    $elem = $doc->createElement($name);
    $frag = $doc->createDocumentFragment();
    $frag->appendXML($value);
    $elem->appendChild($frag);
    return $doc->saveXML($elem);
  }
}
