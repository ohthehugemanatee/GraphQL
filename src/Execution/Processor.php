<?php
/**
 * Date: 03.11.16
 *
 * @author Portey Vasil <portey@gmail.com>
 */

namespace Youshido\GraphQL\Execution;


use Youshido\GraphQL\Exception\ResolveException;
use Youshido\GraphQL\Execution\Container\Container;
use Youshido\GraphQL\Execution\Context\ExecutionContext;
use Youshido\GraphQL\Execution\Visitor\MaxComplexityQueryVisitor;
use Youshido\GraphQL\Field\Field;
use Youshido\GraphQL\Field\FieldInterface;
use Youshido\GraphQL\Field\InputField;
use Youshido\GraphQL\Parser\Ast\ArgumentValue\InputList as AstInputList;
use Youshido\GraphQL\Parser\Ast\ArgumentValue\InputObject as AstInputObject;
use Youshido\GraphQL\Parser\Ast\ArgumentValue\Literal as AstLiteral;
use Youshido\GraphQL\Parser\Ast\ArgumentValue\VariableReference;
use Youshido\GraphQL\Parser\Ast\Field as AstField;
use Youshido\GraphQL\Parser\Ast\FragmentReference;
use Youshido\GraphQL\Parser\Ast\Interfaces\FieldInterface as AstFieldInterface;
use Youshido\GraphQL\Parser\Ast\Mutation as AstMutation;
use Youshido\GraphQL\Parser\Ast\Query as AstQuery;
use Youshido\GraphQL\Parser\Ast\Query;
use Youshido\GraphQL\Parser\Ast\TypedFragmentReference;
use Youshido\GraphQL\Parser\Parser;
use Youshido\GraphQL\Schema\AbstractSchema;
use Youshido\GraphQL\Type\AbstractType;
use Youshido\GraphQL\Type\InputObject\AbstractInputObjectType;
use Youshido\GraphQL\Type\InterfaceType\AbstractInterfaceType;
use Youshido\GraphQL\Type\ListType\AbstractListType;
use Youshido\GraphQL\Type\Object\AbstractObjectType;
use Youshido\GraphQL\Type\Scalar\AbstractScalarType;
use Youshido\GraphQL\Type\TypeMap;
use Youshido\GraphQL\Type\Union\AbstractUnionType;
use Youshido\GraphQL\Validator\RequestValidator\RequestValidator;
use Youshido\GraphQL\Validator\ResolveValidator\ResolveValidator;
use Youshido\GraphQL\Validator\ResolveValidator\ResolveValidatorInterface;

class Processor
{

    const TYPE_NAME_QUERY = '__typename';

    /** @var ExecutionContext */
    protected $executionContext;

    /** @var ResolveValidatorInterface */
    protected $resolveValidator;

    /** @var  array */
    protected $data;

    /** @var int */
    protected $maxComplexity;

    /** @var array */
    protected $deferredResolverCallbacks;

    /** @var array */
    protected $deferredResolverIds;

    /** @var \Youshido\GraphQL\Execution\DeferredResolver[] */
    protected $deferredResolvers;

    public function __construct(AbstractSchema $schema)
    {
        if (empty($this->executionContext)) {
            $this->executionContext = new ExecutionContext($schema);
            $this->executionContext->setContainer(new Container());
        }

        $this->resolveValidator = new ResolveValidator($this->executionContext);
    }

    public function processPayload($payload, $variables = [], $reducers = [])
    {
        $this->data = [];

        try {
            $this->parseAndCreateRequest($payload, $variables);

            if ($this->maxComplexity) {
                $reducers[] = new MaxComplexityQueryVisitor($this->maxComplexity);
            }

            if ($reducers) {
                $reducer = new Reducer();
                $reducer->reduceQuery($this->executionContext, $reducers);
            }

            foreach ($this->executionContext->getRequest()->getAllOperations() as $query) {
                if ($operationResult = $this->resolveQuery($query)) {
                    $this->data = array_merge($this->data, $operationResult);
                };
            }
        } catch (\Exception $e) {
            $this->executionContext->addError($e);
        }

        return $this;
    }

