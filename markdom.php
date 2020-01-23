<?php

define('BLOCK', [
  'li'         => '[0-9]+\.|- ',
  'h'          => '#{1,6}',
  'pre'        => '`{4}',
  'blockquote' => '>',
  'hr'         => '-{3,}',
  'p'          => '[a-z]',
]);

define('INLINE', [
  'a'      => '(!?)\[([^\)^\[]+)\]\(([^\)]+)(?:\"([^"]+)\")?\)',
  'strong' => '[_*]{2}',
  'em'     => '[_*]',
  'mark'   => '=',
  'q'      => '"',
  'code'   => '`',
  'samp'   => '`{2}',
  's'      => '~{2}',
  'abbr'   => '\^{2}',
  'time'   => '\+',
  'input'  => '\[[ox]\]'
]);

/****         ************************************************************************** MarkDom */
class MarkDOM {
  private $doc = null;
  
  public function __construct($text, $root = 'article') {
    $this->doc = new \DOMDocument;
    $this->doc->formatOutput = true;
    $this->doc->loadXML("<{$root}/>");
    $prior = $this->doc->documentElement;
    foreach ($this->split($text) as $block) {
      $prior = $block->render($prior);
    }
  }
  
  private function split(string $text) {
    $filtered = preg_replace(['/¶|\r\n|\r/u', '/\t/'], ["\n",'    '], $text); 
    return array_map('Block::Lexer', array_filter(explode("\n", $filtered), 'MarkDOM::notEmpty'));
  }
  
  public function notEmpty($string) {
    return ! empty(trim($string));
  }
  
  public function __toSTring() {
    return $this->doc->saveXML();
  }
}

/****       ****************************************************************************** LEXER */
class Block {
  public $name, $text, $depth, $symbol, $value, $reset = false, $context = null;
  
  static public function Lexer(string $text) { 
    //7.4  implode('|', array_map(fn($re):string => "({$re})", BLOCK);
    $exp = implode('|', array_map(function($regex) { 
      return "({$regex})";
    }, BLOCK));
    
    $key   = preg_match("/\s*{$exp}/Ai", $text, $list, PREG_OFFSET_CAPTURE) ? count($list) - 2 : 0;
    $match = array_pop($list) ?? [null, 0];

    return new self(array_keys(BLOCK)[$key], $text, ...$match);
  }

  public function __construct(string $name, string $text, string $symbol, int $indent) {
    $offset = strlen($symbol);
    $this->name   = $name != 'h' ?  $name :  "h" . $offset;
    $this->text   = trim(substr($text, $indent + (($name == 'p') ? 0 : $offset)));
    $this->depth  = floor($indent/2);
    $this->symbol = trim($symbol);
    $this->value  = new Inline($this);
  }
  
  public function render($previous) {
    if ($this->name == 'li')
      return $this->renderLI($previous);
    else if ($previous instanceof DOMElement) 
      $this->context =  $previous;
    else if ($this->name != $previous->name && $previous->reset)
      $this->context = $previous->reset;
    else 
      $this->context = $previous->context;

    $this->value->inject($this->context->appendChild(new \DOMElement($this->name)));
    
    return $this;
  }
  
  public function getType() {
    return $this->symbol == '-' ? 'ul' :'ol';
  }
  
  public function makeParent(\DOMElement $context): \DOMElement {
    return $context->appendChild(new \DOMElement($this->getType()));
  }
  
  // TODO: there has to be recursion somewhere in here. This hurts the eyeballs right now
  // TODO: nested lists should get a flag that can be styled (otherwise they will be doubled bulleted)
  public function renderLI($previous) {
    $this->reset = $previous->reset;
    $depth       = $this->depth;
    if ($previous->name != 'li') {
      $this->context = $this->makeParent($previous->context);
      $this->reset = $previous->context;    
    } else if ($previous->depth < $depth) { 
      $this->context = $this->makeParent($previous->context->appendChild(new \DOMElement('li')));
    } elseif ($previous->depth > $depth) {
      $this->context = $previous->context;
      while($depth++ < $previous->depth) {
        $this->context = $this->context->parentNode->parentNode;
      }
      if ($this->getType() != $this->context->nodeName) {
        // will break if indentation is sloppy
        $this->context = $this->makeParent($this->context->parentNode);  
      }
    } elseif ($this->getType() != $previous->getType()) {
      $this->context = $this->makeParent($previous->context->parentNode);
    } else {
      $this->context = $previous->context;
    }
    
    $this->value->inject($this->context->appendChild(new \DOMElement('li')));
    return $this;
  }
}

/****        **************************************************************************** INLINE */
class Inline {
  private $block;
  //(?:(\_+|\^|~{2}|`+|=)([^*^~`=]+)\1)
  private static $symbols = null;
  // add sup/sub, time
  public function __construct($block) {
    $this->block = $block;
    //7.4 self::$symbols ??= preg_replace("/[\d{}+?:]/", '', count_chars(implode('', INLINE), 3));
    self::$symbols = self::$symbols ?? preg_replace("/[\d{}+?:]/", '', count_chars(implode('', INLINE), 3));
  }
  
  public function inject($elem) {
    $fragment = $elem->ownerDocument->createDocumentFragment();
    $fragment->appendXML($this->parse($this->block->text));
    return $elem->appendChild($fragment);
  }
  
  public function parse($text) {
    
    foreach ($this->tags as $name => $re) {
      // print_r($re);
      // while(preg_match("/{$re}/u", $text, $match, PREG_OFFSET_CAPTURE)) {
      //   // continue/break or reset loop after replacing text
      //
      // }
    }
    
    while(preg_match('/(?:(!?)\[([^\)^\[]+)\]\(([^\)]+)\)(?:\"([^"]+)\")?)|(?:([_*^]{1,2})(.+)(\1))/u', $text, $match, PREG_OFFSET_CAPTURE)) {
      $text = substr_replace($text, '-'.$match[2][0].'-', $match[0][1], strlen($match[0][0]));
      // print_r($match);
    }
    return $text;
  }
}