<?php
$mark = microtime(true);
// $test = <<<EOD
//
// # Test h1
// #### Test h4
// ####### Test h7 (shouldnt work)
//
//
// 1. ordered one
// 2. ordered two
//   - nested unordered [one](/url)
//   - nested unordered two
//     - unordered double nested one
//     - unordered double nested two
//       1. orderded triple nested one
//       2. ordered triple nested two
//     - unordered double nested three
// 3. ordered three
//
// - unordered one
// - unordered two
//
// this is some paragraph <strong>text</strong> with **strong** and this is a [![img](http://sometthing.com)](http://example.com) and another [thing](http://whatever.com) and then I'm finding some more [text](to link to)"something great"
//
// > this is a blockquote paragraph "with some quoted text."
//
// EOD;
// echo "------ ORIGINAL --------\n\n\n\n ".$test . "\n\n\n\n";

include 'vendor-markdown.php';

// include('vendor-markdown.php');
echo "\n\n\n\n ------ CLASSIC ---------\n\n\n\n" . (new Markdown)->text(file_get_contents('example.md')) . "\n";



echo  (microtime(true) - $mark). 'sec, mem:' . (memory_get_peak_usage() / 1000) . "kb\n";
// include('diatom.php');
// include('block.php');
//
// // echo "\n\n\n\n ------ ENHANCED --------\n\n\n\n". new MarkDOM($test);
//
// echo "\n\n\n\n ------ ENHANCED --------\n\n\n\n". new MarkDown('example.md');

?>