<?php

// NEXT: parse links

class Document {
  public static function instance() {
    static $doc = null;
    
    if ($doc === null) {
      $doc = new \DOMDocument;
      $doc->formatOutput = true;
      $doc->loadXML('<'.Format::$root.'/>');
    }
    return $doc;
  }
}

/****        ************************************************************************** Format */
class Format {
  public static $root = 'article';
  
  private $definitions = [];
  public $doc;
  
  public function __construct($root = null) {
    $this->doc = Document::instance();
  }
  public function markdown($text) {
    
    $context = $this->doc->documentElement;
    $depth   = 0;
    foreach ($this->parse(preg_replace(['/¶|\r\n|\r/u', '/\t/'], ["\n",'    '], $text)) as $block) {
      // if depth changes, go up. 
      while($depth > $block->lexer->depth) {
        $context = $context->parentNode;
        $depth--;
      }

      if ($block->context && $block->context != $context->nodeName) {
        /*
          TODO uls go in lis when nested... how to implement that rule?
        */
        if ($block->lexer->depth > $depth) {
          $context = $context->appendChild(new \DOMElement($block->getName()));
        }
        $depth = $block->lexer->depth;
        $context = $context->appendChild(new \DOMElement($block->context ?: Format::$root));
      }
      $val = $context->appendChild(new \DOMElement($block->getName()));
      $block->value->inject($val);
    }
    return $this->doc->saveXML();
  }
  
  private function parse(string $text) {
    return array_map('Lexer::Block', array_filter(explode("\n", $text), 'Format::notEmpty'));
  }
  
  public function notEmpty($string) {
    return ! empty(trim($string));
  }
}

// Represents a DOMElement that occupies at least one line
class Block {
  public $value, $lexer, $context = null;

  protected $name = 'p';
    
  public function __construct($lexer) {
    $this->lexer = $lexer;
    $this->value  = new Inline($this);
  }
  
  public function getName() {
    return $this->name;
  }
  
  public function getValue() {
    return $this->lexer->text;
  }
}

/****        **************************************************************************** INLINE */
class Inline {
  private $block;
  public function __construct(Block $block) {
    $this->block = $block;
  }
  
  public function inject($elem) {
    $fragment = $elem->ownerDocument->createDocumentFragment();
    $fragment->appendXML($this->block->getValue());
    return $elem->appendChild($fragment);
  }
}

/****       ****************************************************************************** LEXER */
class Lexer {
  public $text, $depth, $symbol;
  static public $blocks = [
    'ListItem'   => '[0-9]+\.|-',
    'Heading'    => '#{1,6}',
    'Code'       => '`{4}',
    'BlockQuote' => '>',
    'Block'      => '[a-z]',
  ];

  static public function Block(string $line) {
    $exp   = implode('|', array_map(function($regex) { 
      return "({$regex})";
    }, array_values(self::$blocks)));
    
    $key = preg_match("/\s*{$exp}/Ai", $line, $matches, PREG_OFFSET_CAPTURE) ? count($matches) - 2 : 0;
    $match = array_pop($matches) ?? [null, 0];
    $type = array_keys(self::$blocks)[$key];
    return new $type(new self($type, $line, ...$match));
  }

  public function __construct(string $type, string $line, string $symbol, int $indent) {
    $this->symbol  = $symbol;
    $this->text    = substr($line, $indent);
    $this->depth   = floor($indent/2);
  } 
}


class ListItem extends Block {
  public $name = 'li', $context = true;
  public function getValue() {
    return trim(substr($this->lexer->text, strlen($this->lexer->symbol)));
  }
  public function getContext() {
    return $this->lexer->symbol == '-' ? 'ul' :'ol';
  }
}

class Heading extends Block {
  private $size = 0;
  public function getName() {
    
    if ($this->name[0] != 'h') {
      while(substr($this->lexer->text, $this->size, 1) == '#') $this->size++;            
      $this->name = "h{$this->size}";
    }
    
    return $this->name;
  }
  
  public function getValue() {
    return trim(substr($this->lexer->text, $this->size));
  }
}

class BlockQuote extends Block {}
class Code extends Block {}





$test = <<<EOD
  
# Test h1
#### Test h4


1. ordered one
2. ordered two
  - nested unordered [one](/url)
  - nested unordered two
4. ordered four

- unordered one
- unordered two
  
this is some paragraph <strong>text</strong> with **strong**

EOD;

echo "\n" . (new Format)->markdown($test) . "\n";

?>