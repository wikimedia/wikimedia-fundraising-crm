<?php
/*
 +--------------------------------------------------------------------+
 | Copyright CiviCRM LLC. All rights reserved.                        |
 |                                                                    |
 | This work is published under the GNU AGPLv3 license with some      |
 | permitted exceptions and without any warranty. For full license    |
 | and copyright information, see https://civicrm.org/licensing       |
 +--------------------------------------------------------------------+
 */

namespace Civi\Api4\Utils;

/**
 * Just another place to put static functions...
 */
class ReflectionUtils {

  /**
   * @param \Reflector|\ReflectionClass $reflection
   * @param string $type
   *   If we are not reflecting the class itself, specify "Method", "Property", etc.
   * @param array $vars
   *   Variable substitutions to perform in the docblock
   * @return array
   */
  public static function getCodeDocs($reflection, $type = NULL, $vars = []) {
    $comment = $reflection->getDocComment();
    foreach ($vars as $key => $val) {
      $comment = str_replace('$' . strtoupper(\CRM_Utils_String::pluralize($key)), \CRM_Utils_String::pluralize($val), $comment);
      $comment = str_replace('$' . strtoupper($key), $val, $comment);
    }
    $docs = self::parseDocBlock($comment);

    // Recurse into parent functions
    if (isset($docs['inheritDoc']) || isset($docs['inheritdoc'])) {
      unset($docs['inheritDoc'], $docs['inheritdoc']);
      $newReflection = NULL;
      try {
        if ($type) {
          $name = $reflection->getName();
          $reflectionClass = $reflection->getDeclaringClass()->getParentClass();
          if ($reflectionClass) {
            $getItem = "get$type";
            $newReflection = $reflectionClass->$getItem($name);
          }
        }
        else {
          $newReflection = $reflection->getParentClass();
        }
      }
      catch (\ReflectionException $e) {
      }
      if ($newReflection) {
        // Mix in
        $additionalDocs = self::getCodeDocs($newReflection, $type, $vars);
        if (!empty($docs['comment']) && !empty($additionalDocs['comment'])) {
          $docs['comment'] .= "\n\n" . $additionalDocs['comment'];
        }
        $docs += $additionalDocs;
      }
    }
    return $docs;
  }

  /**
   * @param string $comment
   * @return array
   */
  public static function parseDocBlock($comment) {
    $info = [];
    $param = NULL;
    $bufferedVar = '';
    $parsingVarArray = false;

    foreach (preg_split("/((\r?\n)|(\r\n?))/", $comment) as $num => $line) {
      if (!$num || str_contains($line, '*/')) {
        continue;
      }

      $line = ltrim(trim($line), '*');
      if (strlen($line) && $line[0] === ' ') {
        $line = substr($line, 1);
      }

      // Continue parsing multiline array{...}
      if ($parsingVarArray) {
        $bufferedVar .= $line;
        if (str_contains($line, '}')) {
          $parsingVarArray = false;
          // Parse the full array shape now
          $info['type'] = ['array'];
          $info['shape'] = self::parseArrayShape($bufferedVar);
        }
        continue;
      }

      if (str_starts_with(ltrim($line), '@')) {
        $words = explode(' ', ltrim($line, ' @'));
        $key = array_shift($words);
        $param = NULL;

        if ($key == 'var') {
          $varType = implode(' ', $words);
          if (str_starts_with($varType, 'array{') && !str_contains($varType, '}')) {
            $parsingVarArray = true;
            $bufferedVar = $varType;
          }
          elseif (str_starts_with($varType, 'array{') && str_contains($varType, '}')) {
            $info['type'] = ['array'];
            $info['shape'] = self::parseArrayShape($varType);
          }
          else {
            $info['type'] = explode('|', strtolower($words[0]));
          }
        }
        elseif ($key == 'return') {
          $info['return'] = explode('|', $words[0]);
        }
        elseif ($key == 'options') {
          $val = str_replace(', ', ',', implode(' ', $words));
          $info[$key] = explode(',', $val);
        }
        elseif ($key == 'throws' || $key == 'see') {
          $info[$key][] = implode(' ', $words);
        }
        elseif ($key == 'param' && $words) {
          $type = $words[0][0] !== '$' ? explode('|', array_shift($words)) : NULL;
          $param = rtrim(array_shift($words), '-:()/');
          $info['params'][$param] = [
            'type' => $type,
            'description' => $words ? ltrim(implode(' ', $words), '-: ') : '',
            'comment' => '',
          ];
        }
        else {
          // Unrecognized annotation, but we'll duly add it to the info array
          $val = implode(' ', $words);
          $info[$key] = strlen($val) ? $val : TRUE;
        }
      }
      elseif ($param) {
        $info['params'][$param]['comment'] .= $line . "\n";
      }
      elseif ($num == 1) {
        $info['description'] = ucfirst($line);
      }
      elseif (!$line) {
        $info['comment'] = isset($info['comment']) ? "{$info['comment']}\n" : NULL;
      }
      // For multi-line description.
      elseif (count($info) === 1 && isset($info['description']) && substr($info['description'], -1) !== '.') {
        $info['description'] .= ' ' . $line;
      }
      else {
        $info['comment'] = isset($info['comment']) ? "{$info['comment']}\n$line" : $line;
      }
    }
    if (isset($info['comment'])) {
      $info['comment'] = rtrim($info['comment']);
    }
    return $info;
  }

