<?php

/**
* FINE granularity DIFF
*
* Computes a set of instructions to convert the content of
* one string into another.
*
* Originally created by Raymond Hill (github.com/gorhill/PHP-FineDiff), brought up
* to date by Cog Powered (github.com/cogpowered/FineDiff).
*
* @copyright Copyright 2011 (c) Raymond Hill (http://raymondhill.net/blog/?p=441)
* @copyright Copyright 2013 (c) Robert Crowe (http://cogpowered.com)
* @link https://github.com/cogpowered/FineDiff
* @version 0.0.1
* @license MIT License (http://www.opensource.org/licenses/mit-license.php)
*/

namespace cogpowered\FineDiff\Render;

use cogpowered\FineDiff\Parser\OpcodeInterface;

class Html extends Renderer
{
    public function callback($opcode, $from, $from_offset, $from_len)
    {
        if ($opcode === 'c') {
            $html = htmlentities(substr($from, $from_offset, $from_len), ENT_COMPAT, 'UTF-8');
        } else if ($opcode === 'd') {

            $deletion = substr($from, $from_offset, $from_len);

            if (strcspn($deletion, " \n\r") === 0) {
                $deletion = str_replace(array("\n","\r"), array('\n','\r'), $deletion);
            }

            $html = '<del>'.htmlentities($deletion, ENT_COMPAT, 'UTF-8').'</del>';

        } else /* if ( $opcode === 'i' ) */ {
            $html = '<ins>'.htmlentities(substr($from, $from_offset, $from_len), ENT_COMPAT, 'UTF-8').'</ins>';
        }

        return $html;
    }
}
