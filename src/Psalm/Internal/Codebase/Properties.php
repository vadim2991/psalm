<?php
namespace Psalm\Internal\Codebase;

use Psalm\CodeLocation;
use Psalm\Context;
use Psalm\Internal\Provider\{
    ClassLikeStorageProvider,
    FileReferenceProvider,
    PropertyExistenceProvider,
    PropertyTypeProvider,
    PropertyVisibilityProvider
};
use Psalm\StatementsSource;
use Psalm\Type;
use function preg_replace;
use function explode;
use function strtolower;

/**
 * @internal
 *
 * Handles information about class properties
 */
class Properties
{
    /**
     * @var ClassLikeStorageProvider
     */
    private $classlike_storage_provider;

    /**
     * @var bool
     */
    public $collect_locations = false;

    /**
     * @var FileReferenceProvider
     */
    public $file_reference_provider;

    /**
     * @var PropertyExistenceProvider
     */
    public $property_existence_provider;

    /**
     * @var PropertyTypeProvider
     */
    public $property_type_provider;

    /**
     * @var PropertyVisibilityProvider
     */
    public $property_visibility_provider;

    public function __construct(
        ClassLikeStorageProvider $storage_provider,
        FileReferenceProvider $file_reference_provider
    ) {
        $this->classlike_storage_provider = $storage_provider;
        $this->file_reference_provider = $file_reference_provider;
        $this->property_existence_provider = new PropertyExistenceProvider();
        $this->property_visibility_provider = new PropertyVisibilityProvider();
        $this->property_type_provider = new PropertyTypeProvider();
    }

    /**
     * Whether or not a given property exists
     *
     * @return bool
     */
    public function propertyExists(
        string $property_id,
        bool $read_mode,
        StatementsSource $source = null,
        Context $context = null,
        CodeLocation $code_location = null
    ) {
        // remove trailing backslash if it exists
        $property_id = preg_replace('/^\\\\/', '', $property_id);

        list($fq_class_name, $property_name) = explode('::$', $property_id);

        if ($this->property_existence_provider->has($fq_class_name)) {
            $property_exists = $this->property_existence_provider->doesPropertyExist(
                $fq_class_name,
                $property_name,
                $read_mode,
                $source,
                $context,
                $code_location
            );

            if ($property_exists !== null) {
                return $property_exists;
            }
        }

        $class_storage = $this->classlike_storage_provider->get($fq_class_name);

        if (isset($class_storage->declaring_property_ids[$property_name])) {
            $declaring_property_class = $class_storage->declaring_property_ids[$property_name];

            if ($context && $context->calling_method_id) {
                $this->file_reference_provider->addMethodReferenceToClassMember(
                    $context->calling_method_id,
                    strtolower($declaring_property_class) . '::$' . $property_name
                );
            } elseif ($source) {
                $this->file_reference_provider->addFileReferenceToClassMember(
                    $source->getFilePath(),
                    strtolower($declaring_property_class) . '::$' . $property_name
                );
            }

            if ($this->collect_locations && $code_location) {
                $this->file_reference_provider->addCallingLocationForClassProperty(
                    $code_location,
                    strtolower($declaring_property_class) . '::$' . $property_name
                );
            }

            return true;
        }

        if ($context && $context->calling_method_id) {
            $this->file_reference_provider->addMethodReferenceToMissingClassMember(
                $context->calling_method_id,
                strtolower($fq_class_name) . '::$' . $property_name
            );
        } elseif ($source) {
            $this->file_reference_provider->addFileReferenceToMissingClassMember(
                $source->getFilePath(),
                strtolower($fq_class_name) . '::$' . $property_name
            );
        }

        return false;
    }

    /**
     * @param  string $property_id
     *
     * @return string|null
     */
    public function getDeclaringClassForProperty($property_id, bool $read_mode)
    {
        list($fq_class_name, $property_name) = explode('::$', $property_id);

        if ($this->property_existence_provider->has($fq_class_name)) {
            if ($this->property_existence_provider->doesPropertyExist(
                $fq_class_name,
                $property_name,
                $read_mode,
                null,
                null
            )) {
                return $fq_class_name;
            }
        }

        $class_storage = $this->classlike_storage_provider->get($fq_class_name);

        if (isset($class_storage->declaring_property_ids[$property_name])) {
            return $class_storage->declaring_property_ids[$property_name];
        }
    }

