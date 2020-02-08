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
    $this->doc->loadXML("<{$root}/>");
    foreach (Tokenizer::scan($path, $this->doc) as $block) {
      $rendered = $block->render();
      // print_r($rendered);
    }
  }

  public function __toSTring() {
    return $this->doc->saveXML();
  }
}

class Tokenizer {
  const BLOCK = [
  //'rgxp' => '/\s*(?:(\d+\.)|(- )|(#{1,6})|(`{3})|(>)|(-{3,})|(\/\/)|(\S))/Ai',
    'name' => [ 'ol'   , 'ul' ,    'h'  ,  'pre' , 'blockquote',  'hr'  , 'comment',  'p'   ],
    'rgxp' => ['\d+\.' , '- ' , '#{1,6}', '`{3}' ,     '>'     , '-{3,}',  '\/\/'  , '\S'   ],
    'join' => [ false  , false,  false  ,  true  ,    false    ,  false ,  false   , false  ],
    'tots' => [ 'li'   , 'li' , 'PCDATA', 'CDATA',     'p'     , 'EMPTY', 'CDATA'  ,'PCDATA'],
  ];
  
  // these are really better suited to a concept of 'fences', and pre would be involved in one. lots of pondering still
  const BOUND = [
    'q'      => '"([^"]+)"',        // " 
    'strong' => '\*\*([^*]+)\*\*',  // **
    'em'     => '\*([^\*]+)\*',     // *
    'mark'   => '\|([^|]+)\|',      // |
    'time'   => '``([^``]+)``',     // ``
    'code'   => '`([^`]+)`',        // `
    's'      => '~~([^~~]+)~~',     // ~~
    'abbr'   => '\^\^([^\^]+)\^\^', // ^^
  ];
  
  // links, inputs, img (perhaps more; sup, sub, audio video figure, table, footnotes, etc) st|nd|rd|th|[0-9.]
  const HYBRID = [
    // TODO, right now this does img too.. would rather something <whatever.jpg> do the trick, as a general embedder
    'a'      => '(!?)\[([^\)^\[]+)\]\(([^\)]+)(?:\"([^"]+)\")?\)',
    'input'  => '^\[([x\s])\](.*)$',
  ];
  
  static public function blockmatch($text) {
    //7.4   sprintf("/%s/Ai", implode('|', array_map(fn($re) => "({$re})", self::BLOCK['rgxp']));
    $rgxp = sprintf("/\s*(?:%s)/Ai", implode('|', array_map(function($re) { 
      return "({$re})";
    }, self::BLOCK['rgxp'])));

    if (preg_match($rgxp, $text, $list, 0b100000000) < 1) return false;

    $match  = array_pop($list); // last match contains match & offset: [string $symbol, int offset]
    $column = array_combine(array_keys(self::BLOCK), array_column(self::BLOCK, count($list) - 1));

    return array_merge([
      'mark'  => $match[0],
      'depth' => floor($match[1] / 2),
    ], $column);
  }
  
  public function boundmatch() {
    $chars = count_chars(implode('', self::BOUND), 3);
  }
  
  static public function scan($path, DOMDocument $context) {
    $block = new Block($context);

    foreach (new SplFileObject($path) as $line) {

      $block->capture($line);
      if ($block->state(Block::FINISHED) === false) continue;
      yield $block;
      $block = new Block($context);
    }
  }
}

/****       ****************************************************************************** BLOCK */
class Block {
  const READY = 0; const SCANNING = 1; const FINISHED = 2;
  
  public $doc, $context = null;
  private $status = 0, $lexeme = [], $munch = null, $exit = '';
    
  public function __construct(DOMDocument $doc) {
    $this->doc = $doc;
  }
  
  public function state(int $status): bool {
    return $status === $this->status;
  }

  public function capture(string $text) {
    if ($this->status === self::READY) {
      if ($this->munch = Tokenizer::blockmatch($text)) {

        $this->status = self::SCANNING;

        if ($this->munch['join']) {
          $this->exit = $this->munch['mark'];
          return;
        }

      } else return;

    } else if ($this->munch && rtrim($text) === $this->exit) {
      return $this->status = self::FINISHED;
    }
    $this->lexeme[] = $text;
  }
  
  public function render() {
    // START: create a generator function and load with current config ($munch);
    $context = $this->doc->documentElement;

    foreach($this->process($this->munch) as $element) {
      $context->appendChild($element);
    }
    return $this;
  }
  
  private function evaluate($lexeme, $munch) {

    if (empty($munch)) {
      return;
      // return $munch->appendData($lexeme);
      
    }
    
    if ($munch['join'] && $munch['tots'] === 'CDATA') {
      return $this->doc->createCDATASection($lexeme);
    }
    
    if ($munch['name'] === 'comment') {
      return $this->doc->createComment($lexeme);
    }
    
    // now we do shitloads of work with type/depth 
    return $this->doc->createElement($munch['name']);
    
    // $gen->send();
  }
  
  private function process($munch) {
    foreach($this->lexeme as $lexeme) { 
      $node = $this->evaluate($lexeme, $munch || Tokenizer::blockmatch($lexeme));
      yield $node;
      $munch = $node instanceof DOMCharacterData ? $node : false;
    }
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
    // this is part of a rewrite of the inline parser so it can easily be used on its own, outside of the Markdom
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