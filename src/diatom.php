<?php

libxml_use_internal_errors(true);
spl_autoload_register(fn ($name) => include 'src/' . strtolower($name) . '.php');

/*
  TODO [ ] Make sure processing instructions become template variables
       [ ] Attempt to set Routes as objects instead of storing the value to be called (line 29)
       [ ] Figure out nested scopes in Template::getSlugs
*/

###################################################################################################
# Route | Provides two useful methods to enable routing methods specified by a user's request
## 1. set up routse with `Route::{name}(callback|callable)`
## 2. compose a router, `Route::compose(new Response|new Command)`

class Route
{
  static private $actions = [];
  
  // private function __construct(callable $action, $view = null) {
  //   # code...
  // }
  
  static public function compose(Router $router) {
    return $router->delegate((new self)->configure($router));
  }
  
  private function configure(Router $route) {
    $action = self::$actions[$route->action] ?? $route(self::$actions);
    return call_user_func_array($action->bindTo($route), $route->params);
  }
  
  static public function __callStatic($action, $arguments) {
    if (is_callable($arguments[0]))
      self::$actions[$action] = $arguments[0];
  }
}

###########################################################################################################
# File | Construct an object that represents an object that can, conceiveably, be saved somewhere
## Use this class if you want to open/read/create/save a file somewhere
## Use this class if you want to look up a mimetype
## This class is often Type inforced by other classes ie., funcion someMethod(File $arg) {...}
## Load up a file with the static File::load and you will have access to a body property

class File
{
  static public $mimes = [
    'png'  => 'image/png',
    'jpeg' => 'image/jpeg',
    'jpg'  => 'image/jpeg',
    'webp' => 'image/webp',
    'ico'  => 'image/vnd',
    'woff2'=> 'font/woff2',
    'pdf'  => 'application/pdf',
    'mp4'  => 'video/mp4',
    'mov'  => 'video/quicktime',
    'm4a'  => 'audio/m4a',
    'mp3'  => 'audio/mp3',
    'xml'  => 'application/xml',
    'html' => 'text/html',
    'js'   => 'application/javascript',
    'json' => 'application/json',
    'css'  => 'text/css',
    'svg'  => 'image/svg+xml',
    'zip'  => 'application/zip',
    'gz'   => 'application/x-gzip'
  ];
  
  public $uri, $url, $type, $info, $mime, $local = true, $size = 0, $body = '';

  public function __construct(string $identifier, ?string $type = null)
  {
    $this->uri   = $identifier;
    $identifier  = parse_url($identifier);
    $this->info  = $identifier + pathinfo($identifier['path'] ?? '/');
    $this->type  = strtolower($type ?: $this->info['extension'] ?? 'html');
    $this->info['scheme'] ??= ($this->type == 'gz' ? 'compress.zlib' : 'file');
    $this->local = substr($this->info['scheme'], 0, 4) != 'http';
    $this->url   = $this->local ? $this->info['scheme'].'://'.realpath($this->uri) : $this->uri;
    $this->mime  = self::$mimes[$this->type];
  }
  
  static public function load($path)
  {
    $instance = new static($path);
    if (! $instance->body = file_get_contents($instance->url))
      throw new InvalidArgumentException('Bad path: ' . $path);
    $instance->size = strlen($instance->body);
    return $instance;
  }
  
  public function verify(string $hash, string $algo = 'md5') {
    return hash($algo, $this->body) === trim($hash, '"');
  }
  
  public function __toString() {
    return $this->body;
  }
}

###################################################################################################
# Request | This is used to configure an variable request (like a web server) via the constructor
## use static  /GET methods to request and execute preconfigured routes