    protected function resolveQuery(AstQuery $query)
    {
        $schema = $this->executionContext->getSchema();
        $type   = $query instanceof AstMutation ? $schema->getMutationType() : $schema->getQueryType();
        $field  = new Field([
            'name' => $query instanceof AstMutation ? 'mutation' : 'query',
            'type' => $type
        ]);

        if (self::TYPE_NAME_QUERY == $query->getName()) {
            return [$this->getAlias($query) => $type->getName()];
        }

        $this->resolveValidator->assetTypeHasField($type, $query);
        $value = $this->resolveField($field, $query);

        return [$this->getAlias($query) => $value];
    }

    protected function resolveField(FieldInterface $field, AstFieldInterface $ast, $parentValue = null, $fromObject = false)
    {
      if ($parentValue != NULL && isset($this->deferredResolvers[$parentValue])) {
        return;
      }
        try {
            /** @var AbstractObjectType $type */
            $type        = $field->getType();
            $nonNullType = $type->getNullableType();

            if (self::TYPE_NAME_QUERY == $ast->getName()) {
                return $nonNullType->getName();
            }

            $this->resolveValidator->assetTypeHasField($nonNullType, $ast);

            $targetField = $nonNullType->getField($ast->getName());

            $this->prepareAstArguments($targetField, $ast, $this->executionContext->getRequest());
            $this->resolveValidator->assertValidArguments($targetField, $ast, $this->executionContext->getRequest());

            switch ($kind = $targetField->getType()->getNullableType()->getKind()) {
                case TypeMap::KIND_ENUM:
                case TypeMap::KIND_SCALAR:
                    if ($ast instanceof AstQuery && $ast->hasFields()) {
                        throw new ResolveException(sprintf('You can\'t specify fields for scalar type "%s"', $targetField->getType()->getNullableType()->getName()), $ast->getLocation());
                    }

                    return $this->resolveScalar($targetField, $ast, $parentValue);

                case TypeMap::KIND_OBJECT:
                    /** @var $type AbstractObjectType */
                    if (!$ast instanceof AstQuery) {
                        throw new ResolveException(sprintf('You have to specify fields for "%s"', $ast->getName()), $ast->getLocation());
                    }

                    $resolved = $this->resolveObject($targetField, $ast, $parentValue);
                    return $resolved;

                case TypeMap::KIND_LIST:
                    return $this->resolveList($targetField, $ast, $parentValue);

                case TypeMap::KIND_UNION:
                case TypeMap::KIND_INTERFACE:
                    if (!$ast instanceof AstQuery) {
                        throw new ResolveException(sprintf('You have to specify fields for "%s"', $ast->getName()), $ast->getLocation());
                    }

                    return $this->resolveComposite($targetField, $ast, $parentValue);

                default:
                    throw new ResolveException(sprintf('Resolving type with kind "%s" not supported', $kind));
            }
        } catch (\Exception $e) {
            $this->executionContext->addError($e);

            if ($fromObject) {
                throw $e;
            }

            return null;
        }
    }

    private function prepareAstArguments(FieldInterface $field, AstFieldInterface $query, Request $request)
    {
        foreach ($query->getArguments() as $astArgument) {
            if ($field->hasArgument($astArgument->getName())) {
                $argumentType = $field->getArgument($astArgument->getName())->getType()->getNullableType();

                $astArgument->setValue($this->prepareArgumentValue($astArgument->getValue(), $argumentType, $request));
            }
        }
    }

