<?php




/****          ************************************************************************ MARKDOWN */
class MarkDown {
  private $definitions = [];
  private $doc;
  
  public function __construct($root = 'article') {
    $this->doc = new \DOMDocument;
    $this->doc->formatOutput = true;
    $this->doc->loadXML("<{$root}/>");
  }
  public function text($text) {
    $lines = explode("\n", trim(str_replace("\t", '    ', preg_replace('/¶|\r\n|\r/u', "\n", $text))));
    $output = trim($this->lines($lines, $this->doc->documentElement));
    return $output; //$this->doc->saveXML();
  }

  private $breaksEnabled = false;
  protected $block_methods = [
    '#' => ['Heading'],
    '*' => ['Rule', 'List'],
    '+' => ['List'],
    '-' => ['Table', 'Rule', 'List'],
    '0' => ['List'],
    '1' => ['List'],
    '2' => ['List'],
    '3' => ['List'],
    '4' => ['List'],
    '5' => ['List'],
    '6' => ['List'],
    '7' => ['List'],
    '8' => ['List'],
    '9' => ['List'],
    ':' => ['Table'],
    '<' => ['Comment', 'Markup'],
    '>' => ['Quote'],
    '_' => ['Rule'],
    '`' => ['FencedCode'],
    '|' => ['Table'],
    '~' => ['FencedCode'],
  ];


  protected function lines(array $lines, $context) {
    $current_block = null;
    
    foreach ($lines as $num => $line) { // **************************************** BEGIN FOREACH
      if (rtrim($line) === '') {
        if (isset($current_block)) $current_block['interrupted'] = true;
        continue;
      }
      
      $indent = 0;
      while (substr($line, $indent, 1) === ' ') $indent++;
      
      $config = ['body' => $line, 'indent' => $indent, 'text' => substr($line, $indent)];

      if (isset($current_block['incomplete'])) {
        if ($block_config = $this->{'addTo'.$current_block['type']}($config, $current_block)) {
          $current_block = $block_config;
          continue;
        } else {
          $complete_method = 'complete'.$current_block['type'];
          if (method_exists($this, $complete_method)) {
            $current_block = $this->{$complete_method}($current_block);
          }
          unset($current_block['incomplete']);
        }
      }

      $marker = $config['text'][0];
      $definitions = ['[' => ['Reference']];

      if (isset($definitions[$marker])) {
        foreach ($definitions[$marker] as $dm) {  
          if ($def = $this->{'identify'.$dm}($config, $current_block)) {
            $this->definitions[$dm][$def['id']] = $def['data'];
            continue 2;
          }
        }
      }

      
      foreach (array_merge(['CodeBlock'], $this->block_methods[$marker] ?? []) as $id_method) {
        if ($block = $this->{"identify{$id_method}"}($config, $current_block)) {
          $block['type'] = $id_method;

          
          if ( ! isset($block['identified'])) {
            $Elements[] = $current_block['element'];
            $block['identified'] = true;
          }
          
          if (method_exists($this, "addTo{$id_method}")) {
            $block['incomplete'] = true;
          }
          
          if (is_null($current_block)) {
            echo "{$block['type']} is null";
          }
          
          $current_block = $block;
          continue 2;
        }
      }
      
      
      if (isset($current_block) && ! isset($current_block['type']) && ! isset($current_block['interrupted'])) {
        $current_block['element']['text'] .= "\n".$config['text'];
        
      } else {
        $Elements[] = $current_block['element'];
        $current_block = $this->buildParagraph($config);
        $current_block['identified'] = true;
      }
    } // **************************************************************************** END FOREACH     
    
    if (isset($current_block['incomplete']) and method_exists($this, 'complete'.$current_block['type'])) {
      $current_block = $this->{'complete'.$current_block['type']}($current_block);
    }

    $Elements[] = $current_block['element'];
    unset($Elements[0]);

    return $this->elements($Elements, $context);
  }
    
    protected function buildParagraph($Line) {
      return [
        'element' => [
          'name' => 'p',
          'text' => $Line['text'],
          'handler' => 'line',
        ]
      ];
    }

    protected function identifyHeading($line) {
      if (isset($line['text'][1])) {
        $size = 0;
        while(substr($line['text'], $size, 1) == '#') $size++;            
        return [
          'element' => [
            'name'    => 'h' . min(6, $size),
            'text'    => trim($line['text'], '# '),
            'handler' => 'line',
          ]
        ];
      }
    }

