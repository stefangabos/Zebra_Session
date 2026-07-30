<?php

namespace Zebra\Sniffs\Formatting;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Standards\Generic\Sniffs\Formatting\DisallowMultipleStatementsSniff;

/**
 * Each statement on a line by itself, except inside a closure.
 *
 *      $flattened = array_map(function($row) { return $row['id']; }, $rows);    allowed
 *      $a = 1; $b = 2;                                                          still reported
 *
 * A closure short enough to sit on one line is written on one line in these libraries, and the statement
 * inside it is what the standard sniff counts as the second statement on that line. It already makes the
 * same kind of exception for the three parts of a "for" condition; this adds one for closures and leaves
 * everything else to it.
 */
class ClosureAwareMultipleStatementsSniff extends DisallowMultipleStatementsSniff
{
    /**
     * @param  File  $phpcsFile  the file being scanned
     * @param  int   $stackPtr   position of the semicolon in the token stack
     *
     * @return void
     */
    public function process(File $phpcsFile, $stackPtr) {
        $tokens = $phpcsFile->getTokens();

        // the statement this semicolon closes
        if ($this->is_inside_a_closure($tokens, $stackPtr)) {
            return;
        }

        // and the one before it, which is the other half of what the standard sniff is complaining about -
        // for "$callback = function($x) { return $x; };" it is the inner return that sits inside the closure
        // while the semicolon being reported belongs to the assignment outside it
        $previous = $phpcsFile->findPrevious([T_SEMICOLON, T_OPEN_TAG, T_OPEN_TAG_WITH_ECHO], ($stackPtr - 1));

        if (
            $previous !== false
            && $tokens[$previous]['code'] === T_SEMICOLON
            && $tokens[$previous]['line'] === $tokens[$stackPtr]['line']
            && $this->is_inside_a_closure($tokens, $previous)
        ) {
            return;
        }

        parent::process($phpcsFile, $stackPtr);
    }

    /**
     * Whether the given token sits inside a closure.
     *
     * Every token carries the list of scopes it is nested in, so this is a question about that list rather
     * than something that has to be worked out by walking backwards.
     *
     * @param  array<int,array<string,mixed>>  $tokens
     * @param  int                             $stackPtr
     *
     * @return bool
     */
    private function is_inside_a_closure($tokens, $stackPtr) {
        if (empty($tokens[$stackPtr]['conditions'])) {
            return false;
        }

        foreach ($tokens[$stackPtr]['conditions'] as $code) {
            if ($code === T_CLOSURE) {
                return true;
            }
        }

        return false;
    }
}