class Request
{
  const UA = 'Diatom Request';
  public $uri, $method, $data = '', $basic = false, $headers = [];
  public function __construct(array $headers, $default = 'index.html')
  {
    $this->uri    = urldecode(trim($headers['REQUEST_URI'], '/'));
    $this->method = $headers['REQUEST_METHOD'] ?? 'GET';
    
    $this->headers = $headers;
    
    [$basename, $extension] = explode('.', $default);
    preg_match('/\/?(.*?)(?:\.([a-z]{2,}))?$/m', $this->uri, $match);
    
    $this->route = ($match[1] ?? $basename)  ?: $basename;  // the match may or may not
    $this->type  = ($match[2] ?? $extension) ?: $extension; // have a key w/ a falsy value
    
    $this->mime    = $headers['CONTENT_TYPE']  ?? File::$mimes[$this->type] ?? File::$mimes['html'];
    $this->default = isset($headers['partial']) ? $this->route : $basename;
    
    // 'Basic' requests do not need a layout
    $this->basic = $this->mime != 'text/html' || ($headers['HTTP_YIELD'] ?? false);  
    
    if (in_array($this->method, ['POST','PUT']) && ($headers['CONTENT_LENGTH'] ?? 0) > 0) {
      // can be _POST or stdin... consider  
      $this->data = file_get_contents('php://input');
    }
  }
  
  static public function open($uri, array $data = [], array $headers = []): Document {
    $response = new Response(new self(array_merge($headers,[
      'REQUEST_URI'    => $uri,
      'REQUEST_METHOD' => 'GET',
      'CONTENT_TYPE'   => $headers['content-type'] ?? null,
      'HTTP_YIELD'     => true,
    ])));

    return Route::compose($response)->render($response->merge($data));
  }
  
  static private function make(string $method, string $url, ?array $data, array $headers, ?callable $callback)
  {
    $response = new Response(new self(array_merge($headers, [
      'REQUEST_URI'    => parse_url($url)['path'],
      'REQUEST_METHOD' => $method,
      'CONTENT_TYPE'   => $headers['content-type'] ?? null,
    ])));
    
    curl_setopt_array($ch = curl_init(), [
      CURLOPT_CUSTOMREQUEST    => $method,
      CURLOPT_USERAGENT        => self::UA,
      CURLOPT_URL              => $url,
      CURLOPT_HEADER           => false,
      CURLOPT_HEADERFUNCTION   => [$response, 'header'],
      CURLOPT_RETURNTRANSFER   => true,
    ]);
    
    if ($method != 'GET') {
      if ($headers['content-type'] == 'application/json')
        $data = json_encode($data);
      
      if (! empty($data))
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    }
    
    foreach ($headers as $key => &$header) {
      $name = mb_convert_case(strtolower(str_replace('_', '-', $key)), MB_CASE_TITLE, "UTF-8");
      $header =  "{$name}: {$header}";
    }
    
    if (! empty($headers))
      curl_setopt($ch, CURLOPT_HTTPHEADER, array_values($headers));
    
    if ($callback) {
      curl_setopt($ch, CURLOPT_NOPROGRESS, false);
      curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, $callback);
    }
    
    $response->body = curl_exec($ch);
    
    curl_close($ch);
    
    $type = $response->headers['content-type'] ??= 'text/plain';
    if (strpos($type, 'application/json') !== false)
      $response->merge(json_decode($response->body, true));
    else if (substr($type, -2) == 'ml')
      $response->data = Document::open($response);
    
    return $response;
  }
  
  static public function GET($url, array $headers = [], ?callable $callback = null): Response {
    return self::make('GET', $url, null, $headers, $callback);
  }
  
  static public function POST($url, array $data, array $headers = [], ?callable $callback = null): Response {
    $headers['content-type'] ??= 'multipart/form-data';
    return self::make('POST', $url, $data, $headers, $callback);
  }
  
  static public function PATCH($url, array $data, array $headers = []): Response {
    return self::make('PATCH', $url, $data, $headers, null);
  }
  
  public function __toString() {
    return $this->uri;
  }
}


interface Router
{
  public function __invoke(array $routes): Controller;
  public function delegate($config);
}


class Response extends File implements Router
{
  use Registry;
  public $action, $params, $request, $headers = [], $layout = null, $view;
  
  static private $pages = [];

