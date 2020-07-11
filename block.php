<?php
$mark = microtime(true);

class Parse {
  private $DOM, $context = null;
  
  public function __construct(string $path, string $xml = '<article/>', string $xpath = '/*') {
    $this->DOM     = new DOMDocument;
    $this->DOM->formatOutput = true;
    $this->DOM->loadXML($xml);
    $this->context = (new DOMXpath($this->DOM))->query($xpath)[0] ?? null;
    foreach ($this->scan(new SplFileObject($path)) as $block) $block->process($this->context);
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
  
  const BLOCK = [
    'name' => [ 'ol'    , 'ul' ,  'h%d'  ,  'pre' , 'blockquote',  'hr'  ,'comment',  'p'  ],
    'rgxp' => ['\d+\. ?', '- ' ,'#{1,6}' , '`{3}' ,   '> ?'     , '-{3,}',  '\/\/' , '\S'  ],
  ];
  
  const INLINE = [
    '~~' => 's',
    '**' => 'strong',
    '__' => 'em',
    '``' => 'time',
    '`'  => 'code',
    '^^' => 'abbr',
    '|'  => 'mark',
    '"'  => 'q',
  ];
  
  public $flag, $trim, $depth, $text, $name, $rgxp, $value, $grouped = false;
  
  function __construct($data) {
    foreach ($data as $prop => $value) $this->{$prop} = $value;
    $this->value =  trim(substr($this->text, $this->name == 'p' ? 0 : $this->trim * $this->depth));
    $this->grouped = in_array($this->name, ['pre', 'ol','ul']);
  }
  
  public function interpret(Block $block)
  {
    if ($block->trap || $this->name === 'pre') {

      if ($block->trap === false && $block->trap = $this->flag)
        return $block;

      elseif ($block->trap == trim($this->text))
        return new Block;

      $this->name = 'pre';
      return $block->push($this);
    }
      
    $last = $block->lastToken();
    $done = $last && $this->name != $last->name && $this->depth == $last->depth;

    return $done ? new Block($this) : $block->push($this);
  }
}


class Block {
    
  public  $trap = false;
  private $token = [];
  private static $rgxp;

  public function __construct(?Token $token = null) {
    self::$rgxp ??= sprintf("/\s*(?:%s)/Ai", implode('|', array_map(fn($x) => "($x)", Token::BLOCK['rgxp'])));
    if ($token) $this->token[] = $token;
  }
  
  public function parse($text)
  {
    if (preg_match(self::$rgxp, $text, $list, PREG_OFFSET_CAPTURE) < 1) return false;

    [$symbol, $offset] = array_pop($list); // last match contains match & offset: [string $symbol, int offset]
    $trim = strlen($symbol);

    return new Token ([
      'name'  => sprintf(Token::BLOCK['name'][count($list) - 1], $trim),
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
  
  public function process(DOMElement $context): void
  {
    foreach($this->token as $token) {
      
      if ($context instanceof DOMCharacterData) {
        $context->appendData($token->text);
        continue;
      }
      $context = $this->append($context, $token);
    }
  }
  
  private function append ($context, $token)
  {
    if ($token->name == 'comment')
      return $context->appendChild(new DOMComment($token->value));
    
    $element = $context->appendChild(new DOMElement($token->name));
    
    if ($token->name === 'pre')
      return $element->appendChild(new DOMCdataSection($token->text));
    
    if ($token->name === 'hr') return $context;

    $element->nodeValue = $token->value;
    Inline::format($element);      

    return $context;
  }
}


class Inline {
  private static $rgxp = null;
  
  private $DOM, $node;
  
  public function __construct(DOMElement $node) {
    self::$rgxp ??= [
      'pair' => sprintf('/(%s)(?:(?!\1).)+\1/u', join('|', array_map(fn($k)=> addcslashes($k, '!..~'), array_keys(Token::INLINE)))),
      'link' => '/(!?)\[([^\]]+)\]\((\S+)\)/u'
    ];
    
    $this->DOM  = $node->ownerDocument;
    $this->node = $node;
  } 
  
  public function parse(?DOMElement $node = null) {
    $node ??= $this->node;

    $text = $node->nodeValue;
    
    $matches = [
      ...$this->gather(self::$rgxp['link'], $text, [$this, 'link']),
      ...$this->gather(self::$rgxp['pair'], $text, [$this, 'basic']),
    ];
    
    if ($node->nodeName == 'li')
      array_push($matches, ...$this->gather('/^\[([x\s])\](.*)$/u', $text, [$this, 'input']));
    
    usort($matches, fn($A, $B)=> $B[0] <=> $A[0]);
    
    foreach ($matches as $i => [$in, $out, $end, $elem]) {
      // skip nested.. parsed separately
      if (($matches[$i+1][2] ?? 0) > $end) continue;
      
      $node->replaceChild($elem, $node->firstChild->splitText($in)->splitText($out)->previousSibling);
      $this->parse($elem);
    }
    return $node;
  }
  
  static public function format($node) {
    return (new self($node))->parse();
  }
  
  public function gather($rgxp, $text, callable $callback)
  {
    preg_match_all($rgxp, $text, $matches, PREG_OFFSET_CAPTURE|PREG_SET_ORDER);
    return array_map($callback, $matches);
  }
    
  private function basic($match)
  {
    $symbol = $match[1][0];
    $node   = $this->DOM->createElement(Token::INLINE[$symbol], trim($match[0][0], $symbol));
    $out = strlen($match[0][0]);
    return [$match[0][1], $out, $match[0][1] + $out, $node];
  }
  
  private function link($match)
  {
    if ($match[1][0]) {
      $node = $this->DOM->createElement('img');
      $node->setAttribute('src', $match[3][0]);
      $node->setAttribute('alt',  $match[2][0]);
    } else {
      $node = $this->DOM->createElement('a', $match[2][0]);
      $node->setAttribute('href', $match[3][0]);
    }
    $out = strlen($match[0][0]);
    return [$match[0][1], $out, $match[0][1] + $out, $node];
  }

  private function input($match)
  {
    $node = $this->DOM->createElement('label', $match[2][0]);
    $input = $node->insertBefore($this->DOM->createElement('input'), $node->firstChild);
    $input->setAttribute('type', 'checkbox');
    if ($match[1][0] != ' ') $input->setAttribute('checked', 'checked');
    $out = strlen($match[0][0]);
    return [0, $out, $out, $node];
  }
}



$parser = new Parse('example.md');
echo $parser;

echo (microtime(true) - $mark). 'sec, mem:' . (memory_get_peak_usage() / 1000) . "kb\n";