    private function prepareArgumentValue($argumentValue, AbstractType $argumentType, Request $request)
    {
        switch ($argumentType->getKind()) {
            case TypeMap::KIND_LIST:
                /** @var $argumentType AbstractListType */
                $result = [];
                if ($argumentValue instanceof AstInputList || is_array($argumentValue)) {
                    $list = is_array($argumentValue) ? $argumentValue : $argumentValue->getValue();
                    foreach ($list as $item) {
                        $result[] = $this->prepareArgumentValue($item, $argumentType->getItemType()->getNullableType(), $request);
                    }
                } else {
                    if ($argumentValue instanceof VariableReference) {
                        return $this->getVariableReferenceArgumentValue($argumentValue, $argumentType, $request);
                    }
                }

                return $result;

            case TypeMap::KIND_INPUT_OBJECT:
                /** @var $argumentType AbstractInputObjectType */
                $result = [];
                if ($argumentValue instanceof AstInputObject) {
                    foreach ($argumentType->getFields() as $field) {
                        /** @var $field Field */
                        if ($field->getConfig()->has('defaultValue')) {
                            $result[$field->getName()] = $field->getType()->getNullableType()->parseInputValue($field->getConfig()->get('defaultValue'));
                        }
                    }
                    foreach ($argumentValue->getValue() as $key => $item) {
                        if ($argumentType->hasField($key)) {
                            $result[$key] = $this->prepareArgumentValue($item, $argumentType->getField($key)->getType()->getNullableType(), $request);
                        } else {
                            $result[$key] = $item;
                        }
                    }
                } else {
                    if ($argumentValue instanceof VariableReference) {
                        return $this->getVariableReferenceArgumentValue($argumentValue, $argumentType, $request);
                    } else {
                        if (is_array($argumentValue)) {
                            return $argumentValue;
                        }
                    }
                }

                return $result;

            case TypeMap::KIND_SCALAR:
            case TypeMap::KIND_ENUM:
                /** @var $argumentValue AstLiteral|VariableReference */
                if ($argumentValue instanceof VariableReference) {
                    return $this->getVariableReferenceArgumentValue($argumentValue, $argumentType, $request);
                } else {
                    if ($argumentValue instanceof AstLiteral) {
                        return $argumentValue->getValue();
                    } else {
                        return $argumentValue;
                    }
                }
        }

        throw new ResolveException('Argument type not supported');
    }

    private function getVariableReferenceArgumentValue(VariableReference $variableReference, AbstractType $argumentType, Request $request)
    {
        $variable = $variableReference->getVariable();
        if ($argumentType->getKind() === TypeMap::KIND_LIST) {
            if (
                (!$variable->isArray() && !is_array($variable->getValue())) ||
                ($variable->getTypeName() !== $argumentType->getNamedType()->getNullableType()->getName()) ||
                ($argumentType->getNamedType()->getKind() === TypeMap::KIND_NON_NULL && $variable->isArrayElementNullable())
            ) {
                throw new ResolveException(sprintf('Invalid variable "%s" type, allowed type is "%s"', $variable->getName(), $argumentType->getNamedType()->getNullableType()->getName()), $variable->getLocation());
            }
        } else {
            if ($variable->getTypeName() !== $argumentType->getName()) {
                throw new ResolveException(sprintf('Invalid variable "%s" type, allowed type is "%s"', $variable->getName(), $argumentType->getName()), $variable->getLocation());
            }
        }

        $requestValue = $request->getVariable($variable->getName());
        if ((null === $requestValue && $variable->isNullable()) && !$request->hasVariable($variable->getName())) {
            throw new ResolveException(sprintf('Variable "%s" does not exist in request', $variable->getName()), $variable->getLocation());
        }

        return $requestValue;
    }