    protected function identifyCodeBlock($Line) {
        if ($Line['indent'] >= 4) {
            
            $text = substr($Line['body'], 4);

            return array(
                'element' => array(
                    'name' => 'pre',
                    'handler' => 'element',
                    'text' => array(
                        'name' => 'code',
                        'text' => $text,
                    ),
                ),
            );
        }
        return false;
    }

    protected function addToCodeBlock($Line, $Block) {
        if ($Line['indent'] >= 4) {
            if (isset($Block['interrupted'])) {
                $Block['element']['text']['text'] .= "\n";
                unset($Block['interrupted']);
            }

            $Block['element']['text']['text'] .= "\n";

            $text = substr($Line['body'], 4);

            $Block['element']['text']['text'] .= $text;

            return $Block;
        }
        return false;
    }

    protected function completeCodeBlock($Block) {
        $text = $Block['element']['text']['text'];

        $text = htmlspecialchars($text, ENT_NOQUOTES, 'UTF-8');

        $Block['element']['text']['text'] = $text;

        return $Block;
    }

    protected function identifyComment($Line) {
      if (isset($Line['text'][3]) and $Line['text'][3] === '-' and $Line['text'][2] === '-' and $Line['text'][1] === '!') {
        $Block = array(
          'element' => $Line['body'],
        );

            if (preg_match('/-->$/', $Line['text'])) {
                $Block['closed'] = true;
            }

            return $Block;
        }
    }

    protected function addToComment($Line, array $Block) {
        if (isset($Block['closed'])) return false;

        $Block['element'] .= "\n" . $Line['body'];

        if (preg_match('/-->$/', $Line['text'])) {
            $Block['closed'] = true;
        }

        return $Block;
    }

    protected function identifyFencedCode($Line) {
        if (preg_match('/^(['.$Line['text'][0].']{3,})[ ]*([\w-]+)?[ ]*$/', $Line['text'], $matches)) {
            $Element = [
              'name' => 'code',
              'text' => '',
            ];

            if (isset($matches[2])) {
                $Element['attributes'] = ['class' => "language-{$matches[2]}"];
            }

            $Block = array(
                'char' => $Line['text'][0],
                'element' => array(
                    'name' => 'pre',
                    'handler' => 'element',
                    'text' => $Element,
                ),
            );

            return $Block;
        }
    }

    protected function addToFencedCode($Line, $Block) {
        if (isset($Block['complete'])) return false;

        if (isset($Block['interrupted']))
        {
            $Block['element']['text']['text'] .= "\n";

            unset($Block['interrupted']);
        }

        if (preg_match('/^'.$Block['char'].'{3,}[ ]*$/', $Line['text']))
        {
            $Block['element']['text']['text'] = substr($Block['element']['text']['text'], 1);

            $Block['complete'] = true;

            return $Block;
        }

        $Block['element']['text']['text'] .= "\n".$Line['body'];;

        return $Block;
    }

    protected function completeFencedCode($Block) {
        $text = $Block['element']['text']['text'];

        $text = htmlspecialchars($text, ENT_NOQUOTES, 'UTF-8');

        $Block['element']['text']['text'] = $text;

        return $Block;
    }

  protected function identifyList($line) {
    [$name, $pattern] = $line['text'][0] <= '-' ? ['ul', '[*+-]'] : ['ol', '[0-9]+[.]'];

    if (preg_match("/^({$pattern}[ ]+)(.*)/", $line['text'], $matches)) {
      $config = [
        'indent'  => $line['indent'],
        'pattern' => $pattern,
        'element' => [
          'name'  => $name,
          'handler' => 'elements',
        ]
      ];

      $config['li'] = [
        'name' => 'li',
        'handler' => 'li',
        'text' => [$matches[2]],
      ];
              
      $config['element']['text'][] =& $config['li'];

      return $config;
    }
  }

    protected function addToList($Line, array $Block) {
      if ($Block['indent'] === $Line['indent'] and preg_match('/^'.$Block['pattern'].'[ ]+(.*)/', $Line['text'], $matches)) {
            if (isset($Block['interrupted'])) {
                $Block['li']['text'] []= '';

                unset($Block['interrupted']);
            }

            unset($Block['li']);

            $Block['li'] = array(
                'name' => 'li',
                'handler' => 'li',
                'text' => array(
                    $matches[1],
                ),
            );

            $Block['element']['text'] []= & $Block['li'];

            return $Block;
        }

        if ( ! isset($Block['interrupted']))
        {
            $text = preg_replace('/^[ ]{0,4}/', '', $Line['body']);

            $Block['li']['text'] []= $text;

            return $Block;
        }

        if ($Line['indent'] > 0) {
            $Block['li']['text'] []= '';

            $text = preg_replace('/^[ ]{0,4}/', '', $Line['body']);

            $Block['li']['text'] []= $text;

            unset($Block['interrupted']);

            return $Block;
        }
        
        return false;
    }