  public function __construct(Request $request, array $data = [])
  {
    parent::__construct($request->uri, $request->type);
    $this->merge($data);
    $this->request = $request;
    $this->body    = self::$pages[$request->default] ?? null;
    $this->params  = explode('/', $request->route);
    $this->action  = strtolower(array_shift($this->params));
    $this->view    = self::$pages[$request->route] ?? self::$pages[$this->action] ?? null;
  }
  
  public function yield($key, $template)
  {
    if (! $this->layout && $this->body) $this->layout = new Template($this->body);
    $this->layout->set($key, $template);
  }
  
  public function __invoke(array $routes): Controller {
    array_unshift($this->params, 'index');
    return new Class extends Controller {
      public function index() {
        if (! $this->view) throw new Exception("'{$this->router->action}' Not Found", 404);
        return $this->view;
      }
    };
  }
  
  public function header($resource, $header)
  {
    if (!empty(trim($header))) {
      $split = strpos($header, ':') ?: 0;
      $key   = $split ? strtolower(substr($header, 0, $split)) : 'status'; 
      $this->headers[$key] = trim($split ? substr($header, $split+1) : $header);
    }
    return strlen($header);
  }
  
  # TODO refactor; this is ugly and confusing
  public function delegate($config) {
    if ($this->request->basic) {
      if ($config && ! ($config instanceof DOMNode)) return $config;
      return new Template(($config instanceof DOMNode ? $config : $this->view), $this->layout);
    } else if ($this->body != $config)
      $this->yield(Template::YIELD, $config);
    
    return $this->layout;
  }
  
  static public function PAIR(array $files, $error = 'error/index.html')
  {
    $queue = new SplPriorityQueue;
    self::$pages['error'] = Document::open($error);
    foreach(Data::apply($files, 'Document::open') as $DOM) {
    
      $info  = $DOM->info;
      $info['route'] ??= $info['path']['filename'];
      self::$pages[$info['route']] = $DOM;
      
      if (isset($info['publish']))
        $queue->insert($info, -$info['publish']);
    }

    return iterator_to_array($queue);
  }
}

###################################################################################################
# Command | this is the non-http version of the router interface as a means to direct CLI scripts
# that can interact with same components of the application, including running the applications
# own Request/Response calls to gather data (if specified properly).

class Command implements Router
{
  use Registry;
  public $script, $params, $action, $status = 0, $input;
    
  static public function __callStatic($key, $args) {
    return Route::compose(new self([$key, ...$args]));
  }
  
  public function __construct(array $params = [])
  {
    $this->params = $params;
    $this->action = array_shift($this->params) ?: 'help';
    $this->input  = fopen ('php://stdin', 'r');
  }
  
  public function prompt($message)
  {
    echo $message . ": ";
    return trim(fgets($this->input));
  }
  
  public function __invoke(array $routes): Controller {
    return new Class($routes) extends Controller {
      public function __construct($routes) {
        $this->routes = $routes;
      }
      
      public function index() {
        return "Run 'bin/task help *name*' to see signatures\nCommands: \n" 
               . print_r(array_keys($this->routes), true);
      }
      
      public function __call($name, $args) {
        $object = $this->routes[$name];
        if ($object instanceof Controller) {
          $in = new ReflectionObject($object);
          $object = [];
          foreach ($in->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if($method->getDeclaringClass()->name == $in->name && $method->name[0] != '_') {
              $object[$method->name] = array_map(fn($param) => (string)$param, $method->getParameters());
            }
          }
        }
        return print_r($object, true);
      }
    };
  }
  
  public function delegate($config) {
    return $config;
  }
}

###########################################################################################################
# Controller | Usually extended, but can be instantiatied @see Router. Follows the common practice of
# many frameworks where the method name is the second parameter in a url. Controllers are applied
# using the Route::name(callable) convention (also specifiels __invoke to return default controller)

abstract class Controller
{
  protected $router;

  public function __get($key) {
    return $this->router->{$key};
  }
  
  protected function init(Router $router) {
    return true;
  }
  
  public function yield($key, $value)
  {
    return $this->router->yield($key, $value);
  }
  
  public function index() {
    throw new Exception('Not Implemented', 501);
  }
  