  protected function resolveObject(FieldInterface $field, AstFieldInterface $ast, $parentValue, $fromUnion = false)
    {
        $resolvedValue = $parentValue;
        if (!$fromUnion) {
            $resolvedValue = $this->doResolve($field, $ast, $parentValue);
        }

        $this->resolveValidator->assertValidResolvedValueForField($field, $resolvedValue);

        if (null === $resolvedValue) {
            return null;
        }

      if ($resolvedValue instanceof DeferredResolver) {
        $resolvedValue = $this->parseDeferredResolver($resolvedValue);
      }

        /** @var AbstractObjectType $type */
        $type = $field->getType()->getNullableType();

        try {
            return $this->collectResult($field, $type, $ast, $resolvedValue);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * @param FieldInterface     $field
     * @param AbstractObjectType $type
     * @param AstFieldInterface  $ast
     * @param                    $resolvedValue
     * @return array
     */
    private function collectResult(FieldInterface $field, AbstractObjectType $type, $ast, $resolvedValue)
    {
        $result = [];

        foreach ($ast->getFields() as $astField) {
            switch (true) {
                case $astField instanceof TypedFragmentReference:
                    $astName  = $astField->getTypeName();
                    $typeName = $type->getName();

                    if ($typeName !== $astName) {
                        foreach ($type->getInterfaces() as $interface) {
                            if ($interface->getName() === $astName) {
                                $result = array_merge($result, $this->collectResult($field, $type, $astField, $resolvedValue));

                                break;
                            }
                        }

                        continue;
                    }

                    $result = array_merge($result, $this->collectResult($field, $type, $astField, $resolvedValue));

                    break;

                case $astField instanceof FragmentReference:
                    $astFragment      = $this->executionContext->getRequest()->getFragment($astField->getName());
                    $astFragmentModel = $astFragment->getModel();
                    $typeName         = $type->getName();

                    if ($typeName !== $astFragmentModel) {
                        foreach ($type->getInterfaces() as $interface) {
                            if ($interface->getName() === $astFragmentModel) {
                                $result = array_merge($result, $this->collectResult($field, $type, $astFragment, $resolvedValue));

                                break;
                            }

                        }

                        continue;
                    }

                    $result = array_merge($result, $this->collectResult($field, $type, $astFragment, $resolvedValue));

                    break;

                default:
                    $result[$this->getAlias($astField)] = $this->resolveField($field, $astField, $resolvedValue, true);
            }
        }

        if (!empty($this->deferredResolvers)) {
          $result = $this->processDeferredResolvers($result);

          $result = array_merge($result, $this->collectResult($field, $type, $astField, $resolvedValue));
        }

        return $result;
    }

    protected function resolveScalar(FieldInterface $field, AstFieldInterface $ast, $parentValue)
    {
        $resolvedValue = $this->doResolve($field, $ast, $parentValue);

        $this->resolveValidator->assertValidResolvedValueForField($field, $resolvedValue);

        /** @var AbstractScalarType $type */
        $type = $field->getType()->getNullableType();

        return $type->serialize($resolvedValue);
    }

    protected function resolveList(FieldInterface $field, AstFieldInterface $ast, $parentValue)
    {
        /** @var AstQuery $ast */
        $resolvedValue = $this->doResolve($field, $ast, $parentValue);

        $this->resolveValidator->assertValidResolvedValueForField($field, $resolvedValue);

        if (null === $resolvedValue) {
            return null;
        }

        /** @var AbstractListType $type */
        $type     = $field->getType()->getNullableType();
        $itemType = $type->getNamedType();

        $fakeAst = clone $ast;
        if ($fakeAst instanceof AstQuery) {
            $fakeAst->setArguments([]);
        }

        $fakeField = new Field([
            'name' => $field->getName(),
            'type' => $itemType,
            'args' => $field->getArguments(),
        ]);

        $result = [];
        foreach ($resolvedValue as $resolvedValueItem) {
            try {
                $fakeField->getConfig()->set('resolve', function () use ($resolvedValueItem) {
                    return $resolvedValueItem;
                });

                switch ($itemType->getNullableType()->getKind()) {
                    case TypeMap::KIND_ENUM:
                    case TypeMap::KIND_SCALAR:
                        $value = $this->resolveScalar($fakeField, $fakeAst, $resolvedValueItem);

                        break;


                    case TypeMap::KIND_OBJECT:
                        $value = $this->resolveObject($fakeField, $fakeAst, $resolvedValueItem);

                        break;

                    case TypeMap::KIND_UNION:
                    case TypeMap::KIND_INTERFACE:
                        $value = $this->resolveComposite($fakeField, $fakeAst, $resolvedValueItem);

                        break;

                    default:
                        $value = null;
                }
            } catch (\Exception $e) {
                $this->executionContext->addError($e);

                $value = null;
            }

            $result[] = $value;
        }

        return $result;
    }

    protected function resolveComposite(FieldInterface $field, AstFieldInterface $ast, $parentValue)
    {
        /** @var AstQuery $ast */
        $resolvedValue = $this->doResolve($field, $ast, $parentValue);

        $this->resolveValidator->assertValidResolvedValueForField($field, $resolvedValue);

        if (null === $resolvedValue) {
            return null;
        }

        /** @var AbstractUnionType $type */
        $type         = $field->getType()->getNullableType();
        $resolvedType = $type->resolveType($resolvedValue);

        if (!$resolvedType) {
            throw new ResolveException('Resolving function must return type');
        }

        if ($type instanceof AbstractInterfaceType) {
            $this->resolveValidator->assertTypeImplementsInterface($resolvedType, $type);
        } else {
            $this->resolveValidator->assertTypeInUnionTypes($resolvedType, $type);
        }

        $fakeField = new Field([
            'name' => $field->getName(),
            'type' => $resolvedType,
            'args' => $field->getArguments(),
        ]);

        return $this->resolveObject($fakeField, $ast, $resolvedValue, true);
    }

    protected function parseAndCreateRequest($payload, $variables = [])
    {
        if (empty($payload)) {
            throw new \InvalidArgumentException('Must provide an operation.');
        }

        $parser  = new Parser();
        $request = new Request($parser->parse($payload), $variables);

        (new RequestValidator())->validate($request);

        $this->executionContext->setRequest($request);
    }

    protected function doResolve(FieldInterface $field, AstFieldInterface $ast, $parentValue = null)
    {
        /** @var AstQuery|AstField $ast */
        $arguments = $this->parseArgumentsValues($field, $ast);
        $astFields = $ast instanceof AstQuery ? $ast->getFields() : [];

        return $field->resolve($parentValue, $arguments, $this->createResolveInfo($field, $astFields));

    }

    protected function parseArgumentsValues(FieldInterface $field, AstFieldInterface $ast)
    {
        $values   = [];
        $defaults = [];

        foreach ($field->getArguments() as $argument) {
            /** @var $argument InputField */
            if ($argument->getConfig()->has('defaultValue')) {
                $defaults[$argument->getName()] = $argument->getConfig()->getDefaultValue();
            }
        }

        foreach ($ast->getArguments() as $astArgument) {
            $argument     = $field->getArgument($astArgument->getName());
            $argumentType = $argument->getType()->getNullableType();

            $values[$argument->getName()] = $argumentType->parseValue($astArgument->getValue());

            if (isset($defaults[$argument->getName()])) {
                unset($defaults[$argument->getName()]);
            }
        }

        return array_merge($values, $defaults);
    }

    private function getAlias(AstFieldInterface $ast)
    {
        return $ast->getAlias() ?: $ast->getName();
    }

    protected function createResolveInfo(FieldInterface $field, array $astFields)
    {
        return new ResolveInfo($field, $astFields, $this->executionContext);
    }


    /**
     * You can access ExecutionContext to check errors and inject dependencies
     *
     * @return ExecutionContext
     */
    public function getExecutionContext()
    {
        return $this->executionContext;
    }

    public function getResponseData()
    {
        $result = [];

        if (!empty($this->data)) {
            $result['data'] = $this->data;
        }

        if ($this->executionContext->hasErrors()) {
            $result['errors'] = $this->executionContext->getErrorsArray();
        }

        return $result;
    }

    /**
     * @return int
     */
    public function getMaxComplexity()
    {
        return $this->maxComplexity;
    }

    /**
     * @param int $maxComplexity
     */
    public function setMaxComplexity($maxComplexity)
    {
        $this->maxComplexity = $maxComplexity;
    }

  /**
   * Parse a deferred resolver.
   *
   * Take a single deferred resolver, and:
   * - add it to the callbackmap and idmap properties.
   * - add it to the array of delayed resolver references.
   * - return the delayedResolver's placeholder.
   * @param DeferredResolver $delayedResolver
   */
    protected function parseDeferredResolver(DeferredResolver $deferredResolver)
    {
          // If we already have some ids with this callback, add this id to the stack.
          $callback_index = array_search($deferredResolver::$resolveCallback, $this->deferredResolverCallbacks);
        if ($callback_index) {
            $this->deferredResolverIds[$callback_index][$deferredResolver->getPlaceholder()] = $deferredResolver->getId();
        } else {
            // Otherwise, add the callback and the ID to their respective stacks.
            $this->deferredResolverCallbacks[] = $deferredResolver::$resolveCallback;
            $this->deferredResolverIds[][$deferredResolver->getPlaceholder()] = $deferredResolver->getId();
        }
        $this->deferredResolvers[$deferredResolver->getPlaceholder()] = &$deferredResolver;

        // Let's try this with a reference instead.
        return $deferredResolver->getPlaceholder();
    }

  /**
   * Process deferred resolvers.
   *
   * - check that the callbacks are callable.
   * - loop over the callbacks, and send them all their list of IDs.
   * - replace placeholder values with real objects.
   */
    protected function processDeferredResolvers(array $result)
    {
        $loaded_objects = [];
        foreach ($this->deferredResolverCallbacks as $index => $callback) {
            if (is_callable($callback)) {
                $loaded_objects += call_user_func_array($callback, $this->deferredResolverIds[$index]);
            }
        }
        foreach ($this->deferredResolvers as $placeholder => $value) {
            $this->deferredResolvers[$placeholder] = $loaded_objects[$placeholder];
        }
        // @todo: insert loaded objects results in the results array.

        return $result;
    }
}
