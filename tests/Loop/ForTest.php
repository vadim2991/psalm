<?php
namespace Psalm\Tests\Loop;

use Psalm\Tests\Traits;
use const DIRECTORY_SEPARATOR;

class ForTest extends \Psalm\Tests\TestCase
{
    use Traits\InvalidCodeAnalysisTestTrait;
    use Traits\ValidCodeAnalysisTestTrait;

    /**
     * @return iterable<string,array{string,assertions?:array<string,string>,error_levels?:string[]}>
     */
    public function providerValidCodeParse()
    {
        return [
            'implicitFourthLoop' => [
                '<?php
                    function test(): int {
                      $x = 0;
                      $y = 1;
                      $z = 2;
                      for ($i = 0; $i < 3; $i++) {
                        $x = $y;
                        $y = $z;
                        $z = 5;
                      }
                      return $x;
                    }',
            ],
            'falseToBoolInContinueAndBreak' => [
                '<?php
                    $a = false;

                    for ($i = 0; $i < 4; $i++) {
                      $j = rand(0, 10);

                      if ($j === 2) {
                        $a = true;
                        continue;
                      }

                      if ($j === 3) {
                        $a = true;
                        break;
                      }
                    }',
                'assignments' => [
                    '$a' => 'bool',
                ],
            ],
            'forLoopwithOKChange' => [
                '<?php
                    $j = 5;
                    for ($i = $j; $i < 4; $i++) {
                      $j = 9;
                    }',
            ],
            'preventNegativeZeroScrewingThingsUp' => [
                '<?php
                    function foo() : void {
                      $v = [1 => 0];
                      for ($d = 0; $d <= 10; $d++) {
                        for ($k = -$d; $k <= $d; $k += 2) {
                          if ($k === -$d || ($k !== $d && $v[$k-1] < $v[$k+1])) {
                            $x = $v[$k+1];
                          } else {
                            $x = $v[$k-1] + 1;
                          }

                          $v[$k] = $x;
                        }
                      }
                    }',
            ],
            'whileTrueWithBreak' => [
                '<?php
                    for (;;) {
                        $a = "hello";
                        break;
                    }
                    for (;;) {
                        $b = 5;
                        break;
                    }',
                'assertions' => [
                    '$a' => 'string',
                    '$b' => 'int',
                ],
            ],
            'continueOutsideLoop' => [
                '<?php
                    class Node {
                        /** @var Node|null */
                        public $next;
                    }

                    /** @return void */
                    function test(Node $head) {
                        for ($node = $head; $node; $node = $next) {
                            $next = $node->next;
                            $node->next = null;
                        }
                    }',
            ],
            'echoAfterFor' => [
                '<?php
                    for ($i = 0; $i < 5; $i++);
                    echo $i;',
            ],
        ];
    }

    /**
     * @return iterable<string,array{string,error_message:string,2?:string[],3?:bool,4?:string}>
     */
    public function providerInvalidCodeParse()
    {
        return [
            'possiblyUndefinedArrayInWhileAndForeach' => [
                '<?php
                    for ($i = 0; $i < 4; $i++) {
                        while (rand(0,10) === 5) {
                            $array[] = "hello";
                        }
                    }

                    echo $array;',
                'error_message' => 'PossiblyUndefinedGlobalVariable - src' . DIRECTORY_SEPARATOR . 'somefile.php:4:29 - Possibly undefined ' .
                    'global variable $array, first seen on line 4',
            ],
            'forLoopInvalidation' => [
                '<?php
                    for ($i = 0; $i < 4; $i++) {
                      foreach ([1, 2, 3] as $i) {}
                    }',
                'error_message' => 'LoopInvalidation',
            ],
            'forInfiniteNoBreak' => [
                '<?php
                    for (;;) {
                        $a = "hello";
                    }

                    echo $a;',
                'error_message' => 'UndefinedGlobalVariable',
            ],
        ];
    }
}