  public function __invoke($action = 'index', ...$params)
  {
    $this->action = strtolower($action);
    if (! is_callable([$this, $this->action])) throw new Exception("'{$action}' not found", 404);
    return  $this->{$this->action}(...$params);
  }
  
  public function bindTo(Router $route): Controller
  {
    $this->router = $route;
    $this->init($route);
    return $this;
  }
}

###########################################################################################################
# Template | rarely need to interact with this directly, it is mainly responsible for allowing
# DOM objects to be parsed in rendered against user-supplied data. All configuration for this
# happens in templates themselves, save for the `render` method, which is how data is applied

class Template
{
  public const YIELD = '-default-view-object-';
  
  private $DOM, $templates = [], $slugs = [], $cache;
  
  public function __construct(DOMnode $input, ?self $parent = null, $cache = false)
  {
    if ($input instanceof Element) {
      $this->DOM = new Document($input->export());
      $this->DOM->info = $input->ownerDocument->info ?? null;
      foreach (Document::$callbacks['open'] as $render) call_user_func($render, $this->DOM);
    } else {
      $this->DOM = $input;
    }
    if ($parent) $this->templates = $parent->templates;
    if ($cache) $this->cache = $this->DOM->documentElement->cloneNode(true);
  }
  
  public function reset() {
    if ($this->cache instanceof DOMNode)
      $this->DOM->replaceChild($this->cache->cloneNode(true), $this->DOM->documentElement);
  }

  
  public function render($data = [], $parse = true): Document
  {
    foreach ($this->getStubs('insert') as [$cmd, $path, $xpath, $context]) {
      
      $DOM = file_exists($path) ? Document::open($path) : Request::open($path, $data);
      $ref = $context->parentNode;
      foreach ($DOM->find($xpath) as $node) {
        if (!$node instanceof Text) $node = (new self($node))->render($data, false)->documentElement;
        $ref->insertBefore($this->DOM->importNode($node, true), $context);
      }
      $ref->removeChild($context);
    }
    
    foreach ($this->getStubs('yield') as [$cmd, $prop, $exp, $ref]) {
      if ($DOM = $this->templates[$prop ?? Template::YIELD] ?? null) {
        $context  = ($exp !== '/') ?  $ref : $ref->nextSibling;
        $node     = (new self($DOM, $this))->render($data, false)->documentElement;
        $ref->parentNode->replaceChild($this->DOM->importNode($node, true), $context);
      }
      
      if ($ref && $ref->parentNode && $parse)
        $ref->parentNode->removeChild($ref);
    }

    foreach ($this->getStubs('iterate') as [$cmd, $key, $exp, $ref]) {
      $context  = $ref->nextSibling;
      $template = new self($context, null, true);
      
      foreach (Data::fetch($key, $data) ?? [] as $datum) {
        $node = $this->DOM->importNode($template->render($datum, true)->documentElement, true);
        $context->parentNode->insertBefore($node, $context);
        $template->reset();
      }
      
      $context->remove();
      $ref->parentNode->removeChild($ref);
    }
    
    if ($parse) $this->parse($data);
    
    return $this->DOM;
  }
  
  # Note, this has a DOMNode as typecase, but should be Document|Element in 8.0
  public function set(string $key, $stub = null): self
  {
    if ($stub instanceof DOMNode) $this->templates[$key] = $stub;
    return $this;
  }
  
  private function parse($data)
  {
    foreach ($this->getSlugs() as $path => $slugs) {
      if ($node = $this->DOM->select($path)) {
        if ($node instanceof Element) $node->removeAttribute('data-id');
        $text = $node->nodeValue;
        foreach ($slugs as [$key, $offset]) {
          $replacement = Data::fetch($key, $data);
          if ($replacement === null && $node->remove()) continue 2;
          $text = substr_replace($text, $replacement, ...$offset);
        }
        $node($text);
      }
    }
  }
  
