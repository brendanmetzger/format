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

  public function markdown($text) {
    
    $prior = null;
    foreach ($this->parse($text) as $block) {
      $prior = $block->render($prior);
    }

    return Document::instance()->saveXML();
  }
  
  private function parse(string $text) {
    $filtered = preg_replace(['/¶|\r\n|\r/u', '/\t/'], ["\n",'    '], $text);
    return array_map('Lexer::Block', array_filter(explode("\n", $filtered), 'Format::notEmpty'));
  }
  
  public function notEmpty($string) {
    return ! empty(trim($string));
  }
}

/****       ****************************************************************************** LEXER */
class Lexer {
  public $text, $depth, $symbol;
  static public $blocks = [
    'ListItem'   => '[0-9]+\.|- ',
    'Heading'    => '#{1,6}',
    'Code'       => '`{4}',
    'BlockQuote' => '>',
    'Block'      => '[a-z]',
    'Rule'       => '-{3,}'
  ];

  static public function Block(string $line) {
    $exp   = implode('|', array_map(function($regex) { 
      return "({$regex})";
    }, array_values(self::$blocks)));

    $key = preg_match("/\s*{$exp}/Ai", $line, $list, PREG_OFFSET_CAPTURE) ? count($list) - 2 : 0;
    
    $match = array_pop($list) ?? [null, 0];
    $type = array_keys(self::$blocks)[$key];
    return new $type(new self($type, $line, ...$match));
  }

  public function __construct(string $type, string $line, string $symbol, int $indent) {
    $this->symbol  = $symbol;
    $this->text    = substr($line, $indent);
    $this->depth   = floor($indent/2);
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

/****       ****************************************************************************** BLOCK */
class Block {
  public $value, $lexer, $context, $reset = false;

  protected $name = 'p';
    
  public function __construct($lexer) {
    $this->lexer = $lexer;
    $this->value  = new Inline($this);
  }
  
  public function render(Block $previous = null): Block {
    if ($previous === null) {
      $this->context =  Document::instance()->documentElement;
    } elseif ($this->getName() != $previous->getName() && $previous->reset) {
      $this->context = $previous->reset;
    } else {
      $this->context = $previous->context;
    }
    $this->value->inject($this->context->appendChild(new \DOMElement($this->getName())));
    return $this;
  }
  
  public function getName() {
    return $this->name;
  }
  
  public function getValue() {
    return $this->lexer->text;
  }
}

/****          ************************************************************************ LISTITEM */
class ListItem extends Block {
  protected $name = 'li', $type = null;
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
  
  public function render(Block $previous = null): Block {
    $this->reset = $previous->reset;
    $depth = $this->lexer->depth;
    
    if ($previous->getName() != 'li') {
     
      $this->context = $this->makeParent($previous->context);
      $this->reset = $previous->context;
    
    } elseif ($previous->lexer->depth < $depth) { 
    
      $this->context = $this->makeParent($previous->context->appendChild(new \DOMElement('li')));
    
    } elseif ($previous->lexer->depth > $depth) {
    
      $this->context = $previous->context;
      while($depth++ < $previous->lexer->depth) {
        $this->context = $this->context->parentNode->parentNode;
      }
      
      if ($this->getType() != $this->context->nodeName) {
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

/****         ************************************************************************** HEADING */
class Heading extends Block {
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

class BlockQuote extends Block {}
class Code extends Block {}
class Rule extends Block {}





$test = <<<EOD
  
# Test h1
#### Test h4
####### Test h7 (shouldnt work)


1. ordered one
2. ordered two
  - nested unordered [one](/url)
  - nested unordered two
    - unordered double nested one
    - unordered double nested two
      1. orderded triple nested one
      2. ordered triple nested two 
    - unordered double nested three
3. ordered three

- unordered one
- unordered two
  
this is some paragraph <strong>text</strong> with **strong**

EOD;

echo (new Format)->markdown($test);

?>