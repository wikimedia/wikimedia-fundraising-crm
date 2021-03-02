<?php

use CRM_Rpow_Classifier as Classifier;

class CRM_Rpow_ClassifierTest extends \PHPUnit\Framework\TestCase {

  public function getExampleFiles() {
    $files = [
      Classifier::TYPE_READ => __DIR__ . '/examples/reads.sql',
      Classifier::TYPE_WRITE => __DIR__ . '/examples/writes.sql',
      Classifier::TYPE_BUFFER => __DIR__ . '/examples/buffers.sql',
    ];

    $exs = [];
    foreach ($files as $expectOutput => $file) {
      $sqls = CRM_Rpow_ExampleLoader::load($file);
      foreach ($sqls as $sql) {
        $exs[] = [$expectOutput, $sql];
      }
    }
    return $exs;
  }

  public function getExamplesWithStrings() {
    $strs = [
      ['SELECT "@x := 1"', 'SELECT ""'],
      ['SELECT @x := 1', 'SELECT @x := 1'],
      ['SELECT "foo", "bar"', 'SELECT "", ""'],
      ['SELECT "foo\'s", "bar\'s"', 'SELECT "", ""'],
      ['SELECT "foo\\\"s"', 'SELECT ""'],
      ['SELECT "foo\a"', 'SELECT ""'],
      ['SELECT "foo\\""', 'SELECT ""'],
      ['SELECT "foo \"bar\"", "whiz"', 'SELECT "", ""'],
      ['SELECT foo("bar") AS `whiz`', 'SELECT foo("") AS ``'],
      ['SELECT \'foo("bar")\' AS `wh"iz`', 'SELECT \'\' AS ``'],
      ['SELECT `foo` AS `foobar` WHERE \'whim\'', 'SELECT `` AS `` WHERE \'\''],
    ];
    // print_r($strs);
    return $strs;
  }

  public function getActiveCommentExamples() {
    $strs = [
      ['/*!40101 SET NAMES utf8*/', 'SET NAMES utf8'],
      ['/*!50101 SET NAMES utf8 */', 'SET NAMES utf8 '],
      ['/*!40101 SET NAMES utf8  */', 'SET NAMES utf8 '],
      ['/*!not-a-number SET NAMES UTF8*/', '/*!not-a-number SET NAMES UTF8*/'],
      ['/*50101 SET NAMES utf8 */', '/*50101 SET NAMES utf8 */'],
      ['SELECT /*!50111 DISTINCT*/ foo FROM /*!50111 bar*/', 'SELECT DISTINCT foo FROM bar'],
      ['SELECT /*!50111 DISTINCT */ foo FROM bar', 'SELECT DISTINCT foo FROM bar'],
    ];
    return $strs;
  }

  /**
   * @dataProvider getExampleFiles
   */
  public function testClassify($expectOutput, $sql) {
    $c = new Classifier();
    $this->assertEquals($expectOutput, $c->classify($sql), "Expect the following expression to be classified as {$expectOutput}: {$sql}");
  }

  /**
   * @dataProvider getExamplesWithStrings
   */
  public function testStripStrings($input, $expected) {
    $c = new Classifier();
    $this->assertEquals($expected, $c->stripStrings($input));
  }

  /**
   * @dataProvider getActiveCommentExamples
   */
  public function testCleanActiveComments($input, $expected) {
    $c = new Classifier();
    $this->assertEquals($expected, $c->cleanActiveComments($input));
  }

}