  private function getStubs($key): iterable
  {
    $exp = "comment()[starts-with(normalize-space(.), '{$key}')";
    if ($key == 'iterate')
      $exp .= " and not(ancestor::*/preceding-sibling::{$exp}])";
      
    return Data::apply($this->DOM->find("//{$exp}]"), function($node) {
      return preg_split('/\s+/', trim($node->data), 3) + [1 => null, 2 => '/', 3 => $node];
    });
  }

  protected function getSlugs($cache = false): iterable
  {
    if (empty($this->slugs)) {
      $xp = "contains(.,'\${') and not(*)";
      foreach ( $this->DOM->find("//*[{$xp} and not(self::script)]|//*/@*[{$xp}]") as $var ) {
        $path  = $var->getNodePath();
        $this->slugs[$path] ??= [];

        preg_match_all('/\$\{.+?\}/i', $var(htmlentities($var)), $match, PREG_OFFSET_CAPTURE);

        foreach (array_reverse($match[0]) as [$k, $i]) {
          
          $split = [mb_strlen(substr($var, 0, $i)), mb_strlen($k)];
          $node  = $var->firstChild->splitText($split[0])->splitText($split[1])->previousSibling;
          $key   = substr($node->nodeValue, 2, -1);

          if ($key[0] == '$') {
            // $node($key);
            continue;
          }
          
          $this->slugs[$path][] = [$key, $split];
        }
      }
    }
    return $this->slugs;
  }
    
  
}

###################################################################################################
# Document | This loads and creates documents that can be searched and modified. Extends the 
# language-supplied DOMDocument object to subclass some of the nodes for convienient interactions.
## While the document object model can be wordy, it is powerful, and it is literally the same api
## in any language that provides an interface (namely JavaScript). This class is used exclusively
## and often to interact with data, templates... basically doing anything with markup involved.

class Document extends DOMDocument
{
  public  $info  = [];
  private $xpath = null,
          $props = [ 'preserveWhiteSpace' => false, 'formatOutput' => true, 'encoding' => 'UTF-8'];
          
  static public $callbacks = ['open' => [], 'close' => []];
  static private $cache = [];
  public function  __construct(string $xml, array $props = [])
  {
    parent::__construct('1.0', 'UTF-8');

    foreach (( $props + $this->props ) as $p => $value) $this->{$p} = $value;
    foreach (['Element','Text','Attr'] as       $c    ) $this->registerNodeClass("DOM{$c}", $c);
    
    if (! $this->loadXML($xml, LIBXML_COMPACT)) throw new ParseError('DOM Parse Error');
    
    $this->xpath = new DOMXpath($this);
  }
  
  public function save($path) {
    return file_put_contents($path, $this->saveXML(), LOCK_EX);
  }
  
  public function find($exp, ?DOMNode $context = null): DOMNodelist
  {
    if (! $result = $this->xpath->query($exp, $context))
      throw new Exception("Malformed predicate: {$exp}", 500);
    
    return $result;
  }
    
  public function select($exp, ?DOMNode $context = null) {
    return $this->find($exp, $context)[0] ?? null; 
  }
  
  public function evaluate(string $exp, ?DOMNode $context) {
    return $this->xpath->evaluate("string({$exp})", $context);
  }
    
  public function __toString()
  {
    foreach (self::$callbacks['close'] as $render) call_user_func($render, $this);
    $prefix = $this->documentElement->nodeName == 'html' ? "<!DOCTYPE html>\n" : '';
    return $prefix . $this->saveXML($this->documentElement);
  }
  
  static public function on(string $type, callable $callback) {
    self::$callbacks[$type][] = $callback;
  }
  
  // accepts string|File
  static public function open($path, $opt = [])
  {
    $key = is_string($path) ? $path : $path->uri;
    if (self::$cache[$key] ?? false) return self::$cache[$key];
    
    $file = $path instanceof File ? $path : File::load($path);

    try {
      if (! $file->local)
        $file->body = preg_replace('/\sxmlns=(\"|\').*?(\1)/', '', $file->body);

      $DOM = (substr($file->mime, -2) == 'ml') ? new self($file->body, $opt) : Parser::load($file);
    } catch (ParseError $e) {
      $err = (object)libxml_get_errors()[0];
      throw new ErrorException($err->message, 500, E_ERROR, realpath($path), $err->line, $e);
    }

    foreach ($DOM->find("/processing-instruction()") as $pi)
      $DOM->info[$pi->target] = trim($pi->data);
    
    $DOM->info['src']     = $path;
    $DOM->info['path']    = $file->info;
    $DOM->info['title'] ??= $DOM->info['path']['filename'];

    foreach (self::$callbacks['open'] as $render) call_user_func($render, $DOM);

    return self::$cache[$key] = $DOM;
  }
}