    protected function identifyQuote($Line) {
        if (preg_match('/^>[ ]?(.*)/', $Line['text'], $matches))
        {
            $Block = array(
                'element' => array(
                    'name' => 'blockquote',
                    'handler' => 'lines',
                    'text' => (array) $matches[1],
                ),
            );

            return $Block;
        }
    }

    protected function addToQuote($Line, array $Block)  {
        if ($Line['text'][0] === '>' and preg_match('/^>[ ]?(.*)/', $Line['text'], $matches))
        {
            if (isset($Block['interrupted']))
            {
                $Block['element']['text'] []= '';

                unset($Block['interrupted']);
            }

            $Block['element']['text'] []= $matches[1];

            return $Block;
        }

        if ( ! isset($Block['interrupted']))
        {
            $Block['element']['text'] []= $Line['text'];

            return $Block;
        }
        return false;
    }

    protected function identifyRule($Line) {
      if (preg_match('/^(['.$Line['text'][0].'])([ ]{0,2}\1){2,}[ ]*$/', $Line['text']))
        return [ 'element' => [ 'name' => 'hr' ] ];
    }

    protected function identifyMarkup($Line) {
      $textLevelElements = array(
              'a', 'br', 'bdo', 'abbr', 'blink', 'nextid', 'acronym', 'basefont',
              'b', 'em', 'big', 'cite', 'small', 'spacer', 'listing',
              'i', 'rp', 'del', 'code',          'strike', 'marquee',
              'q', 'rt', 'ins', 'font',          'strong',
              's', 'tt', 'sub', 'mark',
              'u', 'xm', 'sup', 'nobr',
                         'var', 'ruby',
                         'wbr', 'span',
                                'time',
          );
          
      $emptyElements = ['area', 'base', 'br', 'col', 'command', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source'];
      
        if (preg_match('/^<(\w[\w\d]*)(?:[ ][^>]*)?(\/?)[ ]*>/', $Line['text'], $matches)) {
            if (in_array($matches[1], $textLevelElements)) return;

            $Block = array(
                'element' => $Line['body'],
            );

            if ($matches[2] or in_array($matches[1], $emptyElements) or preg_match('/<\/'.$matches[1].'>[ ]*$/', $Line['text'])) {
                $Block['closed'] = true;
            }
            else {
                $Block['depth'] = 0;
                $Block['name'] = $matches[1];
            }

            return $Block;
        }
    }

    protected function addToMarkup($Line, array $Block) {
        if (isset($Block['closed'])) return false;

        if (preg_match('/<'.$Block['name'].'([ ][^\/]+)?>/', $Line['text'])) # opening tag
        {
            $Block['depth'] ++;
        }

        if (stripos($Line['text'], '</'.$Block['name'].'>') !== false) # closing tag
        {
            if ($Block['depth'] > 0)
            {
                $Block['depth'] --;
            }
            else
            {
                $Block['closed'] = true;
            }
        }

        $Block['element'] .= "\n".$Line['body'];

        return $Block;
    }

    protected function identifyTable($Line, array $Block = null) {

        if ( ! isset($Block) or isset($Block['type']) or isset($Block['interrupted'])) {
            return;
        }

        if (strpos($Block['element']['text'], '|') !== false and rtrim($Line['text'], ' -:|') === '')
        {
            $alignments = array();

            $divider = $Line['text'];

            $divider = trim($divider);
            $divider = trim($divider, '|');

            $dividerCells = explode('|', $divider);

            foreach ($dividerCells as $dividerCell)
            {
                $dividerCell = trim($dividerCell);

                if ($dividerCell === '')
                {
                    continue;
                }

                $alignment = null;

                if ($dividerCell[0] === ':')
                {
                    $alignment = 'left';
                }

                if (substr($dividerCell, -1) === ':')
                {
                    $alignment = $alignment === 'left' ? 'center' : 'right';
                }

                $alignments []= $alignment;
            }

            # ~

            $HeaderElements = array();

            $header = $Block['element']['text'];

            $header = trim($header);
            $header = trim($header, '|');

            $headerCells = explode('|', $header);

            foreach ($headerCells as $index => $headerCell)
            {
                $headerCell = trim($headerCell);

                $HeaderElement = array(
                    'name' => 'th',
                    'text' => $headerCell,
                    'handler' => 'line',
                );

                if (isset($alignments[$index]))
                {
                    $alignment = $alignments[$index];

                    $HeaderElement['attributes'] = array(
                        'align' => $alignment,
                    );
                }

                $HeaderElements []= $HeaderElement;
            }

            # ~

            $Block = array(
                'alignments' => $alignments,
                'identified' => true,
                'element' => array(
                    'name' => 'table',
                    'handler' => 'elements',
                ),
            );

            $Block['element']['text'] []= array(
                'name' => 'thead',
                'handler' => 'elements',
            );

            $Block['element']['text'] []= array(
                'name' => 'tbody',
                'handler' => 'elements',
                'text' => array(),
            );

            $Block['element']['text'][0]['text'] []= array(
                'name' => 'tr',
                'handler' => 'elements',
                'text' => $HeaderElements,
            );

            return $Block;
        }
    }

    protected function addToTable($Line, array $Block) {
        if ($Line['text'][0] === '|' or strpos($Line['text'], '|'))
        {
            $Elements = array();

            $row = $Line['text'];

            $row = trim($row);
            $row = trim($row, '|');

            $cells = explode('|', $row);

            foreach ($cells as $index => $cell)
            {
                $cell = trim($cell);

                $Element = array(
                    'name' => 'td',
                    'handler' => 'line',
                    'text' => $cell,
                );

                if (isset($Block['alignments'][$index]))
                {
                    $Element['attributes'] = array(
                        'align' => $Block['alignments'][$index],
                    );
                }

                $Elements []= $Element;
            }

            $Element = array(
                'name' => 'tr',
                'handler' => 'elements',
                'text' => $Elements,
            );

            $Block['element']['text'][1]['text'] []= $Element;

            return $Block;
        }
        return false;
    }

    protected function identifyReference($Line) {
        if (preg_match('/^\[(.+?)\]:[ ]*<?(\S+?)>?(?:[ ]+["\'(](.+)["\')])?[ ]*$/', $Line['text'], $matches)) {
            $Definition = array(
                'id' => strtolower($matches[1]),
                'data' => array(
                    'url' => $matches[2],
                ),
            );

            if (isset($matches[3]))
            {
                $Definition['data']['title'] = $matches[3];
            }

            return $Definition;
        }
    }
    
    protected function element(array $Element, \DOMNode $node = null) {
      
      if ($node) {
        $element = $Element;

        $elem = $node->appendChild(new \DOMElement($element['name']));
        
        if (is_array($element['text'])) {
          $this->{$element['handler']}($element['text'], $elem);
        } else {
          $elem->nodeValue = $element['text'];
          if (isset($element['attributes'])) {
            foreach ($element['attributes'] as $name => $value) {
              $elem->setAttribute($name, $value);
            }
            
          }
          
        }
      }
      
      
      $markup = '<'.$Element['name'];

      if (isset($Element['attributes'])) {
        foreach ($Element['attributes'] as $name => $value) {
          $markup .= ' '.$name.'="'.$value.'"';
        }
      }

      if (isset($Element['text'])) {
            $markup .= '>';

        if (isset($Element['handler'])) {
          $markup .= $this->{$Element['handler']}($Element['text']);
        } else {
          $markup .= $Element['text'];
        }

        $markup .= '</'.$Element['name'].'>';
      } else {
        $markup .= ' />';
      }
      return $markup;
    }

    protected function elements(array $Elements, $context = null) {  
      $markup = '';
      foreach ($Elements as $Element) {
        $markup .= "\n";
        if (is_string($Element)) {
          $markup .= $Element;
          continue;
        }

        $markup .= $this->element($Element, $context ?: $this->doc->documentElement);
      }

      return $markup . "\n";
    }

    public function line($text) {
        $markup = '';
        $slice  = $text;
        $offset = 0;
        $inline_elems = [
          '!' => array('Link'),
          '&' => array('Ampersand'),
          '*' => ['Emphasis'],
          '/' => array('Url'),
          '<' => array('UrlTag', 'EmailTag', 'Tag', 'LessThan'),
          '[' => array('Link'),
          '_' => array('Emphasis'),
          '`' => array('InlineCode'),
          '~' => array('Strikethrough'),
          '\\' => array('EscapeSequence'),
        ];

        $types = implode('', array_keys($inline_elems));
        
        while ($excerpt = strpbrk($slice, $types)) {
          
          $marker = $excerpt[0];
          $offset += strpos($slice, $marker);


          foreach ($inline_elems[$marker] as $nodeName) {
                
            $elem_config = $this->{"identify{$nodeName}"}(['text' => $excerpt, 'context' => $text]);

                if ( ! isset($elem_config)) continue;
                
                # The identified span can be ahead of the marker.
                if (isset($elem_config['position']) and $elem_config['position'] > $offset) continue;

                # Spans that start at the position of their marker don't have to set a position.

                if ( ! isset($elem_config['position'])) $elem_config['position'] = $offset;
                
                $plainText = substr($text, 0, $elem_config['position']);

                $markup .= $this->readPlainText($plainText);
                
                $markup .= isset($elem_config['markup']) ? $elem_config['markup'] : $this->element($elem_config['element']);

                $text    = substr($text, $elem_config['position'] + $elem_config['extent']);

                $slice = $text;
                $offset = 0;
                continue 2;
            }

            $slice = substr($excerpt, 1);
            $offset++;
        }

        $markup .= $this->readPlainText($text);

        return $markup;
    }


    protected function identifyUrl($Excerpt) {
        if ( ! isset($Excerpt['text'][1]) or $Excerpt['text'][1] !== '/')
        {
            return;
        }

        if (preg_match('/\bhttps?:[\/]{2}[^\s<]+\b\/*/ui', $Excerpt['context'], $matches, PREG_OFFSET_CAPTURE))
        {
            $url = str_replace(array('&', '<'), array('&amp;', '&lt;'), $matches[0][0]);

            return array(
                'extent' => strlen($matches[0][0]),
                'position' => $matches[0][1],
                'element' => array(
                    'name' => 'a',
                    'text' => $url,
                    'attributes' => array(
                        'href' => $url,
                    ),
                ),
            );
        }
    }

    protected function identifyAmpersand($Excerpt) {
        if ( ! preg_match('/^&#?\w+;/', $Excerpt['text']))
        {
            return array(
                'markup' => '&amp;',
                'extent' => 1,
            );
        }
    }

    protected function identifyStrikethrough($Excerpt) {
        if ( ! isset($Excerpt['text'][1]))
        {
            return;
        }

        if ($Excerpt['text'][1] === '~' and preg_match('/^~~(?=\S)(.+?)(?<=\S)~~/', $Excerpt['text'], $matches))
        {
            return array(
                'extent' => strlen($matches[0]),
                'element' => array(
                    'name' => 'del',
                    'text' => $matches[1],
                    'handler' => 'line',
                ),
            );
        }
    }

    protected function identifyEscapeSequence($Excerpt) {
      if (isset($Excerpt['text'][1]) and in_array($Excerpt['text'][1], ['\\', '`', '*', '_', '{', '}', '[', ']', '(', ')', '>', '#', '+', '-', '.', '!'])) {
        return [
          'markup' => $Excerpt['text'][1],
          'extent' => 2,
        ];
      }
    }

    protected function identifyLessThan() {
        return array(
            'markup' => '&lt;',
            'extent' => 1,
        );
    }

    protected function identifyUrlTag($Excerpt) {
        if (strpos($Excerpt['text'], '>') !== false and preg_match('/^<(https?:[\/]{2}[^\s]+?)>/i', $Excerpt['text'], $matches))
        {
            $url = str_replace(array('&', '<'), array('&amp;', '&lt;'), $matches[1]);

            return array(
                'extent' => strlen($matches[0]),
                'element' => array(
                    'name' => 'a',
                    'text' => $url,
                    'attributes' => array(
                        'href' => $url,
                    ),
                ),
            );
        }
    }

    protected function identifyEmailTag($Excerpt) {
        if (strpos($Excerpt['text'], '>') !== false and preg_match('/^<(\S+?@\S+?)>/', $Excerpt['text'], $matches))
        {
            return array(
                'extent' => strlen($matches[0]),
                'element' => array(
                    'name' => 'a',
                    'text' => $matches[1],
                    'attributes' => array(
                        'href' => 'mailto:'.$matches[1],
                    ),
                ),
            );
        }
    }

    protected function identifyTag($Excerpt) {

        if (strpos($Excerpt['text'], '>') !== false and preg_match('/^<\/?\w.*?>/', $Excerpt['text'], $matches))
        {
            return array(
                'markup' => $matches[0],
                'extent' => strlen($matches[0]),
            );
        }
    }

    protected function identifyInlineCode($Excerpt) {
        $marker = $Excerpt['text'][0];

        if (preg_match('/^('.$marker.'+)[ ]*(.+?)[ ]*(?<!'.$marker.')\1(?!'.$marker.')/', $Excerpt['text'], $matches))
        {
            $text = $matches[2];
            $text = htmlspecialchars($text, ENT_NOQUOTES, 'UTF-8');

            return array(
                'extent' => strlen($matches[0]),
                'element' => array(
                    'name' => 'code',
                    'text' => $text,
                ),
            );
        }
    }

    protected function identifyLink($Excerpt) {
        $extent = $Excerpt['text'][0] === '!' ? 1 : 0;

        if (strpos($Excerpt['text'], ']') and preg_match('/\[((?:[^][]|(?R))*)\]/', $Excerpt['text'], $matches)) {
            $Link = array('text' => $matches[1], 'label' => strtolower($matches[1]));

            $extent += strlen($matches[0]);

            $substring = substr($Excerpt['text'], $extent);

            if (preg_match('/^\s*\[([^][]+)\]/', $substring, $matches))
            {
                $Link['label'] = strtolower($matches[1]);

                if (isset($this->definitions['Reference'][$Link['label']]))
                {
                    $Link += $this->definitions['Reference'][$Link['label']];

                    $extent += strlen($matches[0]);
                } else return;
                
            }
            elseif (isset($this->definitions['Reference'][$Link['label']])) {
                $Link += $this->definitions['Reference'][$Link['label']];

                if (preg_match('/^[ ]*\[\]/', $substring, $matches)) {
                    $extent += strlen($matches[0]);
                }
            } elseif (preg_match('/^\([ ]*(.*?)(?:[ ]+[\'"](.+?)[\'"])?[ ]*\)/', $substring, $matches)) {
                $Link['url'] = $matches[1];

                if (isset($matches[2])) {
                    $Link['title'] = $matches[2];
                }

                $extent += strlen($matches[0]);
            } else return;
        } else return;

        $url = str_replace(array('&', '<'), array('&amp;', '&lt;'), $Link['url']);

        if ($Excerpt['text'][0] === '!')
        {
            $Element = array(
                'name' => 'img',
                'attributes' => array(
                    'alt' => $Link['text'],
                    'src' => $url,
                ),
            );
        }
        else
        {
            $Element = array(
                'name' => 'a',
                'handler' => 'line',
                'text' => $Link['text'],
                'attributes' => array(
                    'href' => $url,
                ),
            );
        }

        if (isset($Link['title']))
        {
            $Element['attributes']['title'] = $Link['title'];
        }

        return array(
            'extent' => $extent,
            'element' => $Element,
        );
    }

    protected function identifyEmphasis($Excerpt) {
        if ( ! isset($Excerpt['text'][1])) return;

        $marker = $Excerpt['text'][0];
        
        $strong = [
          '*' => '/^[*]{2}((?:[^*]|[*][^*]*[*])+?)[*]{2}(?![*])/s',
          '_' => '/^__((?:[^_]|_[^_]*_)+?)__(?!_)/us',
        ];
        
        $em = [
          '*' => '/^[*]((?:[^*]|[*][*][^*]+?[*][*])+?)[*](?![*])/s',
          '_' => '/^_((?:[^_]|__[^_]*__)+?)_(?!_)\b/us',
        ];
        
        if ($Excerpt['text'][1] === $marker and preg_match($strong[$marker], $Excerpt['text'], $matches)) {
        
          $emphasis = 'strong';
        
        } elseif (preg_match($em[$marker], $Excerpt['text'], $matches)) {
          
          $emphasis = 'em';
        
        } else return;


        return [
          'extent' => strlen($matches[0]),
          'element' => [
            'name' => $emphasis,
            'handler' => 'line',
            'text' => $matches[1],
          ],
        ];
    }

    protected function readPlainText($text) {
      return str_replace($this->breaksEnabled ? "\n" : "  \n", "<br />\n", $text);
    }

    protected function li($lines, $context = null) {

        $markup = $this->lines($lines, $context);

        $trimmedMarkup = trim($markup);

        if ( ! in_array('', $lines) and substr($trimmedMarkup, 0, 3) === '<p>')
        {
            $markup = $trimmedMarkup;
            $markup = substr($markup, 3);

            $position = strpos($markup, "</p>");

            $markup = substr_replace($markup, '', $position, 4);
        }
        if ($context) {
          $context->nodeValue = $markup;
        }
        

        return $markup;
    }
    

}