    /**
     * Get the class this property appears in (vs is declared in, which could give a trait)
     *
     * @param  string $property_id
     *
     * @return string|null
     */
    public function getAppearingClassForProperty($property_id, bool $read_mode)
    {
        list($fq_class_name, $property_name) = explode('::$', $property_id);

        if ($this->property_existence_provider->has($fq_class_name)) {
            if ($this->property_existence_provider->doesPropertyExist(
                $fq_class_name,
                $property_name,
                $read_mode,
                null,
                null
            )) {
                return $fq_class_name;
            }
        }

        $class_storage = $this->classlike_storage_provider->get($fq_class_name);

        if (isset($class_storage->appearing_property_ids[$property_name])) {
            $appearing_property_id = $class_storage->appearing_property_ids[$property_name];

            return explode('::$', $appearing_property_id)[0];
        }
    }

    /**
     * @param  string $property_id
     * @return  \Psalm\Storage\PropertyStorage
     */
    public function getStorage($property_id)
    {
        // remove trailing backslash if it exists
        $property_id = preg_replace('/^\\\\/', '', $property_id);

        list($fq_class_name, $property_name) = explode('::$', $property_id);

        $class_storage = $this->classlike_storage_provider->get($fq_class_name);

        if (isset($class_storage->declaring_property_ids[$property_name])) {
            $declaring_property_class = $class_storage->declaring_property_ids[$property_name];
            $declaring_class_storage = $this->classlike_storage_provider->get($declaring_property_class);

            if (isset($declaring_class_storage->properties[$property_name])) {
                return $declaring_class_storage->properties[$property_name];
            }
        }

        throw new \UnexpectedValueException('Property ' . $property_id . ' should exist');
    }

    /**
     * @param  string $property_id
     * @return  ?Type\Union
     */
    public function getPropertyType(
        $property_id,
        bool $property_set,
        StatementsSource $source = null,
        Context $context = null
    ) {
        // remove trailing backslash if it exists
        $property_id = preg_replace('/^\\\\/', '', $property_id);

        list($fq_class_name, $property_name) = explode('::$', $property_id);

        if ($this->property_type_provider->has($fq_class_name)) {
            $property_type = $this->property_type_provider->getPropertyType(
                $fq_class_name,
                $property_name,
                !$property_set,
                $source,
                $context
            );

            if ($property_type !== null) {
                return $property_type;
            }
        }

        $class_storage = $this->classlike_storage_provider->get($fq_class_name);

        if (isset($class_storage->declaring_property_ids[$property_name])) {
            $declaring_property_class = $class_storage->declaring_property_ids[$property_name];
            $declaring_class_storage = $this->classlike_storage_provider->get($declaring_property_class);

            if (isset($declaring_class_storage->properties[$property_name])) {
                $storage = $declaring_class_storage->properties[$property_name];
            } else {
                throw new \UnexpectedValueException('Property ' . $property_id . ' should exist');
            }
        } else {
            throw new \UnexpectedValueException('Property ' . $property_id . ' should exist');
        }

        if ($storage->type) {
            if ($property_set) {
                if (isset($class_storage->pseudo_property_set_types[$property_name])) {
                    return $class_storage->pseudo_property_set_types[$property_name];
                }
            } else {
                if (isset($class_storage->pseudo_property_get_types[$property_name])) {
                    return $class_storage->pseudo_property_get_types[$property_name];
                }
            }

            return $storage->type;
        }

        if (!isset($class_storage->overridden_property_ids[$property_name])) {
            return null;
        }

        foreach ($class_storage->overridden_property_ids[$property_name] as $overridden_property_id) {
            $overridden_storage = $this->getStorage($overridden_property_id);

            if ($overridden_storage->type) {
                return $overridden_storage->type;
            }
        }

        return null;
    }
}