###################################################################################################
# Document Renderer Callback
# This is actually a configuration, but it is so important I wanted to keep it in the framework—
# I can't imagine building a website and not implementing this feature. It packages css and js
# to be DOM copacetic as well as mitigating the domready dance that can be cumbersome to manage

Document::on('close', function (Document $DOM) {
  // Find style tags, make sure they are CDATA; the esoteric stuff is just (arbitrary) formatting
  foreach ($DOM->find('//style') as $node) {
    $text  = $node->replaceChild(new Text("\n    /**/\n    "), $node->firstChild)->nodeValue;
    $cb    = fn($matches) => join('', array_map('trim', explode("\n", $matches[0])));
    $cdata = sprintf("*/\n    %s\n    /*", preg_replace_callback('(\{[^{]+\})', $cb, preg_replace('/\n\s*\n/', "\n", trim($text))));
    $node->insertBefore($DOM->createCDATASection($cdata), $node->firstChild->splitText(7));
  }
  
  // Lazy load all scripts + enforce embed after DOMready 
  foreach ($DOM->find('//script') as $node) {
    $data = $node->getAttribute('src') ?: sprintf("data:application/javascript;base64,%s", base64_encode($node->nodeValue));
    $node("KIT.script('{$data}')")->removeAttribute('src');
  }
  
  // move things that should be in the <head> and specify autolad.js
  if ($head = $DOM->select('/html/head')) {
    $path = sprintf("data:application/javascript;base64,%s", base64_encode(File::load('ux/autoload.js')));
    $head->appendChild(new Element('script'))('')->setAttribute('src', $path);
    $DOM->documentElement->setAttribute('xmlns', 'http://www.w3.org/1999/xhtml');
    foreach ($DOM->find('.//style|.//meta|.//link', $head->nextSibling) as $node) $head->appendChild($node);
  }
});


###################################################################################################
# DOMtype extensions

class Element extends DOMElement implements ArrayAccess {
  use invocable;
  
  public function find(string $path) {
    return $this->ownerDocument->find($path, $this);
  }
  
  public function select(string $path) {
    return $this->ownerDocument->select($path, $this);
  }
  
  public function export(): string {
    return $this->ownerDocument->saveXML($this);
  }

  public function offsetExists($key) {
    return $this->find($key)->count() > 0;
  }

  public function offsetGet($key, $create = false, $index = 0)
  {
    if (($nodes = $this->find($key)) && ($nodes->length > $index))
      return new Data($nodes);
    else if ($create)
      return $this->appendChild(($key[0] == '@') ? new Attr(substr($key, 1)) : new Element($key));
    else 
      throw new UnexpectedValueException("{$this->parentNode->nodeName} does not have {$key}");
  }

  public function offsetSet($key, $value) {
    return $this->offsetGet($key, true)($value);
  }

  public function offsetUnset($key) {
    return $this[$key]->remove();
  }
  
  public function remove() {
    return ($parent = $this->parentNode) ? $parent->removeChild($this) : null;
  }
  
  public function __call($key, $args): DOMNode {
    return $this->offsetSet($key, ...$args);
  }
}

class Text extends DOMText { use invocable; }
class Attr extends DOMAttr {
  use invocable;
  public function remove() {
    return ($elem = $this->ownerElement) ? $elem->removeAttribute($this->nodeName) : null;
  }
}

trait invocable
{
  public function __invoke($input): self
  {
    $this->nodeValue = '';
    if ($input instanceof DOMNode) $this->appendChild($input);
    else $this->nodeValue = htmlspecialchars($input);
    return $this;
  }
  
