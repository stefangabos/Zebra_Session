<?php

namespace Zebra\Sniffs\Functions;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;

/**
 * One space after the "function" keyword when a name follows it, none when it does not.
 *
 *      function get_tables($database)      correct - a name follows
 *      function($value) { ... }            correct - a closure, nothing follows but the arguments
 *
 *      function  get_tables($database)     wrong - more than one space
 *      function ($value) { ... }           wrong - a closure does not take one
 *
 * PSR-12 asks for a space in both cases, and the sniff that enforces it - PEAR.Functions.FunctionDeclaration,
 * which Squiz.Functions.MultiLineFunctionDeclaration extends - has no property for treating closures
 * differently. Excluding its SpaceAfterFunction code would silence it for named declarations too, so the
 * rule is written out here rather than switched off.
 */
class FunctionKeywordSpacingSniff implements Sniff
{
    /**
     * PHP_CodeSniffer gives a closure its own token rather than reporting it as T_FUNCTION, which is what
     * makes the two cases straightforward to tell apart.
     *
     * @return array<int|string>
     */
    public function register() {
        return [T_FUNCTION, T_CLOSURE];
    }

    /**
     * @param  File  $phpcsFile  the file being scanned
     * @param  int   $stackPtr   position of the "function" keyword in the token stack
     *
     * @return void
     */
    public function process(File $phpcsFile, $stackPtr) {
        $tokens = $phpcsFile->getTokens();

        $is_closure = $tokens[$stackPtr]['code'] === T_CLOSURE;

        $expected = $is_closure ? 0 : 1;

        // how much whitespace there actually is, counting a line break as something other than a number so
        // that it can never be mistaken for the single space a named declaration wants
        if ($tokens[($stackPtr + 1)]['code'] !== T_WHITESPACE) {
            $found = 0;
        } elseif (strpos($tokens[($stackPtr + 1)]['content'], $phpcsFile->eolChar) !== false) {
            $found = 'newline';
        } else {
            $found = $tokens[($stackPtr + 1)]['length'];
        }

        if ($found === $expected) {
            return;
        }

        $error = $is_closure
            ? 'Expected no space after FUNCTION keyword for a closure; %s found'
            : 'Expected 1 space after FUNCTION keyword; %s found';

        $code = $is_closure ? 'SpaceAfterClosureKeyword' : 'SpaceAfterFunctionKeyword';

        $fix = $phpcsFile->addFixableError($error, $stackPtr, $code, [$found]);

        if ($fix !== true) {
            return;
        }

        if ($found === 0) {
            $phpcsFile->fixer->addContent($stackPtr, ' ');
        } elseif ($expected === 0) {
            $phpcsFile->fixer->replaceToken(($stackPtr + 1), '');
        } else {
            $phpcsFile->fixer->replaceToken(($stackPtr + 1), ' ');
        }
    }
}
