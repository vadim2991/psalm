<?php
namespace Psalm\Tests\FileUpdates;

use Psalm\Internal\Analyzer\FileAnalyzer;
use Psalm\Internal\Analyzer\ProjectAnalyzer;
use Psalm\Internal\Provider\Providers;
use Psalm\Tests\Internal\Provider;
use Psalm\Tests\TestConfig;
use function strpos;
use function array_keys;
use function getcwd;
use const DIRECTORY_SEPARATOR;

class AnalyzedMethodTest extends \Psalm\Tests\TestCase
{
    /**
     * @return void
     */
    public function setUp() : void
    {
        parent::setUp();

        FileAnalyzer::clearCache();

        $this->file_provider = new \Psalm\Tests\Internal\Provider\FakeFileProvider();

        $config = new TestConfig();

        $providers = new Providers(
            $this->file_provider,
            new \Psalm\Tests\Internal\Provider\ParserInstanceCacheProvider(),
            null,
            null,
            new Provider\FakeFileReferenceCacheProvider()
        );

        $this->project_analyzer = new ProjectAnalyzer(
            $config,
            $providers
        );
        $this->project_analyzer->setPhpVersion('7.3');
    }

    /**
     * @dataProvider providerTestValidUpdates
     *
     * @param array<string, string> $start_files
     * @param array<string, string> $end_files
     * @param array<string, string> $error_levels
     *
     * @return void
     */
    public function testValidInclude(
        array $start_files,
        array $end_files,
        array $initial_analyzed_methods,
        array $unaffected_analyzed_methods,
        array $error_levels = []
    ) {
        $test_name = $this->getTestName();
        if (strpos($test_name, 'SKIPPED-') !== false) {
            $this->markTestSkipped('Skipped due to a bug.');
        }

        $this->project_analyzer->getCodebase()->diff_methods = true;

        $codebase = $this->project_analyzer->getCodebase();

        $config = $codebase->config;
        $config->throw_exception = false;

        foreach ($error_levels as $error_type => $error_level) {
            $config->setCustomErrorLevel($error_type, $error_level);
        }

        foreach ($start_files as $file_path => $contents) {
            $this->file_provider->registerFile($file_path, $contents);
            $codebase->addFilesToAnalyze([$file_path => $file_path]);
        }

        $codebase->scanFiles();

        $this->assertSame([], $codebase->analyzer->getAnalyzedMethods());

        $codebase->analyzer->analyzeFiles($this->project_analyzer, 1, false);

        $this->assertSame(
            $initial_analyzed_methods,
            $codebase->analyzer->getAnalyzedMethods()
        );

        foreach ($end_files as $file_path => $contents) {
            $this->file_provider->registerFile($file_path, $contents);
        }

        $codebase->reloadFiles($this->project_analyzer, array_keys($end_files));

        $codebase->analyzer->loadCachedResults($this->project_analyzer);

        $this->assertSame(
            $unaffected_analyzed_methods,
            $codebase->analyzer->getAnalyzedMethods()
        );
    }