  public function __toString(): string {
    return $this->nodeValue;
  }
}

###################################################################################################
# Registry

trait Registry {
  public $data = [];
  public function __set($key, $value) {
    $this->data[$key] = $value;
  }
  public function __get($key) {
    return $this->data[$key];
  }
  public function merge(array $data) {
    return $this->data = array_merge($data, $this->data);
  }
}

###################################################################################################
# Data

class Data extends ArrayIterator
{
  public $length = 0;
  private $maps = [];
    
  static public function fetch($namespace, $data, $wedge = '.')
  {
    if (is_array($namespace)) {
      while ($key = array_shift($namespace)) {
        $data = $data[$key] ?? null;
        if (is_callable($data) && ! $data instanceof Element) {
          return $data(...$namespace);
        }

      }
      return $data;
    }
    return $data[$namespace] ?? self::fetch(explode($wedge, $namespace), $data);
  }
    
  
  static public function apply(iterable $data, callable $callback): self {
    return (new self($data))->map($callback);
  }
  
  public function __construct(iterable $data){
    if (! is_array($data))
      $data = iterator_to_array($data);
    parent::__construct($data);
    $this->length = count($data);
  }
  
  public function current() {
    $current = parent::current();
    foreach ($this->maps as $callback) $current = $callback($current);
    return $current;
  }
  
  public function map(callable $callback): self {
    $this->maps[] = $callback;
    return $this;
  }

  public function sort($callback): self
  {
    if (is_callable($callback)) {
      if (!empty($this->maps)) return (new Data ($this))->sort($callback);

      $this->uasort($callback);
    }
    return $this;
  }
  
  public function filter(callable $callback) {
    return new CallbackFilterIterator($this, $callback);
  }

  public function limit($start, $length = -1) {
    $start-= 1;
    $start = ($length > 0 ? $start * $length : $start);
    $limit = new LimitIterator($this, $start, $length);
    $limit->length = $this->length;
    return $limit;
  }
  
  public function __toString() {
    return (string) $this->current();
  }
}

###################################################################################################
# Parser

class Parser {
  const EXT   = 'xmd';
  
  static public function load(File $file) {
    $ext = ['css' => '<style/>', 'js' => '<script/>', 'txt' => '<pre/>'];
    if (isset($ext[$file->type])) {
      $DOM = new Document($ext[$file->type]);
      $DOM->documentElement->appendChild(new Text($file->body));
      return $DOM;
    }
    throw new Error("{$file->type} Not Supported", 500);
  }
}

###################################################################################################
# Model | a wrapper for any DOM element that turns it into a model-able resource
# 
abstract class Model implements ArrayAccess {
  protected $context;

  public function __construct(Element $context) { 
    $this->context = $context;
  }
  
  public function collect(string $expression) {
    return Data::apply($this->context->find($expression), fn($item) => new static($item));
  }
      
  public function offsetExists($key) {
    return isset($this->context[$key]) || method_exists($this, "get{$key}");
  }

  public function offsetGet($key)
  {
    if (property_exists($this, $key)) return $this->{$key};

    $method  = "get{$key}";
    return method_exists($this, $method) ? $this->{$method}($this->context) : $this->context[$key];
  }

  public function offSetSet($key, $value) {
    return $this->context[$key] = $value;
  }

  public function offsetUnset($key) {
    unset($this->context[$key]);
    return true;
  }
  
  final public function __toString() {
    return $this->context['@id'];
  }
}

###################################################################################################
# Redirect | simply throw this with a url and the app will catch it and exit nicely

class Redirect extends Exception {
  // 301 permanent, 302, temporary, 303 after put/post
  public $headers = [
    ['Cache-Control: no-store, no-cache, must-revalidate, max-age=0'],
    ['Cache-Control: post-check=0, pre-check=0', false],
    ['Pragma: no-cache'],
  ];
  
  public function __construct(string $location, $code = 302) {
    $this->headers[] = ["Location: {$location}", false, $code];
  }
  
  public function __destruct() {
    foreach ($this->headers as $header) header(...$header);
  }
}