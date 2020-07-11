<?php
$mark = microtime(true);
include('inline.php');

class Parse {
  private $DOM, $context = null;
  
  public function __construct(string $path, string $xml = '<article/>', string $xpath = '/*') {
    $this->DOM     = new DOMDocument;
    $this->DOM->formatOutput = true;
    $this->DOM->loadXML($xml);
    $this->context = (new DOMXpath($this->DOM))->query($xpath)[0] ?? null;

    foreach ($this->scan(new SplFileObject($path)) as $block) {
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
  public $flag, $trim, $depth, $text, $name, $rgxp, $value, $grouped = false;
  
  function __construct($data) {
    foreach ($data as $prop => $value) $this->{$prop} = $value;
    $this->value =  trim(substr($this->text, $this->name == 'p' ? 0 : $this->trim * $this->depth));
    $this->grouped = in_array($this->name, ['pre', 'ol','ul']);
  }
  
  // this will only get called by capturing blocks
  public function interpret(Block $block)
  {
    if ($block->trap || $this->name === 'pre') {
      echo "trapping {$block->trap} \n";
      if ($block->trap === false) {
        echo "setting trap!\n";
        $block->trap = $this->flag;
        return $block;
      }
      elseif ($block->trap == trim($this->text)) {
        echo "closing trap!\n";
        return new Block;
      }
        
      else {
        $this->name = 'pre';
        return $block->push($this);
      }
        
      
    } else {
      
      $last = $block->lastToken();
      if ($last && $this->name != $last->name && $this->depth == $last->depth)
        return new Block($this);
      else
        return $block->push($this);
    }
  }
  
}


class Block {
  
  const MAP = [
    'name' => [ 'ol'    , 'ul' ,  'h%d'  ,  'pre' , 'blockquote',  'hr'  ,'comment',  'p'  ],
    'rgxp' => ['\d+\. ?', '- ' ,'#{1,6}' , '`{3}' ,   '> ?'     , '-{3,}',  '\/\/' , '\S'  ],
  ];
  
  public  $trap = false;
  private $token = [];
  private static $rgxp;

  public function __construct(?Token $token = null) {
    self::$rgxp ??= sprintf("/\s*(?:%s)/Ai", implode('|', array_map(fn($x) => "($x)", self::MAP['rgxp'])));
    if ($token) $this->token[] = $token;
  }
  
  public function parse($text)
  {
    if (preg_match(self::$rgxp, $text, $list, PREG_OFFSET_CAPTURE) < 1) return false;

    [$symbol, $offset] = array_pop($list); // last match contains match & offset: [string $symbol, int offset]
    $trim = strlen($symbol);

    return new Token ([
      'name'  => sprintf(self::MAP['name'][count($list) - 1], $trim),
      'flag'  => $symbol,
      'trim'  => $trim,
      'depth' => floor($offset / 2) + 1,
      'text'  => $text,
    ]);
  }
  
  public function push(Token $token): Block {
    $this->token[] = $token;
    return $this;
  }
  
  public function lastToken() {
    return $this->token[count($this->token) - 1] ?? null;
  }
  
  public function capture(string $line)
  {
    if (! $token = $this->parse($line))
      return $this;
    
    if ($token->grouped || $this->trap)
      return $token->interpret($this);

    return empty($this->token) ? $this->push($token) : new Block($token);
  }
  
  public function process(DOMElement $context)
  {
    foreach($this->token as $token) {
      
      if ($context instanceof DOMCharacterData) {
        $context->appendData($token->text);
        continue;
      }
         
      $context = $this->append($context, $token);
    }
    return $this;
  }
  
  private function append ($context, $token)
  {
    if ($token->name == 'comment')
      return $context->appendChild(new DOMComment($token->value));
    
    $element = $context->appendChild(new DOMElement($token->name));
    
    if ($token->name === 'pre') {
      return $element->appendChild(new DOMCdataSection($token->text));
    }

    if ($token->name === 'hr') return $context;

    $element->nodeValue = $token->value;
    Inline::format($element);      

    return $context;
  }
}

$parser = new Parse('example.md');
echo $parser;

echo (microtime(true) - $mark). 'sec, mem:' . (memory_get_peak_usage() / 1000) . "kb\n";