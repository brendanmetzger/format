<?php

include('diatom.php');
include('inline.php');

class Parse {
  private $DOM, $context = null;
  
  public function __construct($path, $xml = '<article/>', $xpath = '/*') {
    $this->DOM     = new Document($xml);
    $this->context = $this->DOM->select($xpath);

    foreach ($this->scan(new SplFileObject($path)) as $block) {
      print_r($block);
      $rendered = $block->process($this->context);
    }
  }

  
  private function scan($iterator)
  {
    $block = new Block;

    foreach ($iterator as $line) {
      $result = $block->capture($line);
      if ($block === $result) continue;
      yield $block;
      $block = $result;
    }
    yield $block;
  }
  
  public function __toSTring() {
    return $this->DOM->saveXML();
  }
}

class Token {
  public $mark, $trim, $depth, $text, $name, $rgxp, $type, $resolved = true;
  private $halt = null;
  
  function __construct($data) {
    foreach ($data as $prop => $value) $this->{$prop} = $value;
    $this->resolved = ! ($this->type === 4 || $this->name == 'ol' || $this->name == 'ul');
  }
  
  // this will only get called by capturing blocks
  public function interpret(Block $block)
  {
    if ($this->type === 4) {
      if ($this->halt === null) {
        $this->halt = $this->mark;
        return $block;
      }  elseif ($this->halt == trim($this->text)) {
        return new Block;
      }
      return $block->push($this);
      
    } else {
      $last = $block->lastToken();
      if ($last && $this->name != $last->name && $this->depth == $last->depth) {
        return new Block($this);
      }
      
      return $block->push($this);
    }
  }
  
}


class Block {
  
  const MAP = [
    'name' => [ 'ol'    , 'ul' ,  'h%d'  ,  'pre' , 'blockquote',  'hr'  , 'comment',  'p'  ],
    'rgxp' => ['\d+\. ?', '- ' ,'#{1,6}' , '`{3}' ,   '> ?'     , '-{3,}',  '\/\/'  , '\S'  ],
    'type' => [    1    ,   1  ,    1    ,    4   ,      1      ,    0   ,    8     ,   1   ],
  ];
  
  private $token = [];
  private static $rgxp;

  public function __construct(?Token $token = null) {
    self::$rgxp ??= sprintf("/\s*(?:%s)/Ai", implode('|', array_map(fn($x) => "($x)", self::MAP['rgxp'])));
    if ($token) $this->token[] = $token;
  }
  
  
  public function parse($text) {

    if (preg_match(self::$rgxp, $text, $list, PREG_OFFSET_CAPTURE) < 1) return false;

    [$symbol, $offset] = array_pop($list); // last match contains match & offset: [string $symbol, int offset]

    $column = array_combine(array_keys(self::MAP), array_column(self::MAP, count($list) - 1));
    $trim = strlen($symbol);
    $depth = floor($offset / 2) + 1;
    return new Token (array_merge($column, [
      'mark'  => $symbol,
      'trim'  => $trim,
      'depth' => $depth,
      'text'  => $text,
      'name'  => sprintf($column['name'], $trim),
      ]));
  }
  
  public function push(Token $token): Block {
    $this->token[] = $token;
    return $this;
  }
  
  public function lastToken() {
    return $this->token[count($this->token) - 1] ?? null;
  }
  
  public function capture(string $text)
  {
    if (! $token = $this->parse($text)) return $this;

    if (! $token->resolved)             return $token->interpret($this);

    return empty($this->token) ? $this->push($token) : new Block($token);
  }
  
  public function process(DOMElement $context) {

    foreach($this->token as $token) {
      
      if ($context instanceof DOMCharacterData) {
        $context->appendData($token->text);
        continue;
      }
      
      $context = $this->evaluate($context, $token);
    }
    return $this;
  }
  
  private function evaluate($context, $token)
  {
    $type = $token->type;
    $trim = $token->trim;
    $text = $token->text;
    $name = sprintf($token->name, $trim);
    
    if ($name === 'comment')
      return $context->appendChild(new DOMComment(trim($text, " \t\n\r\0\x0B/")));
    
    if ($type === XML_CDATA_SECTION_NODE)
      return $context->appendChild(new Element($name))->appendChild(new DOMCdataSection($text));
    
    $element = $context->appendChild(new Element($name));

    if ($type !== 0) {
      $element->nodeValue = trim(substr($text, $name == 'p' ? 0 : $trim * $token->depth));
      
      if ($type == 'li') {
        $element->setAttribute('merge', $token->depth);
      }
    }

    return $context;
  }
}

$parser = new Parse('example.md');
echo $parser;