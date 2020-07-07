<?php

/****         ************************************************************************** MarkDom */
class MarkDown {
  private $doc;
  public function __construct($path, $root = '<article/>') {
    $this->doc = new Document($root);
    $scanner = Tokenizer::scan(new SplFileObject($path), $this->doc);
    foreach ($scanner as $block) {
      $rendered = $block->process($this->doc->documentElement);
    }
  }

  public function __toSTring() {
    // return 'finish';
    return $this->doc->saveXML();
  }
}

class Tokenizer {
  const BLOCK = [
  //'rgxp' => '/\s*(?:(\d+\.)|(- )|(#{1,6})|(`{3})|(>)|(-{3,})|(\/\/)|(\S))/Ai',
    'name' => [ 'ol'    , 'ul' ,  'h%d'  ,  'pre' , 'blockquote',  'hr'  , 'comment',  'p'  ],
    'rgxp' => ['\d+\. ?', '- ' ,'#{1,6}' , '`{3}' ,   '> ?'     , '-{3,}',  '\/\/'  , '\S'  ],
    'join' => [ false   , false,  false  ,  true  ,    false    ,  false ,  false   , false ],
    'type' => [ 'li'    , 'li' ,    1    ,    4   ,     'p'     ,    0   ,    8     ,   1   ],
  ];
  // XML_%s_NODE types as follows: ELEMENT: 1, TEXT: 3, CDATA_SECTION: 4, COMMENT: 8; EMPTY: 0 (non-standard)


  // these are really better suited to a concept of 'fences', and pre would be involved in one. lots of pondering still
  
  static public function blockmatch($text) {
    $rgxp = sprintf("/\s*(?:%s)/Ai", implode('|', array_map(fn($re) => "({$re})", self::BLOCK['rgxp'])));

    if (preg_match($rgxp, $text, $list, 0b100000000) < 1) return false;

    [$symbol, $offset] = array_pop($list); // last match contains match & offset: [string $symbol, int offset]
    $column = array_combine(array_keys(self::BLOCK), array_column(self::BLOCK, count($list) - 1));

    return array_merge([
      'mark'  => $symbol,
      'trim'  => strlen($symbol),
      'depth' => floor($offset / 2),
    ], $column);
  }
  
  public function boundmatch() {
    $chars = count_chars(implode('', self::BOUND), 3);
  }
  
  static public function scan($iterator, DOMDocument $context) {
    $block = new Block($context);

    foreach ($iterator as $line) {
      if ($block->capture($line)) continue;
      yield $block;
      $block = new Block($context);
    }
    
    yield $block; // in case file does not end in newline or is empty;
  }
}

class Block {
  const READY = 0; const SCANNING = 1; const FINISH = 2;
  
  public $doc, $context = null;
  private $status = 0, $lexeme = [], $tokens = [], $halt_flag = '';
    
  public function __construct(DOMDocument $doc) {
    $this->doc = $doc;
  }
  
  public function finished(?bool $set = false) {
    if ($set) $this->status = self::FINISH;
    return $this->status === self::FINISH;
  }
  
  public function capture(string $text, $capture = false) {
    if ($this->status === self::READY) {
      
      if ($token = Tokenizer::blockmatch($text)) {
        
        $this->tokens[] = $token;
        $this->status   = self::SCANNING;

        if ($token['join'])
          $this->halt_flag = $token['mark'];
        else
          $capture = true;

      }

    } else if (rtrim($text) === $this->halt_flag) $this->finished(true);     
      else $capture = true;
    
    if ($capture) $this->lexeme[] = $text;
    
    return ! $this->finished();
  }
  
  public function process(DOMElement $context) {
    
    foreach($this->lexeme as $idx => $lexeme) { 

      if ($this->tokens[0]['type'] !== XML_CDATA_SECTION_NODE && !isset($this->tokens[$idx])) {
        $this->tokens[] = Tokenizer::blockmatch($lexeme);
      }

      $context = $this->evaluate($context, $lexeme, ...array_slice($this->tokens, -2));
    }
    return $this;
  }
  
  private function evaluate($context, $lexeme, array $token, $previous = null) {

    if ($context instanceof DOMCdataSection || $context instanceof DOMComment) {
      $context->appendData($lexeme);
    } else if ($token['name'] === 'comment') {
       $element = $context->appendChild($this->doc->createComment(trim($lexeme)));
    } else if ($token['join'] && $token['type'] === XML_CDATA_SECTION_NODE) {
        $element = $context->appendChild($this->doc->createElement($token['name']));
        return $element->appendChild($this->doc->createCDATASection($lexeme));

    } else {
      $element = $this->doc->createElement(sprintf($token['name'], $token['trim']));
      
      if ($token['type'] !== 0) {
        if (is_int($token['type'])) {
          // trim the gunk off the front of strings
          $element->nodeValue = trim(substr($lexeme, $token['name'] == 'p' ? 0 : $token['trim']));
        } else {
          
          if ($previous === null) {
            $child = $element->appendChild($this->doc->createElement($token['type']));
            $child->nodeValue = substr(trim($lexeme), $token['trim']);
              
          } else if ($previous['depth'] != $token['depth']) {
            // create new Block instead of all this razzledazzle
            echo "previous depth is {$previous['depth']} — gotta make a new type for {$token['name']} at {$token['depth']}\n";
            // if depth > last depth OR type != last type: new context
            // if depth < last depth: parent's parent context
            
          } else if ($previous['type'] != $token['type']) {
            // remember the type, and if it changes, must create a new child and embed in a new context.
            echo "previous type is {$previous['type']} — gotta make a new type for {$token['name']}\n";
          }
        }
      }
      $context->appendChild($element);
    }
    
    
    return $context;
  }
}