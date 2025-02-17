<?php
namespace Psalm\Tests;

use const DIRECTORY_SEPARATOR;

class MagicPropertyTest extends TestCase
{
    use Traits\InvalidCodeAnalysisTestTrait;
    use Traits\ValidCodeAnalysisTestTrait;

    /**
     * @return iterable<string,array{string,assertions?:array<string,string>,error_levels?:string[]}>
     */
    public function providerValidCodeParse()
    {
        return [
            'propertyDocblock' => [
                '<?php
                    namespace Bar;

                    /**
                     * @property string $foo
                     */
                    class A {
                        /** @param string $name */
                        public function __get($name): ?string {
                            if ($name === "foo") {
                                return "hello";
                            }

                            return null;
                        }

                        /**
                         * @param string $name
                         * @param mixed $value
                         */
                        public function __set($name, $value): void {
                        }
                    }

                    $a = new A();
                    $a->foo = "hello";
                    $a->bar = "hello"; // not a property',
            ],
            'propertyOfTypeClassDocblock' => [
                '<?php
                    namespace Bar;

                    class PropertyType {}

                    /**
                     * @property PropertyType $foo
                     */
                    class A {
                        /** @param string $name */
                        public function __get($name): ?string {
                            if ($name === "foo") {
                                return "hello";
                            }

                            return null;
                        }

                        /**
                         * @param string $name
                         * @param mixed $value
                         */
                        public function __set($name, $value): void {
                        }
                    }

                    $a = new A();
                    $a->foo = new PropertyType();',
            ],
            'propertySealedDocblockDefinedPropertyFetch' => [
                '<?php
                    namespace Bar;
                    /**
                     * @property string $foo
                     * @psalm-seal-properties
                     */
                    class A {
                        public function __get(string $name): ?string {
                            if ($name === "foo") {
                                return "hello";
                            }

                            return null;
                        }

                        /** @param mixed $value */
                        public function __set(string $name, $value): void {
                        }
                    }

                    $a = new A();
                    echo $a->foo;',
            ],
            /**
             * With a magic setter and no annotations specifying properties or types, we can
             * set anything we want on any variable name. The magic setter is trusted to figure
             * it out.
             */
            'magicSetterUndefinedPropertyNoAnnotation' => [
                '<?php
                    class A {
                        public function __get(string $name): ?string {
                            if ($name === "foo") {
                                return "hello";
                            }

                            return null;
                        }

                        /** @param mixed $value */
                        public function __set(string $name, $value): void {
                        }

                        public function goodSet(): void {
                            $this->__set("foo", new stdClass());
                        }
                    }',
            ],
            /**
             * With a magic getter and no annotations specifying properties or types, we can
             * get anything we want with any variable name. The magic getter is trusted to figure
             * it out.
             */
            'magicGetterUndefinedPropertyNoAnnotation' => [
                '<?php
                    class A {
                        public function __get(string $name): ?string {
                            if ($name === "foo") {
                                return "hello";
                            }

                            return null;
                        }

                        /** @param mixed $value */
                        public function __set(string $name, $value): void {
                        }

                        public function goodGet(): void {
                            echo $this->__get("foo");
                        }
                    }',
            ],
            /**
             * The property $foo is defined as a string with the `@property` annotation. We
             * use the magic setter to set it to a string, so everything is cool.
             */
            'magicSetterValidAssignmentType' => [
                '<?php
                    /**
                     * @property string $foo
                     */
                    class A {
                        public function __get(string $name): ?string {
                            if ($name === "foo") {
                                return "hello";
                            }

                            return null;
                        }

                        /** @param mixed $value */
                        public function __set(string $name, $value): void {
                        }

                        public function goodSet(): void {
                            $this->__set("foo", "value");
                        }
                    }',
            ],
            'propertyDocblockAssignmentToMixed' => [
                '<?php
                    /**
                     * @property string $foo
                     */
                    class A {
                        public function __get(string $name): ?string {
                            if ($name === "foo") {
                                return "hello";
                            }

                            return null;
                        }

                        /** @param mixed $value */
                        public function __set(string $name, $value): void {
                        }
                    }

                    /** @param mixed $b */
                    function foo($b) : void {
                        $a = new A();
                        $a->__set("foo", $b);
                    }',
                'assertions' => [],
                'error_level' => ['MixedAssignment', 'MixedPropertyTypeCoercion'],
            ],
            'namedPropertyByVariable' => [
                '<?php
                    class A {
                        /** @var string|null */
                        public $foo;

                        public function __get(string $var_name) : ?string {
                            if ($var_name === "foo") {
                                return $this->$var_name;
                            }

                            return null;
                        }
                    }',
            ],
            'getPropertyExplicitCall' => [
                '<?php
                    class A {
                        public function __get(string $name) {}

                        /**
                         * @param mixed $value
                         */
                        public function __set(string $name, $value) {}
                    }

                    /**
                     * @property string $test
                     */
                    class B extends A {
                        public function test(): string {
                            return $this->__get("test");
                        }
                    }',
            ],
            'inheritedGetPropertyExplicitCall' => [
                '<?php
                    /**
                     * @property string $test
                     */
                    class A {
                        public function __get(string $name) {}

                        /**
                         * @param mixed $value
                         */
                        public function __set(string $name, $value) {}
                    }

                    class B extends A {
                        public function test(): string {
                            return $this->__get("test");
                        }
                    }',
            ],
            'undefinedThisPropertyFetchWithMagic' => [
                '<?php
                    /**
                     * @property-read string $name
                     * @property string $otherName
                     */
                    class A {
                        public function __get(string $name): void {
                        }

                        public function goodGet(): void {
                            echo $this->name;
                        }
                        public function goodGet2(): void {
                            echo $this->otherName;
                        }
                    }
                    $a = new A();
                    echo $a->name;
                    echo $a->otherName;',
            ],
            'directFetchForMagicProperty' => [
                '<?php
                    /**
                     * @property string $test
                     */
                    class C {
                        public function __get(string $name)
                        {
                        }

                        /**
                         * @param mixed $value
                         */
                        public function __set(string $name, $value)
                        {
                        }

                        public function test(): string
                        {
                            return $this->test;
                        }
                    }',
            ],
            'magicPropertyFetchOnProtected' => [
                '<?php
                    class C {
                        /** @var string */
                        protected $foo = "foo";

                        public function __get(string $name) {}

                        /**
                         * @param mixed $value
                         */
                        public function __set(string $name, $value)
                        {
                        }
                    }

                    $c = new C();
                    $c->foo = "bar";
                    echo $c->foo;',
                'assertions' => [],
                'error_level' => ['MixedArgument'],
            ],
            'dontAssumeNonNullAfterPossibleMagicFetch' => [
                '<?php
                    class C {
                        public function __get(string $name) : string {
                            return "hello";
                        }
                    }

                    function foo(?C $c) : void {
                        echo $c->foo;

                        if ($c) {}
                    }',
                'assertions' => [],
                'error_level' => ['PossiblyNullPropertyFetch'],
            ],
            'accessInMagicGet' => [
                '<?php
                    class X {
                        public function __get(string $name) : string {
                            switch ($name) {
                                case "a":
                                    return $this->other;
                                case "other":
                                    return "foo";
                            }
                            return "default";
                        }
                    }',
                'assertions' => [],
                'error_level' => ['MixedReturnStatement', 'MixedInferredReturnType'],
            ],
            'overrideInheritedProperty' => [
                '<?php
                    interface ServiceInterface {}

                    class ConcreteService implements ServiceInterface {
                        public function getById(int $i) : void {}
                    }

                    class Foo
                    {
                        /** @var ServiceInterface */
                        protected $service;

                        public function __construct(ServiceInterface $service)
                        {
                            $this->service = $service;
                        }
                    }

                    /** @property ConcreteService $service */
                    class FooBar extends Foo
                    {
                        public function __construct(ConcreteService $concreteService)
                        {
                            parent::__construct($concreteService);
                        }

                        public function doSomething(): void
                        {
                            $this->service->getById(123);
                        }
                    }',
            ],
            'magicInterfacePropertyRead' => [
                '<?php
                    /**
                     * @property-read string $foo
                     * @psalm-seal-properties
                     */
                    interface GetterSetter {
                        /** @return mixed */
                        public function __get(string $key);
                        /** @param mixed $value */
                        public function __set(string $key, $value) : void;
                    }

                    /** @psalm-suppress NoInterfaceProperties */
                    function getFoo(GetterSetter $o) : string {
                        return $o->foo;
                    }',
            ],
            'magicInterfacePropertyWrite' => [
                '<?php
                    /**
                     * @property-write string $foo
                     * @psalm-seal-properties
                     */
                    interface GetterSetter {
                        /** @return mixed */
                        public function __get(string $key);
                        /** @param mixed $value */
                        public function __set(string $key, $value) : void;
                    }

                    /** @psalm-suppress NoInterfaceProperties */
                    function getFoo(GetterSetter $o) : void {
                        $o->foo = "hello";
                    }',
            ],
            'psalmPropertyDocblock' => [
                '<?php
                    namespace Bar;

                    /**
                     * @psalm-property string $foo
                     */
                    class A {
                        /** @param string $name */
                        public function __get($name): ?string {
                            if ($name === "foo") {
                                return "hello";
                            }

                            return null;
                        }

                        /**
                         * @param string $name
                         * @param mixed $value
                         */
                        public function __set($name, $value): void {
                        }
                    }

                    $a = new A();
                    $a->foo = "hello";
                    $a->bar = "hello"; // not a property',
            ],
            'overridePropertyAnnotations' => [
                '<?php
                    namespace Bar;

                    /**
                     * @property int $foo
                     * @psalm-property string $foo
                     */
                    class A {
                        /** @param string $name */
                        public function __get($name): ?string {
                            if ($name === "foo") {
                                return "hello";
                            }

                            return null;
                        }

                        /**
                         * @param string $name
                         * @param mixed $value
                         */
                        public function __set($name, $value): void {
                        }
                    }

                    $a = new A();
                    $a->foo = "hello";
                    $a->bar = "hello"; // not a property',
            ],
            'overrideWithReadWritePropertyAnnotations' => [
                '<?php
                    namespace Bar;

                    /**
                     * @psalm-property int $foo
                     * @property-read string $foo
                     * @property-write array $foo
                     */
                    class A {
                        /** @param string $name */
                        public function __get($name): ?string {
                            if ($name === "foo") {
                                return "hello";
                            }

                            return null;
                        }

                        /**
                         * @param string $name
                         * @param mixed $value
                         */
                        public function __set($name, $value): void {
                        }

                        public function takesString(string $s): void {}
                    }

                    $a = new A();
                    $a->foo = [];

                    $a = new A();
                    $a->takesString($a->foo);',
            ],
        ];
    }

