<?php
/*
  TODO
  [ ] TYPOGRAPHY: replace ' with actual apostrophe, -- with n-dash --- with mdash
  [ ] deal with & breaking everything
  [ ] post-render h2's into sections
  [ ] think of syntax to post-render certain lists into definition lists
  [ ] consider |mark| into post-render <strong><strong>mark</strong></strong> (****mark****) thing
  [ ] deal with abstract HTML class (by removing it and placing code elsewhere)
  [ ] anything in code element should be treated as character data!
  [ ] rentering inline elements should be itempotent (always wanted to use that word) so that order of replacing elements doesnt matter, produces same output always
*/

/****     ********************************************************************************** XMD */
class XMD {
  public function __construct($path, $root = 'article') {
    $this->doc = new DOMDocument('1.0', 'UTF-8');
    $this->doc->formatOutput = true;
    $this->doc->loadXML("<{$root}/>");
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

// Consider a token class for each element

class Token {
  public function __construct($name, $rgxp, $fenced, $contents) {
    # code...
  }
  
  // this token object could delegate responsibility for creation of it's element, making
  // the Block::evaluate method much less messy.
}

class Tokenizer {
  const BLOCK = [
  //'rgxp' => '/\s*(?:(\d+\.)|(- )|(#{1,6})|(`{3})|(>)|(-{3,})|(\/\/)|(\S))/Ai',
    'name' => [   'pin'  , 'ol'    , 'ul' ,  'h%d'  ,   'DATA'   , 'blockquote',  'hr'  , 'comment',   'p'  ],
    'rgxp' => ['\?[a-z]+','\d+\. ?', '- ' ,'#{1,6}' ,'::(?=[sp])',   '> ?'     , '-{3,}',  '\/\/'  ,'(?=\S)'],
    'join' => [   false  , false   , false,  false  ,    true    ,    false    ,  false ,  false   ,  false ],
    'type' => [     7    , 'li'    , 'li' ,    1    ,      4     ,     'p'     ,    0   ,    8     ,    1   ],
  ];
  // XML_%s_NODE types as follows: ELEMENT: 1, TEXT: 3, CDATA_SECTION: 4, PI: 7, COMMENT: 8; EMPTY: 0 (non-standard)

  // consider changing ``` to ::, esp since then classes can be added nicer: ::script|pre|style
  
  // these are really better suited to a concept of 'fences', and pre would be involved in one. lots of pondering still
  const BOUND = [
    'q'      => '"([^"]+)"',        // " 
    'strong' => '\*\*([^*]+)\*\*',  // **
    'em'     => '\*([^\*]+)\*',     // *
    'span'   => '\_([^\_]+)\_',     // _
    'mark'   => '\|([^|]+)\|',      // |
    'time'   => '``([^``]+)``',     // ``
    'code'   => '`([^`]+)`',        // `
    's'      => '~~([^~~]+)~~',     // ~~
    'abbr'   => '\^\^([^\^]+)\^\^', // ^^
  ];
  
  // links, inputs, img (perhaps more; sup, sub, audio video figure, table, footnotes, etc) \^(st|nd|rd|th|[\d]+)
  const HYBRID = [
    // TODO, right now this does img too.. would rather something <whatever.jpg> do the trick, as a general embedder
    'a'      => '(!?)\[([^\)^\[]+)\]\(([^\)]+)(?:\"([^"]+)\")?\)',
    'input'  => '^\[([x\s])\](.*)$',
  ];
  
  static public function blockmatch($text) {
    //7.4   sprintf("/%s/Ai", implode('|', array_map(fn($re) => "({$re})", self::BLOCK['rgxp']));
    //8.0 weakMap this
    $rgxp = sprintf("/\s*(?:%s)/i", implode('|', array_map(function($re) { 
      return "({$re})"; // add capture for css classes or attributes can be (?:\.([a-z]+)\s), so ##.help This will have a class applied or
    }, self::BLOCK['rgxp'])));

    if (preg_match($rgxp, $text, $list, 0b100000000) < 1) return false;
    $match  = array_pop($list); // last match contains match & offset: [string $symbol, int offset]
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


/****       ****************************************************************************** BLOCK */
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

        $this->status   = self::SCANNING;

        if ($token['join'])
          $this->halt_flag = $token['mark'];
          $token['name'] = trim(substr($text, strlen($token['mark'])));
        } else $capture = true;
        
        $this->tokens[] = $token;
        
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
      // $context = $this->evaluate($context, $current, $next);
      $context = $this->evaluate($context, $lexeme, ...array_slice($this->tokens, -2));
    }
    return $this;
  }
  
  private function evaluate($context, $lexeme, array $token, $previous = null) {

    if ($context instanceof DOMCdataSection || $context instanceof DOMComment) {
      $context->appendData($lexeme);
    } elseif ($token['type'] === XML_PI_NODE) {
      $owner = $context->ownerDocument;
      $owner->insertBefore(new DOMProcessingInstruction(substr($token['mark'],1), trim(substr($lexeme, $token['trim']))), $owner->firstChild);
      return $context;
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
          $element->nodeValue = trim(substr($lexeme, $token['trim']));
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


/****        **************************************************************************** INLINE */
class Inline {

  private $text, $node;
  
  public function __construct($text, DOMDocument $doc) {
    $this->node = $doc->createDocumentFragment();
    $this->doc  = $doc;
    $this->text = $text;
  }
  
    
  public function inject($elem) {
    if (empty($this->text)) return;
    $this->node->appendXML($this->parse($this->text));
    return $elem->appendChild($this->node);
  }
  
  public function parse2($elem) {
    // this is part of a rewrite of the inline parser so it can easily be used on its own, outside of the xMD
    // instance (like in model output). I want to get something in place that replaceChilds text nodes with proper
    // element nodes, so the constant saving and outputing and injecting/appending of xml isn't done unnecessarily
    foreach (INLINE::tags as $name => $re) {
      
      preg_match_all("/{$re}/u", $this->frag->textContent, $hits, 0b100000000);
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
      if (preg_match_all("/{$re}/u", $text, $hits, 0b10) > 0)
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