    /**
     * @return array<string,array{start_files:array<string,string>,end_files:array<string,string>,initial_analyzed_methods:array<string,array<string,int>>,unaffected_analyzed_methods:array<string,array<string,int>>,4?:array<string,string>}>
     */
    public function providerTestValidUpdates()
    {
        return [
            'basicRequire' => [
                'start_files' => [
                    getcwd() . DIRECTORY_SEPARATOR . 'A.php' => '<?php
                        namespace Foo;

                        class A{
                            public function fooFoo(): void {

                            }

                            public function barBar(): string {
                                return "hello";
                            }
                        }',
                    getcwd() . DIRECTORY_SEPARATOR . 'B.php' => '<?php
                        namespace Foo;

                        class B {
                            public function foo(): void {
                                (new A)->fooFoo();
                            }

                            public function bar() : void {
                                echo (new A)->barBar();
                            }

                            public function noReturnType() {}
                        }',
                ],
                'end_files' => [
                    getcwd() . DIRECTORY_SEPARATOR . 'A.php' => '<?php
                        namespace Foo;

                        class A{
                            public function fooFoo(?string $foo = null): void {

                            }

                            public function barBar(): string {
                                return "hello";
                            }
                        }',
                    getcwd() . DIRECTORY_SEPARATOR . 'B.php' => '<?php
                        namespace Foo;

                        class B {
                            public function foo(): void {
                                (new A)->fooFoo();
                            }

                            public function bar() : void {
                                echo (new A)->barBar();
                            }

                            public function noReturnType() {}
                        }',
                ],
                'initial_analyzed_methods' => [
                    getcwd() . DIRECTORY_SEPARATOR . 'A.php' => [
                        'foo\a::foofoo' => 1,
                        'foo\a::barbar' => 1,
                    ],
                    getcwd() . DIRECTORY_SEPARATOR . 'B.php' => [
                        'foo\b::foo' => 1,
                        'foo\b::bar' => 1,
                        'foo\b::noreturntype' => 1,
                    ],
                ],
                'unaffected_analyzed_methods' => [
                    getcwd() . DIRECTORY_SEPARATOR . 'A.php' => [
                        'foo\a::barbar' => 1,
                    ],
                    getcwd() . DIRECTORY_SEPARATOR . 'B.php' => [
                        'foo\b::bar' => 1,
                        'foo\b::noreturntype' => 1,
                    ],
                ],
                [
                    'MissingReturnType' => \Psalm\Config::REPORT_INFO,
                ],
            ],
            'invalidateAfterPropertyChange' => [
                'start_files' => [
                    getcwd() . DIRECTORY_SEPARATOR . 'A.php' => '<?php
                        namespace Foo;

                        class A {
                            /** @var string */
                            public $foo = "bar";
                        }',
                    getcwd() . DIRECTORY_SEPARATOR . 'B.php' => '<?php
                        namespace Foo;

                        class B {
                            public function foo() : string {
                                return (new A)->foo;
                            }

                            public function bar() : void {
                                $a = new A();
                            }
                        }',
                ],
                'end_files' => [
                    getcwd() . DIRECTORY_SEPARATOR . 'A.php' => '<?php
                        namespace Foo;

                        class A {
                            /** @var int */
                            public $foo = 5;
                        }',
                    getcwd() . DIRECTORY_SEPARATOR . 'B.php' => '<?php
                        namespace Foo;

                        class B {
                            public function foo() : string {
                                return (new A)->foo;
                            }

                            public function bar() : void {
                                $a = new A();
                            }
                        }',
                ],
                'initial_analyzed_methods' => [
                    getcwd() . DIRECTORY_SEPARATOR . 'B.php' => [
                        'foo\b::foo' => 1,
                        'foo\b::bar' => 1,
                    ],
                ],
                'unaffected_analyzed_methods' => [
                    getcwd() . DIRECTORY_SEPARATOR . 'B.php' => [
                        'foo\b::bar' => 1,
                    ],
                ],
            ],
            'invalidateAfterStaticPropertyChange' => [
                'start_files' => [
                    getcwd() . DIRECTORY_SEPARATOR . 'A.php' => '<?php
                        namespace Foo;

                        class A {
                            /** @var string */
                            public static $foo = "bar";
                        }',
                    getcwd() . DIRECTORY_SEPARATOR . 'B.php' => '<?php
                        namespace Foo;

                        class B {
                            public function foo() : string {
                                return A::$foo;
                            }

                            public function bar() : void {
                                $a = new A();
                            }
                        }',
                ],
                'end_files' => [
                    getcwd() . DIRECTORY_SEPARATOR . 'A.php' => '<?php
                        namespace Foo;

                        class A {
                            /** @var int */
                            public static $foo = 5;
                        }',
                    getcwd() . DIRECTORY_SEPARATOR . 'B.php' => '<?php
                        namespace Foo;

                        class B {
                            public function foo() : string {
                                return A::$foo;
                            }

                            public function bar() : void {
                                $a = new A();
                            }
                        }',
                ],
                'initial_analyzed_methods' => [
                    getcwd() . DIRECTORY_SEPARATOR . 'B.php' => [
                        'foo\b::foo' => 1,
                        'foo\b::bar' => 1,
                    ],
                ],
                'unaffected_analyzed_methods' => [
                    getcwd() . DIRECTORY_SEPARATOR . 'B.php' => [
                        'foo\b::bar' => 1,
                    ],
                ],
            ],
            'invalidateAfterStaticFlipPropertyChange' => [
                'start_files' => [
                    getcwd() . DIRECTORY_SEPARATOR . 'A.php' => '<?php
                        namespace Foo;

                        class A {
                            /** @var string */
                            public static $foo = "bar";
                        }',
                    getcwd() . DIRECTORY_SEPARATOR . 'B.php' => '<?php
                        namespace Foo;

                        class B {
                            public function foo() : string {
                                return A::$foo;
                            }

                            public function bar() : void {
                                $a = new A();
                            }
                        }',
                ],
                'end_files' => [
                    getcwd() . DIRECTORY_SEPARATOR . 'A.php' => '<?php
                        namespace Foo;

                        class A {
                            /** @var string */
                            public $foo = "bar";
                        }',
                    getcwd() . DIRECTORY_SEPARATOR . 'B.php' => '<?php
                        namespace Foo;

                        class B {
                            public function foo() : string {
                                return A::$foo;
                            }

                            public function bar() : void {
                                $a = new A();
                            }
                        }',
                ],
                'initial_analyzed_methods' => [
                    getcwd() . DIRECTORY_SEPARATOR . 'B.php' => [
                        'foo\b::foo' => 1,
                        'foo\b::bar' => 1,
                    ],
                ],
                'unaffected_analyzed_methods' => [
                    getcwd() . DIRECTORY_SEPARATOR . 'B.php' => [
                        'foo\b::bar' => 1,
                    ],
                ],
            ],
            'invalidateAfterConstantChange' => [
                'start_files' => [
                    getcwd() . DIRECTORY_SEPARATOR . 'A.php' => '<?php
                        namespace Foo;

                        class A {
                            public const FOO = "bar";
                        }',
                    getcwd() . DIRECTORY_SEPARATOR . 'B.php' => '<?php
                        namespace Foo;

                        class B {
                            public function foo() : string {
                                return A::FOO;
                            }

                            public function bar() : void {
                                $a = new A();
                            }
                        }',
                ],
                'end_files' => [
                    getcwd() . DIRECTORY_SEPARATOR . 'A.php' => '<?php
                        namespace Foo;

                        class A {
                            public const FOO = 5;
                        }',
                    getcwd() . DIRECTORY_SEPARATOR . 'B.php' => '<?php
                        namespace Foo;

                        class B {
                            public function foo() : string {
                                return A::FOO;
                            }

                            public function bar() : void {
                                $a = new A();
                            }
                        }',
                ],
                'initial_analyzed_methods' => [
                    getcwd() . DIRECTORY_SEPARATOR . 'B.php' => [
                        'foo\b::foo' => 1,
                        'foo\b::bar' => 1,
                    ],
                ],
                'unaffected_analyzed_methods' => [
                    getcwd() . DIRECTORY_SEPARATOR . 'B.php' => [
                        'foo\b::bar' => 1,
                    ],
                ],
            ],
            'dontInvalidateTraitMethods' => [
                'start_files' => [
                    getcwd() . DIRECTORY_SEPARATOR . 'A.php' => '<?php
                        namespace Foo;

                        class A {
                            use T;

                            public function fooFoo(): void { }
                        }',
                    getcwd() . DIRECTORY_SEPARATOR . 'B.php' => '<?php
                        namespace Foo;

                        class B {
                            public function foo(): void {
                                (new A)->fooFoo();
                            }

                            public function bar() : void {
                                echo (new A)->barBar();
                            }

                            public function noReturnType() {}
                        }',
                     getcwd() . DIRECTORY_SEPARATOR . 'T.php' => '<?php
                        namespace Foo;

                        trait T {
                            public function barBar(): string {
                                return "hello";
                            }
                        }',
                ],
                'end_files' => [
                    getcwd() . DIRECTORY_SEPARATOR . 'A.php' => '<?php
                        namespace Foo;

                        class A {
                            use T;

                            public function fooFoo(?string $foo = null): void { }
                        }',
                    getcwd() . DIRECTORY_SEPARATOR . 'B.php' => '<?php
                        namespace Foo;

                        class B {
                            public function foo(): void {
                                (new A)->fooFoo();
                            }

                            public function bar() : void {
                                echo (new A)->barBar();
                            }

                            public function noReturnType() {}
                        }',
                     getcwd() . DIRECTORY_SEPARATOR . 'T.php' => '<?php
                        namespace Foo;

                        trait T {
                            public function barBar(): string {
                                return "hello";
                            }
                        }',
                ],
                'initial_analyzed_methods' => [
                    getcwd() . DIRECTORY_SEPARATOR . 'A.php' => [
                        'foo\a::barbar&foo\t::barbar' => 1,
                        'foo\a::foofoo' => 1,
                    ],
                    getcwd() . DIRECTORY_SEPARATOR . 'B.php' => [
                        'foo\b::foo' => 1,
                        'foo\b::bar' => 1,
                        'foo\b::noreturntype' => 1,
                    ],
                ],
                'unaffected_analyzed_methods' => [
                    getcwd() . DIRECTORY_SEPARATOR . 'A.php' => [
                        'foo\a::barbar&foo\t::barbar' => 1,
                    ],
                    getcwd() . DIRECTORY_SEPARATOR . 'B.php' => [
                        'foo\b::bar' => 1,
                        'foo\b::noreturntype' => 1,
                    ],
                ],
                [
                    'MissingReturnType' => \Psalm\Config::REPORT_INFO,
                ],
            ],
            'invalidateTraitMethodsWhenTraitRemoved' => [
                'start_files' => [
                    getcwd() . DIRECTORY_SEPARATOR . 'A.php' => '<?php
                        namespace Foo;

                        class A {
                            use T;

                            public function fooFoo(): void { }
                        }',
                    getcwd() . DIRECTORY_SEPARATOR . 'B.php' => '<?php
                        namespace Foo;

                        class B {
                            public function foo(): void {
                                (new A)->fooFoo();
                            }

                            public function bar() : void {
                                echo (new A)->barBar();
                            }
                        }',
                     getcwd() . DIRECTORY_SEPARATOR . 'T.php' => '<?php
                        namespace Foo;

                        trait T {
                            public function barBar(): string {
                                return "hello";
                            }
                        }',
                ],
                'end_files' => [
                    getcwd() . DIRECTORY_SEPARATOR . 'A.php' => '<?php
                        namespace Foo;

                        class A {
                            public function fooFoo(?string $foo = null): void { }
                        }',
                    getcwd() . DIRECTORY_SEPARATOR . 'B.php' => '<?php
                        namespace Foo;

                        class B {
                            public function foo(): void {
                                (new A)->fooFoo();
                            }

                            public function bar() : void {
                                echo (new A)->barBar();
                            }
                        }',
                     getcwd() . DIRECTORY_SEPARATOR . 'T.php' => '<?php
                        namespace Foo;

                        trait T {
                            public function barBar(): string {
                                return "hello";
                            }
                        }',
                ],
                'initial_analyzed_methods' => [
                    getcwd() . DIRECTORY_SEPARATOR . 'A.php' => [
                        'foo\a::barbar&foo\t::barbar' => 1,
                        'foo\a::foofoo' => 1,
                    ],
                    getcwd() . DIRECTORY_SEPARATOR . 'B.php' => [
                        'foo\b::foo' => 1,
                        'foo\b::bar' => 1,
                    ],
                ],
                'unaffected_analyzed_methods' => [
                    getcwd() . DIRECTORY_SEPARATOR . 'A.php' => [
                        'foo\a::barbar&foo\t::barbar' => 1, // this doesn't exist, so we don't care
                    ],
                    getcwd() . DIRECTORY_SEPARATOR . 'B.php' => [],
                ],
            ],
            'invalidateTraitMethodsWhenTraitReplaced' => [
                'start_files' => [
                    getcwd() . DIRECTORY_SEPARATOR . 'A.php' => '<?php
                        namespace Foo;

                        class A {
                            use T;

                            public function fooFoo(): void { }
                        }',
                    getcwd() . DIRECTORY_SEPARATOR . 'B.php' => '<?php
                        namespace Foo;

                        class B {
                            public function foo(): void {
                                (new A)->fooFoo();
                            }

                            public function bar() : void {
                                echo (new A)->barBar();
                            }
                        }',
                     getcwd() . DIRECTORY_SEPARATOR . 'T.php' => '<?php
                        namespace Foo;

                        trait T {
                            public function barBar(): string {
                                return "hello";
                            }
                        }',
                ],
                'end_files' => [
                    getcwd() . DIRECTORY_SEPARATOR . 'A.php' => '<?php
                        namespace Foo;

                        class A {
                            public function fooFoo(?string $foo = null): void { }

                            public function barBar(): int {
                                return 5;
                            }
                        }',
                    getcwd() . DIRECTORY_SEPARATOR . 'B.php' => '<?php
                        namespace Foo;

                        class B {
                            public function foo(): void {
                                (new A)->fooFoo();
                            }

                            public function bar() : void {
                                echo (new A)->barBar();
                            }
                        }',
                     getcwd() . DIRECTORY_SEPARATOR . 'T.php' => '<?php
                        namespace Foo;

                        trait T {
                            public function barBar(): string {
                                return "hello";
                            }
                        }',
                ],
                'initial_analyzed_methods' => [
                    getcwd() . DIRECTORY_SEPARATOR . 'A.php' => [
                        'foo\a::barbar&foo\t::barbar' => 1,
                        'foo\a::foofoo' => 1,
                    ],
                    getcwd() . DIRECTORY_SEPARATOR . 'B.php' => [
                        'foo\b::foo' => 1,
                        'foo\b::bar' => 1,
                    ],
                ],
                'unaffected_analyzed_methods' => [
                    getcwd() . DIRECTORY_SEPARATOR . 'A.php' => [],
                    getcwd() . DIRECTORY_SEPARATOR . 'B.php' => [],
                ],
            ],
            'invalidateTraitMethodsWhenMethodChanged' => [
                'start_files' => [
                    getcwd() . DIRECTORY_SEPARATOR . 'A.php' => '<?php
                        namespace Foo;

                        class A {
                            use T;

                            public function fooFoo(): void { }
                        }',
                    getcwd() . DIRECTORY_SEPARATOR . 'B.php' => '<?php
                        namespace Foo;

                        class B {
                            public function foo(): void {
                                (new A)->fooFoo();
                            }

                            public function bar() : void {
                                echo (new A)->barBar();
                            }
                        }',
                    getcwd() . DIRECTORY_SEPARATOR . 'T.php' => '<?php
                        namespace Foo;

                        trait T {
                            public function barBar(): string {
                                return "hello";
                            }

                            public function bat(): string {
                                return "hello";
                            }
                        }',
                ],
                'end_files' => [
                    getcwd() . DIRECTORY_SEPARATOR . 'A.php' => '<?php
                        namespace Foo;

                        class A {
                            use T;

                            public function fooFoo(?string $foo = null): void { }
                        }',
                    getcwd() . DIRECTORY_SEPARATOR . 'B.php' => '<?php
                        namespace Foo;

                        class B {
                            public function foo(): void {
                                (new A)->fooFoo();
                            }

                            public function bar() : void {
                                echo (new A)->barBar();
                            }
                        }',
                     getcwd() . DIRECTORY_SEPARATOR . 'T.php' => '<?php
                        namespace Foo;

                        trait T {
                            public function barBar(): int {
                                return 5;
                            }

                            public function bat(): string {
                                return "hello";
                            }
                        }',
                ],
                'initial_analyzed_methods' => [
                    getcwd() . DIRECTORY_SEPARATOR . 'A.php' => [
                        'foo\a::barbar&foo\t::barbar' => 1,
                        'foo\a::bat&foo\t::bat' => 1,
                        'foo\a::foofoo' => 1,
                    ],
                    getcwd() . DIRECTORY_SEPARATOR . 'B.php' => [
                        'foo\b::foo' => 1,
                        'foo\b::bar' => 1,
                    ],
                ],
                'unaffected_analyzed_methods' => [
                    getcwd() . DIRECTORY_SEPARATOR . 'A.php' => [
                        'foo\a::bat&foo\t::bat' => 1,
                    ],
                    getcwd() . DIRECTORY_SEPARATOR . 'B.php' => [],
                ],
            ],
            'invalidateTraitMethodsWhenMethodSuperimposed' => [
                'start_files' => [
                    getcwd() . DIRECTORY_SEPARATOR . 'A.php' => '<?php
                        namespace Foo;

                        class A {
                            use T;
                        }',
                    getcwd() . DIRECTORY_SEPARATOR . 'B.php' => '<?php
                        namespace Foo;

                        class B {
                            public function bar() : string {
                                return (new A)->barBar();
                            }
                        }',
                     getcwd() . DIRECTORY_SEPARATOR . 'T.php' => '<?php
                        namespace Foo;

                        trait T {
                            public function barBar(): string {
                                return "hello";
                            }
                        }',
                ],
                'end_files' => [
                    getcwd() . DIRECTORY_SEPARATOR . 'A.php' => '<?php
                        namespace Foo;

                        class A {
                            use T;

                            public function barBar(): int {
                                return 5;
                            }
                        }',
                    getcwd() . DIRECTORY_SEPARATOR . 'B.php' => '<?php
                        namespace Foo;

                        class B {
                            public function bar() : string {
                                return (new A)->barBar();
                            }
                        }',
                     getcwd() . DIRECTORY_SEPARATOR . 'T.php' => '<?php
                        namespace Foo;

                        trait T {
                            public function barBar(): string {
                                return "hello";
                            }
                        }',
                ],
                'initial_analyzed_methods' => [
                    getcwd() . DIRECTORY_SEPARATOR . 'A.php' => [
                        'foo\a::barbar&foo\t::barbar' => 1,
                    ],
                    getcwd() . DIRECTORY_SEPARATOR . 'B.php' => [
                        'foo\b::bar' => 1,
                    ],
                ],
                'unaffected_analyzed_methods' => [
                    getcwd() . DIRECTORY_SEPARATOR . 'A.php' => [],
                    getcwd() . DIRECTORY_SEPARATOR . 'B.php' => [],
                ],
            ],
            'dontInvalidateConstructor' => [
                'start_files' => [
                    getcwd() . DIRECTORY_SEPARATOR . 'A.php' => '<?php
                        namespace Foo;

                        class A {
                            /** @var string */
                            public $foo;

                            public function __construct() {
                                $this->setFoo();
                            }

                            private function setFoo() : void {
                                $this->reallySetFoo();
                            }

                            private function reallySetFoo() : void {
                                $this->foo = "bar";
                            }
                        }',
                ],
                'end_files' => [
                    getcwd() . DIRECTORY_SEPARATOR . 'A.php' => '<?php
                        namespace Foo;

                        class A {
                            /** @var string */
                            public $foo;

                            public function __construct() {
                                $this->setFoo();
                            }

                            private function setFoo() : void {
                                $this->reallySetFoo();
                            }

                            private function reallySetFoo() : void {
                                $this->foo = "bar";
                            }
                        }',
                ],

                'initial_analyzed_methods' => [
                    getcwd() . DIRECTORY_SEPARATOR . 'A.php' => [
                        'foo\a::__construct' => 2,
                        'foo\a::setfoo' => 1,
                        'foo\a::reallysetfoo' => 1,
                    ],
                ],
                'unaffected_analyzed_methods' => [
                    getcwd() . DIRECTORY_SEPARATOR . 'A.php' => [
                        'foo\a::__construct' => 2,
                        'foo\a::setfoo' => 1,
                        'foo\a::reallysetfoo' => 1,
                    ],
                ],
            ],
            'invalidateConstructorWhenDependentMethodChanges' => [
                'start_files' => [
                    getcwd() . DIRECTORY_SEPARATOR . 'A.php' => '<?php
                        namespace Foo;

                        class A {
                            /** @var string */
                            public $foo;

                            public function __construct() {
                                $this->setFoo();
                            }

                            private function setFoo() : void {
                                $this->reallySetFoo();
                            }

                            private function reallySetFoo() : void {
                                $this->foo = "bar";
                            }
                        }',
                ],
                'end_files' => [
                    getcwd() . DIRECTORY_SEPARATOR . 'A.php' => '<?php
                        namespace Foo;

                        class A {
                            /** @var string */
                            public $foo;

                            public function __construct() {
                                $this->setFoo();
                            }

                            private function setFoo() : void {
                                $this->reallySetFoo();
                            }

                            private function reallySetFoo() : void {
                                //$this->foo = "bar";
                            }
                        }',
                ],
                'initial_analyzed_methods' => [
                    getcwd() . DIRECTORY_SEPARATOR . 'A.php' => [
                        'foo\a::__construct' => 2,
                        'foo\a::setfoo' => 1,
                        'foo\a::reallysetfoo' => 1,
                    ],
                ],
                'unaffected_analyzed_methods' => [
                    getcwd() . DIRECTORY_SEPARATOR . 'A.php' => [
                        'foo\a::setfoo' => 1,
                    ],
                ],
            ],
            'invalidateConstructorWhenDependentTraitMethodChanges' => [
                'start_files' => [
                    getcwd() . DIRECTORY_SEPARATOR . 'A.php' => '<?php
                        namespace Foo;

                        class A {
                            use T;

                            /** @var string */
                            public $foo;

                            public function __construct() {
                                $this->setFoo();
                            }
                        }',
                    getcwd() . DIRECTORY_SEPARATOR . 'T.php' => '<?php
                        namespace Foo;

                        trait T {
                            private function setFoo() : void {
                                $this->foo = "bar";
                            }
                        }',
                ],
                'end_files' => [
                    getcwd() . DIRECTORY_SEPARATOR . 'A.php' => '<?php
                        namespace Foo;

                        class A {
                            use T;

                            /** @var string */
                            public $foo;

                            public function __construct() {
                                $this->setFoo();
                            }
                        }',
                    getcwd() . DIRECTORY_SEPARATOR . 'T.php' => '<?php
                        namespace Foo;

                        trait T {
                            private function setFoo() : void {
                                //$this->foo = "bar";
                            }
                        }',
                ],
                'initial_analyzed_methods' => [
                    getcwd() . DIRECTORY_SEPARATOR . 'A.php' => [
                        'foo\a::setfoo&foo\t::setfoo' => 1,
                        'foo\a::__construct' => 2,
                    ],
                ],
                'unaffected_analyzed_methods' => [
                    getcwd() . DIRECTORY_SEPARATOR . 'A.php' => [],
                ],
            ],
            'rescanPropertyAssertingMethod' => [
                'start_files' => [
                    getcwd() . DIRECTORY_SEPARATOR . 'A.php' => '<?php
                        namespace Foo;

                        class A {
                            /** @var string */
                            private $foo;

                            public function __construct() {}

                            public function bar() : void {
                                if ($this->foo === null) {}
                            }
                        }',
                ],
                'end_files' => [
                    getcwd() . DIRECTORY_SEPARATOR . 'A.php' => '<?php
                        namespace Foo;

                        class A {
                            /** @var string|null */
                            private $foo;

                            public function __construct() {}

                            public function bar() : void {
                                if ($this->foo === null) {}
                            }
                        }',
                ],
                'initial_analyzed_methods' => [
                    getcwd() . DIRECTORY_SEPARATOR . 'A.php' => [
                        'foo\a::__construct' => 2,
                        'foo\a::bar' => 1,
                    ],
                ],
                'unaffected_analyzed_methods' => [
                    getcwd() . DIRECTORY_SEPARATOR . 'A.php' => [
                        'foo\a::__construct' => 2,
                    ],
                ],
                [
                    'PropertyNotSetInConstructor' => \Psalm\Config::REPORT_INFO,
                    'DocblockTypeContradiction' => \Psalm\Config::REPORT_INFO,
                    'RedundantConditionGivenDocblockType' => \Psalm\Config::REPORT_INFO,
                ],
            ],
            'noChangeAfterSyntaxError' => [
                'start_files' => [
                    getcwd() . DIRECTORY_SEPARATOR . 'A.php' => '<?php
                        namespace Foo;

                        class A {
                            /** @var string|null */
                            private $foo;

                            public function __construct() {}

                            public function bar() : void {
                                if ($this->foo === null) {}
                            }
                        }',
                ],
                'end_files' => [
                    getcwd() . DIRECTORY_SEPARATOR . 'A.php' => '<?php
                        namespace Foo;

                        class A {
                            /** @var string|null */
                            private $foo

                            public function __construct() {}

                            public function bar() : void {
                                if ($this->foo === null) {}
                            }
                        }',
                ],
                'initial_analyzed_methods' => [
                    getcwd() . DIRECTORY_SEPARATOR . 'A.php' => [
                        'foo\a::__construct' => 1,
                        'foo\a::bar' => 1,
                    ],
                ],
                'unaffected_analyzed_methods' => [
                    getcwd() . DIRECTORY_SEPARATOR . 'A.php' => [
                        'foo\a::__construct' => 1,
                    ],
                ],
            ],
            'nothingBeforeSyntaxError' => [
                'start_files' => [
                    getcwd() . DIRECTORY_SEPARATOR . 'A.php' => '<?php
                        namespace Foo;

                        class A {
                            /** @var string|null */
                            private $foo

                            public function __construct() {}

                            public function bar() : void {
                                if ($this->foo === null) {}
                            }
                        }',
                ],
                'end_files' => [
                    getcwd() . DIRECTORY_SEPARATOR . 'A.php' => '<?php
                        namespace Foo;

                        class A {
                            /** @var string|null */
                            private $foo;

                            public function __construct() {}

                            public function bar() : void {
                                if ($this->foo === null) {}
                            }
                        }',
                ],
                'initial_analyzed_methods' => [
                    getcwd() . DIRECTORY_SEPARATOR . 'A.php' => [
                        'foo\a::__construct' => 1,
                        'foo\a::bar' => 1,
                    ],
                ],
                'unaffected_analyzed_methods' => [
                    getcwd() . DIRECTORY_SEPARATOR . 'A.php' => [
                        'foo\a::__construct' => 1,
                    ],
                ],
            ],
        ];
    }
}