    /**
     * @return iterable<string,array{string,error_message:string,2?:string[],3?:bool,4?:string}>
     */
    public function providerInvalidCodeParse()
    {
        return [
            'propertyDocblockInvalidAssignment' => [
                '<?php
                    /**
                     * @property string $foo
                     */
                    class A {
                        public function __get(string $name): ?string {
                            if ($name === "foo") {
                                return "hello";
                            }

                            return null;
                        }

                        /** @param mixed $value */
                        public function __set(string $name, $value): void {
                        }
                    }

                    $a = new A();
                    $a->foo = 5;',
                'error_message' => 'InvalidPropertyAssignmentValue',
            ],
            'propertyInvalidClassAssignment' => [
                '<?php
                    namespace Bar;

                    class PropertyType {}
                    class SomeOtherPropertyType {}

                    /**
                     * @property PropertyType $foo
                     */
                    class A {
                        /** @param string $name */
                        public function __get($name): ?string {
                            if ($name === "foo") {
                                return "hello";
                            }

                            return null;
                        }

                        /**
                         * @param string $name
                         * @param mixed $value
                         */
                        public function __set($name, $value): void {
                        }
                    }

                    $a = new A();
                    $a->foo = new SomeOtherPropertyType();',
                'error_message' => 'InvalidPropertyAssignmentValue - src' . DIRECTORY_SEPARATOR . 'somefile.php:29:31 - $a->foo with declared type'
                    . ' \'Bar\PropertyType\' cannot',
            ],
            'propertyWriteDocblockInvalidAssignment' => [
                '<?php
                    /**
                     * @property-write string $foo
                     */
                    class A {
                        public function __get(string $name): ?string {
                            if ($name === "foo") {
                                return "hello";
                            }

                            return null;
                        }

                        /** @param mixed $value */
                        public function __set(string $name, $value): void {
                        }
                    }

                    $a = new A();
                    $a->foo = 5;',
                'error_message' => 'InvalidPropertyAssignmentValue',
            ],
            'propertySealedDocblockUndefinedPropertyAssignment' => [
                '<?php
                    /**
                     * @property string $foo
                     * @psalm-seal-properties
                     */
                    class A {
                        public function __get(string $name): ?string {
                            if ($name === "foo") {
                                return "hello";
                            }

                            return null;
                        }

                        /** @param mixed $value */
                        public function __set(string $name, $value): void {
                        }
                    }

                    $a = new A();
                    $a->bar = 5;',
                'error_message' => 'UndefinedPropertyAssignment',
            ],
            'propertySealedDocblockDefinedPropertyAssignment' => [
                '<?php
                    /**
                     * @property string $foo
                     * @psalm-seal-properties
                     */
                    class A {
                        public function __get(string $name): ?string {
                            if ($name === "foo") {
                                return "hello";
                            }

                            return null;
                        }

                        /** @param mixed $value */
                        public function __set(string $name, $value): void {
                        }
                    }

                    $a = new A();
                    $a->foo = 5;',
                'error_message' => 'InvalidPropertyAssignmentValue',
            ],
            'propertyReadInvalidFetch' => [
                '<?php
                    /**
                     * @property-read string $foo
                     */
                    class A {
                        /** @return mixed */
                        public function __get(string $name) {
                            if ($name === "foo") {
                                return "hello";
                            }
                        }
                    }

                    $a = new A();
                    echo count($a->foo);',
                'error_message' => 'InvalidArgument',
            ],
            'propertySealedDocblockUndefinedPropertyFetch' => [
                '<?php
                    /**
                     * @property string $foo
                     * @psalm-seal-properties
                     */
                    class A {
                        public function __get(string $name): ?string {
                            if ($name === "foo") {
                                return "hello";
                            }

                            return null;
                        }

                        /** @param mixed $value */
                        public function __set(string $name, $value): void {
                        }
                    }

                    $a = new A();
                    echo $a->bar;',
                'error_message' => 'UndefinedPropertyFetch',
            ],
            /**
             * The property $foo is not defined on the object, but accessed with the magic setter.
             * This is an error because `@psalm-seal-properties` is specified on the class block.
             */
            'magicSetterUndefinedProperty' => [
                '<?php
                    /**
                     * @psalm-seal-properties
                     */
                    class A {
                        public function __get(string $name): ?string {
                            if ($name === "foo") {
                                return "hello";
                            }

                            return null;
                        }

                        /** @param mixed $value */
                        public function __set(string $name, $value): void {
                        }

                        public function badSet(): void {
                            $this->__set("foo", "value");
                        }
                    }',
                'error_message' => 'UndefinedThisPropertyAssignment',
            ],
            /**
             * The property $foo is not defined on the object, but accessed with the magic getter.
             * This is an error because `@psalm-seal-properties` is specified on the class block.
             */
            'magicGetterUndefinedProperty' => [
                '<?php
                    /**
                     * @psalm-seal-properties
                     */
                    class A {
                        public function __get(string $name): ?string {
                            if ($name === "foo") {
                                return "hello";
                            }

                            return null;
                        }

                        /** @param mixed $value */
                        public function __set(string $name, $value): void {
                        }

                        public function badGet(): void {
                            $this->__get("foo");
                        }
                    }',
                'error_message' => 'UndefinedThisPropertyFetch',
            ],
            /**
             * The property $foo is defined as a string with the `@property` annotation, but
             * the magic setter is used to set it to an object.
             */
            'magicSetterInvalidAssignmentType' => [
                '<?php
                    /**
                     * @property string $foo
                     */
                    class A {
                        public function __get(string $name): ?string {
                            if ($name === "foo") {
                                return "hello";
                            }

                            return null;
                        }

                        /** @param mixed $value */
                        public function __set(string $name, $value): void {
                        }

                        public function badSet(): void {
                            $this->__set("foo", new stdClass());
                        }
                    }',
                'error_message' => 'InvalidPropertyAssignmentValue',
            ],
            'propertyDocblockAssignmentToMixed' => [
                '<?php
                    /**
                     * @property string $foo
                     */
                    class A {
                        public function __get(string $name): ?string {
                            if ($name === "foo") {
                                return "hello";
                            }

                            return null;
                        }

                        /** @param mixed $value */
                        public function __set(string $name, $value): void {
                        }
                    }

                    /** @param mixed $b */
                    function foo($b) : void {
                        $a = new A();
                        $a->__set("foo", $b);
                    }',
                'error_message' => 'MixedPropertyTypeCoercion',
                'error_levels' => ['MixedAssignment'],
            ],
            'magicInterfacePropertyWrongProperty' => [
                '<?php
                    /**
                     * @property-read string $foo
                     * @psalm-seal-properties
                     */
                    interface GetterSetter {
                        /** @return mixed */
                        public function __get(string $key);
                        /** @param mixed $value */
                        public function __set(string $key, $value) : void;
                    }

                    /** @psalm-suppress NoInterfaceProperties */
                    function getBar(GetterSetter $o) : string {
                        return $o->bar;
                    }',
                'error_message' => 'UndefinedPropertyFetch',
            ],
            'magicInterfaceWrongPropertyWrite' => [
                '<?php
                    /**
                     * @property-write string $foo
                     * @psalm-seal-properties
                     */
                    interface GetterSetter {
                        /** @return mixed */
                        public function __get(string $key);
                        /** @param mixed $value */
                        public function __set(string $key, $value) : void;
                    }

                    /** @psalm-suppress NoInterfaceProperties */
                    function getFoo(GetterSetter $o) : void {
                        $o->bar = "hello";
                    }',
                'error_message' => 'UndefinedPropertyAssignment',
            ],
        ];
    }
}
