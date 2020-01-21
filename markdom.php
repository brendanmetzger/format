<?php

// NEXT: parse links

/****        ************************************************************************** MarkDom */
class MarkDOM {
  private $doc = null;
  
  public function __construct($text, $root = 'article') {
    $this->doc = new \DOMDocument;
    $this->doc->formatOutput = true;
    $this->doc->loadXML("<{$root}/>");
    $prior = $this->doc->documentElement;
    foreach ($this->parse($text) as $block) {
      $prior = $block->render($prior);
    }
  }
  
  private function parse(string $text) {
    // replace pilcrows and various line breaks with \n, replace tabs with spaces
    $filtered = preg_replace(['/¶|\r\n|\r/u', '/\t/'], ["\n",'    '], $text); 
    return array_map('Lexer::Block', array_filter(explode("\n", $filtered), 'MarkDOM::notEmpty'));
  }
  
  public function notEmpty($string) {
    return ! empty(trim($string));
  }
  
  public function __toSTring() {
    return $this->doc->saveXML();
  }
}

/****       ****************************************************************************** LEXER */
class Lexer {
  public $text, $depth, $symbol;
  // TODO consider an atomic capture that will exit
  static public $blocks = [
    'li'   => '[0-9]+\.|- ',
    'h'    => '#{1,6}',
    'Formatted'  => '`{4}',
    'BlockQuote' => '>',
    'Rule'       => '-{3,}',
    'Block'      => '[a-z]',
  ];
  
  static public function Block(string $line) {
    $exp   = implode('|', array_map(function($regex) { 
      return "({$regex})";
    }, array_values(self::$blocks)));
    $key = preg_match("/\s*{$exp}/Ai", $line, $list, PREG_OFFSET_CAPTURE) ? count($list) - 2 : 0;
    
    $match = array_pop($list) ?? [null, 0];
    $type  = array_keys(self::$blocks)[$key];
    return new $type(new self($type, $line, ...$match));
  }

  public function __construct(string $type, string $line, string $symbol, int $indent) {
    $this->symbol = $symbol;
    $this->text   = substr($line, $indent);
    $this->depth  = floor($indent/2);
  } 
}


/****        **************************************************************************** INLINE */
class Inline {
  private $block;
  //(?:(\_+|\^|~{2}|`+|=)([^*^~`=]+)\1)
  private static $symbols = null;
  public $types = [
    'Anchor' => '(!?)\[([^\)^\[]+)\]\(([^\)]+)(?:\"([^"]+)\")?\)',
    'Strong' => '[_*]{2}',
    'Italic' => '[_*]',
    'Mark'   => '=',
    'Quote'  => '"',
    'Code'   => '`',
    'Samp'   => '`{2}',
    'Strike' => '~{2}',
    'Abbrev' => '\^{2}',
  ];
  // add sup/sub
  public function __construct(Block $block) {
    $this->block = $block;
    // php 7.4 allows self::$symbols ??= preg_replace(...)
    self::$symbols = self::$symbols ?? preg_replace("/[\d{}+?:]/", '', count_chars(implode('', array_values($this->types)), 3));
  }
  
  public function inject($elem) {
    $fragment = $elem->ownerDocument->createDocumentFragment();
    $fragment->appendXML($this->parse($this->block->getValue()));
    return $elem->appendChild($fragment);
  }
  
  public function parse($text) {
    
    foreach ($this->types as $name => $re) {
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

/****       ****************************************************************************** BLOCK */
class Block {
  public $value, $lexer, $reset = false, $context = null;
  protected $name = 'p', $type = null;
    
  public function __construct($lexer) {
    $this->lexer = $lexer;
    $this->value  = new Inline($this);
  }
  
  public function render($previous = null): Block {

    if ($previous->context === null) 
      $this->context =  $previous;
    else if ($this->getName() != $previous->getName() && $previous->reset)
      $this->context = $previous->reset;
    else 
      $this->context = $previous->context;

    $this->value->inject($this->context->appendChild(new \DOMElement($this->getName())));
    return $this;
  }
  
  public function getName() {
    return $this->name;
  }
  
  public function getValue() {
    return $this->lexer->text;
  }
  
  public function getType() {
    return $this->type;
  }
}

/****          ******************************************************************************* li */
class li extends Block {
  /*
    TODO see note at the render method. consider a Tree object, or some way to move up/down syntactically
  */
  const UP   = 1;
  const DOWN = -1;
  protected $name = 'li';
  public function getValue() {
    return trim(substr($this->lexer->text, strlen($this->lexer->symbol)));
  }
  
  public function getType() {
    if (! $this->type) $this->type = $this->lexer->symbol == '- ' ? 'ul' :'ol';
    return $this->type;
  }
  
  public function makeParent(\DOMElement $context): \DOMElement {
    return $context->appendChild(new \DOMElement($this->getType()));
  }
  
  public function climb(int $distance) {
    echo "---- climb {$distance} ----- \n";
  }
  
  // TODO: there has to be recursion somewhere in here. This hurts the eyeballs right now
  public function render(Block $previous = null): Block {
    $this->reset = $previous->reset;
    $depth       = $this->lexer->depth;

    // $this->climb($this->lexer->depth - $previous->lexer->depth);
    
    if ($previous->getName() != 'li') {
      $this->context = $this->makeParent($previous->context);
      $this->reset = $previous->context;    
    } else if ($previous->lexer->depth < $depth) { 
      $this->context = $this->makeParent($previous->context->appendChild(new \DOMElement('li')));
    } elseif ($previous->lexer->depth > $depth) {
      $this->context = $previous->context;
      while($depth++ < $previous->lexer->depth) {
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

/****         ******************************************************************************** H */
class h extends Block {
  public function getName() {
    if ($this->name[0] != 'h') {
      $this->name = "h" . strlen($this->lexer->symbol);
    }
    return $this->name;
  }
  
  public function getValue() {
    return trim(substr($this->lexer->text, strlen($this->lexer->symbol)));
  }
}

class BlockQuote extends Block {
  protected $name = 'blockquote';
  
}
class Formatted extends Block {
  protected $name = 'pre';
}
class Rule extends Block {
  protected $name = 'hr';
}


  /* notes:
  
  rather than bloat the code with complicated syntax to make things like figure and figcaption, consider contextual callbacks, ie., something like an <hr> followed by a <img> followed by a <p>text</p> would create a <figure><img/><figcaption>text</figcaption></figure>. This would be done using xpath + callbacks, ie addCallback('//hr/following-sibling::img/following-sibling::p, function($doc))
  
  */