  protected static function parseArrayShape($definition) {
    $definition = trim($definition);
    if (str_starts_with($definition, 'array{') && str_ends_with($definition, '}')) {
      $definition = substr($definition, 6, -1); // remove array{ and ending }
    }

    $shape = [];
    $parts = preg_split('/,(?![^\{]*\})/', $definition); // splits by comma but not inside nested braces

    foreach ($parts as $part) {
      if (strpos($part, ':') !== false) {
        [$key, $type] = explode(':', $part, 2);
        $key = trim($key);
        $types = array_map('trim', explode('|', trim($type)));
        $shape[$key] = $types;
      }
    }

    return $shape;
  }

  /**
   * List all traits used by a class and its parents.
   *
   * @param object|string $class
   * @return string[]
   */
  public static function getTraits($class): array {
    $traits = [];
    // Get traits of this class + parent classes
    do {
      $traits = array_merge(class_uses($class), $traits);
    } while ($class = get_parent_class($class));
    // Get traits of traits
    foreach ($traits as $trait => $same) {
      $traits = array_merge(class_uses($trait), $traits);
    }
    return $traits;
  }

  /**
   * Get a list of standard properties which can be written+read by outside callers.
   *
   * @param string $class
   */
  public static function findStandardProperties($class): iterable {
    try {
      /** @var \ReflectionClass $clazz */
      $clazz = new \ReflectionClass($class);

      yield from [];
      foreach ($clazz->getProperties(\ReflectionProperty::IS_PROTECTED | \ReflectionProperty::IS_PUBLIC) as $property) {
        if (!$property->isStatic() && $property->getName()[0] !== '_') {
          yield $property;
        }
      }
    }
    catch (\ReflectionException $e) {
      throw new \RuntimeException(sprintf("Cannot inspect class %s.", $class));
    }
  }

  /**
   * Check if a class method is deprecated
   *
   * @param string $className
   * @param string $methodName
   * @return bool
   * @throws \ReflectionException
   */
  public static function isMethodDeprecated(string $className, string $methodName): bool {
    $reflection = new \ReflectionClass($className);
    $docBlock = $reflection->getMethod($methodName)->getDocComment();
    return str_contains($docBlock, "@deprecated");
  }

  /**
   * Find any methods in this class which match the given prefix.
   *
   * @param string $class
   * @param string $prefix
   */
  public static function findMethodHelpers($class, string $prefix): iterable {
    try {
      /** @var \ReflectionClass $clazz */
      $clazz = new \ReflectionClass($class);

      yield from [];
      foreach ($clazz->getMethods(\ReflectionMethod::IS_PUBLIC | \ReflectionMethod::IS_PROTECTED) as $m) {
        if (str_starts_with($m->getName(), $prefix)) {
          yield $m;
        }
      }
    }
    catch (\ReflectionException $e) {
      throw new \RuntimeException(sprintf("Cannot inspect class %s.", $class));
    }
  }

  /**
   * Cast the $value to the preferred $type (if we're fairly confident).
   *
   * This is like PHP's `settype()` but totally not. It only casts in narrow circumstances.
   * This reflects an opinion that some castings are better than others.
   *
   * These will be converted:
   *
   *    cast('123', 'int') => 123
   *    cast('123.4', 'float') => 123.4
   *    cast('0', 'bool') => FALSE
   *    cast(1, 'bool') => TRUE
   *
   * However, a string like 'hello' will never cast to bool, int, or float -- because that's
   * a senseless request. We'll leave that to someone else to figure.
   *
   * @param mixed $value
   * @param array $paramInfo
   * @return mixed
   *   If the $value is agreeable to casting according to a type-rule from $paramInfo, then
   *   we return the converted value. Otherwise, return the original value.
   */
  public static function castTypeSoftly($value, array $paramInfo) {
    if (count($paramInfo['type'] ?? []) !== 1) {
      // I don't know when or why fields can have multiple types. We're just gone leave-be.
      return $value;
    }

    switch ($paramInfo['type'][0]) {
      case 'bool':
        if (in_array($value, [0, 1, '0', '1'], TRUE)) {
          return (bool) $value;
        }
        break;

      case 'int':
        if (is_numeric($value)) {
          return (int) $value;
        }
        break;

      case 'float':
        if (is_numeric($value)) {
          return (float) $value;
        }
        break;

    }

    return $value;
  }

}
